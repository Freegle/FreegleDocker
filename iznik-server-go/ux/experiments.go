package ux

import (
	"encoding/json"
	"regexp"
	"strings"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/queue"
	"github.com/gofiber/fiber/v2"
)

// The experiments side of the UX tab: per funnel, what is running, what ran and how it ended,
// the ideas left, and what microvolunteers said. The rows are written by the Laravel batch
// (UxExperimentService) and by the two write handlers below. Starting, stopping and closing go
// through a background task so the batch's one implementation of the rules (the concurrency cap,
// the 24-hour end, the config key the client reads) is the only one.

const (
	// TaskUxExperiment is the background task the batch runs for start, stop and close.
	TaskUxExperiment = "ux_experiment"
	maxIdeas         = 30
	maxComments      = 20
)

var (
	experimentNameRe = regexp.MustCompile(`^[A-Za-z0-9_-]{1,64}$`)
	armNameRe        = regexp.MustCompile(`^[A-Za-z0-9_]{1,16}$`)
	flows            = map[string]bool{"give": true, "ask": true, "reply": true, "login": true, "chat_promise": true, "chat_outcome": true, "settings": true}
	experimentStates = map[string]bool{"draft": true, "running": true, "adopted": true, "reverted": true, "inconclusive": true, "stopped": true, "closed": true}
)

type Experiment struct {
	ID                uint64          `json:"id"`
	Flow              string          `json:"flow"`
	Name              string          `json:"name"`
	Title             string          `json:"title"`
	Hypothesis        string          `json:"hypothesis"`
	ChangeDescription string          `json:"change"`
	Expected          string          `json:"expected"`
	Arms              json.RawMessage `json:"arms"`
	Status            string          `json:"status"`
	StartedAt         *string         `json:"started_at"`
	EndsAt            *string         `json:"ends_at"`
	DecidedAt         *string         `json:"decided_at"`
	Decision          *string         `json:"decision"`
	Result            json.RawMessage `json:"result,omitempty"`
	PrURL             *string         `json:"pr_url"`
	CleanupPrURL      *string         `json:"cleanup_pr_url"`
	ImageControl      *string         `json:"image_control"`
	ImageVariant      *string         `json:"image_variant"`
	IdeaID            *uint64         `json:"idea_id"`
	CreatedAt         string          `json:"created_at"`
	// LatestRead is the most recent hourly plain-English read, and Hours how long it has run.
	LatestRead *ExperimentRead `json:"latest_read"`
	Hours      float64         `json:"hours"`
	// CleanupOverdue is true for an adopted experiment whose switch is still in the code after
	// the grace period; the tab lists these so nothing rots.
	CleanupOverdue bool      `json:"cleanup_overdue"`
	Reaction       *Reaction `json:"reaction"`
}

type ExperimentRead struct {
	ReadAt  string  `json:"read_at"`
	Hours   float64 `json:"hours"`
	Summary string  `json:"summary"`
}

// Reaction is what microvolunteers said about an experiment. "Don't care" and "don't understand"
// are answers in their own right: a change nobody minds is cheap to adopt, and one people do not
// understand needs its wording fixed before its result means much.
type Reaction struct {
	Better         int64    `json:"better"`
	Worse          int64    `json:"worse"`
	DontCare       int64    `json:"dont_care"`
	DontUnderstand int64    `json:"dont_understand"`
	Total          int64    `json:"total"`
	Comments       []string `json:"comments"`
}

type Idea struct {
	ID           uint64          `json:"id"`
	Flow         string          `json:"flow"`
	Title        string          `json:"title"`
	Rationale    string          `json:"rationale"`
	Expected     string          `json:"expected"`
	Evidence     json.RawMessage `json:"evidence,omitempty"`
	Score        float64         `json:"score"`
	Status       string          `json:"status"`
	ExperimentID *uint64         `json:"experiment_id"`
	Source       string          `json:"source"`
	CreatedAt    string          `json:"created_at"`
}

