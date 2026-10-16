package test

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"regexp"
	"strings"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/ux"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// A reply to an OFFER that looks damaged, or looks like it needs a car to collect, is held
// while Freegle asks the replier to confirm, for the repliers whose arm of the experiment
// confirm-reply is ask. See chat/replyconfirm.go.

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
	groupID      uint64
	posterID     uint64
	replierID    uint64
	msgID        uint64
	chatID       uint64
	posterToken  string
	replierToken string
}

// newReplyConfirmFixture makes a poster, a replier and their chat, and serves every member the arm ask, so
// the question is asked as the tests below were written to expect. A test of the other arms calls
// forceReplyConfirmArm again.
func newReplyConfirmFixture(t *testing.T, name string) replyConfirmFixture {
	ensureReplyConfirmColumns(t)
	ensureFreegleUser(t)
	forceReplyConfirmArm(t, "ask")

	prefix := uniquePrefix(name)
	groupID := CreateTestGroup(t, prefix)
	f := replyConfirmFixture{groupID: groupID}
	f.posterID = CreateTestUser(t, prefix+"_poster", "User")
	f.replierID = CreateTestUser(t, prefix+"_replier", "User")
	f.msgID = CreateTestMessage(t, f.posterID, groupID, "OFFER: armchair ("+prefix+")", 51.5, -0.1)
	f.chatID = CreateTestChatRoom(t, f.replierID, &f.posterID, nil, "User2User")
	_, f.posterToken = CreateTestSession(t, f.posterID)
	_, f.replierToken = CreateTestSession(t, f.replierID)

	return f
}

func (f replyConfirmFixture) reply(t *testing.T, text string, refmsgid bool) uint64 {
	if refmsgid {
		return f.replyAbout(t, f.msgID, text)
	}

	return f.replyAbout(t, 0, text)
}

