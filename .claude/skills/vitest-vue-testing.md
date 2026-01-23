# Vitest Vue Component Testing Skill

## Overview
Patterns and best practices for writing unit tests for Vue 3 components using Vitest and Vue Test Utils in the Freegle/Nuxt codebase.

## Test File Structure

```javascript
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import dayjs from 'dayjs' // If testing date-related features
import ComponentName from '~/path/to/Component.vue'

// Mock stores BEFORE vi.mock calls
const mockStoreName = {
  methodName: vi.fn(),
  propertyName: vi.fn(),
}

// Mock stores
vi.mock('~/stores/storename', () => ({
  useStoreNameStore: () => mockStoreName,
}))

// Mock composables
vi.mock('~/composables/useComposable', () => ({
  useComposable: () => ({
    // For refs used in templates, return object directly (NOT { value: {} })
    someRef: { id: 123, name: 'Test' },
    // For refs used in script, can use { value: {} }
    anotherRef: { value: [] },
  }),
}))

describe('ComponentName', () => {
  // Helper to create test data
  const createTestData = (overrides = {}) => ({
    id: 123,
    name: 'Test',
    ...overrides,
  })

  // Mount helper with common stubs
  function mountComponent(props = {}) {
    return mount(ComponentName, {
      props: {
        requiredProp: createTestData(),
        ...props,
      },
      global: {
        stubs: {
          // Bootstrap-vue components
          'b-card': { template: '<div class="card"><slot /></div>' },
          'b-button': {
            template: '<button @click="$emit(\'click\')"><slot /></button>',
            props: ['variant'],
          },
          // Child components - use stubs for auto-imported components
          ChildComponent: {
            template: '<div class="child-component" />',
            props: ['propName'],
          },
        },
        mocks: {
          // Nuxt auto-imported helpers
          datetimeshort: (val) => `formatted:${val}`,
          timeago: (val) => `${dayjs().diff(dayjs(val), 'days')} days ago`,
        },
      },
    })
  }

  beforeEach(() => {
    vi.clearAllMocks()
    // Reset mock return values
    mockStoreName.methodName.mockResolvedValue()
  })

  // Test groups...
})
```

## Key Patterns

### 1. Mocking Auto-Imported Child Components

For Nuxt auto-imported components, use `global.stubs` instead of `vi.mock()`:

```javascript
// CORRECT - Use stubs for auto-imported components
global: {
  stubs: {
    ModSpammer: {
      template: '<div class="mod-spammer" />',
      props: ['user'],
    },
  },
}

// INCORRECT - vi.mock may not work for auto-imports
vi.mock('~/modtools/components/ModSpammer', () => ({ ... }))
```

### 2. Mocking Composables with Refs

When a composable returns refs used in templates, mock the object directly:

```javascript
// CORRECT - Template unwraps refs automatically
vi.mock('~/composables/useMe', () => ({
  useMe: () => ({
    me: { id: 999, displayname: 'Mod User' }, // Direct object
    myGroups: { value: [{ id: 789 }] }, // Can use value for script-only refs
  }),
}))

// INCORRECT - Template sees { value: { id: 999 } } not { id: 999 }
vi.mock('~/composables/useMe', () => ({
  useMe: () => ({
    me: { value: { id: 999 } }, // Wrong for template usage
  }),
}))
```

### 3. Mocking useNuxtApp

For `$api` and other Nuxt app features:

```javascript
const mockApi = {
  comment: { add: vi.fn() },
  user: { fetch: vi.fn() },
}

// Add to globalThis before tests
globalThis.useNuxtApp = () => ({ $api: mockApi })
```

### 4. Testing Computed Properties

Access computed properties via `wrapper.vm`:

```javascript
it('computed returns expected value', () => {
  const wrapper = mountComponent({
    prop: createTestData({ field: 'value' }),
  })
  expect(wrapper.vm.computedName).toBe('expected')
})

// For truthy checks (when computed returns value, not boolean)
it('isLJ returns truthy when ljuserid exists', () => {
  const wrapper = mountComponent({
    member: createTestData({ ljuserid: 12345 }),
  })
  expect(wrapper.vm.isLJ).toBeTruthy() // NOT .toBe(true)
})
```

