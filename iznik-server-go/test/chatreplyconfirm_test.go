package test

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

// A reply to an OFFER that looks damaged, or looks like it needs a car to collect, is held
// while Freegle asks the replier to confirm. See chat/replyconfirm.go.

// ensureFreegleUser returns the Freegle account the API resolves by its well-known address,
// creating it if this database has never had one.
func ensureFreegleUser(t *testing.T) uint64 {
	db := database.DBConn

	var id uint64
	db.Raw("SELECT userid FROM users_emails WHERE email = 'freegle@ilovefreegle.org' LIMIT 1").Scan(&id)
	if id > 0 {
		return id
	}

	id = CreateTestUser(t, uniquePrefix("freeglesys"), "User")
	db.Exec("INSERT INTO users_emails (userid, email, preferred) VALUES (?, 'freegle@ilovefreegle.org', 1)", id)

	return id
}

func ensureReplyConfirmColumns(t *testing.T) {
	ensureFirstReplyTables(t)
	addColumnIfMissing("chat_prompts", "userid", "BIGINT UNSIGNED NULL")
	addColumnIfMissing("chat_prompts", "heldchatmsgid", "BIGINT UNSIGNED NULL")
	addColumnIfMissing("chat_prompts", "answered_via", "VARCHAR(16) NULL")
}

// seedEee records a classification for the post, as eee:classify-new would.
func seedEee(t *testing.T, msgID uint64, condition string, size string, weight string) {
	res := database.DBConn.Exec("INSERT INTO messages_eee (msgid, is_eee, item_condition, size_bucket, weight_bucket, "+
		"model, prompt_version, classified_at) VALUES (?, 0, ?, ?, ?, 'gemini-test', 'v1', NOW())",
		msgID, condition, size, weight)
	if res.Error != nil {
		t.Fatalf("could not seed messages_eee: %v", res.Error)
	}
}

type replyConfirmFixture struct {
	posterID     uint64
	replierID    uint64
	msgID        uint64
	chatID       uint64
	posterToken  string
	replierToken string
}

func newReplyConfirmFixture(t *testing.T, name string) replyConfirmFixture {
	ensureReplyConfirmColumns(t)
	ensureFreegleUser(t)

	prefix := uniquePrefix(name)
	groupID := CreateTestGroup(t, prefix)
	f := replyConfirmFixture{}
	f.posterID = CreateTestUser(t, prefix+"_poster", "User")
	f.replierID = CreateTestUser(t, prefix+"_replier", "User")
	f.msgID = CreateTestMessage(t, f.posterID, groupID, "OFFER: armchair ("+prefix+")", 51.5, -0.1)
	f.chatID = CreateTestChatRoom(t, f.replierID, &f.posterID, nil, "User2User")
	_, f.posterToken = CreateTestSession(t, f.posterID)
	_, f.replierToken = CreateTestSession(t, f.replierID)

	return f
}

func (f replyConfirmFixture) reply(t *testing.T, text string, refmsgid bool) uint64 {
	body := map[string]interface{}{"message": text}
	if refmsgid {
		body["refmsgid"] = f.msgID
	}

	resp := postChatmessages(t, fmt.Sprintf("/api/chat/%d/message", f.chatID), body, f.replierToken)
	assert.Equal(t, 200, resp.StatusCode)

	var ret struct {
		Id uint64 `json:"id"`
	}
	assert.NoError(t, json.NewDecoder(resp.Body).Decode(&ret))

	return ret.Id
}

type confirmPromptRow struct {
	Chatmsgid     uint64
	Kind          string
	Userid        *uint64
	Heldchatmsgid *uint64
	Answer        *string
	Message       string
	Processing    int `gorm:"column:processingsuccessful"`
}

