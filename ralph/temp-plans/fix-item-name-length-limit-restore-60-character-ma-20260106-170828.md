# Task: Fix item name length limit - restore 60 character maximum. The limit exists in PostItem.vue (maxlength=60) but is missing from: 1) pages/give/mobile/details.vue, 2) pages/find/mobile/details.vue, 3) modtools/components/ModStdMessageModal.vue. Also add backend validation in iznik-server to truncate/reject items over 60 chars.

Created: 2026-01-06 17:08:28

## Task Status

| # | Task | Status | Notes |
|---|------|--------|-------|
| 1 | Fix item name length limit - restore 60 character maximum. The limit exists in PostItem.vue (maxlength=60) but is missing from: 1) pages/give/mobile/details.vue, 2) pages/find/mobile/details.vue, 3) modtools/components/ModStdMessageModal.vue. Also add backend validation in iznik-server to truncate/reject items over 60 chars. | ⬜ Pending | |

## Description

Fix item name length limit - restore 60 character maximum. The limit exists in PostItem.vue (maxlength=60) but is missing from: 1) pages/give/mobile/details.vue, 2) pages/find/mobile/details.vue, 3) modtools/components/ModStdMessageModal.vue. Also add backend validation in iznik-server to truncate/reject items over 60 chars.

## Success Criteria

- Task completed successfully
- All tests pass
- Code follows coding standards

## Associated PRs

### iznik-nuxt3 PRs
[{"number":133,"title":"Add comprehensive Playwright test coverage improvements","url":"https://github.com/Freegle/iznik-nuxt3/pull/133"}]

### iznik-nuxt3-modtools PRs
[{"number":133,"title":"Add comprehensive Playwright test coverage improvements","url":"https://github.com/Freegle/iznik-nuxt3/pull/133"}]

### iznik-server PRs
[{"number":32,"title":"Add TODO comments to skipped tests","url":"https://github.com/Freegle/iznik-server/pull/32"},{"number":29,"title":"Fix: In Group.php getMembers(), when FILTER_BOUNCING is set,  ","url":"https://github.com/Freegle/iznik-server/pull/29"},{"number":28,"title":"Fix: The Newsfeed::like() method calls Notifications::add() with ","url":"https://github.com/Freegle/iznik-server/pull/28"}]

### iznik-server-go PRs
[{"number":3,"title":"Add stats support to authority endpoint","url":"https://github.com/Freegle/iznik-server-go/pull/3"}]

### iznik-batch PRs
[{"number":6,"title":"Add ralph.sh iterative AI coding agent and extract shared coding standards","url":"https://github.com/Freegle/FreegleDocker/pull/6"}]

### FreegleDocker PRs
[{"number":6,"title":"Add ralph.sh iterative AI coding agent and extract shared coding standards","url":"https://github.com/Freegle/FreegleDocker/pull/6"}]
