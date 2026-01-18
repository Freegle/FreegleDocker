# Pseudonymized Log Analysis MCP Server

## Overview

Design for a privacy-preserving AI log analysis system that allows Claude to query Loki logs without exposing PII, using cryptographic pseudonymization with two-container isolation.

**Key security principle**: Claude must never run on a machine that has the pseudonymization key. This ensures that even if the MCP container is compromised, the key remains protected.

## Background Research

### GDPR/ICO Position on Pseudonymization

From [ICO Pseudonymisation Guidance](https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/data-sharing/anonymisation/pseudonymisation/):

> "Pseudonymised data in the hands of an organisation that does not have access to the key – or any other means of identifying those individuals – **may be considered anonymous data**."

Key points:
- Data is **personal data** for the key holder
- Data is **potentially anonymous** for parties without the key
- Under [EDPB Guidelines 01/2025](https://www.edpb.europa.eu/system/files/2025-01/edpb_guidelines_202501_pseudonymisation_en.pdf), key separation is a core requirement

### The "Motivated Intruder" Test

The [ICO's motivated intruder test](https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/data-sharing/anonymisation/how-do-we-ensure-anonymisation-is-effective/) asks: could a reasonably competent person with standard resources re-identify the data?

For cryptographically random tokens without the key:
- **Brute force**: Not feasible (UUID = 2^122 possibilities)
- **Rainbow tables**: Not applicable (tokens are random, not hashed)
- **Linkage attacks**: Minimal (random tokens have no correlation to external data)
- **Inference**: Possible for behavioral patterns, but not identity

**Conclusion**: Properly implemented cryptographic tokenization meets ICO's "reasonably unlikely to re-identify" standard for parties without the key.

### Previous Attempt: ModTools AI Support Helper

The existing AI support tool in ModTools (`iznik-nuxt3-modtools/components/ModSupportAIAssistant.vue`) used a **fact query** approach:
- Browser executes predefined queries with user's JWT
- Returns only aggregated data (counts, booleans, error codes)
- Claude never sees raw log data

**Why it didn't work well:**
- Too restrictive - couldn't explore logs naturally
- Required predicting all useful queries in advance
- Lost the ability for Claude to notice patterns in raw data

### Security Limitations of Simple Approaches

| Approach | Vulnerability |
|----------|---------------|
| Hashing user IDs | Domain too small - rainbow table attack |
| Sequential tokens (USER_001) | Reveals ordering, frequency analysis |
| Single container with key | Sandbox escape exposes key |
| Instruction-based ("don't read key") | No enforcement |

## Threat Model

### What we're protecting against

**Primary threat**: PII appearing in Anthropic's logs/training data
- Emails, user IDs, IP addresses, locations
- Should be cryptographically impossible to reverse without our key

**Secondary threat**: Claude attempting to bypass pseudonymization
- Using curl/wget to query Loki directly
- Reading configuration files to obtain the key
- Social engineering via tool misuse

### What we're NOT protecting against

- Attacker who has compromised our infrastructure and obtained the key
  - At that point they can access the database directly anyway
- Nation-state level attacks
- Zero-day container escapes combined with network pivoting

## Architecture: Two-Container Isolation

The architecture uses two containers to maintain separation between Claude and the pseudonymization key:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  Docker Compose Environment                                                  │
│                                                                              │
│  ┌────────────────────────────────┐   ┌────────────────────────────────┐   │
│  │   Container 1: MCP Interface   │   │   Container 2: Pseudonymizer   │   │
│  │                                │   │                                │   │
│  │ - Exposes MCP tools to Claude  │──▶│ - Has the pseudonymization key │   │
│  │ - NO key access                │   │ - Queries Loki                 │   │
│  │ - Validates requests           │   │ - Pseudonymizes PII            │   │
│  │ - Cannot reach Loki directly   │   │ - Returns sanitized data       │   │
│  │                                │   │                                │   │
│  │ network: mcp-frontend          │   │ network: mcp-frontend          │   │
│  │                                │   │ network: mcp-backend           │   │
│  └────────────────────────────────┘   └───────────────┬────────────────┘   │
│              ▲                                        │                     │
│              │ MCP Protocol (stdio)                   │                     │
│              │                                        ▼                     │
│  ┌───────────┴────────────┐               ┌────────────────────┐           │
│  │   Claude Code          │               │       Loki         │           │
│  │   (external session)   │               │   (port 3100)      │           │
│  └────────────────────────┘               └────────────────────┘           │
└─────────────────────────────────────────────────────────────────────────────┘
```

**Why two containers, not three?**

Claude interacts via the MCP protocol (stdio), which only allows calling defined tool functions. It cannot:
- Execute arbitrary code in the MCP container
- Access the filesystem
- Make network requests directly

The risk is that if Claude ran on a machine with the key, a sophisticated attack could potentially access it. By keeping the key in a separate container (Container 2), even complete compromise of Container 1 yields only pseudonymized data.

### Container 1: MCP Interface (No Key)

**Purpose**: Expose MCP tools to Claude, validate requests, forward to Pseudonymizer

**Security measures**:
- No pseudonymization key
- No direct Loki access
- Only accepts well-formed MCP requests
- Only forwards to Container 2

```python
# mcp_interface/server.py
from fastapi import FastAPI, HTTPException
import httpx

app = FastAPI()

PSEUDONYMIZER_URL = "http://mcp-pseudonymizer:8080"

@app.post("/mcp/loki/query")
async def query_loki(request: LokiQueryRequest):
    """
    Validate request format and forward to pseudonymizer.
    We have NO key here - can't reverse anything.
    """
    if not is_valid_logql(request.query):
        raise HTTPException(400, "Invalid LogQL query")

    if request.limit > 1000:
        raise HTTPException(400, "Limit too high")

    async with httpx.AsyncClient() as client:
        response = await client.post(
            f"{PSEUDONYMIZER_URL}/query",
            json=request.dict(),
            timeout=30.0
        )

    return response.json()
```

### Container 2: Pseudonymizer (Has Key)

**Purpose**: Query Loki, pseudonymize results, return safe data

**Security measures**:
- Only accepts requests from Container 2 (network isolation)
- Key injected via environment variable (not in filesystem)
- Stateless - mapping table in memory or encrypted SQLite

```python
# pseudonymizer/server.py
import os
import uuid
import sqlite3
from cryptography.fernet import Fernet
from fastapi import FastAPI
import httpx

app = FastAPI()

# Key from environment - not in any file Claude could read
PSEUDO_KEY = os.environ["PSEUDONYMIZATION_KEY"]
fernet = Fernet(PSEUDO_KEY)

LOKI_URL = "http://loki:3100"

# In-memory mapping (or encrypted SQLite)
mapping_db = sqlite3.connect(":memory:")
mapping_db.execute("""
    CREATE TABLE mappings (
        original_hash TEXT PRIMARY KEY,
        token TEXT,
        field_type TEXT
    )
""")

def get_or_create_token(value: str, field_type: str) -> str:
    """
    Get existing token or create new one.
    Tokens are random UUIDs - no mathematical relationship to original.
    """
    # Hash original to use as lookup key (we don't store plaintext)
    value_hash = hashlib.sha256(
        (value + PSEUDO_KEY).encode()  # Keyed hash
    ).hexdigest()

    cursor = mapping_db.execute(
        "SELECT token FROM mappings WHERE original_hash = ?",
        (value_hash,)
    )
    row = cursor.fetchone()

    if row:
        return row[0]

    # Create new random token
    token = f"{field_type}_{uuid.uuid4().hex[:12]}"
    mapping_db.execute(
        "INSERT INTO mappings VALUES (?, ?, ?)",
        (value_hash, token, field_type)
    )
    return token

def pseudonymize_log_entry(entry: dict) -> dict:
    """Pseudonymize known PII fields."""
    result = entry.copy()

    # Email addresses
    if "email" in result:
        result["email"] = get_or_create_token(result["email"], "EMAIL")

    # User IDs
    if "userid" in result:
        result["userid"] = get_or_create_token(str(result["userid"]), "USER")

    # IP addresses
    if "ip" in result:
        result["ip"] = get_or_create_token(result["ip"], "IP")

    # Coordinates - round to ~1km
    if "lat" in result:
        result["lat"] = round(float(result["lat"]), 2)
    if "lng" in result:
        result["lng"] = round(float(result["lng"]), 2)

    # Scan text fields for emails/IPs
    for key, value in result.items():
        if isinstance(value, str):
            result[key] = pseudonymize_text(value)

    return result

@app.post("/query")
async def query_and_pseudonymize(request: LokiQueryRequest):
    """Query Loki and pseudonymize results."""

    # Query Loki
    async with httpx.AsyncClient() as client:
        response = await client.get(
            f"{LOKI_URL}/loki/api/v1/query_range",
            params={
                "query": request.query,
                "start": request.start,
                "end": request.end,
                "limit": request.limit
            }
        )

    data = response.json()

    # Pseudonymize each log entry
    if "data" in data and "result" in data["data"]:
        for stream in data["data"]["result"]:
            stream["values"] = [
                [ts, pseudonymize_log_entry(parse_log_line(line))]
                for ts, line in stream["values"]
            ]

    return data
```

**Network**:
```yaml
networks:
  mcp-frontend:  # Receives from MCP interface
  mcp-backend:   # Sends to Loki
```

## Escape Analysis

### If Claude compromises Container 1 (MCP Interface)

**Can reach**: Container 2 (Pseudonymizer) API only
**Cannot reach**: Loki directly, Internet, filesystem

**Container 1 has**:
- No pseudonymization key
- No direct Loki access
- No useful credentials

**Result**: Dead end. Can only send queries that return pseudonymized data.

### If Claude compromises Container 2 (Pseudonymizer)

This requires:
1. Escaping the MCP protocol constraints
2. Gaining code execution in Container 1
3. Pivoting to Container 2

At this point, the attacker has demonstrated sophisticated capabilities beyond our threat model. However:
- Key is in environment variable, not file
- Key rotates regularly
- Audit logs would show the breach
- Still requires network pivot which is blocked by Docker network isolation

## MCP Tool Definition

```typescript
// MCP tool exposed to Claude
{
  name: "loki_query",
  description: "Query application logs from Loki. Returns pseudonymized results - emails, user IDs, and IPs are replaced with consistent tokens (e.g., EMAIL_a8f3c2, USER_7b2e91). Coordinates are rounded to ~1km.",
  inputSchema: {
    type: "object",
    properties: {
      query: {
        type: "string",
        description: "LogQL query. Example: {app=\"freegle\"} |= \"error\""
      },
      start: {
        type: "string",
        description: "Start time (ISO 8601 or relative like '1h')"
      },
      end: {
        type: "string",
        description: "End time (ISO 8601 or 'now')"
      },
      limit: {
        type: "integer",
        description: "Max results (default 100, max 1000)"
      }
    },
    required: ["query"]
  }
}
```

## Key Management

### Key Generation

```bash
# Generate a Fernet key (256-bit)
python3 -c "from cryptography.fernet import Fernet; print(Fernet.generate_key().decode())"
```

### Key Storage

- **NOT in any file** in Pseudonymizer container
- Injected via environment variable at container start
- Stored in Docker secrets or HashiCorp Vault
- Rotated monthly (old mappings archived)

### Key Rotation

When rotating keys:
1. New key for new queries
2. Old mappings remain valid (stored with key version)
3. Gradual migration over 30 days
4. Old key destroyed after migration

## Browser Integration

The browser talks to Container 1's exposed API (or Container 2 directly for questions):

```typescript
// ModTools integration
async function askLogQuestion(question: string): Promise<string> {
  const response = await fetch('https://log-analyzer.freegle.org/ask', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ question })
  });

  const result = await response.json();
  // Result contains pseudonymized data only
  // e.g., "USER_7b2e91 had 15 errors in the last hour from IP_3c8d2a"
  return result.answer;
}
```

## Docker Compose Configuration

### Profiles

The MCP containers use Docker Compose profiles for different environments:

| Profile | Environment | Loki Access | Use Case |
|---------|-------------|-------------|----------|
| `mcp-dev` | Local development | Via SSH tunnel to live Loki | Testing MCP before Loki migration |
| `mcp` | Production | Direct to Loki in same compose | Full production deployment |
| `logging` | Production | N/A | Loki + Alloy stack only |

### docker-compose.mcp.yml

```yaml
version: '3.8'

