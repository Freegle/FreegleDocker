#!/bin/bash
#
# Script to push submodules before pushing parent repository
# This ensures the pre-push hook doesn't block the push
#

set -e  # Exit on any error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}════════════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}  Pushing Submodules First, Then Parent Repository${NC}"
echo -e "${BLUE}════════════════════════════════════════════════════════════${NC}"
echo ""

# Check if there are any submodules
if [ ! -f .gitmodules ]; then
    echo -e "${YELLOW}⚠️  No submodules found in this repository${NC}"
    echo -e "${BLUE}Pushing parent repository...${NC}"
    git push "$@"
    exit 0
fi

# Get list of submodules that need pushing
SUBMODULES_TO_PUSH=""
SUBMODULES_CLEAN=""

echo -e "${BLUE}🔍 Checking submodules for unpushed commits...${NC}"
echo ""

git submodule foreach --quiet '
    SUBMODULE_NAME=$name
    SUBMODULE_PATH=$path

    # Check if submodule has unpushed commits
    REMOTE=$(git remote | head -n1)
    if [ -z "$REMOTE" ]; then
        echo "⚠️  Submodule $SUBMODULE_NAME has no remote configured - skipping"
        exit 0
    fi

    # Fetch to get latest remote refs
    git fetch "$REMOTE" --quiet 2>/dev/null || true

    # Get current branch
    BRANCH=$(git rev-parse --abbrev-ref HEAD)

    # Check if there are commits to push
    COMMITS_TO_PUSH=$(git log "$REMOTE/$BRANCH..$BRANCH" --oneline 2>/dev/null | wc -l || echo "0")

    if [ "$COMMITS_TO_PUSH" -gt 0 ]; then
        echo "📤 $SUBMODULE_NAME ($SUBMODULE_PATH): $COMMITS_TO_PUSH commit(s) to push"
    else
        echo "✅ $SUBMODULE_NAME ($SUBMODULE_PATH): Already up to date"
    fi
'

echo ""
echo -e "${BLUE}📤 Pushing all submodules...${NC}"
echo ""

# Push all submodules
git submodule foreach '
    REMOTE=$(git remote | head -n1)
    if [ -z "$REMOTE" ]; then
        echo "⚠️  Skipping $name (no remote)"
        exit 0
    fi

    BRANCH=$(git rev-parse --abbrev-ref HEAD)
    echo "Pushing $name ($BRANCH branch)..."

    if git push "$REMOTE" "$BRANCH"; then
        echo "✅ $name pushed successfully"
    else
        echo "❌ Failed to push $name"
        exit 1
    fi
    echo ""
'

if [ $? -ne 0 ]; then
    echo ""
    echo -e "${RED}════════════════════════════════════════════════════════════${NC}"
    echo -e "${RED}  ❌ Failed to push one or more submodules${NC}"
    echo -e "${RED}════════════════════════════════════════════════════════════${NC}"
    exit 1
fi

echo -e "${GREEN}✅ All submodules pushed successfully!${NC}"
echo ""
echo -e "${BLUE}📤 Now pushing parent repository...${NC}"
echo ""

# Push parent repository (pass through any arguments like --force, --dry-run, etc.)
if git push "$@"; then
    echo ""
    echo -e "${GREEN}════════════════════════════════════════════════════════════${NC}"
    echo -e "${GREEN}  ✅ Successfully pushed all submodules and parent repository${NC}"
    echo -e "${GREEN}════════════════════════════════════════════════════════════${NC}"
    exit 0
else
    echo ""
    echo -e "${RED}════════════════════════════════════════════════════════════${NC}"
    echo -e "${RED}  ❌ Failed to push parent repository${NC}"
    echo -e "${RED}════════════════════════════════════════════════════════════${NC}"
    exit 1
fi
