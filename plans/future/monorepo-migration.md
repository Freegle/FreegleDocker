# Monorepo Migration Plan

## Overview

Migrate from the current multi-repo structure with Git submodules to a single monolithic repository while preserving full Git history for all repositories.

## Current Structure

```
FreegleDocker/
├── .circleci/
│   ├── config.yml
│   └── orb/freegle-tests.yml
├── iznik-server/          (submodule -> github.com/Freegle/iznik-server)
├── iznik-server-go/       (submodule -> github.com/Freegle/iznik-server-go)
├── iznik-nuxt3/           (submodule -> github.com/Freegle/iznik-nuxt3)
│   └── modtools/          (ModTools is now part of iznik-nuxt3, not a separate repo)
├── iznik-batch/           (submodule -> github.com/Freegle/iznik-batch)
├── status/
├── docker-compose.yml
└── testenv.php
```

**Note:** `iznik-nuxt3-modtools` was merged into `iznik-nuxt3/modtools/` - both Freegle and ModTools now build from the same repository with different build commands.

## Target Structure

```
FreegleDocker/
├── .circleci/
│   └── config.yml         (consolidated)
├── api-php/               (was iznik-server)
├── api-go/                (was iznik-server-go)
├── web/                   (was iznik-nuxt3, includes both Freegle and ModTools)
│   └── modtools/          (ModTools-specific build config)
├── batch/                 (was iznik-batch)
├── status/
├── docker-compose.yml
└── testenv.php
```

## Phase 1: Preparation (Estimated: 2-4 hours)

### 1.1 Create Migration Branch
```bash
git checkout -b monorepo-migration
```

### 1.2 Document Current CI Configuration
- Export list of all CircleCI jobs, workflows, and triggers
- Document environment variables used by each repo
- List all GitHub Actions workflows in submodules

### 1.3 Backup
- Create backup branches in all repositories
- Document current submodule commit SHAs

## Phase 2: History-Preserving Migration (Estimated: 4-6 hours)

### 2.1 Prepare Submodule Histories

For each submodule, rewrite history to move files into subdirectory:

```bash
# Clone each repo separately
git clone https://github.com/Freegle/iznik-server.git /tmp/iznik-server-migrate
cd /tmp/iznik-server-migrate

# Rewrite history to move all files into api-php/
git filter-repo --to-subdirectory-filter api-php

# Repeat for other repos:
# iznik-server-go -> api-go/
# iznik-nuxt3 -> web/
# iznik-batch -> batch/
```

### 2.2 Merge Histories into FreegleDocker

```bash
cd /path/to/FreegleDocker
git checkout monorepo-migration

# Remove submodules
git submodule deinit -f iznik-server
git rm -f iznik-server
rm -rf .git/modules/iznik-server

# Add remote and merge history
git remote add iznik-server-history /tmp/iznik-server-migrate
git fetch iznik-server-history --tags
git merge iznik-server-history/master --allow-unrelated-histories -m "Merge iznik-server history into monorepo"

# Repeat for each submodule
```

### 2.3 Clean Up
- Remove .gitmodules file
- Update .gitignore
- Remove submodule entries from .git/config

## Phase 3: CircleCI Reconfiguration (Estimated: 6-8 hours)

### 3.1 Current CI Triggers

| Repository | Trigger | Action |
|------------|---------|--------|
| FreegleDocker | Push to master | Full test suite |
| iznik-server | Push to master | Webhook to FreegleDocker CI |
| iznik-server | PR | Clone FreegleDocker, run PHP tests |
| iznik-server-go | Push to master | Webhook to FreegleDocker CI |
| iznik-server-go | PR | Clone FreegleDocker, run Go tests |
| iznik-nuxt3 | Push to master | Webhook to FreegleDocker CI |
| iznik-nuxt3 | PR | Clone FreegleDocker, run Playwright tests |

### 3.2 New CI Configuration

**Single config.yml with path filtering:**