func (f replyConfirmFixture) prompt(t *testing.T) *confirmPromptRow {
	var rows []confirmPromptRow
	database.DBConn.Raw("SELECT cp.chatmsgid, cp.kind, cp.userid, cp.heldchatmsgid, cp.answer, cm.message, "+
		"cm.processingsuccessful FROM chat_prompts cp INNER JOIN chat_messages cm ON cm.id = cp.chatmsgid "+
		"WHERE cm.chatid = ? AND cp.kind = 'confirm_reply'", f.chatID).Scan(&rows)

	if len(rows) == 0 {
		return nil
	}

	assert.Len(t, rows, 1, "one question per reply, however many messages follow")
	return &rows[0]
}

func (f replyConfirmFixture) messageTypes(t *testing.T, token string) []string {
	resp, err := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/chat/%d/message?jwt=%s", f.chatID, token), nil), -1)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var msgs []struct {
		Type   string                 `json:"type"`
		Prompt map[string]interface{} `json:"prompt"`
	}
	assert.NoError(t, json.NewDecoder(resp.Body).Decode(&msgs))

	types := []string{}
	for _, m := range msgs {
		if m.Type == "Prompt" {
			if assert.NotNil(t, m.Prompt, "the buttons and answer, not just the text") {
				assert.NotEmpty(t, m.Prompt["askedname"], "so the other member reads who answered")
			}
		}
		types = append(types, m.Type)
	}

	return types
}

func (f replyConfirmFixture) answer(t *testing.T, promptID uint64, token string, answer string) int {
	req := httptest.NewRequest("POST",
		fmt.Sprintf("/api/chat/%d/message/%d/prompt?jwt=%s", f.chatID, promptID, token),
		strings.NewReader(`{"answer":"`+answer+`"}`))
	req.Header.Set("Content-Type", "application/json")

	resp, err := getApp().Test(req, -1)
	assert.NoError(t, err)

	return resp.StatusCode
}

// promptRecord is how a question was answered: "button", or "message" when writing again counted
// as a yes. Empty while it is unanswered.
func promptRecord(promptID uint64) string {
	var row struct {
		AnsweredVia *string `gorm:"column:answered_via"`
	}
	database.DBConn.Table("chat_prompts").Select("answered_via").Where("chatmsgid = ?", promptID).Scan(&row)
	if row.AnsweredVia != nil {
		return *row.AnsweredVia
	}
	return ""
}

func processingState(msgID uint64) (int, int) {
	var row struct {
		Processingrequired   int
		Processingsuccessful int
	}
	database.DBConn.Raw("SELECT processingrequired, processingsuccessful FROM chat_messages WHERE id = ?", msgID).Scan(&row)
	return row.Processingrequired, row.Processingsuccessful
}

func TestReplyConfirm_DamagedOfferAsksTheReplier(t *testing.T) {
	f := newReplyConfirmFixture(t, "rcdamaged")
	seedEee(t, f.msgID, "damaged", "medium", "5_20kg")

	replyID := f.reply(t, "Is it still available?", true)

	p := f.prompt(t)
	if assert.NotNil(t, p, "a reply to a damaged item should be held for the replier to confirm") {
		assert.Equal(t, f.replierID, *p.Userid, "asked of the replier, not the poster")
		assert.Equal(t, replyID, *p.Heldchatmsgid)
		assert.True(t, strings.HasPrefix(p.Message, "@"), "addressed to the replier by name: %s", p.Message)
		assert.Contains(t, p.Message, "damaged")
		assert.NotContains(t, p.Message, "car")
		assert.Equal(t, 0, p.Processing, "never processed, so the poster is never shown or told about it")
	}

	// The reply itself waits for chats:process-incoming, which waits for the answer.
	required, _ := processingState(replyID)
	assert.Equal(t, 1, required)
}

