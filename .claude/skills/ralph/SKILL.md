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

Now analyse the user's request and create your status table to begin work.
