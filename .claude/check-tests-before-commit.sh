#!/bin/bash
# Claude hook: remind to add tests and run suites before committing

# Check if the commit message mentions a fix or feature
COMMIT_MSG=$(cat "$1" 2>/dev/null || echo "")

# Check for code changes (not just test files)
STAGED_CODE=$(git diff --cached --name-only | grep -v "test\|spec\|__test__" | grep -E "\.(go|vue|js|ts|php)$")
STAGED_TESTS=$(git diff --cached --name-only | grep -E "(test|spec)\.(go|js|ts|vue|php)$")

if [ -n "$STAGED_CODE" ] && [ -z "$STAGED_TESTS" ]; then
    echo "WARNING: You are committing code changes without any test changes."
    echo "Code files: $STAGED_CODE"
    echo ""
    echo "Have you:"
    echo "  1. Written tests for this change?"
    echo "  2. Run the relevant test suite (Go, Vitest, Playwright)?"
    echo ""
    echo "If tests aren't needed, proceed. Otherwise add tests first."
fi