### 5. Testing Methods

```javascript
it('method calls store correctly', async () => {
  const wrapper = mountComponent()
  await wrapper.vm.methodName('arg')
  expect(mockStoreName.storeMethod).toHaveBeenCalledWith('arg')
})
```

### 6. Testing Emitted Events

```javascript
it('emits event on action', async () => {
  const wrapper = mountComponent()
  wrapper.vm.triggerMethod()
  expect(wrapper.emitted('eventname')).toBeTruthy()
})
```

### 7. Testing Conditional Rendering

```javascript
it('shows element when condition is true', () => {
  const wrapper = mountComponent({
    member: createTestData({ showField: true }),
  })
  expect(wrapper.find('.conditional-element').exists()).toBe(true)
})

it('hides element when condition is false', () => {
  const wrapper = mountComponent({
    member: createTestData({ showField: false }),
  })
  expect(wrapper.find('.conditional-element').exists()).toBe(false)
})
```

### 8. Testing Text Content

```javascript
it('displays expected text', () => {
  const wrapper = mountComponent()
  expect(wrapper.text()).toContain('Expected text')
})

it('does not display text when hidden', () => {
  const wrapper = mountComponent()
  expect(wrapper.text()).not.toContain('Hidden text')
})
```

## Test Design Philosophy: Behavior Over Assertions

**CRITICAL: Write fewer, smarter tests that focus on behavior branches, not atomic assertions.**

### The Golden Rule: Don't Test the Framework

Vue already has tests for its own functionality. Don't re-test:
- That `v-if` conditionally renders elements
- That `{{ variable }}` interpolates data
- That `v-bind` binds attributes
- That props pass to child components
- That computed properties update when dependencies change

**BAD - Testing Vue's interpolation:**
```javascript
it('displays the title', () => {
  const wrapper = mount(Item, { props: { item: { title: 'Book' } } })
  expect(wrapper.find('h2').text()).toBe('Book')  // Just testing Vue works!
})
```

**GOOD - Test behavior that YOUR code implements:**
```javascript
it('calls addToCart when button clicked by authenticated user', async () => {
  const wrapper = mount(Item, { props: { item, user: loggedInUser } })
  await wrapper.find('button').trigger('click')
  expect(mockCartStore.add).toHaveBeenCalledWith(item.id)
})
```

### What TO Test

1. **Your business logic** - Conditions YOU wrote, not Vue's rendering
2. **User interactions** - Click handlers, form submissions, event emissions
3. **Component boundaries** - That your component calls stores/APIs correctly
4. **Conditional branches in YOUR code** - Different `v-if` outcomes based on your logic

### What NOT to Test

1. **Framework behavior** - Vue's reactivity, template compilation, prop passing
2. **Third-party libraries** - That Bootstrap renders a button, that Pinia stores data
3. **Trivial code** - Getters/setters, simple data interpolation
4. **Internal state** - Test outputs, not refs/data internals
5. **Each template binding** - Use snapshot tests for overall markup if needed

### Ask Before Writing Each Test

> "Is this testing MY business logic, or am I just verifying Vue/libraries work?"

If it's the latter, skip the test.

---

Each `mount()` call is expensive. Avoid creating multiple tests that mount the same component to check trivially related things.

### BAD - Redundant tests with overlapping coverage:

```javascript
// Each test mounts the component fresh - wasteful and redundant
it('mounts successfully', () => {
  const wrapper = mountComponent()
  expect(wrapper.exists()).toBe(true)
})

it('renders button', () => {
  const wrapper = mountComponent()  // Mounting again!
  expect(wrapper.find('button').exists()).toBe(true)
})

it('button has correct text', () => {
  const wrapper = mountComponent()  // Mounting AGAIN!
  expect(wrapper.find('button').text()).toContain('Submit')
})

it('button has primary variant', () => {
  const wrapper = mountComponent()  // And AGAIN!
  expect(wrapper.find('button').classes()).toContain('primary')
})
```

### GOOD - One test per behavior scenario:

```javascript
it('renders submit button with correct styling', () => {
  const wrapper = mountComponent()
  const button = wrapper.find('button')

  expect(button.exists()).toBe(true)
  expect(button.text()).toContain('Submit')
  expect(button.classes()).toContain('primary')
  expect(button.attributes('to')).toBe('/submit')
})
```

