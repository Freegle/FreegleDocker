package test

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"strconv"
	"strings"
	"sync"
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
		db.Exec("DELETE FROM ux_problems WHERE title LIKE ?", prefix+"%")
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
	assert.Equal(t, 400, postAs(thirdToken, `{"ux_prompt_id":`+itoa(int(surveyID))+`,"ux_answer":{"worst":"give"}}`), "worst on its own is not an answer")
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

// ---- wave 2: idea linking, arm shapes, problems, open questions, skips ----

func uxInsertIdea(t *testing.T, flow, title, status string) uint64 {
	t.Helper()
	db := database.DBConn
	db.Exec("INSERT INTO ux_ideas (flow, title, rationale, expected, score, status, source, created_at, updated_at) VALUES (?, ?, 'why', 'what', 5, ?, 'ai', NOW(), NOW())", flow, title, status)
	var id uint64
	db.Raw("SELECT id FROM ux_ideas WHERE title = ?", title).Scan(&id)
	require.NotZero(t, id)
	return id
}

func uxExperimentBody(prefix, name, flow string) map[string]interface{} {
	return map[string]interface{}{
		"flow": flow, "name": prefix + name, "title": prefix + " title " + name, "hypothesis": "h",
		"change": "c", "expected": "e",
	}
}

func TestUxCreateExperimentArmsAreExactly(t *testing.T) {
	prefix := uniquePrefix("uxarms")
	uxExpClean(t, prefix)
	token := uxSupportToken(t, "uxarmssup")

	// A range of three numbers is refused, not cut to two; one number is not padded to two.
	body := uxExperimentBody(prefix, "-a", "give")
	body["arms"] = map[string][]int{"control": {0, 49, 99}, "b": {50, 99}}
	code, _ := uxPost(t, token, "experiments", body)
	assert.Equal(t, 400, code)
	body["arms"] = map[string][]int{"control": {0, 49}, "b": {7}}
	code, _ = uxPost(t, token, "experiments", body)
	assert.Equal(t, 400, code)
	body["arms"] = map[string][]int{"control": {}, "b": {50, 99}}
	code, _ = uxPost(t, token, "experiments", body)
	assert.Equal(t, 400, code)
	body["arms"] = map[string][]int{"control": {0, 49}, "b": {50, 99}}
	code, out := uxPost(t, token, "experiments", body)
	assert.Equal(t, 200, code, out)
}

func TestUxCreateExperimentRefusesReservedNames(t *testing.T) {
	prefix := uniquePrefix("uxres")
	uxExpClean(t, prefix)
	token := uxSupportToken(t, "uxressup")
	db := database.DBConn
	for _, name := range []string{"__proto__", "constructor", "prototype"} {
		body := uxExperimentBody(prefix, "", "give")
		body["name"] = name
		code, _ := uxPost(t, token, "experiments", body)
		assert.Equal(t, 400, code, name)
		var n int64
		db.Table("ux_experiments").Where("name = ?", name).Count(&n)
		assert.Zero(t, n, name)
	}
}

func TestUxCreateExperimentFromIdea(t *testing.T) {
	prefix := uniquePrefix("uxidea")
	uxExpClean(t, prefix)
	token := uxSupportToken(t, "uxideasup")
	db := database.DBConn

	open := uxInsertIdea(t, "give", prefix+" open idea", "open")
	running := uxInsertIdea(t, "give", prefix+" running idea", "running")
	done := uxInsertIdea(t, "give", prefix+" done idea", "done")
	other := uxInsertIdea(t, "ask", prefix+" other flow idea", "open")

	body := func(name string, idea uint64) map[string]interface{} {
		b := uxExperimentBody(prefix, name, "give")
		b["idea_id"] = idea
		return b
	}

	// Missing, finished, running and wrong-flow ideas are refused, and nothing is created or changed.
	code, _ := uxPost(t, token, "experiments", body("-missing", 999999999))
	assert.Equal(t, 400, code)
	code, _ = uxPost(t, token, "experiments", body("-running", running))
	assert.Equal(t, 409, code)
	code, _ = uxPost(t, token, "experiments", body("-done", done))
	assert.Equal(t, 409, code)
	code, _ = uxPost(t, token, "experiments", body("-other", other))
	assert.Equal(t, 400, code)
	var n int64
	db.Table("ux_experiments").Where("name LIKE ?", prefix+"%").Count(&n)
	assert.Zero(t, n, "a refused request leaves no draft behind")
	var status string
	db.Raw("SELECT status FROM ux_ideas WHERE id = ?", running).Scan(&status)
	assert.Equal(t, "running", status)
	db.Raw("SELECT status FROM ux_ideas WHERE id = ?", done).Scan(&status)
	assert.Equal(t, "done", status)

	// An open idea in the same flow is scheduled and linked, once.
	code, out := uxPost(t, token, "experiments", body("-good", open))
	require.Equal(t, 200, code, out)
	id := uint64(out["id"].(float64))
	var linked uint64
	db.Raw("SELECT experiment_id FROM ux_ideas WHERE id = ?", open).Scan(&linked)
	assert.Equal(t, id, linked)
	db.Raw("SELECT status FROM ux_ideas WHERE id = ?", open).Scan(&status)
	assert.Equal(t, "scheduled", status)
	code, _ = uxPost(t, token, "experiments", body("-again", open))
	assert.Equal(t, 409, code, "a second draft cannot claim the same idea")
	db.Raw("SELECT experiment_id FROM ux_ideas WHERE id = ?", open).Scan(&linked)
	assert.Equal(t, id, linked, "the first draft keeps it")
}

func TestUxCreateExperimentSameNameAtOnceIsOneWinnerNoServerError(t *testing.T) {
	prefix := uniquePrefix("uxrace")
	uxExpClean(t, prefix)
	token := uxSupportToken(t, "uxracesup")

	var wg sync.WaitGroup
	codes := make([]int, 8)
	for i := range codes {
		wg.Add(1)
		go func(i int) {
			defer wg.Done()
			b, _ := json.Marshal(uxExperimentBody(prefix, "-same", "give"))
			req := httptest.NewRequest("POST", "/api/modtools/ux/experiments?jwt="+token, strings.NewReader(string(b)))
			req.Header.Set("Content-Type", "application/json")
			resp, err := getApp().Test(req, 30000)
			if err == nil {
				codes[i] = resp.StatusCode
			}
		}(i)
	}
	wg.Wait()
	ok, conflict := 0, 0
	for _, c := range codes {
		switch c {
		case 200:
			ok++
		case 409:
			conflict++
		}
	}
	assert.Equal(t, 1, ok, "%v", codes)
	assert.Equal(t, len(codes)-1, conflict, "a lost race is a conflict, never a 500: %v", codes)
}

func TestUxCreateExperimentDesign(t *testing.T) {
	prefix := uniquePrefix("uxdesign")
	uxExpClean(t, prefix)
	token := uxSupportToken(t, "uxdesignsup")
	db := database.DBConn

	bad := uxExperimentBody(prefix, "-bad", "give")
	bad["design"] = map[string]interface{}{"touches": []string{"checkout"}}
	code, _ := uxPost(t, token, "experiments", bad)
	assert.Equal(t, 400, code)
	bad["design"] = map[string]interface{}{"expected_points": 500}
	code, _ = uxPost(t, token, "experiments", bad)
	assert.Equal(t, 400, code)

	good := uxExperimentBody(prefix, "-good", "give")
	good["design"] = map[string]interface{}{"touches": []string{"give", "login", "give"}, "expected_points": 4.5}
	code, out := uxPost(t, token, "experiments", good)
	require.Equal(t, 200, code, out)
	var design string
	db.Raw("SELECT design FROM ux_experiments WHERE id = ?", uint64(out["id"].(float64))).Scan(&design)
	assert.JSONEq(t, `{"touches":["give","login"],"expected_points":4.5}`, design)
}

