package test

import (
	"encoding/json"
	"net/http/httptest"
	"strconv"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/microvolunteering"
	"github.com/freegle/iznik-server-go/ux"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// The experiments side of the UX read API, its two write handlers, the public config key the
// client reads arms from, and the UxPrompt microvolunteering challenge. Every test names its rows
// with a unique prefix and removes them afterwards.

func uxExpClean(t *testing.T, prefix string) {
	t.Helper()
	db := database.DBConn
	cleanup := func() {
		db.Exec("DELETE r FROM ux_prompt_responses r INNER JOIN ux_prompts p ON p.id = r.prompt_id WHERE p.question LIKE ?", prefix+"%")
		db.Exec("DELETE FROM ux_prompts WHERE question LIKE ?", prefix+"%")
		db.Exec("DELETE FROM ux_experiment_reads WHERE experiment_id IN (SELECT id FROM ux_experiments WHERE name LIKE ?)", prefix+"%")
		db.Exec("DELETE FROM ux_experiments WHERE name LIKE ?", prefix+"%")
		db.Exec("DELETE FROM ux_ideas WHERE title LIKE ?", prefix+"%")
		db.Exec("DELETE FROM background_tasks WHERE task_type = ? AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.reason')) LIKE ?", ux.TaskUxExperiment, prefix+"%")
	}
	cleanup()
	t.Cleanup(cleanup)
}

func uxPost(t *testing.T, token, path string, body interface{}) (int, map[string]interface{}) {
	t.Helper()
	b, _ := json.Marshal(body)
	url := "/api/modtools/ux/" + path
	if token != "" {
		url += "?jwt=" + token
	}
	req := httptest.NewRequest("POST", url, strings.NewReader(string(b)))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, 30000)
	require.NoError(t, err)
	out := map[string]interface{}{}
	_ = json.Unmarshal(rsp(resp), &out)
	return resp.StatusCode, out
}

func uxPatch(t *testing.T, token, path string, body interface{}) (int, map[string]interface{}) {
	t.Helper()
	b, _ := json.Marshal(body)
	url := "/api/modtools/ux/" + path
	if token != "" {
		url += "?jwt=" + token
	}
	req := httptest.NewRequest("PATCH", url, strings.NewReader(string(b)))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, 30000)
	require.NoError(t, err)
	out := map[string]interface{}{}
	_ = json.Unmarshal(rsp(resp), &out)
	return resp.StatusCode, out
}

func TestUxExperimentsRequireSupport(t *testing.T) {
	userID := CreateTestUser(t, uniquePrefix("uxexpuser"), "User")
	_, userToken := CreateTestSession(t, userID)

	assert.Equal(t, 401, uxGet(t, "", "experiments", nil))
	assert.Equal(t, 403, uxGet(t, userToken, "experiments", nil))
	code, _ := uxPost(t, userToken, "experiments", map[string]interface{}{"flow": "give"})
	assert.Equal(t, 403, code)
	code, _ = uxPatch(t, userToken, "experiments/1", map[string]interface{}{"action": "start"})
	assert.Equal(t, 403, code)
}

