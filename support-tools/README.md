# Pseudonymized Log & Database Analysis MCP

Privacy-preserving AI analysis system for Freegle support staff and volunteers.

## Overview

This system allows support volunteers to query logs and database records using natural language while ensuring no PII (Personally Identifiable Information) is ever sent to the AI. The architecture uses pseudonymization tokens that are meaningless without the key, which is kept securely within Freegle's infrastructure.

## What Gets Pseudonymized

The following PII is automatically detected and replaced with tokens:

| Data Type | Pattern | Token Format |
|-----------|---------|--------------|
| Email addresses | `user@domain.com` | `user_abc123@gmail.com` (preserves common domains) |
| IP addresses | `192.168.1.1` | `10.0.x.x` (internal IP format) |
| Phone numbers | `07700 123456` | `07700xxxxxx` |
| UK postcodes | `SW1A 1AA` | `ZZxx 9ZZ` |
| User IDs | 6+ digit numbers | Numeric (9999xxxxxx range) |
| Names | In log context | `User_abc123`, `Person_def456` |

**Note:** User IDs (6+ digits) are pseudonymized to prevent cross-referencing with external data while maintaining numeric format for correlation within the session.

## Architecture

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  Frontend (Browser)                                                          │
│  - User selects a Freegler to investigate                                   │
│  - User enters natural language query                                       │
│  - Receives response with real PII (de-tokenized)                           │
└────────────────────────────────┬────────────────────────────────────────────┘
                                 │
                                 ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│  Container 0: Query Sanitizer (port 8084)                                   │
│  - Extracts PII from user query                                             │
│  - Creates consistent tokens (EMAIL_a8f3c2)                                 │
│  - Sends mapping to Pseudonymizer                                           │
│  - Returns pseudonymized query + mapping to frontend                        │
└────────────────────────────────┬────────────────────────────────────────────┘
                                 │
          ┌──────────────────────┼──────────────────────┐
          │                      │                      │
          ▼                      ▼                      ▼
┌─────────────────┐   ┌───────────────────┐   ┌────────────────────────┐
│ Claude (API)    │   │ Pseudonymized     │   │ Mapping to             │
│                 │   │ query to Claude   │   │ Pseudonymizer (direct) │
│ Only sees tokens│   │                   │   │                        │
│ Never real PII  │   │                   │   │                        │
└────────┬────────┘   └───────────────────┘   └────────────────────────┘
         │
         ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│  Container 2: MCP Interface (internal only)                                 │
│  - Stateless proxy (NO key, NO mapping)                                     │
│  - Forwards tool calls to Pseudonymizer                                     │
│  - If compromised: can only issue pseudonymized queries                     │
└────────────────────────────────┬────────────────────────────────────────────┘
                                 │
                                 ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│  Container 3: Pseudonymizer + Loki Access                                   │
│  - HAS the key (token → real value mapping)                                 │
│  - Translates tokens to real values for Loki queries                        │
│  - Pseudonymizes Loki results before returning                              │
│  - Writes audit log of all queries                                          │
└────────────────────────────────┬────────────────────────────────────────────┘
                                 │
                                 ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│  Loki                                                                        │
│  - Existing log aggregation service                                          │
│  - Contains real PII in logs                                                 │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Container Security Summary

| Container | Has Key? | Has Mapping? | Can Reach | If Compromised |
|-----------|----------|--------------|-----------|----------------|
| 0: Query Sanitizer | No | Creates it | C3 | Can see user queries (trusted staff only) |
| 2: MCP Interface | No | No | C3 only | Can only issue pseudonymized queries |
| 3: Pseudonymizer | Yes | Yes | Loki only | Full access - requires multiple escapes |

## MCP Tools

### loki_query

Query application logs from Loki. Returns pseudonymized log entries.

**Input Schema:**
```json
{
  "type": "object",
  "properties": {
    "sessionId": {
      "type": "string",
      "description": "Session ID from query sanitization. Required."
    },
    "query": {
      "type": "string",
      "description": "LogQL query (e.g., {app=\"freegle\"} |= \"error\")"
    },
    "start": {
      "type": "string",
      "description": "Start time - relative (1h, 24h, 7d) or ISO 8601. Default: 1h"
    },
    "end": {
      "type": "string",
      "description": "End time. Default: now"
    },
    "limit": {
      "type": "integer",
      "description": "Maximum results. Default: 100"
    }
  },
  "required": ["sessionId", "query"]
}
```

