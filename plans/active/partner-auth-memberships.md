# Partner Auth for Memberships — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add partner key authentication to the Go API membership endpoints so Trash Nothing can subscribe/unsubscribe users via API key + tnuserid/email, matching the V1 PHP behavior.

**Architecture:** A shared `user/partner.go` provides partner key validation and user lookup helpers. The existing `PutMemberships` and `DeleteMemberships` handlers gain a partner auth path that runs before JWT auth — if a `partner` query param is present, the partner path handles the request entirely.

**Tech Stack:** Go, Fiber, GORM, MySQL

**Note:** Partner consent on messages (`POST /message` with `PartnerConsent` action) is already implemented in `message/message.go:1699` and wired into the dispatcher at line 2827. No work needed there.

---

## File Map

| File | Action | Responsibility |
|------|--------|---------------|
| `iznik-server-go/user/partner.go` | Create | Shared partner auth helpers |
| `iznik-server-go/membership/membership.go` | Modify (lines 1008-1081 and 1090-1145) | Add partner path to PUT/DELETE handlers |
| `iznik-server-go/test/partner_test.go` | Create | Tests for partner auth helpers |
| `iznik-server-go/test/membership_test.go` | Modify (append) | Tests for partner membership endpoints |

---

## Task 1: Shared Partner Auth Helpers (user/partner.go)

**Files:**
- Create: `iznik-server-go/user/partner.go`
- Create: `iznik-server-go/test/partner_test.go`

### Step 1.1: Write failing tests for ValidatePartnerKey

- [ ] Create `iznik-server-go/test/partner_test.go` with tests:

```go
package test

import (
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/stretchr/testify/assert"
)

func TestValidatePartnerKeyValid(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("valkey")
	key := prefix + "_key"
	db.Exec("INSERT INTO partners_keys (partner, `key`, domain) VALUES (?, ?, ?)",
		prefix+"_partner", key, "user.trashnothing.com")
	t.Cleanup(func() { db.Exec("DELETE FROM partners_keys WHERE `key` = ?", key) })

	partnerID, partnerName, domain, err := user.ValidatePartnerKey(db, key)
	assert.NoError(t, err)
	assert.Greater(t, partnerID, uint64(0))
	assert.Contains(t, partnerName, prefix)
	assert.Equal(t, "user.trashnothing.com", domain)
}

func TestValidatePartnerKeyInvalid(t *testing.T) {
	db := database.DBConn
	_, _, _, err := user.ValidatePartnerKey(db, "bogus_key_does_not_exist")
	assert.Error(t, err)
}

func TestFindByTNIdOrEmailByTNId(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("findtn")
	userID := CreateTestUser(t, prefix, "User")
	var tnid uint64 = 99900000 + uint64(userID%10000)
	db.Exec("UPDATE users SET tnuserid = ? WHERE id = ?", tnid, userID)

	found := user.FindByTNIdOrEmail(db, tnid, "")
	assert.Equal(t, userID, found)
}

func TestFindByTNIdOrEmailByEmail(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("findemail")
	userID := CreateTestUser(t, prefix, "User")
	email := prefix + "@test.com"

	found := user.FindByTNIdOrEmail(db, 0, email)
	assert.Equal(t, userID, found)
}

func TestFindByTNIdOrEmailNotFound(t *testing.T) {
	db := database.DBConn
	found := user.FindByTNIdOrEmail(db, 0, "nonexistent@nowhere.test")
	assert.Equal(t, uint64(0), found)
}

func TestCreatePartnerUser(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("createptn")
	email := prefix + "-g1234@user.trashnothing.com"
	var tnid uint64 = 88800000

	uid, err := user.CreatePartnerUser(db, tnid, email)
	assert.NoError(t, err)
	assert.Greater(t, uid, uint64(0))
	t.Cleanup(func() {
		db.Exec("DELETE FROM users_emails WHERE userid = ?", uid)
		db.Exec("DELETE FROM users WHERE id = ?", uid)
	})

	// Verify tnuserid was set.
	var storedTN *uint64
	db.Raw("SELECT tnuserid FROM users WHERE id = ?", uid).Scan(&storedTN)
	assert.NotNil(t, storedTN)
	assert.Equal(t, tnid, *storedTN)

	// Verify email was added.
	var emailCount int64
	db.Raw("SELECT COUNT(*) FROM users_emails WHERE userid = ? AND email = ?", uid, email).Scan(&emailCount)
	assert.Equal(t, int64(1), emailCount)

	// Verify name extracted from email prefix (before -g).
	var fullname string
	db.Raw("SELECT fullname FROM users WHERE id = ?", uid).Scan(&fullname)
	assert.Equal(t, prefix, fullname)
}

func TestCreatePartnerUserNameFromAtSign(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("createptn2")
	email := prefix + "@user.trashnothing.com"
	var tnid uint64 = 88800001

	uid, err := user.CreatePartnerUser(db, tnid, email)
	assert.NoError(t, err)
	assert.Greater(t, uid, uint64(0))
	t.Cleanup(func() {
		db.Exec("DELETE FROM users_emails WHERE userid = ?", uid)
		db.Exec("DELETE FROM users WHERE id = ?", uid)
	})

	// Name should be prefix (extracted at @ since no -g).
	var fullname string
	db.Raw("SELECT fullname FROM users WHERE id = ?", uid).Scan(&fullname)
	assert.Equal(t, prefix, fullname)
}
```