func TestUxExperimentCreateValidatesAndListsPerFlow(t *testing.T) {
	prefix := uniquePrefix("uxexp")
	uxExpClean(t, prefix)
	token := uxSupportToken(t, "uxexpsup")

	body := map[string]interface{}{
		"flow": "give", "name": prefix + "-skip", "title": prefix + " Make skipping photos obvious",
		"hypothesis": "People leave at photos because they cannot see they can skip.",
		"change":     "A plain link above the camera button.", "expected": "More finish from the photos step.",
	}
	code, out := uxPost(t, token, "experiments", body)
	require.Equal(t, 200, code, out)
	assert.Equal(t, "draft", out["status"])
	id := uint64(out["id"].(float64))
	assert.NotZero(t, id)

	// Same name again is a conflict; a bad flow, a bad name and empty text are refused.
	code, _ = uxPost(t, token, "experiments", body)
	assert.Equal(t, 409, code)
	bad := map[string]interface{}{}
	for k, v := range body {
		bad[k] = v
	}
	bad["flow"] = "checkout"
	code, _ = uxPost(t, token, "experiments", bad)
	assert.Equal(t, 400, code)
	bad["flow"] = "give"
	bad["name"] = "has spaces"
	code, _ = uxPost(t, token, "experiments", bad)
	assert.Equal(t, 400, code)
	bad["name"] = prefix + "-two"
	bad["title"] = "  "
	code, _ = uxPost(t, token, "experiments", bad)
	assert.Equal(t, 400, code)
	bad["title"] = "t"
	bad["arms"] = map[string][2]int{"control": {0, 60}, "b": {50, 99}}
	code, _ = uxPost(t, token, "experiments", bad)
	assert.Equal(t, 400, code)
	bad["arms"] = map[string][2]int{"a": {0, 49}, "b": {50, 99}}
	code, _ = uxPost(t, token, "experiments", bad)
	assert.Equal(t, 400, code)

	// A read, a prompt answer and an idea show up under the funnel.
	db := database.DBConn
	db.Exec("UPDATE ux_experiments SET status = 'adopted', started_at = NOW() - INTERVAL 30 DAY, decided_at = NOW() - INTERVAL 29 DAY, decision = 'Adopted at the end.' WHERE id = ?", id)
	db.Exec("INSERT INTO ux_experiment_reads (experiment_id, read_at, hours, metrics, summary) VALUES (?, NOW() - INTERVAL 29 DAY, 24, '{}', 'After 24 hours: fine.')", id)
	db.Exec("INSERT INTO ux_prompts (kind, flow, experiment_id, question, body, options, status, max_responses, created_at, updated_at) VALUES ('experiment', 'give', ?, ?, 'b', '[{\"key\":\"better\",\"label\":\"Better\"},{\"key\":\"worse\",\"label\":\"Worse\"},{\"key\":\"dont_care\",\"label\":\"Dont care\"},{\"key\":\"dont_understand\",\"label\":\"Dont understand\"}]', 'closed', 200, NOW(), NOW())", id, prefix+" q")
	var promptID uint64
	db.Raw("SELECT id FROM ux_prompts WHERE question = ?", prefix+" q").Scan(&promptID)
	uid := CreateTestUser(t, uniquePrefix("uxexpvoter"), "User")
	db.Exec("INSERT INTO ux_prompt_responses (prompt_id, userid, answer, comment) VALUES (?, ?, '{\"choice\":\"worse\"}', 'Hated it')", promptID, uid)
	db.Exec("INSERT INTO ux_ideas (flow, title, rationale, expected, score, status, source, created_at, updated_at) VALUES ('give', ?, 'r', 'e', 7.5, 'open', 'ai', NOW(), NOW())", prefix+" idea")

	var resp ux.ExperimentsResponse
	assert.Equal(t, 200, uxGet(t, token, "experiments?flow=give", &resp))
	require.Len(t, resp.Flows, 1)
	give := resp.Flows[0]
	assert.Equal(t, "give", give.Flow)
	var found *ux.Experiment
	for i := range give.History {
		if give.History[i].ID == id {
			found = &give.History[i]
		}
	}
	require.NotNil(t, found, "the adopted experiment is in the history")
	assert.True(t, found.CleanupOverdue, "adopted 29 days ago with no cleanup is overdue")
	require.NotNil(t, found.LatestRead)
	assert.Equal(t, "After 24 hours: fine.", found.LatestRead.Summary)
	assert.InDelta(t, 24, found.Hours, 0.1)
	require.NotNil(t, found.Reaction)
	assert.Equal(t, int64(1), found.Reaction.Worse)
	assert.Equal(t, []string{"Hated it"}, found.Reaction.Comments)
	var idea *ux.Idea
	for i := range give.Ideas {
		if strings.HasPrefix(give.Ideas[i].Title, prefix) {
			idea = &give.Ideas[i]
		}
	}
	require.NotNil(t, idea)
	assert.Equal(t, 7.5, idea.Score)

	// An invalid flow filter is refused; every flow has a section when none is given.
	assert.Equal(t, 400, uxGet(t, token, "experiments?flow=bad%20flow", nil))
	var all ux.ExperimentsResponse
	assert.Equal(t, 200, uxGet(t, token, "experiments", &all))
	assert.GreaterOrEqual(t, len(all.Flows), 7)
}