// SurveyTally is what members said per option: best and worst for a "best / worst" survey, count
// for an awareness question ("did you know you can...", answered with one option).
type SurveyTally struct {
	Key   string `json:"key"`
	Label string `json:"label"`
	Best  int64  `json:"best"`
	Worst int64  `json:"worst"`
	Count int64  `json:"count"`
}

type Survey struct {
	PromptID       uint64        `json:"prompt_id"`
	Kind           string        `json:"kind"`
	Flow           string        `json:"flow"`
	Question       string        `json:"question"`
	Status         string        `json:"status"`
	Responses      int64         `json:"responses"`
	Tallies        []SurveyTally `json:"tallies"`
	DontCare       int64         `json:"dont_care"`
	DontUnderstand int64         `json:"dont_understand"`
	Comments       []string      `json:"comments"`
}

type FlowExperiments struct {
	Flow        string       `json:"flow"`
	Running     []Experiment `json:"running"`
	History     []Experiment `json:"history"`
	Ideas       []Idea       `json:"ideas"`
	IdeasTried  int64        `json:"ideas_tried"`
	SurveyBest  int64        `json:"survey_best"`
	SurveyWorst int64        `json:"survey_worst"`
}

type ExperimentsResponse struct {
	Flows        []FlowExperiments `json:"flows"`
	RunningCount int64             `json:"running_count"`
	MaxRunning   int64             `json:"max_running"`
	Surveys      []Survey          `json:"surveys"`
}

type experimentRow struct {
	ID                uint64     `gorm:"column:id"`
	Flow              string     `gorm:"column:flow"`
	Name              string     `gorm:"column:name"`
	Title             string     `gorm:"column:title"`
	Hypothesis        string     `gorm:"column:hypothesis"`
	ChangeDescription string     `gorm:"column:change_description"`
	Expected          string     `gorm:"column:expected"`
	Arms              string     `gorm:"column:arms"`
	Status            string     `gorm:"column:status"`
	StartedAt         *time.Time `gorm:"column:started_at"`
	EndsAt            *time.Time `gorm:"column:ends_at"`
	DecidedAt         *time.Time `gorm:"column:decided_at"`
	Decision          *string    `gorm:"column:decision"`
	Result            *string    `gorm:"column:result"`
	PrURL             *string    `gorm:"column:pr_url"`
	CleanupPrURL      *string    `gorm:"column:cleanup_pr_url"`
	ImageControl      *string    `gorm:"column:image_control"`
	ImageVariant      *string    `gorm:"column:image_variant"`
	IdeaID            *uint64    `gorm:"column:idea_id"`
	CreatedAt         time.Time  `gorm:"column:created_at"`
}

const timeFormat = "2006-01-02 15:04:05"

func tstr(t *time.Time) *string {
	if t == nil {
		return nil
	}
	s := t.UTC().Format(timeFormat)
	return &s
}

