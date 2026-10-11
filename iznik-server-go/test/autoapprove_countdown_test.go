package test

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

// getAutoapproveatField fetches a message as the given user and returns the
// autoapproveat value for the named group (nil if absent / not a mod / not pending).
func getAutoapproveatField(t *testing.T, msgid uint64, groupid uint64, token string) interface{} {
	resp, err := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/message/%d?jwt=%s", msgid, token), nil))
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)
	var body map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&body)
	groups, _ := body["groups"].([]interface{})
	for _, g := range groups {
		gm, _ := g.(map[string]interface{})
		if gid, ok := gm["groupid"].(float64); ok && uint64(gid) == groupid {
			return gm["autoapproveat"]
		}
	}
	return nil
}

// insertAutomodRow records a messages_automod decision for (msgid, groupid), as the
// automod flowchart would after judging a Pending copy. createdMinutesAgo backdates
// created (0 = just now); pass a value bigger than how long ago the post was last edited
// to produce a stale row.
func insertAutomodRow(t *testing.T, msgid uint64, groupid uint64, verdict string, createdMinutesAgo int) {
	sql := fmt.Sprintf(
		"INSERT INTO messages_automod (msgid, groupid, mode, chart_version, verdict, end_node, reason, path, created) "+
			"VALUES (?, ?, 'approve', 'v1', ?, 'test_end', NULL, '{}', NOW() - INTERVAL %d MINUTE)", createdMinutesAgo)
	res := database.DBConn.Exec(sql, msgid, groupid, verdict)
	assert.NoError(t, res.Error)
}

// A3: loading the Pending queue bumps autoapprove_hold_until to >= NOW()+10m
// (extend-only — an existing longer hold is never shortened).
func TestListMessagesMTPendingBumpsHold(t *testing.T) {
	prefix := uniquePrefix("hold_bump")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	// The hold is read only on communities in the post-moderation trial, so it is only
	// written there (see the outside-trial check at the end).
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "")
	t.Setenv("FREEGLE_AUTOAPPROVE_TRIAL_GROUPS", fmt.Sprintf("%d", groupID))
	poster := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, poster, groupID, "Member")
	CreateTestMembership(t, modID, groupID, "Moderator")
	db.Exec("UPDATE memberships SET ourPostingStatus = NULL WHERE userid = ? AND groupid = ?", poster, groupID)
	_, modToken := CreateTestSession(t, modID)

	// Fresh pending post with no hold. contentcheck_checked_at must be set so the
	// post is visible in the Pending list (else the content-check filter hides it).
	freshMsg := CreateTestMessage(t, poster, groupID, prefix+" fresh pending", 52.0, -1.0)
	// Pending post already held 60 min out — extend-only must not shorten it.
	heldMsg := CreateTestMessage(t, poster, groupID, prefix+" held pending", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Pending', arrival=NOW(), contentcheck_checked_at=NOW(), autoapprove_hold_until=NULL WHERE msgid=?", freshMsg)
	db.Exec("UPDATE messages_groups SET collection='Pending', arrival=NOW(), contentcheck_checked_at=NOW(), autoapprove_hold_until=NOW() + INTERVAL 60 MINUTE WHERE msgid=?", heldMsg)

	resp, err := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/modtools/messages?groupid=%d&collection=Pending&jwt=%s", groupID, modToken), nil))
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var freshSecs int64
	db.Raw("SELECT TIMESTAMPDIFF(SECOND, NOW(), autoapprove_hold_until) FROM messages_groups WHERE msgid=? AND groupid=?", freshMsg, groupID).Scan(&freshSecs)
	assert.GreaterOrEqual(t, freshSecs, int64(9*60), "fresh pending post should be held >= ~10 min after load")

	var heldSecs int64
	db.Raw("SELECT TIMESTAMPDIFF(SECOND, NOW(), autoapprove_hold_until) FROM messages_groups WHERE msgid=? AND groupid=?", heldMsg, groupID).Scan(&heldSecs)
	assert.Greater(t, heldSecs, int64(30*60), "existing longer hold must not be shortened (extend-only)")

	// Outside the trial nothing reads the hold, so loading the Pending queue writes nothing.
	t.Setenv("FREEGLE_AUTOAPPROVE_TRIAL_GROUPS", "")
	db.Exec("UPDATE messages_groups SET autoapprove_hold_until=NULL WHERE msgid=?", freshMsg)
	resp, err = getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/modtools/messages?groupid=%d&collection=Pending&jwt=%s", groupID, modToken), nil))
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)
	var untouched int64
	db.Raw("SELECT COUNT(*) FROM messages_groups WHERE msgid=? AND groupid=? AND autoapprove_hold_until IS NULL", freshMsg, groupID).Scan(&untouched)
	assert.Equal(t, int64(1), untouched, "a community outside the trial gets no hold written on load")

	db.Exec("DELETE FROM messages_groups WHERE msgid IN (?, ?)", freshMsg, heldMsg)
	db.Exec("DELETE FROM messages WHERE id IN (?, ?)", freshMsg, heldMsg)
}

