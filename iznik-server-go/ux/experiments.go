package ux

import (
	"encoding/json"
	"errors"
	"math"
	"os"
	"regexp"
	"strconv"
	"strings"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/queue"
	"github.com/go-sql-driver/mysql"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
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
	maxProblems      = 30
	maxValues        = 20
	// maxSurveys bounds the prompts the tab lists; an open prompt, its follow-up and the older
	// surveys all share it.
	maxSurveys = 30
)

var (
	experimentNameRe = regexp.MustCompile(`^[A-Za-z0-9_-]{1,64}$`)
	armNameRe        = regexp.MustCompile(`^[A-Za-z0-9_]{1,16}$`)
	flows            = map[string]bool{"give": true, "ask": true, "reply": true, "login": true, "chat_promise": true, "chat_outcome": true, "settings": true}
	// These names are keys of the public config object the client reads. A name that is also an
	// Object.prototype key would be written into it and could shadow the lookup.
	reservedNames    = map[string]bool{"__proto__": true, "constructor": true, "prototype": true}
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
	// Design is what the batch keeps about how the test is read: the flows it can affect
	// (touches), the effect it was sized for (expected_points) and its power.
	Design json.RawMessage `json:"design,omitempty"`
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
	Type         string          `json:"type"`
	ProblemID    *uint64         `json:"problem_id"`
	ExperimentID *uint64         `json:"experiment_id"`
	Source       string          `json:"source"`
	CreatedAt    string          `json:"created_at"`
}

// ProblemIdea and ProblemExperiment are the short forms listed under a problem.
type ProblemIdea struct {
	ID     uint64  `json:"id"`
	Title  string  `json:"title"`
	Status string  `json:"status"`
	Type   string  `json:"type"`
	Score  float64 `json:"score"`
}

type ProblemExperiment struct {
	ID     uint64 `json:"id"`
	Title  string `json:"title"`
	Status string `json:"status"`
}

// Problem is something members said gets in their way, with the ideas aimed at it and the
// experiments those became. Weight is how others ranked it in the follow-up survey, once there
// is one: (most - least) / times shown, so null means nobody has been asked yet.
type Problem struct {
	ID          uint64 `json:"id"`
	Title       string `json:"title"`
	Description string `json:"description"`
	Source      string `json:"source"`
	Mentions    int64  `json:"mentions"`
	// Answers is how many answers the open question this problem came from was analysed over, the
	// base for Mentions ("14 of 120"): the count stored when it was analysed, not how many have
	// arrived since, because the themes were drawn from the first. It is per problem because the
	// tab can hold several analysed questions, and a number from one question over another's
	// answers is wrong. Zero for a problem that did not come from a question.
	Answers int64 `json:"answers"`
	// Asked is how many members ranked the problems in the follow-up survey, the base for Weight.
	// Zero until there is a follow-up with answers.
	Asked  int64    `json:"asked"`
	Weight *float64 `json:"weight"`
	// Most, Least and TimesShown are the figures Weight was worked out from, read from the
	// ranking the tick stored in the problem's evidence. They are what the tab shows below 100
	// answers, so they are null until the problem has been shown in a follow-up.
	Most        *int64              `json:"most,omitempty"`
	Least       *int64              `json:"least,omitempty"`
	TimesShown  *int64              `json:"times_shown,omitempty"`
	Quotes      []Quote             `json:"quotes"`
	Status      string              `json:"status"`
	Ideas       []ProblemIdea       `json:"ideas"`
	Experiments []ProblemExperiment `json:"experiments"`
}

// Value is something members said they value about Freegle.
type Value struct {
	ID       uint64 `json:"id"`
	Title    string `json:"title"`
	Mentions int64  `json:"mentions"`
	// Answers is how many answers the question it came from was analysed over (see Problem.Answers).
	Answers int64   `json:"answers"`
	Quotes  []Quote `json:"quotes"`
}

// Quote is words copied from one member's answer that the batch checked really are in it. It is
// shown in ModTools only, escaped, behind a toggle.
type Quote struct {
	AnswerID uint64 `json:"answer_id"`
	Span     string `json:"span"`
}

