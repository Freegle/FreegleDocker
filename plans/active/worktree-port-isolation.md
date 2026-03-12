# Worktree Port Isolation Plan

**GitHub Issue**: #76 - Remove exposed ports from docker-compose for parallel worktree instances
**Status**: Planning (not yet implemented)
**Created**: 2026-03-05

## Problem Statement

When using git worktrees with Claude Code, each worktree needs its own Docker Compose environment. Currently this causes port clashes because all 25+ port bindings are hardcoded (with PORT_* env vars as the only mitigation). Even with port offsets, Claude consistently forgets which ports belong to which environment, leading to repeated confusion and wasted time.

### Why Port Offsets Don't Work

The offset approach (e.g., PORT_TRAEFIK_HTTP=80 for main, 10080 for worktree-1) fails because:
1. Claude loses track of which offset is for which worktree across context compactions
2. Documentation in .md files isn't reliably consulted
3. 25+ ports per environment means 25+ things to get right
4. Windows host browser bookmarks/shortcuts break when offsets change
5. Chrome MCP and Playwright configs need different URLs per environment

### Current Port Inventory (25 bindings across 16 services)

| Service | Host Port | Container Port |
|---------|-----------|----------------|
| traefik | 80, 8192, 8080 | 80, 8192, 8080 |
| phpmyadmin | 8086 | 80 |
| mailpit | 8025, 1025 | 8025, 1025 |
| spamassassin | 783 | 783 |
| rspamd | 11334 | 11334 |
| tusd | 1080 | 8080 |
| status | 8081 | 3000 |
| ai-support | 8083 | 3000 |
| apiv1 | 1023, 83, 8181 | 22, 80, 80 |
| apiv2 | 8193 | 8192 |
| apiv2-live | 8194 | 8192 |
| dev-local | 3002 | 3002 |
| dev-live | 3005 | 3002 |
| prod-local | 3012 | 3003 |
| modtools-dev-local | 3003 | 3000 |
| modtools-dev-live | 3006 | 3000 |
| modtools-prod-local | 3013 | 3001 |
| loki | 3100 | 3100 |
| postfix | 25 | 25 |
| mcp-sanitizer | 8084 | 8080 |

## Design Constraints

1. **One manual test environment at a time** - Only one worktree needs browser access from Windows host
2. **Playwright must work per-worktree** - Currently uses `network_mode: host`; needs to work without host ports
3. **Chrome MCP must work** - Claude uses this for visual testing; connects to Chrome on Windows host
4. **CI must keep working** - Only one instance per CI VM, needs explicit port exposure
5. **Yesterday must keep working** - Single instance, needs ports
6. **No port numbers in Claude's working memory** - The core UX goal
7. **Backward compatible** - Single-checkout users must not need any changes or new commands
8. **Per-worktree databases** - Full isolation; each worktree gets its own volumes and runs migrations independently

## Research: Industry Approaches

