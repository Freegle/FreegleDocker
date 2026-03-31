# Monorepo Migration Plan

## Motivation

Submodule pointers are commit hashes with no branch awareness. On 2026-03-20, FreegleDocker's iznik-nuxt3 pointer was accidentally on a feature branch commit (11 workers, 29 test files) instead of master (1 worker, 21 test files). CI passed for days with the wrong code. PR merges corrected the pointer and "broke" CI — but it was actually a correction. This class of bug is inherent to submodules.

## Current Repos

| Repo | Activity | Notes |
|------|----------|-------|
| Freegle/FreegleDocker | Active | Parent repo: docker-compose, CI orb, scripts |
| Freegle/iznik-nuxt3 | Very active | Vue 3 frontend + ModTools + Capacitor mobile |
| Freegle/iznik-server-go | Active | V2 Go API |
| Freegle/iznik-batch | Active | Laravel batch jobs + migrations |
| Freegle/iznik-server | Legacy | PHP V1 API, being replaced by Go |

## Approach: Shadow Monorepo with Gradual Cutover

Create `Freegle/iznik` as a parallel monorepo kept in sync via an automated merge script. All existing repos continue working unchanged. The monorepo is validated independently before any cutover. Starting this while the v2 migration branch is open is intentional — it surfaces integration problems early rather than after cutover.

## Infrastructure Affected

### CircleCI

**Current state:**
- FreegleDocker has a main `.circleci/config.yml` that delegates to a 3500+ line orb (`freegle-tests.yml`)
- The orb handles: submodule clone/init, container build, parallel test execution (Go, Laravel, PHP, Playwright), auto-merge master→production
- Submodule PR testing uses `clone-freegle-docker` + `replace-submodule` pattern — clones FreegleDocker, then swaps in the PR branch of the sub-repo
- Each submodule has `.github/workflows/trigger-parent-ci.yml` — on push to master, updates FreegleDocker's submodule pointer and pushes, triggering CI
- Critical secret: `FREEGLE_DOCKER_TOKEN` (org-scoped PAT) powers the webhook workflows

**Monorepo changes:**
- Remove submodule init/update steps from orb
- Remove `clone-freegle-docker` + `replace-submodule` pattern (PRs are now in the same repo)
- Add path-filtered test runs: only run Go tests if `iznik-server-go/` changed, etc. (CircleCI `path-filtering` orb or dynamic config)
- Remove `trigger-parent-ci.yml` from all sub-repos
- Auto-merge master→production stays the same

**Verification:** Run both CI pipelines in parallel (FreegleDocker + monorepo) and compare results.

### Docker Compose

**Current state:**
- `docker-compose.yml` has 30+ references to submodule paths (`./iznik-nuxt3`, `./iznik-server-go`, etc.)
- Dockerfiles in each submodule directory
- Volume mounts for dev containers use submodule paths
- Yesterday system uses `docker-compose.override.yesterday.yml` with same paths

**Monorepo changes:**
- All paths stay the same — the directory structure is identical (subtree preserves paths)
- No changes needed to docker-compose.yml, Dockerfiles, or volume mounts
- Yesterday system works unchanged

### Netlify (Web Deployment)

**Current state:**
- Two Netlify sites deploy from iznik-nuxt3's `production` branch:
  - Freegle site: `npm run build` → `dist/`
  - ModTools site: `cd modtools && npm run build` → `modtools/dist/`
- Triggered by webhook when CircleCI auto-merges master→production

**Monorepo changes:**
- Update Netlify build settings: base directory = `iznik-nuxt3/`
- Update webhook to trigger from monorepo's production branch
- Or: keep Netlify pointing at the old repo during transition (sync script keeps it current)

### Mobile Builds (Android + iOS)

**Current state:**
- Capacitor config in iznik-nuxt3 (`ionic.config.ts`)
- CircleCI jobs for Android (Fastlane + Gradle) and iOS (Fastlane + Xcode 26.2)
- Version management: `increment-version` job writes `.new_version` workspace file
- Android signing via keystores in environment variables
- Builds triggered on production branch
- Fastlane handles app store submission

