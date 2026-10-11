package automod

import (
	"strconv"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
)

// EndCount is how often the chart ended at one node, and how often a moderator then did the
// opposite: approved a post the chart held, or rejected, deleted or held one it approved -
// on the community the chart decided for (ModDisagreed), or, for an approve, on another
// community the post reached (NeighbourDisagreed: the post got past the trial community and
// a neighbouring community's moderator, or a members' report, pulled it up there).
type EndCount struct {
	End                string `json:"end" gorm:"column:end_node"`
	Verdict            string `json:"verdict" gorm:"column:verdict"`
	Count              int64  `json:"count" gorm:"column:count"`
	ModDisagreed       int64  `json:"modDisagreed" gorm:"column:mod_disagreed"`
	NeighbourDisagreed int64  `json:"neighbourDisagreed" gorm:"column:neighbour_disagreed"`
}

// FeedbackCount is how many times moderators marked one node's answer as wrong.
type FeedbackCount struct {
	Node  string `json:"node" gorm:"column:node"`
	Count int64  `json:"count" gorm:"column:count"`
}

// ModeCount is how many decisions were recorded in one mode.
type ModeCount struct {
	Count int64 `json:"count"`
}

// GroupCount is one community's decisions over the window, so a community can be moved
// from shadow to approve-only on its own numbers. Decided excludes the rows that are not a
// decision about the post (UNAVAILABLE, GROUP_MODERATES_ALL) and is the denominator for
// the disagreement counts; Late is how many decisions landed after the post's auto-approve
// wait had already run out (so the countdown shown ran from the decision, not arrival).
type GroupCount struct {
	Groupid            uint64 `json:"groupid" gorm:"column:groupid"`
	Nameshort          string `json:"nameshort" gorm:"column:nameshort"`
	Decisions          int64  `json:"decisions" gorm:"column:decisions"`
	Decided            int64  `json:"decided" gorm:"column:decided"`
	Approves           int64  `json:"approves" gorm:"column:approves"`
	Holds              int64  `json:"holds" gorm:"column:holds"`
	Unavailable        int64  `json:"unavailable" gorm:"column:unavailable"`
	ModDisagreed       int64  `json:"modDisagreed" gorm:"column:mod_disagreed"`
	NeighbourDisagreed int64  `json:"neighbourDisagreed" gorm:"column:neighbour_disagreed"`
	Late               int64  `json:"late" gorm:"column:late"`
}

// Latency is how long the chart took to decide after the content check had run: the time
// a post sat in Pending with no decision and so no countdown. Seconds.
type Latency struct {
	AvgSeconds float64 `json:"avgSeconds" gorm:"column:avg_seconds"`
	MaxSeconds float64 `json:"maxSeconds" gorm:"column:max_seconds"`
}

// VersionCount is the same breakdown per chart version, so a chart or model change shows as
// a step in the disagreement rate rather than being averaged into the window.
type VersionCount struct {
	Version            string `json:"version" gorm:"column:chart_version"`
	Decisions          int64  `json:"decisions" gorm:"column:decisions"`
	Decided            int64  `json:"decided" gorm:"column:decided"`
	Approves           int64  `json:"approves" gorm:"column:approves"`
	Holds              int64  `json:"holds" gorm:"column:holds"`
	Unavailable        int64  `json:"unavailable" gorm:"column:unavailable"`
	ModDisagreed       int64  `json:"modDisagreed" gorm:"column:mod_disagreed"`
	NeighbourDisagreed int64  `json:"neighbourDisagreed" gorm:"column:neighbour_disagreed"`
	Late               int64  `json:"late" gorm:"column:late"`
}

// DayCount is the same breakdown per day of decision, oldest first: the trend line, where a
// backlog, an outage or a regression shows as a day that looks unlike its neighbours.
type DayCount struct {
	Day                string `json:"day" gorm:"column:day"`
	Decisions          int64  `json:"decisions" gorm:"column:decisions"`
	Decided            int64  `json:"decided" gorm:"column:decided"`
	Approves           int64  `json:"approves" gorm:"column:approves"`
	Holds              int64  `json:"holds" gorm:"column:holds"`
	Unavailable        int64  `json:"unavailable" gorm:"column:unavailable"`
	ModDisagreed       int64  `json:"modDisagreed" gorm:"column:mod_disagreed"`
	NeighbourDisagreed int64  `json:"neighbourDisagreed" gorm:"column:neighbour_disagreed"`
	Late               int64  `json:"late" gorm:"column:late"`
}

