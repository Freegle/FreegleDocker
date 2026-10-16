package ux

import (
	"encoding/json"
	"strconv"
	"sync"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/gofiber/fiber/v2"
)

// The ideas route: what the idea generator has proposed for each funnel, ranked, with the experiment
// each idea became and the member problem it answers. The rows are written by the Laravel batch
// (UxIdeasService), which also moves an idea's status along with its experiment every hour. This
// route only reads. Changing an idea is the job of `php artisan ux:idea`.
//
// The experiment and the problem are joined live from their own tables, read only, so the answer is
// right even when the hourly sync has not caught up. Either table may not exist: the tables belong to
// the experiment engine and the member surveys, which are separate changes. A missing table gives a
// null join and never an error.

const (
	// defaultIdeas and maxIdeas are the ideas returned when limit is not sent, and the most that
	// can be asked for.
	defaultIdeas = 200
	maxIdeas     = 500

	// tableCheckTTL is how long the answer to "does this table exist" is kept.
	tableCheckTTL = 60 * time.Second
)

// ideaStatuses are the states an idea is in, in the order a person meets them. The batch moves an
// idea along: waiting, queued as a draft experiment, being tried, tried, or set aside.
var ideaStatuses = []string{"open", "scheduled", "running", "done", "rejected"}

// ideaTypes are the two kinds of idea. A fix has a right answer and ships as an ordinary pull
// request; an experiment needs a test.
var ideaTypes = map[string]bool{"fix": true, "experiment": true}

// ideaFlowOrder is the funnels in the order members meet them, so the per-funnel summary reads the
// same way every time.
var ideaFlowOrder = []string{"give", "ask", "reply", "chat_promise", "chat_outcome", "login", "settings"}

// IdeaExperiment is the experiment an idea became, as it is now.
type IdeaExperiment struct {
	ID     uint64 `json:"id"`
	Name   string `json:"name"`
	Title  string `json:"title"`
	Status string `json:"status"`
}

// IdeaProblem is the member problem an idea answers.
type IdeaProblem struct {
	ID    uint64 `json:"id"`
	Title string `json:"title"`
}

// Idea is one row of the backlog. Evidence holds the generator's category, expected points,
// confidence, locality, risk, files, the change it wrote and the problem it found. ProblemID is set
// only when the idea is tied to a problem members raised; ProblemTitle is that problem's title, or
// else the generator's own title for the problem it found.
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
	Source       string          `json:"source"`
	ProblemID    *uint64         `json:"problem_id"`
	ProblemTitle *string         `json:"problem_title"`
	Problem      *IdeaProblem    `json:"problem"`
	Attempts     int64           `json:"attempts"`
	Experiment   *IdeaExperiment `json:"experiment"`
	CreatedAt    *string         `json:"created_at"`
}

// IdeaBest is the best waiting idea of a funnel.
type IdeaBest struct {
	ID    uint64  `json:"id"`
	Title string  `json:"title"`
	Score float64 `json:"score"`
}

// IdeaFlow is one funnel's summary: how many ideas are waiting and which is best.
type IdeaFlow struct {
	Flow string    `json:"flow"`
	Open int64     `json:"open"`
	Best *IdeaBest `json:"best"`
}

// IdeasResponse is the whole answer. Counts and Flows cover every idea whatever the filters, so a
// caller can show the totals and narrow the list; Ideas is the filtered list.
type IdeasResponse struct {
	Counts map[string]int64 `json:"counts"`
	Flows  []IdeaFlow       `json:"flows"`
	Ideas  []Idea           `json:"ideas"`
}

type ideaRow struct {
	ID           uint64     `gorm:"column:id"`
	Flow         string     `gorm:"column:flow"`
	Title        string     `gorm:"column:title"`
	Rationale    string     `gorm:"column:rationale"`
	Expected     string     `gorm:"column:expected"`
	Evidence     *string    `gorm:"column:evidence"`
	Score        float64    `gorm:"column:score"`
	Status       string     `gorm:"column:status"`
	Type         string     `gorm:"column:type"`
	Source       string     `gorm:"column:source"`
	ProblemID    *uint64    `gorm:"column:problem_id"`
	ProblemTitle *string    `gorm:"column:problem_title"`
	ExperimentID *uint64    `gorm:"column:experiment_id"`
	Attempts     int64      `gorm:"column:attempts"`
	CreatedAt    *time.Time `gorm:"column:created_at"`
}

