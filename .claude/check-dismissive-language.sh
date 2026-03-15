#!/bin/bash
# Stop hook: Detect dismissive language about test failures.
# Forces Claude to investigate and fix failures instead of dismissing them.

INPUT=$(cat)
LAST_MESSAGE=$(echo "$INPUT" | jq -r '.last_assistant_message // ""')
STOP_ACTIVE=$(echo "$INPUT" | jq -r '.stop_hook_active // false')

# Prevent infinite loops - only intervene once
if [ "$STOP_ACTIVE" = "true" ]; then
  exit 0
fi

# If message is empty or very short, skip
if [ ${#LAST_MESSAGE} -lt 20 ]; then
  exit 0
fi

# Patterns that dismiss test failures instead of fixing them.
# Each pattern is case-insensitive and targets phrases used to avoid investigation.
DISMISSIVE_PATTERNS=(
  'pre-existing'
  'pre existing'
  'already fail'
  'already broken'
  'already known'
  'not caused by'
  'not related to'
  'unrelated.*(fail|test|error)'
  '(fail|test|error).*unrelated'
  'known (issue|failure|flak)'
  'skip.*(fail|test)'
  'ignore.*(fail|test)'
  'not our (fault|problem|issue)'
  'nothing to do with'
  'beyond the scope'
  'out of scope.*(test|fail)'
  'can be addressed later'
  'separate issue'
  'existing (bug|issue|problem|failure)'
  'was already'
  'were already'
)

MATCHED=""
for pattern in "${DISMISSIVE_PATTERNS[@]}"; do
  if echo "$LAST_MESSAGE" | grep -iqE "$pattern"; then
    MATCHED=$(echo "$LAST_MESSAGE" | grep -ioE "$pattern" | head -1)
    break
  fi
done

if [ -n "$MATCHED" ]; then
  cat <<EOF
{
  "decision": "block",
  "reason": "STOP. You used dismissive language about test failures: '$MATCHED'. Per CLAUDE.md: NEVER dismiss test failures as pre-existing or unrelated. ALL test failures must be investigated and fixed. Go back and: 1) Identify the actual root cause of each failing test. 2) Fix the failures. 3) Verify the fixes work. Do not proceed until all tests pass."
}
EOF
  exit 0
fi

exit 0
