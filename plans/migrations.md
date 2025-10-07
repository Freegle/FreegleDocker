# Database Migrations

SQL migrations to be executed on live server. Always backup first, test on staging, deploy code changes before/after as noted.

---

## Pending Migrations

### SMS Notifications Removal
**Related plan:** remove-sms-notifications.md
**Prerequisites:** Deploy code changes to remove SMS functionality FIRST

```sql
-- Drop users_phones table (stores phone numbers for SMS - no longer used)
DROP TABLE IF EXISTS `users_phones`;
```

---

## Completed Migrations

(Migrations moved here after execution on production with date)
