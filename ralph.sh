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
IN_PROGRESS_DIR="$SCRIPT_DIR/plans/in-progress"
PROGRESS_FILE=""
STATE_FILE=""
MAX_ITERATIONS=10
ITERATION=0
PLAN_FILE=""
TASK_MODE=false
TASK_STRING=""
IS_FIRST_RUN=false
CODING_STANDARDS_HASH=""
CODING_STANDARDS_CHANGED=false

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
RALPH_SKILL_FILE="$SCRIPT_DIR/.claude/skills/ralph/SKILL.md"

load_coding_standards() {
    if [[ -f "$CODING_STANDARDS_FILE" ]]; then
        cat "$CODING_STANDARDS_FILE"
    else
        echo "WARNING: codingstandards.md not found at $CODING_STANDARDS_FILE"
    fi
}

load_ralph_skill() {
    # Load the Ralph skill methodology, stripping YAML frontmatter
    if [[ -f "$RALPH_SKILL_FILE" ]]; then
        # Skip first 4 lines (YAML frontmatter: ---, name, description, ---)
        tail -n +5 "$RALPH_SKILL_FILE"
    else
        echo "WARNING: Ralph skill not found at $RALPH_SKILL_FILE"
    fi
}

get_coding_standards_hash() {
    if [[ -f "$CODING_STANDARDS_FILE" ]]; then
        md5sum "$CODING_STANDARDS_FILE" | cut -d' ' -f1
    else
        echo "none"
    fi
}

check_coding_standards_changed() {
    local current_hash
    current_hash=$(get_coding_standards_hash)

    if [[ -n "$CODING_STANDARDS_HASH" && "$current_hash" != "$CODING_STANDARDS_HASH" ]]; then
        CODING_STANDARDS_CHANGED=true
        log WARN "Coding standards changed during iteration. Will re-evaluate in next iteration."

        # Show what changed
        echo "--- Coding Standards Changes ---" >> "$PROGRESS_FILE"
        echo "Previous hash: $CODING_STANDARDS_HASH" >> "$PROGRESS_FILE"
        echo "Current hash: $current_hash" >> "$PROGRESS_FILE"
        echo "--- End Changes ---" >> "$PROGRESS_FILE"
    else
        CODING_STANDARDS_CHANGED=false
    fi

    # Update hash for next check
    CODING_STANDARDS_HASH="$current_hash"
}

get_coding_standards_change_notice() {
    if $CODING_STANDARDS_CHANGED; then
        cat << 'NOTICE_EOF'

## ⚠️ CODING STANDARDS CHANGED

The coding standards (codingstandards.md) were modified during the previous iteration.
Before continuing with new work, you MUST:

1. **Re-read the coding standards** - Run: cat codingstandards.md
2. **Review your recent changes** - Check if any completed work violates the new standards.
3. **Apply new standards** - If the new standards affect your work, update the code accordingly.
4. **Update task status** - If a "complete" task now needs rework, mark it as 🔄 In Progress.

Do NOT mark the plan as complete until all work complies with the updated standards.

NOTICE_EOF
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
LAST_GIT_COMMIT="$(git rev-parse HEAD 2>/dev/null || echo "unknown")"
LAST_RUN="$(date '+%Y-%m-%d %H:%M:%S')"
EOF
}

move_plan_to_in_progress() {
    # Move plan from plans/ to plans/in-progress/ when starting work
    mkdir -p "$IN_PROGRESS_DIR"

    local plan_dir=$(dirname "$PLAN_FILE")
    local plan_name=$(basename "$PLAN_FILE")

    # Only move if it's in plans/ but not already in in-progress/
    if [[ "$plan_dir" == "$SCRIPT_DIR/plans" && ! -f "$IN_PROGRESS_DIR/$plan_name" ]]; then
        mv "$PLAN_FILE" "$IN_PROGRESS_DIR/$plan_name"
        PLAN_FILE="$IN_PROGRESS_DIR/$plan_name"
        log INFO "Moved plan to in-progress: $PLAN_FILE"
    elif [[ "$plan_dir" == "$SCRIPT_DIR/plans/"* && "$plan_dir" != *"in-progress"* ]]; then
        # Plan is in a subfolder of plans/ but not in-progress
        mv "$PLAN_FILE" "$IN_PROGRESS_DIR/$plan_name"
        PLAN_FILE="$IN_PROGRESS_DIR/$plan_name"
        log INFO "Moved plan to in-progress: $PLAN_FILE"
    fi
}