func uxInsertProblem(t *testing.T, flow *string, kind, title, source string, mentions int, weight interface{}, promptID interface{}) uint64 {
	t.Helper()
	db := database.DBConn
	db.Exec("INSERT INTO ux_problems (flow, kind, title, description, source, prompt_id, mentions, weight, status, created_at, updated_at) VALUES (?, ?, ?, 'desc', ?, ?, ?, ?, 'open', NOW(), NOW())",
		flow, kind, title, source, promptID, mentions, weight)
	var id uint64
	db.Raw("SELECT id FROM ux_problems WHERE title = ?", title).Scan(&id)
	require.NotZero(t, id)
	return id
}

func TestUxExperimentsListProblemsValuesAndIdeasUnderThem(t *testing.T) {
	prefix := uniquePrefix("uxprob")
	uxExpClean(t, prefix)
	token := uxSupportToken(t, "uxprobsup")
	db := database.DBConn
	give, none := "give", (*string)(nil)

	p1 := uxInsertProblem(t, &give, "problem", prefix+" photos are slow", "members", 14, 0.4, nil)
	p2 := uxInsertProblem(t, &give, "problem", prefix+" unclear wording", "ai", 2, nil, nil)
	gen := uxInsertProblem(t, none, "problem", prefix+" too many emails", "members", 5, nil, nil)
	val := uxInsertProblem(t, none, "value", prefix+" it is free", "members", 30, nil, nil)
	_ = p2

	idea := uxInsertIdea(t, "give", prefix+" shrink photos first", "scheduled")
	db.Exec("UPDATE ux_ideas SET problem_id = ?, type = 'fix' WHERE id = ?", p1, idea)
	_ = uxInsertIdea(t, "give", prefix+" unrelated idea", "open")
	code, out := uxPost(t, token, "experiments", uxExperimentBody(prefix, "-link", "give"))
	require.Equal(t, 200, code, out)
	expID := uint64(out["id"].(float64))
	db.Exec("UPDATE ux_ideas SET experiment_id = ? WHERE id = ?", expID, idea)

	var all ux.ExperimentsResponse
	require.Equal(t, 200, uxGet(t, token, "experiments", &all))

	var give1 *ux.FlowExperiments
	for i := range all.Flows {
		if all.Flows[i].Flow == "give" {
			give1 = &all.Flows[i]
		}
	}
	require.NotNil(t, give1)
	var found *ux.Problem
	titles := []string{}
	for i := range give1.Problems {
		if strings.HasPrefix(give1.Problems[i].Title, prefix) {
			titles = append(titles, give1.Problems[i].Title)
		}
		if give1.Problems[i].ID == p1 {
			found = &give1.Problems[i]
		}
	}
	require.NotNil(t, found)
	assert.Equal(t, []string{prefix + " photos are slow", prefix + " unclear wording"}, titles, "most mentioned first")
	assert.Equal(t, int64(14), found.Mentions)
	require.NotNil(t, found.Weight)
	assert.InDelta(t, 0.4, *found.Weight, 0.001)
	assert.Equal(t, "members", found.Source)
	require.Len(t, found.Ideas, 1)
	assert.Equal(t, idea, found.Ideas[0].ID)
	assert.Equal(t, "fix", found.Ideas[0].Type)
	assert.Equal(t, "scheduled", found.Ideas[0].Status)
	assert.Equal(t, 5.0, found.Ideas[0].Score)
	require.Len(t, found.Experiments, 1)
	assert.Equal(t, expID, found.Experiments[0].ID)
	assert.Equal(t, "draft", found.Experiments[0].Status)

	// A problem with no flow is general; a value is listed only among the values.
	genOK, valOK := false, false
	for _, p := range all.GeneralProblems {
		if p.ID == gen {
			genOK = true
		}
		assert.NotEqual(t, val, p.ID)
	}
	for _, v := range all.Values {
		if v.ID == val {
			valOK = true
			assert.Equal(t, int64(30), v.Mentions)
		}
	}
	assert.True(t, genOK, "general problem listed")
	assert.True(t, valOK, "value listed")
	for _, f := range all.Flows {
		for _, p := range f.Problems {
			assert.NotEqual(t, gen, p.ID, "a general problem is not in a flow")
			assert.NotEqual(t, val, p.ID)
		}
	}
}

// A member's suggestion for a problem that belongs to no funnel is filed with the reserved flow
// "general". It must not become a funnel section of its own: that section would read "nobody
// started" and offer no way to turn the idea into an experiment. It comes back in general_ideas.
// The offer refuses Declined and Excluded members and a prompt at its cap; the answer must refuse
// the same, or anyone who can POST can vote in a ranking or push an open question past its cap.
func TestUxPromptAnswerIsRefusedWhereTheOfferWouldBe(t *testing.T) {
	prefix := uniquePrefix("uxelig")
	uxExpClean(t, prefix)
	db := database.DBConn
	options := `[{"key":"better","label":"Better"},{"key":"worse","label":"Worse"}]`
	promptID := insertPrompt(t, "experiment", prefix+" better or worse?", options)

	for _, level := range []string{microvolunteering.TrustDeclined, microvolunteering.TrustExcluded} {
		u := CreateTestUser(t, uniquePrefix("uxelig"+level), "User")
		db.Exec("UPDATE users SET trustlevel = ? WHERE id = ?", level, u)
		assert.Equal(t, 403, uxAnswer(t, getToken(t, u), promptID, `{"choice":"better"}`), level+" members are not asked, so cannot answer")
		var n int64
		db.Raw("SELECT COUNT(*) FROM ux_prompt_responses WHERE prompt_id = ? AND userid = ?", promptID, u).Scan(&n)
		assert.Equal(t, int64(0), n)
	}

	// Up to the cap, and no further.
	db.Exec("UPDATE ux_prompts SET max_responses = 2 WHERE id = ?", promptID)
	var codes []int
	for i := 0; i < 3; i++ {
		u := CreateTestUser(t, uniquePrefix("uxeligcap"), "User")
		codes = append(codes, uxAnswer(t, getToken(t, u), promptID, `{"choice":"better"}`))
	}
	assert.Equal(t, []int{200, 200, 400}, codes)
	var stored int64
	db.Raw("SELECT COUNT(*) FROM ux_prompt_responses WHERE prompt_id = ?", promptID).Scan(&stored)
	assert.Equal(t, int64(2), stored)

	// A skip is not an answer and is not held to the cap.
	u := CreateTestUser(t, uniquePrefix("uxeligskip"), "User")
	assert.Equal(t, 200, uxAnswer(t, getToken(t, u), promptID, `{"skipped":true}`))
}

func TestUxExperimentsMemberIdeasWithNoFunnelAreListedApart(t *testing.T) {
	prefix := uniquePrefix("uxgenidea")
	uxExpClean(t, prefix)
	token := uxSupportToken(t, "uxgenideasup")
	db := database.DBConn

	loose := uxInsertIdea(t, "general", prefix+" a suggestion with no problem behind it", "open")
	db.Exec("UPDATE ux_ideas SET source = 'member' WHERE id = ?", loose)

	var all ux.ExperimentsResponse
	require.Equal(t, 200, uxGet(t, token, "experiments", &all))

	for _, f := range all.Flows {
		assert.NotEqual(t, "general", f.Flow, "general is not a funnel")
	}
	found := false
	for _, i := range all.GeneralIdeas {
		if i.ID == loose {
			found = true
			assert.Equal(t, "member", i.Source)
		}
	}
	assert.True(t, found, "the suggestion is listed among the general ideas")
}

