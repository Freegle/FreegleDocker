#!/bin/bash
#
# ralph.sh - Iterative AI coding agent for Freegle plans
#
# Named after the Ralph Wiggum approach: run an AI agent repeatedly on the same
# prompt in a continuous loop, allowing it to iteratively improve its work.
#
# Usage:
#   ./ralph.sh <plan-file> [max-iterations]     - Execute a plan file
#   ./ralph.sh -t "task description" [max-iterations]  - Execute an explicit task
#
# Examples:
#   ./ralph.sh plans/active/my-feature.md 10
#   ./ralph.sh -t "Fix failing CircleCI tests" 5
#   ./ralph.sh -t "Add unit tests for UserController"
#

set -e

# =============================================================================
# Configuration
# =============================================================================

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG_DIR="$SCRIPT_DIR/ralph/logs"
STATE_DIR="$SCRIPT_DIR/ralph/state"
TEMP_PLANS_DIR="$SCRIPT_DIR/ralph/temp-plans"
PROGRESS_FILE=""
STATE_FILE=""
MAX_ITERATIONS=10
ITERATION=0
PLAN_FILE=""
TASK_MODE=false
TASK_STRING=""
IS_FIRST_RUN=false

# Context window management - limit turns to prevent context overflow
MAX_TURNS=50

# Parse arguments
parse_args() {
    if [[ "$1" == "-t" || "$1" == "--task" ]]; then
        TASK_MODE=true
        TASK_STRING="$2"
        MAX_ITERATIONS=${3:-10}
    else
        PLAN_FILE="$1"
        MAX_ITERATIONS=${2:-10}
    fi
}

# Create a temporary plan from a task string
create_temp_plan() {
    mkdir -p "$TEMP_PLANS_DIR"

    # Create a slug from the task for filename
    local slug=$(echo "$TASK_STRING" | tr '[:upper:]' '[:lower:]' | sed 's/[^a-z0-9]/-/g' | sed 's/--*/-/g' | cut -c1-50)
    local timestamp=$(date '+%Y%m%d-%H%M%S')
    PLAN_FILE="$TEMP_PLANS_DIR/${slug}-${timestamp}.md"

    cat > "$PLAN_FILE" << EOF
# Task: $TASK_STRING

Created: $(date '+%Y-%m-%d %H:%M:%S')

## Task Status

| # | Task | Status | Notes |
|---|------|--------|-------|
| 1 | $TASK_STRING | ⬜ Pending | |

## Description

$TASK_STRING

## Success Criteria

- Task completed successfully
- All tests pass
- Code follows coding standards
EOF

    log INFO "Created temporary plan: $PLAN_FILE"
}

# Colours for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Colour

# =============================================================================
# Freegle-Specific Wisdom (loaded from codingstandards.md)
# =============================================================================

CODING_STANDARDS_FILE="$SCRIPT_DIR/codingstandards.md"

load_coding_standards() {
    if [[ -f "$CODING_STANDARDS_FILE" ]]; then
        cat "$CODING_STANDARDS_FILE"
    else
        echo "WARNING: codingstandards.md not found at $CODING_STANDARDS_FILE"
    fi
}

# =============================================================================
# State Management (for context bridging between sessions)
# =============================================================================

setup_state() {
    mkdir -p "$STATE_DIR"
    local plan_name=$(basename "$PLAN_FILE" .md)
    STATE_FILE="$STATE_DIR/${plan_name}.state"

    if [[ -f "$STATE_FILE" ]]; then
        # Existing state - load previous iteration count
        source "$STATE_FILE"
        IS_FIRST_RUN=false
        log INFO "Resuming from previous session. Last iteration: $ITERATION"
    else
        # First run - create state file
        IS_FIRST_RUN=true
        ITERATION=0
        save_state
        log INFO "First run for this plan. Initializing state."
    fi
}