// replyAbout writes in the chat as the replier, naming a post unless msgID is 0.
func (f replyConfirmFixture) replyAbout(t *testing.T, msgID uint64, text string) uint64 {
	body := map[string]interface{}{"message": text}
	if msgID != 0 {
		body["refmsgid"] = msgID
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
		rows := replyConfirmExposures(t, f.replierID)
		if assert.Len(t, rows, 1, "one row for the reply, which the question names") {
			assert.Equal(t, "damaged", rows[0]["stratum"])
			assert.EqualValues(t, replyID, rows[0]["subject_id"])
		}
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
			rows := replyConfirmExposures(t, f.replierID)
			if assert.Len(t, rows, 1) {
				assert.Equal(t, "car", rows[0]["stratum"])
			}
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
		rows := replyConfirmExposures(t, f.replierID)
		if assert.Len(t, rows, 1) {
			assert.Equal(t, "both", rows[0]["stratum"])
		}
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

// ----------------------------------------------------------------------------- the experiment confirm-reply

// The experiment confirm-reply serves a replier one of two arms: control, whose reply goes through as it
// always did, and ask, whose reply is held while Freegle asks. Both arms leave one row in
// ux_experiment_exposures. See docs/developers/reference/ux-experiments.md, "Confirming a reply before it
// is passed on". Each test forces the arm it needs with forceReplyConfirmArm and reads the rows back by the
// experiment's name, so the name in ux.ExperimentArm and the name in ux.RecordExposure cannot drift apart.

// writeReplyConfirmConfig writes the two config rows an arm is read from, flushes the API's copy of them,
// and puts back what was there when the test ends. An empty experiments string writes no ux_experiments
// row, and haveSwitch false writes no ux_server_arms row.
func writeReplyConfirmConfig(t *testing.T, experiments string, switchValue string, haveSwitch bool) {
	t.Helper()
	db := database.DBConn

	var old []struct {
		Key   string
		Value string
	}
	db.Raw("SELECT `key` AS `key`, value AS value FROM config WHERE `key` IN ('ux_experiments', 'ux_server_arms')").Scan(&old)
	db.Exec("DELETE FROM config WHERE `key` IN ('ux_experiments', 'ux_server_arms')")
	if experiments != "" {
		db.Exec("INSERT INTO config (`key`, value) VALUES ('ux_experiments', ?)", experiments)
	}
	if haveSwitch {
		db.Exec("INSERT INTO config (`key`, value) VALUES ('ux_server_arms', ?)", switchValue)
	}
	ux.FlushServerArmCache()

	t.Cleanup(func() {
		db.Exec("DELETE FROM config WHERE `key` IN ('ux_experiments', 'ux_server_arms')")
		for _, r := range old {
			db.Exec("INSERT INTO config (`key`, value) VALUES (?, ?)", r.Key, r.Value)
		}
		ux.FlushServerArmCache()
	})
}

// forceReplyConfirmArm serves every member the given arm of confirm-reply, as an adopted entry, so its end
// time and the clock do not matter. An empty arm leaves no entry and no switch: nobody has an arm.
func forceReplyConfirmArm(t *testing.T, arm string) {
	t.Helper()
	if arm == "" {
		writeReplyConfirmConfig(t, "", "", false)

		return
	}

	writeReplyConfirmConfig(t, `{"confirm-reply":{"arms":{"`+arm+`":[0,99]},"adopted":true}}`, "1", true)
}

// replyConfirmExposures is what the experiment recorded for a replier, oldest first. The rows are removed
// when the test ends.
func replyConfirmExposures(t *testing.T, userid uint64) []map[string]interface{} {
	t.Helper()
	db := database.DBConn
	t.Cleanup(func() {
		db.Exec("DELETE FROM ux_experiment_exposures WHERE experiment = 'confirm-reply' AND userid = ?", userid)
	})

	var rows []map[string]interface{}
	db.Raw("SELECT * FROM ux_experiment_exposures WHERE experiment = 'confirm-reply' AND userid = ? ORDER BY id", userid).Scan(&rows)

	return rows
}

// hideExposureTable makes the exposure table missing, as it is on a database whose SQL has not been run.
func hideExposureTable(t *testing.T) {
	t.Helper()
	db := database.DBConn

	require.NoError(t, db.Exec("RENAME TABLE ux_experiment_exposures TO ux_experiment_exposures_hidden").Error)
	t.Cleanup(func() { db.Exec("RENAME TABLE ux_experiment_exposures_hidden TO ux_experiment_exposures") })
}

// breakExposureInserts leaves a table that can be read but not written: it has the four columns the
// exposure-exists read uses and not the column arm, so every insert fails. hideExposureTable cannot reach
// the transaction, because the read fails first and returns.
func breakExposureInserts(t *testing.T) {
	t.Helper()
	db := database.DBConn

	require.NoError(t, db.Exec("RENAME TABLE ux_experiment_exposures TO ux_experiment_exposures_hidden").Error)
	require.NoError(t, db.Exec("CREATE TABLE ux_experiment_exposures (experiment VARCHAR(64) NOT NULL, "+
		"subject_type VARCHAR(16) NOT NULL, subject_id BIGINT UNSIGNED NOT NULL, userid BIGINT UNSIGNED NOT NULL)").Error)
	t.Cleanup(func() {
		db.Exec("DROP TABLE ux_experiment_exposures")
		db.Exec("RENAME TABLE ux_experiment_exposures_hidden TO ux_experiment_exposures")
	})
}

// eligibleReplyConfirmFixture is a fixture whose post looks damaged, so a reply to it is in the experiment.
func eligibleReplyConfirmFixture(t *testing.T, name string) replyConfirmFixture {
	f := newReplyConfirmFixture(t, name)
	seedEee(t, f.msgID, "damaged", "medium", "5_20kg")

	return f
}

// promptsHolding is how many questions name any of these replies as the one they hold.
func promptsHolding(replyIDs ...uint64) int64 {
	var n int64
	database.DBConn.Raw("SELECT COUNT(*) FROM chat_prompts WHERE heldchatmsgid IN ?", replyIDs).Scan(&n)

	return n
}

func chatMessageCount(chatID uint64) int64 {
	var n int64
	database.DBConn.Raw("SELECT COUNT(*) FROM chat_messages WHERE chatid = ?", chatID).Scan(&n)

	return n
}

// replyConfirmComparable is a chat message as stored, without the columns that differ between any two
// messages: its own id, who and where it is, which post it names, and when it was written.
func replyConfirmComparable(id uint64) map[string]interface{} {
	var row map[string]interface{}
	database.DBConn.Raw("SELECT * FROM chat_messages WHERE id = ?", id).Scan(&row)
	for _, k := range []string{"id", "chatid", "userid", "refmsgid", "date"} {
		delete(row, k)
	}

	return row
}

// posterView is the reply as the poster's chat fetch returns it, without the fields that differ between
// any two messages.
func (f replyConfirmFixture) posterView(t *testing.T, replyID uint64) map[string]interface{} {
	resp, err := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/chat/%d/message?jwt=%s", f.chatID, f.posterToken), nil), -1)
	require.NoError(t, err)
	require.Equal(t, 200, resp.StatusCode)

	var msgs []map[string]interface{}
	require.NoError(t, json.NewDecoder(resp.Body).Decode(&msgs))

	for _, m := range msgs {
		if id, ok := m["id"].(float64); ok && uint64(id) == replyID {
			for _, k := range []string{"id", "chatid", "userid", "refmsgid", "date"} {
				delete(m, k)
			}

			return m
		}
	}

	t.Fatalf("the poster's fetch does not hold reply %d", replyID)

	return nil
}

// fixtureWithAnotherBucket is a fixture whose poster has a different bucket for confirm-reply from the
// replier, so an arm looked up for the poster by mistake would not match the replier's.
func fixtureWithAnotherBucket(t *testing.T, name string) (replyConfirmFixture, int) {
	for i := 0; i < 40; i++ {
		f := eligibleReplyConfirmFixture(t, name)
		b := ux.ExperimentBucket(f.replierID, "confirm-reply", "")
		if ux.ExperimentBucket(f.posterID, "confirm-reply", "") != b {
			return f, b
		}
	}

	t.Fatal("could not make a poster whose bucket differs from the replier's")

	return replyConfirmFixture{}, 0
}

func TestReplyConfirm_ControlIsNotAskedAndLeavesOneRow(t *testing.T) {
	f := eligibleReplyConfirmFixture(t, "rccontrol")
	g := eligibleReplyConfirmFixture(t, "rcnoarm")
	var eeeID uint64
	database.DBConn.Raw("SELECT id FROM messages_eee WHERE msgid = ? ORDER BY id DESC LIMIT 1", f.msgID).Scan(&eeeID)

	forceReplyConfirmArm(t, "control")
	controlReply := f.reply(t, "Is it still available?", true)
	forceReplyConfirmArm(t, "")
	plainReply := g.reply(t, "Is it still available?", true)

	assert.Nil(t, f.prompt(t), "control is not asked")
	required, successful := processingState(controlReply)
	assert.Equal(t, 1, required, "unprocessed, as every new reply is")
	assert.Equal(t, 0, successful)
	assert.EqualValues(t, 0, promptsHolding(controlReply), "and not held for an answer")

	rows := replyConfirmExposures(t, f.replierID)
	if assert.Len(t, rows, 1) {
		assert.Equal(t, "confirm-reply", rows[0]["experiment"])
		assert.Equal(t, "control", rows[0]["arm"])
		assert.Equal(t, "reply", rows[0]["subject_type"])
		assert.EqualValues(t, controlReply, rows[0]["subject_id"])
		assert.EqualValues(t, f.replierID, rows[0]["userid"])
		assert.Equal(t, "damaged", rows[0]["stratum"])
		assert.JSONEq(t, fmt.Sprintf(`{"eee_id":%d,"deliverypossible":0}`, eeeID), fmt.Sprint(rows[0]["detail"]))
	}
	assert.Empty(t, replyConfirmExposures(t, g.replierID), "a member with no arm leaves no row")

	// So a control reply is today's reply apart from its one row: the same stored message, the same
	// number of messages in the chat, nothing held, and the same fields for the poster once it is delivered.
	assert.Equal(t, replyConfirmComparable(plainReply), replyConfirmComparable(controlReply))
	assert.Equal(t, chatMessageCount(g.chatID), chatMessageCount(f.chatID))
	assert.EqualValues(t, 1, chatMessageCount(f.chatID), "no question beside the reply")
	assert.EqualValues(t, 0, promptsHolding(controlReply, plainReply))

	var ripple int64
	database.DBConn.Raw("SELECT COUNT(*) FROM rippling_held_replies WHERE chatmsgid IN ?", []uint64{controlReply, plainReply}).Scan(&ripple)
	assert.EqualValues(t, 0, ripple)

	database.DBConn.Exec("UPDATE chat_messages SET processingrequired = 0, processingsuccessful = 1 WHERE id IN ?", []uint64{controlReply, plainReply})
	assert.Equal(t, g.posterView(t, plainReply), f.posterView(t, controlReply))
}

func TestReplyConfirm_AskWritesTheRowAndTheQuestionTogether(t *testing.T) {
	f := eligibleReplyConfirmFixture(t, "rcask")
	forceReplyConfirmArm(t, "ask")

	replyID := f.reply(t, "Is it still available?", true)

	rows := replyConfirmExposures(t, f.replierID)
	if assert.Len(t, rows, 1) {
		assert.Equal(t, "ask", rows[0]["arm"])
		assert.EqualValues(t, replyID, rows[0]["subject_id"])
		assert.Equal(t, "damaged", rows[0]["stratum"])
	}

	p := f.prompt(t)
	if assert.NotNil(t, p) {
		assert.Equal(t, replyID, *p.Heldchatmsgid, "the question holds the reply the row names")
		assert.Equal(t, f.replierID, *p.Userid)
	}
	assert.EqualValues(t, 1, promptsHolding(replyID))
}

func TestReplyConfirm_WithNoArmThereIsNoQuestionAndNoRow(t *testing.T) {
	soon := func(seconds int64) string {
		return fmt.Sprintf(`{"confirm-reply":{"arms":{"ask":[0,99]},"until":%d}}`, time.Now().Unix()+seconds)
	}
	cases := []struct {
		name        string
		experiments string
		switchValue string
		haveSwitch  bool
	}{
		{"NothingInTheConfig", "", "", false},
		{"TheExperimentWithNoSwitch", `{"confirm-reply":{"arms":{"ask":[0,99]},"adopted":true}}`, "", false},
		{"TheSwitchIsZero", `{"confirm-reply":{"arms":{"ask":[0,99]},"adopted":true}}`, "0", true},
		{"AnEntryForAnotherExperimentOnly", `{"digest-subject":{"arms":{"ask":[0,99]},"adopted":true}}`, "1", true},
		{"ARunningEntryThatEndedAnHourAgo", soon(-3600), "1", true},
		{"ARunningEntryMoreThanFifteenDaysAhead", soon(20 * 24 * 3600), "1", true},
	}

	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			f := eligibleReplyConfirmFixture(t, "rcnoarm")
			writeReplyConfirmConfig(t, c.experiments, c.switchValue, c.haveSwitch)

			replyID := f.reply(t, "Is it still available?", true)

			assert.Nil(t, f.prompt(t), "nobody is asked without an arm")
			assert.EqualValues(t, 0, promptsHolding(replyID))
			assert.Empty(t, replyConfirmExposures(t, f.replierID), "and no row is written")
			required, _ := processingState(replyID)
			assert.Equal(t, 1, required, "the reply is created as it always was")
		})
	}
}