// AgreementResponse is the SysAdmin automated review report.
type AgreementResponse struct {
	Days        int             `json:"days"`
	Groupid     uint64          `json:"groupid,omitempty"`
	Total       int64           `json:"total"`
	Unavailable int64           `json:"unavailable"`
	Late        int64           `json:"late"`
	Latency     Latency         `json:"latency"`
	ByEnd       []EndCount      `json:"byEnd"`
	ByGroup     []GroupCount    `json:"byGroup"`
	ByVersion   []VersionCount  `json:"byVersion"`
	ByDay       []DayCount      `json:"byDay"`
	Feedback    []FeedbackCount `json:"feedback"`
	Shadow      ModeCount       `json:"shadow"`
	Approve     ModeCount       `json:"approve"`
}

// breakdownSQL is the per-row aggregate every breakdown (community, chart version, day)
// shares, so they can never drift apart.
func breakdownSQL() string {
	return "COUNT(*) AS decisions, " +
		"SUM(" + decidedSQL + ") AS decided, " +
		"SUM(" + decidedSQL + " AND ma.verdict = 'approve') AS approves, " +
		"SUM(" + decidedSQL + " AND ma.verdict = 'hold') AS holds, " +
		"SUM(ma.end_node = 'UNAVAILABLE') AS unavailable, " +
		modDisagreedSQL + ", " + neighbourDisagreedSQL + ", " +
		"SUM(" + lateSQL() + ") AS late"
}

// undecidedEnds are the end nodes that are not a verdict on the post itself: the service
// could not answer, or the community has moderators check everything. They stay in the
// per-end rows but are left out of every disagreement count and denominator.
const undecidedEnds = "('UNAVAILABLE', 'GROUP_MODERATES_ALL')"

// decidedSQL is 1 for a row that is a real decision.
const decidedSQL = "ma.end_node NOT IN " + undecidedEnds

// modDisagreedSQL: a hold is contradicted by a human approval of that copy; an approve by a
// moderator's Rejected, Deleted or Hold log on that copy after the decision.
const modDisagreedSQL = `SUM(CASE
	WHEN ` + decidedSQL + ` AND ma.verdict = 'hold' THEN mg.approvedby IS NOT NULL
	WHEN ` + decidedSQL + ` THEN EXISTS (SELECT 1 FROM logs l
		WHERE l.msgid = ma.msgid AND l.groupid = ma.groupid AND l.type = 'Message'
		AND l.subtype IN ('Rejected', 'Deleted', 'Hold')
		AND l.byuser IS NOT NULL AND l.timestamp >= ma.created)
	ELSE 0
	END) AS mod_disagreed`

// neighbourDisagreedSQL: an approve contradicted on ANOTHER community the post is on - a
// receiving community's moderator rejecting, deleting or holding the rippled copy, or a
// members' report quorum pulling it back there. The signal that a bad post got past the
// trial community.
const neighbourDisagreedSQL = `SUM(CASE
	WHEN ` + decidedSQL + ` AND ma.verdict = 'approve' THEN EXISTS (SELECT 1 FROM logs l
		WHERE l.msgid = ma.msgid AND l.groupid IS NOT NULL AND l.groupid <> ma.groupid
		AND l.type = 'Message' AND l.subtype IN ('Rejected', 'Deleted', 'Hold')
		AND l.byuser IS NOT NULL AND l.timestamp >= ma.created)
	ELSE 0
	END) AS neighbour_disagreed`

// lateSQL is 1 when the decision landed after the auto-approve wait from arrival had run
// out, so the post had no countdown for the whole of its wait.
func lateSQL() string {
	return "(mg.arrival IS NOT NULL AND ma.created > mg.arrival + INTERVAL " + strconv.Itoa(utils.AutoapproveDelayMinutes()) + " MINUTE)"
}