get_open_prs() {
    # Get list of open PRs across all submodules
    local prs=""

    for repo in "iznik-nuxt3" "iznik-nuxt3-modtools" "iznik-server" "iznik-server-go" "iznik-batch"; do
        if [[ -d "$SCRIPT_DIR/$repo" ]]; then
            local repo_prs
            repo_prs=$(cd "$SCRIPT_DIR/$repo" && gh pr list --state open --json number,title,url 2>/dev/null || echo "[]")
            if [[ "$repo_prs" != "[]" ]]; then
                prs+="### $repo PRs\n$repo_prs\n\n"
            fi
        fi
    done

    # Also check FreegleDocker itself
    local docker_prs
    docker_prs=$(cd "$SCRIPT_DIR" && gh pr list --state open --json number,title,url 2>/dev/null || echo "[]")
    if [[ "$docker_prs" != "[]" ]]; then
        prs+="### FreegleDocker PRs\n$docker_prs\n\n"
    fi

    echo -e "$prs"
}

get_pr_feedback() {
    # Get feedback (comments, reviews, CI status) on open PRs
    # This helps Claude understand what reviewers are asking for
    local feedback=""

    for repo in "iznik-nuxt3" "iznik-nuxt3-modtools" "iznik-server" "iznik-server-go" "iznik-batch"; do
        if [[ -d "$SCRIPT_DIR/$repo" ]]; then
            # Get open PRs
            local prs_json
            prs_json=$(cd "$SCRIPT_DIR/$repo" && gh pr list --state open --json number,title,headRefName 2>/dev/null || echo "[]")

            # Parse each PR and get its feedback
            local pr_numbers
            pr_numbers=$(echo "$prs_json" | jq -r '.[].number' 2>/dev/null)

            for pr_num in $pr_numbers; do
                [[ -z "$pr_num" ]] && continue

                # Get PR reviews and comments
                local pr_details
                pr_details=$(cd "$SCRIPT_DIR/$repo" && gh pr view "$pr_num" \
                    --json title,reviews,comments,statusCheckRollup,headRefName 2>/dev/null || echo "{}")

                local pr_title
                pr_title=$(echo "$pr_details" | jq -r '.title // "Unknown"')
                local branch_name
                branch_name=$(echo "$pr_details" | jq -r '.headRefName // "unknown"')

                # Get reviews that request changes or have comments
                local reviews
                reviews=$(echo "$pr_details" | jq -r '.reviews[]? | select(.state == "CHANGES_REQUESTED" or .state == "COMMENTED") | "[\(.state)] \(.author.login): \(.body // "No comment")"' 2>/dev/null)

                # Get unresolved comments (excluding bot comments)
                local comments
                comments=$(echo "$pr_details" | jq -r '.comments[]? | select(.author.login != "github-actions" and (.body | test("^🤖") | not)) | "\(.author.login): \(.body)"' 2>/dev/null)

                # Get CI status
                local ci_status
                ci_status=$(echo "$pr_details" | jq -r '.statusCheckRollup[]? | select(.state != "SUCCESS") | "[\(.state)] \(.name // .context)"' 2>/dev/null)

                # Only include if there's actionable feedback
                if [[ -n "$reviews" || -n "$comments" || -n "$ci_status" ]]; then
                    feedback+="\n### $repo PR #$pr_num: $pr_title\n"
                    feedback+="Branch: $branch_name\n"

                    if [[ -n "$reviews" ]]; then
                        feedback+="\n**Review Feedback:**\n$reviews\n"
                    fi

                    if [[ -n "$comments" ]]; then
                        feedback+="\n**Comments:**\n$comments\n"
                    fi

                    if [[ -n "$ci_status" ]]; then
                        feedback+="\n**CI Issues:**\n$ci_status\n"
                    fi

                    feedback+="\n"
                fi
            done
        fi
    done

    # Also check FreegleDocker itself
    local docker_prs_json
    docker_prs_json=$(cd "$SCRIPT_DIR" && gh pr list --state open --json number 2>/dev/null || echo "[]")
    local docker_pr_nums
    docker_pr_nums=$(echo "$docker_prs_json" | jq -r '.[].number' 2>/dev/null)

    for pr_num in $docker_pr_nums; do
        [[ -z "$pr_num" ]] && continue

        local pr_details
        pr_details=$(cd "$SCRIPT_DIR" && gh pr view "$pr_num" \
            --json title,reviews,comments,statusCheckRollup,headRefName 2>/dev/null || echo "{}")

        local pr_title
        pr_title=$(echo "$pr_details" | jq -r '.title // "Unknown"')
        local branch_name
        branch_name=$(echo "$pr_details" | jq -r '.headRefName // "unknown"')

        local reviews
        reviews=$(echo "$pr_details" | jq -r '.reviews[]? | select(.state == "CHANGES_REQUESTED" or .state == "COMMENTED") | "[\(.state)] \(.author.login): \(.body // "No comment")"' 2>/dev/null)

        local comments
        comments=$(echo "$pr_details" | jq -r '.comments[]? | select(.author.login != "github-actions" and (.body | test("^🤖") | not)) | "\(.author.login): \(.body)"' 2>/dev/null)

        local ci_status
        ci_status=$(echo "$pr_details" | jq -r '.statusCheckRollup[]? | select(.state != "SUCCESS") | "[\(.state)] \(.name // .context)"' 2>/dev/null)

        if [[ -n "$reviews" || -n "$comments" || -n "$ci_status" ]]; then
            feedback+="\n### FreegleDocker PR #$pr_num: $pr_title\n"
            feedback+="Branch: $branch_name\n"

            if [[ -n "$reviews" ]]; then
                feedback+="\n**Review Feedback:**\n$reviews\n"
            fi

            if [[ -n "$comments" ]]; then
                feedback+="\n**Comments:**\n$comments\n"
            fi

            if [[ -n "$ci_status" ]]; then
                feedback+="\n**CI Issues:**\n$ci_status\n"
            fi

            feedback+="\n"
        fi
    done

    echo -e "$feedback"
}