- [ ] Run tests to verify they fail:

```bash
curl -s -X POST http://localhost:8081/api/tests/go
# Check status — expected: build failure (user.ValidatePartnerKey not defined)
```

### Step 1.2: Implement partner helpers

- [ ] Create `iznik-server-go/user/partner.go`:

```go
package user

import (
	"errors"
	"strings"
	"time"

	"gorm.io/gorm"
)

// ValidatePartnerKey checks a partner API key against the partners_keys table.
// Returns partner ID, name, domain, and any error.
func ValidatePartnerKey(db *gorm.DB, key string) (uint64, string, string, error) {
	type partnerRow struct {
		ID      uint64 `gorm:"column:id"`
		Partner string `gorm:"column:partner"`
		Domain  string `gorm:"column:domain"`
	}

	var p partnerRow
	db.Raw("SELECT id, partner, domain FROM partners_keys WHERE `key` = ?", key).Scan(&p)

	if p.ID == 0 {
		return 0, "", "", errors.New("invalid partner key")
	}

	return p.ID, p.Partner, p.Domain, nil
}

// FindByTNIdOrEmail looks up a user by Trash Nothing user ID first, then by email.
// Returns user ID or 0 if not found.
func FindByTNIdOrEmail(db *gorm.DB, tnuserid uint64, email string) uint64 {
	var uid uint64

	if tnuserid > 0 {
		db.Raw("SELECT id FROM users WHERE tnuserid = ? LIMIT 1", tnuserid).Scan(&uid)
		if uid > 0 {
			return uid
		}
	}

	if email != "" {
		db.Raw("SELECT userid FROM users_emails WHERE email = ? LIMIT 1", email).Scan(&uid)
	}

	return uid
}

// CreatePartnerUser creates a new user for a partner integration.
// Extracts display name from the email prefix (before -g or @).
// Sets tnuserid and adds the email address.
func CreatePartnerUser(db *gorm.DB, tnuserid uint64, email string) (uint64, error) {
	// Extract name from email: take part before -g (TN convention) or before @.
	name := email
	if atIdx := strings.LastIndex(name, "@"); atIdx > 0 {
		name = name[:atIdx]
	}
	if gIdx := strings.LastIndex(name, "-g"); gIdx > 0 {
		name = name[:gIdx]
	}

	// Create user record.
	now := time.Now()
	result := db.Exec(
		"INSERT INTO users (fullname, tnuserid, lastaccess, added, systemrole) VALUES (?, ?, ?, ?, 'User')",
		name, tnuserid, now, now,
	)
	if result.Error != nil {
		return 0, result.Error
	}

	var uid uint64
	db.Raw("SELECT id FROM users WHERE tnuserid = ? ORDER BY id DESC LIMIT 1", tnuserid).Scan(&uid)
	if uid == 0 {
		return 0, errors.New("user created but ID not found")
	}

	// Add email.
	db.Exec("INSERT INTO users_emails (userid, email) VALUES (?, ?)", uid, email)

	return uid, nil
}
```

