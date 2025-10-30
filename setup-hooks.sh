#!/usr/bin/env bash
#
# Setup script for Git hooks
# Works on both Linux and Windows (Git Bash/WSL/MINGW)

# Print colorful messages
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BLUE='\033[0;34m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# Echo with color and emoji
info() {
  echo -e "${BLUE}ℹ️ $1${NC}"
}

success() {
  echo -e "${GREEN}✅ $1${NC}"
}

warn() {
  echo -e "${YELLOW}⚠️ $1${NC}"
}

error() {
  echo -e "${RED}❌ $1${NC}"
}

# Detect operating system
case "$(uname -s)" in
  CYGWIN*|MINGW*|MSYS*)
    IS_WINDOWS=1
    info "Windows environment detected"
    ;;
  *)
    IS_WINDOWS=0
    info "Unix-like environment detected"
    ;;
esac

# Get the path to the project root
PROJECT_ROOT="$(git rev-parse --show-toplevel)"
info "Project root: $PROJECT_ROOT"

# Change to the project root directory
cd "$PROJECT_ROOT" || {
  error "Failed to cd to $PROJECT_ROOT"
  exit 1
}

# Create git hooks directory if it doesn't exist
mkdir -p .git/hooks

# Check if pre-push hook exists
if [ ! -f .git/hooks/pre-push ]; then
    warn "pre-push hook not found - this is unexpected!"
    warn "The hook should already exist in .git/hooks/"
    exit 1
fi

info "pre-push hook already installed"

# Ensure the hook has Unix line endings (LF not CRLF)
if [ "$IS_WINDOWS" -eq 1 ]; then
  info "Ensuring Unix line endings for hooks..."
  dos2unix .git/hooks/pre-push 2>/dev/null || tr -d '\r' < .git/hooks/pre-push > .git/hooks/pre-push.tmp && mv .git/hooks/pre-push.tmp .git/hooks/pre-push
  dos2unix .git/hooks/post-checkout 2>/dev/null || tr -d '\r' < .git/hooks/post-checkout > .git/hooks/post-checkout.tmp && mv .git/hooks/post-checkout.tmp .git/hooks/post-checkout
fi

# Make hooks executable (platform-specific)
if [ "$IS_WINDOWS" -eq 1 ]; then
  # On Windows, set git config core.fileMode false to ignore executable bit
  git config core.fileMode false
  info "On Windows, executable permissions are handled by Git"
else
  # On Unix-like systems, use chmod
  chmod +x .git/hooks/pre-push
  chmod +x .git/hooks/post-checkout
  success "File permissions set correctly"
fi

success "Git hooks are ready!"
success ""
success "The pre-push hook will ensure submodule commits are pushed before the parent repo."
success "If you use PhpStorm, see the PhpStorm configuration instructions in the documentation."