// SurveyTally is what members said per option: most and least for a problem ranking, count for
// an awareness or reaction question answered with one option. TimesShown is how often the option
// was on the screen (from the shown lists stored with answers), the base for a rate: an option
// shown 40 times and picked most 10 times is a different story from one shown 400 times.
// Best and Worst are the same figures as Most and Least, kept for older readers of this endpoint.
type SurveyTally struct {
	Key        string `json:"key"`
	Label      string `json:"label"`
	Best       int64  `json:"best"`
	Worst      int64  `json:"worst"`
	Most       int64  `json:"most"`
	Least      int64  `json:"least"`
	TimesShown int64  `json:"times_shown"`
	Count      int64  `json:"count"`
}

// Survey is one prompt that is not about a single experiment. Responses never includes a skip.
// An open prompt (free text) lists no answers here: they are member content and are read one
// prompt at a time, not in this overview.
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
	Nothing        int64         `json:"nothing"`
	// None is "None of these has got in my way" on a problem ranking: a real answer, and the
	// reason a rate of picks is taken over times shown rather than over responses.
	None     int64    `json:"none"`
	Skipped  int64    `json:"skipped"`
	Analysed bool     `json:"analysed"`
	Comments []string `json:"comments"`
	// Awareness only. Yes, No and NotSure are the answers to "is it possible to ...?". A Decoy
	// asks about something Freegle does not do, so its right answer is no. RightShare is the
	// share of those three answers that were right (0 until someone answers).
	Yes        int64   `json:"yes"`
	No         int64   `json:"no"`
	NotSure    int64   `json:"not_sure"`
	Decoy      bool    `json:"decoy"`
	RightShare float64 `json:"right_share"`
}

type FlowExperiments struct {
	Flow        string       `json:"flow"`
	Running     []Experiment `json:"running"`
	History     []Experiment `json:"history"`
	Ideas       []Idea       `json:"ideas"`
	Problems    []Problem    `json:"problems"`
	IdeasTried  int64        `json:"ideas_tried"`
	SurveyBest  int64        `json:"survey_best"`
	SurveyWorst int64        `json:"survey_worst"`
}