### When to create separate tests:

1. **Different code branches** - Each `v-if` condition deserves its own test
2. **Different input states** - e.g., null vs populated data
3. **Different user interactions** - Each distinct user action
4. **Error vs success states** - These are distinct behaviors

### Example of proper test organization:

```javascript
describe('GiftAidButton', () => {
  it('shows declaration button when gift aid not completed', () => {
    mockStore.giftaid = null
    const wrapper = mountComponent()

    const button = wrapper.find('button')
    expect(button.exists()).toBe(true)
    expect(button.text()).toContain('Gift Aid declaration')
    expect(button.attributes('to')).toBe('/giftaid')
  })

  it('shows already declared message when gift aid completed', () => {
    mockStore.giftaid = { period: 'Lifetime' }
    const wrapper = mountComponent()

    expect(wrapper.find('button').exists()).toBe(false)
    expect(wrapper.text()).toContain('already made a gift aid declaration')
  })
})
```

Two tests covering two distinct behavior branches - not six tests checking trivial variations.

## Test Categories (Organized by Behavior)

1. **Happy path rendering** - Component with typical data renders correctly (one test with multiple assertions)
2. **Conditional branches** - One test per `v-if`/`v-else` branch
3. **User interactions** - One test per distinct user action
4. **Edge cases** - Null/undefined/empty states (often just 1-2 tests)
5. **Store/API interactions** - Verify correct calls are made

## Advanced Consolidation Patterns

### Use `it.each()` for Mapping/Lookup Tests

When testing computed properties that map values (e.g., source → label), use parameterized tests:

```javascript
// ❌ BAD - 7 separate tests for each mapping
it('returns API for api source', () => { ... })
it('returns User for client source', () => { ... })
it('returns Email for email source', () => { ... })
// ... 4 more nearly identical tests

// ✅ GOOD - 1 parameterized test
it.each([
  ['api', 'API'],
  ['client', 'User'],
  ['email', 'Email'],
  ['laravel-batch', 'Email'],
  ['unknown', 'unknown'],
])('returns %s label for %s source', (source, expected) => {
  const wrapper = mountComponent({ log: createLog({ source }) })
  expect(wrapper.vm.sourceLabel).toBe(expected)
})
```

### Consolidate "Returns X or Null" Patterns

Many computed properties return a value when data exists, or null when missing. Test both cases together:

```javascript
// ❌ BAD - 2 separate tests
it('returns subject from raw.message', () => {
  const wrapper = mountComponent({ log: createLog({ raw: { subject: 'Test' } }) })
  expect(wrapper.vm.messageSubject).toBe('Test')
})
it('returns null when no subject', () => {
  const wrapper = mountComponent({ log: createLog({ raw: {} }) })
  expect(wrapper.vm.messageSubject).toBeNull()
})

// ✅ GOOD - 1 test covering both cases
it('returns subject from raw or null when missing', () => {
  expect(
    mountComponent({ log: createLog({ raw: { subject: 'Test' } }) }).vm.messageSubject
  ).toBe('Test')
  expect(
    mountComponent({ log: createLog({ raw: {} }) }).vm.messageSubject
  ).toBeNull()
})
```

### Combine Similar Store Lookup Patterns

When multiple computed properties follow the same "lookup by ID" pattern, test them together:

```javascript
// ❌ BAD - 9 separate tests (3 per entity)
it('returns user from store when available', () => { ... })
it('returns undefined when user not in store', () => { ... })
it('returns null when no user_id', () => { ... })
// ... same 3 tests for byUser and displayGroup

// ✅ GOOD - 1 test checking all scenarios
it('returns entity from store, undefined when not in store, null when no ID', () => {
  mockUserStore.list = { 123: { id: 123 } }
  mockGroupStore.list = { 789: { id: 789 } }

  const wrapper = mountComponent({
    log: createLog({ user_id: 123, byuser_id: 456, group_id: 789 }),
  })
  expect(wrapper.vm.displayUser).toEqual({ id: 123 })
  expect(wrapper.vm.byUser).toBeUndefined() // 456 not in store
  expect(wrapper.vm.displayGroup).toEqual({ id: 789 })

  const noIds = mountComponent({
    log: createLog({ user_id: null, byuser_id: null, group_id: null }),
  })
  expect(noIds.vm.displayUser).toBeNull()
  expect(noIds.vm.byUser).toBeNull()
  expect(noIds.vm.displayGroup).toBeNull()
})
```