### Step 1.3: Run tests and verify they pass

- [ ] Trigger Go tests:

```bash
curl -s -X POST http://localhost:8081/api/tests/go
```

Expected: all partner_test.go tests pass.

### Step 1.4: Commit

- [ ] Commit:

```bash
git add iznik-server-go/user/partner.go iznik-server-go/test/partner_test.go
git commit -m "feat: add shared partner auth helpers for TN integration"
```

---

## Task 2: PUT /api/memberships — Partner Subscribe

**Files:**
- Modify: `iznik-server-go/membership/membership.go` (PutMemberships, lines 1008-1081)
- Modify: `iznik-server-go/test/membership_test.go` (append tests)

### Step 2.1: Write failing tests

- [ ] Append to `iznik-server-go/test/membership_test.go`:

```go
// =============================================================================
// Partner Auth Membership Tests
// =============================================================================

func TestPutMembershipsPartnerSubscribe(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("ptnsub")

	groupID := CreateTestGroup(t, prefix)
	partnerKey := prefix + "_key"
	partnerDomain := "user.trashnothing.com"
	db.Exec("INSERT INTO partners_keys (partner, `key`, domain) VALUES (?, ?, ?)",
		prefix+"_partner", partnerKey, partnerDomain)
	t.Cleanup(func() { db.Exec("DELETE FROM partners_keys WHERE `key` = ?", partnerKey) })

	// Create existing user with tnuserid.
	userID := CreateTestUser(t, prefix, "User")
	var tnid uint64 = 77700000 + uint64(userID%10000)
	db.Exec("UPDATE users SET tnuserid = ? WHERE id = ?", tnid, userID)
	email := prefix + "-g1234@" + partnerDomain

	// Add the partner-domain email to the user.
	db.Exec("INSERT INTO users_emails (userid, email) VALUES (?, ?)", userID, email)

	body := fmt.Sprintf(`{"groupid":%d}`, groupID)
	url := fmt.Sprintf("/api/memberships?partner=%s&tnuserid=%d&email=%s",
		partnerKey, tnid, email)
	req := httptest.NewRequest("PUT", url, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&result)
	assert.Equal(t, float64(0), result["ret"])
	assert.Equal(t, float64(userID), result["fduserid"])

	// Verify membership was created.
	var memberCount int64
	db.Raw("SELECT COUNT(*) FROM memberships WHERE userid = ? AND groupid = ?", userID, groupID).Scan(&memberCount)
	assert.Equal(t, int64(1), memberCount)
}

func TestPutMembershipsPartnerAutoCreate(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("ptncreat")

	groupID := CreateTestGroup(t, prefix)
	partnerKey := prefix + "_key"
	partnerDomain := "user.trashnothing.com"
	db.Exec("INSERT INTO partners_keys (partner, `key`, domain) VALUES (?, ?, ?)",
		prefix+"_partner", partnerKey, partnerDomain)
	t.Cleanup(func() { db.Exec("DELETE FROM partners_keys WHERE `key` = ?", partnerKey) })

	var tnid uint64 = 77700099
	email := prefix + "-g5678@" + partnerDomain

	body := fmt.Sprintf(`{"groupid":%d}`, groupID)
	url := fmt.Sprintf("/api/memberships?partner=%s&tnuserid=%d&email=%s",
		partnerKey, tnid, email)
	req := httptest.NewRequest("PUT", url, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&result)
	assert.Equal(t, float64(0), result["ret"])
	fduserid := uint64(result["fduserid"].(float64))
	assert.Greater(t, fduserid, uint64(0))
	t.Cleanup(func() {
		db.Exec("DELETE FROM memberships WHERE userid = ?", fduserid)
		db.Exec("DELETE FROM users_emails WHERE userid = ?", fduserid)
		db.Exec("DELETE FROM users WHERE id = ?", fduserid)
	})

	// Verify membership.
	var memberCount int64
	db.Raw("SELECT COUNT(*) FROM memberships WHERE userid = ? AND groupid = ?", fduserid, groupID).Scan(&memberCount)
	assert.Equal(t, int64(1), memberCount)
}

func TestPutMembershipsPartnerWrongDomain(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("ptnwrong")

	groupID := CreateTestGroup(t, prefix)
	partnerKey := prefix + "_key"
	db.Exec("INSERT INTO partners_keys (partner, `key`, domain) VALUES (?, ?, ?)",
		prefix+"_partner", partnerKey, "user.trashnothing.com")
	t.Cleanup(func() { db.Exec("DELETE FROM partners_keys WHERE `key` = ?", partnerKey) })

	email := prefix + "@wrong-domain.com"
	body := fmt.Sprintf(`{"groupid":%d}`, groupID)
	url := fmt.Sprintf("/api/memberships?partner=%s&tnuserid=0&email=%s",
		partnerKey, email)
	req := httptest.NewRequest("PUT", url, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 403, resp.StatusCode)
}

func TestPutMembershipsPartnerInvalidKey(t *testing.T) {
	groupID := uint64(1)
	body := fmt.Sprintf(`{"groupid":%d}`, groupID)
	url := fmt.Sprintf("/api/memberships?partner=%s&tnuserid=1&email=x@y.com", "bogus_key")
	req := httptest.NewRequest("PUT", url, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 403, resp.StatusCode)
}
```