// Experiments lists, per funnel, the running and past experiments with their latest read, the
// open ideas, and the survey tallies. Support/Admin only.
// @Router /modtools/ux/experiments [get]
func Experiments(c *fiber.Ctx) error {
	if err := requireSupport(c); err != nil {
		return err
	}
	flow, err := parseFlow(c)
	if err != nil {
		return err
	}

	db := database.DBConn
	now := time.Now().UTC()
	cleanupDays := 7

	var rows []experimentRow
	q := db.Table("ux_experiments").Order("id DESC")
	if flow != "" {
		q = q.Where("flow = ?", flow)
	}
	if err := q.Scan(&rows).Error; err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Could not read experiments")
	}

	byFlow := map[string]*FlowExperiments{}
	order := []string{}
	get := func(f string) *FlowExperiments {
		if fe, ok := byFlow[f]; ok {
			return fe
		}
		fe := &FlowExperiments{Flow: f, Running: []Experiment{}, History: []Experiment{}, Ideas: []Idea{}}
		byFlow[f] = fe
		order = append(order, f)
		return fe
	}
	// Every known flow has a section, even an empty one, so the tab reads the same every week.
	for _, f := range []string{"give", "ask", "login", "reply", "chat_promise", "chat_outcome", "settings"} {
		if flow == "" || flow == f {
			get(f)
		}
	}

	var running int64
	for _, r := range rows {
		e := Experiment{
			ID: r.ID, Flow: r.Flow, Name: r.Name, Title: r.Title, Hypothesis: r.Hypothesis,
			ChangeDescription: r.ChangeDescription, Expected: r.Expected, Arms: json.RawMessage(r.Arms),
			Status: r.Status, StartedAt: tstr(r.StartedAt), EndsAt: tstr(r.EndsAt), DecidedAt: tstr(r.DecidedAt),
			Decision: r.Decision, PrURL: r.PrURL, CleanupPrURL: r.CleanupPrURL, ImageControl: r.ImageControl,
			ImageVariant: r.ImageVariant, IdeaID: r.IdeaID, CreatedAt: r.CreatedAt.UTC().Format(timeFormat),
		}
		if r.Result != nil && json.Valid([]byte(*r.Result)) {
			e.Result = json.RawMessage(*r.Result)
		}
		if r.StartedAt != nil {
			end := now
			if r.DecidedAt != nil {
				end = *r.DecidedAt
			}
			e.Hours = float64(int(end.Sub(*r.StartedAt).Minutes())) / 60
		}
		if r.Status == "adopted" && r.DecidedAt != nil && now.Sub(*r.DecidedAt) > time.Duration(cleanupDays)*24*time.Hour {
			e.CleanupOverdue = true
		}
		e.LatestRead = latestRead(r.ID)
		e.Reaction = reaction(r.ID)

		fe := get(r.Flow)
		if r.Status == "running" {
			running++
			fe.Running = append(fe.Running, e)
		} else if r.Status != "draft" {
			fe.History = append(fe.History, e)
		} else {
			// Drafts sit with history so a prepared experiment is visible before it starts.
			fe.History = append(fe.History, e)
		}
	}

	// Ideas: open ones ranked by score, plus how many have been tried.
	var ideas []Idea
	iq := db.Table("ux_ideas").Select("id, flow, title, rationale, expected, evidence, score, status, experiment_id, source, created_at").
		Where("status IN ('open', 'scheduled')").Order("score DESC, id ASC")
	if flow != "" {
		iq = iq.Where("flow = ?", flow)
	}
	var ideaRows []struct {
		ID           uint64    `gorm:"column:id"`
		Flow         string    `gorm:"column:flow"`
		Title        string    `gorm:"column:title"`
		Rationale    string    `gorm:"column:rationale"`
		Expected     string    `gorm:"column:expected"`
		Evidence     *string   `gorm:"column:evidence"`
		Score        float64   `gorm:"column:score"`
		Status       string    `gorm:"column:status"`
		ExperimentID *uint64   `gorm:"column:experiment_id"`
		Source       string    `gorm:"column:source"`
		CreatedAt    time.Time `gorm:"column:created_at"`
	}
	iq.Scan(&ideaRows)
	for _, r := range ideaRows {
		fe := get(r.Flow)
		if len(fe.Ideas) >= maxIdeas {
			continue
		}
		i := Idea{ID: r.ID, Flow: r.Flow, Title: r.Title, Rationale: r.Rationale, Expected: r.Expected, Score: r.Score,
			Status: r.Status, ExperimentID: r.ExperimentID, Source: r.Source, CreatedAt: r.CreatedAt.UTC().Format(timeFormat)}
		if r.Evidence != nil && json.Valid([]byte(*r.Evidence)) {
			i.Evidence = json.RawMessage(*r.Evidence)
		}
		fe.Ideas = append(fe.Ideas, i)
	}
	ideas = nil
	_ = ideas
	var tried []struct {
		Flow string `gorm:"column:flow"`
		N    int64  `gorm:"column:n"`
	}
	db.Table("ux_ideas").Select("flow, COUNT(*) AS n").Where("status IN ('done', 'rejected', 'running')").Group("flow").Scan(&tried)
	for _, t := range tried {
		if fe, ok := byFlow[t.Flow]; ok {
			fe.IdeasTried = t.N
		}
	}

	surveys := surveyTallies()
	for _, s := range surveys {
		if s.Kind != "survey" {
			continue
		}
		for _, t := range s.Tallies {
			if fe, ok := byFlow[t.Key]; ok {
				fe.SurveyBest += t.Best
				fe.SurveyWorst += t.Worst
			}
		}
	}

	out := ExperimentsResponse{Flows: []FlowExperiments{}, RunningCount: running, MaxRunning: 5, Surveys: surveys}
	for _, f := range order {
		out.Flows = append(out.Flows, *byFlow[f])
	}
	return c.JSON(out)
}

