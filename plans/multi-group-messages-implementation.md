# Multi-Group Messages Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Allow a single message to exist on multiple groups, with per-group moderation state and Trash Nothing deduplication.

**Architecture:** Bottom-up — schema migrations first, then Go API changes, then Nuxt client. Each task is independently testable. The DB already supports multi-group via `messages_groups` composite key `(msgid, groupid)`, but all code assumes single-group.

**Tech Stack:** MySQL/Laravel migrations, Go/Fiber/GORM, Nuxt3/Vue3/Pinia, Vitest, Go test

**Design spec:** `plans/multi-group-messages-design.md`

---

## File Structure

### New files
- `iznik-batch/database/migrations/YYYY_MM_DD_000001_add_per_group_columns_to_messages_groups.php` — Schema migration
- `iznik-batch/database/migrations/YYYY_MM_DD_000002_copy_per_group_data_to_messages_groups.php` — Data migration
- `iznik-batch/app/Console/Commands/Dedup/TnDedupCommand.php` — TN background dedup job
- `iznik-batch/tests/Unit/Commands/Dedup/TnDedupCommandTest.php` — Test for dedup job

### Modified files — Go API
- `iznik-server-go/message/messageGroup.go` — Add Heldby/Spamtype/Spamreason fields to struct
- `iznik-server-go/message/message_list.go:19-23` — Add Heldby to MessageGroupInfo struct
- `iznik-server-go/message/message.go:1470-1484` — handleHold: per-group
- `iznik-server-go/message/message.go:1513-1527` — handleRelease: per-group
- `iznik-server-go/message/message.go:1407-1450` — handleDeleteMessage: per-group
- `iznik-server-go/message/message.go:1452-1468` — handleSpam: per-group
- `iznik-server-go/message/message.go:1486-1511` — handleBackToPending: per-group heldby
- `iznik-server-go/message/message.go:1276-1285` — logAndNotifyMods: log to specific group
- `iznik-server-go/microvolunteering/microvolunteering.go:714-716` — sendForReview: per-group spamreason

### Modified files — Nuxt client
- `iznik-nuxt3/stores/message.js:683-692` — getByGroup: check all groups
- `iznik-nuxt3/modtools/components/ModMessageButton.vue:178-186` — Use contextual groupid prop
- `iznik-nuxt3/modtools/components/ModMessage.vue:758` — Pass contextual groupid to children
- `iznik-nuxt3/modtools/components/ModMessageCrosspost.vue:44-49` — Use contextual groupid
- `iznik-nuxt3/modtools/components/ModStdMessageModal.vue:241-249` — Use contextual groupid
- `iznik-nuxt3/modtools/components/ModMessageDuplicate.vue:55-62` — Use contextual groupid
- `iznik-nuxt3/modtools/components/ModLog.vue:78-86` — Show all groups
- `iznik-nuxt3/modtools/composables/useModMessages.js:59-72` — Sort by contextual group arrival
- `iznik-nuxt3/components/MyMessage.vue:796,827,907` — Show all groups
- `iznik-nuxt3/components/OutcomeModal.vue:301-308` — Remove groupid dependency
- `iznik-nuxt3/components/MessageReportModal.vue:147` — Report to all groups
- `iznik-nuxt3/components/ExportPost.vue:9-10` — Show all group names

---

## Task 1: Schema Migration — Add Per-Group Columns

**Files:**
- Create: `iznik-batch/database/migrations/YYYY_MM_DD_000001_add_per_group_columns_to_messages_groups.php`