func TestReplyConfirm_ARippleHeldReplyIsNotAskedAndLeavesNoRow(t *testing.T) {
	db := database.DBConn
	f := eligibleReplyConfirmFixture(t, "rcripple")
	CreateTestMembership(t, f.posterID, f.groupID, "Member")
	CreateTestMembership(t, f.replierID, f.groupID, "Member")
	db.Exec(`UPDATE users SET settings = '{"mylocation":{"lat":51.5,"lng":-0.1}}' WHERE id = ?`, f.replierID)

	// A reach that exists and does not cover the replier, far to the east.
	const outside = "POLYGON((5.0 51.4,5.2 51.4,5.2 51.6,5.0 51.6,5.0 51.4))"
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon_cells, outer_bound) VALUES (?, 51.5, -0.1, ?, "+
		"ST_Envelope(ST_GeomFromText('"+outside+"', 3857)))", f.msgID, mustRasterize(t, outside))
	t.Cleanup(func() {
		db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", f.msgID)
		db.Exec("DELETE FROM rippling_held_replies WHERE msgid = ?", f.msgID)
	})
	stubReachEvalMax(t, "out")
	forceReplyConfirmArm(t, "ask")

	replyID := f.reply(t, "I'd like this please", true)

	var held int64
	db.Raw("SELECT COUNT(*) FROM rippling_held_replies WHERE chatmsgid = ? AND status = 'held'", replyID).Scan(&held)
	assert.EqualValues(t, 1, held, "rippling holds the reply")
	assert.Nil(t, f.prompt(t), "so it is not asked about as well: the poster would see the question before the reply")
	assert.EqualValues(t, 0, promptsHolding(replyID))
	assert.Empty(t, replyConfirmExposures(t, f.replierID), "and it is not in the experiment")
}