func latestRead(experimentID uint64) *ExperimentRead {
	var r struct {
		ReadAt  time.Time `gorm:"column:read_at"`
		Hours   float64   `gorm:"column:hours"`
		Summary string    `gorm:"column:summary"`
	}
	res := database.DBConn.Table("ux_experiment_reads").Select("read_at, hours, summary").
		Where("experiment_id = ?", experimentID).Order("read_at DESC").Limit(1).Scan(&r)
	if res.RowsAffected == 0 {
		return nil
	}
	return &ExperimentRead{ReadAt: r.ReadAt.UTC().Format(timeFormat), Hours: r.Hours, Summary: r.Summary}
}

// reaction tallies the experiment's prompt answers: {"choice": "better"|"same"|"worse"}.
func reaction(experimentID uint64) *Reaction {
	db := database.DBConn
	var rows []struct {
		Answer  string  `gorm:"column:answer"`
		Comment *string `gorm:"column:comment"`
	}
	db.Table("ux_prompt_responses").Select("ux_prompt_responses.answer, ux_prompt_responses.comment").
		Joins("INNER JOIN ux_prompts ON ux_prompts.id = ux_prompt_responses.prompt_id").
		Where("ux_prompts.experiment_id = ?", experimentID).Order("ux_prompt_responses.timestamp DESC").Scan(&rows)
	out := &Reaction{Comments: []string{}}
	for _, r := range rows {
		var a struct {
			Choice string `json:"choice"`
		}
		_ = json.Unmarshal([]byte(r.Answer), &a)
		switch a.Choice {
		case "better":
			out.Better++
		case "worse":
			out.Worse++
		case "dont_care":
			out.DontCare++
		case "dont_understand":
			out.DontUnderstand++
		default:
			continue
		}
		out.Total++
		if r.Comment != nil && strings.TrimSpace(*r.Comment) != "" && len(out.Comments) < maxComments {
			out.Comments = append(out.Comments, clip(*r.Comment, 300))
		}
	}
	return out
}

func clip(s string, n int) string {
	r := []rune(strings.TrimSpace(s))
	if len(r) > n {
		return string(r[:n])
	}
	return string(r)
}

