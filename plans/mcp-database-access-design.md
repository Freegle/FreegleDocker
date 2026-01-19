# MCP Database Access Design

## Overview

Enable AI to query the Freegle database through MCP with:
1. Read-only access only (no modifications)
2. Field-level whitelisting with privacy classification
3. Automatic pseudonymization of sensitive data
4. Human-in-the-loop approval (when Privacy Review is enabled)

## Architecture

```
AI Query Request
       │
       ▼
┌─────────────────┐
│ MCP Server      │ (mcp-db-server.js)
│ - Validates SQL │
│ - Checks tables │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ Status API      │ (status-nuxt)
│ - Executes SQL  │
│ - Applies field │
│   whitelist     │
│ - Pseudonymizes │
│   sensitive     │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ MySQL Database  │
└─────────────────┘
```

## Field Classification

### Privacy Levels

1. **PUBLIC** - Returns real value as-is
   - Timestamps (created, added, lastaccess)
   - Numeric IDs (userid, messageid, groupid)
   - Post subjects/titles (public content)
   - Group names and descriptions
   - Enum values (type, status)
   - Counts and aggregates

2. **SENSITIVE** - Pseudonymized before return
   - User names (firstname, lastname, fullname)
   - Email addresses
   - IP addresses
   - Phone numbers
   - Chat message content (private conversations)
   - Physical addresses

3. **BLOCKED** - Never returned, filtered from results
   - Password hashes
   - API keys
   - Session tokens
   - Settings JSON (may contain PII)

## Table and Field Whitelist

```javascript
const SCHEMA = {
  users: {
    allowed: true,
    fields: {
      id: 'PUBLIC',
      firstname: 'SENSITIVE',
      lastname: 'SENSITIVE',
      fullname: 'SENSITIVE',
      systemrole: 'PUBLIC',
      added: 'PUBLIC',
      lastaccess: 'PUBLIC',
      settings: 'BLOCKED',      // May contain PII
      bouncing: 'PUBLIC',
      deleted: 'PUBLIC',
      engagement: 'PUBLIC',
      trustlevel: 'PUBLIC',
    }
  },

  users_emails: {
    allowed: true,
    fields: {
      id: 'PUBLIC',
      userid: 'PUBLIC',
      email: 'SENSITIVE',       // Always pseudonymize
      preferred: 'PUBLIC',
      added: 'PUBLIC',
      validated: 'PUBLIC',
      bounced: 'PUBLIC',
      // No validatekey - BLOCKED by omission
    }
  },

  messages: {
    allowed: true,
    fields: {
      id: 'PUBLIC',
      arrival: 'PUBLIC',
      date: 'PUBLIC',
      deleted: 'PUBLIC',
      source: 'PUBLIC',
      fromip: 'SENSITIVE',      // IP address
      fromuser: 'PUBLIC',
      fromname: 'SENSITIVE',    // User name
      fromaddr: 'SENSITIVE',    // Email
      subject: 'PUBLIC',        // Public post title
      type: 'PUBLIC',
      lat: 'PUBLIC',
      lng: 'PUBLIC',
      locationid: 'PUBLIC',
      availablenow: 'PUBLIC',
      message: 'BLOCKED',       // Full email body - too sensitive
      textbody: 'BLOCKED',
      htmlbody: 'BLOCKED',
    }
  },

  messages_groups: {
    allowed: true,
    fields: {
      msgid: 'PUBLIC',
      groupid: 'PUBLIC',
      collection: 'PUBLIC',
      arrival: 'PUBLIC',
      deleted: 'PUBLIC',
    }
  },

  chat_rooms: {
    allowed: true,
    fields: {
      id: 'PUBLIC',
      chattype: 'PUBLIC',
      user1: 'PUBLIC',
      user2: 'PUBLIC',
      created: 'PUBLIC',
      lastmsg: 'PUBLIC',
    }
  },

  chat_messages: {
    allowed: true,
    fields: {
      id: 'PUBLIC',
      chatid: 'PUBLIC',
      userid: 'PUBLIC',
      type: 'PUBLIC',
      refmsgid: 'PUBLIC',
      date: 'PUBLIC',
      message: 'SENSITIVE',     // Private conversation - pseudonymize
      seenbyall: 'PUBLIC',
      reviewrequired: 'PUBLIC',
      reviewrejected: 'PUBLIC',
    }
  },

  groups: {
    allowed: true,
    fields: {
      id: 'PUBLIC',
      nameshort: 'PUBLIC',
      namefull: 'PUBLIC',
      type: 'PUBLIC',
      region: 'PUBLIC',
      lat: 'PUBLIC',
      lng: 'PUBLIC',
      membercount: 'PUBLIC',
      modcount: 'PUBLIC',
      tagline: 'PUBLIC',
      description: 'PUBLIC',
      founded: 'PUBLIC',
      settings: 'BLOCKED',      // May contain mod info
      contactmail: 'SENSITIVE', // Email
    }
  },

  memberships: {
    allowed: true,
    fields: {
      id: 'PUBLIC',
      userid: 'PUBLIC',
      groupid: 'PUBLIC',
      role: 'PUBLIC',
      collection: 'PUBLIC',
      added: 'PUBLIC',
      deleted: 'PUBLIC',
      emailfrequency: 'PUBLIC',
    }
  },

  logs: {
    allowed: true,
    fields: {
      id: 'PUBLIC',
      timestamp: 'PUBLIC',
      byuser: 'PUBLIC',
      type: 'PUBLIC',
      subtype: 'PUBLIC',
      groupid: 'PUBLIC',
      user: 'PUBLIC',
      msgid: 'PUBLIC',
      text: 'SENSITIVE',        // May contain PII
    }
  },

  messages_outcomes: {
    allowed: true,
    fields: {
      id: 'PUBLIC',
      msgid: 'PUBLIC',
      outcome: 'PUBLIC',
      timestamp: 'PUBLIC',
      userid: 'PUBLIC',
      happiness: 'PUBLIC',
      comments: 'SENSITIVE',    // User-provided text
    }
  },
};
```