run_code_quality_check() {
    # Run code quality checks on changed files only
    # This prevents flagging pre-existing issues while catching new problems
    log INFO "Running code quality checks on changed files..."

    local issues=""
    local has_issues=false

    # Get list of changed files (staged and unstaged)
    local changed_files
    changed_files=$(git diff --name-only HEAD 2>/dev/null)
    local staged_files
    staged_files=$(git diff --staged --name-only 2>/dev/null)
    local all_changed="$changed_files"$'\n'"$staged_files"

    # Filter by file type
    local go_files=$(echo "$all_changed" | grep '\.go$' | sort -u)
    local php_files=$(echo "$all_changed" | grep '\.php$' | sort -u)
    local js_files=$(echo "$all_changed" | grep -E '\.(js|ts|vue)$' | sort -u)

    # Check for copy-paste detection (jscpd)
    if command -v jscpd &> /dev/null && [[ -n "$all_changed" ]]; then
        log INFO "Running jscpd copy-paste detection..."
        local jscpd_result
        # Only check changed files by filtering to their directories
        local dirs_to_check=""
        for f in $all_changed; do
            [[ -f "$f" ]] && dirs_to_check+=" $(dirname "$f")"
        done
        dirs_to_check=$(echo "$dirs_to_check" | tr ' ' '\n' | sort -u | tr '\n' ' ')

        if [[ -n "$dirs_to_check" ]]; then
            jscpd_result=$(jscpd --min-lines 10 --min-tokens 50 --reporters console $dirs_to_check 2>&1 || true)
            if echo "$jscpd_result" | grep -q "Found.*clones"; then
                issues+="\n### Copy-Paste Detection (jscpd)\n$jscpd_result\n"
                has_issues=true
            fi
        fi
    fi

    # Check Go files with golangci-lint
    if command -v golangci-lint &> /dev/null && [[ -n "$go_files" ]]; then
        log INFO "Running golangci-lint on changed Go files..."
        for repo in "iznik-server-go"; do
            if [[ -d "$SCRIPT_DIR/$repo" ]]; then
                local repo_go_files=$(echo "$go_files" | grep "^$repo/" | sed "s|^$repo/||")
                if [[ -n "$repo_go_files" ]]; then
                    local lint_result
                    lint_result=$(cd "$SCRIPT_DIR/$repo" && golangci-lint run --new-from-rev=HEAD~1 2>&1 || true)
                    if [[ -n "$lint_result" && "$lint_result" != *"No issues"* ]]; then
                        issues+="\n### Go Lint Issues ($repo)\n$lint_result\n"
                        has_issues=true
                    fi
                fi
            fi
        done
    fi

    # Check PHP files with PHPStan (if available)
    if [[ -n "$php_files" ]]; then
        for repo in "iznik-server" "iznik-batch"; do
            if [[ -d "$SCRIPT_DIR/$repo" ]]; then
                local repo_php_files=$(echo "$php_files" | grep "^$repo/" | sed "s|^$repo/||")
                if [[ -n "$repo_php_files" ]]; then
                    # Run PHPStan via docker if available
                    if docker ps | grep -q apiv1; then
                        log INFO "Running PHPStan on changed PHP files in $repo..."
                        local phpstan_result
                        # Run phpstan on specific files
                        for pf in $repo_php_files; do
                            local container_path="/var/www/iznik/$pf"
                            phpstan_result+=$(docker exec freegle-apiv1 sh -c \
                                "cd /var/www/iznik && php composer/vendor/bin/phpstan analyse --memory-limit=512M --no-progress $container_path 2>&1" || true)
                        done
                        if echo "$phpstan_result" | grep -qE '\[ERROR\]|Line\s+[0-9]+'; then
                            issues+="\n### PHP Static Analysis ($repo)\n$phpstan_result\n"
                            has_issues=true
                        fi
                    fi
                fi
            fi
        done
    fi

    # Return issues summary
    if $has_issues; then
        echo -e "$issues"
    fi
}

