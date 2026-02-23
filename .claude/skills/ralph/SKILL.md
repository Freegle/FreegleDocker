---
name: ralph
description: "MUST use for any non-trivial development task in FreegleDocker - implements iterative development with status tracking, session logging, validation, and one-task-at-a-time approach. Use when implementing features, fixing bugs, refactoring, or any task requiring more than a single simple change."
---

# Ralph Iterative Development Approach

You are now using the Ralph approach for this task. This is MANDATORY for the Freegle codebase.

## 0. Session Logging (CRITICAL for Continuity)

**Before starting any work, check for and update the session log.**

Session logs preserve context across conversation restarts and context compaction. This is essential for long-running tasks.

### Session Log Location
claude.md -> "Session Log" section (at end of file)

### On Session Start
1. Read `claude.md` to check for existing session log
2. **If the session log references an active plan file, READ THAT PLAN FILE IMMEDIATELY.** The plan file's status tables are the master progress tracker. You must know where you are in the plan before doing any work.
3. If resuming work, review the last session's state
4. Add a new dated entry showing you're continuing

### During Work
After completing each subtask or making significant progress:
```markdown
### YYYY-MM-DD HH:MM - [Brief description]
- **Status**: [Current task status table snapshot]
- **Completed**: [What was just finished]
- **Next**: [What's planned next]
- **Blockers**: [Any issues encountered]
- **Key Decisions**: [Important choices made and why]
```

### On Session End / Before Context Compaction
Update the session log with:
- Current state of all tasks
- Any uncommitted changes
- Commands that were running (e.g., CI builds in progress)
- Exact next steps for continuation

### Example Session Log Entry
```markdown
### 2026-01-16 14:30 - Implemented email tracking
- **Status**: Tasks 1-3 ✅, Task 4 🔄 (CI running), Tasks 5-6 ⬜
- **Completed**: Added AMP pixel tracking to email templates
- **Next**: Wait for CI, then verify in MailPit
- **Blockers**: None
- **Key Decisions**: Used existing envelope() pattern for consistency
```

## 1. Break Down the Task

First, analyse the request and break it into discrete subtasks. Create a status table:

```markdown
## Task Status

| # | Task | Status | Notes |
|---|------|--------|-------|
| 1 | [First subtask] | 🔄 In Progress | |
| 2 | [Second subtask] | ⬜ Pending | |
| 3 | [Third subtask] | ⬜ Pending | |
```

Status icons:
- ⬜ Pending - not started
- 🔄 In Progress - currently working on
- ✅ Complete - finished and validated
- ❌ Blocked - needs user input

## 2. Test-Driven Development (MANDATORY for Bug Fixes)

**For bug fixes, ALWAYS use TDD: write the failing test FIRST.**

### The TDD Cycle: Red → Green → Refactor

1. **RED**: Write a test that reproduces the bug
   - The test should FAIL with the current code
   - This proves you understand the bug
   - If the test passes immediately, you're testing the wrong thing

2. **VERIFY RED**: Run the test and confirm it fails
   - The failure message should match your expectation
   - If it fails for a different reason, fix the test

3. **GREEN**: Write minimal code to make the test pass
   - Don't add extra features
   - Don't refactor yet
   - Just make it pass

4. **VERIFY GREEN**: Run the test and confirm it passes
   - All other tests should still pass too

5. **REFACTOR**: Clean up if needed (while keeping tests green)

### Why This Matters

- **Tests written after code pass immediately** - you never saw them catch the bug
- **Tests written first prove they test something** - you watched them fail
- **Prevents "fix the test to match the code"** - the test is the specification

### Example Task Status for Bug Fix

```markdown
| # | Task | Status | Notes |
|---|------|--------|-------|
| 1 | Write failing test reproducing bug | 🔄 In Progress | |
| 2 | Verify test fails for expected reason | ⬜ Pending | |
| 3 | Implement minimal fix | ⬜ Pending | |
| 4 | Verify test passes | ⬜ Pending | |
| 5 | Code Quality Review | ⬜ Pending | |
```

## 3. Work Iteratively

For each subtask:
1. Mark it as 🔄 In Progress
2. Complete the work
3. **Validate before marking complete**:
   - Front-end changes: Use Chrome DevTools MCP to verify visually
   - Email changes: Use MailPit to inspect
   - Backend/API changes: Ensure test coverage (aim for 90% on touched modules)
4. Mark as ✅ Complete or ❌ Blocked
5. Update the status table
6. Move to next task

## 4. Code Quality Review (MANDATORY)

Before marking the overall task complete, perform a thorough code quality review:

### 4.1 Deficiency Analysis
Explicitly look for problems or deficiencies in your changes:
- **Logic errors**: Edge cases not handled? Null/empty checks missing?
- **Security issues**: Input validation? SQL injection? XSS?
- **Performance**: Unnecessary loops? N+1 queries? Missing indexes?
- **Error handling**: What happens when things fail?
- **Config dependencies**: Are required config values always present?

### 4.2 Consistency Check
Check for inconsistencies with how similar problems are solved elsewhere:
- Search for similar patterns in the codebase (e.g., other envelope() methods, similar services)
- Ensure your approach matches established patterns
- If you deviate from patterns, document WHY with a comment
- Check naming conventions match existing code

### 4.3 Code Duplication Refactoring
Every change should improve code quality, not decrease it:
- **Identify duplication**: Is there existing code that does something similar?
- **Extract common logic**: Create shared helpers/traits for repeated patterns
- **Don't copy-paste**: If you find yourself copying code, refactor instead
- **Clean up nearby code**: If you see small improvements near your changes, make them
- **Remove dead code**: If your change makes code obsolete, delete it

### 4.4 Document Future Improvements
During review, you may identify issues that are out of scope for this change. **Document these for the PR**:
- Keep a running list of "Future Improvements" identified during review
- Include patterns that could be improved but aren't blocking
- Note technical debt discovered but not addressed
- These go in the PR description under "## Future Improvements"

### 4.5 Test Quality
- Tests should test behavior, not implementation details
- Tests should be readable and self-documenting
- Avoid testing the same thing multiple ways
- Mock external dependencies, not internal logic

## 5. Critical Rules

- **ONE task at a time** - do not try to do multiple things at once
- **NEVER mark complete without validation/testing**
- **NEVER accept flaky tests** - fix the root cause instead of adding retries
- **NEVER skip test coverage** - it's mandatory
- **NEVER skip the Code Quality Review** - it catches real bugs
- Run `eslint --fix` on changed files (for JS/TS)
- Run `php artisan pint` on changed files (for PHP)
- Update the status table after completing each task

## 6. Completion Criteria

Only declare the overall task complete when:
- All subtasks are ✅ Complete
- All relevant tests pass
- Code Quality Review completed (Section 3)
- Code follows coding standards in codingstandards.md
- Changes have been validated appropriately
- No code duplication introduced

## 7. PR Description Format

When creating a PR, include:

```markdown
## Summary
[1-3 bullet points of what was done]

## Code Quality Review
- **Deficiency Analysis**: [Any issues found and addressed]
- **Consistency Check**: [Patterns verified/followed]
- **Duplication**: [Any refactoring done]

## Future Improvements
[Issues identified during review that are out of scope but worth noting]
- [ ] [Improvement 1]
- [ ] [Improvement 2]

## Test Plan
[How to verify the changes work]

Fixes #[issue number]
```

**IMPORTANT**: Do NOT include "Generated with Claude Code" or any AI attribution in PR descriptions.

### 7.1 Updating PRs

After Code Quality Review, always update the PR to reflect the final state:

1. **Check for comments first**: Run `gh api repos/{owner}/{repo}/issues/{pr_number}/comments --jq 'length'`
2. **If no comments exist**: Update the PR body directly using the REST API:
   ```bash
   gh api repos/{owner}/{repo}/pulls/{pr_number} --method PATCH -f body="..."
   ```
3. **If comments exist**: Add a reply comment instead of editing the PR body:
   ```bash
   gh pr comment {pr_number} --body "## Updated Code Quality Review\n..."
   ```

This preserves discussion context while keeping the PR description accurate.

**REST API Note**: Always use `gh api` with REST endpoints for PR updates, NOT `gh pr edit`. The `gh pr edit` command uses GraphQL which triggers deprecation errors related to Projects Classic, causing updates to silently fail.

## 8. If Blocked

If you need user input or are blocked:
- Mark the task as ❌ Blocked
- Clearly explain what you need
- Wait for user response before continuing

## 9. Database Migration Strategy

Freegle uses a specific migration approach:

### Development/CI Environment
- **Laravel migrations** in `iznik-batch/database/migrations/` are the source of truth
- CircleCI runs migrations automatically via `php artisan migrate`
- Always create Laravel migrations for schema changes

### Production Environment
- **Manual SQL execution required** - Laravel migrations are NOT run automatically on production
- Store production SQL in relevant `*_migration.sql` files (e.g., `iznik-server-go/emailtracking/live_migration.sql`)
- SQL must be **idempotent** - safe to run multiple times
- Use `INFORMATION_SCHEMA.COLUMNS` checks before `ALTER TABLE`

