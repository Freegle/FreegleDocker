# TDD Migration Checklist

This file tracks the proper TDD migration of modtools components from Options API to script setup.

## Process for each component:
1. **REVERT**: Check out the OLD Options API version from master
2. **TEST**: Write a unit test for the component
3. **VERIFY OLD**: Run test, ensure it passes on Options API code
4. **MIGRATE**: Re-apply the script setup migration
5. **VERIFY NEW**: Run test, ensure it passes on script setup code
6. **COMMIT**: Commit the test (migration already committed)

## Status Legend:
- ⬜ Pending
- 🔄 In Progress
- ✅ Complete
- ❌ Blocked

## Components (124 total)

| # | Component | Status | Test Written | Old Passes | New Passes | Notes |
|---|-----------|--------|--------------|------------|------------|-------|
| 1 | ModAddMemberModal | ✅ | ✅ | ✅ | ✅ | 9 tests |
| 2 | ModAdmin | ✅ | ✅ | ✅ | ✅ | 23 tests |
| 3 | ModAffiliationConfirmModal | ✅ | ✅ | ✅ | ✅ | 7 tests |
| 4 | ModAimsModal | ✅ | ✅ | ✅ | ✅ | 4 tests |
| 5 | ModAlertHistory | ✅ | ✅ | ✅ | ✅ | 9 tests |
| 6 | ModAlertHistoryDetailsModal | ✅ | ✅ | ✅ | ✅ | 7 tests |
| 7 | ModAlertHistoryStatsModal | ✅ | ✅ | ✅ | ✅ | 8 tests |
| 8 | ModBanMemberConfirmModal | ✅ | ✅ | ✅ | ✅ | 13 tests |
| 9 | ModBanMemberModal | ✅ | ✅ | ✅ | ✅ | 9 tests |
| 10 | ModBouncing | ✅ | ✅ | ✅ | ✅ | 9 tests |
| 11 | ModCake | ✅ | ✅ | ✅ | ✅ | 10 tests |
| 12 | ModCakeModal | ✅ | ✅ | ✅ | ✅ | 6 tests |
| 13 | ModChatModal | ⬜ | | | | Complex async setup |
| 14 | ModChatNoteModal | ✅ | ✅ | ✅ | ✅ | 11 tests |
| 15 | ModChatReview | ⬜ | | | | Large complex |
| 16 | ModChatReviewUser | ✅ | ✅ | ✅ | ✅ | 19 tests |
| 17 | ModChatViewButton | ✅ | ✅ | ✅ | ✅ | 8 tests |
| 18 | ModComment | ⬜ | | | | Complex composables |
| 19 | ModCommentAddModal | ⬜ | | | | |
| 20 | ModCommentEditModal | ⬜ | | | | |
| 21 | ModCommentUser | ✅ | ✅ | ✅ | ✅ | 14 tests |
| 22 | ModComments | ⬜ | | | | |
| 23 | ModCommunityEvent | ✅ | ✅ | ✅ | ✅ | 17 tests |
| 24 | ModConfigSetting | ⬜ | | | | |
| 25 | ModConvertKML | ✅ | ✅ | ✅ | ✅ | 11 tests |
| 26 | ModDashboardDiscourseTopic | ✅ | ✅ | ✅ | ✅ | 15 tests |
| 27 | ModDashboardDiscourseTopics | ⬜ | | | | |
| 28 | ModDashboardFreeglersPosting | ✅ | N/A | N/A | ✅ | 10 tests (extends→composable) |
| 29 | ModDashboardFreeglersReplying | ⬜ | | | | |
| 30 | ModDashboardImpact | ⬜ | | | | |
| 31 | ModDashboardModeratorsActive | ⬜ | | | | |
| 32 | ModDashboardPopularPosts | ⬜ | | | | |
| 33 | ModDashboardRecentCounts | ⬜ | | | | |
| 34 | ModDeletedOrForgotten | ✅ | N/A | N/A | ✅ | 8 tests (auto-import) |
| 35 | ModGiftAid | ⬜ | | | | |
| 36 | ModGroupRule | ✅ | ✅ | ✅ | ✅ | 19 tests |
| 37 | ModGroupSelect | ⬜ | | | | |
| 38 | ModGroupSetting | ⬜ | | | | |
| 39 | ModImpact | ⬜ | | | | |
| 40 | ModLog | ⬜ | | | | |
| 41 | ModLogGroup | ⬜ | | | | |
| 42 | ModLogMessage | ⬜ | | | | |
| 43 | ModLogs | ⬜ | | | | |
| 44 | ModLogsModal | ⬜ | | | | |
| 45 | ModMember | ⬜ | | | | |
| 46 | ModMemberActions | ⬜ | | | | |
| 47 | ModMemberButton | ⬜ | | | | |
| 48 | ModMemberButtons | ⬜ | | | | |
| 49 | ModMemberEngagement | ⬜ | | | | |
| 50 | ModMemberExportButton | ⬜ | | | | |
| 51 | ModMemberHappiness | ⬜ | | | | |
| 52 | ModMemberRating | ⬜ | | | | |
| 53 | ModMemberReview | ⬜ | | | | |
| 54 | ModMemberReviewActions | ⬜ | | | | |
| 55 | ModMemberSearchbox | ⬜ | | | | |
| 56 | ModMemberSummary | ⬜ | | | | |
| 57 | ModMemberTypeSelect | ⬜ | | | | |
| 58 | ModMemberships | ⬜ | | | | |
| 59 | ModMergeButton | ⬜ | | | | |
| 60 | ModMergeMemberModal | ⬜ | | | | |
| 61 | ModMessage | ⬜ | | | | |
| 62 | ModMessageButton | ⬜ | | | | |
| 63 | ModMessageButtons | ⬜ | | | | |
| 64 | ModMessageCrosspost | ⬜ | | | | |
| 65 | ModMessageDuplicate | ⬜ | | | | |
| 66 | ModMessageEmailModal | ⬜ | | | | |
| 67 | ModMessageMicroVolunteering | ⬜ | | | | |
| 68 | ModMessageRelated | ⬜ | | | | |
| 69 | ModMessageUserInfo | ⬜ | | | | |
| 70 | ModMessageWorry | ⬜ | | | | |
| 71 | ModMicrovolunteering | ⬜ | | | | |
| 72 | ModMicrovolunteeringDetailsButton | ⬜ | | | | |
| 73 | ModMicrovolunteeringModal | ⬜ | | | | |
| 74 | ModMissingFacebook | ✅ | ✅ | N/A | ✅ | 17 tests, fixed null protection |
| 75 | ModMissingProfile | ✅ | ✅ | N/A | ✅ | 11 tests, fixed null protection |
| 76 | ModMissingRules | ✅ | ✅ | N/A | ✅ | 20 tests, fixed null protection |
| 77 | ModModeration | ⬜ | | | | |
| 78 | ModPhoto | ⬜ | | | | |
| 79 | ModPhotoModal | ⬜ | | | | |
| 80 | ModPopularPost | ✅ | ✅ | ✅ | ✅ | 17 tests |
| 81 | ModPostcodeTester | ⬜ | | | | |
| 82 | ModPostingHistory | ⬜ | | | | |
| 83 | ModPostingHistoryModal | ⬜ | | | | |
| 84 | ModRelatedMember | ⬜ | | | | |
| 85 | ModRulesModal | ⬜ | | | | |
| 86 | ModSettingShortlink | ⬜ | | | | |
| 87 | ModSettingsGroup | ⬜ | | | | |
| 88 | ModSettingsGroupFacebook | ⬜ | | | | |
| 89 | ModSettingsModConfig | ⬜ | | | | |
| 90 | ModSettingsPersonal | ⬜ | | | | |
| 91 | ModSettingsStandardMessageButton | ⬜ | | | | |
| 92 | ModSettingsStandardMessageModal | ⬜ | | | | |
| 93 | ModSettingsStandardMessageSet | ⬜ | | | | |
| 94 | ModSocialAction | ⬜ | | | | |
| 95 | ModSpamKeywordBadge | ✅ | ✅ | ✅ | ✅ | 16 tests |
| 96 | ModSpammer | ⬜ | | | | |
| 97 | ModSpammerReport | ⬜ | | | | |
| 98 | ModStatus | ⬜ | | | | |
| 99 | ModStdMessageModal | ⬜ | | | | |
| 100 | ModStoryReview | ⬜ | | | | |
| 101 | ModSupportAIAssistant | ⬜ | | | | |
| 102 | ModSupportAddGroup | ⬜ | | | | |
| 103 | ModSupportChat | ⬜ | | | | |
| 104 | ModSupportChatList | ⬜ | | | | |
| 105 | ModSupportCheckVolunteers | ⬜ | | | | |
| 106 | ModSupportContactGroup | ⬜ | | | | |
| 107 | ModSupportEmailStats | ⬜ | | | | |
| 108 | ModSupportFindGroup | ⬜ | | | | |
| 109 | ModSupportFindGroupVolunteer | ⬜ | | | | |
| 110 | ModSupportFindUser | ⬜ | | | | |
| 111 | ModSupportListGroups | ⬜ | | | | |
| 112 | ModSupportMembership | ⬜ | | | | |
| 113 | ModSupportSpamKeywords | ⬜ | | | | |
| 114 | ModSupportUser | ⬜ | | | | |
| 115 | ModSupportWorryWords | ⬜ | | | | |
| 116 | ModSupporter | ✅ | ✅ | ✅ | ✅ | 13 tests |
| 117 | ModSystemLogEntry | ⬜ | | | | |
| 118 | ModSystemLogSearch | ⬜ | | | | |
| 119 | ModSystemLogTreeNode | ⬜ | | | | |
| 120 | ModSystemLogs | ⬜ | | | | |
| 121 | ModTeamMember | ✅ | ✅ | ✅ | ✅ | 10 tests |
| 122 | ModVolunteerOpportunity | ✅ | ✅ | ✅ | ✅ | 15 tests |
| 123 | ModWorryWordBadge | ✅ | ✅ | ✅ | ✅ | 22 tests |
| 124 | ModZoomStock | ⬜ | | | | |

