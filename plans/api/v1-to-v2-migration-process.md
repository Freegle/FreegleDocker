# V1 to V2 API Migration Process

## Overview
This document defines the standard process for migrating v1 API endpoints to v2, ensuring consistency, performance, and maintainability.

## Migration Process Steps

**IMPORTANT**: When migrating any v1 API endpoint used in FD, also migrate its uses in MT to maintain consistency across both applications.

### 1. Select Target Endpoint
Choose a v1 endpoint to migrate based on:
- Usage frequency (from API analysis)
- Dependencies (start with endpoints that others don't depend on)
- Complexity (start simple, build expertise)
- Check usage in BOTH FD and MT codebases

### 2. Analyze Existing v1 Implementation

#### 2.1 Locate v1 PHP Code
```bash
# Find the PHP API handler
grep -r "function GET\|function POST\|function PUT\|function DELETE" iznik-server/http/api/ | grep -i <endpoint>
```

#### 2.2 Analyze ACTUAL Client Usage
**IMPORTANT**: Only implement the functionality that FD/MT actually uses, not everything the v1 endpoint supports.

```bash
# Find how FD actually calls this endpoint
grep -r "api.*<endpoint>\|/<endpoint>" iznik-nuxt3/ --include="*.js" --include="*.vue" | grep -v node_modules

# Check what parameters FD sends
# Check what response fields FD uses
# Identify any conditional logic based on responses
```

#### 2.3 Document REQUIRED Behavior Only
- Request parameters actually sent by FD
- Response fields actually used by FD
- Database queries needed for FD functionality
- Business logic required by FD
- Error handling that FD depends on
- Permissions/authentication as used by FD

#### 2.3 Identify Test Coverage
```bash
# Find existing PHPUnit tests
find iznik-server/test -name "*.php" | xargs grep -l <endpoint>
```

### 3. Design v2 Implementation

#### 3.1 API Design Principles
- **Minimal implementation**: Only implement what FD/MT actually uses, not the full v1 functionality
- **Use proper HTTP verbs**: GET for reads, POST for creates, PUT for updates, DELETE for deletes
- **Return IDs not objects**: References should be IDs, not nested objects
- **Consistent JSON format**: Match existing v2 response patterns
- **RESTful paths**: `/api/v2/resource/{id}/action`

#### 3.2 Response Format Template
```json
// Success: HTTP 200, data at top level
{
  "id": 123,
  "field": "value",
  "related_id": 456  // ID reference, not nested object
}

// Error: HTTP 400/404/500, error message
{
  "error": "Resource not found"
}
```

### 4. Implement v2 Endpoint

#### 4.1 Create Handler Structure
```go
// handlers/<resource>.go
func (h *Handler) GetResource(c *fiber.Ctx) error {
    ctx := context.Background()

    // Parse parameters
    id := c.Params("id")

    // Use goroutines for parallel operations
    var wg sync.WaitGroup
    var resource Resource
    var relatedData RelatedData
    var err1, err2 error

    wg.Add(2)

    // Parallel data fetching
    go func() {
        defer wg.Done()
        resource, err1 = h.getResource(ctx, id)
    }()

    go func() {
        defer wg.Done()
        relatedData, err2 = h.getRelatedData(ctx, id)
    }()

    wg.Wait()

    // Error handling
    if err1 != nil || err2 != nil {
        return c.Status(500).JSON(fiber.Map{
            "error": "Failed to fetch data",
        })
    }

    // Return response - data at top level
    return c.JSON(fiber.Map{
        "id": resource.ID,
        "name": resource.Name,
        "related_id": relatedData.ID, // ID only, not object
    })
}
```

#### 4.2 Database Query Patterns

**Simple queries - use GORM:**
```go
var user User
h.db.Where("id = ?", id).First(&user)
```

**Complex/performance-critical queries - use raw SQL:**
```go
rows, err := h.db.Raw(`
    SELECT u.id, u.name, COUNT(m.id) as message_count
    FROM users u
    LEFT JOIN messages m ON m.userid = u.id
    WHERE u.id = ?
    GROUP BY u.id, u.name
`, id).Rows()
```

#### 4.3 Parallel Processing Pattern
```go
// Fetch multiple related data in parallel
func (h *Handler) getCompleteData(ctx context.Context, id string) (CompleteData, error) {
    var wg sync.WaitGroup
    var mu sync.Mutex
    result := CompleteData{}
    errors := []error{}

    // Define parallel tasks
    tasks := []func(){
        func() {
            defer wg.Done()
            if data, err := h.getUserData(ctx, id); err != nil {
                mu.Lock()
                errors = append(errors, err)
                mu.Unlock()
            } else {
                mu.Lock()
                result.User = data
                mu.Unlock()
            }
        },
        func() {
            defer wg.Done()
            if data, err := h.getMessageData(ctx, id); err != nil {
                mu.Lock()
                errors = append(errors, err)
                mu.Unlock()
            } else {
                mu.Lock()
                result.Messages = data
                mu.Unlock()
            }
        },
    }

    // Execute all tasks
    wg.Add(len(tasks))
    for _, task := range tasks {
        go task()
    }
    wg.Wait()

    if len(errors) > 0 {
        return result, errors[0]
    }
    return result, nil
}
```

### 5. Create Comprehensive Tests

#### 5.1 Go Unit Tests (>90% coverage required)
```go
// handlers/<resource>_test.go
func TestGetResource(t *testing.T) {
    tests := []struct {
        name           string
        id             string
        setupMock      func(*MockDB)
        expectedStatus int
        expectedBody   map[string]interface{}
    }{
        {
            name: "successful fetch",
            id:   "123",
            setupMock: func(m *MockDB) {
                m.On("GetResource", "123").Return(Resource{ID: 123}, nil)
            },
            expectedStatus: 200,
            expectedBody: map[string]interface{}{
                "id": 123,
            },
        },
        {
            name: "not found",
            id:   "999",
            setupMock: func(m *MockDB) {
                m.On("GetResource", "999").Return(nil, ErrNotFound)
            },
            expectedStatus: 404,
            expectedBody: map[string]interface{}{
                "error": "Resource not found",
            },
        },
        // Add edge cases, error scenarios, permission tests
    }

    for _, tt := range tests {
        t.Run(tt.name, func(t *testing.T) {
            // Test implementation
        })
    }
}
```

#### 5.2 Integration Tests
```go
func TestResourceIntegration(t *testing.T) {
    // Test with real database
    db := setupTestDB()
    defer cleanupTestDB(db)

    // Create test data
    testUser := createTestUser(db)
    testResource := createTestResource(db, testUser.ID)

    // Make API call
    resp := makeRequest("GET", "/api/v2/resource/"+testResource.ID)

    // Verify response - data at top level
    assert.Equal(t, 200, resp.StatusCode)
    assert.Equal(t, testResource.ID, resp.Body["id"])
}
```

#### 5.3 Achieving >90% Test Coverage

**MANDATORY**: All new v2 endpoints must have >90% test coverage before being merged.

To achieve >90% coverage, test these scenarios:

1. **Happy path** - Successful operation with valid data
2. **Validation errors** - Invalid/missing required fields
3. **Authentication** - Authenticated vs unauthenticated users
4. **Authorization** - User has/doesn't have permission
5. **Database errors** - What happens when DB operations fail
6. **Edge cases** - Empty strings, null values, boundary conditions
7. **Error recovery** - Partial failures in parallel operations

**Example: Complete test coverage for /src endpoint**
```go
func TestRecordSource(t *testing.T) {
    tests := []struct {
        name           string
        payload        interface{}
        expectedStatus int
    }{
        // Happy path
        {name: "Valid source", payload: src.SourceRequest{Src: "facebook-ad-123"}, expectedStatus: 204},

        // Validation errors
        {name: "Empty source", payload: src.SourceRequest{Src: ""}, expectedStatus: 400},
        {name: "Invalid JSON", payload: map[string]interface{}{"invalid": "data"}, expectedStatus: 400},
        {name: "Empty body", payload: "", expectedStatus: 400},

        // Edge cases
        {name: "Very long source", payload: src.SourceRequest{Src: strings.Repeat("a", 256)}, expectedStatus: 400},
        {name: "Special characters", payload: src.SourceRequest{Src: "test-campaign/123!@#"}, expectedStatus: 204},
    }
    // Test implementation...
}
```

**Verify coverage**:
```bash
# Run tests with coverage for specific package
docker exec freegle-apiv2 sh -c "cd test && go test -run TestRecordSource -coverprofile=/tmp/coverage.out -coverpkg=../src && go tool cover -func=/tmp/coverage.out | grep src.go"

# Expected output showing >90%:
# RecordSource    90.9%
# recordSource    60.0%
# total           76.2%  <- Must be >90%
```

**If coverage is <90%**: Add tests for uncovered error paths, typically:
- Database connection failures
- Database insert/update errors
- Validation edge cases
- Error handling branches

#### 5.4 PHPUnit Compatibility Tests
Create tests that verify v2 behavior matches v1:
```php
// test/ut/php/api/<Resource>V2Test.php
class ResourceV2Test extends IznikTestCase {
    public function testV2MatchesV1Behavior() {
        // Call v1 endpoint
        $v1Response = $this->call('GET', '/api/resource', ['id' => 123]);

        // Call v2 endpoint
        $v2Response = $this->callV2('GET', '/api/v2/resource/123');

        // Verify compatibility - v2 has data at top level
        $this->assertEquals($v1Response['id'], $v2Response['id']);
        $this->assertEquals($v1Response['name'], $v2Response['name']);
    }
}
```

### 6. Mark V1 Endpoint as Deprecated

After v2 is working, mark the v1 PHP endpoint as deprecated:

```php
// In /iznik-server/http/api/<endpoint>.php
function <endpoint>() {
    // TODO: DEPRECATED - This endpoint has been migrated to v2 Go API
    // Can be retired once all clients are using v2
    // Migrated: [date]
    // V2 endpoint: /api/v2/<endpoint>

    // ... existing code ...
}
```

### 7. Update Frontend Stores

**IMPORTANT**: Focus on FD FIRST. Only after FD is working with v2, consider whether MT can use the same v2 endpoint or needs different functionality.

#### 7.1 Update FD First
```bash
# Search FD for uses of the endpoint (EXCLUDING modtools folder)
grep -r "api.*<endpoint>\|/<endpoint>" iznik-nuxt3/ --include="*.js" --include="*.vue" | grep -v node_modules | grep -v modtools
```

#### 7.2 Then Consider MT (if endpoint is used there)
After FD is working with the minimal v2 implementation:
- Check if MT can use the same v2 endpoint
- If MT needs additional functionality, add it without breaking FD
- If MT needs completely different behavior, consider a separate endpoint

#### 7.3 Modify Pinia Store
```javascript
// stores/resource.js
export const useResourceStore = defineStore('resource', {
  actions: {
    async fetch(id) {
      try {
        // Switch to v2 API - data at top level
        const response = await api().v2.resource.get(id)
        this.resource = response
        // Handle ID references
        if (response.related_id) {
          await this.fetchRelated(response.related_id)
        }
      } catch (error) {
        console.error('Failed to fetch resource:', error)
      }
    }
  }
})
```

### 8. Migration Checklist

#### Pre-Implementation
- [ ] Analyzed v1 endpoint behavior
- [ ] Identified all database queries
- [ ] Found existing PHPUnit tests
- [ ] Documented response format
- [ ] Identified parallel processing opportunities

#### Implementation
- [ ] Created v2 handler with proper HTTP verb
- [ ] Implemented goroutines for parallel operations (with sync.WaitGroup)
- [ ] Used GORM for simple queries
- [ ] Used raw SQL for complex queries
- [ ] Returns IDs not nested objects
- [ ] Matches v2 JSON response format

#### Testing
- [ ] Go unit tests written (>90% coverage)
- [ ] **Verify coverage**: `docker exec freegle-apiv2 sh -c "cd test && go test -run Test<Endpoint> -coverprofile=/tmp/coverage.out -coverpkg=../<package> && go tool cover -func=/tmp/coverage.out | grep <package>"`
- [ ] **Coverage must be >90%** - Add tests for error paths if needed
- [ ] Integration tests written
- [ ] PHPUnit compatibility tests written
- [ ] **Tests run successfully in apiv2 container**
- [ ] Performance tested with parallel operations

#### Frontend (Both FD and MT)
- [ ] FD: Pinia store updated
- [ ] FD: ID reference handling implemented
- [ ] FD: Error handling added
- [ ] FD: Frontend tests updated
- [ ] MT: Pinia store updated (if endpoint is used)
- [ ] MT: ID reference handling implemented
- [ ] MT: Error handling added
- [ ] MT: Frontend tests updated

#### Cleanup & Documentation
- [ ] **Mark v1 PHP endpoint as deprecated with TODO comment**
- [ ] **Update migration tracking document to mark endpoint as completed**
- [ ] **Remove any intermediate migration planning files**
- [ ] API documentation updated
- [ ] Migration notes documented
- [ ] Breaking changes identified
- [ ] Add to list of endpoints ready for v1 retirement

### 9. Performance Optimization Patterns

#### 9.1 Batch Operations
```go
// Process multiple items in parallel batches
func (h *Handler) processBatch(ctx context.Context, ids []string) []Result {
    results := make([]Result, len(ids))
    var wg sync.WaitGroup

    // Process in batches of 10
    batchSize := 10
    for i := 0; i < len(ids); i += batchSize {
        end := i + batchSize
        if end > len(ids) {
            end = len(ids)
        }

        wg.Add(1)
        go func(start, end int) {
            defer wg.Done()
            for j := start; j < end; j++ {
                results[j] = h.processItem(ctx, ids[j])
            }
        }(i, end)
    }

    wg.Wait()
    return results
}
```


### 10. Serverless Considerations

#### Important: Goroutines in Lambda/Netlify Functions
- **DON'T use fire-and-forget goroutines** - They may be terminated when function execution ends
- **DO complete all database operations synchronously** within the handler
- **DO use goroutines for parallel operations** that complete before handler returns
- **Example of CORRECT usage**: Using sync.WaitGroup to wait for all goroutines

```go
// WRONG - goroutine may not complete
go recordData(data)
return c.SendStatus(200)

// CORRECT - wait for completion
var wg sync.WaitGroup
wg.Add(1)
go func() {
    defer wg.Done()
    recordData(data)
}()
wg.Wait()
return c.SendStatus(200)
```

### 11. Common Pitfalls to Avoid

1. **Don't return nested objects** - Always return IDs and let frontend fetch if needed
2. **Don't use fire-and-forget goroutines in serverless** - They may not complete
3. **Don't use GORM for complex queries** - Use raw SQL for performance
4. **Don't forget error handling** - Handle all error cases explicitly
5. **Don't skip tests** - Maintain >90% coverage requirement
6. **Don't break v1 compatibility** - Ensure frontend can handle both during transition

### 12. Example: Migrating GET /chatrooms

#### 12.1 v1 Analysis
```php
// v1: GET /api/chatrooms
// Returns: array of chat room objects with nested user data
{
    "chatrooms": [
        {
            "id": 123,
            "name": "Chat about sofa",
            "chattype": "User2User",
            "lastmsg": "2024-01-15 10:30:00",
            "user": {
                "id": 456,
                "displayname": "John D",
                "profile": {...}
            },
            "messages": [...]
        }
    ]
}
```

#### 12.2 v2 Implementation
```go
// v2: GET /api/v2/chatrooms
// chat/chatrooms.go
package chat

func ListChatRooms(c *fiber.Ctx) error {
    ctx := context.Background()
    myid := user.GetUserIDFromRequest(c)

    if myid == 0 {
        return c.Status(401).JSON(fiber.Map{
            "error": "Not logged in",
        })
    }

    var wg sync.WaitGroup
    var chatRooms []ChatRoom
    var unreadCounts map[int64]int
    var err1, err2 error

    wg.Add(2)

    // Fetch chat rooms and unread counts in parallel
    go func() {
        defer wg.Done()
        chatRooms, err1 = getChatRoomsForUser(ctx, myid)
    }()

    go func() {
        defer wg.Done()
        unreadCounts, err2 = getUnreadCounts(ctx, myid)
    }()

    wg.Wait()

    if err1 != nil {
        return c.Status(500).JSON(fiber.Map{
            "error": "Failed to fetch chat rooms",
        })
    }

    // Build response with ID references only
    result := make([]fiber.Map, len(chatRooms))
    for i, room := range chatRooms {
        result[i] = fiber.Map{
            "id": room.ID,
            "name": room.Name,
            "chattype": room.ChatType,
            "lastmsg": room.LastMsg,
            "other_user_id": room.OtherUserID, // ID only, not nested object
            "unread_count": unreadCounts[room.ID],
        }
    }

    return c.JSON(result)
}

// Helper function using raw SQL for performance
func getChatRoomsForUser(ctx context.Context, userID int64) ([]ChatRoom, error) {
    query := `
        SELECT c.id, c.name, c.chattype, c.lastmsgseen,
               CASE
                   WHEN c.user1 = ? THEN c.user2
                   ELSE c.user1
               END as other_user_id
        FROM chat_rooms c
        WHERE (c.user1 = ? OR c.user2 = ?)
        AND c.chattype = 'User2User'
        ORDER BY c.lastmsgseen DESC
        LIMIT 100
    `

    rows, err := db.Raw(query, userID, userID, userID).Rows()
    if err != nil {
        return nil, err
    }
    defer rows.Close()

    var rooms []ChatRoom
    for rows.Next() {
        var room ChatRoom
        err := rows.Scan(&room.ID, &room.Name, &room.ChatType,
                        &room.LastMsg, &room.OtherUserID)
        if err != nil {
            return nil, err
        }
        rooms = append(rooms, room)
    }

    return rooms, nil
}
```

## Next Steps

1. Start with simple, high-usage endpoints
2. Build expertise with the patterns
3. Gradually tackle more complex migrations
4. Maintain backward compatibility during transition
5. Monitor performance improvements
6. Document lessons learned

## Success Metrics

- **Coverage**: >90% test coverage for all v2 endpoints
- **Performance**: 50%+ improvement in response times
- **Reliability**: <0.1% error rate
- **Compatibility**: Zero breaking changes for frontend
- **Code Quality**: Consistent patterns across all v2 endpoints