// A4: GET /message/:id exposes autoapproveat only for Pending posts viewed by a
// moderator; clean-path posts get a time, danger-signalled posts get nil, and
// non-mods never see it.
func TestAutoapproveatPendingModGating(t *testing.T) {
	// This test exercises the clean 20-minute path, which is dark by default —
	// enable it (the rollout gate itself is covered by TestAutoapproveatRolloutGate).
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "true")
	prefix := uniquePrefix("autoapproveat")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	regularID := CreateTestUser(t, prefix+"_reg", "User")
	CreateTestMembership(t, poster, groupID, "Member")
	CreateTestMembership(t, modID, groupID, "Moderator")
	CreateTestMembership(t, regularID, groupID, "Member")
	db.Exec("UPDATE memberships SET ourPostingStatus = NULL WHERE userid = ? AND groupid = ?", poster, groupID)
	_, modToken := CreateTestSession(t, modID)
	_, regToken := CreateTestSession(t, regularID)

	// Clean-path pending post: NULL poster, content-check clean, arrival 5 min ago, and the
	// flowchart's own decision recorded as a current approve.
	cleanMsg := CreateTestMessage(t, poster, groupID, prefix+" clean pending", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Pending', arrival=NOW() - INTERVAL 5 MINUTE, contentcheck_checked_at=NOW() - INTERVAL 4 MINUTE, contentcheck_reasons=NULL, autoapprove_hold_until=NULL WHERE msgid=?", cleanMsg)
	insertAutomodRow(t, cleanMsg, groupID, "approve", 0)
	defer db.Exec("DELETE FROM messages_automod WHERE msgid=?", cleanMsg)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid=?", cleanMsg)
	defer db.Exec("DELETE FROM messages WHERE id=?", cleanMsg)

	// Mod sees autoapproveat.
	assert.NotNil(t, getAutoapproveatField(t, cleanMsg, groupID, modToken),
		"mod should see autoapproveat on a clean pending post with an approve verdict")
	// Non-mod does not.
	assert.Nil(t, getAutoapproveatField(t, cleanMsg, groupID, regToken),
		"non-mod must not see autoapproveat")
}

// The wait before a clean post publishes itself is the same for every community. A
// leftover settings.autoapprove.delay_minutes on the group must not shorten the countdown,
// and FREEGLE_AUTOAPPROVE_DELAY_MINUTES (the figure the cron reads) must lengthen it.
func TestAutoapproveatDelayIsSiteWide(t *testing.T) {
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "true")
	prefix := uniquePrefix("aadelaysite")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, poster, groupID, "Member")
	CreateTestMembership(t, modID, groupID, "Moderator")
	db.Exec("UPDATE memberships SET ourPostingStatus = NULL WHERE userid = ? AND groupid = ?", poster, groupID)
	// A community that tried to shorten the wait to 5 minutes.
	db.Exec("UPDATE `groups` SET settings = JSON_SET(COALESCE(settings, '{}'), '$.autoapprove.delay_minutes', 5) WHERE id = ?", groupID)
	_, modToken := CreateTestSession(t, modID)

	msg := CreateTestMessage(t, poster, groupID, prefix+" clean pending", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Pending', arrival=NOW() - INTERVAL 5 MINUTE, contentcheck_checked_at=NOW() - INTERVAL 4 MINUTE, contentcheck_reasons=NULL, autoapprove_hold_until=NULL WHERE msgid=?", msg)
	// Decided a minute after the content check, as the chart normally is.
	insertAutomodRow(t, msg, groupID, "approve", 4)
	defer db.Exec("DELETE FROM messages_automod WHERE msgid=?", msg)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid=?", msg)
	defer db.Exec("DELETE FROM messages WHERE id=?", msg)

	parseAt := func(v interface{}) time.Time {
		s, ok := v.(string)
		assert.True(t, ok, "autoapproveat should be a time string, got %#v", v)
		at, err := time.Parse(time.RFC3339, s)
		assert.NoError(t, err)
		return at
	}

	// Site-wide 20 minutes from a decision 4 minutes ago: about 16 minutes away, not
	// already due as the group's 5-minute figure would make it.
	at := parseAt(getAutoapproveatField(t, msg, groupID, modToken))
	assert.True(t, at.After(time.Now().Add(12*time.Minute)),
		"a community setting must not shorten the site-wide wait; got %v", at)
	assert.True(t, at.Before(time.Now().Add(18*time.Minute)),
		"the countdown should be the site-wide 20 minutes; got %v", at)

	// The cron's own figure moves the countdown.
	t.Setenv("FREEGLE_AUTOAPPROVE_DELAY_MINUTES", "60")
	at = parseAt(getAutoapproveatField(t, msg, groupID, modToken))
	assert.True(t, at.After(time.Now().Add(50*time.Minute)),
		"FREEGLE_AUTOAPPROVE_DELAY_MINUTES must set the countdown; got %v", at)
}

// The wait counts from the chart's decision when that is later than arrival, as the cron's
// own query does: a verdict that lands late (service down, backlog) gives moderators the full
// window from then, instead of a post that is already overdue publishing the minute its row
// is written with no countdown ever shown.
func TestAutoapproveatCountsFromTheDecision(t *testing.T) {
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "true")
	prefix := uniquePrefix("aadecided")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, poster, groupID, "Member")
	CreateTestMembership(t, modID, groupID, "Moderator")
	db.Exec("UPDATE memberships SET ourPostingStatus = NULL WHERE userid = ? AND groupid = ?", poster, groupID)
	_, modToken := CreateTestSession(t, modID)

	// Arrived two hours ago; the review only decided three minutes ago.
	msg := CreateTestMessage(t, poster, groupID, prefix+" late decision", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Pending', arrival=NOW() - INTERVAL 2 HOUR, contentcheck_checked_at=NOW() - INTERVAL 2 HOUR, contentcheck_reasons=NULL, autoapprove_hold_until=NULL WHERE msgid=?", msg)
	insertAutomodRow(t, msg, groupID, "approve", 3)
	defer db.Exec("DELETE FROM messages_automod WHERE msgid=?", msg)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid=?", msg)
	defer db.Exec("DELETE FROM messages WHERE id=?", msg)

	v := getAutoapproveatField(t, msg, groupID, modToken)
	s, ok := v.(string)
	assert.True(t, ok, "autoapproveat should be a time string, got %#v", v)
	at, err := time.Parse(time.RFC3339, s)
	assert.NoError(t, err)
	assert.True(t, at.After(time.Now().Add(14*time.Minute)),
		"the countdown should run 20 minutes from the decision, not be overdue; got %v", at)
	assert.True(t, at.Before(time.Now().Add(19*time.Minute)),
		"the countdown should run 20 minutes from the decision; got %v", at)
}

