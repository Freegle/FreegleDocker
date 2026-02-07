# API Test Coverage Matrix

Generated 2026-02-07. Updated as part of Phase 0B of the V1-to-V2 API migration plan.

## Summary

| Metric | Value |
|--------|-------|
| Total v1 PHP endpoints | 59 |
| Total v2 Go endpoints | ~75 |
| PHP endpoints with tests | 51 (86%) |
| PHP endpoints with no tests | 8 (14%) |
| Go endpoints with good coverage | 27 (36%) |
| Go endpoints with partial coverage | 37 (49%) |
| Go endpoints with no tests | 8 (11%) |
| API wrappers still using v1 only | 15 files |
| API wrappers using v2 only | 8 files |
| API wrappers using both v1+v2 | 19 files |
| v1 API calls in client | ~182 |
| v2 API calls in client | ~70 |

## V2 Go Endpoint Coverage

### Good Coverage (auth + error + happy path tests)

| Endpoint | Method | Handler | Test File | Key Tests |
|----------|--------|---------|-----------|-----------|
| /address | GET | address.ListForUser | address_test.go | TestAddress, TestAddressModeratorAccess |
| /address/{id} | GET | address.GetAddress | address_test.go | TestAddress, TestAddressModeratorAccess |
| /authority | GET | authority.Search | authority_test.go | 5 tests incl. empty result, limit, v2 path |
| /authority/{id} | GET | authority.Single | authority_test.go | 5 tests incl. stats, invalid ID |
| /authority/{id}/message | GET | authority.Messages | authority_test.go | 4 tests incl. zero/invalid ID |
| /chat | GET | chat.ListForUser | chat_test.go | TestListChats (auth + error) |
| /chat/{id} | GET | chat.GetChat | chat_test.go | TestListChats |
| /chat/{id}/message | GET | chat.GetChatMessages | chat_test.go | TestListChats |
| /chat/{id}/message | POST | chat.CreateChatMessage | chat_test.go | TestCreateChatMessage |
| /chat/lovejunk | POST | chat.CreateChatMessageLoveJunk | chat_test.go | TestCreateChatMessageLoveJunk, TestUserBanned |
| /clientlog | POST | clientlog.ReceiveClientLogs | clientlog_test.go | 9 tests (empty, invalid, multi, auth, v2) |
| /config/admin/spam_keywords | GET/POST/DEL | config.* | config_test.go | 9 tests with auth + validation |
| /config/admin/worry_words | GET/POST/DEL | config.* | config_test.go | 6 tests with auth + validation |
| /donations | GET | donations.GetDonations | donations_test.go | 3 tests incl. invalid group |
| /email/stats | GET | emailtracking.Stats | emailtracking_test.go | Auth + unauth tests |
| /email/user/{id} | GET | emailtracking.UserEmails | emailtracking_test.go | Auth + unauth tests |
| /e/d/p/{id} | GET | emailtracking.Pixel | emailtracking_test.go | 3 tests incl. first-open |
| /e/d/r/{id} | GET | emailtracking.Click | emailtracking_test.go | 4 tests incl. unsubscribe |
| /giftaid | GET | donations.GetGiftAid | giftaid_test.go | 6 tests (auth, periods, deleted) |
| /isochrone | GET | isochrone.ListIsochrones | isochrone_test.go | Auth + error |
| /message/{ids} | GET | message.GetMessages | message_test.go | 3 tests incl. unseen, access control |
| /message/mygroups | GET | message.Groups | message_test.go | TestMyGroups (auth + error) |
| /microvolunteering | GET | microvolunteering.GetChallenge | microvolunteering_test.go | 8 tests (auth, types, exclusions) |
| /newsfeed | GET | newsfeed.Feed | newsfeed_test.go | Auth + error |
| /notification | GET | notification.List | notifications_test.go | Auth + error |
| /notification/count | GET | notification.Count | notifications_test.go | 2 tests |
| /notification/seen | POST | notification.Seen | notifications_test.go | 3 tests (auth, invalid) |
| /notification/allseen | POST | notification.AllSeen | notifications_test.go | 2 tests |
| /amp/chat/* | GET/POST | amp.* | amp_test.go | 7 tests (CORS, auth, expired, reuse) |
| /src | POST | src.RecordSource | src_test.go | 6 tests (auth, session, resilience) |
| /volunteering | GET | volunteering.List | volunteering_test.go | Auth + error |

### Partial Coverage (some tests, missing auth or error cases)

| Endpoint | Method | Handler | Missing |
|----------|--------|---------|---------|
| /activity | GET | message.GetRecentActivity | No auth test |
| /communityevent | GET | communityevent.List | No error tests |
| /communityevent/{id} | GET | communityevent.Single | No auth test |
| /communityevent/group/{id} | GET | communityevent.ListGroup | No auth test |
| /e/d/i/{id} | GET | emailtracking.Image | Only 1 test |
| /group | GET | group.ListGroups | No auth/error tests |
| /group/{id} | GET | group.GetGroup | No auth test |
| /group/{id}/message | GET | group.GetGroupMessages | No auth/error tests |
| /illustration | GET | misc.GetIllustration | No auth test |
| /isochrone/message | GET | isochrone.Messages | No error tests |
| /job | GET | job.GetJobs | No auth/error tests |
| /job/{id} | GET | job.GetJob | No auth test |
| /job | POST | job.RecordJobClick | No auth/error tests |
| /latestmessage | GET | misc.LatestMessage | No auth/error tests |
| /location/typeahead | GET | location.Typeahead | No auth test |
| /location/latlng | GET | location.LatLng | Minimal tests |
| /location/{id} | GET | location.GetLocation | Minimal tests |
| /location/{id}/addresses | GET | location.GetLocationAddresses | Minimal tests |
| /logo | GET | logo.Get | No auth/error tests |
| /message/count | GET | isochrone.Count | No error tests |
| /message/inbounds | GET | message.Bounds | No auth test |
| /message/search/{term} | GET | message.Search | No auth test |
| /newsfeed/{id} | GET | newsfeed.Single | No auth test |
| /newsfeedcount | GET | newsfeed.Count | No error tests |
| /online | GET | misc.Online | No auth/error tests |
| /story | GET | story.List | No auth/error tests |
| /story/{id} | GET | story.Single | No auth test |
| /story/group/{id} | GET | story.Group | No auth/error tests |
| /user/{id}/message | GET | message.GetMessagesForUser | No auth test |
| /volunteering/{id} | GET | volunteering.Single | No auth test |
| /volunteering/group/{id} | GET | volunteering.ListGroup | No auth test |

### No Test Coverage

| Endpoint | Method | Handler | Priority |
|----------|--------|---------|----------|
| /config/{key} | GET | config.Get | Medium |
| /email/stats/timeseries | GET | emailtracking.TimeSeries | Low |
| /email/stats/bytype | GET | emailtracking.StatsByType | Low |
| /email/stats/clicks | GET | emailtracking.TopClickedLinks | Low |
| /user/{id}/publiclocation | GET | user.GetPublicLocation | Medium |
| /user/{id}/search | GET | user.GetSearchesForUser | Low |
| /systemlogs | GET | systemlogs.GetLogs | Low (admin only) |
| /systemlogs/counts | GET | systemlogs.GetLogCounts | Low (admin only) |

---

## V1 PHP Endpoint Coverage

### Well Tested (10+ test methods)

| Endpoint | Test File | Tests | v2 Status |
|----------|-----------|-------|-----------|
| message.php | messageAPITest.php | 61 | Not migrated |
| session.php | sessionTest.php | 29 | Not migrated |
| user.php | userAPITest.php | 23 | Partial (byemail) |
| memberships.php | membershipsAPITest.php | 22 | Not migrated |
| chatmessages.php | chatMessagesAPITest.php | 15 | Not migrated |
| messages.php | messagesTest.php | 15 | Not migrated |
| newsfeed.php | newsfeedAPITest.php | 13 | Not migrated |
| chatrooms.php | chatRoomsAPITest.php | 12 | Not migrated |

### No PHP Test Coverage

| Endpoint | Methods | Risk | v2 Status |
|----------|---------|------|-----------|
| stripecreateintent.php | POST | CRITICAL - payments | Not migrated |
| stripecreatesubscription.php | POST | CRITICAL - payments | Not migrated |
| stdmsg.php | CRUD | HIGH - moderation | Not migrated |
| notification.php | GET, POST | MEDIUM | POST migrated to v2 |
| domains.php | GET | LOW | Not migrated |
| groups.php | GET | LOW - may be unused | Not migrated |
| mentions.php | GET | LOW | Not migrated |
| usersearch.php | GET, DELETE | MEDIUM | Not migrated |

---

## Client API Wrapper Migration Status

### Fully on v2 (no v1 calls)

| Wrapper | v2 Methods |
|---------|-----------|
| AddressAPI.js | 2 |
| ConfigAPI.js | 4 |
| EmailTrackingAPI.js | 7 |
| JobAPI.js | 3 |
| LogoAPI.js | 1 |
| NotificationAPI.js | 4 |
| SystemLogsAPI.js | 4 |
| UserSearchAPI.js | 1 |

### Still v1 only (need migration)

| Wrapper | v1 Methods | Priority |
|---------|-----------|----------|
| AdminsAPI.js | 4 | Low (admin) |
| AlertAPI.js | 2 | Low |
| BanditAPI.js | 3 | Low (A/B testing) |
| CommentAPI.js | 2 | Medium (moderation) |
| DashboardAPI.js | 2 | Low (admin) |
| DomainAPI.js | 1 | Low |
| ImageAPI.js | 1 | Medium (file upload) |
| InvitationAPI.js | 1 | Low |
| MembershipsAPI.js | 10 | HIGH (core feature) |
| MergeAPI.js | 3 | Medium |
| ModConfigsAPI.js | 4 | Medium (moderation) |
| NoticeboardAPI.js | 3 | Medium |
| ShortlinksAPI.js | 2 | Low |
| SimulationAPI.js | 3 | Low |
| SocialActionsAPI.js | 5 | Medium |
| SpammersAPI.js | 2 | Medium (moderation) |
| StatusAPI.js | 1 | Low |
| TeamAPI.js | 1 | Low |
| TrystAPI.js | 3 | Low |
| VisualiseAPI.js | 1 | Low |

### Mixed v1+v2 (partially migrated)

| Wrapper | v1 Methods | v2 Methods | Notes |
|---------|-----------|-----------|-------|
| AuthorityAPI.js | 1 | 1 | |
| ChatAPI.js | 13 | 4 | Most POST still v1 |
| CommunityEventAPI.js | 2 | 3 | GETs on v2 |
| DonationsAPI.js | 2 | 1 | GET on v2 |
| GiftAidAPI.js | 3 | 1 | GET on v2 |
| GroupAPI.js | 4 | 3 | GETs on v2 |
| IsochroneAPI.js | 1 | 2 | GETs on v2 |
| LocationAPI.js | 3 | 4 | Most on v2 |
| LogsAPI.js | 1 | 1 | |
| MessageAPI.js | 20 | 7 | Most POST still v1 |
| MicroVolunteeringAPI.js | 1 | 2 | GETs on v2 |
| NewsAPI.js | 11 | 2 | Most still v1 |
| SessionAPI.js | 7 | 1 | Almost all v1 |
| StoriesAPI.js | 3 | 3 | GETs on v2 |
| UserAPI.js | 8 | 4 | GETs on v2 |
| VolunteeringAPI.js | 2 | 3 | GETs on v2 |

---

## Playwright E2E Coverage

| Test File | Tests | API Endpoints Exercised |
|-----------|-------|------------------------|
| test-homepage.spec.js | 4 | Navigation only |
| test-pages.spec.js | 9 | Navigation only |
| test-post-flow.spec.js | 3 | Message POST, Chat POST (v2) |
| test-browse.spec.js | 5 | Group join/leave, message browsing |
| test-explore.spec.js | 2 | Group join/leave |
| test-reply-flow-logged-in.spec.js | 3 | Session, Chat, Message (v1+v2) |
| test-reply-flow-new-user.spec.js | 3 | Registration, Chat, Message |
| test-reply-flow-existing-user.spec.js | 3 | Login, Chat, Message |
| test-reply-flow-social.spec.js | ~3 | Social auth, Chat |
| test-reply-flow-edge-cases.spec.js | ~3 | Error handling |
| test-reply-flow-logging.spec.js | ~3 | Chat messaging, logging |
| test-settings.spec.js | 4 | Session POST, User GET |
| test-post-validation.spec.js | ~3 | Message POST validation |
| test-marketing-consent.spec.js | ~3 | Registration, Settings |
| test-register-unsubscribe.spec.js | ~3 | Registration, Account deletion |
| test-user-ratings.spec.js | 1 | Rating endpoints, User fetch |
| test-modtools-login.spec.js | 2 | ModTools auth |
| test-ai-illustration.spec.js | 4 | AI illustration fetch |

### Playwright Gaps (endpoints with no E2E coverage)

- Address CRUD
- Isochrone operations
- Volunteering CRUD
- Community events
- Newsfeed operations
- Noticeboard operations
- All admin/moderation endpoints
- Stripe payment flows
- Merge operations
- Invitation flows

---

## Recommendations

### Immediate (Phase 0B.5-6)

1. Write Go tests for 8 untested v2 endpoints
2. Write at least 1 Playwright test for address, volunteering, and community event flows
3. Add auth tests to 30+ Go endpoints that lack them

### During Migration (per endpoint)

1. Before migrating each v1 endpoint, verify existing PHP test coverage
2. Write equivalent Go tests following TDD (test first, then implement)
3. Add Playwright E2E test for each migrated codepath
4. Switch client API wrapper from v1 to v2

### Post-Migration (Phase 6)

1. Verify zero v1 calls remain in client for migrated endpoints
2. Check Loki logs for v1 traffic to migrated endpoints
3. Run full test suite against v2-only config
