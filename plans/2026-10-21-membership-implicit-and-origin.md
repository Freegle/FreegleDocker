# Membership `implicit` flag and `added_origin` enum

Replace `memberships.rippled` with two columns, and move TrashNothing "didn't opt in" from posts to
memberships:

- **`implicit`** (bool, `NOT NULL DEFAULT 0`): 1 = the member has not knowingly joined this
  community. Changes over time: it clears to 0 when they join, move into the area, or engage.
- **`added_origin`** (enum): how the membership row was created. Never changes after insert.

Today `rippled` does both jobs: it says how the row was created, and it is cleared when the
membership becomes ordinary. TN posters who didn't opt in have no membership at all; the
restriction on them is worked out from `messages_groups.mod_messaging_allowed` on their posts.

The steps are in the order they need to happen. Each phase can ship as its own PR.

---

## Phase 0 - Decisions to settle before writing code

- [ ] **The flag's name.** The notes say both `implicit` and "rename `rippled` to mod messaging
  allowed". Use `implicit`: most of the code that reads this flag is not about messaging
  (removal on retraction, the leave guard, stories, attribution), and a contact-specific
  name invites someone to flip it for a contact reason. Add a new column rather than renaming
  `rippled` in place. Go, batch and the frontend deploy separately, so a rename would break
  whichever deploys second.
- [ ] **Enum values.** The proposal below maps each place a membership is inserted to a value.
- [ ] **What a TN membership created at ingestion gets.** Recommended: `implicit = 1`,
  `emailfrequency = 0`, `eventsallowed = 0`, `volunteeringallowed = 0`, so no mail starts.
  Check with Edward whether these members should appear in ModTools member lists and in
  member counts (see Phase 6).
- [ ] **Contact becomes per group instead of per person.** Today
  `UsersUnaddressedOnly` lets moderators of every group contact a TN poster if any one of
  their posts was addressed. A per-membership flag decides it group by group. Confirm with
  whoever made the mixed-poster rule.
- [ ] **A post's status follows the poster's current relationship with the group, not their
  consent when they posted.** A TN post's "nobody owns this" status (reports skip moderators,
  moderators can't edit it) will be read from the poster's membership. When the poster later
  consents, joins or engages, their old posts become moderator-owned.