This adds `heldby`, `spamtype`, `spamreason` to `messages_groups`. The columns on `messages` are NOT dropped yet — that's the final cleanup task after all code is deployed.

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages_groups', function (Blueprint $table) {
            $table->unsignedBigInteger('heldby')->nullable()->after('approvedat');
            $table->string('spamtype', 50)->nullable()->after('heldby');
            $table->string('spamreason', 255)->nullable()->after('spamtype');

            $table->foreign('heldby')->references('id')->on('users')->onDelete('set null');
            $table->index('heldby', 'heldby_idx');
        });
    }

    public function down(): void
    {
        Schema::table('messages_groups', function (Blueprint $table) {
            $table->dropForeign(['heldby']);
            $table->dropIndex('heldby_idx');
            $table->dropColumn(['heldby', 'spamtype', 'spamreason']);
        });
    }
};
```

- [ ] **Step 2: Run migration**

Run: `docker exec freegle-batch php artisan migrate`
Expected: Migration completes. `messages_groups` now has `heldby`, `spamtype`, `spamreason` columns.

- [ ] **Step 3: Verify**

Run: `docker exec freegle-batch php artisan tinker --execute="Schema::getColumnListing('messages_groups')"`
Expected: Output includes `heldby`, `spamtype`, `spamreason`.

- [ ] **Step 4: Commit**

```bash
git add iznik-batch/database/migrations/*add_per_group_columns*
git commit -m "feat: add heldby/spamtype/spamreason columns to messages_groups for per-group moderation"
```

---

## Task 2: Data Migration — Copy Existing Per-Group State

**Files:**
- Create: `iznik-batch/database/migrations/YYYY_MM_DD_000002_copy_per_group_data_to_messages_groups.php`

For any message currently held or marked as spam, copy the state to all its `messages_groups` rows. This is safe because today each message has exactly one `messages_groups` row.

- [ ] **Step 1: Write the data migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Copy heldby from messages to messages_groups for currently-held messages.
        DB::statement('
            UPDATE messages_groups mg
            INNER JOIN messages m ON m.id = mg.msgid
            SET mg.heldby = m.heldby
            WHERE m.heldby IS NOT NULL
        ');

        // Copy spamtype/spamreason from messages to messages_groups.
        DB::statement('
            UPDATE messages_groups mg
            INNER JOIN messages m ON m.id = mg.msgid
            SET mg.spamtype = m.spamtype, mg.spamreason = m.spamreason
            WHERE m.spamtype IS NOT NULL
        ');
    }

    public function down(): void
    {
        // No rollback needed — the messages table still has the original data.
    }
};
```

- [ ] **Step 2: Run migration**

Run: `docker exec freegle-batch php artisan migrate`
Expected: Migration completes.

- [ ] **Step 3: Verify data copied**

Run:
```bash
docker exec freegle-db mysql -u root iznik -e "
  SELECT COUNT(*) AS held_messages FROM messages WHERE heldby IS NOT NULL;
  SELECT COUNT(*) AS held_mg_rows FROM messages_groups WHERE heldby IS NOT NULL;
"
```
Expected: Both counts should match (since each message currently has one messages_groups row).

- [ ] **Step 4: Commit**

```bash
git add iznik-batch/database/migrations/*copy_per_group_data*
git commit -m "feat: copy heldby/spamtype/spamreason from messages to messages_groups"
```

---

## Task 3: Go API — MessageGroup Struct Update

**Files:**
- Modify: `iznik-server-go/message/messageGroup.go:13-24`
- Modify: `iznik-server-go/message/message_list.go:19-23`

Add the new per-group fields to the Go structs so GORM reads them from DB and they appear in API responses.

- [ ] **Step 1: Update MessageGroup struct**

In `iznik-server-go/message/messageGroup.go`, add fields to the struct:

```go
type MessageGroup struct {
	Groupid     uint64    `json:"groupid"`
	Msgid       uint64    `json:"msgid"`
	Arrival     time.Time `json:"arrival"`
	Collection  string    `json:"collection"`
	Autoreposts uint      `json:"autoreposts"`
	Approvedby  uint64    `json:"approvedby"`
	Heldby      *uint64   `json:"heldby,omitempty"`
	Spamtype    *string   `json:"spamtype,omitempty"`
	Spamreason  *string   `json:"spamreason,omitempty"`
}
```

- [ ] **Step 2: Update MessageGroupInfo struct**

In `iznik-server-go/message/message_list.go`, add `Heldby` so list views can show held status:

```go
type MessageGroupInfo struct {
	Groupid    uint64    `json:"groupid"`
	Collection string    `json:"collection"`
	Arrival    time.Time `json:"arrival"`
	Heldby     *uint64   `json:"heldby,omitempty"`
}
```

- [ ] **Step 3: Update the list query to include heldby**

In `message_list.go:245`, update the query:

```go
db.Raw("SELECT groupid, collection, arrival, heldby FROM messages_groups WHERE msgid = ? AND deleted = 0", msgID).Scan(&groups)
```

- [ ] **Step 4: Run tests**

Run: `docker exec freegle-apiv2 go test ./test/... -count=1 -timeout 300s`
Expected: All existing tests pass (additive change only).

- [ ] **Step 5: Commit**

```bash
cd iznik-server-go
git add message/messageGroup.go message/message_list.go
git commit -m "feat: add heldby/spamtype/spamreason to MessageGroup struct for per-group moderation"
```

---

## Task 4: Go API — Per-Group Hold

**Files:**
- Modify: `iznik-server-go/message/message.go:1470-1484` (handleHold)
- Test: `iznik-server-go/test/message_test.go`

Change `handleHold` to write to `messages_groups.heldby` instead of `messages.heldby`. The mod must be holding it on a specific group — use `req.Groupid` if provided, otherwise the primary group.

- [ ] **Step 1: Write the failing test**

Add to `iznik-server-go/test/message_test.go`:

```go
func TestPostMessageHoldPerGroup(t *testing.T) {
	prefix := uniquePrefix("hold_pg")
	db := database.DBConn

	groupA := CreateTestGroup(t, prefix+"_a")
	groupB := CreateTestGroup(t, prefix+"_b")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, posterID, groupA, "Member")
	CreateTestMembership(t, posterID, groupB, "Member")
	CreateTestMembership(t, modID, groupA, "Moderator")
	CreateTestMembership(t, modID, groupB, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	// Create a message and add it to both groups.
	msgID := createPendingMessage(t, posterID, groupA, prefix)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts) VALUES (?, ?, NOW(), 'Pending', 0)", msgID, groupB)

	// Hold on group A only.
	body := map[string]interface{}{
		"id":      msgID,
		"action":  "Hold",
		"groupid": groupA,
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", modToken)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Verify heldby set on group A's messages_groups row.
	var heldbyA *uint64
	db.Raw("SELECT heldby FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupA).Scan(&heldbyA)
	assert.NotNil(t, heldbyA)
	assert.Equal(t, modID, *heldbyA)

	// Verify heldby NOT set on group B's messages_groups row.
	var heldbyB *uint64
	db.Raw("SELECT heldby FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupB).Scan(&heldbyB)
	assert.Nil(t, heldbyB)
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec freegle-apiv2 go test ./test/ -run TestPostMessageHoldPerGroup -v -count=1`
Expected: FAIL — heldby is set on messages table (global), not on messages_groups.

- [ ] **Step 3: Implement per-group hold**

In `iznik-server-go/message/message.go`, replace the `handleHold` function (lines ~1470-1484):

```go
func handleHold(c *fiber.Ctx, myid uint64, req PostMessageRequest) error {
	db := database.DBConn

	ctx := getMessageModContext(db, myid, req.ID)
	if ctx == nil {
		return fiber.NewError(fiber.StatusForbidden, "Not a moderator for this message")
	}

	// Determine which group to hold on.
	groupid := ctx.Groupid
	if req.Groupid != nil && *req.Groupid > 0 {
		groupid = *req.Groupid
	}

	// Per-group hold: set heldby on the specific messages_groups row.
	db.Exec("UPDATE messages_groups SET heldby = ? WHERE msgid = ? AND groupid = ?", myid, req.ID, groupid)

	// Also update messages.heldby for backwards compatibility during migration.
	// TODO: Remove this once all code reads from messages_groups.heldby.
	db.Exec("UPDATE messages SET heldby = ? WHERE id = ?", myid, req.ID)

	logAndNotifyMods(db, flog.LOG_SUBTYPE_HOLD, ctx, myid, req.ID, 0, "")

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker exec freegle-apiv2 go test ./test/ -run TestPostMessageHoldPerGroup -v -count=1`
Expected: PASS

- [ ] **Step 5: Run full test suite**

Run: `docker exec freegle-apiv2 go test ./test/... -count=1 -timeout 300s`
Expected: All tests pass.

- [ ] **Step 6: Commit**

```bash
cd iznik-server-go
git add message/message.go test/message_test.go
git commit -m "feat: per-group hold — write heldby to messages_groups instead of messages"
```

---

## Task 5: Go API — Per-Group Release

**Files:**
- Modify: `iznik-server-go/message/message.go:1513-1527` (handleRelease)
- Test: `iznik-server-go/test/message_test.go`

- [ ] **Step 1: Write the failing test**

```go
func TestPostMessageReleasePerGroup(t *testing.T) {
	prefix := uniquePrefix("rel_pg")
	db := database.DBConn

	groupA := CreateTestGroup(t, prefix+"_a")
	groupB := CreateTestGroup(t, prefix+"_b")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, posterID, groupA, "Member")
	CreateTestMembership(t, posterID, groupB, "Member")
	CreateTestMembership(t, modID, groupA, "Moderator")
	CreateTestMembership(t, modID, groupB, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	// Create message on both groups, held on both.
	msgID := createPendingMessage(t, posterID, groupA, prefix)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts) VALUES (?, ?, NOW(), 'Pending', 0)", msgID, groupB)
	db.Exec("UPDATE messages_groups SET heldby = ? WHERE msgid = ?", modID, msgID)

	// Release on group A only.
	body := map[string]interface{}{
		"id":      msgID,
		"action":  "Release",
		"groupid": groupA,
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", modToken)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Group A should be released.
	var heldbyA *uint64
	db.Raw("SELECT heldby FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupA).Scan(&heldbyA)
	assert.Nil(t, heldbyA)

	// Group B should still be held.
	var heldbyB *uint64
	db.Raw("SELECT heldby FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupB).Scan(&heldbyB)
	assert.NotNil(t, heldbyB)
	assert.Equal(t, modID, *heldbyB)
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec freegle-apiv2 go test ./test/ -run TestPostMessageReleasePerGroup -v -count=1`
Expected: FAIL

- [ ] **Step 3: Implement per-group release**

```go
func handleRelease(c *fiber.Ctx, myid uint64, req PostMessageRequest) error {
	db := database.DBConn

	ctx := getMessageModContext(db, myid, req.ID)
	if ctx == nil {
		return fiber.NewError(fiber.StatusForbidden, "Not a moderator for this message")
	}

	groupid := ctx.Groupid
	if req.Groupid != nil && *req.Groupid > 0 {
		groupid = *req.Groupid
	}

	// Per-group release.
	db.Exec("UPDATE messages_groups SET heldby = NULL WHERE msgid = ? AND groupid = ?", req.ID, groupid)

	// Check if still held on any group — if not, clear messages.heldby for backwards compat.
	var stillHeldCount int64
	db.Raw("SELECT COUNT(*) FROM messages_groups WHERE msgid = ? AND heldby IS NOT NULL", req.ID).Scan(&stillHeldCount)
	if stillHeldCount == 0 {
		db.Exec("UPDATE messages SET heldby = NULL WHERE id = ?", req.ID)
	}

	logAndNotifyMods(db, flog.LOG_SUBTYPE_RELEASE, ctx, myid, req.ID, 0, "")

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker exec freegle-apiv2 go test ./test/ -run TestPostMessageReleasePerGroup -v -count=1`
Expected: PASS

- [ ] **Step 5: Run full suite, commit**

Run: `docker exec freegle-apiv2 go test ./test/... -count=1 -timeout 300s`

```bash
cd iznik-server-go && git add message/message.go test/message_test.go
git commit -m "feat: per-group release — clear heldby on specific messages_groups row"
```

---

## Task 6: Go API — Per-Group Delete

**Files:**
- Modify: `iznik-server-go/message/message.go:1407-1450` (handleDeleteMessage)
- Test: `iznik-server-go/test/message_test.go`

Currently deletes ALL `messages_groups` rows and soft-deletes the message. Change to delete only the specified group's row. If it was the last group, then soft-delete the message.

- [ ] **Step 1: Write the failing test**

```go
func TestPostMessageDeletePerGroup(t *testing.T) {
	prefix := uniquePrefix("del_pg")
	db := database.DBConn

	groupA := CreateTestGroup(t, prefix+"_a")
	groupB := CreateTestGroup(t, prefix+"_b")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, posterID, groupA, "Member")
	CreateTestMembership(t, posterID, groupB, "Member")
	CreateTestMembership(t, modID, groupA, "Moderator")
	CreateTestMembership(t, modID, groupB, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	msgID := createPendingMessage(t, posterID, groupA, prefix)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts) VALUES (?, ?, NOW(), 'Pending', 0)", msgID, groupB)

	// Delete from group A only.
	body := map[string]interface{}{
		"id":      msgID,
		"action":  "Delete",
		"groupid": groupA,
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", modToken)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Group A's row should be gone.
	var countA int64
	db.Raw("SELECT COUNT(*) FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupA).Scan(&countA)
	assert.Equal(t, int64(0), countA)

	// Group B's row should still exist.
	var countB int64
	db.Raw("SELECT COUNT(*) FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupB).Scan(&countB)
	assert.Equal(t, int64(1), countB)

	// Message itself should NOT be soft-deleted (still on group B).
	var deleted *time.Time
	db.Raw("SELECT deleted FROM messages WHERE id = ?", msgID).Scan(&deleted)
	assert.Nil(t, deleted)
}

func TestPostMessageDeleteLastGroup(t *testing.T) {
	prefix := uniquePrefix("del_last")
	db := database.DBConn

	groupA := CreateTestGroup(t, prefix+"_a")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, posterID, groupA, "Member")
	CreateTestMembership(t, modID, groupA, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	msgID := createPendingMessage(t, posterID, groupA, prefix)

	// Delete from the only group.
	body := map[string]interface{}{
		"id":      msgID,
		"action":  "Delete",
		"groupid": groupA,
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", modToken)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Message should be soft-deleted since it was the last group.
	var deleted *time.Time
	db.Raw("SELECT deleted FROM messages WHERE id = ?", msgID).Scan(&deleted)
	assert.NotNil(t, deleted)
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker exec freegle-apiv2 go test ./test/ -run "TestPostMessageDelete(PerGroup|LastGroup)" -v -count=1`
Expected: FAIL

- [ ] **Step 3: Implement per-group delete**

```go
func handleDeleteMessage(c *fiber.Ctx, myid uint64, req PostMessageRequest) error {
	db := database.DBConn

	ctx := getMessageModContext(db, myid, req.ID)
	if ctx == nil {
		return fiber.NewError(fiber.StatusForbidden, "Not a moderator for this message")
	}

	if req.Groupid != nil && *req.Groupid > 0 {
		ctx.Groupid = *req.Groupid
	}
	groupid := ctx.Groupid

	// Delete from the specific group only.
	db.Exec("DELETE FROM messages_groups WHERE msgid = ? AND groupid = ?", req.ID, groupid)

	// Check if any groups remain. If not, soft-delete the message itself.
	var remainingGroups int64
	db.Raw("SELECT COUNT(*) FROM messages_groups WHERE msgid = ? AND deleted = 0", req.ID).Scan(&remainingGroups)
	if remainingGroups == 0 {
		db.Exec("UPDATE messages SET deleted = NOW(), messageid = NULL WHERE id = ?", req.ID)
	}

	subject := ""
	if req.Subject != nil {
		subject = *req.Subject
	}
	body := ""
	if req.Body != nil {
		body = *req.Body
	}
	stdmsgid := uint64(0)
	if req.Stdmsgid != nil {
		stdmsgid = *req.Stdmsgid
	}

	db.Exec("INSERT INTO background_tasks (task_type, data) VALUES (?, JSON_OBJECT('msgid', ?, 'groupid', ?, 'byuser', ?, 'subject', ?, 'body', ?, 'stdmsgid', ?))",
		"email_message_rejected", req.ID, groupid, myid, subject, body, stdmsgid)

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}
```

- [ ] **Step 4: Run tests, verify pass**

Run: `docker exec freegle-apiv2 go test ./test/ -run "TestPostMessageDelete(PerGroup|LastGroup)" -v -count=1`
Expected: PASS

- [ ] **Step 5: Full suite, commit**

Run: `docker exec freegle-apiv2 go test ./test/... -count=1 -timeout 300s`

```bash
cd iznik-server-go && git add message/message.go test/message_test.go
git commit -m "feat: per-group delete — only remove message from specified group"
```

---

## Task 7: Go API — Per-Group Spam

**Files:**
- Modify: `iznik-server-go/message/message.go:1452-1468` (handleSpam)
- Test: `iznik-server-go/test/message_test.go`

Currently marks spam globally. Change to set `collection = 'Spam'` and `spamtype`/`spamreason` on the specific group's row only.

- [ ] **Step 1: Write the failing test**

```go
func TestPostMessageSpamPerGroup(t *testing.T) {
	prefix := uniquePrefix("spam_pg")
	db := database.DBConn

	groupA := CreateTestGroup(t, prefix+"_a")
	groupB := CreateTestGroup(t, prefix+"_b")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, posterID, groupA, "Member")
	CreateTestMembership(t, posterID, groupB, "Member")
	CreateTestMembership(t, modID, groupA, "Moderator")
	CreateTestMembership(t, modID, groupB, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	msgID := createPendingMessage(t, posterID, groupA, prefix)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts) VALUES (?, ?, NOW(), 'Pending', 0)", msgID, groupB)

	// Spam on group A only.
	body := map[string]interface{}{
		"id":      msgID,
		"action":  "Spam",
		"groupid": groupA,
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", modToken)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Group A should be marked as Spam.
	var collectionA string
	db.Raw("SELECT collection FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupA).Scan(&collectionA)
	assert.Equal(t, "Spam", collectionA)

	// Group B should still be Pending.
	var collectionB string
	db.Raw("SELECT collection FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupB).Scan(&collectionB)
	assert.Equal(t, "Pending", collectionB)

	// Message itself should NOT be soft-deleted (still active on group B).
	var deleted *time.Time
	db.Raw("SELECT deleted FROM messages WHERE id = ?", msgID).Scan(&deleted)
	assert.Nil(t, deleted)
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec freegle-apiv2 go test ./test/ -run TestPostMessageSpamPerGroup -v -count=1`
Expected: FAIL

- [ ] **Step 3: Implement per-group spam**

```go
func handleSpam(c *fiber.Ctx, myid uint64, req PostMessageRequest) error {
	db := database.DBConn

	ctx := getMessageModContext(db, myid, req.ID)
	if ctx == nil {
		return fiber.NewError(fiber.StatusForbidden, "Not a moderator for this message")
	}

	groupid := ctx.Groupid
	if req.Groupid != nil && *req.Groupid > 0 {
		groupid = *req.Groupid
	}

	// Record for spam training (global — the message content is spammy regardless of group).
	db.Exec("REPLACE INTO messages_spamham (msgid, spamham) VALUES (?, ?)", req.ID, utils.COLLECTION_SPAM)

	// Per-group: mark as Spam on this group only.
	db.Exec("UPDATE messages_groups SET collection = ?, spamtype = 'Spam', spamreason = 'Moderator' WHERE msgid = ? AND groupid = ?",
		utils.COLLECTION_SPAM, req.ID, groupid)

	// If no non-spam groups remain, soft-delete the message.
	var activeGroups int64
	db.Raw("SELECT COUNT(*) FROM messages_groups WHERE msgid = ? AND collection != ? AND deleted = 0", req.ID, utils.COLLECTION_SPAM).Scan(&activeGroups)
	if activeGroups == 0 {
		db.Exec("UPDATE messages SET deleted = NOW() WHERE id = ?", req.ID)
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}
```

- [ ] **Step 4: Run test, verify pass, full suite, commit**

Run: `docker exec freegle-apiv2 go test ./test/ -run TestPostMessageSpamPerGroup -v -count=1`
Then: `docker exec freegle-apiv2 go test ./test/... -count=1 -timeout 300s`

```bash
cd iznik-server-go && git add message/message.go test/message_test.go
git commit -m "feat: per-group spam — mark spam on specific group only"
```

---

## Task 8: Go API — Per-Group BackToPending

**Files:**
- Modify: `iznik-server-go/message/message.go:1486-1511` (handleBackToPending)
- Test: `iznik-server-go/test/message_test.go`

The collection update is already per-group when `req.Groupid` is provided (lines 1499-1504). But `heldby` is still written to the messages table (line 1496). Fix to write to `messages_groups`.

- [ ] **Step 1: Write the failing test**

```go
func TestPostMessageBackToPendingPerGroup(t *testing.T) {
	prefix := uniquePrefix("btp_pg")
	db := database.DBConn

	groupA := CreateTestGroup(t, prefix+"_a")
	groupB := CreateTestGroup(t, prefix+"_b")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, posterID, groupA, "Member")
	CreateTestMembership(t, posterID, groupB, "Member")
	CreateTestMembership(t, modID, groupA, "Moderator")
	CreateTestMembership(t, modID, groupB, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	// Create message approved on both groups.
	msgID := createPendingMessage(t, posterID, groupA, prefix)
	db.Exec("UPDATE messages_groups SET collection = 'Approved' WHERE msgid = ? AND groupid = ?", msgID, groupA)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts) VALUES (?, ?, NOW(), 'Approved', 0)", msgID, groupB)

	// BackToPending on group A only.
	body := map[string]interface{}{
		"id":      msgID,
		"action":  "BackToPending",
		"groupid": groupA,
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", modToken)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Group A should be Pending and held.
	var collA string
	var heldbyA *uint64
	db.Raw("SELECT collection FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupA).Scan(&collA)
	db.Raw("SELECT heldby FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupA).Scan(&heldbyA)
	assert.Equal(t, "Pending", collA)
	assert.NotNil(t, heldbyA)
	assert.Equal(t, modID, *heldbyA)

	// Group B should still be Approved and NOT held.
	var collB string
	var heldbyB *uint64
	db.Raw("SELECT collection FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupB).Scan(&collB)
	db.Raw("SELECT heldby FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupB).Scan(&heldbyB)
	assert.Equal(t, "Approved", collB)
	assert.Nil(t, heldbyB)
}
```

- [ ] **Step 2: Run test, verify fail**

Run: `docker exec freegle-apiv2 go test ./test/ -run TestPostMessageBackToPendingPerGroup -v -count=1`

- [ ] **Step 3: Implement fix**

Replace handleBackToPending:

```go
func handleBackToPending(c *fiber.Ctx, myid uint64, req PostMessageRequest) error {
	db := database.DBConn

	ctx := getMessageModContext(db, myid, req.ID)
	if ctx == nil {
		return fiber.NewError(fiber.StatusForbidden, "Not a moderator for this message")
	}

	groupid := ctx.Groupid
	if req.Groupid != nil && *req.Groupid > 0 {
		groupid = *req.Groupid
	}

	// Per-group: hold and move back to Pending on this group only.
	db.Exec("UPDATE messages_groups SET collection = ?, heldby = ?, approvedby = NULL, approvedat = NULL WHERE msgid = ? AND groupid = ? AND collection = ?",
		utils.COLLECTION_PENDING, myid, req.ID, groupid, utils.COLLECTION_APPROVED)

	// Backwards compat: also update messages.heldby.
	db.Exec("UPDATE messages SET heldby = ? WHERE id = ?", myid, req.ID)

	logAndNotifyMods(db, flog.LOG_SUBTYPE_HOLD, ctx, myid, req.ID, 0, "Back to pending")

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}
```

- [ ] **Step 4: Run test, verify pass, full suite, commit**

```bash
cd iznik-server-go && git add message/message.go test/message_test.go
git commit -m "feat: per-group back-to-pending — hold only on the target group"
```

---

## Task 9: Go API — logAndNotifyMods Per-Group Logging

**Files:**
- Modify: `iznik-server-go/message/message.go:1276-1285` (logAndNotifyMods)

Currently logs to `ctx.Groupid` (primary group) but notifies all groups. The log should go to the specific group the action was taken on. Since callers now set `ctx.Groupid` to the target group before calling `logAndNotifyMods`, this is already correct after Tasks 4-8. But we should verify and add a test.

- [ ] **Step 1: Verify current behaviour**

Read `logAndNotifyMods` — it uses `ctx.Groupid` for the log entry. After our changes, callers set `ctx.Groupid` to the request groupid before calling. Confirm this by reviewing each caller.

- [ ] **Step 2: Write a test**

```go
func TestModActionLogsToSpecificGroup(t *testing.T) {
	prefix := uniquePrefix("log_pg")
	db := database.DBConn

	groupA := CreateTestGroup(t, prefix+"_a")
	groupB := CreateTestGroup(t, prefix+"_b")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, posterID, groupA, "Member")
	CreateTestMembership(t, posterID, groupB, "Member")
	CreateTestMembership(t, modID, groupA, "Moderator")
	CreateTestMembership(t, modID, groupB, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	msgID := createPendingMessage(t, posterID, groupA, prefix)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts) VALUES (?, ?, NOW(), 'Pending', 0)", msgID, groupB)

	// Hold on group B specifically.
	body := map[string]interface{}{
		"id":      msgID,
		"action":  "Hold",
		"groupid": groupB,
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", modToken)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	// The log entry should reference group B, not group A.
	var logGroupid uint64
	db.Raw("SELECT groupid FROM logs WHERE msgid = ? AND subtype = 'Hold' ORDER BY id DESC LIMIT 1", msgID).Scan(&logGroupid)
	assert.Equal(t, groupB, logGroupid)
}
```

- [ ] **Step 3: Run test, verify pass**

Run: `docker exec freegle-apiv2 go test ./test/ -run TestModActionLogsToSpecificGroup -v -count=1`
Expected: PASS (since handleHold now sets groupid from req before calling logAndNotifyMods).

- [ ] **Step 4: Commit**

```bash
cd iznik-server-go && git add test/message_test.go
git commit -m "test: verify mod action logs reference the specific target group"
```

---

## Task 10: Go API — Microvolunteering sendForReview Per-Group

**Files:**
- Modify: `iznik-server-go/microvolunteering/microvolunteering.go:712-717`
- Test: `iznik-server-go/test/microvolunteering_test.go`

`sendForReview` writes `spamreason` to `messages` table and updates `collection` on ALL groups. Needs to accept a groupid and write per-group.

- [ ] **Step 1: Write the failing test**

```go
func TestSendForReviewPerGroup(t *testing.T) {
	prefix := uniquePrefix("sfr_pg")
	db := database.DBConn

	groupA := CreateTestGroup(t, prefix+"_a")
	groupB := CreateTestGroup(t, prefix+"_b")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	CreateTestMembership(t, posterID, groupA, "Member")
	CreateTestMembership(t, posterID, groupB, "Member")

	msgID := CreateTestMessage(t, posterID, groupA, "Test Offer Spam Item", 55.9533, -3.1883)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts) VALUES (?, ?, NOW(), 'Approved', 0)", msgID, groupB)

	// Call sendForReview targeting group A.
	sendForReview(db, msgID, groupA, "Test spam reason")

	// Group A should be Pending with spamreason set.
	var collA string
	var reasonA *string
	db.Raw("SELECT collection FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupA).Scan(&collA)
	db.Raw("SELECT spamreason FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupA).Scan(&reasonA)
	assert.Equal(t, "Pending", collA)
	assert.NotNil(t, reasonA)
	assert.Equal(t, "Test spam reason", *reasonA)

	// Group B should still be Approved with no spamreason.
	var collB string
	var reasonB *string
	db.Raw("SELECT collection FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupB).Scan(&collB)
	db.Raw("SELECT spamreason FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupB).Scan(&reasonB)
	assert.Equal(t, "Approved", collB)
	assert.Nil(t, reasonB)
}
```

- [ ] **Step 2: Run test, verify fail**

- [ ] **Step 3: Update sendForReview signature and implementation**

```go
func sendForReview(db *gorm.DB, msgid uint64, groupid uint64, reason string) {
	db.Exec("UPDATE messages_groups SET spamreason = ?, collection = ? WHERE msgid = ? AND groupid = ?",
		reason, utils.COLLECTION_PENDING, msgid, groupid)
}
```

Update all callers of `sendForReview` to pass the groupid. Search for all call sites — each microvolunteering challenge handler that calls `sendForReview` will need to determine the groupid from the message context.

- [ ] **Step 4: Run test, verify pass, full suite, commit**

```bash
cd iznik-server-go && git add microvolunteering/microvolunteering.go test/microvolunteering_test.go
git commit -m "feat: sendForReview per-group — write spamreason to messages_groups"
```

---

## Task 11: Go API — List/Search Dedup Across Groups

**Files:**
- Modify: `iznik-server-go/message/message_list.go:177-200`

Currently the list query selects `mg.msgid FROM messages_groups mg WHERE mg.groupid IN (?)`. If a message is on two groups the user is a member of, and both groupids are in the IN clause, the same msgid appears twice. Fix with `SELECT DISTINCT mg.msgid`.

**Note:** The search queries in `search.go` already `GROUP BY msgid` (lines 160, 200, 242, 282) so they're already safe.

- [ ] **Step 1: Write the failing test**

```go
func TestListMessagesDedupsMultiGroup(t *testing.T) {
	prefix := uniquePrefix("list_dedup")
	db := database.DBConn

	groupA := CreateTestGroup(t, prefix+"_a")
	groupB := CreateTestGroup(t, prefix+"_b")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	viewerID := CreateTestUser(t, prefix+"_viewer", "User")
	CreateTestMembership(t, posterID, groupA, "Member")
	CreateTestMembership(t, posterID, groupB, "Member")
	CreateTestMembership(t, viewerID, groupA, "Member")
	CreateTestMembership(t, viewerID, groupB, "Member")
	_, viewerToken := CreateTestSession(t, viewerID)

	// Create message on both groups.
	msgID := CreateTestMessage(t, posterID, groupA, "Test Multi Group Offer", 55.9533, -3.1883)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts) VALUES (?, ?, NOW(), 'Approved', 0)", msgID, groupB)

	// List messages across both groups.
	url := fmt.Sprintf("/api/messages?groupids=%d,%d&collection=Approved&jwt=%s", groupA, groupB, viewerToken)
	req := httptest.NewRequest("GET", url, nil)
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var result ListMessagesResponse
	json2.Unmarshal(rsp(resp), &result)

	// Message should appear exactly once, with both groups in its groups array.
	count := 0
	for _, m := range result.Messages {
		if m.ID == msgID {
			count++
			assert.GreaterOrEqual(t, len(m.Groups), 2, "Should have both groups in the array")
		}
	}
	assert.Equal(t, 1, count, "Message should appear exactly once, not twice")
}
```

- [ ] **Step 2: Run test, verify fail**

- [ ] **Step 3: Add DISTINCT to list query**

In `message_list.go`, change the standard listing query (line 177):

```go
sql := "SELECT DISTINCT mg.msgid FROM messages_groups mg " +
```

Also add DISTINCT to all the search-variant queries in the same function (lines 127, 139, 153, 164) — anywhere `SELECT mg.msgid` appears.

- [ ] **Step 4: Run test, verify pass, full suite, commit**

```bash
cd iznik-server-go && git add message/message_list.go test/message_test.go
git commit -m "feat: deduplicate message listings when message is on multiple queried groups"
```

---

## Task 12: Background TN Dedup Job

**Files:**
- Create: `iznik-batch/app/Console/Commands/Dedup/TnDedupCommand.php`
- Create: `iznik-batch/tests/Unit/Commands/Dedup/TnDedupCommandTest.php`

Periodic job that finds messages with the same `tnpostid` but different `messages.id`, merges them by moving `messages_groups` rows to the oldest message, and deletes duplicates.

- [ ] **Step 1: Write the test**

```php
<?php

namespace Tests\Unit\Commands\Dedup;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

class TnDedupCommandTest extends TestCase
{
    public function test_merges_duplicate_tn_posts(): void
    {
        // Create two messages with the same tnpostid on different groups.
        $groupA = DB::table('groups')->insertGetId(['nameshort' => 'test-tn-dedup-a', 'type' => 'Freegle', 'publish' => 1]);
        $groupB = DB::table('groups')->insertGetId(['nameshort' => 'test-tn-dedup-b', 'type' => 'Freegle', 'publish' => 1]);
        $userId = DB::table('users')->insertGetId(['systemrole' => 'User']);

        $msg1 = DB::table('messages')->insertGetId([
            'fromuser' => $userId,
            'subject' => 'OFFER: Test Item',
            'type' => 'Offer',
            'arrival' => now()->subHour(),
            'tnpostid' => 'TN-DEDUP-TEST-123',
        ]);
        $msg2 = DB::table('messages')->insertGetId([
            'fromuser' => $userId,
            'subject' => 'OFFER: Test Item',
            'type' => 'Offer',
            'arrival' => now(),
            'tnpostid' => 'TN-DEDUP-TEST-123',
        ]);

        DB::table('messages_groups')->insert([
            ['msgid' => $msg1, 'groupid' => $groupA, 'collection' => 'Approved', 'arrival' => now()->subHour()],
            ['msgid' => $msg2, 'groupid' => $groupB, 'collection' => 'Approved', 'arrival' => now()],
        ]);

        $this->artisan('dedup:tn')->assertSuccessful();

        // msg1 (older) should now have both groups.
        $this->assertDatabaseHas('messages_groups', ['msgid' => $msg1, 'groupid' => $groupA]);
        $this->assertDatabaseHas('messages_groups', ['msgid' => $msg1, 'groupid' => $groupB]);

        // msg2 should be deleted.
        $this->assertDatabaseMissing('messages_groups', ['msgid' => $msg2]);
        $this->assertNotNull(DB::table('messages')->where('id', $msg2)->value('deleted'));

        // Cleanup.
        DB::table('messages')->whereIn('id', [$msg1, $msg2])->delete();
        DB::table('groups')->whereIn('id', [$groupA, $groupB])->delete();
        DB::table('users')->where('id', $userId)->delete();
    }

    public function test_ignores_messages_without_tnpostid(): void
    {
        // Create two messages without tnpostid.
        $groupA = DB::table('groups')->insertGetId(['nameshort' => 'test-tn-nodup-a', 'type' => 'Freegle', 'publish' => 1]);
        $userId = DB::table('users')->insertGetId(['systemrole' => 'User']);

        $msg1 = DB::table('messages')->insertGetId([
            'fromuser' => $userId, 'subject' => 'OFFER: Item A', 'type' => 'Offer', 'arrival' => now(),
        ]);
        $msg2 = DB::table('messages')->insertGetId([
            'fromuser' => $userId, 'subject' => 'OFFER: Item B', 'type' => 'Offer', 'arrival' => now(),
        ]);

        DB::table('messages_groups')->insert([
            ['msgid' => $msg1, 'groupid' => $groupA, 'collection' => 'Approved', 'arrival' => now()],
            ['msgid' => $msg2, 'groupid' => $groupA, 'collection' => 'Approved', 'arrival' => now()],
        ]);

        $this->artisan('dedup:tn')->assertSuccessful();

        // Both messages should still exist.
        $this->assertDatabaseHas('messages_groups', ['msgid' => $msg1]);
        $this->assertDatabaseHas('messages_groups', ['msgid' => $msg2]);

        // Cleanup.
        DB::table('messages')->whereIn('id', [$msg1, $msg2])->delete();
        DB::table('groups')->where('id', $groupA)->delete();
        DB::table('users')->where('id', $userId)->delete();
    }
}
```

- [ ] **Step 2: Write the command**

```php
<?php

namespace App\Console\Commands\Dedup;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TnDedupCommand extends Command
{
    protected $signature = 'dedup:tn';
    protected $description = 'Merge duplicate Trash Nothing cross-posts by tnpostid';

    public function handle(): int
    {
        // Find tnpostids with multiple message IDs.
        $duplicates = DB::table('messages')
            ->select('tnpostid', DB::raw('MIN(id) as canonical_id'), DB::raw('COUNT(*) as cnt'))
            ->whereNotNull('tnpostid')
            ->where('tnpostid', '!=', '')
            ->whereNull('deleted')
            ->groupBy('tnpostid')
            ->having('cnt', '>', 1)
            ->get();

        $merged = 0;

        foreach ($duplicates as $dup) {
            $duplicateIds = DB::table('messages')
                ->where('tnpostid', $dup->tnpostid)
                ->where('id', '!=', $dup->canonical_id)
                ->whereNull('deleted')
                ->pluck('id');

            foreach ($duplicateIds as $dupeId) {
                DB::transaction(function () use ($dup, $dupeId) {
                    // Move messages_groups rows to canonical message.
                    // Use INSERT IGNORE in case the canonical already has a row for this group.
                    DB::statement('
                        INSERT IGNORE INTO messages_groups (msgid, groupid, collection, arrival, autoreposts, msgtype)
                        SELECT ?, groupid, collection, arrival, autoreposts, msgtype
                        FROM messages_groups WHERE msgid = ?
                    ', [$dup->canonical_id, $dupeId]);

                    // Move messages_history rows.
                    DB::statement('
                        UPDATE IGNORE messages_history SET msgid = ? WHERE msgid = ?
                    ', [$dup->canonical_id, $dupeId]);

                    // Move messages_postings rows.
                    DB::statement('
                        UPDATE IGNORE messages_postings SET msgid = ? WHERE msgid = ?
                    ', [$dup->canonical_id, $dupeId]);

                    // Update chat_messages references.
                    DB::table('chat_messages')
                        ->where('refmsgid', $dupeId)
                        ->update(['refmsgid' => $dup->canonical_id]);

                    // Delete duplicate's messages_groups rows and soft-delete the message.
                    DB::table('messages_groups')->where('msgid', $dupeId)->delete();
                    DB::table('messages')->where('id', $dupeId)->update([
                        'deleted' => now(),
                        'messageid' => null,
                    ]);
                });

                $merged++;
                Log::info("TN dedup: merged message {$dupeId} into {$dup->canonical_id} (tnpostid: {$dup->tnpostid})");
            }
        }

        $this->info("Merged {$merged} duplicate TN posts.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 3: Run test**

Run: `docker exec freegle-batch php artisan test --filter=TnDedupCommandTest`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
cd iznik-batch
git add app/Console/Commands/Dedup/TnDedupCommand.php tests/Unit/Commands/Dedup/TnDedupCommandTest.php
git commit -m "feat: background TN dedup job — merge cross-posted messages by tnpostid"
```

---

## Task 13: Nuxt — Message Store getByGroup Fix

**Files:**
- Modify: `iznik-nuxt3/stores/message.js:683-692`
- Test: existing message store tests

Currently `getByGroup` filters by `groups[0].groupid`. A multi-group message should match if ANY of its groups match.

- [ ] **Step 1: Update getByGroup**

```javascript
getByGroup: (state) => (groupid) => {
  const ret = Object.values(state.list).filter((message) => {
    return (
      message.groups.length > 0 &&
      message.groups.some(
        (g) => parseInt(g.groupid) === parseInt(groupid)
      )
    )
  })
  return ret
},
```

- [ ] **Step 2: Write/update test**

In the message store test file, add a test that creates a message with two groups and verifies `getByGroup` finds it via either group.

- [ ] **Step 3: Run vitest, commit**

Run: `docker exec freegle-nuxt3 npx vitest run stores/message`

```bash
cd iznik-nuxt3 && git add stores/message.js tests/
git commit -m "fix: getByGroup matches message on any group, not just groups[0]"
```

---

## Task 14: Nuxt — ModTools Contextual Groupid

**Files:**
- Modify: `iznik-nuxt3/modtools/components/ModMessage.vue`
- Modify: `iznik-nuxt3/modtools/components/ModMessageButton.vue`
- Modify: `iznik-nuxt3/modtools/components/ModMessageCrosspost.vue`
- Modify: `iznik-nuxt3/modtools/components/ModStdMessageModal.vue`
- Modify: `iznik-nuxt3/modtools/components/ModMessageDuplicate.vue`

The core change: instead of extracting `groups[0].groupid` in each component, pass the contextual groupid (the group the mod is currently moderating) as a prop from the parent.

- [ ] **Step 1: Add groupid prop to ModMessageButton.vue**

Replace the computed groupid (lines 178-186) with a prop:

```javascript
const props = defineProps({
  id: { type: Number, required: true },
  groupid: { type: Number, required: true },
  // ... existing props
})

// Remove the computed groupid — use props.groupid instead.
```

Update all references from `groupid.value` to `props.groupid`.

- [ ] **Step 2: Update ModMessage.vue to pass contextual groupid**

ModMessage.vue has a computed groupid at line ~758. Change it to accept a prop:

```javascript
const props = defineProps({
  id: { type: Number, required: true },
  groupid: { type: Number, required: true },
  // ... existing props
})
```

Pass `props.groupid` to child components:
```vue
<ModMessageButton :id="id" :groupid="props.groupid" ... />
```

- [ ] **Step 3: Update remaining components**

Apply the same pattern to:
- `ModMessageCrosspost.vue` — accept `groupid` prop, remove `messageGroupId` computed
- `ModStdMessageModal.vue` — accept `groupid` prop, use instead of `groups[0]` fallback
- `ModMessageDuplicate.vue` — accept `groupid` prop, remove computed

- [ ] **Step 4: Update parent component that renders ModMessage**

The parent (likely the mod queue page or `useModMessages` composable) already knows which group it's showing. Pass it down:

```vue
<ModMessage :id="message.id" :groupid="currentGroupId" />
```

- [ ] **Step 5: Run vitest for ModTools components**

Run: `docker exec freegle-nuxt3 npx vitest run modtools/`

- [ ] **Step 6: Commit**

```bash
cd iznik-nuxt3
git add modtools/components/ModMessage*.vue modtools/components/ModStdMessageModal.vue
git commit -m "feat: ModTools components use contextual groupid prop instead of groups[0]"
```

---

## Task 15: Nuxt — ModTools Multi-Group Indicator

**Files:**
- Modify: `iznik-nuxt3/modtools/components/ModMessage.vue`

Show a badge when a message is on multiple groups.

- [ ] **Step 1: Add multi-group indicator to template**

In ModMessage.vue template, add after the group name display:

```vue
<span v-if="message.groups && message.groups.length > 1" class="small text-muted ms-1">
  Also on:
  <span v-for="(g, idx) in otherGroups" :key="g.groupid">
    {{ groupStore.get(g.groupid)?.namedisplay }}<span v-if="idx < otherGroups.length - 1">, </span>
  </span>
</span>
```

Add computed:

```javascript
const otherGroups = computed(() => {
  if (!message.value?.groups) return []
  return message.value.groups.filter(g => parseInt(g.groupid) !== parseInt(props.groupid))
})
```

- [ ] **Step 2: Add withdraw warning to delete/spam actions**

In ModMessageButton.vue, update the delete/spam confirmation modals to show a warning when message is on multiple groups:

```vue
<span v-if="message.groups && message.groups.length > 1" class="text-warning">
  This will only remove the message from this group. It remains on {{ message.groups.length - 1 }} other group(s).
</span>
```

- [ ] **Step 3: Run vitest, commit**

```bash
cd iznik-nuxt3 && git add modtools/components/ModMessage*.vue
git commit -m "feat: show multi-group indicator and per-group action warnings in ModTools"
```

---

## Task 16: Nuxt — Sort by Contextual Group Arrival

**Files:**
- Modify: `iznik-nuxt3/modtools/composables/useModMessages.js:59-72`

Currently sorts by `groups[0].arrival`. Should sort by the arrival time of the contextual group.

- [ ] **Step 1: Update sort**

The composable needs to know the current group context. Pass it as a parameter:

```javascript
function sortMessages(messages, contextGroupId) {
  messages.sort((a, b) => {
    const arrivalA = getGroupArrival(a, contextGroupId)
    const arrivalB = getGroupArrival(b, contextGroupId)
    return new Date(arrivalB).getTime() - new Date(arrivalA).getTime()
  })
}

function getGroupArrival(message, groupId) {
  if (message.groups) {
    const contextGroup = message.groups.find(
      (g) => parseInt(g.groupid) === parseInt(groupId)
    )
    if (contextGroup) return contextGroup.arrival
    if (message.groups[0]) return message.groups[0].arrival
  }
  return message.arrival
}
```

- [ ] **Step 2: Update callers to pass contextGroupId**

- [ ] **Step 3: Run vitest, commit**

```bash
cd iznik-nuxt3 && git add modtools/composables/useModMessages.js
git commit -m "feat: sort mod messages by contextual group arrival time"
```

---

## Task 17: Nuxt — Non-Mod Components

**Files:**
- Modify: `iznik-nuxt3/components/MyMessage.vue:796,827,907`
- Modify: `iznik-nuxt3/components/OutcomeModal.vue:301-308`
- Modify: `iznik-nuxt3/components/MessageReportModal.vue:147`
- Modify: `iznik-nuxt3/components/ExportPost.vue:9-10`
- Modify: `iznik-nuxt3/modtools/components/ModLog.vue:78-86`

- [ ] **Step 1: MyMessage.vue — show all groups**

Lines 796, 827, 907 reference `groups[0].groupid`. For MyMessage (the poster's view), the poster sees all their groups. Update the edit flow to use the first group (global edit doesn't need a specific group), but display all groups.

- [ ] **Step 2: OutcomeModal.vue — remove groupid dependency**

Outcomes are global. The groupid computed (lines 301-308) is used for... check what it's used for. If it's only passed to the API, and the API doesn't need it for outcomes, remove it.

- [ ] **Step 3: MessageReportModal.vue — report to all groups**

Line 147 opens a chat to mods of `groups[0].groupid`. For multi-group, the report should notify all groups' mods. The API `handleReport` should be updated to queue notifications for all groups (Task 9 area). The client just needs to trigger the report — the backend handles notification fanout.

- [ ] **Step 4: ExportPost.vue — show all group names**

```vue
<span v-for="(g, idx) in post.groups" :key="g.groupid">
  {{ g.namedisplay }}<span v-if="idx < post.groups.length - 1">, </span>
</span>
```

- [ ] **Step 5: ModLog.vue — show all groups**

Replace `groups[0].collection === 'Pending'` check (line 80-85) with a check that shows the collection for the contextual group, or shows all groups' statuses.

- [ ] **Step 6: Run vitest, commit**

```bash
cd iznik-nuxt3
git add components/MyMessage.vue components/OutcomeModal.vue components/MessageReportModal.vue components/ExportPost.vue modtools/components/ModLog.vue
git commit -m "feat: non-mod components handle multi-group messages"
```

---

## Task 18: Digest Dedup

**Files:**
- Modify: `iznik-batch/app/Services/UnifiedDigestService.php`

The `UnifiedDigestService` already has `deduplicatePosts()` with TN dedup via `tnpostid` (line 288). This should already handle multi-group messages correctly since it deduplicates by `tnpostid` or `fromuser|subject|location`. Verify and add a test.

- [ ] **Step 1: Verify existing dedup handles multi-group**

Read `deduplicatePosts()` — it groups by dedup key and collects `postedToGroups`. When the same `messages.id` appears for multiple groups (because of multi-group `messages_groups` rows), the dedup key will match and groups will be aggregated. This should work.

- [ ] **Step 2: Write test for same-message multi-group dedup**

```php
public function test_deduplication_with_same_message_on_multiple_groups(): void
{
    // Create a single message on two groups (the new multi-group model).
    // The digest query joins messages with messages_groups, so the same message
    // appears twice with different groupids. deduplicatePosts should merge them.
    
    // ... test setup creating a message with two messages_groups rows
    // ... verify deduplicatePosts returns it once with both groups in postedToGroups
}
```

- [ ] **Step 3: Run test, commit**

```bash
cd iznik-batch && git add app/Services/UnifiedDigestService.php tests/
git commit -m "test: verify digest dedup handles multi-group messages"
```

---

## Task 19: Stats Audit

**Files:** Various — this is an investigation task.

Audit all stats queries to determine impact of multi-group messages on counts.

- [ ] **Step 1: Find all stats queries**

Search for `COUNT(*)` and `COUNT(DISTINCT` in Go, PHP, and Laravel code that reference `messages` or `messages_groups`.

- [ ] **Step 2: Categorise each query**

For each: does it count `messages.id` (would undercount after dedup) or `messages_groups` rows (correct for per-group stats)?

- [ ] **Step 3: Document findings**

Write findings to `plans/multi-group-stats-audit.md`. Flag any queries that need changing.

- [ ] **Step 4: Fix any broken queries**

- [ ] **Step 5: Commit**

```bash
git add plans/multi-group-stats-audit.md
git commit -m "docs: stats audit for multi-group messages impact"
```

---

## Task 20: Schema Cleanup — Drop Old Columns

**Files:**
- Create: `iznik-batch/database/migrations/YYYY_MM_DD_000001_drop_per_group_columns_from_messages.php`

**Only run this after all code in Tasks 3-17 is deployed and stable.** This is the final cleanup.

- [ ] **Step 1: Verify no code reads from messages.heldby/spamtype/spamreason**

Search Go, PHP, and Laravel for `messages.heldby`, `messages.spamtype`, `messages.spamreason`. Should find zero hits outside of the migration itself.

- [ ] **Step 2: Write the cleanup migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['heldby']);
            $table->dropColumn(['heldby', 'spamtype', 'spamreason']);
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->unsignedBigInteger('heldby')->nullable();
            $table->string('spamtype', 50)->nullable();
            $table->string('spamreason', 255)->nullable();
            $table->foreign('heldby')->references('id')->on('users')->onDelete('set null');
        });
    }
};
```

- [ ] **Step 3: Run migration, verify, commit**

```bash
cd iznik-batch && git add database/migrations/*drop_per_group_columns*
git commit -m "cleanup: drop heldby/spamtype/spamreason from messages table (now on messages_groups)"
```

---

## Task 21: Nuxt — Message Report Uses Best Shared Group

**Files:**
- Modify: `iznik-nuxt3/components/MessageReportModal.vue:147`

When a user reports a message, the report should go to a single group — the group that both the user and the message share, choosing the most recently posted one. Currently it uses `groups[0].groupid` which may not be a group the reporter is on.

- [ ] **Step 1: Update MessageReportModal.vue**

Replace line 147's `message.value.groups[0].groupid` with logic to find the best shared group:

```javascript
const reportGroupId = computed(() => {
  if (!message.value?.groups?.length) return null
  const myGroups = meStore.me?.groups?.map(g => g.id) || []
  // Find groups that both the user and the message are on, sorted by most recent arrival.
  const shared = message.value.groups
    .filter(g => myGroups.includes(parseInt(g.groupid)))
    .sort((a, b) => new Date(b.arrival) - new Date(a.arrival))
  return shared.length > 0 ? shared[0].groupid : message.value.groups[0].groupid
})
```

Use `reportGroupId.value` in the `openChatToMods` call.

- [ ] **Step 2: Write vitest**

Test that when a message is on groups A and B, and the user is only on group B, the report goes to group B's mods.

- [ ] **Step 3: Run vitest, commit**

```bash
cd iznik-nuxt3 && git add components/MessageReportModal.vue tests/
git commit -m "feat: message report targets the shared group with most recent posting"
```

---

## Task 22: V1 PHP Audit Confirmation

**Files:** None — this is a verification task.

Cross-reference the V1 PHP audit (from the design spec) against V2 Go code to confirm all identified gaps are covered.

- [ ] **Step 1: Verify each V1 gap has V2 coverage**

| V1 Issue | V2 Task | Status |
|----------|---------|--------|
| `reject()` updates ALL groups | Already correct (takes groupid) | Verify |
| `sendForReview()` updates ALL groups | Task 10 | Verify |
| `autoapprove()` deletes all groups | Search for autoapprove in Go | Verify |
| `move()` deletes all, inserts one | Task 6 redesign | Verify |
| `spam()` is global delete | Task 7 | Verify |
| `ModBot` uses first group for rules | Search for modbot/automod in Go | Verify |

- [ ] **Step 2: Search for autoapprove logic in Go**

```bash
docker exec freegle-apiv2 grep -rn "autoapprove\|autoApprove\|auto.approve" --include="*.go" .
```

If found, verify it handles per-group correctly. If not found, note as not-yet-migrated.

- [ ] **Step 3: Search for modbot/automod logic in Go**

```bash
docker exec freegle-apiv2 grep -rn "modbot\|ModBot\|automod\|AutoMod" --include="*.go" .
```

- [ ] **Step 4: Document findings**

Write to `plans/multi-group-v1-audit-results.md`.

- [ ] **Step 5: Commit**

```bash
git add plans/multi-group-v1-audit-results.md
git commit -m "docs: V1 audit confirmation for multi-group messages"
```

---

## Execution Notes

**Backwards compatibility during rollout:** Tasks 4, 5, and 8 include a dual-write to both `messages.heldby` and `messages_groups.heldby`. This keeps V1 PHP code working during the transition. Task 20 removes the old columns only after V1 is fully retired.

**Testing note:** The Go tests cannot be run from WSL directly. Use `docker exec freegle-apiv2 go test ./test/...` to run inside the container.

**Dependencies between tasks:**
- Tasks 1-2 (schema) must be done first
- Tasks 3-11 (Go API) depend on Tasks 1-2 but are independent of each other
- Task 12 (TN dedup) depends on Tasks 1-2
- Tasks 13-17 (Nuxt) can be done in parallel with Go tasks, but should be tested after Go changes are deployed
- Task 18 (digest) depends on Tasks 1-2
- Task 19 (stats) can be done at any time
- Task 20 (cleanup) must be last — after all code deployed and V1 retired
- Task 21 (reports) depends on Tasks 1-2, independent of other Go tasks
- Task 22 (V1 audit) can be done at any time, good to do early as a sanity check
