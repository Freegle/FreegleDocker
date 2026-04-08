---
name: freegle-autonomous-monitor
description: "Autonomous unattended monitor: checks CircleCI, scans all recent Discourse topics and Sentry for bugs, creates TDD fixes as PRs. Runs via cron. Kill switch: open a GitHub issue titled 'HALT MONITOR' in Freegle/FreegleDocker."
---

# Freegle Autonomous Monitor

## Overview

Unattended monitoring loop. Each cron invocation does ONE pass:

1. **Halt check** — abort if kill switch active
2. **Master CI** — if failing, fix and push; exit to let CI re-run
3. **PR CI check** — for any active PRs in state, check CI; if passed remove from state; if failed push a fix
4. **Discourse scan** — scan ALL recently active topics for new bug posts; pick the oldest unhandled one; reproduce + TDD fix + adversarial review + PR
5. **Sentry scan** — if no Discourse bugs, check Sentry for high-frequency issues; same fix flow

Each run does at most ONE new fix. CI is never waited on inline — the next run checks it.

**No Discourse replies are ever posted** — humans decide when and whether to reply.

## Local Schedule

This monitor runs as a local Claude session cron job. To inspect or cancel it, use the `CronList` and `CronDelete` tools (these are deferred — load them first with `ToolSearch select:CronList,CronDelete,CronCreate`):

```
CronList   → shows all active session jobs with their IDs
CronDelete → cancels a job by ID
CronCreate → schedules a new job (cron: "17 * * * *", recurring: true)
```

To restart the monitor after stopping it:
```
CronCreate cron="17 * * * *" recurring=true prompt="Read /home/edward/FreegleDockerWSL/.claude/skills/freegle-autonomous-monitor/skill.md and execute it exactly as written."
```

Note: session cron jobs are in-memory only and are lost when Claude exits. Use `durable: true` to persist across restarts.

## Kill Switch

**First action every run:**

```bash
HALT=$(gh api repos/Freegle/FreegleDocker/issues \
  --jq '[.[] | select(.state=="open" and (.title | ascii_downcase | contains("halt monitor")))] | length')
if [ "$HALT" -gt "0" ]; then
  echo "HALT MONITOR issue is open — aborting."
  exit 0
fi
```

If any open issue in Freegle/FreegleDocker has "halt monitor" (case-insensitive) in the title → exit immediately, do nothing.

## State File

`/tmp/freegle-monitor/state.json` — create with defaults if missing:

```json
{
  "last_run": "2026-01-01T00:00:00Z",
  "discourse_topics": {},
  "active_prs": [],
  "sentry_handled": []
}
```

`discourse_topics`: `{ "9481": { "last_post": 422 }, "9999": { "last_post": 3 } }`

`active_prs`: array of objects:
```json
{
  "pr_number": 42,
  "pr_url": "https://github.com/...",
  "repo": "iznik-nuxt3",
  "branch": "fix/discourse-9481-422-chat-scroll",
  "discourse_topic_id": "9481",
  "discourse_post_number": 422,
  "sentry_issue_id": null
}
```

Read state at the start of every run. Write updated state before exiting.

## Step 1: Halt Check

See above. Exit immediately if triggered.

## Step 2: Check Master CI

```bash
STATE=$(gh api repos/Freegle/FreegleDocker/commits/master/status --jq '.state')
```

- `success` or `pending` → continue to Step 3
- `failure` → investigate the failing job, fix it, push to master, write state, exit

**Finding the failure:**
```bash
# Get the CircleCI pipeline URL from the failed status check
gh api repos/Freegle/FreegleDocker/commits/master/status \
  --jq '.statuses[] | select(.state=="failure") | .target_url'
```

Then use the CircleCI v2 API (token from `~/.circleci/cli.yml`) to fetch the failed job's logs. Read the actual test failure, find the root cause in the code, fix it, commit directly to the failing repo's master, push.

Do NOT create a PR for master CI fixes — push directly so CI re-runs.

## Step 3: Check Active PRs

For each entry in `active_prs`:

```bash
STATUS=$(gh pr checks <PR_NUMBER> -R Freegle/<REPO> --json state \
  --jq '[.[] | .state] | if all(. == "SUCCESS") then "success" elif any(. == "FAILURE") then "failure" else "pending" end' 2>/dev/null || echo "pending")
```

### If `success`:
1. Remove this entry from `active_prs`
2. Update `discourse_topics[topic_id].last_post` if it's the highest post seen
3. Write state

### If `failure`:
1. Fetch the failing step logs via CircleCI API
2. Fix the issue on the existing branch (do NOT create a new PR — push to same branch)
3. Write state, exit

### If `pending`:
- Skip, will check next run

## Step 4: Discourse Scan

### 4a. Fetch recently active topics