- [ ] Run tests — expected: fail (partner path not implemented in PutMemberships).

### Step 2.2: Implement partner auth in PutMemberships

- [ ] Modify `iznik-server-go/membership/membership.go`. Replace the `PutMemberships` function. The partner path runs first — if `partner` query param is present, handle entirely via partner auth. Otherwise fall through to existing JWT path unchanged.

New `PutMemberships`:

```go
func PutMemberships(c *fiber.Ctx) error {
	db := database.DBConn

	// --- Partner auth path ---
	partnerKey := c.Query("partner", "")
	if partnerKey != "" {
		_, _, domain, err := user.ValidatePartnerKey(db, partnerKey)
		if err != nil {
			return fiber.NewError(fiber.StatusForbidden, "Invalid partner key")
		}

		var req PutMembershipsRequest
		if err := c.BodyParser(&req); err != nil {
			return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
		}
		if req.Groupid == 0 {
			return fiber.NewError(fiber.StatusBadRequest, "groupid is required")
		}

		// Check group exists.
		var groupExists int64
		db.Raw("SELECT COUNT(*) FROM `groups` WHERE id = ?", req.Groupid).Scan(&groupExists)
		if groupExists == 0 {
			return fiber.NewError(fiber.StatusNotFound, "Group not found")
		}

		email := c.Query("email", "")
		tnuseridStr := c.Query("tnuserid", "0")
		tnuserid, _ := strconv.ParseUint(tnuseridStr, 10, 64)

		// Validate email domain matches partner domain.
		if !strings.Contains(email, "@"+domain) {
			return fiber.NewError(fiber.StatusForbidden, "Permission denied")
		}

		// Look up user by tnuserid, then email.
		uid := user.FindByTNIdOrEmail(db, tnuserid, email)

		// Auto-create if not found.
		if uid == 0 {
			var createErr error
			uid, createErr = user.CreatePartnerUser(db, tnuserid, email)
			if createErr != nil {
				return fiber.NewError(fiber.StatusInternalServerError, "Failed to create user")
			}
		} else {
			// Ensure this email is on the user (V1 calls addEmail).
			db.Exec("INSERT IGNORE INTO users_emails (userid, email) VALUES (?, ?)", uid, email)
		}

		// Check if banned — return silent success.
		var bannedCount int64
		db.Raw("SELECT COUNT(*) FROM users_banned WHERE userid = ? AND groupid = ?", uid, req.Groupid).Scan(&bannedCount)
		if bannedCount > 0 {
			return c.JSON(fiber.Map{"ret": 4, "status": "Failed - likely ban"})
		}

		// Check if already a member.
		var existingRole string
		db.Raw("SELECT role FROM memberships WHERE userid = ? AND groupid = ?", uid, req.Groupid).Scan(&existingRole)
		if existingRole != "" {
			return c.JSON(fiber.Map{"ret": 0, "status": "Success", "fduserid": uid})
		}

		// Get email ID for membership.
		var emailid uint64
		db.Raw("SELECT id FROM users_emails WHERE userid = ? ORDER BY preferred DESC, id ASC LIMIT 1", uid).Scan(&emailid)

		// Insert membership as approved.
		result := db.Exec("INSERT INTO memberships (userid, groupid, role, collection) VALUES (?, ?, ?, ?)",
			uid, req.Groupid, utils.ROLE_MEMBER, utils.COLLECTION_APPROVED)
		if result.RowsAffected > 0 {
			logMembershipAction(log.LOG_TYPE_GROUP, log.LOG_SUBTYPE_JOINED, req.Groupid, uid, uid, "")
		}

		return c.JSON(fiber.Map{"ret": 0, "status": "Success", "fduserid": uid})
	}

	// --- JWT auth path (existing behavior, unchanged) ---
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	var req PutMembershipsRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	if req.Groupid == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "groupid is required")
	}

	userid := req.Userid
	if userid == 0 {
		userid = myid
	}

	if userid != myid {
		return fiber.NewError(fiber.StatusForbidden, "Cannot add another user")
	}

	// Check the group exists.
	var groupExists int64
	db.Raw("SELECT COUNT(*) FROM `groups` WHERE id = ?", req.Groupid).Scan(&groupExists)
	if groupExists == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Group not found")
	}

	var existingRole string
	db.Raw("SELECT role FROM memberships WHERE userid = ? AND groupid = ?",
		userid, req.Groupid).Scan(&existingRole)
	if existingRole != "" {
		return c.JSON(fiber.Map{"ret": 0, "status": "Success", "addedto": "Approved"})
	}

	var bannedCount int64
	db.Raw("SELECT COUNT(*) FROM users_banned WHERE userid = ? AND groupid = ?",
		userid, req.Groupid).Scan(&bannedCount)
	if bannedCount > 0 {
		return c.JSON(fiber.Map{"ret": 0, "status": "Success", "addedto": utils.COLLECTION_APPROVED})
	}

	var emailid uint64
	db.Raw("SELECT id FROM users_emails WHERE userid = ? ORDER BY preferred DESC, id ASC LIMIT 1",
		userid).Scan(&emailid)

	result := db.Exec("INSERT INTO memberships (userid, groupid, role, collection) VALUES (?, ?, ?, ?)",
		userid, req.Groupid, utils.ROLE_MEMBER, utils.COLLECTION_APPROVED)
	if result.RowsAffected > 0 {
		logMembershipAction(log.LOG_TYPE_GROUP, log.LOG_SUBTYPE_JOINED, req.Groupid, userid, userid, "")
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success", "addedto": utils.COLLECTION_APPROVED})
}
```

