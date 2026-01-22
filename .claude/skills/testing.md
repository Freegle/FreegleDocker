---
name: testing
description: "Guidance for writing high-quality tests. Reference this skill when writing Vitest, PHPUnit, Go, or Playwright tests. Contains patterns, edge cases to test, and common mistakes to avoid."
---

# Freegle Testing Skill

This skill provides guidance for writing high-quality tests across the Freegle codebase. It covers Vitest (Vue components), PHPUnit (PHP API), Go tests, and Playwright (E2E).

## Core Principles

### 1. Tests Must Catch Real Bugs

**The null/undefined edge case lesson**: When testing code that uses composables, stores, or any data source that might not be immediately available, ALWAYS test edge cases:

```javascript
// ALWAYS include these tests for any computed that iterates over data
it('handles null gracefully', () => {
  mockData.value = null
  const wrapper = mountComponent()
  expect(wrapper.vm.computedProperty).toEqual([])
})

it('handles undefined gracefully', () => {
  mockData.value = undefined
  const wrapper = mountComponent()
  expect(wrapper.vm.computedProperty).toEqual([])
})

it('handles empty array', () => {
  mockData.value = []
  const wrapper = mountComponent()
  expect(wrapper.vm.computedProperty).toEqual([])
})
```

### 2. Test Behavior, Not Implementation

- Test what the component DOES, not HOW it does it
- If you change the implementation and tests break, the tests were too coupled

### 3. Coverage is Mandatory

- Never skip coverage collection
- Use coverage to find untested code paths
- Run `npm run test:unit:coverage` to see coverage report

---

## Vitest (Vue Component Tests)

### Location
`iznik-nuxt3/tests/unit/components/`

### Key Patterns

#### Mocking Composables
```javascript
// Mock refs must use computed() to match real behavior
const mockMyGroups = ref([])

vi.mock('~/composables/useMe', () => ({
  useMe: () => ({
    myGroups: computed(() => mockMyGroups.value),
  }),
}))
```

#### Mocking Stores
```javascript
const mockStore = {
  list: {},
  fetch: vi.fn(),
  byId: vi.fn(),
}

vi.mock('~/stores/mystore', () => ({
  useMyStore: () => mockStore,
}))
```

#### Mount Helper Pattern
```javascript
function mountComponent(props = {}) {
  return mount(MyComponent, {
    props: { ...defaultProps, ...props },
    global: {
      stubs: {
        'b-button': true,
        'b-modal': true,
        'v-icon': true,
        NuxtLink: true,
      },
      mocks: {
        // Global functions used in templates
        pluralise: (arr, count) => arr[count === 1 ? 0 : 1],
      },
    },
  })
}
```

#### Testing Computed Properties
```javascript
describe('computed property - actual logic', () => {
  it('filters correctly with real data', () => {
    mockData.value = [
      { id: 1, type: 'Freegle', role: 'Owner' },
      { id: 2, type: 'Other', role: 'Owner' },
    ]
    const wrapper = mountComponent()
    expect(wrapper.vm.filtered).toHaveLength(1)
    expect(wrapper.vm.filtered[0].id).toBe(1)
  })
})
```

#### Testing Methods
```javascript
describe('methods', () => {
  it('method modifies state correctly', async () => {
    const wrapper = mountComponent()
    expect(wrapper.vm.someState).toBe(true)
    wrapper.vm.toggle()
    expect(wrapper.vm.someState).toBe(false)
  })

  it('method calls store action', async () => {
    const wrapper = mountComponent()
    await wrapper.vm.save()
    expect(mockStore.update).toHaveBeenCalledWith({ id: 1, data: 'test' })
  })
})
```

### Common Mistakes to Avoid

1. **Not testing computed logic with real data** - Don't just check that computeds exist
2. **Forgetting null/undefined edge cases** - Composables may return null before data loads
3. **Testing implementation details** - Test behavior, not internal state
4. **Not clearing mocks** - Always use `beforeEach(() => vi.clearAllMocks())`