- [ ] **What happens when a TN implicit membership is removed while the post is live.** Proposed:
  keep the membership while the poster has any live post on the group, as ripple memberships
  already do ([ExpandService.php:1052](../iznik-batch/app/Services/Ripple/ExpandService.php#L1052)).
  Otherwise the post reads as addressed and moderators gain reports and editing on it.
- [ ] **Historical TN backfill scope** (Phase 4b). Create memberships only for posters with a
  live post, or for every TN poster ever? "Every" is large and puts long-gone posters into
  member lists.

### Proposed `added_origin` values

| Value | Set by | Today's log text |
|---|---|---|
| `manual` | Clicked Join: [membership.go:1553](../iznik-server-go/membership/membership.go#L1553) with `manual=true` | `Manual` |
| `posted` | Joined in order to post: [message_submit.go:131](../iznik-server-go/message/message_submit.go#L131) | NULL text |
| `replied` | Joined in order to reply: `useReplyStateMachine.handleJoinGroup` → same endpoint with `manual=false` | `Auto` (shared with other auto-joins) |
| `subscribed` | Email subscribe: [IncomingMailService.php:1275](../iznik-batch/app/Services/Mail/Incoming/IncomingMailService.php#L1275) | `Subscribed` |
| `rippled` | [ExpandService.php:2396](../iznik-batch/app/Services/Ripple/ExpandService.php#L2396) | `Rippled` |
| `moderator` | A moderator adds a member (same endpoint, `byuser` ≠ `user`); also the group creator ([group.go:1020](../iznik-server-go/group/group.go#L1020)) and `AddMembershipCommand` | varies |
| `partner` | TN membership-add flow, where the member opted in: [membership.go:1489](../iznik-server-go/membership/membership.go#L1489) | `via partner` |
| `tn_unaddressed` | TN post ingestion where the post's group isn't in the poster's `freegle_group_ids` (new) | none yet |
| `signup` | Account created with a group: [user.go:2312](../iznik-server-go/user/user.go#L2312) | check |
| `unknown` | Fallback and column default, for anything the backfill can't classify | none |

Still to classify: the join helper at [user.go:1775](../iznik-server-go/user/user.go#L1775) (find
its callers) and the TN email-replay stub at
[EmailReplaySyncer.php:392](../iznik-batch/app/Services/TrashNothing/Sync/EmailReplaySyncer.php#L392).
An account merge moves an existing row, so it keeps that row's value and has no value of its own.

`Auto` today covers both posting and replying. To tell them apart from now on, the join
endpoint needs an explicit origin parameter (Phase 3). Old rows with `Auto` backfill as `replied`
only if no post by them on that group comes within minutes of the join; otherwise as `posted`.
Rows that can't be decided get `unknown`.

### Rippled vs TN implicit: where they differ

Both have `implicit = 1`. `added_origin` decides the cases where they differ:

| Behaviour | `rippled` | `tn_unaddressed` |
|---|---|---|
| Moderators can't start a chat, send standard messages or write about the post; member actions are silent; ModTools buttons hidden | yes | yes |
| Removed automatically when the post is retracted | yes | only once no live post remains on the group (Phase 0 decision) |
| Removed on a ban | yes | yes |
| Can leave without the moderator-role guard | yes | yes (TN accounts are never moderators anyway) |
| Blank posting status not shown as Moderated | yes | yes |
| Email settings copied from the poster's other memberships | yes | no: always no mail |
| Excluded from birthday appeals, re-engagement sign-off, story review queue | yes | yes |
| Welcome email suppressed | yes (RippleIntroMail instead) | yes, and no intro mail either |
| Excluded from the abuse "joined many groups" spread check | yes | yes: TN places posts by location, not by choice |
| Rejoin suppression after leaving | yes | no: rippling doesn't create these |
| Reply attribution: "home member" | no | no, same as rippled |
| Reports skip moderators and two votes remove the post; moderators can't edit it | no: moderators of the post's own group can act | yes |
| Cleared by the member posting on the group | yes | only by TN ingestion when a later post comes with consent |
| Cleared by the member replying or writing back | yes | **no** (Phase 5) |

---

## Phase 1 - Inventory (no code changes)

- [ ] List every reader of `memberships.rippled`, `memberships_history.rippled`, the `Rippled`
  log text and `messages_groups.mod_messaging_allowed`, using the greps below. Tick each one off
  in Phase 3 or Phase 5 as it is converted.
  - `git grep -n -E "\.rippled\b|'rippled'|rippled\s*=|\bRippled\b" -- '*.go' '*.php' '*.vue' '*.js'`
  - `git grep -n -E "mod_messaging_allowed|ModMessagingAllowed|PostIsUnaddressed|UnaddressedOnly"`
  - `git grep -n "'Rippled'"` (log text readers: ExpandService rejoin guard, MembershipsProcessingService, ModLog.vue, useSystemLogFormatter.js)
- [ ] Find every insert into `memberships` (the table above) and every insert into
  `memberships_history`.
- [ ] Find how TN removes a membership (unsubscribe or removal through the partner API) and
  confirm it goes through a path that deletes the row and logs `Group/Left`.
- [ ] Find where group member counts and member lists are computed, for Phase 6.
- [ ] On production (Edward), count rows for each `Group/Joined` log text, and count TN posts
  with `mod_messaging_allowed = 0`, split into live and all. This sizes the backfills and the
  Phase 0 scope decision.

---

## Phase 2 - Schema (additive only)

Laravel migration plus an idempotent `_migration.sql` for production, following
[2026_06_23_000010_add_rippled_to_memberships_migration.sql](../iznik-batch/database/migrations/2026_06_23_000010_add_rippled_to_memberships_migration.sql):
an `information_schema` check first, and columns added so that MySQL 8 can do it as an `INSTANT`
change.

- [ ] `memberships.implicit TINYINT(1) NOT NULL DEFAULT 0`
- [ ] `memberships.added_origin ENUM(...) NOT NULL DEFAULT 'unknown'`
- [ ] `memberships_history.added_origin ENUM(...) NOT NULL DEFAULT 'unknown'`. History records
  creation, so it gets the origin and no `implicit`. Keep `memberships_history.rippled` until
  Phase 7.
- [ ] Copy existing values: `UPDATE memberships SET implicit = rippled WHERE rippled = 1`, in
  chunks. `added_origin` stays `unknown` until Phase 4a.
- [ ] Decide whether any query needs an index on `implicit`. Most reads are keyed on
  `(userid, groupid)`; check the batch scans (birthday appeals, the engagement pass) with
  `EXPLAIN` against production.
- [ ] Update the Laravel `Membership` model (casts, constants for the enum values) and the Go
  structs ([membership.go:488](../iznik-server-go/membership/membership.go#L488),
  [user.go:158](../iznik-server-go/user/user.go#L158)).

---

## Phase 3 - Write both columns, and record the origin on every insert

From here on `rippled` and `implicit` are written together, so either can be read.

- [ ] ExpandService ripple join: `implicit = 1`, `added_origin = 'rippled'`; history row
  `added_origin = 'rippled'`.
- [ ] Every clear path sets `implicit = 0` alongside `rippled = 0`:
  `rippling.ClearRippledMembership` ([rippling/membership.go:30](../iznik-server-go/rippling/membership.go#L30))
  and `RippleEngagementService::clear`.
- [ ] Join endpoint ([membership.go:1553](../iznik-server-go/membership/membership.go#L1553)):
  accept an explicit origin (`manual` / `posted` / `replied` / `moderator`) and set
  `added_origin`. The frontend sends it from `stores/auth.js joinGroup` and
  `useReplyStateMachine`. Old clients send nothing; fall back to today's `manual` flag
  (`true` → `manual`, `false` → `replied`).
- [ ] Every other insert site in the Phase 0 table sets its value.
- [ ] **TN ingestion** ([GroupPostIngestionService.php:265](../iznik-batch/app/Services/TrashNothing/Ingestion/GroupPostIngestionService.php#L265)),
  in the same transaction as the post:
  - No membership and the group isn't in `freegle_group_ids`: insert `implicit = 1`,
    `added_origin = 'tn_unaddressed'`, mail settings per Phase 0, plus a history row and a
    `Group/Joined` log.
  - No membership and the group is in `freegle_group_ids`: insert `implicit = 0`,
    `added_origin = 'partner'`.
  - An existing ordinary membership is never downgraded.
  - An existing implicit membership is cleared when the post comes with consent for that group.
- [ ] Keep writing `messages_groups.mod_messaging_allowed` as now until Phase 7.

---

## Phase 4 - Backfills (commands Edward runs)

Write these as artisan commands like the existing `ripple:*` ones: dry run by default, chunked,
idempotent, safe to re-run, with counts printed at the end.

### 4a. `added_origin` from logs - `memberships:backfill-origin`

- [ ] For each membership with `added_origin = 'unknown'`, take the **most recent** `Group/Joined`
  log for `(userid, groupid)`, ignoring `Ripple-created membership became ordinary:` rows, and
  map its text to a value as in the Phase 0 table. The latest join is the current row's
  creation, because a membership row is deleted when the member leaves. Do **not** use "any
  `Rippled` log exists": that misreads a member who was rippled in, left, and later joined by hand.
- [ ] The `logs` table is the main source. It has ripple joins from before
  `memberships_history.rippled` existed (commit `d24d91564a` came about three hours before
  `d96b7558f4`), and `Group/Joined` rows are never purged. Cross-check against
  `memberships_history.rippled = 1` and report the disagreements.
- [ ] Known false reads to handle or report: account merges (logs move to the surviving account,
  but if both accounts were members of the group the survivor keeps its own row), and
  memberships with no join log at all (→ `unknown`).
- [ ] Fill `memberships_history.added_origin` too, where the row can be matched.
- [ ] Rewrite or retire `ripple:backfill-memberships`
  ([RippleMembershipBackfillService.php](../iznik-batch/app/Services/Ripple/RippleMembershipBackfillService.php)).
  It uses "any `Rippled` log", which is the misread above.

### 4b. TN memberships from posts - `memberships:backfill-tn`

- [ ] For each `(fromuser, groupid)` of a TN post's own row (`rippled_in = 0`,
  `messages.tnpostid IS NOT NULL`), within the Phase 0 scope:
  - any row with `mod_messaging_allowed = 1` → membership `implicit = 0`, origin `partner`
    (create it if missing);
  - otherwise → membership `implicit = 1`, origin `tn_unaddressed` (create it if missing; never
    downgrade an existing ordinary membership).
- [ ] Count with and without the change before any write: how many TN posters become newly
  contactable or uncontactable once contact is decided per group.

---

## Phase 5 - Move readers to the new columns

Go API first, then the batch jobs, then the frontend (see Deploy order).

### `implicit` readers (replacing `rippled`)

- [ ] Moderator chat: [chatroom.go:446](../iznik-server-go/chat/chatroom.go#L446) via
  `IsRippleOnlyMembership` → an `implicit` check. Also replace `UserIsUnaddressedOnly` at
  [chatroom.go:409](../iznik-server-go/chat/chatroom.go#L409).
- [ ] Member actions and standard messages: [membership.go:178](../iznik-server-go/membership/membership.go#L178),
  [membership.go:222](../iznik-server-go/membership/membership.go#L222),
  `notifyposter` in [ProcessBackgroundTasksCommand.php:892](../iznik-batch/app/Console/Commands/Queue/ProcessBackgroundTasksCommand.php#L892),
  `userIsUnaddressedOnly` at [ProcessBackgroundTasksCommand.php:616](../iznik-batch/app/Console/Commands/Queue/ProcessBackgroundTasksCommand.php#L616).
- [ ] Writing to the poster about a post: `PosterMessagingGroups` ([message.go:2920](../iznik-server-go/message/message.go#L2920)).
- [ ] Member list contact flag: [membership.go:837-842](../iznik-server-go/membership/membership.go#L837) (now from the membership row).
- [ ] Automatic removal: [ExpandService.php:1052](../iznik-batch/app/Services/Ripple/ExpandService.php#L1052),
  [ExpandService.php:1082-1134](../iznik-batch/app/Services/Ripple/ExpandService.php#L1082),
  [message.go:7162](../iznik-server-go/message/message.go#L7162). These become `implicit = 1 AND added_origin = 'rippled'`,
  plus the TN rule from Phase 0.
- [ ] Leave guard: `selfLeaveKeepsRole` ([membership.go:1612](../iznik-server-go/membership/membership.go#L1612)).
- [ ] Moderated status display: [user.go:1586](../iznik-server-go/user/user.go#L1586).
- [ ] Email settings source: [ExpandService.php:2328](../iznik-batch/app/Services/Ripple/ExpandService.php#L2328) (`ORDER BY implicit ASC`).
- [ ] Birthday appeals [BirthdayService.php:54](../iznik-batch/app/Services/BirthdayService.php#L54), re-engagement
  [ReengageContentService.php:241](../iznik-batch/app/Services/ReengageContentService.php#L241), stories
  [story.go:149](../iznik-server-go/story/story.go#L149), [story.go:198](../iznik-server-go/story/story.go#L198).
- [ ] Reply attribution: [chatmessage.go:492](../iznik-server-go/chat/chatmessage.go#L492),
  [chatmessage.go:540](../iznik-server-go/chat/chatmessage.go#L540),
  `EstablishedOriginMemberExists` ([attribution.go:37](../iznik-server-go/rippling/attribution.go#L37)),
  [metrics.go:184](../iznik-server-go/rippling/metrics.go#L184), [analytics.go:973](../iznik-server-go/rippling/analytics.go#L973),
  [ReplyAttributionBackfillService.php:75](../iznik-batch/app/Services/Ripple/ReplyAttributionBackfillService.php#L75).
  Decide per query whether `wasRippleJoin` should mean `implicit = 1` or
  `added_origin = 'rippled'`. Today they are the same; once TN memberships exist they differ.
- [ ] RippleEngagementService: the posting pass reads `implicit`, and ignores TN posts
  (`tnpostid IS NOT NULL`) instead of using `mod_messaging_allowed` ([line 152](../iznik-batch/app/Services/Ripple/RippleEngagementService.php#L152)).

### `added_origin` readers (replacing creation-time `rippled` and the log text)

- [ ] Welcome email suppression: [MembershipsProcessingService.php:66](../iznik-batch/app/Services/MembershipsProcessingService.php#L66) →
  `added_origin IN ('rippled', 'tn_unaddressed')` on the history row.
- [ ] Spread check exclusions: [MembershipsProcessingService.php:154-158](../iznik-batch/app/Services/MembershipsProcessingService.php#L154) (log text) and
  [user.go:1528](../iznik-server-go/user/user.go#L1528) (history) → exclude both origins.
- [ ] Intro mail welcome list: [ExpandService.php:2493](../iznik-batch/app/Services/Ripple/ExpandService.php#L2493).
- [ ] Rejoin suppression ([ExpandService.php:1991](../iznik-batch/app/Services/Ripple/ExpandService.php#L1991),
  [ExpandService.php:2573](../iznik-batch/app/Services/Ripple/ExpandService.php#L2573)): this reads past `Group/Joined` logs
  after a leave, when the membership row is already gone, so it has to stay on logs. Leave it, and
  keep writing the `Rippled` log text.
- [ ] Attribution backfill: [ReplyAttributionBackfillService.php:75-81](../iznik-batch/app/Services/Ripple/ReplyAttributionBackfillService.php#L75).

### Post-level TN checks (replacing `mod_messaging_allowed`)

`PostIsUnaddressed(msgid)` becomes: the post's own row (`rippled_in = 0`) joined to the poster's
membership on that group has `implicit = 1 AND added_origin = 'tn_unaddressed'`.

- [ ] [modmessaging.go](../iznik-server-go/modmessaging/modmessaging.go) and the batch copy at
  [ProcessBackgroundTasksCommand.php:596](../iznik-batch/app/Console/Commands/Queue/ProcessBackgroundTasksCommand.php#L596).
- [ ] Callers stay the same: [chatmessage.go:800](../iznik-server-go/chat/chatmessage.go#L800) (report becomes a vote),
  [message.go:3751](../iznik-server-go/message/message.go#L3751) (moderator reply),
  [message.go:4499](../iznik-server-go/message/message.go#L4499) (editing),
  [message.go:6326](../iznik-server-go/message/message.go#L6326) (report endpoint),
  [microvolunteering.go:684](../iznik-server-go/microvolunteering/microvolunteering.go#L684),
  [microvolunteering.go:1149](../iznik-server-go/microvolunteering/microvolunteering.go#L1149).
- [ ] Message card flag: `modMessagingAllowed` ([messageGroup.go:92](../iznik-server-go/message/messageGroup.go#L92)) and
  [message.go:787](../iznik-server-go/message/message.go#L787) (keep the JSON field name `mod_messaging_allowed`
  so [ModMessage.vue:1329](../iznik-nuxt3/modtools/components/ModMessage.vue#L1329) and
  [MessageReportModal.vue:198](../iznik-nuxt3/components/MessageReportModal.vue#L198) keep working).

### TN-specific changes

- [ ] **A TN member who leaves a group can no longer be contacted by its moderators.** Today a
  moderator can open a chat to anyone, member or not
  ([chatroom.go:402](../iznik-server-go/chat/chatroom.go#L402) checks only that the moderator moderates the group).
  Add: a moderator-started chat to a TN account needs an Approved, non-implicit membership.
  Also decide whether an **existing** chat room stays open after the member leaves. Today that
  room is returned before any check runs.
- [ ] **A signature on every moderator message to a TN member.** TN shows the message as coming
  from the Freegle user, and our "{Group} Volunteers" sender name
  ([ChatNotification.php:165-188](../iznik-batch/app/Mail/Chat/ChatNotification.php#L165)) is
  lost. Append a sign-off to the body for TN recipients (`User::isTN()`, plus a `tnuserid`
  check) on: User2Mod chat notifications, standard messages
  ([ProcessBackgroundTasksCommand.php](../iznik-batch/app/Console/Commands/Queue/ProcessBackgroundTasksCommand.php)),
  and moderator replies about a post. No existing signature mechanism was found, so this is new.
- [ ] **Replying or writing back doesn't make a TN member's membership ordinary.** Skip TN users in
  `clearForReplies` and `clearForWriteBacks`
  ([RippleEngagementService.php:209](../iznik-batch/app/Services/Ripple/RippleEngagementService.php#L209),
  [:241](../iznik-batch/app/Services/Ripple/RippleEngagementService.php#L241)). Those clears exist so
  moderators can deal with the replier. For TN users, moderators report the user through TN's
  report API instead:
  - [ ] **Ping Andrew (TN) a few days before this ships** so the report API is ready on their side.
  - [ ] TN API client method, a Go endpoint or background task, and a ModTools "Report to
    TrashNothing" action, shown where Chat is hidden for TN implicit members.

### Frontend

- [ ] ModTools reads `member.implicit` and `member.added_origin`, falling back to
  `member.rippled` while an old API is live: [ModMember.vue:302](../iznik-nuxt3/modtools/components/ModMember.vue#L302),
  [ModMemberButtons.vue:127](../iznik-nuxt3/modtools/components/ModMemberButtons.vue#L127),
  [:149](../iznik-nuxt3/modtools/components/ModMemberButtons.vue#L149),
  [:282](../iznik-nuxt3/modtools/components/ModMemberButtons.vue#L282).
- [ ] Show the origin in the member view ("Added because their post rippled in", "Added by
  TrashNothing; did not choose this community").

---

## Phase 6 - Side effects of TN memberships

- [ ] Member counts, member lists, digests, ChitChat, events/volunteering mail, and the
  "statistics that count local activity" rule in [rippling.md](../.claude/rules/rippling.md):
  decide for each whether `implicit` members are left out, and make the change.
- [ ] Banning a TN implicit member deletes the row, so the post then reads as addressed; check
  that the ban also takes the post down.
- [ ] TN re-subscribes its members by mail (see [modtools.md](../.claude/rules/modtools.md)). Check that
  doesn't turn an implicit membership into an ordinary one.

---

## Phase 7 - Cleanup (after the backfills have run on production and every reader is moved)

- [ ] Stop writing `memberships.rippled`; drop it from the Go structs and the API JSON (once the
  frontend no longer falls back to it); drop the column.
- [ ] `memberships_history.rippled`: drop once its readers use `added_origin`.
- [ ] `messages_groups.mod_messaging_allowed`: confirm with a grep that nothing reads it. Its
  only remaining value is as a record of what the poster consented to when they posted. Drop it,
  or keep it renamed `unaddressed` if you want that record or a fallback for a missing
  membership. Drop the write in TN ingestion with it.
- [ ] Remove `UsersUnaddressedOnly` / `UserIsUnaddressedOnly` and their batch copies.

---

## Throughout

- **Deploy order.** The frontend ships itself when master goes green; the Go API is rebuilt by
  hand later. So: the Go API adds the new JSON fields before the frontend reads them, the frontend
  falls back to the old field, and the PR description says "deploy Go first" (see
  [frontend-traps.md](../.claude/rules/frontend-traps.md)). Production SQL runs before the code
  that writes the new columns.
- **Tests.** For every reader changed, a test with a ripple implicit membership, a TN implicit
  membership, and the same membership after it has been cleared. Run the Go, Laravel, Vitest and
  Playwright suites through the status container. Update the orb if CI test configuration changes.
- **Docs, in the same PR as each change:** [rippling-algorithm.md](../docs/developers/reference/rippling-algorithm.md),
  [trashnothing.md](../docs/developers/reference/trashnothing.md), `docs/moderators/rippling-out.md`,
  `docs/members/rippling-out.md`, and the moderator docs on contacting members.
  `scripts/check-docs-freshness.mjs` enforces this.
- **Rules.** Rewrite "A cleared ripple flag must stay cleared" in
  [rippling.md](../.claude/rules/rippling.md) for `implicit` / `added_origin`, and add a trap: a
  missing TN implicit membership makes the post read as addressed, which gives moderators powers
  they shouldn't have.
