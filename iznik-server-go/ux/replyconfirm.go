package ux

import (
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
)

// ---- reply confirmation ----
//
// How repliers answer the question Freegle asks before passing on a reply to an offer that
// looks damaged or needs a car (chat/replyconfirm.go). Read straight from chat_prompts rather
// than from a rollup: there is one row per question, and it already holds the answer and how it
// was given.

type ReplyConfirmRow struct {
	// Reason is why it was asked: damaged, car or both.
	Reason string `json:"reason"`
	Asked  int64  `json:"asked"`
	// YesButton tapped "Yes, send my reply"; YesMessage wrote again instead, which counts as yes.
	YesButton  int64 `json:"yes_button"`
	YesMessage int64 `json:"yes_message"`
	No         int64 `json:"no"`
	// NoAnswer expired unanswered, so the reply was dropped; Waiting can still be answered.
	NoAnswer int64 `json:"no_answer"`
	Waiting  int64 `json:"waiting"`
}

type ReplyConfirmPeriod struct {
	Reasons []ReplyConfirmRow `json:"reasons"`
}

type ReplyConfirmResponse struct {
	Start    string             `json:"start"`
	End      string             `json:"end"`
	Reasons  []ReplyConfirmRow  `json:"reasons"`
	Previous ReplyConfirmPeriod `json:"previous"`
}

func replyConfirmRows(p period) ([]ReplyConfirmRow, error) {
	rows := []ReplyConfirmRow{}
	err := database.DBConn.Table("chat_prompts").
		Select("COALESCE(reason, 'unknown') AS reason, COUNT(*) AS asked, "+
			"CAST(SUM(answer = 'yes' AND answered_via = 'button') AS SIGNED) AS yes_button, "+
			"CAST(SUM(answer = 'yes' AND answered_via = 'message') AS SIGNED) AS yes_message, "+
			"CAST(SUM(answer = 'no') AS SIGNED) AS no, "+
			"CAST(SUM(answered_at IS NULL AND expires_at < NOW()) AS SIGNED) AS no_answer, "+
			"CAST(SUM(answered_at IS NULL AND (expires_at IS NULL OR expires_at >= NOW())) AS SIGNED) AS waiting").
		Where("kind = ? AND created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)",
			utils.PROMPT_KIND_CONFIRM_REPLY, p.startStr(), p.endStr()).
		Group("reason").Order("reason").Scan(&rows).Error
	return rows, err
}

// ReplyConfirm serves GET /modtools/ux/replyconfirm.
var ReplyConfirm = serve("replyconfirm", func(r request) (interface{}, error) {
	cur, err := replyConfirmRows(r.cur)
	if err != nil {
		return nil, err
	}
	prev, err := replyConfirmRows(r.prev)
	if err != nil {
		return nil, err
	}
	return ReplyConfirmResponse{
		Start:    r.cur.startStr(),
		End:      r.cur.endStr(),
		Reasons:  cur,
		Previous: ReplyConfirmPeriod{Reasons: prev},
	}, nil
})