get_code_quality_issues() {
    # Wrapper to get code quality issues for the prompt
    local quality_issues
    quality_issues=$(run_code_quality_check 2>/dev/null)
    echo -e "$quality_issues"
}

get_circleci_failures() {
    # Get CircleCI failures for the current branch
    local failures=""

    # Source .env for CircleCI token
    if [[ -f "$SCRIPT_DIR/.env" ]]; then
        source "$SCRIPT_DIR/.env"
    fi

    if [[ -z "$CIRCLECI_TOKEN" ]]; then
        return  # No token, skip
    fi

    # Get recent failed pipelines
    local recent_pipelines
    recent_pipelines=$(curl -s -H "Circle-Token: $CIRCLECI_TOKEN" \
        "https://circleci.com/api/v2/project/github/Freegle/FreegleDocker/pipeline?branch=master" 2>/dev/null)

    local failed_pipeline_id
    failed_pipeline_id=$(echo "$recent_pipelines" | jq -r '.items[0] | select(.state == "failed") | .id' 2>/dev/null)

    if [[ -n "$failed_pipeline_id" && "$failed_pipeline_id" != "null" ]]; then
        # Get workflow failures
        local workflows
        workflows=$(curl -s -H "Circle-Token: $CIRCLECI_TOKEN" \
            "https://circleci.com/api/v2/pipeline/$failed_pipeline_id/workflow" 2>/dev/null)

        local failed_workflows
        failed_workflows=$(echo "$workflows" | jq -r '.items[] | select(.status == "failed") | .name' 2>/dev/null)

        if [[ -n "$failed_workflows" ]]; then
            failures+="## CircleCI Failures\n\n"
            failures+="The latest pipeline has failures:\n"
            failures+="$failed_workflows\n\n"
            failures+="Check CircleCI for details: https://app.circleci.com/pipelines/github/Freegle/FreegleDocker\n"
        fi
    fi

    echo -e "$failures"
}

detect_schema_changes() {
    # Detect if there are pending database schema changes that need manual application.
    # Returns schema change summary if any are detected.
    local changes=""

    # Check for modified migration files in Laravel
    local laravel_migrations
    laravel_migrations=$(git -C "$SCRIPT_DIR/iznik-batch" diff --name-only HEAD 2>/dev/null | grep -E 'database/migrations/.*\.php$' || true)
    if [[ -n "$laravel_migrations" ]]; then
        changes+="### Laravel Migrations (iznik-batch)\n"
        changes+="$laravel_migrations\n\n"
    fi

    # Check for schema.sql changes
    local schema_changes
    schema_changes=$(git -C "$SCRIPT_DIR/iznik-server" diff --name-only HEAD 2>/dev/null | grep -E 'install/schema\.sql$|install/.*\.sql$' || true)
    if [[ -n "$schema_changes" ]]; then
        changes+="### Schema SQL Changes (iznik-server)\n"
        changes+="$schema_changes\n\n"
    fi

    # Check for ALTER TABLE in recent commits
    for repo in "iznik-server" "iznik-batch"; do
        if [[ -d "$SCRIPT_DIR/$repo" ]]; then
            local alter_statements
            alter_statements=$(git -C "$SCRIPT_DIR/$repo" diff HEAD 2>/dev/null | grep -E '^\+.*ALTER TABLE|^\+.*CREATE TABLE|^\+.*DROP TABLE' || true)
            if [[ -n "$alter_statements" ]]; then
                changes+="### SQL DDL Statements ($repo)\n"
                changes+="$alter_statements\n\n"
            fi
        fi
    done

    echo -e "$changes"
}