```bash
API_KEY=$(python3 -c "import json; print(json.load(open('/home/edward/profile.json'))['auth_pairs'][0]['user_api_key'])")

# Get all topics with activity in the last 7 days
curl -s -H "User-Api-Key: $API_KEY" \
  "https://discourse.ilovefreegle.org/latest.json?order=activity&per_page=30" \
  | python3 -c "
import json, sys
d = json.load(sys.stdin)
for t in d['topic_list']['topics']:
    print(t['id'], t['posts_count'], t['title'][:60])
"
```

### 4b. For each topic, fetch posts newer than state

For each topic ID `T` where `T` is not in state or has posts beyond `last_post`:

```bash
LAST=$(python3 -c "import json; s=json.load(open('/tmp/freegle-monitor/state.json')); print(s.get('discourse_topics',{}).get('$T',{}).get('last_post',0))")

curl -s -H "User-Api-Key: $API_KEY" \
  "https://discourse.ilovefreegle.org/t/${T}.json" \
  | python3 -c "
import json, sys, html, re
d = json.load(sys.stdin)
for p in d['post_stream']['posts']:
    if p['post_number'] > $LAST:
        text = re.sub(r'<[^>]+>', '', p['cooked'])
        text = html.unescape(text)[:400]
        print(p['post_number'], p['username'], text)
"
```

### 4c. Classify each new post

| Type | Criteria |
|------|----------|
| **bug** | "doesn't work", "broken", error message, "Oh dear", screenshot of failure, wrong data |
| **retest** | "still not working", references a previous fix |
| **question** | "how do I", "is it possible" — skip |
| **feedback/thanks** | confirmation, praise — skip (update last_post, no action) |

Cross-reference recent git commits to avoid re-fixing something already fixed:
```bash
git -C /home/edward/FreegleDockerWSL/iznik-nuxt3 log --oneline --since="5 days ago"
git -C /home/edward/FreegleDockerWSL/iznik-server-go log --oneline --since="5 days ago"
```

### 4d. Pick ONE bug to fix

Pick the oldest unhandled bug post across all topics. Skip any that are already in `active_prs`.

**Before picking, check for open PRs that already address this topic/issue:**

```bash
# Check all open PRs across all repos for the same topic_id or symptom
gh pr list -R Freegle/iznik-server-go --state open --json number,title,headRefName | \
  python3 -c "import json,sys; [print(p['number'], p['headRefName'], p['title']) for p in json.load(sys.stdin)]"
gh pr list -R Freegle/iznik-nuxt3 --state open --json number,title,headRefName | \
  python3 -c "import json,sys; [print(p['number'], p['headRefName'], p['title']) for p in json.load(sys.stdin)]"
gh pr list -R Freegle/FreegleDocker --state open --json number,title,headRefName | \
  python3 -c "import json,sys; [print(p['number'], p['headRefName'], p['title']) for p in json.load(sys.stdin)]"
```

If an open PR already exists for this topic/issue, use judgment to decide if it is a duplicate:

**Duplicate (skip — do not open another PR):**
- The existing PR modifies the same files/functions as the fix you'd write
- The symptom description in the existing PR title/body matches the new post's symptom (even if the Discourse post number or Sentry ID differs)
- Example: "phantom badge count" and "task count showing 1 when nothing to do" describe the same bug

**Complementary (proceed — note dependency):**
- The existing PR fixes a different code path or layer (e.g. backend vs frontend, different handler)
- The existing PR's body explicitly says it is a partial fix or addresses only one aspect
- Example: PR fixes the ban action itself; new bug is that the banned list doesn't show old bans

When in doubt, read the existing PR diff (`gh pr diff <N> -R Freegle/<REPO>`) and compare it to the files you'd need to change. If there's overlap → duplicate.

If none found → go to Step 5 (Sentry).

### 4e. Fix the bug (TDD)

**Branch naming**: `fix/discourse-{topic_id}-{post_number}-{short-slug}`

```bash
cd /home/edward/FreegleDockerWSL/<affected-repo>
git checkout master && git pull
git checkout -b fix/discourse-{topic_id}-{post_number}-{short-slug}
```

1. **Investigate** — read the relevant Go/Vue/Laravel code, check Loki if helpful
2. **Write failing test** — in Go test file or Vitest spec; run it and confirm it FAILS
3. **Fix** — minimal change; run test again and confirm it PASSES
4. **Adversarial review** — run Step 4f before committing
5. **Commit**:
```bash
git commit -m "fix: <description>

Fixes Discourse topic #<TOPIC_ID> post #<POST_NUMBER>

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
git push -u origin <branch>
```
6. **Create PR** against master of the affected repo:
```bash
gh pr create \
  --repo Freegle/<REPO> \
  --title "fix: <description>" \
  --body "$(cat <<'EOF'
## Summary
- Fixes issue reported in Discourse: <topic URL> post #<N>
- <what was wrong and what the fix does>

## Test plan
- Added test: <test name>
- Verifies: <what it checks>
EOF
)"
```
7. **Return to master**: `git checkout master`
8. **Add to `active_prs`** in state with `discourse_topic_id` and `discourse_post_number`
9. **Update `discourse_topics[T].last_post`** to this post number