### Approach A: Shared Traefik Gateway (recommended by community)
One Traefik instance on ports 80/443 discovers all compose projects via Docker labels. Each project uses unique hostnames (e.g., `main-freegle.localhost`, `feature-xyz-freegle.localhost`). No per-project port exposure needed.
- **Source**: [Holger Woltersdorf's guide](https://hollo.me/devops/routing-to-multiple-docker-compose-development-setups-with-traefik.html)
- **Pro**: Clean, automatic discovery
- **Con**: Requires hostname-per-environment, changes browser URLs

### Approach B: No Ports + docker exec (Issue #76's original approach)
Remove all ports. Inter-service communication via Docker DNS. Automated tests via `docker exec` into status container. One `freegle expose` command to add ports to the active environment.
- **Pro**: Eliminates port clashes entirely
- **Con**: Large refactor (7 phases), Playwright container redesign needed

### Approach C: Provider Constraints (label-based isolation)
Multiple Traefik instances, each filtering containers by label (e.g., `compose.project=main` vs `compose.project=feature-x`). Only the "active" Traefik binds to host ports.
- **Source**: [Robert Jensen's guide](https://www.robert-jensen.dk/posts/2025/multiple-traefik-instances-single-docker-host/)
- **Pro**: Clean isolation
- **Con**: Multiple Traefik instances is wasteful

### Approach D: iptables DNAT (kernel-level routing)
Use firewall rules for transparent port remapping. No application changes needed.
- **Source**: [Ivan Velichko's guide](https://iximiuz.com/en/posts/multiple-containers-same-port-reverse-proxy/)
- **Pro**: Transparent to applications
- **Con**: Complex, fragile, not portable to Windows/WSL2

## Recommended Architecture: COMPOSE_FILE-based Port Overlay

### Core Principle: Ports Live in a Separate Compose File, Included by Default

```
docker-compose.yml              # No ports. All services, internal networking only.
docker-compose.ports.yml        # All port bindings. Checked into git.
docker-compose.override.yesterday.yml  # Yesterday-specific (existing, updated)
```

**The mechanism**: Docker Compose's `COMPOSE_FILE` env var controls which files are loaded.

```
# .env (default - single checkout, ports exposed)
COMPOSE_FILE=docker-compose.yml:docker-compose.ports.yml

# .env in a secondary worktree (no ports)
COMPOSE_FILE=docker-compose.yml
```

### Why This Works for Everyone

| User | COMPOSE_FILE | Ports? | Changes needed? |
|------|-------------|--------|----------------|
| Single-checkout dev (existing) | `docker-compose.yml:docker-compose.ports.yml` | Yes, all standard ports | **None** |
| Main worktree (active) | `docker-compose.yml:docker-compose.ports.yml` | Yes | None |
| Secondary worktree (background) | `docker-compose.yml` | No | Auto-configured by `freegle worktree create` |
| CI | `docker-compose.yml:docker-compose.ports.yml` | Yes | Orb copies ports file reference into .env |
| Yesterday | `docker-compose.yml:docker-compose.ports.yml:docker-compose.override.yesterday.yml` | Yes (remapped) | Update override to layer on top of ports file |

**Single-checkout users change nothing.** The default `.env` includes the ports file. Everything works exactly as today.

### Architecture Diagram

```
                    Windows Host (browser, Chrome MCP)
                         |
                    port 80 (Traefik) — only from active worktree
                         |
              [Active worktree's Traefik]
                    /         \
            [services]       [services]
            (internal)       (internal)

     Main worktree              Worktree "feature-x"
     COMPOSE_FILE includes      COMPOSE_FILE excludes
     ports overlay              ports overlay
     (ports exposed)            (no ports, no clash)
```

### How `freegle activate` Works

```bash
freegle activate feature-x
```

1. Finds the worktree directory for `feature-x`
2. In the **current** worktree's `.env`: removes `docker-compose.ports.yml` from `COMPOSE_FILE`
3. In `feature-x`'s `.env`: adds `docker-compose.ports.yml` to `COMPOSE_FILE`
4. Restarts Traefik in both (old one releases ports, new one binds them)

```bash
freegle activate        # (no argument) — activates the current worktree
freegle deactivate      # removes ports overlay from current worktree
```

### Key Design Decisions

1. **Ports in a separate checked-in compose file** (`docker-compose.ports.yml`) — not generated, not an override, just a standard compose file included via `COMPOSE_FILE`
2. **Default `.env` includes it** — backward compatible, no change for single-checkout users
3. **`freegle activate` swaps which `.env` includes it** — one command, no port numbers to remember
4. **Fixed, well-known ports always** — the active environment always uses port 80 (Traefik), 8025 (Mailpit), 3100 (Loki), etc. No offsets ever.
5. **Per-worktree databases** — volumes scoped by `COMPOSE_PROJECT_NAME`, each worktree runs migrations on first startup
6. **Playwright joins Docker network** — no host ports needed for automated testing
7. **Chrome MCP works unchanged** — connects to `*.localhost:80` which is always the active worktree

### How Playwright Works Without Host Ports

Currently Playwright uses `network_mode: host` and resolves `*.localhost` to `127.0.0.1` (via extra_hosts), hitting Traefik on the host's port 80.

New approach:
- Playwright container joins the compose default network
- Add `.localhost` hostnames as network aliases on the Traefik service
- Playwright resolves `freegle-prod-local.localhost` via Docker DNS to the Traefik container
- Traefik routes to the correct backend as normal
- No host ports needed for Playwright at all
- Works identically in every worktree regardless of which is "active"

### How Chrome MCP Works

Chrome MCP connects to a Chrome browser on the Windows host. The user/Claude navigates to URLs like `http://freegle-prod-local.localhost`. Chrome resolves `*.localhost` to `127.0.0.1`. WSL2 port forwarding delivers traffic to the active worktree's Traefik on port 80.

This is **identical to how it works today** — no change needed for Chrome MCP.

### How CI Works

CI runs a single instance on each VM. The orb's setup step ensures `COMPOSE_FILE` includes the ports overlay:

```yaml
# In the orb's start-services command
- run:
    name: Configure compose files
    command: |
      cd "$HOME/FreegleDocker"
      # Ensure .env has ports overlay (should be default, but be explicit)
      if ! grep -q 'docker-compose.ports.yml' .env 2>/dev/null; then
        echo 'COMPOSE_FILE=docker-compose.yml:docker-compose.ports.yml' >> .env
      fi
```

The orb currently uses `docker-compose -f docker-compose.yml` explicitly in ~20 places. These would change to just `docker-compose` (which reads `COMPOSE_FILE` from `.env`), or be updated to `-f docker-compose.yml -f docker-compose.ports.yml`.

**Hardcoded container names in CI**: The orb references `freegle-*` container names in ~50 places (`docker exec freegle-apiv1`, `docker cp freegle-playwright:...`, etc.). These continue to work because the default `COMPOSE_PROJECT_NAME` remains `freegle`. CI never uses worktrees.

Key CI touch points:
- Line 276: Container name verification list (`ALL_CONTAINERS="freegle-traefik freegle-percona..."`)
- Lines 752-968: `docker exec`/`docker cp` with `freegle-apiv1`, `freegle-apiv1-phpunit`, `freegle-apiv2`
- Lines 1199-1234: `docker cp freegle-playwright:/app/...`
- Lines 1358-1378: Artifact collection from named containers

**No changes needed to CI container name references.** `COMPOSE_PROJECT_NAME` defaults to `freegle`, container names stay `freegle-*`.

### How Yesterday Works

Yesterday currently uses `docker-compose.override.yesterday.yml` which is copied to `docker-compose.override.yml`. It remaps some ports (apiv1 to 8182, apiv2 to 8195) and adds ports to dev containers.

New approach: Yesterday's `.env` sets:
```
COMPOSE_FILE=docker-compose.yml:docker-compose.ports.yml:docker-compose.override.yesterday.yml
```

The Yesterday override uses `ports: !reset` to remap ports that conflict with yesterday-traefik. This **already works** with the layered compose file approach — `!reset` in the Yesterday override replaces the ports from the ports overlay.

The Yesterday override continues to work unchanged. The only update is adding the `COMPOSE_FILE` line to Yesterday's `.env`.

## Implementation Phases

### Phase 1: Traefik Network Aliases (foundation)
- Add all `.localhost` hostnames as `aliases` on the Traefik service in the default network
- Remove `extra_hosts: host-gateway` from dev containers (replaced by aliases)
- Simplify internal URLs: drop port numbers from `http://apiv1.localhost:${PORT_TRAEFIK_HTTP:-80}/api`
- Add Traefik provider constraint: `--providers.docker.constraints=Label('com.docker.compose.project','${COMPOSE_PROJECT_NAME:-freegle}')`

### Phase 2: Fix Hardcoded Container Names in Internal References
- `http://freegle-tusd:8080/tus` -> `http://tusd:8080/tus` (use service names)
- All `freegle-*` references in container-to-container configs -> service names
- This enables `COMPOSE_PROJECT_NAME` to vary per worktree
- **Note**: CI `docker exec`/`docker cp` references stay as `freegle-*` (they use the default project name)

### Phase 3: Extract Ports + Parameterize Names
- Move all 25 `ports:` sections from `docker-compose.yml` into new `docker-compose.ports.yml`
- Change `container_name: freegle-xxx` to `container_name: ${COMPOSE_PROJECT_NAME:-freegle}-xxx`
- Move Playwright from `network_mode: host` to default network
- Scope volumes: `percona-data` -> `${COMPOSE_PROJECT_NAME:-freegle}-percona-data`
- Update `.env` default: `COMPOSE_FILE=docker-compose.yml:docker-compose.ports.yml`

### Phase 4: Update CI and Yesterday
- Update CI orb to either:
  - Drop explicit `-f docker-compose.yml` (let `COMPOSE_FILE` from `.env` handle it), OR
  - Use `-f docker-compose.yml -f docker-compose.ports.yml` explicitly
- Update Yesterday override to layer correctly on top of ports file
- Update Yesterday `.env` with `COMPOSE_FILE` line
- **Test**: Run full CI pipeline on feature branch before merging

### Phase 5: Status Container Updates
- `getContainerName(suffix)` using `COMPOSE_PROJECT_NAME` env var
- Update ~30 service definitions in services.ts
- Update test trigger endpoints (go, php, playwright, laravel)
- Update file-sync.sh

### Phase 6: CLI Tool (`freegle` bash script)
```bash
freegle activate [worktree-name]  # Swaps COMPOSE_FILE in .env files, restarts Traefik
freegle deactivate                # Removes ports overlay from current .env
freegle status                    # Shows which worktree is active + container health
freegle worktree create <name>    # git worktree + COMPOSE_PROJECT_NAME + COMPOSE_FILE setup
freegle worktree list             # Shows worktrees with compose status
freegle worktree remove <name>    # Stops compose, removes volumes and worktree
```

### Phase 7: Documentation
- Update CLAUDE.md with multi-instance section
- Update .env.example
- Update worktree skill to auto-set COMPOSE_PROJECT_NAME and COMPOSE_FILE

## Implementation Order

Phases 1-4 MUST ship together (extracting ports without CI/Yesterday updates breaks those environments):

```
[Phase 1 + 2 + 3 + 4] -> [Phase 5] -> [Phase 6] -> [Phase 7]
```

## Risks and Mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| Traefik cross-project container discovery | Routes go to wrong project | Provider constraint on compose.project label |
| Playwright DNS resolution in Docker network | Tests can't reach services | Traefik network aliases + test in CI before merging |
| WSL2 port forwarding with single Traefik | Browser can't reach active env | Test WSL2 localhost forwarding early in Phase 1 |
| CI orb `-f` flags out of sync | CI builds fail | Phase 4 explicitly tests full CI pipeline on branch |
| Yesterday override `!reset` interactions | Port remapping breaks | Test Yesterday compose config validates before deploying |
| Volume isolation between worktrees | Data corruption | Named volumes scoped by COMPOSE_PROJECT_NAME |
| `docker exec` to status container needs project name | Claude forgets project name | `freegle status` command; status container gets COMPOSE_PROJECT_NAME env var |

## What This Does NOT Change

- Browser URLs stay the same (`*.localhost`)
- Chrome MCP workflow stays the same
- Single-checkout users change nothing (default `.env` includes ports)
- CI container names stay `freegle-*` (default COMPOSE_PROJECT_NAME)
- Yesterday override mechanism stays the same (just adds COMPOSE_FILE to .env)
- All inter-service communication stays the same (Docker DNS)

## Design Decisions (resolved)

1. **Per-worktree databases**: Yes. Full isolation. Each worktree gets scoped volumes and runs migrations on first startup.
2. **CLI tool**: Bash script (`freegle`). Consistent with existing `ralph.sh`.
3. **Mailpit**: Included in ports overlay. Only accessible on the active worktree. You only check email for the environment you're actively testing.
4. **MCP tools (Loki, sanitizer, etc)**: Included in ports overlay. When you activate a worktree, you get all its ports. One command, everything works.
5. **Backward compatibility**: Default `.env` includes `docker-compose.ports.yml` via `COMPOSE_FILE`. Existing single-checkout users change nothing.