func TestUxExperimentActionQueuesForTheBatch(t *testing.T) {
	prefix := uniquePrefix("uxact")
	uxExpClean(t, prefix)
	token := uxSupportToken(t, "uxactsup")
	db := database.DBConn

	code, out := uxPost(t, token, "experiments", map[string]interface{}{
		"flow": "ask", "name": prefix + "-x", "title": prefix + " t", "hypothesis": "h", "change": "c", "expected": "e",
	})
	require.Equal(t, 200, code, out)
	id := int(out["id"].(float64))

	// Only the transitions that make sense are queued.
	code, _ = uxPatch(t, token, "experiments/9999999999", map[string]interface{}{"action": "start"})
	assert.Equal(t, 404, code)
	code, _ = uxPatch(t, token, "experiments/"+itoa(id), map[string]interface{}{"action": "dance"})
	assert.Equal(t, 400, code)
	code, _ = uxPatch(t, token, "experiments/"+itoa(id), map[string]interface{}{"action": "stop", "reason": prefix})
	assert.Equal(t, 400, code, "a draft cannot be stopped")
	code, _ = uxPatch(t, token, "experiments/"+itoa(id), map[string]interface{}{"action": "close", "reason": prefix})
	assert.Equal(t, 400, code, "a draft cannot be closed")

	code, out = uxPatch(t, token, "experiments/"+itoa(id), map[string]interface{}{"action": "start", "reason": prefix})
	require.Equal(t, 200, code, out)
	assert.Equal(t, true, out["queued"])

	var queued int64
	// The data column is JSON, which MySQL re-serialises with its own key order and spacing, so
	// match on the extracted values rather than on the text.
	db.Table("background_tasks").Where("task_type = ? AND JSON_EXTRACT(data, '$.action') = 'start' AND JSON_EXTRACT(data, '$.id') = ?", ux.TaskUxExperiment, id).Count(&queued)
	assert.Equal(t, int64(1), queued, "one start task queued for the batch")
	// The row itself is untouched: the batch owns the transition.
	var status string
	db.Table("ux_experiments").Select("status").Where("id = ?", id).Scan(&status)
	assert.Equal(t, "draft", status)
}

func itoa(i int) string {
	return strconv.Itoa(i)
}

func TestUxExperimentsConfigKeyIsPublic(t *testing.T) {
	db := database.DBConn
	db.Exec("INSERT INTO config (`key`, value) VALUES ('ux_experiments', '{\"t\":{\"control\":[0,49],\"b\":[50,99]}}') ON DUPLICATE KEY UPDATE value = VALUES(value)")
	t.Cleanup(func() { db.Exec("DELETE FROM config WHERE `key` = 'ux_experiments'") })

	resp, err := getApp().Test(httptest.NewRequest("GET", "/api/config/ux_experiments", nil))
	require.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)
	var items []map[string]interface{}
	require.NoError(t, json.Unmarshal(rsp(resp), &items))
	require.Len(t, items, 1)
	assert.Contains(t, items[0]["value"], "\"control\":[0,49]")

	// Other keys stay private.
	resp, _ = getApp().Test(httptest.NewRequest("GET", "/api/config/deploy.laravel_commit", nil))
	assert.Equal(t, 403, resp.StatusCode)
}

// ---- the UxPrompt microvolunteering challenge ----

func insertPrompt(t *testing.T, kind, question, options string) uint64 {
	db := database.DBConn
	db.Exec("INSERT INTO ux_prompts (kind, question, body, options, images, status, max_responses, created_at, updated_at) VALUES (?, ?, 'Body text', ?, '[{\"url\":\"https://img/a.png\",\"caption\":\"Now\"}]', 'open', 200, NOW(), NOW())", kind, question, options)
	var id uint64
	db.Raw("SELECT id FROM ux_prompts WHERE question = ?", question).Scan(&id)
	require.NotZero(t, id)
	return id
}