save_state() {
    cat > "$STATE_FILE" << EOF
# Ralph state file for $(basename "$PLAN_FILE")
# Generated: $(date)
ITERATION=$ITERATION
LAST_GIT_COMMIT=$(git rev-parse HEAD 2>/dev/null || echo "unknown")
LAST_RUN=$(date '+%Y-%m-%d %H:%M:%S')
EOF
}

get_context_summary() {
    # Generate a compact summary for context bridging
    local summary=""

    # Get recent git commits since we started
    if [[ -f "$STATE_FILE" ]]; then
        source "$STATE_FILE"
        if [[ -n "$LAST_GIT_COMMIT" && "$LAST_GIT_COMMIT" != "unknown" ]]; then
            summary+="## Recent Git Activity\n"
            summary+=$(git log --oneline -10 2>/dev/null || echo "No git history")
            summary+="\n\n"
        fi
    fi

    # Get last iteration summary from progress file (last 50 lines)
    if [[ -f "$PROGRESS_FILE" ]]; then
        summary+="## Last Iteration Summary\n"
        summary+=$(tail -50 "$PROGRESS_FILE")
        summary+="\n"
    fi

    echo -e "$summary"
}

# =============================================================================
# Initializer Agent (runs on first session only)
# =============================================================================

run_initializer() {
    log INFO "Running initializer agent to set up environment..."

    local init_prompt=$(cat << 'INIT_EOF'
# Initializer Agent Task

You are the INITIALIZER agent for a long-running plan execution. Your job is to:

1. **Analyse the plan** and identify all discrete tasks/features.
2. **Check the current state** of the codebase (git status, existing files).
3. **Create a status table** in the plan file if one doesn't exist.

## Status Table Format

If the plan doesn't already have a status table, add one near the top in this format:

```markdown
## Task Status

| # | Task | Status | Notes |
|---|------|--------|-------|
| 1 | First task description | ⬜ Pending | |
| 2 | Second task description | ⬜ Pending | |
```

Status icons:
- ⬜ Pending - not started
- 🔄 In Progress - currently working on
- ✅ Complete - finished and tested
- ❌ Blocked - needs user input

4. **Do NOT implement anything** - just set up the status tracking.
5. **Output INITIALIZER_COMPLETE** when done.

INIT_EOF
)

    local plan_content=$(cat "$PLAN_FILE")
    local full_prompt="$init_prompt

## The Plan

$plan_content
"

    local prompt_file=$(mktemp)
    echo "$full_prompt" > "$prompt_file"

    local output_file=$(mktemp)

    if claude --dangerously-skip-permissions \
              --output-format text \
              --max-turns 20 \
              < "$prompt_file" \
              > "$output_file" 2>&1; then
        log SUCCESS "Initializer agent completed."

        # Log output
        echo "--- Initializer Output ---" >> "$PROGRESS_FILE"
        cat "$output_file" >> "$PROGRESS_FILE"
        echo "--- End Initializer ---" >> "$PROGRESS_FILE"
    else
        log WARN "Initializer agent had issues. Continuing anyway."
    fi

    rm -f "$prompt_file" "$output_file"
}

# =============================================================================
# Functions
# =============================================================================

log() {
    local level="$1"
    shift
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    local colour=""

    case "$level" in
        INFO) colour="$BLUE" ;;
        SUCCESS) colour="$GREEN" ;;
        WARN) colour="$YELLOW" ;;
        ERROR) colour="$RED" ;;
    esac

    echo -e "${colour}[$timestamp] [$level] $*${NC}"
    echo "[$timestamp] [$level] $*" >> "$PROGRESS_FILE"
}

check_prerequisites() {
    log INFO "Checking prerequisites..."

    # Check claude CLI exists
    if ! command -v claude &> /dev/null; then
        log ERROR "claude CLI not found. Install Claude Code first."
        exit 1
    fi

    # Check plan file exists
    if [[ ! -f "$PLAN_FILE" ]]; then
        log ERROR "Plan file not found: $PLAN_FILE"
        exit 1
    fi

    # Check we're in FreegleDocker
    if [[ ! -f "$SCRIPT_DIR/docker-compose.yml" ]]; then
        log ERROR "Must run from FreegleDocker directory."
        exit 1
    fi

    log SUCCESS "Prerequisites check passed."
}