## Progress Summary
- Total: 124
- Complete: 33
- In Progress: 0
- Pending: 91
- Tests: 513 across 43 test files

## Session Log

### 2026-01-22 16:25 - Started TDD migration
- **Component 1**: ModAddMemberModal - ✅ Complete (9 tests)
- **Component 2**: ModAdmin - ✅ Complete (23 tests)
- **Component 3**: ModAffiliationConfirmModal - ✅ Complete (7 tests)
- **Component 4**: ModAimsModal - ✅ Complete (4 tests)
- **Component 5**: ModAlertHistory - ✅ Complete (9 tests)
- **Component 6**: ModAlertHistoryDetailsModal - ✅ Complete (7 tests)
- **Component 7**: ModAlertHistoryStatsModal - ✅ Complete (8 tests)
- **Component 8**: ModBanMemberConfirmModal - ✅ Complete (13 tests)
- **Component 9**: ModBanMemberModal - ✅ Complete (9 tests)
- **Component 10**: ModBouncing - ✅ Complete (9 tests)
- **Component 11**: ModCake - ✅ Complete (10 tests)
- **Component 12**: ModCakeModal - ✅ Complete (6 tests)
- **Component 13**: ModChatModal - ⏭️ Skipped (complex async setup)
- **Component 14**: ModChatNoteModal - ✅ Complete (11 tests)
- **Component 16**: ModChatReviewUser - ✅ Complete (19 tests)
- **Component 21**: ModCommentUser - ✅ Complete (14 tests)
- **Component 23**: ModCommunityEvent - ✅ Complete (17 tests)
- **Component 25**: ModConvertKML - ✅ Complete (11 tests)
- **Component 26**: ModDashboardDiscourseTopic - ✅ Complete (15 tests)
- **Component 28**: ModDashboardFreeglersPosting - ✅ Complete (10 tests, extends→composable)
- **Component 34**: ModDeletedOrForgotten - ✅ Complete (8 tests, auto-import)
- **Component 36**: ModGroupRule - ✅ Complete (19 tests)
- **Component 95**: ModSpamKeywordBadge - ✅ Complete (16 tests)
- **Component 123**: ModWorryWordBadge - ✅ Complete (22 tests)
- **Component 121**: ModTeamMember - ✅ Complete (10 tests)
- **Component 122**: ModVolunteerOpportunity - ✅ Complete (15 tests)
- **Component 80**: ModPopularPost - ✅ Complete (17 tests)

- **Component 116**: ModSupporter - ✅ Complete (13 tests)

### 2026-01-22 17:15 - Bug fixes for null protection
**Critical Issue Found**: Production bug in ModMissingProfile - `TypeError: myGroups is not iterable`

Root cause: When iterating over `myGroups.value` from `useMe()` composable, the value can be null/undefined before initialization. Similar bugs existed in other components.

**Bug fixes applied**:
- **Component 75**: ModMissingProfile - Fixed `for (const group of myGroups.value)` → `for (const group of myGroups.value || [])`
- **Component 74**: ModMissingFacebook - Fixed 2 instances of same bug
- **Component 76**: ModMissingRules - Fixed 3 instances of same bug

**Tests written** (all test null/undefined edge cases):
- ModMissingProfile.spec.js - 11 tests
- ModMissingFacebook.spec.js - 17 tests
- ModMissingRules.spec.js - 20 tests

**Lesson learned**: Tests must include edge cases for composable return values (null, undefined, empty arrays) - not just test that things exist.

**Total tests in this session: 251 new tests across 19 new test files**