Write state, exit. Next run will check CI.

### 4f. Adversarial Review (run before every commit)

Use the `superpowers:code-reviewer` Agent to adversarially review every fix before pushing. Pass it:
- The full diff of the change
- The test added
- The original error/symptom
- The files changed

The reviewer must answer:

1. **Root cause vs symptom**: Does this fix the actual bug, or just silence/suppress it? Red flags:
   - `logError=false` on an endpoint that could have legitimate errors — should use selective suppression `(data) => condition`
   - `try/catch` that swallows errors without logging or propagating
   - Never-resolving promises (`new Promise(resolve => {})`) that hang callers
   - Timeouts increased without fixing why the operation was slow
   - Error boundaries added without fixing why the component crashed
   - **Redundant retry layer on top of library retries** — if TUS/fetch already has `retryDelays`, adding a manual `retryAll()` in the error handler runs against broken transitional state and crashes. Check whether the library already handles the retry before adding another layer.
   - **Shared dictionary mutation from individual record edits** — if multiple users can edit the same record (e.g. `items.name`), an UPDATE causes flip-flopping between editors. Use the submitted value directly in the response/subject without persisting it back to the shared table.
   - **Normalising shared mutable values on every write** — if the fix enforces a "correct" form (e.g. normalised case, trimmed whitespace, canonical category) by rewriting a shared value on every PATCH/PUT, and different callers could legitimately disagree on what the correct form is (e.g. different moderators, different systems), the value will oscillate rather than converge: each save "corrects" what the previous save wrote. This is not a safe fix. If the fix changes a shared mutable value on every write to enforce a "correct" form, and different callers could legitimately disagree on what the correct form is, close the branch instead.
   - **Fixing only one layer of a two-layer problem** — e.g. Vue `v-model` on `<input type="number">` sends strings; the Go API must use `FlexFloat64`/`FlexInt` AND the client must use `parseFloat()`. If only the server is fixed, the client still sends the wrong type when the server is not yet deployed. Fix both.
   - **Suppressing invalid-ID errors server-side** — if the client sends an invalid/NaN user ID, that is a client bug. Add an `isNaN` guard at the client's API layer so the bad call never reaches the server, rather than catching the 404 server-side.

2. **Related work**: Check `git log --oneline --since="7 days ago"` in both repos. Has this already been fixed elsewhere? Is there a related PR that conflicts?

3. **Test quality**: Does the test verify the fix actually works (correct behaviour), or does it only confirm that an error is silenced?
   - **Vitest module isolation**: if two tests import the same composable/module, add `vi.resetModules()` in `beforeEach` AND `afterEach` to prevent module-level `ref()` state from leaking between tests.
   - **Optional-chain vacuous assertions**: if a test looks up a handler with `.find()?.[1]` and then calls `handler?.()`, the `not.toThrow()` assertion is trivially true when `handler` is `undefined`. Always `expect(handler).toBeDefined()` before calling it (and drop the `?.`).
   - **Asserting the wrong thing**: a test that checks `items.name` was updated is wrong if the fix deliberately stops updating `items.name`. Update the assertion to match the new correct behaviour and add a comment explaining why.

4. **Risk**: Could this change hide a legitimate error that should be visible?

**Verdict**:
- `PASS` — fix is correct, addresses root cause, test is meaningful → proceed to commit
- `NEEDS_WORK` — describe what's wrong; go back and fix before committing
- `CLOSE` — this approach is fundamentally wrong; delete the branch and do not create a PR

If `NEEDS_WORK`: rework the fix (new approach, not just tweaking the bad one), re-run the test, re-review.
If `CLOSE`: abandon this branch, `git checkout master`, mark the issue as `review_rejected` in state so it isn't re-attempted.

## Step 5: Sentry Scan (only if no Discourse bugs found)

```bash
SENTRY_TOKEN=$(grep SENTRY_AUTH_TOKEN /home/edward/FreegleDockerWSL/.env | cut -d= -f2 | tr -d '"')

for SLUG in nuxt3 go modtools; do
  curl -s -H "Authorization: Bearer $SENTRY_TOKEN" \
    "https://sentry.io/api/0/projects/freegle/${SLUG}/issues/?query=is:unresolved&sort=freq&limit=10" \
    | python3 -c "
import json, sys
issues = json.load(sys.stdin)
if not isinstance(issues, list): sys.exit(0)
for i in issues:
    if i['count'] and int(i['count']) >= 10:
        print('$SLUG', i['id'], i['count'], i['userCount'], i['title'][:80])
"
done
```

