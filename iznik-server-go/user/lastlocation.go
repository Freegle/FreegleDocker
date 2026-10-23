package user

import (
	log2 "github.com/freegle/iznik-server-go/log"
	"gorm.io/gorm"
)

// SetLastLocation records a member's chosen postcode as users.lastlocation and, when it is a real
// change, writes the same User/PostcodeChange log row that saving settings writes, so the member
// logs show every location change whichever route made it (e.g. posting with a chosen location).
// A repeat of the current location writes nothing, so ordinary posting does not flood the logs.
func SetLastLocation(db *gorm.DB, myid uint64, locationid uint64) {
	var old *uint64
	db.Table("users").Select("lastlocation").Where("id = ?", myid).Scan(&old)
	if old != nil && *old == locationid {
		return
	}

	db.Table("users").Where("id = ?", myid).Update("lastlocation", locationid)

	var name *string
	db.Table("locations").Select("name").Where("id = ?", locationid).Scan(&name)
	log2.Log(log2.LogEntry{
		Type:    log2.LOG_TYPE_USER,
		Subtype: log2.LOG_SUBTYPE_POSTCODECHANGE,
		User:    &myid,
		Byuser:  &myid,
		Text:    name,
	})
}
