# Plan: TN Sync Shadow Testing

## TL;DR

Add archive-and-replay infrastructure to validate that iznik-batch's `TNSyncCommand` produces identical results to iznik-server's `tn_sync.php`, following the same pattern as the incoming email migration. The legacy PHP code archives each TN API item alongside pre-action DB state and the action taken; a new Laravel replay command processes the same items using the archived state and compares decisions.

---

### Phase 1: Archive Producer (iznik-server)

1. **Add `saveTNSyncItem()` helper** to `tn_sync.php` — accumulates per-item records (API data + pre-action DB state + action taken) during the sync run
2. **Instrument ratings loop** — capture pre-action state (`user_exists`) and action (`UPSERT_RATING` / `DELETE_RATING` / `SKIP`) for each rating. *Depends on 1*
3. **Instrument user-changes loop** — capture pre-action state (`user_exists`, `is_tn`, `fullname`, `emails[]`, `locationid`) and all actions (`UPSERT_REPLY_TIME`, `UPDATE_NAME`, `REPLACE_EMAIL`, `UPDATE_LOCATION`, `FORGET`, etc.) per change. *Depends on 1*
4. **Instrument duplicate merge phase** — capture detection results (username + user IDs) and merge actions. *Depends on 1*
5. **Add `writeTNSyncArchive()`** — at end of sync run, write one JSON archive file to `/var/lib/freegle/tn-sync-archive/{date}/{timestamp}_{random}.json`. Atomic write via temp+rename. Enable/disable by creating/removing the directory. *Depends on 2-4*

### Phase 2: Archive Consumer (iznik-batch)

6. **Create `TNSyncAction` enum** — define action types: `SKIP`, `UPSERT_RATING`, `DELETE_RATING`, `FORGET_USER`, `UPSERT_REPLY_TIME`, `UPSERT_ABOUT_ME`, `UPDATE_NAME`, `REPLACE_EMAIL`, `UPDATE_LOCATION`, `MERGE`. *Parallel with Phase 1*
7. **Extract `TNSyncDecisionService`** — pure methods taking API item + pre-action state, returning action(s). No DB reads:
   - `decideRatingAction(array $rating, array $preState): array`
   - `decideUserChangeActions(array $change, array $preState): array`
   - `decideMergeActions(array $duplicateGroup): array`
   - *Depends on 6*
8. **Refactor `TNSyncCommand`** to call `TNSyncDecisionService` for decisions, then execute them. Ensures replay tests the exact same logic production uses. *Depends on 7*
9. **Create `ReplayTNSyncArchiveCommand`** (`php artisan tn:replay-archive {path} {--limit=0} {--stop-on-mismatch} {--output=table|json|summary}`):
   - Reads archive files, calls decision service with archived item + pre-state
   - Compares computed actions against archived legacy actions
   - Reports matches/mismatches/errors with percentages
   - *Depends on 7, 8*

### Phase 3: Validation & Deployment

10. **Create archive directory** on production. *Depends on 5*
11. **Accumulate archives** over hours/days while legacy sync runs.
12. **Run replay** against archives, investigate mismatches, fix bugs, re-run until 100% match. *Depends on 9, 11*
13. **Cut over** cron from `tn_sync.php` to `php artisan tn:sync`.
14. **Cleanup** — remove archiving code, replay command, decision service if no longer needed separately.

---

### Archive Format

One JSON file per sync run, saved to `/var/lib/freegle/tn-sync-archive/{date}/{HHmmss}_{random}.json`:

```json
{
  "version": 1,
  "timestamp": "2026-03-19T12:00:00Z",
  "sync_from": "...",
  "sync_to": "...",
  "ratings": [
    {
      "item": { "...raw TN API rating object..." },
      "pre_state": {
        "user_exists": true,
        "user_id": 12345
      },
      "legacy_action": {
        "type": "UPSERT_RATING",
        "ratee": 12345,
        "rating": 5,
        "tn_rating_id": 67890,
        "date": "2026-03-19T11:30:00Z"
      }
    }
  ],
  "user_changes": [
    {
      "item": { "...raw TN API change object..." },
      "pre_state": {
        "user_exists": true,
        "user_id": 456,
        "is_tn": true,
        "fullname": "Alice-g298",
        "emails": ["alice-g298@user.trashnothing.com", "alice-g301@user.trashnothing.com"],
        "locationid": 789
      },
      "legacy_actions": [
        {"type": "UPSERT_REPLY_TIME", "userid": 456, "replytime": 3600, "date": "..."},
        {"type": "UPDATE_NAME", "userid": 456, "old_name": "Alice", "new_name": "Bob"},
        {"type": "REPLACE_EMAIL", "old": "alice-g298@...", "new": "bob-g298@..."}
      ]
    }
  ],
  "merges": [
    {
      "username": "charlie",
      "user_ids": [100, 200, 300],
      "legacy_actions": [
        {"type": "MERGE", "from": 200, "into": 100},
        {"type": "MERGE", "from": 300, "into": 100}
      ]
    }
  ]
}
```

---

### Relevant Files

- `iznik-server/scripts/cron/tn_sync.php` — legacy sync script to add archiving to (steps 1-5)
- `iznik-server/scripts/incoming/incoming.php` — reference implementation of `saveIncomingArchive()` pattern
- `iznik-server/include/user/User.php` — `isTN()`, `removeTNGroup()`, `getEmails()`, `getName()`, `getPrivate('locationid')` methods used in pre-state capture
- `iznik-batch/app/Console/Commands/TrashNothing/TNSyncCommand.php` — new sync command to refactor (step 8)
- `iznik-batch/app/Console/Commands/ReplayIncomingArchiveCommand.php` — deleted reference (commit `786ad113`) for replay command structure
- `iznik-batch/app/Services/Mail/Incoming/IncomingMailService.php` — reference for `routeDryRun()` pattern
- `iznik-batch/config/freegle.php` — TN config (api_key, api_base_url, sync_date_file)

### Verification

1. **Unit test `TNSyncDecisionService`**: Test each decide* method with known inputs → expected actions. Cover edge cases: user doesn't exist, user not TN, rating=0 (delete), name change with multiple emails, location change to closest postcode, account removal.
2. **Integration test archive format**: Create a sample archive JSON, verify `ReplayTNSyncArchiveCommand` parses it correctly and produces expected comparison output.
3. **Production validation**: Run replay against real archives accumulated over 24+ hours. Target: 100% match rate on ratings, 100% on user-changes, 100% on merges.
4. **Manual spot-check**: For any mismatches, manually verify which code is correct by inspecting the raw TN API item + DB state.

### Decisions

- Pre-action DB state captured in archive to avoid timing issues
- All 3 phases (ratings, user-changes, merges) in scope
- Decision logic extracted into testable pure functions shared by both `TNSyncCommand` and replay command
- One archive file per sync run; directory-toggle pattern for enable/disable

### Further Considerations

1. **Email change ordering** — name changes produce multiple `REPLACE_EMAIL` actions. Compare as sorted sets, not ordered lists, to avoid false mismatches.
2. **`closestPostcode()` ties** — if two postcodes are equidistant, legacy and new code may pick differently. Flag as potential false mismatch if within 0.1km.
3. **Merge target ordering** — both codes use `$userIds[0]` as merge target. Verify both SQL queries return IDs in the same order, or sort before selecting target.