// ---- does a table exist ----

type tableAnswer struct {
	exists  bool
	expires time.Time
}

var (
	tableMu    sync.Mutex
	tableSeen  = map[string]tableAnswer{}
	tableCheck = databaseHasTable
)

// databaseHasTable asks the database whether a table is there.
func databaseHasTable(table string) bool {
	var names []string
	database.DBConn.Table("information_schema.tables").Select("table_name").
		Where("table_schema = DATABASE() AND table_name = ?", table).Scan(&names)
	return len(names) > 0
}

// SetTableCheck replaces the question "does this table exist" and forgets what was remembered. Tests
// use it to stand in for a part that has not been made yet; the function it returns puts the real
// question back.
func SetTableCheck(fn func(table string) bool) (restore func()) {
	tableMu.Lock()
	defer tableMu.Unlock()
	previous := tableCheck
	tableCheck = fn
	tableSeen = map[string]tableAnswer{}

	return func() {
		tableMu.Lock()
		defer tableMu.Unlock()
		tableCheck = previous
		tableSeen = map[string]tableAnswer{}
	}
}

// hasTable answers from memory for a minute. A table that comes into being is noticed within the
// minute, and one that is not there costs one question a minute, not one per request.
func hasTable(table string) bool {
	tableMu.Lock()
	if a, ok := tableSeen[table]; ok && time.Now().Before(a.expires) {
		tableMu.Unlock()
		return a.exists
	}
	check := tableCheck
	tableMu.Unlock()

	exists := check(table)

	tableMu.Lock()
	tableSeen[table] = tableAnswer{exists: exists, expires: time.Now().Add(tableCheckTTL)}
	tableMu.Unlock()

	return exists
}

// ---- the filters ----

type ideaFilter struct {
	flow      string
	status    string
	typ       string
	problemID uint64
	limit     int
}

// parseIdeaFilter reads flow, status, type, problem_id and limit. A status or type that is not one of
// the known words, a problem_id that is not a number and a limit that is not a whole number above
// zero are a 400. A limit above the most allowed is cut to it.
func parseIdeaFilter(c *fiber.Ctx) (ideaFilter, error) {
	flow, err := parseFlow(c)
	if err != nil {
		return ideaFilter{}, err
	}
	f := ideaFilter{flow: flow, limit: defaultIdeas}

	if s := c.Query("status"); s != "" {
		known := false
		for _, k := range ideaStatuses {
			known = known || k == s
		}
		if !known {
			return ideaFilter{}, fiber.NewError(fiber.StatusBadRequest, "status must be one of open, scheduled, running, done, rejected")
		}
		f.status = s
	}
	if s := c.Query("type"); s != "" {
		if !ideaTypes[s] {
			return ideaFilter{}, fiber.NewError(fiber.StatusBadRequest, "type must be fix or experiment")
		}
		f.typ = s
	}
	if s := c.Query("problem_id"); s != "" {
		n, err := strconv.ParseUint(s, 10, 64)
		if err != nil || n == 0 {
			return ideaFilter{}, fiber.NewError(fiber.StatusBadRequest, "invalid problem_id")
		}
		f.problemID = n
	}
	if s := c.Query("limit"); s != "" {
		n, err := strconv.Atoi(s)
		if err != nil || n < 1 {
			return ideaFilter{}, fiber.NewError(fiber.StatusBadRequest, "invalid limit")
		}
		if n > maxIdeas {
			n = maxIdeas
		}
		f.limit = n
	}

	return f, nil
}