// A copy the batch picked for the quality-check sample waits for a moderator, whatever the
// chart decided, so it shows no countdown. An unsampled copy with an approve decision does.
func TestAutoapproveatQualitySample(t *testing.T) {
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "true")
	prefix := uniquePrefix("aasample")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, poster, groupID, "Member")
	CreateTestMembership(t, modID, groupID, "Moderator")
	db.Exec("UPDATE memberships SET ourPostingStatus = NULL WHERE userid = ? AND groupid = ?", poster, groupID)
	_, modToken := CreateTestSession(t, modID)

	msg := CreateTestMessage(t, poster, groupID, prefix+" clean pending", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Pending', arrival=NOW() - INTERVAL 5 MINUTE, contentcheck_checked_at=NOW() - INTERVAL 4 MINUTE, autoapprove_hold_until=NULL, quality_sample=0 WHERE msgid=?", msg)
	insertAutomodRow(t, msg, groupID, "approve", 0)
	defer db.Exec("DELETE FROM messages_automod WHERE msgid=?", msg)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid=?", msg)
	defer db.Exec("DELETE FROM messages WHERE id=?", msg)

	assert.NotNil(t, getAutoapproveatField(t, msg, groupID, modToken), "not sampled: counts down")

	db.Exec("UPDATE messages_groups SET quality_sample=1 WHERE msgid=?", msg)
	assert.Nil(t, getAutoapproveatField(t, msg, groupID, modToken), "sampled: waits for a moderator")

	// The decision block says so, which is how the card explains an approved decision
	// with no countdown.
	automod := getAutomodField(t, msg, groupID, modToken)
	assert.NotNil(t, automod, "the moderator sees the stored decision")
	assert.Equal(t, true, automod["qualitysample"], "the decision block carries the sample flag")
}

// After an edit the content check re-runs. A decision made before that re-run saw the old
// findings and is not current, as the cron's own query says, so there is no countdown until
// the chart has decided again.
func TestAutoapproveatDecisionBeforeContentCheckRerun(t *testing.T) {
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "true")
	prefix := uniquePrefix("aastalefacts")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, poster, groupID, "Member")
	CreateTestMembership(t, modID, groupID, "Moderator")
	db.Exec("UPDATE memberships SET ourPostingStatus = NULL WHERE userid = ? AND groupid = ?", poster, groupID)
	_, modToken := CreateTestSession(t, modID)

	// Decided four minutes ago; the content check ran again two minutes ago.
	msg := CreateTestMessage(t, poster, groupID, prefix+" edited after decision", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Pending', arrival=NOW() - INTERVAL 10 MINUTE, contentcheck_checked_at=NOW() - INTERVAL 2 MINUTE, contentcheck_reasons=NULL, autoapprove_hold_until=NULL WHERE msgid=?", msg)
	insertAutomodRow(t, msg, groupID, "approve", 4)
	defer db.Exec("DELETE FROM messages_automod WHERE msgid=?", msg)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid=?", msg)
	defer db.Exec("DELETE FROM messages WHERE id=?", msg)

	assert.Nil(t, getAutoapproveatField(t, msg, groupID, modToken),
		"a decision older than the content check's last run is not current")

	// The chart decides again on the new findings.
	db.Exec("DELETE FROM messages_automod WHERE msgid=?", msg)
	insertAutomodRow(t, msg, groupID, "approve", 1)
	assert.NotNil(t, getAutoapproveatField(t, msg, groupID, modToken),
		"a decision made after the re-run counts down")
}