func TestReplyConfirm_ASecondReplyToTheSamePostAddsNoRow(t *testing.T) {
	for _, arm := range []string{"ask", "control"} {
		for _, c := range []struct{ name, text string }{
			{"TheSameTextTwice", "Is it still available?"},
			{"OtherText", "I can come tonight"},
		} {
			t.Run(arm+"/"+c.name, func(t *testing.T) {
				f := eligibleReplyConfirmFixture(t, "rcsecond")
				forceReplyConfirmArm(t, arm)

				first := f.reply(t, "Is it still available?", true)
				f.reply(t, c.text, true)

				rows := replyConfirmExposures(t, f.replierID)
				if assert.Len(t, rows, 1, "one row for the replier and the post, whatever the number of replies") {
					assert.Equal(t, arm, rows[0]["arm"])
					assert.EqualValues(t, first, rows[0]["subject_id"], "and it is the first reply's")
				}

				if arm == "control" {
					assert.Nil(t, f.prompt(t))

					return
				}

				p := f.prompt(t) // asserts there is only one question
				if assert.NotNil(t, p) && assert.NotNil(t, p.Answer) {
					assert.Equal(t, "yes", *p.Answer, "the further message answered the first question")
					assert.Equal(t, "message", promptRecord(p.Chatmsgid))
				}
				var unanswered int64
				database.DBConn.Raw("SELECT COUNT(*) FROM chat_prompts WHERE heldchatmsgid = ? AND answered_at IS NULL", first).Scan(&unanswered)
				assert.EqualValues(t, 0, unanswered, "so the first reply is no longer held")
			})
		}
	}
}

