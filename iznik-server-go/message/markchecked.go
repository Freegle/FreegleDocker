package message

import (
	"fmt"
	"log"

	"github.com/freegle/iznik-server-go/database"
	flog "github.com/freegle/iznik-server-go/log"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
)

// MarkCheckedRequest is the request body for marking auto-published posts as
// checked by a moderator (clearing them from the Check oversight queue).
type MarkCheckedRequest struct {
	Groupid uint64   `json:"groupid"`          // 0 = all of the mod's groups
	Filter  string   `json:"filter"`           // "checked" (used when no ids given)
	IDs     []uint64 `json:"ids,omitempty"`    // specific messages, else mark the whole bucket
	Reject  bool     `json:"reject,omitempty"` // true = pull the specified posts back to Pending, held, for a decision
}

// checkRejectReason is the spamreason stored on, and the Hold log text written to, every
// copy a Check-queue reject pulls back on a community other than the one acted on.
const checkRejectReason = "A moderator pulled this post back from Check for review."

// checkRejectLogText is the Hold log text on the community the moderator acted on.
const checkRejectLogText = "Pulled back from Check"

// MarkChecked records that a moderator has reviewed auto-published posts. These
// are posts that went live without a manual approval from members with no
// posting status, so there is no approvedby/heldby to key off — this sets a
// dedicated checkedat/checkedby marker that drives the Check count.
//
// @Summary Mark auto-published posts as checked by a moderator
// @Tags message
// @Accept json
// @Produce json
// @Success 200 {object} map[string]interface{}
// @Router /modtools/messages/markchecked [post]
func MarkChecked(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	var req MarkCheckedRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	db := database.DBConn

	// Resolve the groups the mod may act on.
	var groupIDs []uint64
	if req.Groupid == 0 {
		groupIDs = user.GetActiveModGroupIDs(myid)
		if len(groupIDs) == 0 {
			// Not a moderator of any group — cannot mark anything checked (D11).
			return fiber.NewError(fiber.StatusForbidden, "Not a moderator")
		}
	} else {
		if !user.IsModOfGroup(myid, req.Groupid) {
			return fiber.NewError(fiber.StatusForbidden, "Not a moderator for this group")
		}
		groupIDs = []uint64{req.Groupid}
	}

	// The Check queue exists only on communities in the post-moderation trial, and so do
	// its actions: outside the trial the same predicate (approvedby NULL, no posting status)
	// matches the 48-hour fallback's approvals on every community, and a stamp there would
	// count as a review in the moderation stats and lift the earned-reach hold on a post
	// nobody looked at. Mirrors the list filter in ListMessagesMT.
	groupIDs = utils.AutoapproveTrialGroups(groupIDs)
	if len(groupIDs) == 0 {
		return c.JSON(fiber.Map{"success": true, "checked": 0, "rejected": 0})
	}

	// Reject: pull the specified auto-published posts back out of the live feed for a
	// proper moderation decision. ModTools then shows each one as an ordinary Pending card,
	// held by this moderator, who approves, edits or rejects it as normal; that later action
	// writes its own log. This is Back to pending on the post's own community
	// (pullBackToPending, the same path handleBackToPending uses), so a copy a neighbouring
	// community's moderator approved by hand stays live, the unreviewed rippled copies are
	// withdrawn, needs_moderator stops the auto-approve cron re-publishing the post if the
	// hold is released, and the reach is frozen rather than killed, so a later Approve
	// brings the post back without it having to earn its reach again. Targeted only — there
	// is no bulk "reject the whole bucket" (that would be far too blunt). rippled_in = 0:
	// oversight actions apply to a post's ORIGIN row only — rippled-in copies are the origin
	// community's responsibility, never yanked directly from another group's queue.
	if req.Reject {
		if len(req.IDs) == 0 {
			return fiber.NewError(fiber.StatusBadRequest, "reject requires specific ids")
		}

		// Snapshot the rows the reject will hit, so the pull-back and the log rows apply
		// to exactly the posts actually rejected (not e.g. ids the mod lacks permission
		// for, which the WHERE filters out). approvedby IS NULL: only a copy that published
		// by itself is in the Check queue, so only one can be pulled back through it; a copy
		// a moderator approved by hand is undone through Back to pending, on purpose.
		var hit []struct {
			Msgid    uint64
			Groupid  uint64
			Fromuser uint64
		}
		db.Table("messages_groups mg").
			Select("mg.msgid, mg.groupid, m.fromuser").
			Joins("INNER JOIN messages m ON m.id = mg.msgid").
			Where("mg.msgid IN ? AND mg.groupid IN ? AND mg.collection = ? AND mg.deleted = 0 AND mg.rippled_in = 0 AND mg.approvedby IS NULL",
				req.IDs, groupIDs, utils.COLLECTION_APPROVED).
			Scan(&hit)

		acting := map[uint64][]uint64{}
		fromuser := map[uint64]uint64{}
		var order []uint64
		for _, h := range hit {
			if _, seen := acting[h.Msgid]; !seen {
				order = append(order, h.Msgid)
			}
			acting[h.Msgid] = append(acting[h.Msgid], h.Groupid)
			fromuser[h.Msgid] = h.Fromuser
		}

		var rejected int64
		for _, msgid := range order {
			groups := acting[msgid]
			pullBackToPending(db, myid, msgid, groups, checkRejectReason)

			// Check-specific: the post is no longer "checked" on the communities acted on,
			// and the earned-reach await clock is settled. A post the gate had paused stops
			// waiting for review the moment a moderator pulls it back, so the time waited
			// is banked and the stamp cleared; leaving the stamp set would keep this frozen
			// row in the SysAdmin "awaiting review now" count for ever.
			r := db.Table("messages_groups").
				Where("msgid = ? AND groupid IN ? AND collection = ?", msgid, groups, utils.COLLECTION_PENDING).
				Updates(map[string]interface{}{"checkedat": gorm.Expr("NULL"), "checkedby": gorm.Expr("NULL")})
			rejected += r.RowsAffected
			db.Table("rippling_reach").Where("msgid = ? AND awaiting_review_since IS NOT NULL", msgid).
				Updates(map[string]interface{}{
					"awaiting_review_seconds": gorm.Expr(
						"awaiting_review_seconds + IFNULL(TIMESTAMPDIFF(SECOND, awaiting_review_since, NOW()), 0)"),
					"awaiting_review_since": gorm.Expr("NULL"),
				})

			for _, gid := range groups {
				// Logged as a Hold, not a Rejected: nothing is decided yet. The moderation-stats
				// "later actioned" analytic counts it as an auto-published post a moderator had
				// to act on. If the moderator then rejects it, that Reject writes the Rejected
				// log, which is what vetoes this member's next post from auto-publishing; if they
				// approve it, the member is not penalised.
				logModAction(db, flog.LOG_TYPE_MESSAGE, flog.LOG_SUBTYPE_HOLD, gid, fromuser[msgid], myid, msgid, 0, checkRejectLogText)
				log.Printf("automod: check reject msgid=%d groupid=%d byuser=%d", msgid, gid, myid)
			}
		}

		return c.JSON(fiber.Map{"success": true, "rejected": rejected})
	}

	var rowsAffected int64
	if len(req.IDs) > 0 {
		// Mark the specified posts checked — only ones still unchecked, and only
		// on groups the mod moderates. rippled_in = 0 for the same reason as the
		// bucket update below: rippled-in copies are not part of this group's
		// oversight, and a checkedat stamped on one would corrupt the AutoModChecked
		// analytic (it measures review of posts that auto-published HERE).
		r := db.Table("messages_groups").
			Where("msgid IN ? AND groupid IN ? AND collection = ? AND deleted = 0 AND checkedat IS NULL AND rippled_in = 0",
				req.IDs, groupIDs, utils.COLLECTION_APPROVED).
			Updates(map[string]interface{}{
				"checkedat": gorm.Expr("NOW()"),
				"checkedby": myid,
			})
		rowsAffected = r.RowsAffected
	} else {
		// Mark the whole bucket checked (the "mark all as checked" action). The
		// bucket condition mirrors the Check list filter so exactly the posts a
		// mod is looking at get cleared.
		if req.Filter != "checked" {
			return fiber.NewError(fiber.StatusBadRequest, "filter must be 'checked' (D10)")
		}
		statusWhere := "mem.ourPostingStatus IS NULL"
		// keep-raw: multi-table UPDATE ... JOIN ... SET mg.col; GORM's Update cannot render a JOIN.
		r := db.Exec("UPDATE messages_groups mg "+
			"INNER JOIN messages m ON m.id = mg.msgid "+
			"INNER JOIN memberships mem ON mem.userid = m.fromuser AND mem.groupid = mg.groupid "+
			"SET mg.checkedat = NOW(), mg.checkedby = ? "+
			"WHERE mg.groupid IN ? AND mg.collection = ? AND mg.deleted = 0 "+
			"AND mg.approvedby IS NULL AND mg.rippled_in = 0 AND "+statusWhere+" "+
			fmt.Sprintf("AND mg.checkedat IS NULL AND mg.arrival >= NOW() - INTERVAL %d DAY", utils.MESSAGE_CHECK_WINDOW_DAYS),
			myid, groupIDs, utils.COLLECTION_APPROVED)
		rowsAffected = r.RowsAffected
	}

	return c.JSON(fiber.Map{"success": true, "checked": rowsAffected})
}