### Running Tests
```bash
npx vitest run                                    # All unit tests
npx vitest run tests/unit/components/MyComp.spec.js  # Single file
npx vitest run --coverage                         # With coverage
```

---

## PHPUnit (PHP API Tests)

### Location
`iznik-server/test/ut/php/include/` and `api/`

### Key Patterns

#### Base Test Class
```php
class MyTest extends IznikTestCase {
    protected function setUp(): void {
        parent::setUp();
        // Clean up test data
    }
}
```

#### Factory Methods (use these!)
```php
// Available in IznikTestCase
list($user, $id) = $this->createTestUserWithLogin('Test User', 'password');
$groupid = $this->createTestGroup();
$this->createTestUserWithMembership($id, $groupid, 'Member');
```

#### Testing API Endpoints
```php
public function testAPIEndpoint() {
    list($u, $id) = $this->createTestUserWithLogin('Test', 'pw');

    $ret = $this->call('GET', '/api/endpoint', ['param' => 'value']);

    $this->assertEquals(0, $ret['ret']);
    $this->assertArrayHasKey('data', $ret);
}
```

### Guidelines

1. **Refactor duplicate code** - If a pattern appears more than twice, extract to a method
2. **Use data providers** for parameterized tests
3. **Use strict comparisons** - `=== TRUE` not just truthy
4. **Clean up in setUp()** - Don't leave test data between tests

### Running Tests
```bash
# Full test class
php composer/vendor/phpunit/phpunit/phpunit --configuration test/ut/php/phpunit.xml --filter MyTest test/ut/php/include/MyTest.php

# Single method
php composer/vendor/phpunit/phpunit/phpunit --configuration test/ut/php/phpunit.xml --filter MyTest::testSpecific test/ut/php/include/
```

---

## Go Tests

### Location
`iznik-server-go/test/`

### Key Patterns

#### Test Structure
```go
func TestMyFeature(t *testing.T) {
    prefix := uniquePrefix("mytest")
    userID, token := CreateFullTestUser(t, prefix)

    req := httptest.NewRequest("GET", "/api/endpoint?jwt="+token, nil)
    resp, _ := getApp().Test(req)

    assert.Equal(t, 200, resp.StatusCode)

    var result MyType
    json.Unmarshal(rsp(resp), &result)
    assert.Equal(t, expected, result.Field)
}
```

#### Factory Functions
```go
// Use these from testUtils.go
CreateTestUser(t, prefix)           // Basic user
CreateFullTestUser(t, prefix)       // User with token
CreateTestUserWithEmail(t, prefix, email)
```

#### Request Testing
```go
// GET request
req := httptest.NewRequest("GET", "/api/endpoint?param=value", nil)

// POST request with JSON body
body := `{"field": "value"}`
req := httptest.NewRequest("POST", "/api/endpoint", strings.NewReader(body))
req.Header.Set("Content-Type", "application/json")
```

### Guidelines

1. **Use testify/assert** for readable assertions
2. **Unique prefixes** - Always use `uniquePrefix()` to avoid test data collision
3. **Single app instance** - Use `getApp()` from main_test.go
4. **Run with race detection** in CI: `go test -race ./...`

### Running Tests
```bash
go test ./test/...                    # All tests
go test ./test/... -run TestMyFeature # Single test
go test -race ./test/...              # With race detection
```

---

## Playwright (E2E Tests)

**Full documentation**: `iznik-nuxt3/tests/e2e/README.md`

### Location
`iznik-nuxt3/tests/e2e/`

### Key Patterns

#### Test Structure with Fixtures
```javascript
test('should do something', async ({
  page,
  testEmail,
  postMessage,
  withdrawPost,
}) => {
  // Use fixtures instead of manual setup
  await loginViaHomepage(page, email, password)

  const result = await postMessage({
    type: 'OFFER',
    item: 'Test Item',
    email: testEmail,
  })
})
```

