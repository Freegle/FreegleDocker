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
