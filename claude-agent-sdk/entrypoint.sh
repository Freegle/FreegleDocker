#!/bin/bash
set -e

echo "=== AI Support Helper Container Starting ==="
echo "Running as user: $(whoami)"

# Check for Anthropic API key (required for new SDK)
if [ -z "$ANTHROPIC_API_KEY" ]; then
  echo "ERROR: ANTHROPIC_API_KEY not set"
  echo "Add ANTHROPIC_API_KEY to your .env file"
  exit 1
fi

echo "Anthropic API key configured"

echo "=== Starting Node.js server ==="
exec node server.js