**Monorepo changes:**
- Update CircleCI mobile build jobs to use `iznik-nuxt3/` as working directory
- Fastlane configs stay the same (they're relative to the app directory)
- Capacitor config stays the same
- Version file paths may need updating
- Signing keys and environment variables stay the same

### GitHub

**Current state:**
- Issues and PRs on each sub-repo
- Branch protection on each repo
- Dependabot on each repo
- GitHub Actions (trigger-parent-ci) on each sub-repo

**Monorepo changes:**
- Issues: migrate open issues via GitHub API script (title, body, labels, link back to original)
- Open PRs: finish in old repo (sync picks up result) or recreate branch in monorepo
- Closed PRs/issues: stay in archived repos for historical reference
- Set up branch protection on monorepo
- Configure Dependabot for monorepo paths
- Remove trigger-parent-ci workflows from sub-repos at cutover

### Production Deployment

**Current state:**
- CircleCI master tests pass → auto-merge to production branch in iznik-nuxt3
- Netlify deploys from production branch
- batch-prod container runs against production DB (secrets in `.env.background`)
- Go API container (apiv2) requires rebuild after code changes

**Monorepo changes:**
- Auto-merge targets monorepo's production branch instead
- batch-prod and apiv2 container builds reference same directory paths (no change)

## Migration Phases

### Phase 1: Create monorepo and sync script

1. Create `Freegle/iznik` repo on GitHub
2. Import each repo with full history via `git subtree add`
3. Copy FreegleDocker root files (docker-compose, .circleci, scripts, .env.example)
4. Write `sync-from-repos.sh`:
   - `git subtree pull` from each sub-repo's master
   - Handle merge conflicts gracefully (alert, don't auto-resolve)
   - Log what was synced
5. Set up GitHub Actions webhook: sub-repo push → trigger sync for that prefix
6. Run sync, verify `git log` / `git blame` work across full history

### Phase 2: Get CI running on monorepo

1. Adapt CircleCI config to work without submodule init/update
2. Add path-filtered test execution
3. Run both FreegleDocker CI and monorepo CI in parallel
4. Compare results until they match consistently
5. Test the v2 migration branch scenario: merge it in the monorepo, verify CI passes

### Phase 3: Get builds running on monorepo

1. Set up Netlify to deploy from monorepo (can use a preview/staging site first)
2. Set up mobile builds from monorepo
3. Verify app store submission works
4. Verify Yesterday system works from monorepo checkout

### Phase 4: Gradual development shift

1. Start making some changes directly in the monorepo
2. Changes in sub-repos sync automatically via the script
3. Changes in monorepo can push back via `git subtree push` if needed
4. As confidence grows, make the monorepo the primary

### Phase 5: Cutover

1. Migrate open issues from sub-repos to monorepo (scripted via GitHub API)
2. Close or recreate open PRs
3. Archive old sub-repos (read-only, history preserved)
4. Remove trigger-parent-ci workflows
5. Update all documentation and contributor guides
6. FreegleDocker becomes read-only, points to monorepo

## What Stays the Same During Transition

- All existing repos continue to work
- All existing CI pipelines continue to run
- Contributors keep using the repos they're used to
- Netlify deploys from existing repos
- Mobile builds from existing repos
- No disruption until explicit cutover decision

## Risks

1. **Merge conflicts in sync**: Unlikely given distinct directory structure, but possible if root files (docker-compose, CI config) are edited in both places
2. **Repo size**: Combined history will be large but manageable
3. **Two sources of truth**: During transition, need discipline about change direction. Sync script helps but doesn't prevent conflicts
4. **Fastlane path assumptions**: May hardcode paths that need adjusting
5. **CircleCI resource class**: Monorepo CI may need different resource allocation if running all tests