func TestReplyConfirm_LargeOrHeavyAsksAboutACar(t *testing.T) {
	for _, c := range []struct{ size, weight string }{
		{"large", "5_20kg"},
		{"medium", "20_100kg"},
		{"unsure", "over_100kg"},
	} {
		f := newReplyConfirmFixture(t, "rcbig")
		seedEee(t, f.msgID, "reusable", c.size, c.weight)

		f.reply(t, "I'd love this", true)

		p := f.prompt(t)
		if assert.NotNil(t, p, "size %s / weight %s needs a car", c.size, c.weight) {
			assert.Contains(t, p.Message, "car")
			assert.NotContains(t, p.Message, "damaged")
		}
	}
}

func TestReplyConfirm_BothReasonsInOneQuestion(t *testing.T) {
	f := newReplyConfirmFixture(t, "rcboth")
	seedEee(t, f.msgID, "damaged", "large", "over_100kg")

	f.reply(t, "Can I have it?", true)

	p := f.prompt(t)
	if assert.NotNil(t, p) {
		assert.Contains(t, p.Message, "damaged")
		assert.Contains(t, p.Message, "car")
	}
}

func TestReplyConfirm_NothingToAsk(t *testing.T) {
	// A small reusable item, and an item nobody has classified yet, go straight through.
	f := newReplyConfirmFixture(t, "rcfine")
	seedEee(t, f.msgID, "reusable", "small", "1_5kg")
	f.reply(t, "Yes please", true)
	assert.Nil(t, f.prompt(t))

	g := newReplyConfirmFixture(t, "rcunclassified")
	g.reply(t, "Yes please", true)
	assert.Nil(t, g.prompt(t))
}

func TestReplyConfirm_NewestClassificationWins(t *testing.T) {
	f := newReplyConfirmFixture(t, "rcnewest")
	seedEee(t, f.msgID, "damaged", "large", "over_100kg")
	database.DBConn.Exec("INSERT INTO messages_eee (msgid, is_eee, item_condition, size_bucket, weight_bucket, "+
		"model, prompt_version, classified_at) VALUES (?, 0, 'reusable', 'small', 'under_1kg', 'gemini-test', 'v2', NOW())",
		f.msgID)

	f.reply(t, "Yes please", true)
	assert.Nil(t, f.prompt(t), "a reclassification that clears the item replaces the old verdict")
}

func TestReplyConfirm_NotForTrashNothingMembers(t *testing.T) {
	f := newReplyConfirmFixture(t, "rctnreplier")
	seedEee(t, f.msgID, "damaged", "large", "over_100kg")
	database.DBConn.Exec("UPDATE users SET tnuserid = ? WHERE id = ?", f.replierID, f.replierID)
	f.reply(t, "Still there?", true)
	assert.Nil(t, f.prompt(t), "a TrashNothing replier is never asked")

	g := newReplyConfirmFixture(t, "rctnposter")
	seedEee(t, g.msgID, "damaged", "large", "over_100kg")
	database.DBConn.Exec("INSERT INTO users_emails (userid, email, preferred) VALUES (?, ?, 1)",
		g.posterID, fmt.Sprintf("tn%d@user.trashnothing.com", g.posterID))
	g.reply(t, "Still there?", true)
	assert.Nil(t, g.prompt(t), "nor is a reply to a TrashNothing poster")
}

func TestReplyConfirm_PosterWhoDeliversIsNotACarQuestion(t *testing.T) {
	f := newReplyConfirmFixture(t, "rcdelivers")
	seedEee(t, f.msgID, "reusable", "large", "over_100kg")
	database.DBConn.Exec("UPDATE messages SET deliverypossible = 1 WHERE id = ?", f.msgID)

	f.reply(t, "Yes please", true)
	assert.Nil(t, f.prompt(t), "the poster may bring it, so the car question does not arise")
}

func TestReplyConfirm_OnlyOffers(t *testing.T) {
	f := newReplyConfirmFixture(t, "rcwanted")
	database.DBConn.Exec("UPDATE messages SET type = 'Wanted' WHERE id = ?", f.msgID)
	seedEee(t, f.msgID, "damaged", "large", "over_100kg")

	f.reply(t, "I have one", true)
	assert.Nil(t, f.prompt(t))
}