setup_logging() {
    mkdir -p "$LOG_DIR"
    local plan_name=$(basename "$PLAN_FILE" .md)
    local timestamp=$(date '+%Y%m%d-%H%M%S')
    PROGRESS_FILE="$LOG_DIR/${plan_name}-${timestamp}.log"

    log INFO "Progress file: $PROGRESS_FILE"
    log INFO "Plan: $PLAN_FILE"
    log INFO "Max iterations: $MAX_ITERATIONS"
}

check_git_status() {
    log INFO "Checking git status across all submodules..."

    local has_changes=false

    # Check main repo
    if [[ -n $(git -C "$SCRIPT_DIR" status --porcelain) ]]; then
        log WARN "FreegleDocker has uncommitted changes."
        has_changes=true
    fi

    # Check submodules
    for submodule in iznik-nuxt3 iznik-nuxt3-modtools iznik-server iznik-server-go; do
        if [[ -d "$SCRIPT_DIR/$submodule" ]]; then
            if [[ -n $(git -C "$SCRIPT_DIR/$submodule" status --porcelain) ]]; then
                log WARN "$submodule has uncommitted changes."
                has_changes=true
            fi
        fi
    done

    if $has_changes; then
        log WARN "There are uncommitted changes. Claude will see these and can build on them."
    fi
}

run_local_tests() {
    log INFO "Running local tests before proceeding..."

    local test_failed=false

    # Check if status container is running
    if ! docker ps | grep -q status; then
        log WARN "Status container not running. Starting docker-compose..."
        docker-compose up -d status
        sleep 10
    fi

    # Run Go tests
    log INFO "Running Go API tests..."
    if ! curl -s -X POST http://localhost:8081/api/tests/go | grep -q '"success":true'; then
        log ERROR "Go tests failed!"
        test_failed=true
    else
        log SUCCESS "Go tests passed."
    fi

    # Run PHPUnit tests
    log INFO "Running PHPUnit tests..."
    if ! curl -s -X POST http://localhost:8081/api/tests/php | grep -q '"success":true'; then
        log ERROR "PHPUnit tests failed!"
        test_failed=true
    else
        log SUCCESS "PHPUnit tests passed."
    fi

    # Run Laravel tests
    log INFO "Running Laravel tests..."
    if ! curl -s -X POST http://localhost:8081/api/tests/laravel | grep -q '"success":true'; then
        log ERROR "Laravel tests failed!"
        test_failed=true
    else
        log SUCCESS "Laravel tests passed."
    fi

    # Run Playwright tests
    log INFO "Running Playwright tests..."
    if ! curl -s -X POST http://localhost:8081/api/tests/playwright | grep -q '"success":true'; then
        log ERROR "Playwright tests failed!"
        test_failed=true
    else
        log SUCCESS "Playwright tests passed."
    fi

    if $test_failed; then
        return 1
    fi
    return 0
}

check_circleci_status() {
    log INFO "Checking CircleCI status..."

    # Source .env for CircleCI token
    if [[ -f "$SCRIPT_DIR/.env" ]]; then
        source "$SCRIPT_DIR/.env"
    fi

    if [[ -z "$CIRCLECI_TOKEN" ]]; then
        log WARN "CIRCLECI_TOKEN not set. Cannot check CI status."
        return 0
    fi

    # Get latest pipeline status
    local status=$(curl -s -H "Circle-Token: $CIRCLECI_TOKEN" \
        "https://circleci.com/api/v2/project/github/Freegle/FreegleDocker/pipeline?branch=master" \
        | jq -r '.items[0].state // "unknown"')

    log INFO "Latest CircleCI pipeline status: $status"

    if [[ "$status" == "failed" ]]; then
        log WARN "Latest CI pipeline failed. Check before pushing."
        return 1
    fi

    return 0
}

