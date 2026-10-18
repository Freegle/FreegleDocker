package message

import (
	"encoding/json"
	"fmt"
	"log"
	"strings"
	"time"

	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/lockdown"
	"github.com/freegle/iznik-server-go/queue"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

// SubmitMessage creates a complete, ready-to-go post in a SINGLE call.
//
// This replaces the old multi-step compose dance (PUT /message to create a
// draft → POST /image per attachment → POST /message action=JoinAndPost to
// submit), which forced the client to invent temporary message ids and fake
// attachment ids and to keep draft/submit state in sync — the source of a long
// run of compose bugs (Discourse #9656 etc., sparse-array crashes, string ids
// sent to a []uint64 field, AI illustrations dropped on the repost path).
//
// The old endpoints are deliberately left in place for backwards compatibility
// (edit/repost still use them); this is purely additive.
//
// One request carries everything: the item, the body, the location,
// the options, and the attachments INLINE (by tusd externaluid — the
// server inserts the messages_attachments row with msgid already set, so there
// are no NULL-then-link round-trips and no client-side attachment ids at all).
// For an unauthenticated user an email find-or-creates the account and a JWT is
// returned, so the same single call works logged-in or logged-out.
//
// The message always lands Pending; the content-check batch promotes clean
// posts from non-moderated members to Approved (and only then is it added to
// messages_spatial). This matches handleJoinAndPost exactly.
func SubmitMessage(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)

	type submitAttachment struct {
		Externaluid  string          `json:"externaluid"`
		Externalmods json.RawMessage `json:"externalmods"`
	}
	type SubmitRequest struct {
		Type             string             `json:"type"`
		Messagetype      string             `json:"messagetype"` // client alias for Type
		Item             string             `json:"item"`
		Textbody         string             `json:"textbody"`
		Locationid       *uint64            `json:"locationid"`
		Availablenow     *int               `json:"availablenow"`
		Deadline         *string            `json:"deadline"`
		Deliverypossible *bool              `json:"deliverypossible"`
		AiDeclined       bool               `json:"ai_declined"`
		Email            string             `json:"email"`
		Attachments      []submitAttachment `json:"attachments"`
	}

	var req SubmitRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}
	if req.Type == "" && req.Messagetype != "" {
		req.Type = req.Messagetype
	}
	if req.Type != "Offer" && req.Type != "Wanted" {
		return fiber.NewError(fiber.StatusBadRequest, "type must be Offer or Wanted")
	}
	if strings.TrimSpace(req.Item) == "" {
		return fiber.NewError(fiber.StatusBadRequest, "Item is required")
	}

	db := database.DBConn

	// Unauthenticated: an email find-or-creates the user and yields a JWT so the
	// single call works for logged-out posters too.
	var jwtString string
	var persistent fiber.Map
	if myid == 0 && req.Email != "" {
		var err error
		myid, jwtString, persistent, err = findOrCreateUserForDraft(db, req.Email)
		if err != nil {
			if strings.Contains(err.Error(), "invalid email") {
				return fiber.NewError(fiber.StatusBadRequest, "Invalid email address")
			}
			return fiber.NewError(fiber.StatusInternalServerError, "Failed to create user")
		}
		// SECURITY: findOrCreateUserForDraft returns a JWT only for a brand-new
		// account. An empty JWT here means the email belongs to an EXISTING account
		// while the caller is not logged in — we must NOT post on their behalf or
		// touch their credentials (the password block below would otherwise mint and
		// return a native password for someone else's account). Require a real login;
		// the client already forces this for a known email.
		if jwtString == "" {
			return fiber.NewError(fiber.StatusUnauthorized, "Please log in to post with this email")
		}
	}
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	// Banned members cannot post. A ban is one site-wide column on the user.
	var bannedCount int64
	db.Table("users").Where("id = ? AND banned IS NOT NULL", myid).Count(&bannedCount)
	if bannedCount > 0 {
		return fiber.NewError(fiber.StatusForbidden, "You are banned")
	}

	// Prohibited posters cannot post.
	var ourPostingStatus *string
	db.Table("users").Select("postingstatus").Where("id = ?", myid).Scan(&ourPostingStatus)
	if ourPostingStatus != nil && strings.EqualFold(*ourPostingStatus, utils.POSTING_STATUS_PROHIBITED) {
		return fiber.NewError(fiber.StatusForbidden, "You are not allowed to post")
	}

	// Decide the collection (V1 parity: Message::save() postcoll + User::postToCollection()).
	// Most posts land Pending and the content-check batch promotes clean ones, but a
	// member who is explicitly unmoderated posts straight to Approved. By the member's
	// site-wide posting status: NULL/MODERATED/PROHIBITED = Pending, anything else
	// (DEFAULT/UNMODERATED) = Approved. A new member has no status, so stays Pending.
	// While the lockdown "posts" hold is on, everything goes to Pending.
	collection := utils.COLLECTION_PENDING
	if ourPostingStatus != nil && !lockdown.Held("posts") {
		s := strings.ToUpper(strings.TrimSpace(*ourPostingStatus))
		if s != "" && s != utils.POSTING_STATUS_MODERATED && s != utils.POSTING_STATUS_PROHIBITED {
			collection = utils.COLLECTION_APPROVED
		}
	}

	availNow := 1
	if req.Availablenow != nil {
		availNow = *req.Availablenow
	}

	// Create the message row, capturing the new id from the SAME connection via
	// LastInsertId(). A separate "SELECT id ... ORDER BY id DESC LIMIT 1" would race
	// with a concurrent post by the same user (GORM uses a connection pool) and could
	// return the wrong id, mislinking attachments/postings/history to another message.
	fromip := c.IP()
	messageid := fmt.Sprintf("%.6f@%s-%d", float64(time.Now().UnixNano())/1e9, utils.USER_DOMAIN, myid)
	initialSubject := req.Type + ": " + req.Item
	sqlDB, err := db.DB()
	if err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to create message")
	}
	insRes, err := sqlDB.Exec("INSERT INTO messages (fromuser, type, subject, textbody, message, arrival, date, source, availableinitially, availablenow, locationid, fromip, messageid, collection) VALUES (?, ?, ?, ?, ?, NOW(), NOW(), 'Platform', ?, ?, ?, ?, ?, ?)",
		myid, req.Type, initialSubject, req.Textbody, req.Textbody, availNow, availNow, req.Locationid, fromip, messageid, collection)
	if err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to create message")
	}
	msgidInt, err := insRes.LastInsertId()
	if err != nil || msgidInt == 0 {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to retrieve message ID")
	}
	msgid := uint64(msgidInt)

	// Attachments INLINE by tusd externaluid. First attachment is primary
	// (drives the thumbnail). The client may already have created an orphan row
	// via POST /image (msgid NULL) — link that one rather than inserting a
	// duplicate; only insert a fresh row when none exists. This keeps the handler
	// correct whether or not the client still does the separate image upload.
	for i, att := range req.Attachments {
		if strings.TrimSpace(att.Externaluid) == "" {
			continue
		}
		var mods interface{}
		if len(att.Externalmods) > 0 {
			mods = string(att.Externalmods)
		}
		isPrimary := 0
		if i == 0 {
			isPrimary = 1
		}
		linked := db.Exec("UPDATE messages_attachments SET msgid = ?, `primary` = ?, externalmods = COALESCE(?, externalmods) WHERE externaluid = ? AND msgid IS NULL ORDER BY id DESC LIMIT 1",
			msgid, isPrimary, mods, att.Externaluid)
		if linked.RowsAffected == 0 {
			db.Exec("INSERT INTO messages_attachments (msgid, externaluid, externalmods, `primary`) VALUES (?, ?, ?, ?)",
				msgid, att.Externaluid, mods, isPrimary)
		}
	}

	// If the user declined the AI illustration, record it (V1/PATCH parity) so the
	// illustrations cron does not later re-inject a cached AI image into this post.
	if req.AiDeclined {
		db.Exec("INSERT IGNORE INTO messages_ai_declined (msgid) VALUES (?)", msgid)
	}

	// Item + messages_items. Take the item id from the write connection via
	// gorm.WithResult()+LAST_INSERT_ID(id): a separate "SELECT id FROM items WHERE name = ?"
	// can be routed to a lagging read replica under the DB read/write split and return 0
	// rows, silently skipping the messages_items link so the post never shows in
	// browse/search-by-item (the Discourse 9832 "mixed up offers" class of bug). The
	// message-row insert above already uses LastInsertId for exactly this reason, and
	// message.go's item-insert (PutMessageAs) uses the identical idiom.
	itemRes := gorm.WithResult()
	db.Table("items").Clauses(itemRes, clause.OnConflict{
		DoUpdates: clause.Set{
			{Column: clause.Column{Name: "id"}, Value: gorm.Expr("LAST_INSERT_ID(id)")},
		},
	}).Create(map[string]interface{}{"name": req.Item})
	var itemID uint64
	if itemRes.Result != nil {
		if id, idErr := itemRes.Result.LastInsertId(); idErr == nil {
			itemID = uint64(id)
		}
	}
	if itemID > 0 {
		db.Exec("INSERT IGNORE INTO messages_items (msgid, itemid) VALUES (?, ?)", msgid, itemID)
	}

	// Location → lat/lng + user's last location, then rebuild the subject as
	// "KEYWORD: Item (Area PC)".
	if req.Locationid != nil && *req.Locationid > 0 {
		db.Exec("UPDATE users SET lastlocation = ? WHERE id = ?", *req.Locationid, myid)
		var lat, lng float64
		db.Raw("SELECT lat, lng FROM locations WHERE id = ?", *req.Locationid).Row().Scan(&lat, &lng)
		if lat != 0 || lng != 0 {
			db.Exec("UPDATE messages SET lat = ?, lng = ? WHERE id = ?", lat, lng, msgid)
		}
	}
	locStr := constructLocationString(db, msgid)
	keyword := messageKeyword(req.Type)
	subject := keyword + ": " + req.Item
	if locStr != "" {
		subject = keyword + ": " + req.Item + " (" + locStr + ")"
	}
	db.Exec("UPDATE messages SET subject = ?, suggestedsubject = ? WHERE id = ?", subject, subject, msgid)

	// Options.
	if req.Deadline != nil && *req.Deadline != "" {
		db.Exec("UPDATE messages SET deadline = ? WHERE id = ?", *req.Deadline, msgid)
	}
	if req.Deliverypossible != nil {
		db.Exec("UPDATE messages SET deliverypossible = ? WHERE id = ?", *req.Deliverypossible, msgid)
	}

	// Post it: the message carries its own collection (set at the insert above), so
	// there is no per-group row. Record the posting and history, and the internal fromaddr.
	db.Exec("INSERT INTO messages_postings (msgid) VALUES (?)", msgid)

	fromaddr := user.GetOrCreateInternalEmail(db, myid)
	db.Exec("UPDATE messages SET fromaddr = ? WHERE id = ?", fromaddr, msgid)
	var histFromname string
	db.Raw("SELECT COALESCE(fullname, '') FROM users WHERE id = ?", myid).Scan(&histFromname)
	db.Exec("INSERT IGNORE INTO messages_history (msgid, source, fromuser, fromname, fromaddr, subject, arrival, fromip) VALUES (?, 'Platform', ?, ?, ?, ?, NOW(), ?)",
		msgid, myid, histFromname, fromaddr, subject, fromip)

	logMessageReceived(db, myid, msgid)

	// A Pending post is not in public browse, so it stays out of messages_spatial
	// (the content-check batch / a moderator adds it when it becomes Approved). But a
	// post that went straight to Approved must be indexed now so it shows on the map,
	// and a live Offer must be announced to freebiealerts.app — both things handleApprove
	// does. (approvedby/approvedat stay NULL: this went live without a moderator, which
	// is exactly what "auto-approved" means. No mod-approval email is sent for the same
	// reason — it was never in a moderation queue.)
	if collection == utils.COLLECTION_APPROVED {
		addApprovedMessageToSpatialIndex(db, msgid)
		if req.Type == "Offer" {
			if err := queue.QueueTask(queue.TaskFreebieAlertsAdd, map[string]interface{}{"msgid": msgid}); err != nil {
				log.Printf("Failed to queue freebie alerts add for message %d: %v", msgid, err)
			}
		}
	}

	resp := fiber.Map{"ret": 0, "status": "Success", "id": msgid}
	if jwtString != "" {
		resp["jwt"] = jwtString
		resp["persistent"] = persistent
	}

	// New user without a password: generate one (so they can log in later).
	var hasPassword int64
	db.Raw("SELECT COUNT(*) FROM users_logins WHERE userid = ? AND type = ?", myid, utils.LOGIN_TYPE_NATIVE).Scan(&hasPassword)
	if hasPassword == 0 {
		password := utils.RandomHex(8)
		salt := auth.GetPasswordSalt()
		hashed := auth.HashPassword(password, salt)
		db.Exec("INSERT INTO users_logins (userid, type, uid, credentials, salt) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE credentials = VALUES(credentials), salt = VALUES(salt)",
			myid, utils.LOGIN_TYPE_NATIVE, myid, hashed, salt)
		resp["newuser"] = true
		resp["newpassword"] = password
	}

	return c.JSON(resp)
}
