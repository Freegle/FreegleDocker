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