Note: also add `"strconv"` and `"strings"` to the import block in membership.go if not already present.

### Step 2.3: Run tests and verify they pass

- [ ] Trigger Go tests, verify partner subscribe tests pass.

### Step 2.4: Commit

```bash
git add iznik-server-go/membership/membership.go iznik-server-go/test/membership_test.go
git commit -m "feat: add partner auth to PUT /memberships for TN subscribe"
```

---

## Task 3: DELETE /api/memberships — Partner Unsubscribe

**Files:**
- Modify: `iznik-server-go/membership/membership.go` (DeleteMemberships, lines 1090-1145)
- Modify: `iznik-server-go/test/membership_test.go` (append tests)

### Step 3.1: Write failing tests

- [ ] Append to `iznik-server-go/test/membership_test.go`:

```go
func TestDeleteMembershipsPartnerUnsubscribe(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("ptnunsub")

	groupID := CreateTestGroup(t, prefix)
	partnerKey := prefix + "_key"
	partnerDomain := "user.trashnothing.com"
	db.Exec("INSERT INTO partners_keys (partner, `key`, domain) VALUES (?, ?, ?)",
		prefix+"_partner", partnerKey, partnerDomain)
	t.Cleanup(func() { db.Exec("DELETE FROM partners_keys WHERE `key` = ?", partnerKey) })

	userID := CreateTestUser(t, prefix, "User")
	var tnid uint64 = 66600000 + uint64(userID%10000)
	db.Exec("UPDATE users SET tnuserid = ? WHERE id = ?", tnid, userID)
	email := prefix + "-g9999@" + partnerDomain
	db.Exec("INSERT INTO users_emails (userid, email) VALUES (?, ?)", userID, email)

	// Add membership first.
	CreateTestMembership(t, userID, groupID, "Member")

	body := fmt.Sprintf(`{"groupid":%d}`, groupID)
	url := fmt.Sprintf("/api/memberships?partner=%s&tnuserid=%d&email=%s",
		partnerKey, tnid, email)
	req := httptest.NewRequest("DELETE", url, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&result)
	assert.Equal(t, float64(0), result["ret"])
	assert.Equal(t, float64(userID), result["fduserid"])

	// Verify membership removed.
	var memberCount int64
	db.Raw("SELECT COUNT(*) FROM memberships WHERE userid = ? AND groupid = ?", userID, groupID).Scan(&memberCount)
	assert.Equal(t, int64(0), memberCount)
}

func TestDeleteMembershipsPartnerUserNotFound(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("ptnunsub404")

	groupID := CreateTestGroup(t, prefix)
	partnerKey := prefix + "_key"
	db.Exec("INSERT INTO partners_keys (partner, `key`, domain) VALUES (?, ?, ?)",
		prefix+"_partner", partnerKey, "user.trashnothing.com")
	t.Cleanup(func() { db.Exec("DELETE FROM partners_keys WHERE `key` = ?", partnerKey) })

	email := "nobody@user.trashnothing.com"
	body := fmt.Sprintf(`{"groupid":%d}`, groupID)
	url := fmt.Sprintf("/api/memberships?partner=%s&tnuserid=0&email=%s",
		partnerKey, email)
	req := httptest.NewRequest("DELETE", url, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	// V1 returns {"ret": 3, "status": "User not found"} — we return 404.
	assert.Equal(t, 404, resp.StatusCode)
}

func TestDeleteMembershipsPartnerWrongDomain(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("ptnunsubdom")

	groupID := CreateTestGroup(t, prefix)
	partnerKey := prefix + "_key"
	db.Exec("INSERT INTO partners_keys (partner, `key`, domain) VALUES (?, ?, ?)",
		prefix+"_partner", partnerKey, "user.trashnothing.com")
	t.Cleanup(func() { db.Exec("DELETE FROM partners_keys WHERE `key` = ?", partnerKey) })

	email := prefix + "@wrong-domain.com"
	body := fmt.Sprintf(`{"groupid":%d}`, groupID)
	url := fmt.Sprintf("/api/memberships?partner=%s&tnuserid=0&email=%s",
		partnerKey, email)
	req := httptest.NewRequest("DELETE", url, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 403, resp.StatusCode)
}
```