## API Endpoints

### Query Sanitizer (Container 0)

**POST /sanitize**
Sanitize a user query by extracting and tokenizing PII.

Request:
```json
{
  "query": "Why is john.smith@gmail.com getting errors?",
  "knownPii": {
    "email": "john.smith@gmail.com",
    "displayname": "John Smith",
    "userid": 12345
  },
  "userId": 12345
}
```

Response:
```json
{
  "pseudonymizedQuery": "Why is EMAIL_a8f3c2 getting errors?",
  "sessionId": "sess_7f3d2a",
  "localMapping": {
    "EMAIL_a8f3c2": "john.smith@gmail.com"
  },
  "detectedPii": null
}
```

**POST /scan**
Scan query for PII without sanitizing (for preview/warning).

### MCP Interface (Container 2)

**GET /tools**
List available MCP tools.

**POST /tools/loki_query**
Execute a Loki query (with pseudonymization).

### Pseudonymizer (Container 3)

**POST /register-mapping**
Register token mappings for a session (called by Container 0).

**POST /query**
Execute a Loki query with token translation and result pseudonymization.

**GET /mapping/:sessionId**
Get the reverse mapping for frontend de-tokenization.

## Token Persistence

Tokens are persistent across sessions to enable correlation:

```
Session 1 (Monday):
  "john.smith@gmail.com" → EMAIL_a8f3c2

Session 2 (Tuesday):
  "john.smith@gmail.com" → EMAIL_a8f3c2 (same token!)

Claude can correlate across sessions because the token is consistent.
```

## GDPR/ICO Compliance

From the [ICO Pseudonymisation Guidance](https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/data-sharing/anonymisation/pseudonymisation/):

> "Pseudonymised data in the hands of an organisation that does not have access to the key – or any other means of identifying those individuals – may be considered anonymous data."

With cryptographically random tokens and proper key separation:
- **Freegle** (has key): Data is personal data, full GDPR applies
- **Anthropic** (no key): Data is effectively anonymous - tokens are meaningless

## Audit Logging

All MCP queries are logged to `/var/log/mcp-audit/YYYY-MM-DD.jsonl`:

```json
{
  "timestamp": "2024-01-15T10:30:00Z",
  "sessionId": "sess_7f3d2a",
  "operation": "loki_query",
  "request": {
    "query": "{app=\"freegle\"} | json | email=\"EMAIL_a8f3c2\"",
    "start": "1h",
    "limit": 100
  },
  "lokiQuery": {
    "query": "{app=\"freegle\"} | json | email=\"john.smith@gmail.com\"",
    "resultCount": 5
  },
  "response": {
    "pseudonymizedEntries": 5,
    "tokensUsed": ["EMAIL_a8f3c2", "IP_7d3f2a"]
  },
  "durationMs": 245
}
```

## Example Queries

The AI Support Helper can answer three types of questions:

### 1. Database Queries (User Information)

Questions about specific users use the MCP database tool with pseudonymized results.

**Example:** "When did this user last log in?"

Response: "This user last logged in today (January 19th, 2026) at around 10:09 PM. So they've been active very recently."

The AI queries the database with SQL like `SELECT lastaccess FROM users WHERE id = ?` and translates the timestamp into a friendly response.

### 2. Log Queries (System Errors)

Questions about system behaviour query Loki logs with automatic pseudonymization.

**Example:** "Are there any errors in the system logs?"

Response: "I found some errors around the time this user was trying to send messages. This looks like a temporary glitch that's now resolved - they should be able to send messages normally now."

**Note:** Log queries require Alloy to be shipping logs to Loki. In local development without logs configured, the AI will explain logs aren't available and suggest alternative approaches.

### 3. Codebase Queries (Technical Questions)

General questions about how Freegle works use built-in code search tools.

**Example:** "Where is the User model defined?"

Response: "The User model is defined in `/app/codebase/iznik-server/include/user/User.php`. There's also a User API endpoint at `/app/codebase/iznik-server/http/api/user.php`."

Codebase queries don't require a specific user to be selected and don't involve the MCP approval flow.

## Docker Compose

Services are defined in `docker-compose.yml`:

- `mcp-query-sanitizer` (port 8084, `mcp-sanitizer.localhost`)
- `mcp-interface` (internal only)
- `mcp-pseudonymizer` (internal only)

Isolated networks prevent direct container-to-container access except through defined paths.