// A rippled-in copy is AutoApproveService's: released after rippled_in_pending_hours,
// unless the RECEIVING group's own keywords or worry words held it, in which case it waits
// for one of that group's moderators and never auto-approves. The countdown must say the
// same - a 48h countdown on a rule-held copy promises a release that will not come.
func TestAutoapproveatRippledInCopy(t *testing.T) {
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "true")
	t.Setenv("RIPPLE_RIPPLED_IN_PENDING_HOURS", "2")
	prefix := uniquePrefix("aarippled")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, poster, groupID, "Member")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	parseAt := func(v interface{}) time.Time {
		s, ok := v.(string)
		assert.True(t, ok, "autoapproveat should be a timestamp string")
		at, err := time.Parse(time.RFC3339, s)
		assert.NoError(t, err)
		return at
	}

	released := CreateTestMessage(t, poster, groupID, prefix+" rippled copy", 52.0, -1.0)
	ruleHeld := CreateTestMessage(t, poster, groupID, prefix+" rule-held copy", 52.0, -1.0)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid IN (?, ?)", released, ruleHeld)
	defer db.Exec("DELETE FROM messages WHERE id IN (?, ?)", released, ruleHeld)
	db.Exec("UPDATE messages_groups SET collection='Pending', rippled_in=1, arrival=NOW() - INTERVAL 30 MINUTE, contentcheck_checked_at=NOW() - INTERVAL 29 MINUTE, contentcheck_reasons=NULL, autoapprove_hold_until=NULL WHERE msgid=?", released)
	db.Exec("UPDATE messages_groups SET collection='Pending', rippled_in=1, arrival=NOW() - INTERVAL 30 MINUTE, contentcheck_checked_at=NOW() - INTERVAL 29 MINUTE, autoapprove_hold_until=NULL, "+
		"contentcheck_reasons='[{\"check\":\"PerGroupWorryWord\",\"action\":\"flag\",\"detail\":\"rabbit\"}]' WHERE msgid=?", ruleHeld)

	v := getAutoapproveatField(t, released, groupID, modToken)
	assert.NotNil(t, v, "a rippled-in copy nothing holds is released after rippled_in_pending_hours")
	at := parseAt(v)
	assert.True(t, at.After(time.Now().Add(60*time.Minute)) && at.Before(time.Now().Add(120*time.Minute)),
		"estimate is arrival + 2h (RIPPLE_RIPPLED_IN_PENDING_HOURS), not the 48h fallback")

	assert.Nil(t, getAutoapproveatField(t, ruleHeld, groupID, modToken),
		"a copy held by the receiving group's own rules never auto-approves: no countdown")
}

// The rollout gate (FREEGLE_AUTOAPPROVE_ENABLED / FREEGLE_AUTOAPPROVE_TRIAL_GROUPS)
// mirrors AutoApproveCleanService::enabledGroupIds. With the gate off (the default) a
// pending post shows no countdown at all: a community outside the trial sees Pending as
// it did before post-moderation. A trial group gets the 20-minute path for that group only.
func TestAutoapproveatRolloutGate(t *testing.T) {
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "")
	t.Setenv("FREEGLE_AUTOAPPROVE_TRIAL_GROUPS", "")
	prefix := uniquePrefix("aagate")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, poster, groupID, "Member")
	CreateTestMembership(t, modID, groupID, "Moderator")
	db.Exec("UPDATE memberships SET ourPostingStatus = NULL WHERE userid = ? AND groupid = ?", poster, groupID)
	_, modToken := CreateTestSession(t, modID)

	msg := CreateTestMessage(t, poster, groupID, prefix+" gated pending", 52.0, -1.0)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid = ?", msg)
	defer db.Exec("DELETE FROM messages WHERE id = ?", msg)
	db.Exec("UPDATE messages_groups SET collection='Pending', arrival=NOW() - INTERVAL 5 MINUTE, contentcheck_checked_at=NOW() - INTERVAL 4 MINUTE, contentcheck_reasons=NULL, autoapprove_hold_until=NULL WHERE msgid=?", msg)
	insertAutomodRow(t, msg, groupID, "approve", 0)
	defer db.Exec("DELETE FROM messages_automod WHERE msgid = ?", msg)

	parseAt := func(v interface{}) time.Time {
		s, ok := v.(string)
		assert.True(t, ok, "autoapproveat should be a timestamp string")
		at, err := time.Parse(time.RFC3339, s)
		assert.NoError(t, err)
		return at
	}

	// Gate fully off: no countdown.
	v := getAutoapproveatField(t, msg, groupID, modToken)
	assert.Nil(t, v, "gate off: a community outside the trial shows no countdown")

	// This group in the trial list: the 20-minute clean path applies again.
	t.Setenv("FREEGLE_AUTOAPPROVE_TRIAL_GROUPS", fmt.Sprintf(" %d ", groupID))
	v = getAutoapproveatField(t, msg, groupID, modToken)
	assert.NotNil(t, v)
	assert.True(t, parseAt(v).Before(time.Now().Add(time.Hour)),
		"trial group: the 20-minute clean-path estimate applies")

	// Global switch on: clean path everywhere, trial list irrelevant.
	t.Setenv("FREEGLE_AUTOAPPROVE_TRIAL_GROUPS", "999999999")
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "true")
	v = getAutoapproveatField(t, msg, groupID, modToken)
	assert.NotNil(t, v)
	assert.True(t, parseAt(v).Before(time.Now().Add(time.Hour)),
		"global switch on: the 20-minute clean-path estimate applies")
}

