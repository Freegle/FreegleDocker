---
name: ralph
description: "MUST use for any non-trivial development task in FreegleDocker - implements iterative development with status tracking, validation, and one-task-at-a-time approach. Use when implementing features, fixing bugs, refactoring, or any task requiring more than a single simple change."
---

# Ralph Iterative Development Approach

You are now using the Ralph approach for this task. This is MANDATORY for the Freegle codebase.

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

## 2. Work Iteratively

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

## 3. Code Quality Review (MANDATORY)

Before marking the overall task complete, perform a thorough code quality review:

### 3.1 Deficiency Analysis
Explicitly look for problems or deficiencies in your changes:
- **Logic errors**: Edge cases not handled? Null/empty checks missing?
- **Security issues**: Input validation? SQL injection? XSS?
- **Performance**: Unnecessary loops? N+1 queries? Missing indexes?
- **Error handling**: What happens when things fail?
- **Config dependencies**: Are required config values always present?

### 3.2 Consistency Check
Check for inconsistencies with how similar problems are solved elsewhere:
- Search for similar patterns in the codebase (e.g., other envelope() methods, similar services)
- Ensure your approach matches established patterns
- If you deviate from patterns, document WHY with a comment
- Check naming conventions match existing code

### 3.3 Code Duplication Refactoring
Every change should improve code quality, not decrease it:
- **Identify duplication**: Is there existing code that does something similar?
- **Extract common logic**: Create shared helpers/traits for repeated patterns
- **Don't copy-paste**: If you find yourself copying code, refactor instead
- **Clean up nearby code**: If you see small improvements near your changes, make them
- **Remove dead code**: If your change makes code obsolete, delete it

### 3.4 Document Future Improvements
During review, you may identify issues that are out of scope for this change. **Document these for the PR**:
- Keep a running list of "Future Improvements" identified during review
- Include patterns that could be improved but aren't blocking
- Note technical debt discovered but not addressed
- These go in the PR description under "## Future Improvements"

### 3.5 Test Quality
- Tests should test behavior, not implementation details
- Tests should be readable and self-documenting
- Avoid testing the same thing multiple ways
- Mock external dependencies, not internal logic

## 4. Critical Rules

- **ONE task at a time** - do not try to do multiple things at once
- **NEVER mark complete without validation/testing**
- **NEVER accept flaky tests** - fix the root cause instead of adding retries
- **NEVER skip test coverage** - it's mandatory
- **NEVER skip the Code Quality Review** - it catches real bugs
- Run `eslint --fix` on changed files (for JS/TS)
- Run `php artisan pint` on changed files (for PHP)
- Update the status table after completing each task

## 5. Completion Criteria

Only declare the overall task complete when:
- All subtasks are ✅ Complete
- All relevant tests pass
- Code Quality Review completed (Section 3)
- Code follows coding standards in codingstandards.md
- Changes have been validated appropriately
- No code duplication introduced

## 6. PR Description Format

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

### 6.1 Updating PRs

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

## 7. If Blocked

If you need user input or are blocked:
- Mark the task as ❌ Blocked
- Clearly explain what you need
- Wait for user response before continuing

## 8. Database Migration Strategy

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
1. Create Laravel migration in `iznik-batch/database/migrations/`
2. Update any Go models that reference the table
3. Provide idempotent SQL for production deployment
4. Document the SQL in the relevant `*_migration.sql` file
5. Tell the user they need to run the SQL on production

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

## 9. Parallel Work / Multi-PR Workflow

When working on multiple PRs or branches simultaneously:

### 9.1 PR Prioritization (CRITICAL)
**Complete PRs in order rather than making partial progress on many:**

- Process PRs by ID order (lowest first): PR #8 before PR #15 before PR #16
- Focus on getting ONE PR fully passing CI and ready to merge before moving to the next
- Only switch to another PR if blocked or waiting for CI

**Rationale**: Completing one PR is more valuable than partial progress on many. Context switching has overhead and incomplete PRs accumulate.

### 9.2 Branch Switching Protocol (CRITICAL)
**ALWAYS merge master when switching to a branch:**

```bash
# 1. Verify current branch has no uncommitted changes
git status

# 2. Switch to target branch
git checkout [branch-name]

# 3. Merge master to get latest changes (including Ralph skill updates)
git merge origin/master

# 4. Verify you're on the correct branch
git branch --show-current

# 5. Push the merge if successful
git push
```

**Why merge master?** The Ralph skill itself is updated on master. Merging ensures you always have the latest skill improvements when working on any PR.

### 9.3 Branch Safety Checks
**ALWAYS verify the current branch before making ANY changes:**

```bash
# Check current branch BEFORE any edit
git branch --show-current
```

- **NEVER assume** you're on the correct branch
- **ALWAYS run** `git branch --show-current` before editing files
- If on the wrong branch, switch BEFORE making changes

### 9.4 Enhanced Status Table for Parallel Work

Track which branch each task belongs to:

```markdown
## Task Status

| # | Task | Branch | Status | Notes |
|---|------|--------|--------|-------|
| 1 | Fix migration safety | fix/migration-safety | 🔄 In Progress | PR #8 |
| 2 | Fix mail driver | fix/test-mail-driver-config | ⬜ Pending | PR #15 |
| 3 | Fix worker pools | feature/worker-pools | ⬜ Pending | PR #16 |
```

### 9.5 Parallel CI Monitoring

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

Now analyse the user's request and create your status table to begin work.
