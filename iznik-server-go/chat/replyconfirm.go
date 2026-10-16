package chat

import (
	"encoding/json"
	"fmt"
	stdlog "log"
	"time"

	"github.com/freegle/iznik-server-go/firstreply"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/freegle/iznik-server-go/ux"
	"gorm.io/gorm"
	"gorm.io/plugin/dbresolver"
)

// Confirming a reply before the poster gets it.
//
// eee:classify-new reads each new OFFER's photo and records whether the item looks damaged and
// roughly how big and heavy it is (messages_eee). When someone replies to an offer that looks
// damaged, or that looks like it needs a car to collect, Freegle asks them in the chat whether
// they still want it, and the reply waits for the answer. That saves the poster a reply from
// someone who would have backed out on seeing the item, or on finding it would not fit in a bag.
//
// The question is an ordinary Prompt message (see chatprompt.go), @-addressed to and asked only
// of the replier: chat_prompts.userid says who may answer it. It is written already processed
// and unsuccessful, the state every delivery path treats as "not for the other member", so
// while it waits the poster is not shown it, notified of it, or has their chat list changed by
// it. When the reply is delivered, ChatProcessService marks the question successful in the same
// step, so the poster sees the question, the answer and the reply together; after a "no" they
// see none of them.
//
// The reply is held by leaving it unprocessed: chats:process-incoming skips a message named in
// an unanswered prompt's heldchatmsgid (ChatProcessService), and an unprocessed message is only
// ever shown to its sender. "Yes" lets the next run deliver it, "no" drops it, and a prompt that
// expires unanswered is dropped by the batch processor.
//
// TrashNothing members are left out on both sides: their replies and posts travel through
// TrashNothing, where nobody would see the question.
//
// It is the experiment confirm-reply, so only repliers whose arm is ask are asked; the others
// are counted and left alone.

// replyConfirmExpiryDays is how long the question stays answerable before the reply is dropped.
const replyConfirmExpiryDays = 3

type replyConfirmEee struct {
	ID            uint64  `gorm:"column:id"`
	ItemCondition *string `gorm:"column:item_condition"`
	SizeBucket    *string `gorm:"column:size_bucket"`
	WeightBucket  *string `gorm:"column:weight_bucket"`
}

// needsACar says whether the item looks too big or heavy to carry home: the size the model
// called large, or a weight from 20kg up.
func (e replyConfirmEee) needsACar() bool {
	if e.SizeBucket != nil && *e.SizeBucket == "large" {
		return true
	}

	return e.WeightBucket != nil && (*e.WeightBucket == "20_100kg" || *e.WeightBucket == "over_100kg")
}

func (e replyConfirmEee) damaged() bool {
	return e.ItemCondition != nil && *e.ItemCondition == "damaged"
}

// replyConfirmQuestion is the wording, given which reasons apply. Empty when neither does.
// It is @-addressed to the replier: once answered the poster sees it too, and a message from
// Freegle in a chat between two people must say which of them it is talking to.
func replyConfirmQuestion(name string, damaged bool, needsCar bool) string {
	var concern string

	switch {
	case damaged && needsCar:
		concern = "from the photo, this looks like it may be damaged or not working, and big or heavy enough " +
			"that you'd need a car or van to collect it."
	case damaged:
		concern = "from the photo, this looks like it may be damaged or not working."
	case needsCar:
		concern = "from the photo, this looks big or heavy enough that you'd need a car or van to collect it."
	default:
		return ""
	}

	return "@" + name + ", before we pass your reply on: " + concern + " Do you still want it?"
}

var replyConfirmOptions = []PromptOption{
	{Value: "yes", Label: "Yes, send my reply", Variant: "primary"},
	{Value: "no", Label: "No, don't send it", Variant: "secondary"},
}

// confirmEarlierReply treats a further message from the replier as a "yes": writing again
// instead of tapping a button means they still want it, and their second message must not
// reach the poster while the first is still held.
func confirmEarlierReply(db *gorm.DB, chatid uint64, myid uint64) {
	db.Table("chat_prompts").
		Where("kind = ? AND userid = ? AND answered_at IS NULL AND chatmsgid IN (?)",
			utils.PROMPT_KIND_CONFIRM_REPLY, myid,
			db.Table("chat_messages").Select("id").Where("chatid = ?", chatid)).
		Updates(map[string]interface{}{
			"answer":       "yes",
			"answered_at":  gorm.Expr("NOW()"),
			"answered_via": "message",
		})
}

// replyConfirmStratum is why a reply is in the experiment: the exposure's stratum.
func replyConfirmStratum(damaged bool, needsCar bool) string {
	switch {
	case damaged && needsCar:
		return "both"
	case damaged:
		return "damaged"
	default:
		return "car"
	}
}