func TestUxPromptChallengeAndResponse(t *testing.T) {
	prefix := uniquePrefix("uxprompt")
	uxExpClean(t, prefix)
	db := database.DBConn
	userID := CreateTestUser(t, uniquePrefix("uxpromptuser"), "User")
	token := getToken(t, userID)

	// Nothing open: no challenge of this type.
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/microvolunteering?jwt="+token+"&types=UxPrompt", nil))
	assert.Equal(t, 200, resp.StatusCode)
	var ch map[string]interface{}
	_ = json.Unmarshal(rsp(resp), &ch)
	assert.NotContains(t, ch, "type")

	expID := insertPrompt(t, "experiment", prefix+" better or worse?", `[{"key":"better","label":"Better"},{"key":"worse","label":"Worse"},{"key":"dont_care","label":"Don't care"},{"key":"dont_understand","label":"Don't understand"}]`)
	surveyID := insertPrompt(t, "survey", prefix+" best and worst?", `[{"key":"give","label":"Posting"},{"key":"reply","label":"Replying"},{"key":"login","label":"Signing in"}]`)
	// The survey already has one answer, so the experiment prompt (fewest answers) comes first.
	other := CreateTestUser(t, uniquePrefix("uxpromptother"), "User")
	db.Exec("INSERT INTO ux_prompt_responses (prompt_id, userid, answer) VALUES (?, ?, '{\"best\":\"give\",\"worst\":\"login\"}')", surveyID, other)

	resp, _ = getApp().Test(httptest.NewRequest("GET", "/api/microvolunteering?jwt="+token+"&types=UxPrompt", nil))
	var challenge microvolunteering.Challenge
	require.NoError(t, json.Unmarshal(rsp(resp), &challenge))
	require.Equal(t, microvolunteering.ChallengeUxPrompt, challenge.Type)
	require.NotNil(t, challenge.UxPrompt)
	assert.Equal(t, expID, challenge.UxPrompt.ID)
	assert.Equal(t, "experiment", challenge.UxPrompt.Kind)
	assert.Len(t, challenge.UxPrompt.Options, 4)
	assert.Len(t, challenge.UxPrompt.Images, 1)

	// An answer that is not one of the options is refused; a good one is stored once.
	post := func(body string) int {
		req := httptest.NewRequest("POST", "/api/microvolunteering?jwt="+token, strings.NewReader(body))
		req.Header.Set("Content-Type", "application/json")
		r, err := getApp().Test(req, 30000)
		require.NoError(t, err)
		return r.StatusCode
	}
	assert.Equal(t, 400, post(`{"ux_prompt_id":`+itoa(int(expID))+`,"ux_answer":{"choice":"meh"}}`))
	assert.Equal(t, 400, post(`{"ux_prompt_id":`+itoa(int(expID))+`}`))
	assert.Equal(t, 200, post(`{"ux_prompt_id":`+itoa(int(expID))+`,"ux_answer":{"choice":"worse"},"comments":"  Too much on one screen  "}`))
	var answer, comment string
	db.Raw("SELECT answer, comment FROM ux_prompt_responses WHERE prompt_id = ? AND userid = ?", expID, userID).Row().Scan(&answer, &comment)
	assert.JSONEq(t, `{"choice":"worse"}`, answer)
	assert.Equal(t, "Too much on one screen", comment)
	// A second answer does not overwrite the first.
	assert.Equal(t, 200, post(`{"ux_prompt_id":`+itoa(int(expID))+`,"ux_answer":{"choice":"better"}}`))
	db.Raw("SELECT answer FROM ux_prompt_responses WHERE prompt_id = ? AND userid = ?", expID, userID).Scan(&answer)
	assert.JSONEq(t, `{"choice":"worse"}`, answer)

	// Having answered today, the member is not asked again, even though the survey is open.
	resp, _ = getApp().Test(httptest.NewRequest("GET", "/api/microvolunteering?jwt="+token+"&types=UxPrompt", nil))
	ch = map[string]interface{}{}
	_ = json.Unmarshal(rsp(resp), &ch)
	assert.NotContains(t, ch, "type")

	// A survey answer needs best and worst from the options; a closed prompt takes nothing.
	otherToken := getToken(t, other)
	postAs := func(tok, body string) int {
		req := httptest.NewRequest("POST", "/api/microvolunteering?jwt="+tok, strings.NewReader(body))
		req.Header.Set("Content-Type", "application/json")
		r, err := getApp().Test(req, 30000)
		require.NoError(t, err)
		return r.StatusCode
	}
	third := CreateTestUser(t, uniquePrefix("uxpromptthird"), "User")
	thirdToken := getToken(t, third)
	assert.Equal(t, 400, postAs(thirdToken, `{"ux_prompt_id":`+itoa(int(surveyID))+`,"ux_answer":{"best":"give"}}`))
	assert.Equal(t, 400, postAs(thirdToken, `{"ux_prompt_id":`+itoa(int(surveyID))+`,"ux_answer":{"best":"reply","worst":"reply"}}`))
	assert.Equal(t, 200, postAs(thirdToken, `{"ux_prompt_id":`+itoa(int(surveyID))+`,"ux_answer":{"best":"reply","worst":"login"}}`))
	fourth := CreateTestUser(t, uniquePrefix("uxpromptfourth"), "User")
	assert.Equal(t, 200, postAs(getToken(t, fourth), `{"ux_prompt_id":`+itoa(int(surveyID))+`,"ux_answer":{"choice":"dont_understand"}}`))
	db.Exec("UPDATE ux_prompts SET status = 'closed' WHERE id = ?", expID)
	assert.Equal(t, 400, postAs(otherToken, `{"ux_prompt_id":`+itoa(int(expID))+`,"ux_answer":{"choice":"better"}}`))

	// The survey tallies reach the experiments read.
	supToken := uxSupportToken(t, "uxpromptsup")
	var all ux.ExperimentsResponse
	assert.Equal(t, 200, uxGet(t, supToken, "experiments", &all))
	var survey *ux.Survey
	for i := range all.Surveys {
		if all.Surveys[i].PromptID == surveyID {
			survey = &all.Surveys[i]
		}
	}
	require.NotNil(t, survey)
	assert.Equal(t, int64(3), survey.Responses)
	assert.Equal(t, int64(1), survey.DontUnderstand)
	tally := map[string]ux.SurveyTally{}
	for _, tl := range survey.Tallies {
		tally[tl.Key] = tl
	}
	assert.Equal(t, int64(1), tally["give"].Best)
	assert.Equal(t, int64(1), tally["reply"].Best)
	assert.Equal(t, int64(2), tally["login"].Worst)
}