// surveyTallies counts, for every survey and awareness prompt, what members said per option:
// {"best": "<key>", "worst": "<key>"} or {"choice": "<key>"}, with the two escape answers apart.
func surveyTallies() []Survey {
	db := database.DBConn
	var prompts []struct {
		ID       uint64  `gorm:"column:id"`
		Kind     string  `gorm:"column:kind"`
		Flow     *string `gorm:"column:flow"`
		Question string  `gorm:"column:question"`
		Options  string  `gorm:"column:options"`
		Status   string  `gorm:"column:status"`
	}
	db.Table("ux_prompts").Select("id, kind, flow, question, options, status").Where("kind IN ('survey', 'awareness')").Order("id DESC").Limit(20).Scan(&prompts)
	out := []Survey{}
	for _, p := range prompts {
		var options []struct {
			Key   string `json:"key"`
			Label string `json:"label"`
		}
		_ = json.Unmarshal([]byte(p.Options), &options)
		idx := map[string]int{}
		s := Survey{PromptID: p.ID, Kind: p.Kind, Question: p.Question, Status: p.Status, Tallies: []SurveyTally{}, Comments: []string{}}
		if p.Flow != nil {
			s.Flow = *p.Flow
		}
		for _, o := range options {
			idx[o.Key] = len(s.Tallies)
			s.Tallies = append(s.Tallies, SurveyTally{Key: o.Key, Label: o.Label})
		}
		var rows []struct {
			Answer  string  `gorm:"column:answer"`
			Comment *string `gorm:"column:comment"`
		}
		db.Table("ux_prompt_responses").Select("answer, comment").Where("prompt_id = ?", p.ID).Order("timestamp DESC").Scan(&rows)
		for _, r := range rows {
			var a struct {
				Best   string `json:"best"`
				Worst  string `json:"worst"`
				Choice string `json:"choice"`
			}
			_ = json.Unmarshal([]byte(r.Answer), &a)
			if i, ok := idx[a.Best]; ok {
				s.Tallies[i].Best++
			}
			if i, ok := idx[a.Worst]; ok {
				s.Tallies[i].Worst++
			}
			switch a.Choice {
			case "dont_care":
				s.DontCare++
			case "dont_understand":
				s.DontUnderstand++
			default:
				if i, ok := idx[a.Choice]; ok {
					s.Tallies[i].Count++
				}
			}
			s.Responses++
			if r.Comment != nil && strings.TrimSpace(*r.Comment) != "" && len(s.Comments) < maxComments {
				s.Comments = append(s.Comments, clip(*r.Comment, 300))
			}
		}
		out = append(out, s)
	}
	return out
}

// ---- writes ----

type CreateExperimentRequest struct {
	Flow         string                 `json:"flow"`
	Name         string                 `json:"name"`
	Title        string                 `json:"title"`
	Hypothesis   string                 `json:"hypothesis"`
	Change       string                 `json:"change"`
	Expected     string                 `json:"expected"`
	Arms         map[string][2]int      `json:"arms"`
	PrURL        string                 `json:"pr_url"`
	ImageControl string                 `json:"image_control"`
	ImageVariant string                 `json:"image_variant"`
	IdeaID       uint64                 `json:"idea_id"`
	Extra        map[string]interface{} `json:"-"`
}

// validArms checks arms the way the batch does: names, ranges inside 0..99, no overlap, a control
// and at least one variant. Returns the arms as JSON.
func validArms(arms map[string][2]int) (string, string) {
	if len(arms) == 0 {
		return `{"control":[0,49],"b":[50,99]}`, ""
	}
	covered := [100]bool{}
	for name, r := range arms {
		if !armNameRe.MatchString(name) {
			return "", "arm names are letters, digits and _"
		}
		if r[0] < 0 || r[1] > 99 || r[0] > r[1] {
			return "", "arm " + name + " must be a [from, to] range within 0 to 99"
		}
		for i := r[0]; i <= r[1]; i++ {
			if covered[i] {
				return "", "arm " + name + " overlaps another arm"
			}
			covered[i] = true
		}
	}
	if _, ok := arms["control"]; !ok {
		return "", "arms must include control"
	}
	if len(arms) < 2 {
		return "", "arms must include a variant"
	}
	b, _ := json.Marshal(arms)
	return string(b), ""
}