build_prompt() {
    local plan_content=$(cat "$PLAN_FILE")
    local context_summary=$(get_context_summary)

    cat << PROMPT_EOF
# Task: Execute Plan (Iteration $ITERATION of $MAX_ITERATIONS)

You are executing a plan iteratively using the Ralph approach. Each iteration
builds on the previous work. Review what has been done and continue progressing.

## The Plan

$plan_content

## Context Summary (from previous iterations)

$context_summary

$(load_coding_standards)

## Your Task for This Iteration

1. **Orient yourself**: Run pwd to confirm working directory. Check git status and git log to understand current state.
2. **Review progress**: Read the plan and progress log above. Identify what has been completed and what remains.
3. **Make incremental progress**: Work on ONE incomplete item at a time. Do not try to do too much in one iteration.
4. **Validate your changes**:
   - Run eslint --fix on changed files.
   - For front-end changes, use Chrome DevTools MCP to visually verify.
   - For email changes, use MailPit to inspect output.
   - For API/backend changes, ensure test coverage of at least 90% on touched modules.
   - Do NOT commit yet - tests must pass first.
5. **Update the status table** in the plan file:
   - Mark your task as ✅ Complete if finished and tested.
   - Mark the next task as 🔄 In Progress if you're continuing.
   - Add notes about what was done or any blockers.
6. **Summarise what you accomplished** - this will be appended to the progress log for audit trail.

## Critical Rules

- NEVER mark a feature as complete without end-to-end testing.
- NEVER accept flaky tests - fix the root cause instead of adding retries.
- NEVER skip tests or make coverage optional.
- Leave the codebase in a clean state for the next iteration.

## Completion Marker

When the ENTIRE plan is complete and all tests pass, include this exact line:
PLAN_COMPLETE_MARKER_12345

If you need user input or are blocked, include this exact line:
NEEDS_USER_INPUT_MARKER_12345

## Important Reminders

- Each iteration should make meaningful progress on ONE item.
- Keep CI green - don't commit broken code.
- Run local tests before any commits.
- Check the plan's success criteria to know when you're done.
PROMPT_EOF
}

run_iteration() {
    ITERATION=$((ITERATION + 1))
    log INFO "========== Starting iteration $ITERATION of $MAX_ITERATIONS =========="

    local prompt=$(build_prompt)
    local prompt_file=$(mktemp)
    echo "$prompt" > "$prompt_file"

    log INFO "Running Claude with plan prompt..."

    # Run Claude and capture output
    local output_file=$(mktemp)

    # Use --dangerously-skip-permissions for automation
    # Use --max-turns for context window management
    if claude --dangerously-skip-permissions \
              --output-format text \
              --max-turns "$MAX_TURNS" \
              < "$prompt_file" \
              > "$output_file" 2>&1; then
        log SUCCESS "Claude completed iteration $ITERATION."
    else
        log ERROR "Claude failed during iteration $ITERATION."
        cat "$output_file" >> "$PROGRESS_FILE"
        rm -f "$prompt_file" "$output_file"
        save_state  # Save state even on failure
        return 1
    fi

    # Check output for completion markers
    local output=$(cat "$output_file")

    # Log a summary of what was done
    log INFO "Claude output summary saved to progress file."
    echo "--- Iteration $ITERATION Output ---" >> "$PROGRESS_FILE"
    echo "$output" >> "$PROGRESS_FILE"
    echo "--- End Iteration $ITERATION ---" >> "$PROGRESS_FILE"

    rm -f "$prompt_file" "$output_file"

    # Save state after each iteration for resume capability
    save_state

    # Check for completion marker
    if echo "$output" | grep -q "PLAN_COMPLETE_MARKER_12345"; then
        log SUCCESS "Plan marked as complete!"
        return 2  # Special return code for completion
    fi

    # Check for user input needed
    if echo "$output" | grep -q "NEEDS_USER_INPUT_MARKER_12345"; then
        log WARN "Claude needs user input. Check progress file for details."
        return 3  # Special return code for user input needed
    fi

    return 0
}