// Ideas lists the backlog, best score first, with the totals by status and a summary per funnel.
// Support/Admin only. It is not cached: the answer is small, and the live join to the experiment is
// there so a person sees a start or an end at once.
func Ideas(c *fiber.Ctx) error {
	if err := requireSupport(c); err != nil {
		return err
	}
	f, err := parseIdeaFilter(c)
	if err != nil {
		return err
	}
	db := database.DBConn

	// Totals over every idea, whatever the filters.
	counts := map[string]int64{}
	for _, s := range ideaStatuses {
		counts[s] = 0
	}
	var byStatus []struct {
		Status string `gorm:"column:status"`
		N      int64  `gorm:"column:n"`
	}
	if err := db.Table("ux_ideas").Select("status, COUNT(*) AS n").Group("status").Scan(&byStatus).Error; err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Could not read ideas")
	}
	for _, s := range byStatus {
		if _, known := counts[s.Status]; known {
			counts[s.Status] = s.N
		}
	}

	// The waiting ideas, best first: how many each funnel has and which is best.
	var waiting []struct {
		ID    uint64  `gorm:"column:id"`
		Flow  string  `gorm:"column:flow"`
		Title string  `gorm:"column:title"`
		Score float64 `gorm:"column:score"`
	}
	if err := db.Table("ux_ideas").Select("id, flow, title, score").Where("status = 'open'").Order("score DESC, id ASC").Scan(&waiting).Error; err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Could not read ideas")
	}
	flows := make([]IdeaFlow, 0, len(ideaFlowOrder))
	at := map[string]int{}
	for _, name := range ideaFlowOrder {
		at[name] = len(flows)
		flows = append(flows, IdeaFlow{Flow: name})
	}
	for _, w := range waiting {
		i, ok := at[w.Flow]
		if !ok {
			continue
		}
		flows[i].Open++
		if flows[i].Best == nil {
			flows[i].Best = &IdeaBest{ID: w.ID, Title: w.Title, Score: w.Score}
		}
	}

	// The list.
	q := db.Table("ux_ideas").
		Select("id, flow, title, rationale, expected, evidence, score, status, type, source, problem_id, problem_title, experiment_id, attempts, created_at").
		Order("score DESC, id ASC").Limit(f.limit)
	if f.flow != "" {
		q = q.Where("flow = ?", f.flow)
	}
	if f.status != "" {
		q = q.Where("status = ?", f.status)
	}
	if f.typ != "" {
		q = q.Where("type = ?", f.typ)
	}
	if f.problemID > 0 {
		q = q.Where("problem_id = ?", f.problemID)
	}
	var rows []ideaRow
	if err := q.Scan(&rows).Error; err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Could not read ideas")
	}

	experiments := experimentsOf(rows)
	problems := problemsOf(rows)

	ideas := make([]Idea, 0, len(rows))
	for _, r := range rows {
		i := Idea{
			ID: r.ID, Flow: r.Flow, Title: r.Title, Rationale: r.Rationale, Expected: r.Expected, Score: r.Score,
			Status: r.Status, Type: r.Type, Source: r.Source, ProblemID: r.ProblemID, ProblemTitle: r.ProblemTitle,
			Attempts: r.Attempts, CreatedAt: tstr(r.CreatedAt),
		}
		if r.Evidence != nil && json.Valid([]byte(*r.Evidence)) {
			i.Evidence = json.RawMessage(*r.Evidence)
		}
		if r.ExperimentID != nil {
			i.Experiment = experiments[*r.ExperimentID]
		}
		if r.ProblemID != nil {
			i.Problem = problems[*r.ProblemID]
		}
		ideas = append(ideas, i)
	}

	return c.JSON(IdeasResponse{Counts: counts, Flows: flows, Ideas: ideas})
}

// experimentsOf reads the experiments the ideas point at, as they are now, in one query. Without the
// experiment table every idea has none.
func experimentsOf(rows []ideaRow) map[uint64]*IdeaExperiment {
	out := map[uint64]*IdeaExperiment{}
	ids := []uint64{}
	for _, r := range rows {
		if r.ExperimentID != nil {
			ids = append(ids, *r.ExperimentID)
		}
	}
	if len(ids) == 0 || !hasTable("ux_experiments") {
		return out
	}
	var found []IdeaExperiment
	database.DBConn.Table("ux_experiments").Select("id, name, title, status").Where("id IN ?", ids).Scan(&found)
	for i := range found {
		out[found[i].ID] = &found[i]
	}

	return out
}

// problemsOf reads the member problems the ideas are tied to, in one query. Without the problem table
// no idea has one, and the idea still carries its own problem title.
func problemsOf(rows []ideaRow) map[uint64]*IdeaProblem {
	out := map[uint64]*IdeaProblem{}
	ids := []uint64{}
	for _, r := range rows {
		if r.ProblemID != nil {
			ids = append(ids, *r.ProblemID)
		}
	}
	if len(ids) == 0 || !hasTable("ux_problems") {
		return out
	}
	var found []IdeaProblem
	database.DBConn.Table("ux_problems").Select("id, title").Where("id IN ?", ids).Scan(&found)
	for i := range found {
		out[found[i].ID] = &found[i]
	}

	return out
}