func TestReplyConfirm_AReplyToAnotherPostGetsTheSameArmAndItsOwnRow(t *testing.T) {
	for _, arm := range []string{"ask", "control"} {
		t.Run(arm, func(t *testing.T) {
			f := eligibleReplyConfirmFixture(t, "rcother")
			second := CreateTestMessage(t, f.posterID, f.groupID, "OFFER: bookcase ("+uniquePrefix("rcother")+")", 51.5, -0.1)
			seedEee(t, second, "reusable", "large", "5_20kg")
			forceReplyConfirmArm(t, arm)

			first := f.reply(t, "Is it still available?", true)
			again := f.replyAbout(t, second, "And is the bookcase still there?")

			rows := replyConfirmExposures(t, f.replierID)
			if assert.Len(t, rows, 2, "each post is its own unit") {
				assert.Equal(t, arm, rows[0]["arm"])
				assert.Equal(t, arm, rows[1]["arm"], "the same arm: it is the replier's")
				assert.EqualValues(t, first, rows[0]["subject_id"])
				assert.EqualValues(t, again, rows[1]["subject_id"])
				assert.Equal(t, "damaged", rows[0]["stratum"])
				assert.Equal(t, "car", rows[1]["stratum"])
			}
		})
	}
}

func TestReplyConfirm_TheRowCarriesTheStratum(t *testing.T) {
	cases := []struct {
		name, condition, size, weight, stratum string
	}{
		{"Damaged", "damaged", "small", "1_5kg", "damaged"},
		{"LargeNeedsACar", "reusable", "large", "5_20kg", "car"},
		{"HeavyNeedsACar", "reusable", "medium", "20_100kg", "car"},
		{"Both", "damaged", "large", "over_100kg", "both"},
	}

	for _, arm := range []string{"ask", "control"} {
		for _, c := range cases {
			t.Run(arm+"/"+c.name, func(t *testing.T) {
				f := newReplyConfirmFixture(t, "rcstratum")
				seedEee(t, f.msgID, c.condition, c.size, c.weight)
				forceReplyConfirmArm(t, arm)

				f.reply(t, "Is it still available?", true)

				rows := replyConfirmExposures(t, f.replierID)
				if assert.Len(t, rows, 1) {
					assert.Equal(t, c.stratum, rows[0]["stratum"])
					assert.Equal(t, arm, rows[0]["arm"])
				}
			})
		}
	}
}