verify_and_commit() {
    log INFO "Plan complete. Running final verification..."

    # Run all tests
    if ! run_local_tests; then
        log ERROR "Final tests failed. Cannot commit."
        return 1
    fi

    log SUCCESS "All tests passed. Ready for commit."
    log INFO "Review changes with: git status && git diff"
    log INFO "To commit, run: git add -A && git commit -m 'Your message'"
    log WARN "Remember: Do NOT push without explicit user instruction."

    return 0
}

show_summary() {
    echo ""
    echo "=========================================="
    echo "              Ralph Summary              "
    echo "=========================================="
    echo ""
    echo "Plan file: $PLAN_FILE"
    echo "Iterations run: $ITERATION"
    echo "Progress file: $PROGRESS_FILE"
    echo ""
    echo "To review progress:"
    echo "  cat $PROGRESS_FILE"
    echo ""
    echo "To continue from where we left off:"
    echo "  $0 $PLAN_FILE $MAX_ITERATIONS"
    echo ""
}

# =============================================================================
# Main
# =============================================================================

main() {
    echo ""
    echo "  🦛 Ralph - Iterative AI Coding Agent"
    echo "  ===================================="
    echo ""

    # Parse command line arguments
    parse_args "$@"

    # Show usage if no input provided
    if [[ -z "$PLAN_FILE" && -z "$TASK_STRING" ]]; then
        echo "Usage:"
        echo "  $0 <plan-file> [max-iterations]           - Execute a plan file"
        echo "  $0 -t \"task description\" [max-iterations] - Execute an explicit task"
        echo ""
        echo "Examples:"
        echo "  $0 plans/active/my-feature.md 10"
        echo "  $0 -t \"Fix failing CircleCI tests\" 5"
        echo "  $0 -t \"Add unit tests for UserController\""
        echo ""
        echo "Available plans:"
        find "$SCRIPT_DIR/plans" -name "*.md" -type f 2>/dev/null | head -20
        exit 1
    fi

    check_prerequisites

    # If task mode, create temporary plan before logging setup
    if $TASK_MODE; then
        # Need to set up log dir first for log function
        mkdir -p "$LOG_DIR"
        local task_slug=$(echo "$TASK_STRING" | tr '[:upper:]' '[:lower:]' | sed 's/[^a-z0-9]/-/g' | sed 's/--*/-/g' | cut -c1-30)
        PROGRESS_FILE="$LOG_DIR/${task_slug}-$(date '+%Y%m%d-%H%M%S').log"
        create_temp_plan
    else
        setup_logging
    fi

    setup_state
    check_git_status

    # Run initializer agent on first run to set up status table
    if $IS_FIRST_RUN; then
        run_initializer
    fi

    log INFO "Starting Ralph loop..."

    while [[ $ITERATION -lt $MAX_ITERATIONS ]]; do
        run_iteration
        local result=$?

        case $result in
            0)
                # Normal completion, continue to next iteration
                log INFO "Iteration $ITERATION completed. Continuing..."
                sleep 2
                ;;
            1)
                # Error occurred
                log ERROR "Iteration failed. Stopping."
                show_summary
                exit 1
                ;;
            2)
                # Plan complete
                log SUCCESS "Plan execution complete!"
                verify_and_commit
                show_summary
                exit 0
                ;;
            3)
                # User input needed
                log WARN "User input required. Pausing."
                show_summary
                exit 0
                ;;
        esac
    done

    log WARN "Reached maximum iterations ($MAX_ITERATIONS)."
    log INFO "Plan may not be complete. Review progress and run again if needed."
    show_summary
}

main "$@"