// D9: markchecked with explicit ids marks only Approved rows on the mod's groups,
// leaving Pending rows and other-group rows untouched.
func TestMarkCheckedSpecificIDs(t *testing.T) {
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "true")
	t.Setenv("FREEGLE_AUTOMOD_SHADOW_GROUPS", "")
	prefix := uniquePrefix("markchk_ids")
	db := database.DBConn

	groupA := CreateTestGroup(t, prefix+"_a")
	groupB := CreateTestGroup(t, prefix+"_b")
	poster := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, poster, groupA, "Member")
	CreateTestMembership(t, poster, groupB, "Member")
	CreateTestMembership(t, modID, groupA, "Moderator") // mod of A only
	_, modToken := CreateTestSession(t, modID)

	approvedA := CreateTestMessage(t, poster, groupA, prefix+" approved a", 52.0, -1.0)
	pendingA := CreateTestMessage(t, poster, groupA, prefix+" pending a", 52.0, -1.0)
	approvedB := CreateTestMessage(t, poster, groupB, prefix+" approved b", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Approved', approvedby=NULL WHERE msgid IN (?, ?)", approvedA, approvedB)
	db.Exec("UPDATE messages_groups SET collection='Pending' WHERE msgid=?", pendingA)

	body := fmt.Sprintf(`{"groupid": %d, "ids": [%d, %d, %d]}`, groupA, approvedA, pendingA, approvedB)
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/modtools/messages/markchecked?jwt=%s", modToken), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var aChecked, pendChecked, bChecked int64
	db.Raw("SELECT COUNT(*) FROM messages_groups WHERE msgid=? AND groupid=? AND checkedat IS NOT NULL", approvedA, groupA).Scan(&aChecked)
	db.Raw("SELECT COUNT(*) FROM messages_groups WHERE msgid=? AND groupid=? AND checkedat IS NOT NULL", pendingA, groupA).Scan(&pendChecked)
	db.Raw("SELECT COUNT(*) FROM messages_groups WHERE msgid=? AND groupid=? AND checkedat IS NOT NULL", approvedB, groupB).Scan(&bChecked)
	assert.Equal(t, int64(1), aChecked, "Approved post on the mod's group should be checked")
	assert.Equal(t, int64(0), pendChecked, "Pending post must not be marked checked (collection guard, D9)")
	assert.Equal(t, int64(0), bChecked, "post on a non-moderated group must not be checked")

	db.Exec("DELETE FROM messages_groups WHERE msgid IN (?, ?, ?)", approvedA, pendingA, approvedB)
	db.Exec("DELETE FROM messages WHERE id IN (?, ?, ?)", approvedA, pendingA, approvedB)
}

// D11: markchecked returns 403 for a mod acting on a group they don't moderate,
// and for a non-mod using groupid=0.
func TestMarkCheckedCrossGroupAndNonMod(t *testing.T) {
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "true")
	t.Setenv("FREEGLE_AUTOMOD_SHADOW_GROUPS", "")
	prefix := uniquePrefix("markchk_403")

	groupA := CreateTestGroup(t, prefix+"_a")
	groupB := CreateTestGroup(t, prefix+"_b")
	modB := CreateTestUser(t, prefix+"_modb", "User")
	CreateTestMembership(t, modB, groupB, "Moderator") // mod of B only
	_, modBToken := CreateTestSession(t, modB)

	// Mod of B targeting group A → 403.
	bodyA := fmt.Sprintf(`{"groupid": %d, "filter": "checked"}`, groupA)
	reqA := httptest.NewRequest("POST", fmt.Sprintf("/api/modtools/messages/markchecked?jwt=%s", modBToken), strings.NewReader(bodyA))
	reqA.Header.Set("Content-Type", "application/json")
	respA, err := getApp().Test(reqA)
	assert.NoError(t, err)
	assert.Equal(t, 403, respA.StatusCode, "mod of B must not mark group A")

	// Non-mod with groupid=0 → 403.
	regID := CreateTestUser(t, prefix+"_reg", "User")
	_, regToken := CreateTestSession(t, regID)
	body0 := `{"groupid": 0, "filter": "checked"}`
	req0 := httptest.NewRequest("POST", fmt.Sprintf("/api/modtools/messages/markchecked?jwt=%s", regToken), strings.NewReader(body0))
	req0.Header.Set("Content-Type", "application/json")
	resp0, err := getApp().Test(req0)
	assert.NoError(t, err)
	assert.Equal(t, 403, resp0.StatusCode, "non-mod with groupid=0 must get 403")
}