### Combine Related/Derived Computed Properties

When computed properties are closely related (e.g., one derives from another), test them together:

```javascript
// ❌ BAD - 4 tests for related properties
it('returns sentry_event_id from raw', () => { ... })
it('returns null when no sentry_event_id', () => { ... })
it('returns Sentry URL when event ID exists', () => { ... })
it('returns null URL when no event ID', () => { ... })

// ✅ GOOD - 1 test for the related pair
it('returns event ID and URL when present, null otherwise', () => {
  const withId = mountComponent({ log: createLog({ raw: { sentry_event_id: 'abc' } }) })
  expect(withId.vm.sentryEventId).toBe('abc')
  expect(withId.vm.sentryUrl).toBe('https://freegle.sentry.io/issues/?query=abc')

  const withoutId = mountComponent({ log: createLog({ raw: {} }) })
  expect(withoutId.vm.sentryEventId).toBeNull()
  expect(withoutId.vm.sentryUrl).toBeNull()
})
```

### Combine Visibility/Toggle Patterns

For show/hide conditionals, test both states in one test:

```javascript
// ❌ BAD - 2 separate tests
it('shows user column by default', () => {
  expect(mountComponent().find('.log-col-user').exists()).toBe(true)
})
it('hides user column when hideUserColumn is true', () => {
  expect(mountComponent({ hideUserColumn: true }).find('.log-col-user').exists()).toBe(false)
})

// ✅ GOOD - 1 test for the toggle behavior
it('shows user column by default, hides when hideUserColumn is true', () => {
  expect(mountComponent().find('.log-col-user').exists()).toBe(true)
  expect(mountComponent({ hideUserColumn: true }).find('.log-col-user').exists()).toBe(false)
})
```

### Use `it.each()` for Browser/Device Detection

User agent parsing tests are perfect for parameterization:

```javascript
// ❌ BAD - 4 separate browser detection tests
it('detects Chrome browser', () => { ... })
it('detects Firefox browser', () => { ... })
it('detects Safari browser', () => { ... })
it('detects Edge browser', () => { ... })

// ✅ GOOD - 1 parameterized test
it.each([
  ['Chrome/120.0.0.0', 'Chrome'],
  ['Firefox/121.0', 'Firefox'],
  ['Safari/605.1.15', 'Safari'],
  ['Edg/120.0.0.0', 'Edge'],
])('detects %s browser', (ua, browser) => {
  const wrapper = mountComponent({
    log: createLog({ raw: { user_agent: `Mozilla/5.0 ${ua}` } }),
  })
  expect(wrapper.vm.deviceInfo.browser).toBe(browser)
})
```

## Consolidation Checklist

Before writing new tests, check if they match these redundancy patterns:

- [ ] **Multiple tests for a value mapping?** → Use `it.each()`
- [ ] **"Returns X" and "Returns null when missing"?** → Combine into one test
- [ ] **Same 3-test pattern for multiple entities?** → Test all entities together
- [ ] **Testing A and testing B where B derives from A?** → Test together
- [ ] **Show/hide for the same condition?** → Test both states together
- [ ] **Similar assertions with different input values?** → Use `it.each()`

**Reduction targets from real consolidation:**
- ModGroupMap: 111 → 28 tests (75% reduction)
- ModSystemLogEntry: 119 → 57 tests (52% reduction)

## Memory-Safe Configuration

For WSL environments, use this vitest.config.mts setup:

```typescript
import os from 'node:os'

export default defineConfig({
  test: {
    pool: 'forks', // More stable than 'threads' for memory isolation
    maxWorkers: process.env.CI ? 2 : Math.max(1, Math.floor(os.cpus().length / 2)),
    fileParallelism: !process.env.VITEST_SINGLE_THREAD,
  },
})
```

## Running Tests