### Step 3.2: Implement partner auth in DeleteMemberships

- [ ] Replace `DeleteMemberships` in `iznik-server-go/membership/membership.go`:

```go
func DeleteMemberships(c *fiber.Ctx) error {
	db := database.DBConn

	// --- Partner auth path ---
	partnerKey := c.Query("partner", "")
	if partnerKey != "" {
		_, _, domain, err := user.ValidatePartnerKey(db, partnerKey)
		if err != nil {
			return fiber.NewError(fiber.StatusForbidden, "Invalid partner key")
		}

		var req DeleteMembershipsRequest
		if err := c.BodyParser(&req); err != nil {
			return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
		}
		if req.Groupid == 0 {
			return fiber.NewError(fiber.StatusBadRequest, "groupid is required")
		}

		email := c.Query("email", "")
		tnuseridStr := c.Query("tnuserid", "0")
		tnuserid, _ := strconv.ParseUint(tnuseridStr, 10, 64)

		// Validate email domain.
		if !strings.Contains(email, "@"+domain) {
			return fiber.NewError(fiber.StatusForbidden, "Permission denied")
		}

		uid := user.FindByTNIdOrEmail(db, tnuserid, email)
		if uid == 0 {
			return fiber.NewError(fiber.StatusNotFound, "User not found")
		}

		// Remove membership.
		db.Exec("DELETE FROM memberships WHERE userid = ? AND groupid = ?", uid, req.Groupid)

		return c.JSON(fiber.Map{"ret": 0, "status": "Success", "fduserid": uid})
	}

	// --- JWT auth path (existing behavior, unchanged) ---
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	var req DeleteMembershipsRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	if req.Groupid == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "groupid is required")
	}

	userid := req.Userid
	if userid == 0 {
		userid = myid
	}

	if req.Ban != nil && *req.Ban {
		if !isModOfGroup(myid, req.Groupid) {
			return fiber.NewError(fiber.StatusForbidden, "Not a moderator of this group")
		}
		db.Exec("DELETE FROM memberships WHERE userid = ? AND groupid = ?", userid, req.Groupid)
		db.Exec("INSERT INTO users_banned (userid, groupid, byuser) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE byuser = VALUES(byuser), date = NOW()",
			userid, req.Groupid, myid)
		logMembershipAction(log.LOG_TYPE_GROUP, log.LOG_SUBTYPE_LEFT, req.Groupid, userid, myid, "via ban")
		return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
	}

	if userid != myid {
		if !isModOfGroup(myid, req.Groupid) {
			return fiber.NewError(fiber.StatusForbidden, "Not a moderator of this group")
		}
		logMembershipAction(log.LOG_TYPE_USER, log.LOG_SUBTYPE_DELETED, req.Groupid, userid, myid, "")
	}

	result := db.Exec("DELETE FROM memberships WHERE userid = ? AND groupid = ? AND collection = ?",
		userid, req.Groupid, utils.COLLECTION_APPROVED)
	if result.RowsAffected == 0 {
		return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}
```

### Step 3.3: Run tests and verify they pass

- [ ] Trigger Go tests, verify partner unsubscribe tests pass.

### Step 3.4: Commit

```bash
git add iznik-server-go/membership/membership.go iznik-server-go/test/membership_test.go
git commit -m "feat: add partner auth to DELETE /memberships for TN unsubscribe"
```

---

## Task 4: Rebuild, Run Full Suite, Final Commit

### Step 4.1: Rebuild apiv2 container

- [ ] Rebuild and restart:

```bash
docker-compose build apiv2 && docker-compose up -d apiv2
```

### Step 4.2: Run full Go test suite

- [ ] Run all tests via status API:

```bash
curl -s -X POST http://localhost:8081/api/tests/go
```

Expected: all tests pass including new partner tests.

### Step 4.3: Verify no regressions

- [ ] Check that existing membership tests still pass (self-join, self-leave, ban).
- [ ] Check that existing partner consent test (TestPostMessagePartnerConsent) still passes.
