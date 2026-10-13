package test

import (
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/ux"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// The SysAdmin UX tab counts how repliers answer the question asked before a reply to a damaged
// or bulky offer is passed on: yes by button, yes by writing again, no, or never.
func TestUxReplyConfirm(t *testing.T) {
	ensureReplyConfirmColumns(t)

	token := uxSupportToken(t, "uxreplyconfirm")
	db := database.DBConn
	cleanup := func() {
		db.Exec("DELETE FROM chat_prompts WHERE kind = 'confirm_reply' AND created_at BETWEEN '2037-03-01' AND '2037-03-15'")
		ux.ResetCache()
	}
	cleanup()
	t.Cleanup(cleanup)

	prefix := uniquePrefix("uxrc")
	a := CreateTestUser(t, prefix+"_a", "User")
	b := CreateTestUser(t, prefix+"_b", "User")
	chatID := CreateTestChatRoom(t, a, &b, nil, "User2User")

	question := func(reason, created, answer, via, expires string) {
		msgID := CreateTestChatMessage(t, chatID, b, "question")
		var ans, answeredAt, how interface{}
		if answer != "" {
			ans, answeredAt, how = answer, created, via
		}
		require.NoError(t, db.Exec("INSERT INTO chat_prompts (chatmsgid, kind, reason, userid, options, answer, answered_at, "+
			"answered_via, expires_at, created_at) VALUES (?, 'confirm_reply', ?, ?, '[]', ?, ?, ?, ?, ?)",
			msgID, reason, a, ans, answeredAt, how, expires, created).Error)
	}

	// This period: damaged asked four times, car twice.
	question("damaged", "2037-03-08 10:00:00", "yes", "button", "2037-03-11 10:00:00")
	question("damaged", "2037-03-09 10:00:00", "yes", "message", "2037-03-12 10:00:00")
	question("damaged", "2037-03-09 11:00:00", "no", "button", "2037-03-12 11:00:00")
	question("damaged", "2037-03-10 10:00:00", "", "", "2020-01-01 00:00:00")
	question("car", "2037-03-12 10:00:00", "yes", "button", "2037-03-15 10:00:00")
	// Still answerable: TIMESTAMP stops at 2038, so this is as far ahead as it can go.
	question("car", "2037-03-14 10:00:00", "", "", "2038-01-01 00:00:00")
	// The previous period.
	question("car", "2037-03-02 10:00:00", "no", "button", "2037-03-05 10:00:00")

	var out ux.ReplyConfirmResponse
	require.Equal(t, 200, uxGet(t, token, "replyconfirm?start=2037-03-08&end=2037-03-14", &out))
	require.Len(t, out.Reasons, 2)

	car, damaged := out.Reasons[0], out.Reasons[1]
	assert.Equal(t, "car", car.Reason)
	assert.EqualValues(t, 2, car.Asked)
	assert.EqualValues(t, 1, car.YesButton)
	assert.EqualValues(t, 1, car.Waiting, "can still be answered")

	assert.Equal(t, "damaged", damaged.Reason)
	assert.EqualValues(t, 4, damaged.Asked)
	assert.EqualValues(t, 1, damaged.YesButton)
	assert.EqualValues(t, 1, damaged.YesMessage)
	assert.EqualValues(t, 1, damaged.No)
	assert.EqualValues(t, 1, damaged.NoAnswer, "expired unanswered, so the reply was dropped")
	assert.EqualValues(t, 0, damaged.Waiting)

	require.Len(t, out.Previous.Reasons, 1)
	assert.EqualValues(t, 1, out.Previous.Reasons[0].No)

	var raw map[string]interface{}
	uxGet(t, token, "replyconfirm?start=2037-03-08&end=2037-03-14", &raw)
	assert.ElementsMatch(t, []string{"reason", "asked", "yes_button", "yes_message", "no", "no_answer", "waiting"},
		mapKeys(raw["reasons"].([]interface{})[0].(map[string]interface{})))
}

func TestUxReplyConfirmNeedsSupport(t *testing.T) {
	prefix := uniquePrefix("uxrcuser")
	member := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, member)

	var out map[string]interface{}
	assert.Equal(t, 403, uxGet(t, token, "replyconfirm", &out))
}