```bash
# Run all tests
npx vitest run

# Run specific test file
npx vitest run tests/unit/components/path/Component.spec.js

# Run with watch mode
npx vitest

# Run single test by name
npx vitest run -t "test name pattern"
```

## Tests to NEVER Write

These test patterns are explicitly banned - they test the framework, not business logic:

```javascript
// ❌ NEVER: "mounts successfully" tests
it('mounts successfully', () => {
  const wrapper = mountComponent()
  expect(wrapper.exists()).toBe(true)  // DELETE THIS TEST
})

// ❌ NEVER: "renders the component/modal" tests
it('renders the component', () => {
  const wrapper = mountComponent()
  expect(wrapper.find('div').exists()).toBe(true)  // DELETE THIS TEST
})

// ❌ NEVER: Prop type/required/default tests
it('accepts groupid prop', () => { ... })       // DELETE
it('groupid is Number type', () => { ... })     // DELETE
it('requires id prop', () => { ... })           // DELETE
it('id defaults to null', () => { ... })        // DELETE

// ❌ NEVER: Initial state tests (testing JS variable assignment)
it('starts with loading as false', () => {
  expect(wrapper.vm.loading).toBe(false)  // DELETE - just tests ref initialization
})

// ❌ NEVER: Help composable tests duplicated across components
// These test the composable, not the component - test once in composable test file
it('shows Help button when showHelp is false', () => { ... })
it('calls toggleHelp when button clicked', () => { ... })
```

**If you find yourself writing these tests, STOP.** They provide zero value and waste test execution time.

## Detecting Test Redundancy with Coverage Analysis

Run coverage with JSON output to find redundant tests:

```bash
npx vitest run --coverage --coverage.reporter=json
node scripts/analyze-coverage-redundancy.js
```

**Warning signs from coverage data:**
- Lines hit >50x indicate tests exercising the same code repeatedly
- Functions called thousands of times suggest excessive test count
- 978 statements hit >50x is a problem - aim for <100
- **Hits per test ratio >500** indicates redundant tests (calculate: total hits / test count)

**Analysis example from real codebase:**
```
ModSocialAction: 53 tests → 130,926 hits = 2,470 hits/test ← EXTREME PROBLEM
ModChatHeader:   74 tests →  77,437 hits = 1,046 hits/test ← PROBLEM
ModMessage:      94 tests →  17,356 hits =   185 hits/test ← OK
```

**Example output showing problems:**
```
components/ModSocialAction.vue line 67: hit 13,579x  ← PROBLEM!
isActioned() called 5,493x                           ← PROBLEM!
```

This means 53 tests are all mounting the component and running the same computed property.

## Mock Data Efficiency

Keep mock data minimal to avoid expensive computed property iterations:

```javascript
// ❌ BAD - 50 groups with 10 Facebook pages each = 500 iterations per mount
vi.mock('~/composables/useMe', () => ({
  useMe: () => ({
    myGroups: { value: generateFiftyGroups() },  // Too much data!
  }),
}))

// ✅ GOOD - Just enough data to test the behavior
vi.mock('~/composables/useMe', () => ({
  useMe: () => ({
    myGroups: { value: [{ id: 1, type: 'Freegle', facebook: [{ uid: '123' }] }] },
  }),
}))
```

**Rule of thumb:** If a computed property iterates over arrays, keep mock arrays to 1-3 items unless testing iteration-specific behavior.

## Debugging Test Failures

1. **Vue warnings cause test failures** - Check for missing props, unresolved components
2. **Component not rendering** - Check v-if conditions, ensure required props provided
3. **Mock not working** - Verify mock path matches import path exactly
4. **Async issues** - Use `await flushPromises()` or `await wrapper.vm.$nextTick()`

## Automated Redundancy Detection

Use these scripts to find and remove low-value tests:

```bash
# Find redundant tests
node scripts/find-redundant-tests.js

# Remove obvious low-value tests (dry-run first!)
node scripts/remove-redundant-tests.js --dry-run
node scripts/remove-redundant-tests.js

# Fix empty describe blocks left over
node scripts/fix-empty-describes.js

# Analyze coverage for redundancy
node scripts/analyze-coverage-redundancy.js
```