#### NEVER Use Hardcoded Timeouts
```javascript
// WRONG - hardcoded timeout
await page.waitForTimeout(500)

// CORRECT - wait for specific condition
await element.waitFor({ state: 'visible', timeout: timeouts.ui.appearance })
```

#### NEVER Use toBeVisible()
```javascript
// WRONG
await expect(element).toBeVisible()

// CORRECT
await element.waitFor({ state: 'visible', timeout: timeouts.ui.appearance })
```

### Available Fixtures
- `testEmail` - Auto-generated unique email
- `getTestEmail(prefix)` - Custom prefix email
- `postMessage(options)` - Create OFFER/WANTED
- `withdrawPost(options)` - Withdraw post
- `setNewUserPassword(password)` - Set password during signup

### Utility Functions
```javascript
// User utilities
import { signUpViaHomepage, loginViaHomepage } from './utils/user'

// UI utilities
import { waitForModal, waitForElementWithText } from './utils/ui'

// Message utilities
import { createMessage, searchMessages } from './utils/message'
```

### Guidelines

1. **Use fixtures** for common operations
2. **Use timeout constants** from config.js
3. **Never use `.skip()`** - all tests must be runnable
4. **Generate unique emails** to avoid test conflicts

### Running Tests
```bash
npm run test                          # All E2E tests
npx playwright test tests/e2e/my.spec.js  # Single file
npx playwright test -g "test name"    # By pattern
npm run test:headed                   # See browser
npm run test:debug                    # Debug mode
```

---

## Test Quality Checklist

Before marking tests complete, verify:

- [ ] Tests include null/undefined edge cases for data sources
- [ ] Tests cover the main logic paths, not just existence checks
- [ ] Mocks are cleared between tests (`vi.clearAllMocks()`)
- [ ] No hardcoded timeouts (Playwright)
- [ ] Factory methods used instead of duplicating setup code
- [ ] Coverage report shows tested code paths
- [ ] Tests fail when the bug exists, pass when fixed (TDD verification)

---

## TDD Workflow

1. **RED**: Write test that fails (proves you understand the bug/feature)
2. **VERIFY RED**: Run test, confirm it fails for expected reason
3. **GREEN**: Write minimal code to pass
4. **VERIFY GREEN**: Run test, confirm it passes
5. **REFACTOR**: Clean up while keeping tests green

**Key insight**: If your test passes immediately when you expect it to fail, you're testing the wrong thing.

---

## Running Tests

### Local Development

**Unit tests (Vitest)** - Run directly, no Docker needed:
```bash
cd iznik-nuxt3
npx vitest run                              # All unit tests
npx vitest run --coverage                   # With coverage report
npx vitest run tests/unit/components/...    # Specific tests
```

**Integration tests** - Run through the status API (requires Docker):
```bash
# Start Docker services first
docker-compose up -d

# Then trigger tests via status API
curl -X POST http://localhost:8081/api/tests/go        # Go API tests
curl -X POST http://localhost:8081/api/tests/php       # PHPUnit tests
curl -X POST http://localhost:8081/api/tests/playwright # Playwright E2E
curl -X POST http://localhost:8081/api/tests/laravel   # Laravel tests
```

**Why use the status API?** The status API provides:
- Consistent test execution environment
- Log aggregation and artifact collection
- Integration with the status dashboard
- Same execution path as CI

### CircleCI Pipeline

Tests run in this order in CI:

1. **Unit tests (fast feedback)** - Run first, before Docker starts
   - Vitest unit tests for components
   - Fast failure if basic code is broken

2. **Integration tests (parallel)** - Run after Docker services are healthy
   - Go tests, PHPUnit tests, Laravel tests, Playwright tests
   - All run in parallel via status API
   - 48-minute watchdog timeout

### Adding New Test Types

To add a new test endpoint to the status API:
1. Create `status-nuxt/server/api/tests/[testname].post.ts`
2. Add it to the CircleCI parallel test block in `.circleci/orb/freegle-tests.yml`
3. Update the status dashboard if needed