// An adopted experiment is provisional while its holdout runs: the batch can still reverse it, and
// closing it ends the holdout. The tab needs to know which are in that state.
func TestUxExperimentsAdoptedIsInItsHoldoutForTheHoldoutDays(t *testing.T) {
	token := uxSupportToken(t, "uxholdsup")
	prefix := uniquePrefix("uxhold")
	uxExpClean(t, prefix)
	db := database.DBConn
	for name, days := range map[string]int{"-new": 3, "-old": 20} {
		db.Exec("INSERT INTO ux_experiments (flow, name, title, hypothesis, change_description, expected, arms, status, decided_at, created_at, updated_at) VALUES ('give', ?, ?, 'h', 'c', 'e', '{\"control\":[0,49],\"b\":[50,99]}', 'adopted', DATE_SUB(NOW(), INTERVAL ? DAY), NOW(), NOW())", prefix+name, prefix+name, days)
	}

	var all ux.ExperimentsResponse
	require.Equal(t, 200, uxGet(t, token, "experiments", &all))
	until := map[string]*string{}
	for _, f := range all.Flows {
		for _, e := range f.History {
			until[e.Name] = e.HoldoutUntil
		}
	}
	assert.NotNil(t, until[prefix+"-new"], "three days in, the 14-day holdout is still running")
	assert.Nil(t, until[prefix+"-old"], "twenty days in, it is over")
}

func TestUxExperimentsReadSettingsFromEnvironment(t *testing.T) {
	token := uxSupportToken(t, "uxenvsup")
	t.Setenv("UX_EXPERIMENTS_MAX_CONCURRENT", "3")
	t.Setenv("UX_EXPERIMENTS_CLEANUP_DAYS", "2")
	prefix := uniquePrefix("uxenv")
	uxExpClean(t, prefix)
	db := database.DBConn
	db.Exec("INSERT INTO ux_experiments (flow, name, title, hypothesis, change_description, expected, arms, status, decided_at, created_at, updated_at) VALUES ('give', ?, ?, 'h', 'c', 'e', '{\"control\":[0,49],\"b\":[50,99]}', 'adopted', DATE_SUB(NOW(), INTERVAL 3 DAY), NOW(), NOW())", prefix+"-old", prefix+" old")

	var all ux.ExperimentsResponse
	require.Equal(t, 200, uxGet(t, token, "experiments", &all))
	assert.Equal(t, int64(3), all.MaxRunning)
	overdue := false
	for _, f := range all.Flows {
		for _, e := range f.History {
			if e.Name == prefix+"-old" {
				overdue = e.CleanupOverdue
			}
		}
	}
	assert.True(t, overdue, "three days after adoption is overdue when the grace period is two")
}

func TestUxExperimentsListReactionsAndReadsPerExperiment(t *testing.T) {
	prefix := uniquePrefix("uxreact")
	uxExpClean(t, prefix)
	token := uxSupportToken(t, "uxreactsup")
	db := database.DBConn

	var ids [2]uint64
	for i := range ids {
		name := fmt.Sprintf("%s-e%d", prefix, i)
		db.Exec("INSERT INTO ux_experiments (flow, name, title, hypothesis, change_description, expected, arms, status, started_at, created_at, updated_at) VALUES ('ask', ?, ?, 'h', 'c', 'e', '{\"control\":[0,49],\"b\":[50,99]}', 'running', DATE_SUB(NOW(), INTERVAL 2 HOUR), NOW(), NOW())", name, name)
		db.Raw("SELECT id FROM ux_experiments WHERE name = ?", name).Scan(&ids[i])
		db.Exec("INSERT INTO ux_experiment_reads (experiment_id, read_at, hours, metrics, summary) VALUES (?, DATE_SUB(NOW(), INTERVAL 2 HOUR), 1, '{}', 'older')", ids[i])
		db.Exec("INSERT INTO ux_experiment_reads (experiment_id, read_at, hours, metrics, summary) VALUES (?, DATE_SUB(NOW(), INTERVAL 1 HOUR), 2, '{}', ?)", ids[i], fmt.Sprintf("newest %d", i))
		db.Exec("INSERT INTO ux_prompts (kind, experiment_id, question, body, options, images, status, max_responses, created_at, updated_at) VALUES ('experiment', ?, ?, '', '[]', '[]', 'open', 100, NOW(), NOW())", ids[i], fmt.Sprintf("%s q%d", prefix, i))
	}
	var prompts [2]uint64
	for i := range prompts {
		db.Raw("SELECT id FROM ux_prompts WHERE question = ?", fmt.Sprintf("%s q%d", prefix, i)).Scan(&prompts[i])
	}
	answer := func(prompt uint64, body string) {
		u := CreateTestUser(t, uniquePrefix("uxreactuser"), "User")
		db.Exec("INSERT INTO ux_prompt_responses (prompt_id, userid, answer) VALUES (?, ?, ?)", prompt, u, body)
	}
	answer(prompts[0], `{"choice":"better"}`)
	answer(prompts[0], `{"choice":"worse"}`)
	answer(prompts[0], `{"skipped":true}`)
	answer(prompts[1], `{"choice":"dont_care"}`)

	var all ux.ExperimentsResponse
	require.Equal(t, 200, uxGet(t, token, "experiments", &all))
	got := map[uint64]ux.Experiment{}
	for _, f := range all.Flows {
		for _, e := range f.Running {
			got[e.ID] = e
		}
	}
	require.Contains(t, got, ids[0])
	require.Contains(t, got, ids[1])
	assert.Equal(t, "newest 0", got[ids[0]].LatestRead.Summary)
	assert.Equal(t, "newest 1", got[ids[1]].LatestRead.Summary)
	r0 := got[ids[0]].Reaction
	assert.Equal(t, int64(1), r0.Better)
	assert.Equal(t, int64(1), r0.Worse)
	assert.Equal(t, int64(2), r0.Total, "a skip is not an answer")
	assert.Equal(t, int64(1), got[ids[1]].Reaction.DontCare)
}

// ---- open questions, settings, skips ----