## SQL Validation Rules

1. **Must be SELECT only**
   - Reject any INSERT, UPDATE, DELETE, DROP, CREATE, ALTER, TRUNCATE
   - Reject stored procedure calls (CALL)
   - Reject INTO OUTFILE/DUMPFILE

2. **Must use whitelisted tables**
   - Parse SQL to extract table names
   - Reject if any non-whitelisted table is used

3. **Must request only allowed columns**
   - Parse SELECT clause
   - `SELECT *` is allowed but expands to allowed columns only
   - Block BLOCKED columns in WHERE/ORDER BY too

4. **Result limit enforced**
   - Maximum 500 rows per query
   - Add LIMIT if not present

5. **No dangerous functions**
   - Block LOAD_FILE(), sys.*, information_schema queries for data
   - Allow INFORMATION_SCHEMA for listing tables/columns (filtered)

## Pseudonymization Strategy

Reuse existing pseudonymizer patterns:
- Emails → `EMAIL_xxxxx`
- IP addresses → `IP_xxxxx`
- Names → `NAME_xxxxx`
- Generic text (chat messages) → `TEXT_xxxxx` (or hash-based)

Consistency: Same input produces same token within a session (via sessionId).

## Example Queries

**Allowed:**
```sql
-- Find a user's recent posts
SELECT m.id, m.subject, m.type, m.arrival, mg.groupid
FROM messages m
JOIN messages_groups mg ON m.id = mg.msgid
WHERE m.fromuser = 12345
ORDER BY m.arrival DESC
LIMIT 10

-- Count messages by type for a user
SELECT type, COUNT(*) as count
FROM messages
WHERE fromuser = 12345
GROUP BY type

-- Find chat rooms for a user
SELECT cr.id, cr.chattype, cr.created, cr.lastmsg
FROM chat_rooms cr
WHERE cr.user1 = 12345 OR cr.user2 = 12345
```

**Blocked:**
```sql
-- Tries to access blocked column
SELECT settings FROM users WHERE id = 12345

-- Tries to modify data
UPDATE users SET fullname = 'Hacker' WHERE id = 12345

-- Tries to access non-whitelisted table
SELECT * FROM sessions WHERE userid = 12345
```

## Implementation Plan

1. Create `mcp-db-server.js` - MCP server for database queries
2. Create `status-nuxt/server/api/mcp/db-query.post.ts` - Query executor
3. Create `status-nuxt/server/utils/db-schema.ts` - Field whitelist config
4. Create `status-nuxt/server/utils/sql-validator.ts` - SQL parsing/validation
5. Update AI assistant guidelines for less technical responses

## Response Format

The MCP tool returns structured data, not raw SQL results:

```json
{
  "status": "success",
  "sessionId": "abc123",
  "query": "SELECT ...",
  "resultCount": 15,
  "columns": ["id", "subject", "type"],
  "rows": [
    {"id": 1, "subject": "OFFER: Garden tools", "type": "Offer"},
    ...
  ],
  "note": "Some values pseudonymized for privacy"
}
```

## AI Response Guidelines Update

Add to CLAUDE.md in ai-support-helper:

```markdown
## Response Style for Support Staff

Support staff are NOT developers. When responding:

1. **Use everyday language** - Talk about "users", "posts", "groups", "chats"
2. **Never show raw SQL** - Summarize what you found instead
3. **Never show code** - Explain concepts, not implementation
4. **Focus on actions** - What can be done to help the user
5. **Be concise** - One clear paragraph is better than technical details

Example BAD response:
"I ran `SELECT * FROM messages WHERE fromuser=123` and found 5 rows with type='Offer'..."

Example GOOD response:
"This user has posted 5 items for offer recently. Their most recent was 'Garden tools' posted 2 days ago."
```

---

# Loki Log Field Whitelist Design

## Problem with Current Approach

The current regex-based pseudonymization is a **blacklist** approach:
- Tries to detect PII patterns (emails, IPs, etc.)
- Will miss new PII fields as code evolves
- No way to know if a field is safe or not

## Whitelist Approach

Similar to the database schema, we should have explicit field classification for log entries:

1. **PUBLIC** - Field passes through unchanged
2. **SENSITIVE** - Field gets pseudonymized
3. **UNKNOWN** - Field not in schema → pseudonymized + flagged for review

## Log Schema Structure

```typescript
// Log sources and their field schemas
const LOG_SCHEMA = {
  // PHP API logs
  'api': {
    fields: {
      timestamp: 'PUBLIC',
      method: 'PUBLIC',
      path: 'PUBLIC',
      status: 'PUBLIC',
      duration_ms: 'PUBLIC',
      userid: 'PUBLIC',        // Numeric ID only
      ip: 'SENSITIVE',
      user_agent: 'PUBLIC',
      // email, name, etc. would be SENSITIVE
    }
  },

  // Go API logs
  'apiv2': {
    fields: {
      timestamp: 'PUBLIC',
      level: 'PUBLIC',
      msg: 'PUBLIC',           // System messages only
      userid: 'PUBLIC',
      groupid: 'PUBLIC',
      error: 'PUBLIC',         // Error codes/types
      // Any user-provided content: SENSITIVE
    }
  },

  // Client-side logs (from browser)
  'client': {
    fields: {
      timestamp: 'PUBLIC',
      level: 'PUBLIC',
      component: 'PUBLIC',
      action: 'PUBLIC',
      error_type: 'PUBLIC',
      url: 'SENSITIVE',        // May contain PII in query params
      message: 'SENSITIVE',    // May contain user input
    }
  },

  // Batch job logs
  'batch': {
    fields: {
      timestamp: 'PUBLIC',
      job: 'PUBLIC',
      status: 'PUBLIC',
      processed: 'PUBLIC',
      failed: 'PUBLIC',
      duration_ms: 'PUBLIC',
    }
  },
}
```

## Unknown Field Handling

When a field is encountered that's not in the schema:

1. **Pseudonymize by default** - Safe fallback
2. **Flag for review** - Log warning with field name and source
3. **Aggregate stats** - Track unknown field counts per source

```typescript
function processLogField(source: string, field: string, value: any): any {
  const schema = LOG_SCHEMA[source]
  const privacy = schema?.fields[field]

  if (!privacy) {
    // Unknown field - pseudonymize and flag
    logUnknownField(source, field)
    return pseudonymize(value)
  }

  if (privacy === 'PUBLIC') {
    return value
  }

  return pseudonymize(value)
}

// Track unknown fields for schema updates
const unknownFields = new Map<string, Set<string>>()

function logUnknownField(source: string, field: string) {
  if (!unknownFields.has(source)) {
    unknownFields.set(source, new Set())
  }
  const fields = unknownFields.get(source)!
  if (!fields.has(field)) {
    fields.add(field)
    console.warn(`[LOG_SCHEMA] Unknown field: ${source}.${field} - pseudonymizing by default`)
  }
}

// API endpoint to get unknown fields for review
// GET /api/mcp/unknown-fields
// Returns: { "api": ["new_field1"], "client": ["user_data"] }
```

## Benefits

1. **Safe by default** - New fields are pseudonymized automatically
2. **Visibility** - Unknown fields are flagged for review
3. **No code sync needed** - Schema is independent of logging code
4. **Easy to update** - Just add fields to the schema when reviewed

## API Endpoint for Unknown Fields

```typescript
// GET /api/mcp/unknown-fields
// Returns list of fields seen in logs but not in schema
// Support staff can review these and request schema updates

{
  "unknownFields": {
    "api": ["new_tracking_field", "debug_info"],
    "client": ["user_search_query"]
  },
  "since": "2024-01-15T00:00:00Z",
  "counts": {
    "api.new_tracking_field": 1523,
    "client.user_search_query": 87
  }
}
```

## Implementation Steps

1. Create `log-schema.ts` with field whitelist per log source
2. Update pseudonymizer to use whitelist instead of regex patterns
3. Add unknown field tracking and warning logs
4. Add API endpoint to report unknown fields
5. Add UI in status page to show unknown fields for review