func TestReplyConfirm_AFailedRowStillDeliversAControlReply(t *testing.T) {
	t.Run("TheTableIsMissing", func(t *testing.T) {
		f := eligibleReplyConfirmFixture(t, "rcmissingc")
		forceReplyConfirmArm(t, "control")
		hideExposureTable(t)

		replyID := f.reply(t, "Is it still available?", true)

		required, _ := processingState(replyID)
		assert.Equal(t, 1, required, "the reply is created and left for processing, as it always was")
		assert.Nil(t, f.prompt(t))
		var n int64
		database.DBConn.Raw("SELECT COUNT(*) FROM ux_experiment_exposures_hidden WHERE userid = ?", f.replierID).Scan(&n)
		assert.EqualValues(t, 0, n, "no row")
	})

	t.Run("TheInsertFails", func(t *testing.T) {
		f := eligibleReplyConfirmFixture(t, "rcbrokenc")
		forceReplyConfirmArm(t, "control")
		breakExposureInserts(t)

		replyID := f.reply(t, "Is it still available?", true)

		required, _ := processingState(replyID)
		assert.Equal(t, 1, required)
		assert.Nil(t, f.prompt(t))
		var n int64
		database.DBConn.Raw("SELECT COUNT(*) FROM ux_experiment_exposures WHERE userid = ?", f.replierID).Scan(&n)
		assert.EqualValues(t, 0, n, "no row")
	})
}

func TestReplyConfirm_AFailedRowLeavesNoQuestionAndNoHold(t *testing.T) {
	check := func(t *testing.T, f replyConfirmFixture, replyID uint64) {
		assert.Nil(t, f.prompt(t), "no question")
		assert.EqualValues(t, 0, promptsHolding(replyID), "so nothing holds the reply, and chats:process-incoming would deliver it")
		assert.EqualValues(t, 1, chatMessageCount(f.chatID), "and the chat holds the reply alone")
		required, _ := processingState(replyID)
		assert.Equal(t, 1, required, "the reply is created, as it was without the experiment")
	}

	t.Run("TheTableIsMissing", func(t *testing.T) {
		f := eligibleReplyConfirmFixture(t, "rcmissinga")
		forceReplyConfirmArm(t, "ask")
		hideExposureTable(t)

		check(t, f, f.reply(t, "Is it still available?", true))
	})

	t.Run("TheInsertFails", func(t *testing.T) {
		f := eligibleReplyConfirmFixture(t, "rcbrokena")
		forceReplyConfirmArm(t, "ask")
		breakExposureInserts(t)

		check(t, f, f.reply(t, "Is it still available?", true))
	})
}