type ExperimentsResponse struct {
	Flows        []FlowExperiments `json:"flows"`
	RunningCount int64             `json:"running_count"`
	MaxRunning   int64             `json:"max_running"`
	Surveys      []Survey          `json:"surveys"`
	// DecoyYesRate is the share of answers to decoy awareness questions that said yes. Members
	// who say yes to something that does not exist are agreeing without knowing, so the figure is
	// shown next to the real questions to let a reader discount them. DecoyAnswers is the base.
	DecoyYesRate    float64   `json:"decoy_yes_rate"`
	DecoyAnswers    int64     `json:"decoy_answers"`
	Values          []Value   `json:"values"`
	GeneralProblems []Problem `json:"general_problems"`
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
	Design            *string    `gorm:"column:design"`
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

// envInt reads a whole-number setting from the environment, so the tab shows the same cap and
// grace period as the batch that enforces them (UX_EXPERIMENTS_MAX_CONCURRENT and
// UX_EXPERIMENTS_CLEANUP_DAYS, defaulting alike in iznik-batch/config/freegle.php).
func envInt(name string, def int) int {
	if v, err := strconv.Atoi(strings.TrimSpace(os.Getenv(name))); err == nil && v > 0 {
		return v
	}
	return def
}

// Experiments lists, per funnel, the running and past experiments with their latest read, the
// open ideas, the problems members raised, and the survey tallies. Support/Admin only.
//
// Everything is read in a handful of grouped queries, not one per experiment or prompt: the tab
// is opened often and an experiment history grows without limit.
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
	cleanupDays := envInt("UX_EXPERIMENTS_CLEANUP_DAYS", 7)
	maxRunning := envInt("UX_EXPERIMENTS_MAX_CONCURRENT", 5)

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
		fe := &FlowExperiments{Flow: f, Running: []Experiment{}, History: []Experiment{}, Ideas: []Idea{}, Problems: []Problem{}}
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

	ids := make([]uint64, 0, len(rows))
	for _, r := range rows {
		ids = append(ids, r.ID)
	}
	reads := latestReads(ids)
	reactions := reactionsFor(ids)

	var running int64
	expByID := map[uint64]ProblemExperiment{}
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
		if r.Design != nil && json.Valid([]byte(*r.Design)) {
			e.Design = json.RawMessage(*r.Design)
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
		e.LatestRead = reads[r.ID]
		e.Reaction = reactions[r.ID]
		expByID[r.ID] = ProblemExperiment{ID: r.ID, Title: r.Title, Status: r.Status}

		fe := get(r.Flow)
		if r.Status == "running" {
			running++
			fe.Running = append(fe.Running, e)
		} else {
			// Drafts sit with history so a prepared experiment is visible before it starts.
			fe.History = append(fe.History, e)
		}
	}

	// Ideas: open ones ranked by score, plus how many have been tried.
	iq := db.Table("ux_ideas").Select("id, flow, title, rationale, expected, evidence, score, status, type, problem_id, experiment_id, source, created_at").
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
		Type         string    `gorm:"column:type"`
		ProblemID    *uint64   `gorm:"column:problem_id"`
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
			Status: r.Status, Type: r.Type, ProblemID: r.ProblemID, ExperimentID: r.ExperimentID, Source: r.Source,
			CreatedAt: r.CreatedAt.UTC().Format(timeFormat)}
		if r.Evidence != nil && json.Valid([]byte(*r.Evidence)) {
			i.Evidence = json.RawMessage(*r.Evidence)
		}
		fe.Ideas = append(fe.Ideas, i)
	}
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

	problems, general, values := problemsFor(flow, expByID)
	for _, p := range problems {
		if fe, ok := byFlow[p.flow]; ok {
			fe.Problems = append(fe.Problems, p.Problem)
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

	out := ExperimentsResponse{Flows: []FlowExperiments{}, RunningCount: running, MaxRunning: int64(maxRunning), Surveys: surveys,
		Values: values, GeneralProblems: general}
	var decoyYes int64
	for _, s := range surveys {
		if s.Kind == "awareness" && s.Decoy {
			decoyYes += s.Yes
			out.DecoyAnswers += s.Yes + s.No + s.NotSure
		}
	}
	if out.DecoyAnswers > 0 {
		out.DecoyYesRate = share(decoyYes, out.DecoyAnswers)
	}
	for _, f := range order {
		out.Flows = append(out.Flows, *byFlow[f])
	}
	return c.JSON(out)
}

type flowProblem struct {
	Problem
	flow string
}

// problemsFor reads the problems and values members raised, with the ideas aimed at each problem
// and the experiments those ideas became. A problem with no flow is general; values are always
// listed on their own. Two queries however many problems there are.
func problemsFor(flow string, experiments map[uint64]ProblemExperiment) ([]flowProblem, []Problem, []Value) {
	db := database.DBConn
	var rows []struct {
		ID          uint64   `gorm:"column:id"`
		Flow        *string  `gorm:"column:flow"`
		Kind        string   `gorm:"column:kind"`
		Title       string   `gorm:"column:title"`
		Description *string  `gorm:"column:description"`
		Source      string   `gorm:"column:source"`
		PromptID    *uint64  `gorm:"column:prompt_id"`
		Mentions    int64    `gorm:"column:mentions"`
		Weight      *float64 `gorm:"column:weight"`
		Status      string   `gorm:"column:status"`
		Evidence    *string  `gorm:"column:evidence"`
	}
	pq := db.Table("ux_problems").Select("id, flow, kind, title, description, source, prompt_id, mentions, weight, status, evidence").
		Where("status != 'dismissed'").Order("mentions DESC, id ASC")
	if flow != "" {
		// A flow's own problems, and the ones that belong to no flow in particular.
		pq = pq.Where("(flow = ? OR flow IS NULL)", flow)
	}
	pq.Scan(&rows)

	problemIDs := []uint64{}
	promptIDs := []uint64{}
	for _, r := range rows {
		if r.Kind == "problem" {
			problemIDs = append(problemIDs, r.ID)
		}
		if r.PromptID != nil {
			promptIDs = append(promptIDs, *r.PromptID)
		}
	}
	answers, asked := promptBases(promptIDs)
	ideasByProblem := map[uint64][]ProblemIdea{}
	expsByProblem := map[uint64][]ProblemExperiment{}
	if len(problemIDs) > 0 {
		var ideas []struct {
			ID           uint64  `gorm:"column:id"`
			ProblemID    uint64  `gorm:"column:problem_id"`
			Title        string  `gorm:"column:title"`
			Status       string  `gorm:"column:status"`
			Type         string  `gorm:"column:type"`
			Score        float64 `gorm:"column:score"`
			ExperimentID *uint64 `gorm:"column:experiment_id"`
		}
		db.Table("ux_ideas").Select("id, problem_id, title, status, type, score, experiment_id").
			Where("problem_id IN ?", problemIDs).Order("score DESC, id ASC").Scan(&ideas)
		seen := map[[2]uint64]bool{}
		for _, i := range ideas {
			ideasByProblem[i.ProblemID] = append(ideasByProblem[i.ProblemID], ProblemIdea{ID: i.ID, Title: i.Title, Status: i.Status, Type: i.Type, Score: i.Score})
			if i.ExperimentID != nil {
				if e, ok := experiments[*i.ExperimentID]; ok && !seen[[2]uint64{i.ProblemID, e.ID}] {
					seen[[2]uint64{i.ProblemID, e.ID}] = true
					expsByProblem[i.ProblemID] = append(expsByProblem[i.ProblemID], e)
				}
			}
		}
	}

	problems := []flowProblem{}
	general := []Problem{}
	values := []Value{}
	perFlow := map[string]int{}
	for _, r := range rows {
		if r.Kind == "value" {
			if len(values) < maxValues {
				ev := parseProblemEvidence(r.Evidence)
				values = append(values, Value{ID: r.ID, Title: r.Title, Mentions: r.Mentions, Answers: answerBase(r.PromptID, answers), Quotes: ev.Quotes})
			}
			continue
		}
		ev := parseProblemEvidence(r.Evidence)
		p := Problem{ID: r.ID, Title: r.Title, Source: r.Source, Mentions: r.Mentions, Weight: r.Weight, Status: r.Status,
			Answers: answerBase(r.PromptID, answers), Quotes: ev.Quotes, Ideas: []ProblemIdea{}, Experiments: []ProblemExperiment{}}
		if ev.Ranking != nil {
			p.Most, p.Least, p.TimesShown = &ev.Ranking.Most, &ev.Ranking.Least, &ev.Ranking.TimesShown
		}
		if r.PromptID != nil {
			p.Asked = asked[*r.PromptID]
		}
		if r.Description != nil {
			p.Description = *r.Description
		}
		if v, ok := ideasByProblem[r.ID]; ok {
			p.Ideas = v
		}
		if v, ok := expsByProblem[r.ID]; ok {
			p.Experiments = v
		}
		if r.Flow == nil || *r.Flow == "" {
			if len(general) < maxProblems {
				general = append(general, p)
			}
			continue
		}
		if perFlow[*r.Flow] >= maxProblems {
			continue
		}
		perFlow[*r.Flow]++
		problems = append(problems, flowProblem{Problem: p, flow: *r.Flow})
	}
	return problems, general, values
}

func answerBase(promptID *uint64, answers map[uint64]int64) int64 {
	if promptID == nil {
		return 0
	}
	return answers[*promptID]
}

// problemEvidence is the part of ux_problems.evidence the tab reads: the quotes the batch checked
// against the answers, and the ranking figures the tick stores beside the weight.
type problemEvidence struct {
	Quotes  []Quote `json:"quotes"`
	Ranking *struct {
		Most       int64 `json:"most"`
		Least      int64 `json:"least"`
		TimesShown int64 `json:"times_shown"`
	} `json:"ranking"`
}

// parseProblemEvidence reads the evidence column, which may be empty or an older shape. Quotes
// is never nil so the JSON carries [] rather than null.
func parseProblemEvidence(raw *string) problemEvidence {
	ev := problemEvidence{}
	if raw != nil && *raw != "" {
		_ = json.Unmarshal([]byte(*raw), &ev)
	}
	if ev.Quotes == nil {
		ev.Quotes = []Quote{}
	}
	return ev
}

// promptBases returns, for each open question that problems came from, how many answers it was
// analysed over and how many members ranked its problems in the follow-up survey (a best or
// worst pick, "none of these" or "don't care"; "don't understand" is not a ranking). These are
// the bases the tab shows next to mentions and weights.
//
// The analysed count is the one stored in the prompt's settings when it was analysed, because the
// themes were drawn from those answers and not from ones that arrived later. A prompt with none
// stored falls back to its written answers (a skip, "nothing got in my way" and "don't
// understand" are not words).
func promptBases(promptIDs []uint64) (answers map[uint64]int64, asked map[uint64]int64) {
	answers = map[uint64]int64{}
	asked = map[uint64]int64{}
	if len(promptIDs) == 0 {
		return
	}
	db := database.DBConn

	var stored []struct {
		ID uint64 `gorm:"column:id"`
		N  *int64 `gorm:"column:n"`
	}
	db.Table("ux_prompts").
		Select("id, CAST(JSON_EXTRACT(settings, '$.analysed_answers') AS SIGNED) AS n").
		Where("id IN ?", promptIDs).Scan(&stored)
	missing := []uint64{}
	for _, r := range stored {
		if r.N != nil {
			answers[r.ID] = *r.N
		} else {
			missing = append(missing, r.ID)
		}
	}
	if len(missing) > 0 {
		var a []struct {
			PromptID uint64 `gorm:"column:prompt_id"`
			N        int64  `gorm:"column:n"`
		}
		db.Table("ux_prompt_responses").Select("prompt_id, COUNT(*) AS n").
			Where("prompt_id IN ?", missing).
			Where("JSON_TYPE(JSON_EXTRACT(answer, '$.text')) = 'STRING' AND TRIM(JSON_UNQUOTE(JSON_EXTRACT(answer, '$.text'))) <> ''").
			Group("prompt_id").Scan(&a)
		for _, r := range a {
			answers[r.PromptID] = r.N
		}
	}

	var f []struct {
		FollowupOf uint64 `gorm:"column:followup_of"`
		N          int64  `gorm:"column:n"`
	}
	followupOf := "CAST(JSON_UNQUOTE(JSON_EXTRACT(p.settings, '$.followup_of')) AS UNSIGNED)"
	db.Table("ux_prompts p").
		Select(followupOf+" AS followup_of, COUNT(r.id) AS n").
		Joins("INNER JOIN ux_prompt_responses r ON r.prompt_id = p.id").
		Where("p.kind = 'survey'").
		Where(followupOf+" IN ?", promptIDs).
		Where("(JSON_EXTRACT(r.answer, '$.best') IS NOT NULL OR JSON_EXTRACT(r.answer, '$.worst') IS NOT NULL" +
			" OR JSON_UNQUOTE(JSON_EXTRACT(r.answer, '$.choice')) IN ('dont_care', 'none'))").
		Group("followup_of").Scan(&f)
	for _, r := range f {
		asked[r.FollowupOf] += r.N
	}
	return
}

// latestReads returns each experiment's most recent plain-English read in one query.
func latestReads(ids []uint64) map[uint64]*ExperimentRead {
	out := map[uint64]*ExperimentRead{}
	if len(ids) == 0 {
		return out
	}
	var rows []struct {
		ID           uint64    `gorm:"column:id"`
		ExperimentID uint64    `gorm:"column:experiment_id"`
		ReadAt       time.Time `gorm:"column:read_at"`
		Hours        float64   `gorm:"column:hours"`
		Summary      string    `gorm:"column:summary"`
	}
	database.DBConn.Table("ux_experiment_reads r").
		Select("r.id, r.experiment_id, r.read_at, r.hours, r.summary").
		Joins("INNER JOIN (SELECT experiment_id, MAX(read_at) AS latest FROM ux_experiment_reads WHERE experiment_id IN ? GROUP BY experiment_id) x ON x.experiment_id = r.experiment_id AND x.latest = r.read_at", ids).
		Order("r.id ASC").Scan(&rows)
	// Two reads in the same second: the later row wins, because the rows arrive in id order.
	for _, r := range rows {
		out[r.ExperimentID] = &ExperimentRead{ReadAt: r.ReadAt.UTC().Format(timeFormat), Hours: r.Hours, Summary: r.Summary}
	}
	return out
}

// answerShape is every key an answer can carry. Open text is deliberately not here: the overview
// counts answers and never reads what they said.
type answerShape struct {
	Choice  string `json:"choice"`
	Best    string `json:"best"`
	Worst   string `json:"worst"`
	Skipped bool   `json:"skipped"`
	// Shown is the option keys the member saw when they answered.
	Shown []string `json:"shown"`
}

// reactionsFor tallies each experiment's prompt answers, {"choice": "better"|"same"|"worse"},
// in one query. Every experiment gets a Reaction, empty if nobody has answered. A skip is not an
// answer and is left out.
func reactionsFor(ids []uint64) map[uint64]*Reaction {
	out := map[uint64]*Reaction{}
	for _, id := range ids {
		out[id] = &Reaction{Comments: []string{}}
	}
	if len(ids) == 0 {
		return out
	}
	var rows []struct {
		ExperimentID uint64  `gorm:"column:experiment_id"`
		Answer       string  `gorm:"column:answer"`
		Comment      *string `gorm:"column:comment"`
	}
	database.DBConn.Table("ux_prompt_responses r").Select("p.experiment_id, r.answer, r.comment").
		Joins("INNER JOIN ux_prompts p ON p.id = r.prompt_id").
		Where("p.experiment_id IN ?", ids).Order("r.timestamp DESC").Scan(&rows)
	for _, r := range rows {
		var a answerShape
		_ = json.Unmarshal([]byte(r.Answer), &a)
		if a.Skipped {
			continue
		}
		red := out[r.ExperimentID]
		switch a.Choice {
		case "better":
			red.Better++
		case "worse":
			red.Worse++
		case "dont_care":
			red.DontCare++
		case "dont_understand":
			red.DontUnderstand++
		default:
			continue
		}
		red.Total++
		if r.Comment != nil && strings.TrimSpace(*r.Comment) != "" && len(red.Comments) < maxComments {
			red.Comments = append(red.Comments, clip(*r.Comment, 300))
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

// surveyTallies counts, for every survey, awareness and open prompt, what members said per option:
// {"best": "<key>", "worst": "<key>"} or {"choice": "<key>"}, with the escape answers apart and
// skips left out of every figure but their own count. Three queries however many prompts.
func surveyTallies() []Survey {
	db := database.DBConn
	var prompts []struct {
		ID       uint64  `gorm:"column:id"`
		Kind     string  `gorm:"column:kind"`
		Flow     *string `gorm:"column:flow"`
		Question string  `gorm:"column:question"`
		Options  string  `gorm:"column:options"`
		Status   string  `gorm:"column:status"`
		Settings *string `gorm:"column:settings"`
	}
	db.Table("ux_prompts").Select("id, kind, flow, question, options, status, settings").Where("kind IN ('survey', 'awareness', 'open')").Order("id DESC").Limit(maxSurveys).Scan(&prompts)
	out := []Survey{}
	if len(prompts) == 0 {
		return out
	}
	promptIDs := make([]uint64, 0, len(prompts))
	for _, p := range prompts {
		promptIDs = append(promptIDs, p.ID)
	}

	// JSON_REMOVE drops the free text on the way out: this overview counts what was said and
	// never carries it.
	var responses []struct {
		PromptID uint64  `gorm:"column:prompt_id"`
		Answer   string  `gorm:"column:answer"`
		Comment  *string `gorm:"column:comment"`
	}
	db.Table("ux_prompt_responses").Select("prompt_id, JSON_REMOVE(answer, '$.text') AS answer, comment").
		Where("prompt_id IN ?", promptIDs).Order("timestamp DESC").Scan(&responses)
	byPrompt := map[uint64][]int{}
	for i, r := range responses {
		byPrompt[r.PromptID] = append(byPrompt[r.PromptID], i)
	}

	// A prompt counts as analysed once its answers have produced problems.
	var analysedIDs []uint64
	db.Table("ux_problems").Where("prompt_id IN ?", promptIDs).Distinct().Pluck("prompt_id", &analysedIDs)
	analysed := map[uint64]bool{}
	for _, id := range analysedIDs {
		analysed[id] = true
	}

	for _, p := range prompts {
		var options []struct {
			Key   string `json:"key"`
			Label string `json:"label"`
		}
		_ = json.Unmarshal([]byte(p.Options), &options)
		idx := map[string]int{}
		s := Survey{PromptID: p.ID, Kind: p.Kind, Question: p.Question, Status: p.Status, Tallies: []SurveyTally{}, Comments: []string{}, Analysed: analysed[p.ID]}
		if p.Flow != nil {
			s.Flow = *p.Flow
		}
		if p.Settings != nil {
			var settings struct {
				Decoy bool `json:"decoy"`
			}
			if json.Unmarshal([]byte(*p.Settings), &settings) == nil {
				s.Decoy = p.Kind == "awareness" && settings.Decoy
			}
		}
		if p.Kind != "open" {
			for _, o := range options {
				idx[o.Key] = len(s.Tallies)
				s.Tallies = append(s.Tallies, SurveyTally{Key: o.Key, Label: o.Label})
			}
		}
		for _, i := range byPrompt[p.ID] {
			r := responses[i]
			var a answerShape
			_ = json.Unmarshal([]byte(r.Answer), &a)
			if a.Skipped {
				s.Skipped++
				continue
			}
			if j, ok := idx[a.Best]; ok {
				s.Tallies[j].Best++
				s.Tallies[j].Most++
			}
			if j, ok := idx[a.Worst]; ok {
				s.Tallies[j].Worst++
				s.Tallies[j].Least++
			}
			for _, k := range a.Shown {
				if j, ok := idx[k]; ok {
					s.Tallies[j].TimesShown++
				}
			}
			if p.Kind == "awareness" {
				switch a.Choice {
				case "yes":
					s.Yes++
				case "no":
					s.No++
				case "not_sure":
					s.NotSure++
				}
			}
			switch a.Choice {
			case "dont_care":
				s.DontCare++
			case "nothing":
				s.Nothing++
			case "none":
				s.None++
			case "dont_understand":
				s.DontUnderstand++
				// An awareness question lists "don't understand" as one of its own options, so
				// its row has to carry the figure too or the table shows 0 beside a note that
				// says otherwise.
				if j, ok := idx[a.Choice]; ok {
					s.Tallies[j].Count++
				}
			default:
				if j, ok := idx[a.Choice]; ok {
					s.Tallies[j].Count++
				}
			}
			s.Responses++
			if p.Kind != "open" && r.Comment != nil && strings.TrimSpace(*r.Comment) != "" && len(s.Comments) < maxComments {
				s.Comments = append(s.Comments, clip(*r.Comment, 300))
			}
		}
		if answered := s.Yes + s.No + s.NotSure; answered > 0 {
			// The right answer is yes for something Freegle does, no for a decoy.
			right := s.Yes
			if s.Decoy {
				right = s.No
			}
			s.RightShare = share(right, answered)
		}
		out = append(out, s)
	}
	return out
}

// share is part over whole to three places, 0 for an empty whole.
func share(part, whole int64) float64 {
	if whole <= 0 {
		return 0
	}
	return math.Round(float64(part)/float64(whole)*1000) / 1000
}

// ---- writes ----

type CreateExperimentRequest struct {
	Flow       string `json:"flow"`
	Name       string `json:"name"`
	Title      string `json:"title"`
	Hypothesis string `json:"hypothesis"`
	Change     string `json:"change"`
	Expected   string `json:"expected"`
	// Arms maps an arm name to its [from, to] slice of the 0 to 99 bucket. A slice, not an array,
	// so a range with three numbers or one is refused instead of being cut or padded to two.
	Arms         map[string][]int `json:"arms"`
	PrURL        string           `json:"pr_url"`
	ImageControl string           `json:"image_control"`
	ImageVariant string           `json:"image_variant"`
	IdeaID       uint64           `json:"idea_id"`
	// Design is optional: touches (the flows the change can affect, for the guardrails) and
	// expected_points (the effect the test is sized for).
	Design *CreateExperimentDesign `json:"design"`
}

type CreateExperimentDesign struct {
	Touches        []string `json:"touches"`
	ExpectedPoints *float64 `json:"expected_points"`
}

// validArms checks arms the way the batch does: names, ranges inside 0..99, no overlap, a control
// and at least one variant. Returns the arms as JSON.
func validArms(arms map[string][]int) (string, string) {
	if len(arms) == 0 {
		return `{"control":[0,49],"b":[50,99]}`, ""
	}
	covered := [100]bool{}
	for name, r := range arms {
		if !armNameRe.MatchString(name) {
			return "", "arm names are letters, digits and _"
		}
		if len(r) != 2 {
			return "", "arm " + name + " must be a [from, to] range of exactly two numbers"
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

// validDesign checks the optional design and returns it as JSON, or "" for none.
func validDesign(d *CreateExperimentDesign) (string, string) {
	if d == nil {
		return "", ""
	}
	out := map[string]interface{}{}
	if len(d.Touches) > 0 {
		seen := map[string]bool{}
		touches := []string{}
		for _, f := range d.Touches {
			if !flows[f] {
				return "", "touches must be flows: give, ask, reply, login, chat_promise, chat_outcome, settings"
			}
			if !seen[f] {
				seen[f] = true
				touches = append(touches, f)
			}
		}
		out["touches"] = touches
	}
	if d.ExpectedPoints != nil {
		if *d.ExpectedPoints < 0.1 || *d.ExpectedPoints > 50 {
			return "", "expected_points must be between 0.1 and 50"
		}
		out["expected_points"] = *d.ExpectedPoints
	}
	if len(out) == 0 {
		return "", ""
	}
	b, _ := json.Marshal(out)
	return string(b), ""
}

// isDuplicateKey is true for MySQL's duplicate entry error (1062), which gorm wraps.
func isDuplicateKey(err error) bool {
	var me *mysql.MySQLError
	return errors.As(err, &me) && me.Number == 1062
}

// CreateExperiment adds a draft. Support/Admin only.
//
// When it is made from an idea, the idea must be open and in the same flow; it is then marked
// scheduled in the same transaction, so two drafts cannot claim one idea and a finished or
// running idea cannot be pulled back into the open list.
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
	if !experimentNameRe.MatchString(req.Name) || reservedNames[req.Name] {
		return fiber.NewError(fiber.StatusBadRequest, "name must be 1 to 64 letters, digits, _ or -, and not a reserved word")
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
	design, msg := validDesign(req.Design)
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
	if design != "" {
		row["design"] = design
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

	var id uint64
	err := db.Transaction(func(tx *gorm.DB) error {
		if req.IdeaID > 0 {
			var idea struct {
				Flow   string `gorm:"column:flow"`
				Status string `gorm:"column:status"`
			}
			res := tx.Table("ux_ideas").Select("flow, status").Where("id = ?", req.IdeaID).Scan(&idea)
			if res.RowsAffected == 0 {
				return fiber.NewError(fiber.StatusBadRequest, "No such idea")
			}
			if idea.Flow != req.Flow {
				return fiber.NewError(fiber.StatusBadRequest, "That idea belongs to a different flow")
			}
		}
		if err := tx.Table("ux_experiments").Create(row).Error; err != nil {
			return err
		}
		tx.Table("ux_experiments").Select("id").Where("name = ?", req.Name).Scan(&id)
		if req.IdeaID > 0 {
			// Conditional on the idea still being open: if another draft or the loop got there
			// first, nothing changes and this one is refused.
			res := tx.Table("ux_ideas").Where("id = ? AND status = 'open' AND flow = ?", req.IdeaID, req.Flow).
				Updates(map[string]interface{}{"status": "scheduled", "experiment_id": id, "updated_at": now})
			if res.Error != nil {
				return res.Error
			}
			if res.RowsAffected == 0 {
				return fiber.NewError(fiber.StatusConflict, "That idea is not open: it is already scheduled, running or finished")
			}
		}
		return nil
	})
	if err != nil {
		var fe *fiber.Error
		if errors.As(err, &fe) {
			return fe
		}
		if isDuplicateKey(err) {
			return fiber.NewError(fiber.StatusConflict, "An experiment with that name already exists")
		}
		return fiber.NewError(fiber.StatusInternalServerError, "Could not create the experiment")
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
