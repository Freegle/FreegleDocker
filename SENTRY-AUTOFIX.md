# Sentry Auto-Fix Integration

Automatically analyzes Sentry issues using Claude Code CLI and creates PRs with fixes and test cases.

## Requirements

- Node.js 18+ installed on the host
- Claude Code CLI installed (`claude` command available)  
- Sentry API auth token

## Installation

1. Install Node.js dependencies:
```bash
cd /home/edward/FreegleDocker
npm install dotenv better-sqlite3
```

2. Ensure Claude CLI is installed:
```bash
claude --version
```

3. Configure Sentry in `.env`:
```bash
SENTRY_AUTH_TOKEN=sntryu_your_token_here
SENTRY_ORG_SLUG=freegle
```

## Usage

```bash
# Analyze one issue and exit
./sentry-autofix --poll-one

# Run continuously (every 30 min)
./sentry-autofix --daemon 30

# Show help
./sentry-autofix --help
```

See full documentation in the script help output.