Filter to issues with `count >= 10` (happening repeatedly) and **not already in `sentry_handled`**.

Pick the highest-count unhandled issue. **Before fixing, check for open PRs referencing the same Sentry issue ID in their branch name or body.** If one exists for the same root cause, skip and pick the next issue. Follow the same TDD fix flow as Step 4e (including adversarial review 4f), but:
- Branch: `fix/sentry-{issue_id}-{short-slug}`
- PR body references the Sentry issue URL
- `active_prs` entry has `sentry_issue_id` set, `discourse_topic_id: null`
- After CI passes (next run) → add issue ID to `sentry_handled`

**Sentry classification — skip these entirely:**
- Third-party ad library errors (ftUtils.js, Sharethrough, etc.) — not our code
- Browser hardware errors (NotReadableError, I/O read failed) — not fixable
- Network-level aborts (HTTP null, ERR_ABORTED, Load failed) — not fixable
- Cross-origin security errors (SecurityError on Window.__v_isRef) — browser restriction
- Infrastructure errors (Failed to fetch image freegletusd-...) — CDN/storage issue
- Generic "Unhandled promise" with no stack trace — not actionable without more info

## Step 5b: Nothing to do

If no Discourse bugs and no Sentry issues → log "Nothing to fix this run." and exit (no email).

## Email Summary (send after any run where an action was taken)

Send an email whenever any of these happened in this run:
- A new PR was created
- A master CI fix was pushed
- A PR branch fix was pushed

**Do not send email** if the run found nothing to do.

Send via Gmail SMTP using credentials from `.env`:

```python
import smtplib, subprocess
from email.mime.text import MIMEText

def get_env(key):
    result = subprocess.run(['grep', key, '/home/edward/FreegleDockerWSL/.env'],
                            capture_output=True, text=True)
    return result.stdout.strip().split('=', 1)[1].strip('"') if result.stdout else ''

to      = get_env('MONITOR_EMAIL')
host    = get_env('SMTP_HOST')
port    = int(get_env('SMTP_PORT') or 587)
user    = get_env('SMTP_USER')
passwd  = get_env('SMTP_PASS')

msg = MIMEText(BODY)
msg['Subject'] = SUBJECT
msg['From'] = user
msg['To'] = to

s = smtplib.SMTP(host, port, timeout=15)
s.ehlo(); s.starttls(); s.login(user, passwd)
s.send_message(msg); s.quit()
```

**Email subject**: `Freegle Monitor: <one-line summary of what was done>`

**Email body**:
```
Freegle autonomous monitor ran at <timestamp>.

Actions taken:
- <bullet for each action: PR created / CI fix pushed>

PR links:
- <pr_url if applicable>

---
To stop the monitor, open a GitHub issue in Freegle/FreegleDocker with the title "HALT MONITOR":
https://github.com/Freegle/FreegleDocker/issues/new?title=HALT+MONITOR

The monitor checks for this issue at the start of every run and will stop immediately.
To resume, close the issue.
```

## Constraints

- **Never merge PRs** — humans merge
- **One fix per run** — don't pile up multiple PRs in one pass
- **Master CI fix** takes priority over everything else
- **Active PR CI fix** takes priority over new bugs
- **Discourse > Sentry** priority
- **Never touch production DB** — only read via `freegle-apiv2-live`
- **Check git log** before fixing — don't re-fix something already committed
- **No Discourse replies** — never post comments to Discourse topics
- **Adversarial review required** — every fix must pass Step 4f before creating a PR

## Codebase Paths

| Repo | Path | Tests |
|------|------|-------|
| Go API | `/home/edward/FreegleDockerWSL/iznik-server-go/` | `docker exec freegle-apiv2 sh -c 'cd /app && MYSQL_DBNAME=iznik_go_test go test ./test/... -run TestName -v'` |
| Nuxt frontend | `/home/edward/FreegleDockerWSL/iznik-nuxt3/` | `docker exec freegle-dev-local sh -c 'cd /app && npx vitest run tests/...'` |
| Laravel batch | `/home/edward/FreegleDockerWSL/iznik-batch/` | `curl -X POST http://localhost:8081/api/tests/laravel` |
| Discourse key | `/home/edward/profile.json` | — |

**Go test database**: Always pass `MYSQL_DBNAME=iznik_go_test` when running Go tests. The container default is `MYSQL_DBNAME=iznik` (production). `TestDatabaseNameIsTestDatabase` will fail and abort the suite if you use the wrong DB — this is intentional. Never dismiss that failure.
