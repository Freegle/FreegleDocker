#!/bin/bash
# PreToolUse hook for git commit: remind to check tests
# Non-blocking — outputs a systemMessage reminder

cat <<'EOF'
{"systemMessage": "Pre-commit checklist: a) Have you added a test for this change? b) Have you run that test? c) Have you run the full test suite via the status API (Go: docker exec freegle-apiv2; Laravel: curl -X POST http://localhost:8081/api/tests/laravel)?"}
EOF
