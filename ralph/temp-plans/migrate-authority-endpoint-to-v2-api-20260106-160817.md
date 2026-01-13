# Task: Migrate /authority endpoint to v2 API

Created: 2026-01-06 16:08:17

## Task Status

| # | Task | Status | Notes |
|---|------|--------|-------|
| 1 | Create authority.go with Single handler (GET /authority/:id) | ⬜ Pending | Get single authority details with polygon, centre, groups |
| 2 | Create authority.go Search handler (GET /authority?search=) | ⬜ Pending | Search authorities by name |
| 3 | Add area code mapping for friendly names | ⬜ Pending | CTY -> County Council, etc |
| 4 | Add routes in router/routes.go | ⬜ Pending | Two new routes for authority |
| 5 | Add tests for new endpoints | ⬜ Pending | Test Single and Search endpoints |
| 6 | Run Go tests to verify | ⬜ Pending | |

## Description

Migrate /authority endpoint to v2 API. The `/authority/:id/message` route already exists in Go. Need to add:
- GET /authority/:id - Returns authority details (name, polygon, centre, overlapping groups)
- GET /authority?search=term - Search authorities by name

## Current State Analysis

**PHP v1 API (authority.php):**
- `GET /authority?id={id}` - Get single authority with optional stats
- `GET /authority?search={term}` - Search authorities by name

**Go v2 API (already exists in authority/message.go):**
- `GET /api/authority/:id/message` - Get messages for authority (IMPLEMENTED)

**PHP Authority.php key methods:**
- `getPublic()` - Returns id, name, area_code, polygon, centre (lat/lng), overlapping groups
- `search($term, $limit)` - Returns matching authorities with friendly area_code names

## Success Criteria

- Task completed successfully
- All tests pass
- Code follows coding standards
- Both GET /authority/:id and GET /authority?search= work