// Reject pulls the specified Approved auto-published posts back to Pending, held by the
// mod, clearing checkedat; it requires explicit ids (no bulk reject).
func TestMarkCheckedReject(t *testing.T) {
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "true")
	t.Setenv("FREEGLE_AUTOMOD_SHADOW_GROUPS", "")
	prefix := uniquePrefix("markchk_reject")
	db := database.DBConn

	groupA := CreateTestGroup(t, prefix+"_a")
	poster := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, poster, groupA, "Member")
	CreateTestMembership(t, modID, groupA, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	approved := CreateTestMessage(t, poster, groupA, prefix+" approved", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Approved', approvedby=NULL, checkedat=NOW(), checkedby=? WHERE msgid=?", modID, approved)

	// The post is mid-ripple: an expanding reach row, a rippled-in copy on a community
	// whose moderators have not looked at it, and one on a community whose own moderator
	// approved it by hand. The reject is Back to pending on the home community: the
	// unreviewed copy is withdrawn, the hand-approved one stays, the reach is frozen so a
	// later Approve brings the post back.
	groupB := CreateTestGroup(t, prefix+"_b")
	groupC := CreateTestGroup(t, prefix+"_c")
	modC := CreateTestUser(t, prefix+"_modc", "User")
	CreateTestMembership(t, modC, groupC, "Moderator")
	// Mid-pause when rejected: the awaiting stamp must be banked and cleared by the
	// reject, or the "posts awaiting review" metric counts this frozen row forever.
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, outer_bound, arrival, awaiting_review_since) "+
		"VALUES (?, 52.0, -1.0, "+
		"ST_Envelope(ST_GeomFromText('POLYGON((-1.2 51.9,-0.8 51.9,-0.8 52.1,-1.2 52.1,-1.2 51.9))', 3857)), NOW(), "+
		"NOW() - INTERVAL 10 MINUTE)", approved)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts, rippled_in) "+
		"VALUES (?, ?, NOW(), 'Approved', 0, 1)", approved, groupB)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts, rippled_in, approvedby, approvedat) "+
		"VALUES (?, ?, NOW(), 'Approved', 0, 1, ?, NOW())", approved, groupC, modC)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid=?", approved)
	defer db.Exec("DELETE FROM rippling_blocked WHERE msgid=?", approved)

	// Reject requires explicit ids.
	noIDs := fmt.Sprintf(`{"groupid": %d, "reject": true}`, groupA)
	reqNo := httptest.NewRequest("POST", fmt.Sprintf("/api/modtools/messages/markchecked?jwt=%s", modToken), strings.NewReader(noIDs))
	reqNo.Header.Set("Content-Type", "application/json")
	respNo, err := getApp().Test(reqNo)
	assert.NoError(t, err)
	assert.Equal(t, 400, respNo.StatusCode, "reject without ids must be 400")

	// A mod of a RECEIVING group cannot reject the rippled-in copy through this endpoint:
	// oversight actions are origin-row only.
	modB := CreateTestUser(t, prefix+"_modb", "User")
	CreateTestMembership(t, modB, groupB, "Moderator")
	_, modBToken := CreateTestSession(t, modB)
	bodyB := fmt.Sprintf(`{"groupid": %d, "reject": true, "ids": [%d]}`, groupB, approved)
	reqB := httptest.NewRequest("POST", fmt.Sprintf("/api/modtools/messages/markchecked?jwt=%s", modBToken), strings.NewReader(bodyB))
	reqB.Header.Set("Content-Type", "application/json")
	respB, err := getApp().Test(reqB)
	assert.NoError(t, err)
	assert.Equal(t, 200, respB.StatusCode)
	var rippledCopy int64
	db.Raw("SELECT COUNT(*) FROM messages_groups WHERE msgid=? AND groupid=? AND collection='Approved' AND rippled_in=1 AND deleted=0", approved, groupB).Scan(&rippledCopy)
	assert.Equal(t, int64(1), rippledCopy, "a receiving-group mod's reject must not yank a rippled-in copy")

	// Reject the approved post from its home community's Check queue.
	body := fmt.Sprintf(`{"groupid": %d, "reject": true, "ids": [%d]}`, groupA, approved)
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/modtools/messages/markchecked?jwt=%s", modToken), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)
	var rejectResp map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&rejectResp)
	assert.Equal(t, float64(1), rejectResp["rejected"])

	var pendingHeld, stillChecked int64
	db.Raw("SELECT COUNT(*) FROM messages_groups WHERE msgid=? AND groupid=? AND collection='Pending' AND heldby=? AND needs_moderator=1 AND checkedat IS NULL AND checkedby IS NULL", approved, groupA, modID).Scan(&pendingHeld)
	db.Raw("SELECT COUNT(*) FROM messages_groups WHERE msgid=? AND groupid=? AND checkedat IS NOT NULL", approved, groupA).Scan(&stillChecked)
	assert.Equal(t, int64(1), pendingHeld, "rejected post is Pending, held by the mod, needs_moderator set, checked stamp cleared")
	assert.Equal(t, int64(0), stillChecked, "checkedat must be cleared on reject")

	// Pulling a post back is a Hold, not a decision: the moderator's later Approve or
	// Reject writes its own log. No Rejected row yet, so an approved post does not count
	// against the member.
	var holdLogs, rejectedLogs int64
	db.Raw("SELECT COUNT(*) FROM logs WHERE type='Message' AND subtype='Hold' AND msgid=? AND groupid=? AND user=? AND byuser=? AND text='Pulled back from Check'",
		approved, groupA, poster, modID).Scan(&holdLogs)
	db.Raw("SELECT COUNT(*) FROM logs WHERE type='Message' AND subtype='Rejected' AND msgid=?", approved).Scan(&rejectedLogs)
	assert.Equal(t, int64(1), holdLogs, "reject must write a Message/Hold log row on the home community")
	assert.Equal(t, int64(0), rejectedLogs, "reject must not write a Rejected log before the moderator decides")

	// The reach is frozen, not killed: a later Approve brings the post back, and the
	// engine's orphan retraction (which exempts only 'held') leaves the kept copy alone.
	var reachStatus string
	db.Raw("SELECT status FROM rippling_reach WHERE msgid=?", approved).Scan(&reachStatus)
	assert.Equal(t, "held", reachStatus, "reject must freeze the reach row synchronously")
	var blocked int64
	db.Raw("SELECT COUNT(*) FROM rippling_blocked WHERE msgid=?", approved).Scan(&blocked)
	assert.Equal(t, int64(1), blocked, "a home pull-back blocks the post from rippling again")

	// The await-review pause is settled by the reject: time banked, stamp cleared.
	var awaitOpen, awaitBanked int64
	db.Raw("SELECT COUNT(*) FROM rippling_reach WHERE msgid=? AND awaiting_review_since IS NOT NULL", approved).Scan(&awaitOpen)
	db.Raw("SELECT awaiting_review_seconds FROM rippling_reach WHERE msgid=?", approved).Scan(&awaitBanked)
	assert.Equal(t, int64(0), awaitOpen, "reject must clear the awaiting stamp")
	assert.GreaterOrEqual(t, awaitBanked, int64(590), "reject must bank the paused time")

	// The unreviewed rippled-in copy is withdrawn, with a log to that community; the copy
	// another community's moderator approved by hand survives, untouched and unlogged.
	var withdrawn, withdrawnLog, keptCopy, keptLogs int64
	db.Raw("SELECT COUNT(*) FROM messages_groups WHERE msgid=? AND groupid=? AND deleted=1", approved, groupB).Scan(&withdrawn)
	db.Raw("SELECT COUNT(*) FROM logs WHERE type='Message' AND subtype='Deleted' AND msgid=? AND groupid=?", approved, groupB).Scan(&withdrawnLog)
	db.Raw("SELECT COUNT(*) FROM messages_groups WHERE msgid=? AND groupid=? AND collection='Approved' AND deleted=0 AND approvedby=? AND needs_moderator=0", approved, groupC, modC).Scan(&keptCopy)
	db.Raw("SELECT COUNT(*) FROM logs WHERE type='Message' AND msgid=? AND groupid=?", approved, groupC).Scan(&keptLogs)
	assert.Equal(t, int64(1), withdrawn, "an unreviewed rippled-in copy is withdrawn by a home pull-back")
	assert.Equal(t, int64(1), withdrawnLog, "and that community is told")
	assert.Equal(t, int64(1), keptCopy, "a copy another community's moderator approved by hand stays live")
	assert.Equal(t, int64(0), keptLogs, "and gets no log")

	db.Exec("DELETE FROM logs WHERE type='Message' AND msgid=?", approved)
	db.Exec("DELETE FROM messages_groups WHERE msgid=?", approved)
	db.Exec("DELETE FROM messages WHERE id=?", approved)
}