get_schema_change_notice() {
    local schema_changes
    schema_changes=$(detect_schema_changes)

    if [[ -n "$schema_changes" ]]; then
        cat << SCHEMA_EOF

## ⚠️ DATABASE SCHEMA CHANGES DETECTED

The following schema changes have been detected:

$schema_changes

**CRITICAL REQUIREMENTS:**

1. Schema changes must be **manually applied on the live database** before deployment.
2. Laravel migrations are primarily for tests - they will NOT auto-run in production.
3. All migrations MUST be idempotent (check if change already exists before applying).
4. You MUST pause and output NEEDS_USER_INPUT_MARKER_12345 to confirm schema changes have been applied.

**Before continuing, ask the user to confirm:**
- Have the schema changes been manually applied to production?
- Only proceed after confirmation.

SCHEMA_EOF
    fi
}

check_ci_verification() {
    # Verify that CI has passed before marking a plan complete.
    # Returns:
    #   0 = CI verified (passed)
    #   1 = CI not verified (failed, pending, or not pushed)
    #   2 = No CI token (can't verify)

    log INFO "Verifying CI status before completion..."

    # Source .env for CircleCI token
    if [[ -f "$SCRIPT_DIR/.env" ]]; then
        source "$SCRIPT_DIR/.env"
    fi

    if [[ -z "$CIRCLECI_TOKEN" ]]; then
        log WARN "CIRCLECI_TOKEN not set. Cannot verify CI status."
        log WARN "CI verification is REQUIRED before marking a plan complete."
        return 2
    fi

    # Check if there are unpushed commits
    local unpushed_count=0
    for repo in "." "iznik-nuxt3" "iznik-nuxt3-modtools" "iznik-server" "iznik-server-go" "iznik-batch"; do
        if [[ -d "$SCRIPT_DIR/$repo" ]]; then
            local ahead
            ahead=$(git -C "$SCRIPT_DIR/$repo" rev-list --count @{upstream}..HEAD 2>/dev/null || echo "0")
            if [[ "$ahead" -gt 0 ]]; then
                log WARN "$repo has $ahead unpushed commit(s)."
                unpushed_count=$((unpushed_count + ahead))
            fi
        fi
    done

    if [[ $unpushed_count -gt 0 ]]; then
        log ERROR "There are $unpushed_count unpushed commits across repositories."
        log ERROR "Changes must be pushed and CI must pass before completion."
        return 1
    fi

    # Get latest pipeline for master branch
    local recent_pipelines
    recent_pipelines=$(curl -s -H "Circle-Token: $CIRCLECI_TOKEN" \
        "https://circleci.com/api/v2/project/github/Freegle/FreegleDocker/pipeline?branch=master" 2>/dev/null)

    local latest_state
    latest_state=$(echo "$recent_pipelines" | jq -r '.items[0].state // "unknown"' 2>/dev/null)
    local latest_id
    latest_id=$(echo "$recent_pipelines" | jq -r '.items[0].id // "unknown"' 2>/dev/null)

    log INFO "Latest CircleCI pipeline state: $latest_state"

    case "$latest_state" in
        "created"|"pending")
            log WARN "CI pipeline is pending. Waiting for completion..."
            return 1
            ;;
        "running")
            log WARN "CI pipeline is still running. Waiting for completion..."
            return 1
            ;;
        "failed"|"error")
            log ERROR "CI pipeline FAILED. Cannot mark plan as complete."
            log ERROR "View details: https://app.circleci.com/pipelines/github/Freegle/FreegleDocker"
            return 1
            ;;
        "success")
            # Get workflow status to ensure all workflows passed
            local workflows
            workflows=$(curl -s -H "Circle-Token: $CIRCLECI_TOKEN" \
                "https://circleci.com/api/v2/pipeline/$latest_id/workflow" 2>/dev/null)

            local failed_workflows
            failed_workflows=$(echo "$workflows" | jq -r '.items[] | select(.status != "success") | .name' 2>/dev/null)

            if [[ -n "$failed_workflows" ]]; then
                log ERROR "Some CI workflows did not pass: $failed_workflows"
                return 1
            fi

            log SUCCESS "CI verification PASSED. All workflows successful."
            return 0
            ;;
        *)
            log WARN "Unknown CI state: $latest_state. Cannot verify."
            return 1
            ;;
    esac
}