```yaml
version: 2.1

# Path filters for selective job execution
parameters:
  run-api-php-tests:
    type: boolean
    default: false
  run-api-go-tests:
    type: boolean
    default: false
  run-web-tests:
    type: boolean
    default: false
  run-all-tests:
    type: boolean
    default: false

workflows:
  # Triggered on any push - determine what changed
  detect-changes:
    jobs:
      - detect-changed-paths:
          filters:
            branches:
              ignore: production

  # Selective test workflows based on changed paths
  api-php-tests:
    when: << pipeline.parameters.run-api-php-tests >>
    jobs:
      - run-php-tests

  api-go-tests:
    when: << pipeline.parameters.run-api-go-tests >>
    jobs:
      - run-go-tests

  web-tests:
    when: << pipeline.parameters.run-web-tests >>
    jobs:
      - run-playwright-tests

  full-test-suite:
    when: << pipeline.parameters.run-all-tests >>
    jobs:
      - run-go-tests
      - run-php-tests
      - run-playwright-tests
      - auto-merge:
          requires:
            - run-go-tests
            - run-php-tests
            - run-playwright-tests

jobs:
  detect-changed-paths:
    docker:
      - image: cimg/base:current
    steps:
      - checkout
      - run:
          name: Detect changed paths and trigger appropriate workflows
          command: |
            # Get changed files
            CHANGED_FILES=$(git diff --name-only HEAD~1 HEAD 2>/dev/null || git diff --name-only HEAD)

            # Determine which tests to run
            RUN_PHP=false
            RUN_GO=false
            RUN_WEB=false

            echo "$CHANGED_FILES" | while read file; do
              case "$file" in
                api-php/*) RUN_PHP=true ;;
                api-go/*) RUN_GO=true ;;
                web-freegle/*|web-modtools/*) RUN_WEB=true ;;
                docker-compose.yml|testenv.php|.circleci/*)
                  RUN_PHP=true
                  RUN_GO=true
                  RUN_WEB=true
                  ;;
              esac
            done

            # Trigger appropriate pipeline parameters
            # (Use CircleCI API to trigger with parameters)
```

### 3.3 Migration Steps for CircleCI

1. **Update orb references** - The freegle-tests orb will need path updates
2. **Remove webhook workflows** from submodule repos (no longer needed)
3. **Update Dockerfile paths** - References to `/var/www/iznik` may need updating
4. **Update docker-compose.yml** - Build context paths change

### 3.4 Jobs to Rename/Reconfigure

| Old Job | New Job | Changes Required |
|---------|---------|------------------|
| `iznik-server-pr-tests` | `api-php-pr-tests` | Path filters, remove clone step |
| `iznik-server-go-pr-tests` | `api-go-pr-tests` | Path filters, remove clone step |
| `iznik-nuxt3-pr-tests` | `web-pr-tests` | Path filters, remove clone step |
| `build-and-test` | `full-test-suite` | Update all path references |

## Phase 3b: Coveralls Configuration

### Current Coveralls Setup

Currently, code coverage is uploaded from CircleCI to Coveralls for each submodule:
- `iznik-server` (iznik-batch) - PHPUnit coverage
- `iznik-server-go` - Go test coverage

### Migration Tasks

1. **Update Coveralls project** - May need to reconfigure for monorepo structure
2. **Update coverage paths** - Coverage reports will reference new directory structure
3. **Consolidate coverage reporting** - Consider unified coverage report across all components
4. **Update .coveralls.yml** if present in submodules

### Post-Migration Coverage Structure

```yaml
# Example coverage configuration
coverage:
  paths:
    - api-php/       # PHP coverage
    - api-go/        # Go coverage
    - web/           # Frontend coverage (if added)
```

## Phase 3c: Netlify Configuration

### Current Netlify Setup

Two separate Netlify sites deploy from `iznik-nuxt3` production branch:
- **Freegle site**: `npm run build` → deploys `dist/`
- **ModTools site**: `cd modtools && npm run build` → deploys `modtools/dist/`

### Migration Tasks

1. **Update build commands** - Change paths from `iznik-nuxt3` to `web/`
2. **Update base directory** - Set to `web/` in Netlify project settings
3. **Update publish directory** - Ensure correct output paths
4. **Test deploy previews** - Verify PR previews work with monorepo

### Netlify Configuration Changes

```toml
# netlify.toml for Freegle site
[build]
  base = "web/"
  command = "npm run build"
  publish = "dist/"

# netlify.toml for ModTools site
[build]
  base = "web/"
  command = "cd modtools && npm run build"
  publish = "modtools/dist/"
```

### Considerations