// CreateExperiment adds a draft. Support/Admin only.
// @Router /modtools/ux/experiments [post]
func CreateExperiment(c *fiber.Ctx) error {
	if err := requireSupport(c); err != nil {
		return err
	}
	var req CreateExperimentRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}
	if !flows[req.Flow] {
		return fiber.NewError(fiber.StatusBadRequest, "flow must be one of give, ask, reply, login, chat_promise, chat_outcome, settings")
	}
	if !experimentNameRe.MatchString(req.Name) {
		return fiber.NewError(fiber.StatusBadRequest, "name must be 1 to 64 letters, digits, _ or -")
	}
	for k, v := range map[string]string{"title": req.Title, "hypothesis": req.Hypothesis, "change": req.Change, "expected": req.Expected} {
		if strings.TrimSpace(v) == "" {
			return fiber.NewError(fiber.StatusBadRequest, k+" is required")
		}
	}
	arms, msg := validArms(req.Arms)
	if msg != "" {
		return fiber.NewError(fiber.StatusBadRequest, msg)
	}

	db := database.DBConn
	var exists int64
	db.Table("ux_experiments").Where("name = ?", req.Name).Count(&exists)
	if exists > 0 {
		return fiber.NewError(fiber.StatusConflict, "An experiment with that name already exists")
	}

	now := time.Now().UTC()
	row := map[string]interface{}{
		"flow": req.Flow, "name": req.Name, "title": clip(req.Title, 160), "hypothesis": strings.TrimSpace(req.Hypothesis),
		"change_description": strings.TrimSpace(req.Change), "expected": strings.TrimSpace(req.Expected), "arms": arms,
		"status": "draft", "created_at": now, "updated_at": now,
	}
	if req.PrURL != "" {
		row["pr_url"] = clip(req.PrURL, 255)
	}
	if req.ImageControl != "" {
		row["image_control"] = clip(req.ImageControl, 255)
	}
	if req.ImageVariant != "" {
		row["image_variant"] = clip(req.ImageVariant, 255)
	}
	if req.IdeaID > 0 {
		row["idea_id"] = req.IdeaID
	}
	if err := db.Table("ux_experiments").Create(row).Error; err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Could not create the experiment")
	}
	var id uint64
	db.Table("ux_experiments").Select("id").Where("name = ?", req.Name).Scan(&id)
	if req.IdeaID > 0 {
		db.Table("ux_ideas").Where("id = ?", req.IdeaID).Updates(map[string]interface{}{"status": "scheduled", "experiment_id": id, "updated_at": now})
	}
	return c.JSON(fiber.Map{"id": id, "status": "draft"})
}

type ExperimentActionRequest struct {
	Action    string `json:"action"`
	Reason    string `json:"reason"`
	CleanupPr string `json:"cleanup_pr"`
}

// ExperimentAction asks the batch to start, stop or close an experiment. The request is queued
// as a background task the batch runs within a minute, so the rules live in one place.
// @Router /modtools/ux/experiments/{id} [patch]
func ExperimentAction(c *fiber.Ctx) error {
	if err := requireSupport(c); err != nil {
		return err
	}
	id, err := c.ParamsInt("id")
	if err != nil || id <= 0 {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid experiment id")
	}
	var req ExperimentActionRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}
	if req.Action != "start" && req.Action != "stop" && req.Action != "close" {
		return fiber.NewError(fiber.StatusBadRequest, "action must be start, stop or close")
	}

	db := database.DBConn
	var status string
	res := db.Table("ux_experiments").Select("status").Where("id = ?", id).Scan(&status)
	if res.RowsAffected == 0 {
		return fiber.NewError(fiber.StatusNotFound, "No such experiment")
	}
	switch req.Action {
	case "start":
		if status != "draft" && status != "stopped" && status != "inconclusive" && status != "reverted" {
			return fiber.NewError(fiber.StatusBadRequest, "Only a draft or a finished experiment can be started")
		}
	case "stop":
		if status != "running" {
			return fiber.NewError(fiber.StatusBadRequest, "Only a running experiment can be stopped")
		}
	case "close":
		if status != "adopted" {
			return fiber.NewError(fiber.StatusBadRequest, "Only an adopted experiment can be closed")
		}
	}

	if err := queue.QueueTask(TaskUxExperiment, map[string]interface{}{
		"id": id, "action": req.Action, "reason": clip(req.Reason, 500), "cleanup_pr": clip(req.CleanupPr, 255),
	}); err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Could not queue the request")
	}
	return c.JSON(fiber.Map{"queued": true, "action": req.Action, "id": id})
}