// Agreement handles GET /modtools/automod/agreement: how the chart's decisions over the last
// N days compare with what moderators then did, by end node and by community, plus "this
// step is wrong" counts by node, how long decisions took, and how many came too late for a
// countdown. Support or Admin only, like the moderation stats it sits beside.
//
// @Summary Automated review agreement with moderators
// @Tags automod
// @Produce json
// @Param days query int false "Window in days (1-365, default 30)"
// @Param groupid query int false "Only this community's decisions (default all)"
// @Success 200 {object} AgreementResponse
// @Failure 401 {object} map[string]interface{}
// @Failure 403 {object} map[string]interface{}
// @Router /modtools/automod/agreement [get]
func Agreement(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}
	if !user.IsAdminOrSupport(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Support or Admin role required")
	}

	days, err := strconv.Atoi(c.Query("days", "30"))
	if err != nil || days < 1 || days > 365 {
		days = 30
	}
	groupid, _ := strconv.ParseUint(c.Query("groupid", "0"), 10, 64)

	db := database.DBConn
	since := gorm.Expr("NOW() - INTERVAL ? DAY", days)
	resp := AgreementResponse{Days: days, Groupid: groupid, ByEnd: []EndCount{}, ByGroup: []GroupCount{}, ByVersion: []VersionCount{}, ByDay: []DayCount{}, Feedback: []FeedbackCount{}}

	// Every query below starts from the decisions in the window, optionally one community's.
	decisions := func() *gorm.DB {
		q := db.Table("messages_automod ma").Where("ma.created >= ?", since)
		if groupid > 0 {
			q = q.Where("ma.groupid = ?", groupid)
		}
		return q
	}
	withCopy := func() *gorm.DB {
		return decisions().Joins("LEFT JOIN messages_groups mg ON mg.msgid = ma.msgid AND mg.groupid = ma.groupid")
	}

	withCopy().
		Select("ma.end_node, ma.verdict, COUNT(*) AS count, " + modDisagreedSQL + ", " + neighbourDisagreedSQL).
		Group("ma.end_node, ma.verdict").
		Order("count DESC").
		Scan(&resp.ByEnd)

	withCopy().
		Joins("LEFT JOIN `groups` g ON g.id = ma.groupid").
		Select("ma.groupid, COALESCE(g.nameshort, '') AS nameshort, " + breakdownSQL()).
		Group("ma.groupid, g.nameshort").
		Order("decisions DESC").
		Scan(&resp.ByGroup)

	withCopy().
		Select("ma.chart_version, " + breakdownSQL()).
		Group("ma.chart_version").
		Order("decisions DESC").
		Scan(&resp.ByVersion)

	withCopy().
		Select("DATE(ma.created) AS day, " + breakdownSQL()).
		Group("DATE(ma.created)").
		Order("day ASC").
		Scan(&resp.ByDay)

	feedback := db.Table("messages_automod_feedback f").
		Joins("JOIN messages_automod ma ON ma.id = f.automodid").
		Where("f.created >= ?", since)
	if groupid > 0 {
		feedback = feedback.Where("ma.groupid = ?", groupid)
	}
	feedback.Select("f.node, COUNT(*) AS count").
		Group("f.node").
		Order("count DESC").
		Scan(&resp.Feedback)

	decisions().Where("ma.mode = ?", "shadow").Count(&resp.Shadow.Count)
	decisions().Where("ma.mode = ?", "approve").Count(&resp.Approve.Count)
	resp.Total = resp.Shadow.Count + resp.Approve.Count

	decisions().Where("ma.end_node = ?", "UNAVAILABLE").Count(&resp.Unavailable)
	withCopy().Where(lateSQL()).Count(&resp.Late)

	// How long the post waited between its content check and the chart's decision. Only
	// real decisions: an UNAVAILABLE row is written the moment the call fails and would
	// make the service look quicker than it was.
	withCopy().
		Where(decidedSQL + " AND mg.contentcheck_checked_at IS NOT NULL").
		Select("COALESCE(AVG(TIMESTAMPDIFF(SECOND, mg.contentcheck_checked_at, ma.created)), 0) AS avg_seconds, " +
			"COALESCE(MAX(TIMESTAMPDIFF(SECOND, mg.contentcheck_checked_at, ma.created)), 0) AS max_seconds").
		Scan(&resp.Latency)

	return c.JSON(resp)
}