- **Monorepo detection** - Netlify has monorepo support, may auto-detect changes
- **Build filtering** - Configure to only rebuild when `web/` changes
- **Environment variables** - Verify all env vars are set correctly

## Phase 3d: Sentry Configuration

### Current Sentry Setup

Sentry is configured in the frontend for error tracking:
- DSN configured via environment variables
- Source maps uploaded during build
- Releases tagged with git commit

### Migration Tasks

1. **Update source map paths** - New directory structure affects source map uploads
2. **Update release naming** - May need to adjust release naming convention
3. **Update Sentry project settings** - Verify project configuration
4. **Test error tracking** - Ensure errors are properly captured post-migration

### Sentry CLI Configuration

```bash
# Update sentry-cli commands for new paths
sentry-cli releases files $RELEASE upload-sourcemaps web/dist --url-prefix '~/'
```

### Considerations

- **Multiple projects** - May want separate Sentry projects for different components
- **Release association** - Ensure commits are properly linked to releases
- **Environment separation** - dev/staging/production environments

## Phase 3e: GitHub Actions Migration

### Current GitHub Actions Setup

Each submodule has `.github/workflows/trigger-parent-ci.yml` that:
1. Triggers on push to master
2. Clones FreegleDocker
3. Updates submodule reference
4. Pushes back to FreegleDocker to trigger CircleCI

### Migration Tasks

1. **Remove trigger workflows** - No longer needed in monorepo
2. **Archive submodule repos** - Mark as read-only, update READMEs
3. **Add monorepo workflows** if needed (optional, CircleCI handles CI)
4. **Update branch protection rules** - Configure for monorepo branches

### Workflows to Remove

| Repository | Workflow File | Purpose |
|------------|--------------|---------|
| iznik-nuxt3 | trigger-parent-ci.yml | Trigger FreegleDocker CI |
| iznik-server | trigger-parent-ci.yml | Trigger FreegleDocker CI |
| iznik-server-go | trigger-parent-ci.yml | Trigger FreegleDocker CI |
| iznik-batch | trigger-parent-ci.yml | Trigger FreegleDocker CI |

### Post-Migration

- GitHub Actions workflows in submodules become obsolete
- All CI runs directly from FreegleDocker on push
- No more webhook coordination needed

## Phase 3f: Git Hooks Configuration

### Current Git Hooks

Git hooks may exist in individual submodules for:
- Pre-commit linting (ESLint, PHP CS Fixer)
- Commit message validation
- Pre-push tests

### Migration Tasks

1. **Consolidate hooks** - Create unified hooks at monorepo root
2. **Update hook paths** - Hooks must work with new directory structure
3. **Consider Husky** - Use Husky for cross-platform hook management
4. **Path-aware hooks** - Only run relevant checks based on changed files

### Recommended Hook Structure

```bash
# .husky/pre-commit
#!/bin/sh
. "$(dirname "$0")/_/husky.sh"

# Get changed files
CHANGED_FILES=$(git diff --cached --name-only)

# Run ESLint on changed JS/Vue files in web/
if echo "$CHANGED_FILES" | grep -q "^web/.*\.\(js\|vue\|ts\)$"; then
  cd web && npx eslint --fix $(echo "$CHANGED_FILES" | grep "^web/" | sed 's/^web\///')
fi

# Run PHP CS Fixer on changed PHP files in api-php/
if echo "$CHANGED_FILES" | grep -q "^api-php/.*\.php$"; then
  cd api-php && ./vendor/bin/php-cs-fixer fix --dry-run
fi
```

### Considerations

- **Performance** - Hooks should be fast, only check changed files
- **Skip option** - Allow `--no-verify` for emergency commits
- **CI backup** - Full linting runs in CI regardless of local hooks

## Phase 4: Docker Configuration Updates

### 4.1 Dockerfile Updates

Update build contexts in Dockerfiles:

```dockerfile
# Before (in iznik-server/Dockerfile)
COPY . /var/www/iznik

# After (in api-php/Dockerfile)
COPY . /var/www/iznik
```

### 4.2 docker-compose.yml Updates

```yaml
# Before
services:
  apiv1:
    build:
      context: ./iznik-server
      dockerfile: Dockerfile

# After
services:
  apiv1:
    build:
      context: ./api-php
      dockerfile: Dockerfile
```