func TestReplyConfirm_AnArmThisCodeHasNoMeaningForIsRecordedAndLeftAlone(t *testing.T) {
	f := eligibleReplyConfirmFixture(t, "rcotherarm")
	writeReplyConfirmConfig(t, `{"confirm-reply":{"arms":{"b":[0,99]},"adopted":true}}`, "1", true)

	replyID := f.reply(t, "Is it still available?", true)

	rows := replyConfirmExposures(t, f.replierID)
	if assert.Len(t, rows, 1) {
		assert.Equal(t, "b", rows[0]["arm"])
	}
	assert.Nil(t, f.prompt(t), "only the arm ask asks")
	assert.EqualValues(t, 0, promptsHolding(replyID))
}

func TestReplyConfirm_TheRepliersBucketDecidesTheArm(t *testing.T) {
	// The replier's bucket is the only one in the arm that is first in the entry. The poster's is not, so
	// an arm looked up for the poster would fall through to the second arm.
	f, b := fixtureWithAnotherBucket(t, "rcbucket")
	writeReplyConfirmConfig(t, fmt.Sprintf(`{"confirm-reply":{"arms":{"ask":[%d,%d],"control":[0,99]},"adopted":true}}`, b, b), "1", true)
	f.reply(t, "Is it still available?", true)
	rows := replyConfirmExposures(t, f.replierID)
	if assert.Len(t, rows, 1) {
		assert.Equal(t, "ask", rows[0]["arm"], "the replier's bucket %d", b)
	}
	assert.NotNil(t, f.prompt(t))

	g, c := fixtureWithAnotherBucket(t, "rcbucketswapped")
	writeReplyConfirmConfig(t, fmt.Sprintf(`{"confirm-reply":{"arms":{"control":[%d,%d],"ask":[0,99]},"adopted":true}}`, c, c), "1", true)
	g.reply(t, "Is it still available?", true)
	rows = replyConfirmExposures(t, g.replierID)
	if assert.Len(t, rows, 1) {
		assert.Equal(t, "control", rows[0]["arm"], "the replier's bucket %d", c)
	}
	assert.Nil(t, g.prompt(t))
}

func TestReplyConfirm_TheSwitchListEntryHasTheShapeTheCodeExpects(t *testing.T) {
	s, ok := ux.ServerSwitches["confirm-reply"]
	if !assert.True(t, ok, "the Go switch list holds the experiment") {
		return
	}

	assert.Equal(t, "confirm-reply", s.Name)
	assert.Regexp(t, regexp.MustCompile(`^\d{4}-\d{2}-\d{2}$`), s.Opened)
	assert.Regexp(t, regexp.MustCompile(`^https://github.com/Freegle/Iznik/pull/[0-9]+$`), s.PR)
	assert.Equal(t, "server", s.Channel)
	assert.Equal(t, []string{"control", "ask"}, s.Arms)
	assert.Equal(t, "confirm_reply", s.Metric.Source)
	assert.Equal(t, 168, s.Metric.WindowHours)
}

// ----------------------------------------------------------------------------- two things the hold must leave alone