update_plan_with_prs() {
    # Add or update PR tracking section in the plan file
    local prs=$(get_open_prs)

    if [[ -n "$prs" && -f "$PLAN_FILE" ]]; then
        # Check if PR section exists
        if grep -q "## Associated PRs" "$PLAN_FILE"; then
            # Update existing section (remove old, add new)
            sed -i '/## Associated PRs/,/^## /{ /^## Associated PRs/!{ /^## /!d } }' "$PLAN_FILE"
            sed -i "s/## Associated PRs/## Associated PRs\n\n$prs/" "$PLAN_FILE"
        else
            # Add new section at end
            echo -e "\n## Associated PRs\n\n$prs" >> "$PLAN_FILE"
        fi
        log INFO "Updated plan with open PRs."
    fi
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
- ⏳ Waiting - waiting for deploy/external action
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

    # Create default progress file if not set
    if [[ -z "$PROGRESS_FILE" ]]; then
        mkdir -p "$LOG_DIR"
        PROGRESS_FILE="$LOG_DIR/ralph-$(date '+%Y%m%d-%H%M%S').log"
    fi
    echo "[$timestamp] [$level] $*" >> "$PROGRESS_FILE"
}

check_prerequisites() {
    log INFO "Checking prerequisites..."

    # Check claude CLI exists
    if ! command -v claude &> /dev/null; then
        log ERROR "claude CLI not found. Install Claude Code first."
        exit 1
    fi

    # Check plan file exists (skip in task mode - will be created later)
    if [[ "$TASK_MODE" != "true" ]] && [[ ! -f "$PLAN_FILE" ]]; then
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

check_integrations() {
    log INFO "Checking integrations and API keys..."

    local has_warnings=false

    # Source .env for tokens
    if [[ -f "$SCRIPT_DIR/.env" ]]; then
        source "$SCRIPT_DIR/.env"
    fi

    # Check CircleCI token
    if [[ -z "$CIRCLECI_TOKEN" ]]; then
        log WARN "CIRCLECI_TOKEN not set. Cannot check CI status automatically."
        has_warnings=true
    else
        # Verify token works
        if curl -s -H "Circle-Token: $CIRCLECI_TOKEN" \
            "https://circleci.com/api/v2/me" | grep -q '"id"'; then
            log SUCCESS "CircleCI token is valid."
        else
            log WARN "CircleCI token appears invalid. CI status checks will fail."
            has_warnings=true
        fi
    fi

    # Check git authentication (can we push?)
    if git ls-remote origin &>/dev/null; then
        log SUCCESS "Git authentication working."
    else
        log WARN "Git authentication may have issues. Check your SSH keys or credentials."
        has_warnings=true
    fi

    # Check if status container is accessible
    if curl -s -o /dev/null -w "%{http_code}" http://localhost:8081/ 2>/dev/null | grep -q "200"; then
        log SUCCESS "Status container is accessible."
    else
        log WARN "Status container not accessible at localhost:8081. Tests may fail."
        has_warnings=true
    fi

    # Check Chrome DevTools MCP connection (optional)
    # This is hard to check programmatically, but we can note it
    log INFO "Chrome DevTools MCP: Ensure browser is connected for UI validation."

    if $has_warnings; then
        log WARN "Some integrations have warnings. Review above before proceeding."
        echo ""
        read -p "Continue anyway? (y/n): " -n 1 -r
        echo ""
        if [[ ! $REPLY =~ ^[Yy]$ ]]; then
            log INFO "Aborted by user."
            exit 0
        fi
    else
        log SUCCESS "All integrations check passed."
    fi
}

check_container_health() {
    log INFO "Checking Docker container health..."

    # Required containers that must be running
    local required_containers=(
        "freegle-dev-local"
        "apiv1"
        "apiv2"
        "percona"
        "playwright"
        "batch"
    )

    # Optional containers - warn if down but don't block
    local optional_containers=(
        "modtools-dev-local"
        "phpmyadmin"
        "mailpit"
        "loki"
        "grafana"
        "tusd"
        "delivery"
    )

    # Fetch all container status
    local status_json
    status_json=$(curl -s http://localhost:8081/api/status/all 2>/dev/null)

    if [[ -z "$status_json" ]]; then
        log ERROR "Cannot fetch container status from status container."
        log ERROR "Ensure the status container is running: docker-compose up -d status"
        exit 1
    fi

    local has_required_failures=false
    local has_optional_warnings=false

    # Check required containers
    log INFO "Checking required containers..."
    for container in "${required_containers[@]}"; do
        local status
        status=$(echo "$status_json" | jq -r ".[\"$container\"].status // \"unknown\"")
        local message
        message=$(echo "$status_json" | jq -r ".[\"$container\"].message // \"No status\"")

        if [[ "$status" == "success" ]]; then
            log SUCCESS "$container: $message"
        else
            log ERROR "$container: $message"
            has_required_failures=true
        fi
    done

    # Check optional containers
    log INFO "Checking optional containers..."
    for container in "${optional_containers[@]}"; do
        local status
        status=$(echo "$status_json" | jq -r ".[\"$container\"].status // \"unknown\"")
        local message
        message=$(echo "$status_json" | jq -r ".[\"$container\"].message // \"No status\"")

        if [[ "$status" == "success" ]]; then
            log SUCCESS "$container: $message"
        elif [[ "$status" == "failed" && "$message" == "Container not found" ]]; then
            log INFO "$container: Not running (optional)"
        else
            log WARN "$container: $message"
            has_optional_warnings=true
        fi
    done

    # Block if required containers are down
    if $has_required_failures; then
        log ERROR "Required containers are not healthy. Cannot proceed."
        log ERROR "Start the required containers with: docker-compose up -d"
        exit 1
    fi

    # Warn about optional containers
    if $has_optional_warnings; then
        log WARN "Some optional containers have issues. Tests may be affected."
        echo ""
        read -p "Continue anyway? (y/n): " -n 1 -r
        echo ""
        if [[ ! $REPLY =~ ^[Yy]$ ]]; then
            log INFO "Aborted by user."
            exit 0
        fi
    fi

    log SUCCESS "Container health check passed."
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
    local pr_feedback=$(get_pr_feedback)
    local ci_failures=$(get_circleci_failures)
    local code_quality=$(get_code_quality_issues)

    cat << PROMPT_EOF
# Task: Execute Plan (Iteration $ITERATION of $MAX_ITERATIONS)

You are executing a plan iteratively using the Ralph approach. Each iteration
builds on the previous work. Review what has been done and continue progressing.

## Ralph Methodology (Single Source of Truth)

$(load_ralph_skill)

## The Plan

$plan_content

## Context Summary (from previous iterations)

$context_summary

$(get_coding_standards_change_notice)

$(get_schema_change_notice)

$(if [[ -n "$pr_feedback" ]]; then
cat << PR_EOF
## ⚠️ PR Feedback Requiring Attention

The following PRs have feedback that needs to be addressed:

$pr_feedback

**IMPORTANT:** Address this feedback BEFORE continuing with other work.
If CI is failing, fix those issues first. If reviewers have requested changes,
implement them before proceeding with new features.

PR_EOF
fi)

$(if [[ -n "$ci_failures" ]]; then
cat << CI_EOF
## ❌ CI Failures

$ci_failures

**IMPORTANT:** Fix CI failures before continuing with other work.
Run local tests to reproduce and fix the issues.

CI_EOF
fi)

$(if [[ -n "$code_quality" ]]; then
cat << QUALITY_EOF
## ⚠️ Code Quality Issues

The following code quality issues were detected in changed files:

$code_quality

**IMPORTANT:** Address these issues before committing:
- Refactor duplicated code to reduce copy-paste
- Fix any linting errors or warnings
- Resolve static analysis issues

QUALITY_EOF
fi)

$(load_coding_standards)

## Your Task for This Iteration

1. **Orient yourself**: Run pwd to confirm working directory. Check git status and git log to understand current state.
2. **Check for blocking issues**: If there is PR feedback, CI failures, or code quality issues above, address those FIRST before any other work.
3. **Review progress**: Read the plan and progress log above. Identify what has been completed and what remains.
4. **Make incremental progress**: Work on ONE incomplete item at a time. Do not try to do too much in one iteration.
5. **Validate your changes**:
   - Run eslint --fix on changed files.
   - For front-end changes, use Chrome DevTools MCP to visually verify.
   - For email changes, use MailPit to inspect output.
   - For API/backend changes, ensure test coverage of at least 90% on touched modules.
   - Do NOT commit yet - tests must pass first.
6. **Update the status table** in the plan file:
   - Mark your task as ✅ Complete if finished and tested.
   - Mark the next task as 🔄 In Progress if you're continuing.
   - Add notes about what was done or any blockers.
7. **Summarise what you accomplished** - this will be appended to the progress log for audit trail.

## Critical Rules

- ALWAYS address PR feedback, CI failures, and code quality issues before starting new work.
- NEVER mark a feature as complete without end-to-end testing.
- NEVER accept flaky tests - fix the root cause instead of adding retries.
- NEVER skip tests or make coverage optional.
- NEVER commit code with duplicate/copy-paste patterns - refactor to share logic.
- NEVER mark a plan complete until CI has verified the changes (local tests are NOT sufficient).
- Leave the codebase in a clean state for the next iteration.

## Completion Requirements

Before outputting PLAN_COMPLETE_MARKER_12345, ensure ALL of these are true:

1. **All tasks in the plan are complete** - Check the status table.
2. **Local tests pass** - Run all test suites via status container.
3. **Changes are committed** - No uncommitted changes.
4. **Changes are pushed** - Push to trigger CI.
5. **CI has passed** - Wait for CircleCI pipeline to complete successfully.

If any of these are not met, continue working on the plan. Output the completion marker ONLY when all criteria are satisfied.

## Completion Marker

When the ENTIRE plan is complete, all tests pass, AND CI has verified the changes, include this exact line:
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

    # Record coding standards hash at start of iteration
    CODING_STANDARDS_HASH=$(get_coding_standards_hash)

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

    # Check if coding standards changed during this iteration
    check_coding_standards_changed

    # Check for completion marker
    if echo "$output" | grep -q "PLAN_COMPLETE_MARKER_12345"; then
        # If standards changed, don't allow completion - need re-evaluation
        if $CODING_STANDARDS_CHANGED; then
            log WARN "Plan marked complete but coding standards changed. Continuing for re-evaluation."
            return 0
        fi

        # Check for uncommitted schema changes that need manual production application
        local schema_changes
        schema_changes=$(detect_schema_changes)
        if [[ -n "$schema_changes" ]]; then
            log WARN "Schema changes detected that may require manual production application."
            log WARN "Ensure schema changes have been applied to production before completing."
            echo ""
            echo "Schema changes detected:"
            echo -e "$schema_changes"
            echo ""
            read -p "Have these schema changes been applied to production? (y/n): " -n 1 -r
            echo ""
            if [[ ! $REPLY =~ ^[Yy]$ ]]; then
                log WARN "Schema changes not confirmed. Cannot mark complete."
                return 0
            fi
            log SUCCESS "Schema changes confirmed as applied."
        fi

        # CRITICAL: Verify CI has passed before accepting completion
        log INFO "Plan marked complete. Verifying CI status..."
        check_ci_verification
        local ci_result=$?

        case $ci_result in
            0)
                log SUCCESS "CI verification passed. Plan is truly complete!"
                return 2  # Special return code for completion
                ;;
            1)
                log WARN "CI verification FAILED. Plan cannot be marked complete yet."
                log WARN "Claude should push changes and wait for CI to pass."
                # Continue iterating - Claude needs to push and verify
                return 0
                ;;
            2)
                log WARN "Cannot verify CI (no token). Proceeding with caution."
                log WARN "MANUAL VERIFICATION REQUIRED: Check CircleCI before considering complete."
                return 2  # Allow completion but with warning
                ;;
        esac
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
    check_integrations
    check_container_health

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

    # Move plan to in-progress folder (if from plans/ directory)
    if [[ "$TASK_MODE" != "true" ]]; then
        move_plan_to_in_progress
    fi

    # Update plan with any open PRs
    update_plan_with_prs

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
