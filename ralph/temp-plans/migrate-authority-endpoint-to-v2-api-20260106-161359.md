# Task: Migrate /authority endpoint to v2 API

Created: 2026-01-06 16:13:59

## Task Status

| # | Task | Status | Notes |
|---|------|--------|-------|
| 1 | Analyse v1 PHP implementation | ✅ Complete | GET by ID, search, stats param |
| 2 | Review existing v2 Go implementation | ✅ Complete | Already has Single, Search, Messages |
| 3 | Add stats query parameter support | ✅ Complete | Added stats.go with GetStatsByAuthority |
| 4 | Update tests for stats functionality | ✅ Complete | Added tests, fixed URL encoding issue |
| 5 | Verify API parity with v1 | ✅ Complete | All authority tests pass (16 tests) |

## Analysis

### v1 PHP Implementation (`iznik-server/http/api/authority.php`)

The v1 endpoint supports:
1. **GET by ID**: Returns authority public attributes with optional stats
   - `?id=123` - get authority by ID
   - `?id=123&stats=true&start=30 days ago&end=today` - with statistics
2. **Search**: `?search=London` - returns matching authorities

### v2 Go Implementation (Current State)

Already implemented in `iznik-server-go/authority/`:
- ✅ `GET /authority/:id` - Single authority with polygon, centre, groups
- ✅ `GET /authority?search=...` - Search by name
- ✅ `GET /authority/:id/message` - Messages within authority area
- ❌ **Missing**: `stats` query parameter support on single authority endpoint

### Gap Analysis

The only missing feature is the `stats` parameter on the single authority endpoint which calls `Stats::getByAuthority()` in PHP. This requires:
1. Adding stats retrieval logic to Go
2. Adding `stats`, `start`, `end` query parameters to Single handler

## Success Criteria

- Task completed successfully
- All tests pass
- Code follows coding standards