func TestUxPromptOpenQuestion(t *testing.T) {
	prefix := uniquePrefix("uxopen")
	uxExpClean(t, prefix)
	db := database.DBConn
	userID := CreateTestUser(t, uniquePrefix("uxopenuser"), "User")
	token := getToken(t, userID)

	openID := insertPrompt(t, "open", prefix+" did anything cause you a problem?", `[]`)
	db.Exec("UPDATE ux_prompts SET settings = ? WHERE id = ?", `{"preset":"problems","randomise":false}`, openID)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/microvolunteering?jwt="+token+"&types=UxPrompt", nil))
	var ch map[string]interface{}
	require.NoError(t, json.Unmarshal(rsp(resp), &ch))
	prompt := ch["uxprompt"].(map[string]interface{})
	assert.Equal(t, "open", prompt["kind"])
	keys := []string{}
	for _, o := range prompt["options"].([]interface{}) {
		keys = append(keys, o.(map[string]interface{})["key"].(string))
	}
	assert.Equal(t, []string{"nothing", "dont_understand"}, keys, "an open question always offers both ways out")
	labels := []string{}
	for _, o := range prompt["options"].([]interface{}) {
		labels = append(labels, o.(map[string]interface{})["label"].(string))
	}
	assert.Equal(t, []string{"I can't think of anything", "Don't understand"}, labels, "the default way out is worded in plain English")
	settings := prompt["settings"].(map[string]interface{})
	assert.Equal(t, "problems", settings["preset"])
	assert.Equal(t, false, settings["randomise"])

	post := func(tok, body string) int {
		req := httptest.NewRequest("POST", "/api/microvolunteering?jwt="+tok, strings.NewReader(body))
		req.Header.Set("Content-Type", "application/json")
		r, err := getApp().Test(req, 30000)
		require.NoError(t, err)
		return r.StatusCode
	}
	id := itoa(int(openID))
	long := strings.Repeat("x", 1001)
	assert.Equal(t, 400, post(token, `{"ux_prompt_id":`+id+`,"ux_answer":{}}`))
	assert.Equal(t, 400, post(token, `{"ux_prompt_id":`+id+`,"ux_answer":{"text":"  ab  "}}`), "two characters after trimming")
	assert.Equal(t, 400, post(token, `{"ux_prompt_id":`+id+`,"ux_answer":{"text":"`+long+`"}}`))
	assert.Equal(t, 400, post(token, `{"ux_prompt_id":`+id+`,"ux_answer":{"choice":"better"}}`))
	var n int64
	db.Raw("SELECT COUNT(*) FROM ux_prompt_responses WHERE prompt_id = ?", openID).Scan(&n)
	assert.Zero(t, n, "refused answers store nothing")

	assert.Equal(t, 200, post(token, `{"ux_prompt_id":`+id+`,"ux_answer":{"text":"  Photos took ages <b>to</b> load\u0007 "},"comments":"ignored"}`))
	var answer string
	var comment *string
	db.Raw("SELECT answer, comment FROM ux_prompt_responses WHERE prompt_id = ? AND userid = ?", openID, userID).Row().Scan(&answer, &comment)
	var stored map[string]interface{}
	require.NoError(t, json.Unmarshal([]byte(answer), &stored))
	assert.Equal(t, "Photos took ages <b>to</b> load", stored["text"], "trimmed and control characters dropped; markup is kept as data")
	assert.Nil(t, comment, "an open answer is the text, not a comment")

	// Exactly 3 and exactly 1000 characters are allowed; the escapes are answers in their own right.
	u2 := CreateTestUser(t, uniquePrefix("uxopenuser2"), "User")
	assert.Equal(t, 200, post(getToken(t, u2), `{"ux_prompt_id":`+id+`,"ux_answer":{"text":"abc"}}`))
	u3 := CreateTestUser(t, uniquePrefix("uxopenuser3"), "User")
	assert.Equal(t, 200, post(getToken(t, u3), `{"ux_prompt_id":`+id+`,"ux_answer":{"text":"`+strings.Repeat("y", 1000)+`"}}`))
	u4 := CreateTestUser(t, uniquePrefix("uxopenuser4"), "User")
	assert.Equal(t, 200, post(getToken(t, u4), `{"ux_prompt_id":`+id+`,"ux_answer":{"choice":"nothing"}}`))
	u5 := CreateTestUser(t, uniquePrefix("uxopenuser5"), "User")
	assert.Equal(t, 200, post(getToken(t, u5), `{"ux_prompt_id":`+id+`,"ux_answer":{"choice":"dont_understand"}}`))
	u6 := CreateTestUser(t, uniquePrefix("uxopenuser6"), "User")
	assert.Equal(t, 200, post(getToken(t, u6), `{"ux_prompt_id":`+id+`,"ux_answer":{"skipped":true}}`))

	// The overview counts them, lists no text, and leaves the skip out of the responses.
	sup := uxSupportToken(t, "uxopensup")
	var all ux.ExperimentsResponse
	require.Equal(t, 200, uxGet(t, sup, "experiments", &all))
	var s *ux.Survey
	for i := range all.Surveys {
		if all.Surveys[i].PromptID == openID {
			s = &all.Surveys[i]
		}
	}
	require.NotNil(t, s)
	assert.Equal(t, "open", s.Kind)
	assert.Equal(t, int64(5), s.Responses)
	assert.Equal(t, int64(1), s.Nothing)
	assert.Equal(t, int64(1), s.DontUnderstand)
	assert.Equal(t, int64(1), s.Skipped)
	assert.False(t, s.Analysed)
	assert.Empty(t, s.Comments)
	assert.Empty(t, s.Tallies)
	raw, _ := json.Marshal(s)
	assert.NotContains(t, string(raw), "Photos took ages")

	// Once its answers have been turned into problems it reads as analysed.
	uxInsertProblem(t, nil, "problem", prefix+" slow photos", "members", 1, nil, openID)
	all = ux.ExperimentsResponse{}
	require.Equal(t, 200, uxGet(t, sup, "experiments", &all))
	for i := range all.Surveys {
		if all.Surveys[i].PromptID == openID {
			assert.True(t, all.Surveys[i].Analysed)
		}
	}
}

func TestUxPromptSurveySettingsPassThrough(t *testing.T) {
	prefix := uniquePrefix("uxset")
	uxExpClean(t, prefix)
	db := database.DBConn
	userID := CreateTestUser(t, uniquePrefix("uxsetuser"), "User")
	token := getToken(t, userID)

	id := insertPrompt(t, "survey", prefix+" which is the biggest problem for you?", `[{"key":"problem:1","label":"Slow photos"},{"key":"problem:2","label":"Wording"}]`)
	db.Exec("UPDATE ux_prompts SET settings = ? WHERE id = ?", `{"best_label":"Biggest problem for me","worst_label":"Smallest problem for me (optional)","randomise":true}`, id)
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/microvolunteering?jwt="+token+"&types=UxPrompt", nil))
	var challenge microvolunteering.Challenge
	require.NoError(t, json.Unmarshal(rsp(resp), &challenge))
	require.NotNil(t, challenge.UxPrompt)
	assert.Equal(t, "Biggest problem for me", challenge.UxPrompt.Settings["best_label"])
	assert.Equal(t, "Smallest problem for me (optional)", challenge.UxPrompt.Settings["worst_label"])
	assert.Equal(t, true, challenge.UxPrompt.Settings["randomise"])

	// No settings is an empty object, never null.
	db.Exec("UPDATE ux_prompts SET settings = NULL WHERE id = ?", id)
	resp, _ = getApp().Test(httptest.NewRequest("GET", "/api/microvolunteering?jwt="+token+"&types=UxPrompt", nil))
	var raw map[string]interface{}
	require.NoError(t, json.Unmarshal(rsp(resp), &raw))
	settings, ok := raw["uxprompt"].(map[string]interface{})["settings"].(map[string]interface{})
	assert.True(t, ok)
	assert.Empty(t, settings)
}