### Migration Checklist
When adding database columns:
1. ✅ Create Laravel migration in `iznik-batch/database/migrations/`
2. ✅ Update any Go models that reference the table
3. ✅ Provide idempotent SQL for production deployment
4. ✅ Document the SQL in the relevant `*_migration.sql` file
5. ✅ Tell the user they need to run the SQL on production

### Example Idempotent Column Addition
```sql
SET @dbname = DATABASE();
SET @tablename = 'table_name';
SET @columnname = 'new_column';
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @columnname) > 0,
  'SELECT 1',
  'ALTER TABLE table_name ADD COLUMN new_column VARCHAR(50) NULL'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;
```

## 10. Parallel Work / Multi-PR Workflow

When working on multiple PRs or branches simultaneously (e.g., monitoring CI while fixing another PR):

### 10.1 Branch Safety Checks (CRITICAL)
**ALWAYS verify the current branch before making ANY changes:**

```bash
# Check current branch BEFORE any edit
git branch --show-current
```

- **NEVER assume** you're on the correct branch
- **ALWAYS run** `git branch --show-current` before editing files
- If on the wrong branch, switch BEFORE making changes

### 10.2 Enhanced Status Table for Parallel Work

Track which branch each task belongs to:

```markdown
## Task Status

| # | Task | Branch | Status | Notes |
|---|------|--------|--------|-------|
| 1 | Fix AMP tracking | feature/amp-email-tracking | ✅ Complete | PR #17 |
| 2 | Fix WorkerPool tests | feature/worker-pools | 🔄 In Progress | PR #16 |
| 3 | Review Debug Markers | fix/amp-footer | ⬜ Pending | PR #9 |
```

### 10.3 Branch Switching Protocol

When switching between tasks on different branches:

1. **Verify no uncommitted changes**: `git status`
2. **Stash if needed**: `git stash push -m "WIP: [task description]"`
3. **Switch branch**: `git checkout [branch-name]`
4. **Verify branch**: `git branch --show-current`
5. **Pop stash if returning**: `git stash pop`

### 10.4 Parallel CI Monitoring

When monitoring CI for multiple PRs:

1. **Read-only operations are safe in parallel**:
   - Checking CI status
   - Reading CircleCI logs
   - Viewing PR status

2. **Write operations require branch verification**:
   - Editing files
   - Committing changes
   - Pushing to remote

3. **Use clear indicators** when reporting which PR/branch you're discussing

## 11. Following a Plan

When working on a multi-phase plan (e.g., an API migration):

### Plan File is the Master Progress Tracker
- The plan file (e.g., `plans/active/v1-to-v2-api-migration.md`) contains status tables with ⬜/🔄/✅ markers
- **Update the plan file's status tables as you complete tasks** - this IS your progress tracker
- **Plan updates go on master only** - the plan is a coordination document, not feature code. Feature branches may have stale plan status; that's fine.
- The session log in CLAUDE.md should reference the plan file path and note current focus, but the plan file is authoritative

### On Every Resume / After Compaction
1. Read the session log in CLAUDE.md
2. **Read the active plan file** referenced in the session log
3. Check which phase/tasks are marked as complete vs pending
4. Resume from where the plan shows you left off
5. Do NOT skip steps in the plan - follow the phases in order

### Session Log Format When Following a Plan
```markdown
**Active plan**: `plans/active/plan-name.md` - READ THIS ON EVERY RESUME/COMPACTION.
### YYYY-MM-DD - [description]
- **Plan Phase**: [Current phase and task numbers]
- **Completed**: [What was finished]
- **Next**: [Next steps per the plan]
```

### Common Mistake: Losing Plan Context
After context compaction, you may only remember "get CI green" but forget that the plan requires adversarial review, per-endpoint checklists, etc. **Always re-read the plan file** - it contains steps you may have forgotten.

## 12. Automated Execution with Ralphy

For unattended/autonomous execution, use `ralphy-cli` (community-maintained) via the thin wrapper:

```bash
# Execute a plan file
./ralph.sh plans/active/my-feature.md

# Execute a single task
./ralph.sh -t "Fix failing tests" --fast

# All ralphy options supported (--parallel, --model, --max-iterations, etc.)
./ralph.sh --help
```

Project-specific rules are in `.ralphy/config.yaml`. The ralph.sh wrapper adds Freegle pre-flight checks (Docker containers, git status) before delegating to ralphy.

Now analyse the user's request and create your status table to begin work.