// A Check reject then a Release (the moderator lets a colleague decide) leaves
// needs_moderator set: the auto-approve cron and the countdown both skip the copy, so the
// post does not quietly publish itself a second time the minute the hold comes off.
func TestMarkCheckedRejectThenReleaseKeepsNeedsModerator(t *testing.T) {
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "true")
	t.Setenv("FREEGLE_AUTOMOD_SHADOW_GROUPS", "")
	prefix := uniquePrefix("markchk_release")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, poster, groupID, "Member")
	CreateTestMembership(t, modID, groupID, "Moderator")
	db.Exec("UPDATE memberships SET ourPostingStatus = NULL WHERE userid = ? AND groupid = ?", poster, groupID)
	_, modToken := CreateTestSession(t, modID)

	msg := CreateTestMessage(t, poster, groupID, prefix+" published", 52.0, -1.0)
	// Published by the clean path an hour ago on a still-current approve decision.
	db.Exec("UPDATE messages_groups SET collection='Approved', approvedby=NULL, arrival=NOW() - INTERVAL 1 HOUR, contentcheck_checked_at=NOW() - INTERVAL 1 HOUR, autoapprove_hold_until=NULL WHERE msgid=?", msg)
	insertAutomodRow(t, msg, groupID, "approve", 55)
	defer db.Exec("DELETE FROM messages_automod WHERE msgid=?", msg)
	defer db.Exec("DELETE FROM logs WHERE type='Message' AND msgid=?", msg)
	defer db.Exec("DELETE FROM rippling_blocked WHERE msgid=?", msg)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid=?", msg)
	defer db.Exec("DELETE FROM messages WHERE id=?", msg)

	body := fmt.Sprintf(`{"groupid": %d, "reject": true, "ids": [%d]}`, groupID, msg)
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/modtools/messages/markchecked?jwt=%s", modToken), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	status, out := postModAction(t, modToken, msg, "Release", groupID)
	assert.Equal(t, 200, status, out)

	var state struct {
		Collection     string
		Heldby         *uint64
		NeedsModerator int
	}
	db.Raw("SELECT collection, heldby, needs_moderator FROM messages_groups WHERE msgid=? AND groupid=?", msg, groupID).Scan(&state)
	assert.Equal(t, "Pending", state.Collection)
	assert.Nil(t, state.Heldby, "Release clears the hold")
	assert.Equal(t, 1, state.NeedsModerator, "but the post still waits for a moderator's decision")

	assert.Nil(t, getAutoapproveatField(t, msg, groupID, modToken),
		"no countdown on a released copy that still needs a moderator, whatever the chart decided")
}

// A copy a moderator approved by hand is not in the Check queue, so the Check reject cannot
// reach it: undoing a moderator's approval is Back to pending, on purpose.
func TestMarkCheckedRejectSkipsHandApprovedCopy(t *testing.T) {
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "true")
	t.Setenv("FREEGLE_AUTOMOD_SHADOW_GROUPS", "")
	prefix := uniquePrefix("markchk_byhand")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, poster, groupID, "Member")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	msg := CreateTestMessage(t, poster, groupID, prefix+" by hand", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Approved', approvedby=?, approvedat=NOW() WHERE msgid=?", modID, msg)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid=?", msg)
	defer db.Exec("DELETE FROM messages WHERE id=?", msg)

	body := fmt.Sprintf(`{"groupid": %d, "reject": true, "ids": [%d]}`, groupID, msg)
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/modtools/messages/markchecked?jwt=%s", modToken), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)
	var out map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&out)
	assert.Equal(t, float64(0), out["rejected"])

	var untouched int64
	db.Raw("SELECT COUNT(*) FROM messages_groups WHERE msgid=? AND groupid=? AND collection='Approved' AND approvedby=? AND heldby IS NULL AND needs_moderator=0", msg, groupID, modID).Scan(&untouched)
	assert.Equal(t, int64(1), untouched, "a hand-approved copy is not reachable through the Check reject")
}

