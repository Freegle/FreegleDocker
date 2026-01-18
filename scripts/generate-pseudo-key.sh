#!/bin/bash
# Generate a pseudonymization key for the MCP log analysis system
#
# Usage: ./scripts/generate-pseudo-key.sh > secrets/pseudo_key.txt

# Generate 32 bytes of random data and base64 encode it
# This creates a 256-bit key suitable for HMAC-SHA256
openssl rand -base64 32