// askReplierToConfirm is the experiment confirm-reply (docs/developers/reference/ux-experiments.md,
// "Confirming a reply before it is passed on"). Half of the repliers to an eligible offer are in the arm
// "ask": their reply is held and they are asked whether they still want the item. The other half are
// "control": nothing changes for them. Both arms leave one exposure row. With no arm (switch off,
// experiment not running, entry malformed or expired) it does nothing at all.
// Best-effort: any failure leaves the reply to go through as normal.
func askReplierToConfirm(db *gorm.DB, chatid uint64, replyid uint64, msgid uint64, replier uint64) {
	arm := ux.ExperimentArm(db, replier, "confirm-reply")
	if arm == "" {
		return
	}

	freegleID := firstreply.SystemUserID(db)
	if freegleID == 0 {
		return
	}

	var post struct {
		Type             string
		Fromuser         uint64
		Deliverypossible int
	}
	db.Table("messages").
		Select("type, COALESCE(fromuser, 0) AS fromuser, deliverypossible").
		Where("id = ?", msgid).
		Scan(&post)
	if post.Type != utils.OFFER || post.Fromuser == 0 || post.Fromuser == replier {
		return
	}

	// One row per replier and post, whichever arm: a second reply to the same post is not a second
	// unit. This also stops a second question. Read on the primary, because the first reply may be
	// seconds old (.claude/rules/go-api-traps.md, "Reads and writes go to different servers"). A read
	// that fails leaves the reply alone, in both arms: carrying on would ask a repeat replier a second
	// question and write a second row (the unique key includes the reply id).
	var exposed int64
	if err := db.Clauses(dbresolver.Write).Table("ux_experiment_exposures x").
		Joins("INNER JOIN chat_messages cm ON cm.id = x.subject_id").
		Where("x.experiment = ? AND x.subject_type = ? AND x.userid = ? AND cm.refmsgid = ?",
			"confirm-reply", "reply", replier, msgid).
		Count(&exposed).Error; err != nil {
		stdlog.Printf("Could not check for an earlier confirm-reply exposure for replier %d on post %d: %v", replier, msgid, err)

		return
	}
	if exposed > 0 {
		return
	}

	var tn int64
	db.Table("users u").
		Where("u.id IN ? AND "+user.TrashNothingSQL("u"), []uint64{replier, post.Fromuser}).
		Count(&tn)
	if tn > 0 {
		return
	}

	// The newest classification wins: the post may have been reclassified since.
	var eee replyConfirmEee
	db.Table("messages_eee").
		Select("id, item_condition, size_bucket, weight_bucket").
		Where("msgid = ?", msgid).
		Order("id DESC").
		Limit(1).
		Scan(&eee)

	// A poster who has said they might deliver takes the car out of the question.
	needsCar := eee.needsACar() && post.Deliverypossible == 0
	if !eee.damaged() && !needsCar {
		return
	}

	stratum := replyConfirmStratum(eee.damaged(), needsCar)
	detail := map[string]any{"eee_id": eee.ID, "deliverypossible": post.Deliverypossible}

	if arm != "ask" {
		// control, and any arm this code has no meaning for: recorded, and the reply goes through as it
		// always did. RecordExposure logs a failure; the reply carries on whatever happens to the row.
		_ = ux.RecordExposure(db, "confirm-reply", arm, replier, "reply", replyid, &stratum, detail)

		return
	}

	question := replyConfirmQuestion(memberName(db, replier), eee.damaged(), needsCar)
	options, _ := json.Marshal(replyConfirmOptions)

	err := db.Transaction(func(tx *gorm.DB) error {
		// First, so that a row which cannot be written leaves no hold and no question: the reply then
		// goes through exactly as it would have in control.
		if err := ux.RecordExposure(tx, "confirm-reply", arm, replier, "reply", replyid, &stratum, detail); err != nil {
			return err
		}

		prompt := ChatMessage{
			Chatid:   chatid,
			Userid:   freegleID,
			Type:     utils.CHAT_MESSAGE_PROMPT,
			Refmsgid: &msgid,
			Message:  question,
			Date:     time.Now(),
		}
		if err := tx.Create(&prompt).Error; err != nil {
			return err
		}

		return tx.Table("chat_prompts").Create(map[string]interface{}{
			"chatmsgid":     prompt.ID,
			"msgid":         msgid,
			"msgids":        fmt.Sprintf("[%d]", msgid),
			"userid":        replier,
			"heldchatmsgid": replyid,
			"kind":          utils.PROMPT_KIND_CONFIRM_REPLY,
			"options":       string(options),
			"expires_at":    gorm.Expr("DATE_ADD(NOW(), INTERVAL ? DAY)", replyConfirmExpiryDays),
			"created_at":    gorm.Expr("NOW()"),
		}).Error
	})

	if err != nil {
		stdlog.Printf("Could not ask replier %d to confirm reply %d in chat %d: %v", replier, replyid, chatid, err)
	}
}

// applyReplyConfirmAnswer is what the replier's answer does to the held reply. "yes" needs
// nothing here: once the prompt is answered chats:process-incoming delivers the reply on its
// next run. "no" marks it processed and unsuccessful, which every delivery path reads as
// "never deliver", with the reason ModTools shows (ChatMessage::PROCESSFAIL_REPLY_WITHDRAWN).
func applyReplyConfirmAnswer(db *gorm.DB, answer string, heldchatmsgid uint64, myid uint64) bool {
	if answer != "no" {
		return true
	}

	res := db.Table("chat_messages").
		Where("id = ? AND userid = ? AND processingrequired = 1", heldchatmsgid, myid).
		Updates(map[string]interface{}{
			"processingrequired":   0,
			"processingsuccessful": 0,
			"processingfailreason": "ReplyWithdrawn",
		})

	return res.Error == nil && res.RowsAffected > 0
}