func TestUxPromptSkipIsRecordedAndNotOfferedAgain(t *testing.T) {
	prefix := uniquePrefix("uxskip")
	uxExpClean(t, prefix)
	db := database.DBConn
	userID := CreateTestUser(t, uniquePrefix("uxskipuser"), "User")
	token := getToken(t, userID)

	first := insertPrompt(t, "survey", prefix+" first?", `[{"key":"give","label":"Posting"},{"key":"reply","label":"Replying"}]`)
	second := insertPrompt(t, "open", prefix+" second?", `[]`)
	db.Exec("UPDATE ux_prompts SET max_responses = 1 WHERE id = ?", first)

	get := func(tok string) map[string]interface{} {
		resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/microvolunteering?jwt="+tok+"&types=UxPrompt", nil))
		out := map[string]interface{}{}
		_ = json.Unmarshal(rsp(resp), &out)
		return out
	}
	post := func(tok, body string) int {
		req := httptest.NewRequest("POST", "/api/microvolunteering?jwt="+tok, strings.NewReader(body))
		req.Header.Set("Content-Type", "application/json")
		r, err := getApp().Test(req, 30000)
		require.NoError(t, err)
		return r.StatusCode
	}

	offered := get(token)["uxprompt"].(map[string]interface{})
	require.Equal(t, float64(first), offered["id"])

	// Skipping is accepted for any kind, whatever the options, and stores a skip with no comment.
	assert.Equal(t, 200, post(token, `{"ux_prompt_id":`+itoa(int(first))+`,"ux_answer":{"skipped":true},"comments":"nope"}`))
	var answer string
	var comment *string
	db.Raw("SELECT answer, comment FROM ux_prompt_responses WHERE prompt_id = ? AND userid = ?", first, userID).Row().Scan(&answer, &comment)
	assert.JSONEq(t, `{"skipped":true}`, answer)
	assert.Nil(t, comment)

	// The same member is not handed a question straight away (the week's gap applies to a skip) ...
	assert.NotContains(t, get(token), "type")
	// ... and when the gap has passed, the skipped prompt stays skipped and the next one is offered.
	db.Exec("UPDATE ux_prompt_responses SET timestamp = DATE_SUB(NOW(), INTERVAL 8 DAY) WHERE userid = ?", userID)
	offered = get(token)["uxprompt"].(map[string]interface{})
	assert.Equal(t, float64(second), offered["id"])

	// A skip is not an answer: it does not use up the prompt's cap of 1, so someone else can still answer it.
	other := CreateTestUser(t, uniquePrefix("uxskipother"), "User")
	otherToken := getToken(t, other)
	offered = get(otherToken)["uxprompt"].(map[string]interface{})
	assert.Equal(t, float64(first), offered["id"], "the skipped prompt still has room")
	assert.Equal(t, 200, post(otherToken, `{"ux_prompt_id":`+itoa(int(first))+`,"ux_answer":{"best":"give","worst":"reply"}}`))
	third := CreateTestUser(t, uniquePrefix("uxskipthird"), "User")
	assert.Equal(t, float64(second), get(getToken(t, third))["uxprompt"].(map[string]interface{})["id"], "now the cap is reached by real answers")

	// Skips are left out of every tally.
	sup := uxSupportToken(t, "uxskipsup")
	var all ux.ExperimentsResponse
	require.Equal(t, 200, uxGet(t, sup, "experiments", &all))
	for _, s := range all.Surveys {
		if s.PromptID == first {
			assert.Equal(t, int64(1), s.Responses)
			assert.Equal(t, int64(1), s.Skipped)
			tally := map[string]ux.SurveyTally{}
			for _, tl := range s.Tallies {
				tally[tl.Key] = tl
			}
			assert.Equal(t, int64(1), tally["give"].Best)
			assert.Equal(t, int64(1), tally["reply"].Worst)
		}
	}
}

func TestUxAwarenessDontUnderstandRowCarriesTheCount(t *testing.T) {
	prefix := uniquePrefix("uxaw")
	uxExpClean(t, prefix)
	id := insertPrompt(t, "awareness", prefix+" did you know?", `[{"key":"yes","label":"Yes"},{"key":"no","label":"No"},{"key":"dont_understand","label":"Don't understand"}]`)
	db := database.DBConn
	for i, a := range []string{`{"choice":"yes"}`, `{"choice":"dont_understand"}`, `{"choice":"dont_understand"}`} {
		u := CreateTestUser(t, uniquePrefix(fmt.Sprintf("uxawuser%d", i)), "User")
		db.Exec("INSERT INTO ux_prompt_responses (prompt_id, userid, answer) VALUES (?, ?, ?)", id, u, a)
	}
	var all ux.ExperimentsResponse
	require.Equal(t, 200, uxGet(t, uxSupportToken(t, "uxawsup"), "experiments", &all))
	for _, s := range all.Surveys {
		if s.PromptID != id {
			continue
		}
		assert.Equal(t, int64(3), s.Responses)
		assert.Equal(t, int64(2), s.DontUnderstand)
		for _, tl := range s.Tallies {
			if tl.Key == "dont_understand" {
				assert.Equal(t, int64(2), tl.Count, "the table row agrees with the note under it")
			}
		}
	}
}

// Each problem carries the answer count of its OWN question, so two analysed questions do not
// share a base, and the ranking line carries how many members were asked.
func TestUxExperimentsProblemsCarryTheBaseOfTheirOwnQuestion(t *testing.T) {
	prefix := uniquePrefix("uxbase")
	uxExpClean(t, prefix)
	token := uxSupportToken(t, "uxbasesup")
	db := database.DBConn
	give := "give"

	q1 := insertPrompt(t, "open", prefix+" problems?", `[]`)
	q2 := insertPrompt(t, "open", prefix+" values?", `[]`)
	follow := insertPrompt(t, "survey", prefix+" follow-up", `[]`)
	db.Exec("UPDATE ux_prompts SET settings = JSON_OBJECT('followup_of', ?) WHERE id = ?", q1, follow)
	// q1 was analysed when it had 3 answers; more have come since. q2 has no stored count.
	db.Exec("UPDATE ux_prompts SET settings = JSON_OBJECT('analysed_answers', 3) WHERE id = ?", q1)

	add := func(prompt uint64, answer string) {
		u := CreateTestUser(t, uniquePrefix("uxbaseuser"), "User")
		db.Exec("INSERT INTO ux_prompt_responses (prompt_id, userid, answer) VALUES (?, ?, ?)", prompt, u, answer)
	}
	for i := 0; i < 3; i++ {
		add(q1, `{"text":"slow"}`)
	}
	add(q1, `{"choice":"nothing"}`)
	add(q1, `{"skipped":true}`)
	for i := 0; i < 2; i++ {
		add(q2, `{"text":"free"}`)
	}
	add(follow, `{"best":"problem:1","worst":"problem:2"}`)
	add(follow, `{"choice":"dont_care"}`)
	add(follow, `{"choice":"none","shown":["problem:1","problem:2"]}`)
	add(follow, `{"choice":"dont_understand"}`)

	p := uxInsertProblem(t, &give, "problem", prefix+" photos are slow", "members", 3, 0.5, q1)
	v := uxInsertProblem(t, nil, "value", prefix+" it is free", "members", 2, nil, q2)
	ai := uxInsertProblem(t, &give, "problem", prefix+" noticed by the generator", "ai", 0, nil, nil)

	var all ux.ExperimentsResponse
	require.Equal(t, 200, uxGet(t, token, "experiments", &all))
	found := map[uint64]ux.Problem{}
	for _, f := range all.Flows {
		for _, pr := range f.Problems {
			found[pr.ID] = pr
		}
	}
	assert.Equal(t, int64(3), found[p].Answers, "the count it was analysed over, not the answers that arrived since")
	assert.Equal(t, int64(3), found[p].Asked, "a ranking, don't care or none of these; don't understand is not one")
	assert.Equal(t, int64(0), found[ai].Answers, "a problem not from a question has no base")
	for _, s := range all.Surveys {
		if s.PromptID == q1 {
			assert.True(t, s.Analysed)
			assert.Equal(t, int64(3), s.AnalysedAnswers, "the survey says how many answers the themes were drawn from")
		}
		if s.PromptID == q2 {
			assert.Equal(t, int64(2), s.AnalysedAnswers, "no stored count: its written answers, the same base its values use")
		}
	}
	assert.Equal(t, int64(0), found[ai].Asked)
	for _, val := range all.Values {
		if val.ID == v {
			assert.Equal(t, int64(2), val.Answers, "no stored count: its written answers, not the other question's")
		}
	}
}

// ---- wave 3: shown lists, four at a time, awareness, cadence ----