// Outside the post-moderation trial the Check queue does not exist, so neither do its
// actions: the same predicate matches every community's 48-hour fallback approvals, and a
// stamp there would count as a review in the stats and lift the earned-reach hold.
func TestMarkCheckedOutsideTrialDoesNothing(t *testing.T) {
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "")
	t.Setenv("FREEGLE_AUTOAPPROVE_TRIAL_GROUPS", "")
	t.Setenv("FREEGLE_AUTOMOD_SHADOW_GROUPS", "")
	prefix := uniquePrefix("markchk_notrial")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, poster, groupID, "Member")
	CreateTestMembership(t, modID, groupID, "Moderator")
	db.Exec("UPDATE memberships SET ourPostingStatus = NULL WHERE userid = ? AND groupid = ?", poster, groupID)
	_, modToken := CreateTestSession(t, modID)

	msg := CreateTestMessage(t, poster, groupID, prefix+" fallback approved", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Approved', approvedby=NULL WHERE msgid=?", msg)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid=?", msg)
	defer db.Exec("DELETE FROM messages WHERE id=?", msg)

	post := func(body string) map[string]interface{} {
		req := httptest.NewRequest("POST", fmt.Sprintf("/api/modtools/messages/markchecked?jwt=%s", modToken), strings.NewReader(body))
		req.Header.Set("Content-Type", "application/json")
		resp, err := getApp().Test(req)
		assert.NoError(t, err)
		assert.Equal(t, 200, resp.StatusCode)
		var out map[string]interface{}
		json.NewDecoder(resp.Body).Decode(&out)
		return out
	}

	assert.Equal(t, float64(0), post(fmt.Sprintf(`{"groupid": %d, "ids": [%d]}`, groupID, msg))["checked"])
	assert.Equal(t, float64(0), post(`{"groupid": 0, "filter": "checked"}`)["checked"])
	assert.Equal(t, float64(0), post(fmt.Sprintf(`{"groupid": %d, "reject": true, "ids": [%d]}`, groupID, msg))["rejected"])

	var untouched int64
	db.Raw("SELECT COUNT(*) FROM messages_groups WHERE msgid=? AND groupid=? AND collection='Approved' AND checkedat IS NULL AND heldby IS NULL", msg, groupID).Scan(&untouched)
	assert.Equal(t, int64(1), untouched, "a community outside the trial is left exactly as it was")
}

// A copy with a spam reason on it is never published by either cron, so it gets no
// countdown: a members' report quorum writes the copy's spamreason as it pulls the post
// back, with no hold and no needs_moderator, and the chart's approve row is still current.
func TestAutoapproveatCopySpamreasonHidesCountdown(t *testing.T) {
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "true")
	t.Setenv("FREEGLE_AUTOMOD_SHADOW_GROUPS", "")
	prefix := uniquePrefix("aaspamreason")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, poster, groupID, "Member")
	CreateTestMembership(t, modID, groupID, "Moderator")
	db.Exec("UPDATE memberships SET ourPostingStatus = NULL WHERE userid = ? AND groupid = ?", poster, groupID)
	_, modToken := CreateTestSession(t, modID)

	msg := CreateTestMessage(t, poster, groupID, prefix+" reported", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Pending', arrival=NOW() - INTERVAL 5 MINUTE, contentcheck_checked_at=NOW() - INTERVAL 4 MINUTE, contentcheck_reasons=NULL, autoapprove_hold_until=NULL, spamreason='Reported by members' WHERE msgid=?", msg)
	insertAutomodRow(t, msg, groupID, "approve", 3)
	defer db.Exec("DELETE FROM messages_automod WHERE msgid=?", msg)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid=?", msg)
	defer db.Exec("DELETE FROM messages WHERE id=?", msg)

	assert.Nil(t, getAutoapproveatField(t, msg, groupID, modToken), "a copy with a spam reason shows no countdown")

	db.Exec("UPDATE messages_groups SET spamreason=NULL WHERE msgid=?", msg)
	assert.NotNil(t, getAutoapproveatField(t, msg, groupID, modToken), "the same copy without one does")
}

// The cron only publishes a post whose member still has no posting status on the
// community. The chart saw the status as a fact when it ran, but a moderator setting the
// member to Moderated while the post waits does not re-run it, so the countdown reads the
// membership too, or it would show a wait the cron never honours.
func TestAutoapproveatPostingStatusSetMidWait(t *testing.T) {
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "true")
	t.Setenv("FREEGLE_AUTOMOD_SHADOW_GROUPS", "")
	prefix := uniquePrefix("aastatus")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, poster, groupID, "Member")
	CreateTestMembership(t, modID, groupID, "Moderator")
	db.Exec("UPDATE memberships SET ourPostingStatus = NULL WHERE userid = ? AND groupid = ?", poster, groupID)
	_, modToken := CreateTestSession(t, modID)

	msg := CreateTestMessage(t, poster, groupID, prefix+" status changed", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Pending', arrival=NOW() - INTERVAL 5 MINUTE, contentcheck_checked_at=NOW() - INTERVAL 4 MINUTE, contentcheck_reasons=NULL, autoapprove_hold_until=NULL WHERE msgid=?", msg)
	insertAutomodRow(t, msg, groupID, "approve", 3)
	defer db.Exec("DELETE FROM messages_automod WHERE msgid=?", msg)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid=?", msg)
	defer db.Exec("DELETE FROM messages WHERE id=?", msg)

	assert.NotNil(t, getAutoapproveatField(t, msg, groupID, modToken), "no posting status: the countdown shows")

	db.Exec("UPDATE memberships SET ourPostingStatus = 'MODERATED' WHERE userid = ? AND groupid = ?", poster, groupID)
	assert.Nil(t, getAutoapproveatField(t, msg, groupID, modToken), "set to Moderated mid-wait: no countdown, as the cron will not publish it")
}