### 4.3 Volume Mounts

Update all volume mount paths in docker-compose.yml and override files.

## Phase 5: Testing and Validation (Estimated: 4-6 hours)

### 5.1 Local Testing
- [ ] All containers build successfully
- [ ] All containers start and become healthy
- [ ] PHP tests pass
- [ ] Go tests pass
- [ ] Playwright tests pass
- [ ] Status page works correctly

### 5.2 CI Testing
- [ ] Push to feature branch triggers correct tests
- [ ] Changes to api-php/ only trigger PHP tests
- [ ] Changes to api-go/ only trigger Go tests
- [ ] Changes to web-freegle/ trigger Playwright tests
- [ ] Changes to shared files trigger all tests
- [ ] Auto-merge to production works

### 5.3 History Verification
```bash
# Verify history is preserved
git log --oneline api-php/ | head -20
git log --oneline api-go/ | head -20
git log --oneline web-freegle/ | head -20

# Verify blame works
git blame api-php/http/api/message.php
```

## Phase 6: Deployment (Estimated: 2-3 hours)

### 6.1 Deployment Steps

1. Merge monorepo-migration branch to master
2. Update CircleCI project settings if needed
3. Verify all CI jobs run correctly
4. Archive old submodule repositories (make read-only)
5. Update any external documentation/links

### 6.2 Rollback Plan

If issues arise:
1. Submodule repositories remain intact
2. Can revert to previous commit with submodules
3. Re-initialize submodules if needed

## Risks and Mitigations

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| History merge conflicts | Medium | Medium | Test thoroughly in separate branch first |
| CI configuration errors | High | Medium | Keep old config until new is validated |
| Docker build failures | Medium | High | Test all builds locally before push |
| Path reference bugs | High | Low | Comprehensive grep for old paths |
| Team confusion | Medium | Low | Clear documentation and communication |
| Netlify deploy failures | Medium | High | Test deploy previews before merge |
| Coveralls path mismatches | Medium | Low | Update coverage config before migration |
| Sentry source map errors | Medium | Medium | Test error tracking post-migration |
| Lost GitHub Actions history | Low | Low | Archive workflows before removing |

## Benefits After Migration

1. **Simplified dependency management** - No submodule sync issues
2. **Atomic commits** - Changes across multiple components in single commit
3. **Easier CI** - No need for webhook triggers between repos
4. **Better code review** - See full context of changes
5. **Simplified onboarding** - Single clone command
6. **Consistent tooling** - Shared linting, formatting, pre-commit hooks

## Estimated Total Effort

| Phase | Hours | Dependencies |
|-------|-------|--------------|
| 1. Preparation | 2-4 | None |
| 2. History Migration | 4-6 | Phase 1 |
| 3. CircleCI Reconfiguration | 6-8 | Phase 2 |
| 3b. Coveralls Configuration | 1-2 | Phase 2 |
| 3c. Netlify Configuration | 2-3 | Phase 2 |
| 3d. Sentry Configuration | 1-2 | Phase 2 |
| 3e. GitHub Actions Migration | 1-2 | Phase 2 |
| 3f. Git Hooks Configuration | 1-2 | Phase 2 |
| 4. Docker Updates | 2-3 | Phase 2 |
| 5. Testing | 4-6 | Phases 3-4 |
| 6. Deployment | 2-3 | Phase 5 |
| **Total** | **26-41** | |

## Alternative: Keep Submodules

If the migration effort is too high, consider these improvements to current setup:

1. **Automate submodule updates** - Script to update all submodules
2. **Improve webhook reliability** - Add retry logic
3. **Better documentation** - Clear guide for working with submodules
4. **Git aliases** - Simplify common submodule operations

## Decision Checklist

Before proceeding, confirm:
- [ ] Team agrees on new directory structure
- [ ] CircleCI credits/plan supports additional pipeline triggers during transition
- [ ] Backup strategy is in place
- [ ] Communication plan for contributors
- [ ] Timeline aligns with development schedule (avoid during active feature work)
- [ ] Netlify project settings documented
- [ ] Coveralls configuration understood
- [ ] Sentry DSN and project settings documented
- [ ] GitHub Actions workflows in submodules identified
- [ ] Git hooks in submodules identified and consolidated