func TestReplyConfirm_MyPostsDoesNotListAHeldReply(t *testing.T) {
	// listed is what the poster's My Posts shows for the post: the count and the list of replies.
	listed := func(t *testing.T, f replyConfirmFixture) (int, int) {
		m := fetchMessage(t, f.posterToken, f.msgID)

		return m.Replycount, len(m.MessageReply)
	}
	reply := func(t *testing.T, arm string) (replyConfirmFixture, uint64) {
		f := eligibleReplyConfirmFixture(t, "rcmyposts")
		CreateTestMembership(t, f.posterID, f.groupID, "Member")
		CreateTestMembership(t, f.replierID, f.groupID, "Member")
		forceReplyConfirmArm(t, arm)

		return f, f.reply(t, "Is it still available?", true)
	}

	t.Run("WhileTheQuestionIsOpen", func(t *testing.T) {
		f, _ := reply(t, "ask")

		count, replies := listed(t, f)
		assert.Equal(t, 0, count, "a reply that waits for its sender's answer is not counted for the poster")
		assert.Equal(t, 0, replies, "and its sender is not listed")
	})

	t.Run("AfterANo", func(t *testing.T) {
		f, _ := reply(t, "ask")
		assert.Equal(t, 200, f.answer(t, f.prompt(t).Chatmsgid, f.replierToken, "no"))

		count, replies := listed(t, f)
		assert.Equal(t, 0, count, "a reply the replier withdrew never reaches the poster")
		assert.Equal(t, 0, replies)
	})

	t.Run("AfterAYes", func(t *testing.T) {
		f, _ := reply(t, "ask")
		assert.Equal(t, 200, f.answer(t, f.prompt(t).Chatmsgid, f.replierToken, "yes"))

		count, replies := listed(t, f)
		assert.Equal(t, 1, count, "listed, as any reply is that the batch has yet to deliver")
		assert.Equal(t, 1, replies)
	})

	t.Run("AControlReplyIsListedAtOnce", func(t *testing.T) {
		f, _ := reply(t, "control")

		count, replies := listed(t, f)
		assert.Equal(t, 1, count)
		assert.Equal(t, 1, replies)
	})
}

func TestReplyConfirm_AnsweringIsNotCountedAsAFreegleChatPromptAnswer(t *testing.T) {
	promptAnswered := func() int64 {
		var n int64
		database.DBConn.Raw("SELECT COALESCE(SUM(count), 0) FROM firstreply_event_metrics WHERE day = CURDATE() AND event = 'prompt_answered'").Scan(&n)

		return n
	}

	// A reply confirmation is asked of a replier in a chat between two members. It is not the Freegle chat's measure.
	f := eligibleReplyConfirmFixture(t, "rcmetric")
	f.reply(t, "Is it still available?", true)
	before := promptAnswered()
	assert.Equal(t, 200, f.answer(t, f.prompt(t).Chatmsgid, f.replierToken, "yes"))
	assert.Equal(t, before, promptAnswered(), "answering a reply confirmation leaves the Freegle chat's count alone")

	// A prompt of another kind is a poster answering Freegle's question in their own chat, and it counts.
	prefix := uniquePrefix("rcmetricdelivery")
	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	freegleID := CreateTestUser(t, prefix+"_freegle", "User")
	msgID := CreateTestMessage(t, posterID, groupID, "OFFER: metric test", 51.5, -0.1)
	chatID := CreateTestChatRoom(t, freegleID, &posterID, nil, "User2User")
	chatMsgID := seedPrompt(t, chatID, freegleID, msgID, "delivery", deliveryOptions)
	t.Cleanup(func() {
		database.DBConn.Exec("DELETE FROM chat_prompts WHERE chatmsgid = ?", chatMsgID)
		database.DBConn.Exec("DELETE FROM chat_messages WHERE chatid = ?", chatID)
	})
	_, token := CreateTestSession(t, posterID)
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/chat/%d/message/%d/prompt?jwt=%s", chatID, chatMsgID, token),
		strings.NewReader(`{"answer":"maybe"}`))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, -1)
	require.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	assert.Equal(t, before+1, promptAnswered(), "a delivery answer is counted, so the first assertion is not true of everything")
}
