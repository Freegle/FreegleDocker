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

## Architecture (2-Container Design)

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  Frontend (Browser - ModTools)                                               │
│  - User selects a Freegler to investigate                                   │
│  - User enters natural language query                                       │
│  - Sends query to AI Support Helper                                         │
│  - Receives response with real PII (de-tokenized locally)                   │
└────────────────────────────────┬────────────────────────────────────────────┘
                                 │
                                 ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│  Container 1: AI Support Helper (ai-support-helper.localhost)               │
│  - Receives natural language queries from frontend                          │
│  - Runs Claude CLI with MCP tools for logs and database                     │
│  - Returns AI-generated responses (pseudonymized)                           │
│  - NEVER sees the token mapping                                             │
└────────────────────────────────┬────────────────────────────────────────────┘
                                 │
              MCP Tools: query_logs, query_database
                                 │
                                 ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│  Container 2: AI Sanitizer (ai-sanitizer.localhost, port 8084)              │
│  - Combined service for ALL sanitization and data access                    │
│  - PII tokenization (/scan, /sanitize)                                      │
│  - Loki log queries (/query) with pseudonymization                          │
│  - Database queries (/api/mcp/db-query) with SQL validation                 │
│  - Token mapping storage (SQLite, persistent)                               │
│  - Audit logging of all queries                                             │
└────────────────────────────────┬────────────────────────────────────────────┘
                                 │
              ┌──────────────────┴──────────────────┐
              ▼                                     ▼
┌─────────────────────────┐             ┌─────────────────────────┐
│  Loki                   │             │  MySQL (Percona)        │
│  - Log aggregation      │             │  - Freegle database     │
│  - Contains real PII    │             │  - Contains real PII    │
└─────────────────────────┘             └─────────────────────────┘
```

## Container Security Summary

| Container | Has Token Mapping? | Can Reach | If Compromised |
|-----------|-------------------|-----------|----------------|
| AI Support Helper | No | AI Sanitizer only | Can only issue pseudonymized queries |
| AI Sanitizer | Yes (SQLite) | Loki, MySQL | Full access - requires container escape |

The AI (Claude) **never** sees real PII - it only receives pseudonymized tokens. The mapping is stored in the AI Sanitizer container and used for:
1. Translating tokens to real values when querying Loki/MySQL
2. Pseudonymizing results before returning to Claude
3. Providing the frontend with mapping for de-tokenization

## MCP Tools

The AI Support Helper runs Claude with two MCP tools:

### query_logs

Query application logs from Loki. Returns pseudonymized log entries.

**Input Schema:**
```json
{
  "type": "object",
  "properties": {
    "query": {
      "type": "string",
      "description": "LogQL query (e.g., {job=\"freegle\"} |= \"error\")"
    },
    "time_range": {
      "type": "string",
      "description": "Time range (e.g., \"1h\", \"24h\", \"7d\")"
    },
    "limit": {
      "type": "integer",
      "description": "Maximum results. Default: 100"
    }
  },
  "required": ["query"]
}
```

### query_database

Query the Freegle database with SQL. Returns pseudonymized results.

**Input Schema:**
```json
{
  "type": "object",
  "properties": {
    "query": {
      "type": "string",
      "description": "SQL SELECT query (e.g., \"SELECT id, fullname FROM users WHERE id = 12345\")"
    }
  },
  "required": ["query"]
}
```

**Allowed Tables:** users, messages, groups, memberships, chat_messages, logs

**Security:** Only SELECT queries allowed, max 500 results, field-level whitelist enforced.

## API Endpoints

### AI Sanitizer (Container 2)

**GET /health**
Health check with database and Loki connectivity status.

**POST /scan**
Scan text for PII without tokenizing (for preview/warning).

**POST /sanitize**
Sanitize text by extracting and tokenizing PII.

Request:
```json
{
  "query": "Why is john.smith@gmail.com getting errors?",
  "knownPii": {
    "email": "john.smith@gmail.com",
    "displayname": "John Smith",
    "userid": 12345
  }
}
```

Response:
```json
{
  "pseudonymizedQuery": "Why is user_abc123@gmail.com getting errors?",
  "sessionId": "sess_7f3d2a",
  "localMapping": {
    "user_abc123@gmail.com": "john.smith@gmail.com"
  }
}
```

**POST /register-mapping**
Register token mappings for a session.

**POST /query**
Execute a Loki query with token translation and result pseudonymization.

**POST /api/mcp/db-query**
Execute a database query with SQL validation and result pseudonymization.

**GET /mapping/:sessionId**
Get the reverse mapping for frontend de-tokenization.

## Token Persistence

Tokens are persistent across sessions (stored in SQLite) to enable correlation:

```
Session 1 (Monday):
  "john.smith@gmail.com" → user_abc123@gmail.com

Session 2 (Tuesday):
  "john.smith@gmail.com" → user_abc123@gmail.com (same token!)

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
    "query": "{app=\"freegle\"} | json | email=\"user_abc123@gmail.com\"",
    "start": "1h",
    "limit": 100
  },
  "lokiQuery": {
    "query": "{app=\"freegle\"} | json | email=\"john.smith@gmail.com\"",
    "resultCount": 5
  },
  "response": {
    "pseudonymizedEntries": 5,
    "tokensUsed": ["user_abc123@gmail.com", "10.0.1.1"]
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

- `ai-support-helper` (port 3020, `ai-support-helper.localhost`)
- `ai-sanitizer` (port 8084, `ai-sanitizer.localhost`, also aliased as `mcp-sanitizer.localhost` for backwards compatibility)

Data persistence:
- `ai-sanitizer-data` - SQLite token database
- `ai-sanitizer-audit-logs` - Audit logs

## Development

### Rebuilding Containers

```bash
# Rebuild AI Sanitizer after code changes
docker-compose build ai-sanitizer && docker-compose up -d ai-sanitizer

# Rebuild AI Support Helper
docker-compose build ai-support-helper && docker-compose up -d ai-support-helper
```

### Testing Endpoints

```bash
# Health check
curl http://ai-sanitizer.localhost/health

# Scan for PII
curl -X POST http://ai-sanitizer.localhost/scan \
  -H "Content-Type: application/json" \
  -d '{"text": "Email john@example.com about issue"}'

# Sanitize text
curl -X POST http://ai-sanitizer.localhost/sanitize \
  -H "Content-Type: application/json" \
  -d '{"query": "Why is john@example.com having problems?", "knownPii": {"email": "john@example.com"}}'

# Database query
curl -X POST http://ai-sanitizer.localhost/api/mcp/db-query \
  -H "Content-Type: application/json" \
  -d '{"query": "SELECT id, fullname FROM users LIMIT 5"}'

# Loki query
curl -X POST http://ai-sanitizer.localhost/query \
  -H "Content-Type: application/json" \
  -d '{"query": "{job=\"freegle\"}", "start": "1h", "limit": 10}'
```