func TestReplyConfirm_OnlyTheReplierSeesTheQuestion(t *testing.T) {
	f := newReplyConfirmFixture(t, "rcvisible")
	seedEee(t, f.msgID, "damaged", "medium", "5_20kg")
	f.reply(t, "Is it still available?", true)

	assert.Contains(t, f.messageTypes(t, f.replierToken), "Prompt")
	assert.NotContains(t, f.messageTypes(t, f.posterToken), "Prompt", "the poster must never see the question")
}

func TestReplyConfirm_OnlyTheReplierCanAnswer(t *testing.T) {
	f := newReplyConfirmFixture(t, "rcanswerer")
	seedEee(t, f.msgID, "damaged", "medium", "5_20kg")
	f.reply(t, "Is it still available?", true)

	p := f.prompt(t)
	if assert.NotNil(t, p) {
		assert.Equal(t, 403, f.answer(t, p.Chatmsgid, f.posterToken, "yes"))
	}
}

func TestReplyConfirm_YesLetsTheReplyThrough(t *testing.T) {
	f := newReplyConfirmFixture(t, "rcyes")
	seedEee(t, f.msgID, "damaged", "medium", "5_20kg")
	replyID := f.reply(t, "Is it still available?", true)

	p := f.prompt(t)
	if assert.NotNil(t, p) {
		assert.Equal(t, 200, f.answer(t, p.Chatmsgid, f.replierToken, "yes"))
		assert.Equal(t, "yes", *f.prompt(t).Answer)
		assert.Equal(t, "button", promptRecord(p.Chatmsgid))
	}

	// Still for chats:process-incoming to deliver, now that nothing holds it.
	required, _ := processingState(replyID)
	assert.Equal(t, 1, required)

	// The poster sees the question when the reply arrives, not before it:
	// ChatProcessService reveals both together.
	assert.NotContains(t, f.messageTypes(t, f.posterToken), "Prompt")
}

func TestReplyConfirm_NoDropsTheReply(t *testing.T) {
	f := newReplyConfirmFixture(t, "rcno")
	seedEee(t, f.msgID, "damaged", "medium", "5_20kg")
	replyID := f.reply(t, "Is it still available?", true)

	p := f.prompt(t)
	if assert.NotNil(t, p) {
		assert.Equal(t, 200, f.answer(t, p.Chatmsgid, f.replierToken, "no"))
	}

	required, successful := processingState(replyID)
	assert.Equal(t, 0, required)
	assert.Equal(t, 0, successful, "the poster never receives a reply the replier withdrew")

	var reason string
	database.DBConn.Table("chat_messages").Select("processingfailreason").Where("id = ?", replyID).Scan(&reason)
	assert.Equal(t, "ReplyWithdrawn", reason, "so a moderator can see why it never arrived")
	assert.NotContains(t, f.messageTypes(t, f.posterToken), "Prompt", "after a no the poster sees nothing")
}

func TestReplyConfirm_CarryingOnTheChatCountsAsYes(t *testing.T) {
	f := newReplyConfirmFixture(t, "rcfollowup")
	seedEee(t, f.msgID, "damaged", "medium", "5_20kg")
	replyID := f.reply(t, "Is it still available?", true)

	// Writing again instead of tapping a button means they still want it - and a second
	// message must not reach the poster ahead of the first.
	f.reply(t, "I can come tonight", false)

	p := f.prompt(t)
	if assert.NotNil(t, p) && assert.NotNil(t, p.Answer) {
		assert.Equal(t, "yes", *p.Answer)
		assert.Equal(t, "message", promptRecord(p.Chatmsgid), "so a tapped yes can be told from carrying on the chat")
	}

	required, _ := processingState(replyID)
	assert.Equal(t, 1, required)
	assert.NotContains(t, f.messageTypes(t, f.posterToken), "Prompt", "revealed with the reply, not before it")
}