func uxAnswer(t *testing.T, tok string, promptID uint64, answer string) int {
	t.Helper()
	body := `{"ux_prompt_id":` + itoa(int(promptID)) + `,"ux_answer":` + answer + `}`
	req := httptest.NewRequest("POST", "/api/microvolunteering?jwt="+tok, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	r, err := getApp().Test(req, 30000)
	require.NoError(t, err)
	return r.StatusCode
}

func uxChallenge(t *testing.T, tok string) map[string]interface{} {
	t.Helper()
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/microvolunteering?jwt="+tok+"&types=UxPrompt", nil))
	out := map[string]interface{}{}
	_ = json.Unmarshal(rsp(resp), &out)
	return out
}

func uxStoredAnswer(t *testing.T, promptID, userID uint64) map[string]interface{} {
	t.Helper()
	var answer string
	database.DBConn.Raw("SELECT answer FROM ux_prompt_responses WHERE prompt_id = ? AND userid = ?", promptID, userID).Scan(&answer)
	out := map[string]interface{}{}
	require.NoError(t, json.Unmarshal([]byte(answer), &out))
	return out
}

const uxSixOptions = `[{"key":"a","label":"A"},{"key":"b","label":"B"},{"key":"c","label":"C"},{"key":"d","label":"D"},{"key":"e","label":"E"},{"key":"f","label":"F"}]`

func TestUxSurveyShowsFourAtATimeLeastShownFirst(t *testing.T) {
	prefix := uniquePrefix("uxdisp")
	uxExpClean(t, prefix)
	db := database.DBConn
	id := insertPrompt(t, "survey", prefix+" which most?", uxSixOptions)

	keysOf := func(ch map[string]interface{}) []string {
		out := []string{}
		for _, k := range ch["uxprompt"].(map[string]interface{})["display"].([]interface{}) {
			out = append(out, k.(string))
		}
		return out
	}

	// Nothing shown yet: four distinct option keys, in some order.
	u := CreateTestUser(t, uniquePrefix("uxdispuser"), "User")
	display := keysOf(uxChallenge(t, getToken(t, u)))
	assert.Len(t, display, 4)
	seen := map[string]bool{}
	for _, k := range display {
		assert.Contains(t, []string{"a", "b", "c", "d", "e", "f"}, k)
		seen[k] = true
	}
	assert.Len(t, seen, 4, "no key twice")

	// a, b and c have been shown 5 times and d once; e and f never. Whoever comes next is shown
	// e and f (never seen) and d (once) first, and one of a, b or c to make up the four.
	for i, shown := range []string{`["a","b","c","d"]`, `["a","b","c"]`, `["a","b","c"]`, `["a","b","c"]`, `["a","b","c"]`} {
		other := CreateTestUser(t, uniquePrefix(fmt.Sprintf("uxdispo%d", i)), "User")
		db.Exec("INSERT INTO ux_prompt_responses (prompt_id, userid, answer) VALUES (?, ?, ?)", id, other, `{"best":"a","shown":`+shown+`}`)
	}
	for i := 0; i < 5; i++ {
		next := CreateTestUser(t, uniquePrefix(fmt.Sprintf("uxdispn%d", i)), "User")
		display = keysOf(uxChallenge(t, getToken(t, next)))
		require.Len(t, display, 4)
		assert.Subset(t, display, []string{"d", "e", "f"}, "the least shown come first")
	}

	// settings.show changes the count, and a survey with fewer options shows them all.
	db.Exec("UPDATE ux_prompts SET settings = ? WHERE id = ?", `{"show":3}`, id)
	next := CreateTestUser(t, uniquePrefix("uxdispshow"), "User")
	assert.Len(t, keysOf(uxChallenge(t, getToken(t, next))), 3)
	small := insertPrompt(t, "survey", prefix+" small?", `[{"key":"x","label":"X"},{"key":"y","label":"Y"}]`)
	db.Exec("UPDATE ux_prompts SET status = 'closed' WHERE id = ?", id)
	next = CreateTestUser(t, uniquePrefix("uxdispsmall"), "User")
	ch := uxChallenge(t, getToken(t, next))
	assert.Equal(t, float64(small), ch["uxprompt"].(map[string]interface{})["id"])
	assert.ElementsMatch(t, []string{"x", "y"}, keysOf(ch))

	// Other kinds carry no display.
	exp := insertPrompt(t, "experiment", prefix+" react?", `[{"key":"better","label":"Better"},{"key":"worse","label":"Worse"}]`)
	db.Exec("UPDATE ux_prompts SET status = 'closed' WHERE id = ?", small)
	next = CreateTestUser(t, uniquePrefix("uxdispexp"), "User")
	ch = uxChallenge(t, getToken(t, next))
	require.Equal(t, float64(exp), ch["uxprompt"].(map[string]interface{})["id"])
	assert.NotContains(t, ch["uxprompt"], "display")
}

func TestUxSurveyAnswerShapesAndShownValidation(t *testing.T) {
	prefix := uniquePrefix("uxshown")
	uxExpClean(t, prefix)
	id := insertPrompt(t, "survey", prefix+" which most?", uxSixOptions)
	fresh := func(name string) (uint64, string) {
		u := CreateTestUser(t, uniquePrefix(name), "User")
		return u, getToken(t, u)
	}

	_, tok := fresh("uxshownbad")
	assert.Equal(t, 400, uxAnswer(t, tok, id, `{"best":"a","shown":["a","zzz"]}`), "an unknown key")
	assert.Equal(t, 400, uxAnswer(t, tok, id, `{"best":"a","shown":["a","a"]}`), "a key twice")
	assert.Equal(t, 400, uxAnswer(t, tok, id, `{"best":"a","shown":["a","b","c","d","e","f","a"]}`), "more than the options")
	assert.Equal(t, 400, uxAnswer(t, tok, id, `{"best":"e","shown":["a","b","c","d"]}`), "best that was not shown")
	assert.Equal(t, 400, uxAnswer(t, tok, id, `{"best":"a","worst":"e","shown":["a","b","c","d"]}`), "worst that was not shown")
	assert.Equal(t, 400, uxAnswer(t, tok, id, `{"best":"a","shown":[]}`), "an empty shown list contains nothing")
	assert.Equal(t, 400, uxAnswer(t, tok, id, `{"best":"a","worst":"a","shown":["a","b"]}`), "worst must differ")
	assert.Equal(t, 400, uxAnswer(t, tok, id, `{"best":"none"}`), "an escape is not an option to rank")
	assert.Equal(t, 400, uxAnswer(t, tok, id, `{"choice":"banana","shown":["a"]}`))
	assert.Equal(t, 400, uxAnswer(t, tok, id, `{"choice":"none","shown":["q"]}`), "none with a bad shown list")
	assert.Equal(t, 400, uxAnswer(t, tok, id, `{"best":"a","shown":["none","a"]}`), "an escape key cannot be shown")

	// Best alone is an answer, and shown is stored as sent, in order.
	u1, t1 := fresh("uxshown1")
	assert.Equal(t, 200, uxAnswer(t, t1, id, `{"best":"c","shown":["d","c","a","b"]}`))
	stored := uxStoredAnswer(t, id, u1)
	assert.Equal(t, "c", stored["best"])
	assert.NotContains(t, stored, "worst")
	assert.Equal(t, []interface{}{"d", "c", "a", "b"}, stored["shown"])

	u2, t2 := fresh("uxshown2")
	assert.Equal(t, 200, uxAnswer(t, t2, id, `{"best":"c","worst":"a","shown":["d","c","a","b"]}`))
	assert.Equal(t, "a", uxStoredAnswer(t, id, u2)["worst"])

	u3, t3 := fresh("uxshown3")
	assert.Equal(t, 200, uxAnswer(t, t3, id, `{"choice":"none","shown":["a","b","c","d"]}`))
	stored = uxStoredAnswer(t, id, u3)
	assert.Equal(t, "none", stored["choice"])
	assert.Equal(t, 4, len(stored["shown"].([]interface{})))

	u4, t4 := fresh("uxshown4")
	assert.Equal(t, 200, uxAnswer(t, t4, id, `{"choice":"dont_understand"}`))
	assert.Equal(t, "dont_understand", uxStoredAnswer(t, id, u4)["choice"])

	// Without shown, an answer is still accepted (older clients).
	_, t5 := fresh("uxshown5")
	assert.Equal(t, 200, uxAnswer(t, t5, id, `{"best":"f","worst":"e"}`))
}

func TestUxReactionAndAwarenessAnswers(t *testing.T) {
	prefix := uniquePrefix("uxreact")
	uxExpClean(t, prefix)
	react := insertPrompt(t, "experiment", prefix+" better or worse?", `[{"key":"better","label":"Better"},{"key":"worse","label":"Worse"},{"key":"dont_care","label":"Makes no difference to me"},{"key":"dont_understand","label":"I'm not sure what it's for"}]`)
	fresh := func(name string) (uint64, string) {
		u := CreateTestUser(t, uniquePrefix(name), "User")
		return u, getToken(t, u)
	}

	// The client swaps better and worse for half of members; the order it showed is what is kept.
	u1, t1 := fresh("uxreact1")
	assert.Equal(t, 400, uxAnswer(t, t1, react, `{"choice":"better","shown":["worse","better","dont_care","dont_care"]}`))
	assert.Equal(t, 400, uxAnswer(t, t1, react, `{"choice":"better","shown":["worse","dont_care","dont_understand"]}`), "the choice must be in shown")
	assert.Equal(t, 200, uxAnswer(t, t1, react, `{"choice":"better","shown":["worse","better","dont_care","dont_understand"]}`))
	stored := uxStoredAnswer(t, react, u1)
	assert.Equal(t, "better", stored["choice"])
	assert.Equal(t, []interface{}{"worse", "better", "dont_care", "dont_understand"}, stored["shown"])

	// Awareness: yes, no and not_sure, and nothing else, even if the row lists more.
	aw := insertPrompt(t, "awareness", prefix+" do you know how?", `[{"key":"yes","label":"Yes"},{"key":"no","label":"No"},{"key":"not_sure","label":"Not sure"},{"key":"dont_understand","label":"Don't understand"}]`)
	_, t2 := fresh("uxaw2")
	assert.Equal(t, 400, uxAnswer(t, t2, aw, `{"choice":"maybe"}`))
	assert.Equal(t, 400, uxAnswer(t, t2, aw, `{"choice":"dont_understand"}`))
	assert.Equal(t, 400, uxAnswer(t, t2, aw, `{"best":"yes","worst":"no"}`))
	assert.Equal(t, 200, uxAnswer(t, t2, aw, `{"choice":"not_sure"}`))

	// An awareness prompt whose row lacks not_sure refuses it.
	aw2 := insertPrompt(t, "awareness", prefix+" do you know how 2?", `[{"key":"yes","label":"Yes"},{"key":"no","label":"No"}]`)
	_, t3 := fresh("uxaw3")
	assert.Equal(t, 400, uxAnswer(t, t3, aw2, `{"choice":"not_sure"}`))
	assert.Equal(t, 200, uxAnswer(t, t3, aw2, `{"choice":"no"}`))
}

func TestUxPromptCadenceIsWeeklyAndBacksOffAfterTwoSkips(t *testing.T) {
	prefix := uniquePrefix("uxcad")
	uxExpClean(t, prefix)
	db := database.DBConn
	ids := []uint64{}
	for i := 0; i < 5; i++ {
		ids = append(ids, insertPrompt(t, "open", fmt.Sprintf("%s q%d?", prefix, i), `[]`))
	}
	offered := func(tok string) bool {
		_, ok := uxChallenge(t, tok)["uxprompt"]
		return ok
	}
	age := func(userID uint64, promptID uint64, days int) {
		db.Exec("UPDATE ux_prompt_responses SET timestamp = DATE_SUB(NOW(), INTERVAL ? DAY) WHERE userid = ? AND prompt_id = ?", days, userID, promptID)
	}

	// An answer blocks a prompt for a week, not a day.
	u := CreateTestUser(t, uniquePrefix("uxcadu"), "User")
	tok := getToken(t, u)
	require.True(t, offered(tok))
	require.Equal(t, 200, uxAnswer(t, tok, ids[0], `{"text":"something"}`))
	assert.False(t, offered(tok))
	age(u, ids[0], 3)
	assert.False(t, offered(tok), "three days on is still inside the week")
	age(u, ids[0], 6)
	assert.False(t, offered(tok), "six days on is still inside the week")
	age(u, ids[0], 8)
	assert.True(t, offered(tok))

	// Two skips in a row: nothing for 30 days after the later one.
	s := CreateTestUser(t, uniquePrefix("uxcads"), "User")
	stok := getToken(t, s)
	require.Equal(t, 200, uxAnswer(t, stok, ids[0], `{"skipped":true}`))
	age(s, ids[0], 8)
	assert.True(t, offered(stok), "one skip only costs the week")
	require.Equal(t, 200, uxAnswer(t, stok, ids[1], `{"skipped":true}`))
	age(s, ids[1], 8)
	assert.False(t, offered(stok), "two skips back off")
	age(s, ids[0], 40)
	age(s, ids[1], 29)
	assert.False(t, offered(stok), "29 days after the later skip is still inside the month")
	age(s, ids[1], 31)
	assert.True(t, offered(stok), "a month after the second skip they are asked again")

	// An answer between skips breaks the run.
	m := CreateTestUser(t, uniquePrefix("uxcadm"), "User")
	mtok := getToken(t, m)
	require.Equal(t, 200, uxAnswer(t, mtok, ids[0], `{"skipped":true}`))
	age(m, ids[0], 20)
	require.Equal(t, 200, uxAnswer(t, mtok, ids[1], `{"text":"an answer"}`))
	age(m, ids[1], 15)
	require.Equal(t, 200, uxAnswer(t, mtok, ids[2], `{"skipped":true}`))
	age(m, ids[2], 8)
	assert.True(t, offered(mtok), "skip, answer, skip is not two skips in a row")
}

func TestUxExperimentsReadCarriesTimesShownAndAwarenessFigures(t *testing.T) {
	prefix := uniquePrefix("uxread")
	uxExpClean(t, prefix)
	db := database.DBConn
	survey := insertPrompt(t, "survey", prefix+" which most?", uxSixOptions)
	answers := []string{
		`{"best":"a","worst":"b","shown":["a","b","c","d"]}`,
		`{"best":"a","shown":["a","c","d","e"]}`,
		`{"best":"c","worst":"a","shown":["a","b","c","e"]}`,
		`{"choice":"none","shown":["b","d","e","f"]}`,
	}
	for i, a := range answers {
		u := CreateTestUser(t, uniquePrefix(fmt.Sprintf("uxreadu%d", i)), "User")
		db.Exec("INSERT INTO ux_prompt_responses (prompt_id, userid, answer) VALUES (?, ?, ?)", survey, u, a)
	}

	opts := `[{"key":"yes","label":"Yes"},{"key":"no","label":"No"},{"key":"not_sure","label":"Not sure"}]`
	real := insertPrompt(t, "awareness", prefix+" real?", opts)
	decoy := insertPrompt(t, "awareness", prefix+" decoy?", opts)
	db.Exec("UPDATE ux_prompts SET settings = ? WHERE id = ?", `{"decoy":true,"reveal":"No, we do not."}`, decoy)
	db.Exec("UPDATE ux_prompts SET settings = ? WHERE id = ?", `{"decoy":false,"reveal":"Yes."}`, real)
	for i, c := range []string{"yes", "yes", "no", "not_sure"} {
		u := CreateTestUser(t, uniquePrefix(fmt.Sprintf("uxreadr%d", i)), "User")
		db.Exec("INSERT INTO ux_prompt_responses (prompt_id, userid, answer) VALUES (?, ?, ?)", real, u, `{"choice":"`+c+`"}`)
	}
	for i, c := range []string{"yes", "no", "no", "no", "skipped"} {
		u := CreateTestUser(t, uniquePrefix(fmt.Sprintf("uxreadd%d", i)), "User")
		answer := `{"choice":"` + c + `"}`
		if c == "skipped" {
			answer = `{"skipped":true}`
		}
		db.Exec("INSERT INTO ux_prompt_responses (prompt_id, userid, answer) VALUES (?, ?, ?)", decoy, u, answer)
	}

	var all ux.ExperimentsResponse
	require.Equal(t, 200, uxGet(t, uxSupportToken(t, "uxreadsup"), "experiments", &all))
	by := map[uint64]ux.Survey{}
	for _, s := range all.Surveys {
		by[s.PromptID] = s
	}

	tally := map[string]ux.SurveyTally{}
	for _, tl := range by[survey].Tallies {
		tally[tl.Key] = tl
	}
	assert.Equal(t, int64(3), tally["a"].TimesShown)
	assert.Equal(t, int64(2), tally["a"].Most)
	assert.Equal(t, int64(1), tally["a"].Least)
	assert.Equal(t, int64(3), tally["b"].TimesShown)
	assert.Equal(t, int64(1), tally["b"].Least)
	assert.Equal(t, int64(1), tally["c"].Most)
	assert.Equal(t, int64(3), tally["e"].TimesShown)
	assert.Equal(t, int64(1), tally["f"].TimesShown)
	assert.Equal(t, int64(1), by[survey].None, "none of these is counted on its own")
	assert.Equal(t, int64(4), by[survey].Responses)

	r := by[real]
	assert.False(t, r.Decoy)
	assert.Equal(t, [3]int64{2, 1, 1}, [3]int64{r.Yes, r.No, r.NotSure})
	assert.InDelta(t, 0.5, r.RightShare, 0.001, "two of four said yes, which is right for a real feature")
	assert.Equal(t, int64(4), r.Tallies[0].Count+r.Tallies[1].Count+r.Tallies[2].Count)

	d := by[decoy]
	assert.True(t, d.Decoy)
	assert.Equal(t, [3]int64{1, 3, 0}, [3]int64{d.Yes, d.No, d.NotSure})
	assert.Equal(t, int64(1), d.Skipped)
	assert.InDelta(t, 0.75, d.RightShare, 0.001, "three of four said no, which is right for a decoy")

	// The top-level rate is yes over all answers to every decoy in the read.
	var yes, answered int64
	for _, s := range all.Surveys {
		if s.Decoy {
			yes += s.Yes
			answered += s.Yes + s.No + s.NotSure
		}
	}
	require.NotZero(t, answered)
	assert.Equal(t, answered, all.DecoyAnswers)
	assert.InDelta(t, float64(yes)/float64(answered), all.DecoyYesRate, 0.001)
}

// The verified quotes and the ranking figures are stored in the problem's evidence by the batch;
// the tab can only show them if the endpoint sends them.
func TestUxExperimentsProblemsCarryQuotesAndTheRankingFigures(t *testing.T) {
	prefix := uniquePrefix("uxquote")
	uxExpClean(t, prefix)
	token := uxSupportToken(t, "uxquotesup")
	db := database.DBConn
	give := "give"

	ranked := uxInsertProblem(t, &give, "problem", prefix+" photos are slow", "members", 3, 0.25, nil)
	value := uxInsertProblem(t, nil, "value", prefix+" it is free", "members", 2, nil, nil)
	bare := uxInsertProblem(t, &give, "problem", prefix+" nothing stored", "members", 1, nil, nil)
	db.Exec("UPDATE ux_problems SET evidence = ? WHERE id = ?",
		`{"answer_ids":[1,2],"quotes":[{"answer_id":1,"span":"photos took ages"},{"answer_id":2,"span":"it froze"}],"ranking":{"prompt_id":9,"most":12,"least":2,"times_shown":40}}`, ranked)
	db.Exec("UPDATE ux_problems SET evidence = ? WHERE id = ?", `{"answer_ids":[3],"quotes":[{"answer_id":3,"span":"free is the point"}]}`, value)

	var raw map[string]interface{}
	require.Equal(t, 200, uxGet(t, token, "experiments", &raw))
	find := func(id uint64, items []interface{}) map[string]interface{} {
		for _, i := range items {
			m := i.(map[string]interface{})
			if uint64(m["id"].(float64)) == id {
				return m
			}
		}
		return nil
	}
	var problems []interface{}
	for _, f := range raw["flows"].([]interface{}) {
		if ps, ok := f.(map[string]interface{})["problems"].([]interface{}); ok {
			problems = append(problems, ps...)
		}
	}

	r := find(ranked, problems)
	require.NotNil(t, r)
	quotes := r["quotes"].([]interface{})
	require.Len(t, quotes, 2)
	assert.Equal(t, "photos took ages", quotes[0].(map[string]interface{})["span"])
	assert.Equal(t, float64(1), quotes[0].(map[string]interface{})["answer_id"])
	assert.Equal(t, float64(12), r["most"])
	assert.Equal(t, float64(2), r["least"])
	assert.Equal(t, float64(40), r["times_shown"])

	b := find(bare, problems)
	require.NotNil(t, b)
	assert.Equal(t, []interface{}{}, b["quotes"], "no quotes is an empty list, not null")
	_, hasMost := b["most"]
	assert.False(t, hasMost, "a problem never ranked has no figures to send")

	v := find(value, raw["values"].([]interface{}))
	require.NotNil(t, v)
	assert.Equal(t, "free is the point", v["quotes"].([]interface{})[0].(map[string]interface{})["span"])
}

// An awareness question is a question, not a tip, so the usual one has no reveal in its settings.
// It must still be served, with its three answers, and answered, with nothing missing to refuse it.
func TestUxAwarenessWithoutRevealIsServedAndAnswered(t *testing.T) {
	prefix := uniquePrefix("uxawnoreveal")
	uxExpClean(t, prefix)
	db := database.DBConn
	userID := CreateTestUser(t, uniquePrefix("uxawnorevealuser"), "User")
	token := getToken(t, userID)

	awID := insertPrompt(t, "awareness", prefix+" Do you know how to promise an item to someone?", `[{"key":"yes","label":"Yes"},{"key":"no","label":"No"},{"key":"not_sure","label":"Not sure"}]`)
	db.Exec("UPDATE ux_prompts SET settings = ? WHERE id = ?", `{"decoy":false}`, awID)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/microvolunteering?jwt="+token+"&types=UxPrompt", nil))
	var ch map[string]interface{}
	require.NoError(t, json.Unmarshal(rsp(resp), &ch))
	prompt := ch["uxprompt"].(map[string]interface{})
	assert.Equal(t, "awareness", prompt["kind"])
	settings := prompt["settings"].(map[string]interface{})
	assert.NotContains(t, settings, "reveal", "no reveal is made up when there is none")
	assert.Equal(t, false, settings["decoy"])
	keys := []string{}
	for _, o := range prompt["options"].([]interface{}) {
		keys = append(keys, o.(map[string]interface{})["key"].(string))
	}
	assert.Equal(t, []string{"yes", "no", "not_sure"}, keys)

	assert.Equal(t, 200, uxAnswer(t, token, awID, `{"choice":"yes"}`))
	var n int64
	db.Raw("SELECT COUNT(*) FROM ux_prompt_responses WHERE prompt_id = ? AND userid = ?", awID, userID).Scan(&n)
	assert.Equal(t, int64(1), n)
}
