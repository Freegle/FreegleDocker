---
name: ralph
description: "MUST use for any non-trivial development task - implements iterative development with status tracking, validation, and one-task-at-a-time approach. Use when implementing features, fixing bugs, refactoring, or any task requiring more than a single simple change."
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

## 3. Critical Rules

- **ONE task at a time** - do not try to do multiple things at once
- **NEVER mark complete without validation/testing**
- **NEVER accept flaky tests** - fix the root cause instead of adding retries
- **NEVER skip test coverage** - it's mandatory
- Run `eslint --fix` on changed files
- Update the status table after completing each task

## 4. Completion Criteria

Only declare the overall task complete when:
- All subtasks are ✅ Complete
- All relevant tests pass
- Code follows coding standards in codingstandards.md
- Changes have been validated appropriately

## 5. If Blocked

If you need user input or are blocked:
- Mark the task as ❌ Blocked
- Clearly explain what you need
- Wait for user response before continuing

Now analyse the user's request and create your status table to begin work.