services:
  # Container 1: MCP Interface (No Key)
  mcp-interface:
    build: ./mcp-interface
    profiles: ["mcp-dev", "mcp"]
    networks:
      - mcp-frontend
    environment:
      - PSEUDONYMIZER_URL=http://mcp-pseudonymizer:8080
    depends_on:
      - mcp-pseudonymizer
    # Claude connects via stdio - no ports exposed

  # Container 2: Pseudonymizer (Has Key)
  mcp-pseudonymizer:
    build: ./mcp-pseudonymizer
    profiles: ["mcp-dev", "mcp"]
    networks:
      - mcp-frontend
      - mcp-backend
    environment:
      - PSEUDONYMIZATION_KEY=${PSEUDO_KEY}
      - LOKI_URL=${LOKI_URL:-http://loki:3100}
    secrets:
      - pseudo_key
    depends_on:
      mcp-loki-tunnel:
        condition: service_healthy
        required: false  # Only in mcp-dev profile

  # SSH Tunnel to Live Loki (Dev only)
  mcp-loki-tunnel:
    image: alpine:latest
    profiles: ["mcp-dev"]  # Only starts in dev
    command: >
      sh -c "apk add --no-cache openssh-client autossh &&
             autossh -M 0 -N -o StrictHostKeyChecking=no
             -o ServerAliveInterval=30 -o ServerAliveCountMax=3
             -L 0.0.0.0:3100:localhost:3100
             -i /ssh/id_rsa
             ${SSH_USER:-root}@docker-internal.ilovefreegle.org"
    volumes:
      - ${SSH_KEY_PATH:-~/.ssh/id_rsa}:/ssh/id_rsa:ro
    networks:
      - mcp-backend
    healthcheck:
      test: ["CMD", "nc", "-z", "localhost", "3100"]
      interval: 30s
      timeout: 5s
      retries: 3
      start_period: 10s

  # Loki (Production - migrated from standalone)
  loki:
    image: grafana/loki:2.9.0
    profiles: ["mcp", "logging"]
    command: -config.file=/etc/loki/config.yaml
    volumes:
      - loki-data:/loki
      - ./loki-config.yaml:/etc/loki/config.yaml:ro
    ports:
      - "3100:3100"  # Exposed for Alloy agents
    networks:
      - mcp-backend
    extra_hosts:
      # Allow Alloy agents from internal network
      - "bulk2-internal:10.220.0.217"
      - "bulk3-internal:10.220.0.90"

networks:
  mcp-frontend:
    internal: true  # No external access
  mcp-backend:
    internal: true  # Pseudonymizer ↔ Loki only

secrets:
  pseudo_key:
    file: ./secrets/pseudo_key.txt

volumes:
  loki-data:
```

### Usage

```bash
# Local development (connects to live Loki via SSH tunnel)
docker-compose -f docker-compose.yml -f docker-compose.mcp.yml --profile mcp-dev up -d

# Production (Loki in same compose stack)
docker-compose -f docker-compose.yml -f docker-compose.mcp.yml --profile mcp up -d

# Loki only (for migration testing)
docker-compose -f docker-compose.yml -f docker-compose.mcp.yml --profile logging up -d
```

## Comparison with Previous Approach

| Aspect | ModTools AI Support | This Design |
|--------|---------------------|-------------|
| Data access | Predefined fact queries only | Full LogQL queries |
| Pseudonymization | Browser-side, session-scoped | Server-side, cryptographic |
| Key security | N/A (no reversible mapping) | Two-container isolation |
| Flexibility | Low (77 fixed queries) | High (any LogQL) |
| Claude sandbox | N/A (API-based) | Container + network isolation |
| Escape resistance | Browser trust model | Defense in depth |

## Phased Deployment

### Phase 1: Local Development with Live Loki

**Goal**: Test MCP containers locally, connected to the existing live Loki server via SSH tunnel.

**Current state**: Loki runs on `docker-internal` (10.220.0.103) as a standalone container, NOT part of FreegleDocker's docker-compose.yml.

**Tasks**:
1. Create `mcp-interface/` container source
2. Create `mcp-pseudonymizer/` container source
3. Create `docker-compose.mcp.yml` with profiles
4. Test SSH tunnel connectivity to live Loki
5. Test pseudonymization with real log data
6. Verify PII is properly masked (spot check emails, user IDs)

**Success criteria**:
- [ ] SSH tunnel container connects to live Loki
- [ ] Pseudonymizer can query Loki and return sanitized results
- [ ] MCP Interface exposes tools that Claude can call
- [ ] PII is properly pseudonymized
- [ ] Claude can answer questions about logs without seeing real PII

**Start command**:
```bash
docker-compose -f docker-compose.yml -f docker-compose.mcp.yml --profile mcp-dev up -d
```

---

### Phase 2: Migrate Loki into Docker Compose

**Goal**: Move the existing Loki container into our Docker Compose environment, preserving all historical log data.

**Prerequisites**: Phase 1 tested and working

**Tasks**:
1. Document current Loki setup on docker-internal (version, config, data size)
2. Add Loki service to docker-compose.mcp.yml
3. Create loki-config.yaml matching current configuration
4. Backup existing Loki data volume
5. Migrate data to new compose-managed volume
6. Update Alloy agents on all servers to point to new Loki endpoint
7. Verify historical logs accessible
8. Decommission old standalone Loki container

**Migration strategy** (Volume Copy):
```bash
# On docker-internal (old host):
docker stop loki
docker run --rm -v loki-data:/source -v /backup:/backup alpine \
  tar czf /backup/loki-data-backup.tar.gz -C /source .

# Transfer to new host
scp /backup/loki-data-backup.tar.gz bulk3:/backup/

# On new host:
docker volume create loki-data
docker run --rm -v loki-data:/target -v /backup:/backup alpine \
  tar xzf /backup/loki-data-backup.tar.gz -C /target

# Start Loki in compose
docker-compose -f docker-compose.yml -f docker-compose.mcp.yml --profile logging up -d loki
```

**Alloy agent update** (each server):
```hcl
# /etc/alloy/config.alloy
loki.write "default" {
  endpoint {
    url = "http://NEW_HOST:3100/loki/api/v1/push"
  }
}
```

**Success criteria**:
- [ ] Loki running in Docker Compose with migrated data
- [ ] Historical logs accessible and queryable
- [ ] All Alloy agents pushing to new Loki endpoint
- [ ] No log gaps during migration
- [ ] MCP containers work with internal Loki (no tunnel needed)

**Production command**:
```bash
docker-compose -f docker-compose.yml -f docker-compose.mcp.yml --profile mcp up -d
```

---

### Phase 3: Deploy MCP for Support

**Goal**: Enable support volunteers to use Claude-assisted log analysis.

**Prerequisites**: Phase 2 complete, Loki running in compose

**Tasks**:
1. Document access method (SSH tunnel for support volunteers)
2. Create MCP server wrapper for Claude Code configuration
3. Define and document MCP tools for common support scenarios
4. Create support volunteer setup guide
5. Test with real support scenarios
6. Train support volunteers on usage

**Access method** (SSH tunnel):
```bash
# Support volunteer runs:
ssh -L 8080:localhost:8080 bulk3.ilovefreegle.org
```

**Claude Code MCP configuration** (~/.claude.json):
```json
{
  "mcpServers": {
    "freegle-logs": {
      "command": "ssh",
      "args": [
        "-L", "8080:localhost:8080",
        "bulk3.ilovefreegle.org",
        "docker", "exec", "-i", "mcp-interface", "/app/mcp-server"
      ]
    }
  }
}
```

**Test scenarios**:
1. "User reports they can't log in" → Find auth errors for timeframe
2. "Emails not arriving" → Check mail spool logs
3. "Site was slow yesterday" → Find performance issues
4. "User sees error message" → Find stack trace from error ID

**Success criteria**:
- [ ] Support volunteers can connect Claude to MCP
- [ ] Claude can answer support questions using logs
- [ ] PII remains pseudonymized throughout
- [ ] Access restricted to authorized volunteers (SSH key required)
- [ ] Documentation enables self-service setup

---

### Timeline Estimate

| Phase | Duration | Dependencies |
|-------|----------|--------------|
| Phase 1: Local Dev | 1-2 weeks | None |
| Phase 2: Loki Migration | 1 week | Phase 1 tested |
| Phase 3: Support Deployment | 1 week | Phase 2 complete |
| **Total** | **3-4 weeks** |

## Open Questions

1. **Session vs Global Mapping**: Should tokens be consistent across sessions (better for correlation) or session-scoped (better for privacy)?

2. **Reverse Lookup UI**: Should ModTools have a (authenticated) way to look up "who is USER_7b2e91"?

3. **Query Restrictions**: Should certain LogQL patterns be blocked (e.g., queries that return too much data)?

4. **Audit Logging**: How much of Claude's activity should we log?

## References

- [ICO Pseudonymisation Guidance](https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/data-sharing/anonymisation/pseudonymisation/)
- [ICO Motivated Intruder Test](https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/data-sharing/anonymisation/how-do-we-ensure-anonymisation-is-effective/)
- [EDPB Guidelines 01/2025 on Pseudonymisation](https://www.edpb.europa.eu/system/files/2025-01/edpb_guidelines_202501_pseudonymisation_en.pdf)
- [NIST Tokenization Standards](https://www.cryptomathic.com/blog/emv-tokenization-and-fips-considerations)
- [Existing ModTools AI Support Implementation](../iznik-nuxt3-modtools/components/ModSupportAIAssistant.vue)
