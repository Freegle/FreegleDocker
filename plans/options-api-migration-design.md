# Options API to Script Setup Migration: TDD Design

## Executive Summary

This document outlines a TDD approach for migrating Vue components from Options API to `<script setup>`. The key insight from community research is that **well-written tests should be API-agnostic** - if you test behavior rather than implementation, the same tests work before AND after migration.

## Current State Analysis

### Component Breakdown
- **Options API components**: ~160 files (primarily in `modtools/components/`)
- **Script setup components**: ~419 files (already migrated)
- **Migration progress**: ~72% complete

### Testing Infrastructure
- **Existing**: Playwright E2E tests only
- **Missing**: Unit test framework (no vitest.config.ts)
- **Available**: `@nuxt/test-utils@3.19.0` already in devDependencies

## Research Findings

### Community Consensus
From [Vue Testing Handbook](https://lmiller1990.github.io/vue-testing-handbook/composition-api.html):

> "Testing a component built with the Composition API should be no different to testing a standard component, since we are not testing the implementation, but the output."
>
> "It should be possible to refactor any traditional Vue component to use the Composition API **without the need to change the unit tests**."

### Recommended Testing Framework
[Nuxt Testing Docs](https://nuxt.com/docs/getting-started/testing) recommend:
- **Vitest** as the test runner (Vite-native, Jest-compatible)
- **@vue/test-utils** for component mounting
- **@nuxt/test-utils** for Nuxt-specific features (auto-imports, composables)

### Key Testing Principles

1. **Test behavior, not implementation** - what it renders, not how
2. **Test props** - verify DOM changes for different prop values
3. **Test emitted events** - verify `$emit` calls with correct payloads
4. **Test user interactions** - clicks, input, form submission
5. **Don't test internal state** - avoid testing `data()` values directly

## Component Isolation Strategy

### Why Isolation Testing?

Each component should be tested **in complete isolation** from its child components. This means:

- **Child components are stubbed** (replaced with empty placeholders)
- **We verify props passed TO children** (not what children render)
- **We verify events FROM children are handled** (by emitting on stubs)
- **We do NOT render the full component tree**

### Benefits of Isolation

1. **Tests only THIS component's logic** - failures pinpoint the exact component
2. **Child component changes don't break parent tests** - no cascading failures
3. **Faster test execution** - no deep rendering
4. **Clearer test intent** - obvious what's being tested
5. **Easier mocking** - fewer dependencies to stub

### Shallow Mounting

Use `shallow: true` option to automatically stub all child components:

```typescript
const wrapper = await mountSuspended(ModMember, {
  props: defaultProps,
  shallow: true,  // All child components become stubs
})
```

Or selectively stub specific components:

```typescript
const wrapper = await mountSuspended(ModMember, {
  props: defaultProps,
  global: {
    stubs: {
      ModComments: true,
      ModSpammer: true,
      ProfileImage: true,
      // Keep some children real if needed:
      // NoticeMessage: false,
    }
  }
})
```

### Testing Child Component Props

Verify the component passes correct props to its children:

```typescript
it('passes correct props to ProfileImage', async () => {
  const wrapper = await mountSuspended(ModMember, {
    props: {
      member: {
        userid: 123,
        displayname: 'Test User',
        profile: { turl: 'https://example.com/photo.jpg' }
      }
    },
    shallow: true,
  })

  const profileImage = wrapper.findComponent({ name: 'ProfileImage' })
  expect(profileImage.exists()).toBe(true)
  expect(profileImage.props('image')).toBe('https://example.com/photo.jpg')
  expect(profileImage.props('name')).toBe('Test User')
  expect(profileImage.props('size')).toBe('sm')
})
```

### Testing Child Component Events

Verify the component handles events from children correctly:

```typescript
it('handles commentadded event from ModComments', async () => {
  const wrapper = await mountSuspended(ModMember, {
    props: defaultProps,
    shallow: true,
  })

  // Find the stubbed child component
  const modComments = wrapper.findComponent({ name: 'ModComments' })

  // Emit event from the stub
  await modComments.vm.$emit('commentadded', { id: 456, text: 'New comment' })

  // Verify parent handled it correctly
  // (e.g., called a store action, updated local state, emitted its own event)
  expect(someStore.addComment).toHaveBeenCalledWith({ id: 456, text: 'New comment' })
})
```

### Testing Conditional Child Rendering

Verify children are rendered/hidden based on conditions:

```typescript
it('shows ModSpammer when member.spammer is true', async () => {
  const wrapper = await mountSuspended(ModMember, {
    props: {
      member: { ...defaultProps.member, spammer: { id: 1 } }
    },
    shallow: true,
  })

  expect(wrapper.findComponent({ name: 'ModSpammer' }).exists()).toBe(true)
})

it('hides ModSpammer when member.spammer is falsy', async () => {
  const wrapper = await mountSuspended(ModMember, {
    props: {
      member: { ...defaultProps.member, spammer: null }
    },
    shallow: true,
  })

  expect(wrapper.findComponent({ name: 'ModSpammer' }).exists()).toBe(false)
})
```

### Testing Slots Passed to Children

```typescript
it('passes correct slot content to ConfirmModal', async () => {
  const wrapper = await mountSuspended(ModMember, {
    props: defaultProps,
    shallow: true,
  })

  // Trigger action that shows modal
  await wrapper.find('[data-test="unban-button"]').trigger('click')

  const modal = wrapper.findComponent({ name: 'ConfirmModal' })
  expect(modal.props('title')).toContain('Unban')
})
```

## Mocking All Dependencies

Components must be tested in **complete isolation**. This means mocking everything the component depends on:

### 1. Pinia Stores

```typescript
// Create mock store with spies
const mockUserStore = {
  user: { id: 1, name: 'Test' },
  fetchMT: vi.fn().mockResolvedValue({ id: 1 }),
  updateUser: vi.fn(),
}

const mockMemberStore = {
  members: [],
  fetchMembers: vi.fn(),
}

vi.mock('~/stores/user', () => ({
  useUserStore: () => mockUserStore
}))

vi.mock('~/stores/member', () => ({
  useMemberStore: () => mockMemberStore
}))

// In tests, verify store methods were called correctly
it('fetches user on mount', async () => {
  await mountComponent()
  expect(mockUserStore.fetchMT).toHaveBeenCalledWith({ id: 123, info: true })
})
```

### 2. Composables

```typescript
// Mock composables
vi.mock('~/composables/useMe', () => ({
  useMe: () => ({
    me: { id: 1, displayname: 'Test Mod' },
    myid: 1,
    myGroups: [{ id: 100, name: 'Test Group' }],
    myGroup: vi.fn((id) => ({ id, name: 'Test Group' })),
  })
}))

vi.mock('~/composables/useModMe', () => ({
  useModMe: () => ({
    myModGroups: [{ id: 100 }],
    myModGroup: vi.fn(),
  })
}))
```

### 3. API Calls ($fetch / useFetch)

```typescript
// Mock $fetch globally
const mockFetch = vi.fn()

vi.mock('#app', () => ({
  useNuxtApp: () => ({
    $fetch: mockFetch,
    $sentryCaptureException: vi.fn(),
  })
}))

// Or mock useFetch
vi.mock('#imports', async () => {
  const actual = await vi.importActual('#imports')
  return {
    ...actual,
    useFetch: vi.fn().mockReturnValue({
      data: ref({ users: [] }),
      pending: ref(false),
      error: ref(null),
    }),
  }
})

// In tests
it('calls API with correct parameters', async () => {
  mockFetch.mockResolvedValue({ success: true })

  const wrapper = await mountComponent()
  await wrapper.find('[data-test="save"]').trigger('click')

  expect(mockFetch).toHaveBeenCalledWith('/api/user/update', {
    method: 'POST',
    body: expect.objectContaining({ userid: 123 })
  })
})
```

### 4. Nuxt Utilities

```typescript
// Mock useRuntimeConfig
vi.mock('#imports', async () => {
  const actual = await vi.importActual('#imports')
  return {
    ...actual,
    useRuntimeConfig: () => ({
      public: {
        apiBase: 'https://api.test.com',
        siteUrl: 'https://test.com',
      }
    }),
    useRoute: () => ({
      params: { id: '123' },
      query: {},
    }),
    useRouter: () => ({
      push: vi.fn(),
      replace: vi.fn(),
    }),
    navigateTo: vi.fn(),
  }
})
```

### 5. Browser APIs

```typescript
// Mock window/document APIs
beforeEach(() => {
  // Mock clipboard
  Object.assign(navigator, {
    clipboard: {
      writeText: vi.fn().mockResolvedValue(undefined),
    },
  })

  // Mock localStorage
  const localStorageMock = {
    getItem: vi.fn(),
    setItem: vi.fn(),
    clear: vi.fn(),
  }
  Object.defineProperty(window, 'localStorage', { value: localStorageMock })

  // Mock window.open
  vi.spyOn(window, 'open').mockImplementation(() => null)
})
```

### 6. Date/Time

```typescript
// Mock Date for consistent tests
beforeEach(() => {
  vi.useFakeTimers()
  vi.setSystemTime(new Date('2024-01-15T10:00:00Z'))
})

afterEach(() => {
  vi.useRealTimers()
})
```

### 7. Third-Party Libraries

```typescript
// Mock dayjs
vi.mock('dayjs', () => ({
  default: vi.fn(() => ({
    format: vi.fn().mockReturnValue('15 Jan 2024'),
    fromNow: vi.fn().mockReturnValue('2 days ago'),
  }))
}))

// Mock external components (e.g., from bootstrap-vue-next)
const mockBButton = {
  template: '<button><slot /></button>',
  props: ['variant', 'disabled', 'size'],
}

// In mount options
global: {
  stubs: {
    'b-button': mockBButton,
    'b-modal': true,
  }
}
```

## Avoiding Boilerplate

A major risk with isolated unit tests is repetitive setup code. Use these patterns to keep tests DRY:

### 1. Global Test Setup File

```typescript
// tests/unit/setup.ts
import { vi, beforeEach, afterEach } from 'vitest'
import { config } from '@vue/test-utils'

// ============================================
// GLOBAL STUBS (applied to all tests)
// ============================================
config.global.stubs = {
  // Stub all bootstrap-vue-next components
  'b-button': { template: '<button :disabled="disabled"><slot /></button>', props: ['variant', 'disabled', 'size'] },
  'b-card': { template: '<div class="card"><slot /><slot name="header" /><slot name="footer" /></div>' },
  'b-card-header': { template: '<div class="card-header"><slot /></div>' },
  'b-card-body': { template: '<div class="card-body"><slot /></div>' },
  'b-card-footer': { template: '<div class="card-footer"><slot /></div>' },
  'b-form-input': { template: '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />', props: ['modelValue'] },
  'b-form-select': { template: '<select :value="modelValue"><slot /></select>', props: ['modelValue', 'options'] },
  'b-modal': true,
  'b-alert': { template: '<div class="alert"><slot /></div>', props: ['variant', 'show'] },
  'b-badge': { template: '<span class="badge"><slot /></span>', props: ['variant'] },
  'b-row': { template: '<div class="row"><slot /></div>' },
  'b-col': { template: '<div class="col"><slot /></div>' },
  'b-input-group': { template: '<div class="input-group"><slot /></div>' },

  // Stub FontAwesome
  'v-icon': { template: '<i :class="icon"></i>', props: ['icon'] },

  // Stub NuxtLink
  'NuxtLink': { template: '<a :href="to"><slot /></a>', props: ['to'] },
}

// ============================================
// GLOBAL MOCKS (applied to all tests)
// ============================================
vi.mock('#imports', async () => {
  const actual = await vi.importActual('#imports')
  return {
    ...actual,
    useRuntimeConfig: () => ({ public: { apiBase: 'https://test.api' } }),
    useRoute: () => ({ params: {}, query: {} }),
    useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
    navigateTo: vi.fn(),
  }
})

// ============================================
// RESET BETWEEN TESTS
// ============================================
beforeEach(() => {
  vi.clearAllMocks()
})

afterEach(() => {
  vi.useRealTimers()
})
```

### 2. Factory Functions for Test Data

```typescript
// tests/unit/factories/member.ts
export function createMember(overrides = {}) {
  return {
    userid: 123,
    displayname: 'Test User',
    email: 'test@example.com',
    profile: { turl: 'https://example.com/photo.jpg' },
    joined: '2024-01-01T00:00:00Z',
    emails: [],
    banned: false,
    bandate: null,
    spammer: null,
    ...overrides,
  }
}

export function createBannedMember(overrides = {}) {
  return createMember({
    banned: true,
    bandate: '2024-01-15T00:00:00Z',
    ...overrides,
  })
}

export function createSpammerMember(overrides = {}) {
  return createMember({
    spammer: { id: 1, reason: 'Known spammer' },
    ...overrides,
  })
}
```

```typescript
// tests/unit/factories/message.ts
export function createMessage(overrides = {}) {
  return {
    id: 1,
    subject: 'OFFER: Test Item',
    type: 'Offer',
    fromuser: createMember(),
    groups: [{ groupid: 100, collection: 'Approved' }],
    ...overrides,
  }
}
```

```typescript
// tests/unit/factories/index.ts
export * from './member'
export * from './message'
export * from './group'
// etc.
```

### 3. Store Mock Factory

```typescript
// tests/unit/mocks/stores.ts
import { vi } from 'vitest'

export function createMockUserStore(overrides = {}) {
  return {
    user: null,
    fetchMT: vi.fn().mockResolvedValue({}),
    updateUser: vi.fn().mockResolvedValue({}),
    ...overrides,
  }
}

export function createMockMemberStore(overrides = {}) {
  return {
    members: [],
    fetchMembers: vi.fn().mockResolvedValue([]),
    updateMember: vi.fn().mockResolvedValue({}),
    ...overrides,
  }
}

export function createMockChatStore(overrides = {}) {
  return {
    chats: [],
    fetchChat: vi.fn().mockResolvedValue({}),
    ...overrides,
  }
}

// Helper to apply store mocks
export function mockStores(stores: Record<string, any>) {
  Object.entries(stores).forEach(([path, mockStore]) => {
    vi.mock(`~/stores/${path}`, () => ({
      [`use${path.charAt(0).toUpperCase() + path.slice(1)}Store`]: () => mockStore
    }))
  })
}
```

### 4. Component-Specific Test Helper

Each component can have a helper that encapsulates its specific setup:

```typescript
// tests/unit/components/modtools/ModMember.spec.ts
import { createMember, createBannedMember } from '../../factories'
import { createMockUserStore, createMockMemberStore } from '../../mocks/stores'

// Component-specific setup
const mockUserStore = createMockUserStore()
const mockMemberStore = createMockMemberStore()

vi.mock('~/stores/user', () => ({ useUserStore: () => mockUserStore }))
vi.mock('~/stores/member', () => ({ useMemberStore: () => mockMemberStore }))

// Component-specific mount helper
function mountModMember(memberOverrides = {}, mountOptions = {}) {
  return mountSuspended(ModMember, {
    props: {
      member: createMember(memberOverrides),
    },
    shallow: true,
    ...mountOptions,
  })
}

// Now tests are concise:
describe('ModMember', () => {
  it('displays member email', async () => {
    const wrapper = await mountModMember({ email: 'custom@test.com' })
    expect(wrapper.text()).toContain('custom@test.com')
  })

  it('shows banned notice for banned members', async () => {
    const wrapper = await mountModMember({ banned: true, bandate: '2024-01-01' })
    expect(wrapper.findComponent({ name: 'NoticeMessage' }).exists()).toBe(true)
  })

  it('passes member to ModComments', async () => {
    const wrapper = await mountModMember({ userid: 456 })
    expect(wrapper.findComponent({ name: 'ModComments' }).props('user').userid).toBe(456)
  })
})
```

### 5. Shared Assertion Helpers

```typescript
// tests/unit/helpers/assertions.ts
import { VueWrapper } from '@vue/test-utils'

export function expectChildComponent(wrapper: VueWrapper, name: string) {
  return {
    toExist() {
      expect(wrapper.findComponent({ name }).exists()).toBe(true)
    },
    notToExist() {
      expect(wrapper.findComponent({ name }).exists()).toBe(false)
    },
    toHaveProps(props: Record<string, any>) {
      const component = wrapper.findComponent({ name })
      expect(component.exists()).toBe(true)
      Object.entries(props).forEach(([key, value]) => {
        expect(component.props(key)).toEqual(value)
      })
    },
  }
}

// Usage in tests:
it('passes correct props to ProfileImage', async () => {
  const wrapper = await mountModMember({ displayname: 'Alice' })
  expectChildComponent(wrapper, 'ProfileImage').toHaveProps({
    name: 'Alice',
    size: 'sm',
  })
})
```

### 6. Vitest Configuration for Setup File

```typescript
// vitest.config.ts
import { defineVitestConfig } from '@nuxt/test-utils/config'

export default defineVitestConfig({
  test: {
    environment: 'nuxt',
    include: ['tests/unit/**/*.spec.ts'],
    setupFiles: ['tests/unit/setup.ts'],  // Auto-runs before all tests
    globals: true,
  },
})
```

### Directory Structure with Helpers

```
tests/
└── unit/
    ├── setup.ts                    # Global setup (auto-loaded)
    ├── factories/                  # Test data factories
    │   ├── index.ts
    │   ├── member.ts
    │   ├── message.ts
    │   └── group.ts
    ├── mocks/                      # Reusable mock factories
    │   ├── stores.ts
    │   ├── composables.ts
    │   └── api.ts
    ├── helpers/                    # Test helper functions
    │   ├── assertions.ts
    │   └── mount.ts
    └── components/                 # Actual test files
        └── modtools/
            ├── ModMember.spec.ts
            └── ModMessage.spec.ts
```

### Before/After Comparison

**BAD - Repetitive boilerplate:**
```typescript
// Every test file repeats this
const mockUserStore = { user: null, fetchMT: vi.fn() }
vi.mock('~/stores/user', () => ({ useUserStore: () => mockUserStore }))

describe('ModMember', () => {
  it('test 1', async () => {
    const wrapper = await mountSuspended(ModMember, {
      props: {
        member: {
          userid: 123,
          displayname: 'Test',
          email: 'test@test.com',
          profile: { turl: '...' },
          // 20 more fields...
        }
      },
      shallow: true,
    })
    // ...
  })
})
```

**GOOD - DRY with helpers:**
```typescript
// Uses shared factories and mocks from setup
describe('ModMember', () => {
  it('test 1', async () => {
    const wrapper = await mountModMember({ displayname: 'Alice' })
    // ...
  })
})
```

## Meaningful Test Selectors

### The Problem with CSS Class Selectors

**BAD - Fragile selectors based on styling classes:**
```typescript
// These break when someone refactors CSS or changes Bootstrap classes
await wrapper.find('.btn-primary').trigger('click')
await wrapper.find('.mt-2.d-flex > button').trigger('click')
await wrapper.find('.card-body .text-danger').text()
```

These selectors are:
- Tied to visual styling, not purpose
- Brittle - CSS refactoring breaks tests
- Hard to understand - what does `.mt-2.d-flex > button` mean?

### Solution: data-test Attributes

Add semantic `data-test` attributes to components as you write tests:

**GOOD - Semantic selectors:**
```typescript
await wrapper.find('[data-test="save-button"]').trigger('click')
await wrapper.find('[data-test="email-toggle"]').trigger('click')
await wrapper.find('[data-test="banned-notice"]').text()
```

### The TDD Process for Selectors

When writing a test that needs to find an element:

1. **Write the test first** with a meaningful `data-test` selector
2. **Run the test** - it will fail because the selector doesn't exist
3. **Add the `data-test` attribute** to the component template
4. **Run the test again** - it should now find the element

```typescript
// Step 1: Write test with meaningful selector
it('saves changes when save button clicked', async () => {
  const wrapper = await mountModMember()
  await wrapper.find('[data-test="save-member-button"]').trigger('click')
  expect(mockMemberStore.updateMember).toHaveBeenCalled()
})

// Step 2: Test fails - element not found

// Step 3: Add data-test to component template
// <b-button data-test="save-member-button" @click="save">Save</b-button>

// Step 4: Test passes
```

### Naming Conventions for data-test

Use consistent, descriptive names:

```
[action]-[subject]-[element-type]

Examples:
- data-test="save-member-button"
- data-test="toggle-emails-button"
- data-test="member-email-display"
- data-test="banned-notice"
- data-test="spammer-warning"
- data-test="profile-image"
- data-test="chat-input"
- data-test="send-message-button"
```

### Component Template Example

```vue
<template>
  <div data-test="mod-member">
    <div data-test="member-header">
      <span data-test="member-email">{{ email }}</span>
      <ProfileImage data-test="member-profile-image" :image="member.profile.turl" />
    </div>

    <NoticeMessage v-if="banned" data-test="banned-notice">
      This freegler is banned.
    </NoticeMessage>

    <b-button data-test="toggle-emails-button" @click="showEmails = !showEmails">
      {{ showEmails ? 'Hide' : 'Show' }} emails
    </b-button>

    <div v-if="showEmails" data-test="emails-list">
      <div v-for="e in emails" :key="e.id" data-test="email-item">
        {{ e.email }}
      </div>
    </div>

    <b-button data-test="save-member-button" @click="save">Save</b-button>
    <b-button data-test="view-chat-button" @click="showChat">Chat</b-button>
  </div>
</template>
```

### Test Helper for Selectors

```typescript
// tests/unit/helpers/selectors.ts
export function testId(id: string) {
  return `[data-test="${id}"]`
}

// Usage in tests:
import { testId } from '../../helpers/selectors'

await wrapper.find(testId('save-member-button')).trigger('click')
await wrapper.find(testId('banned-notice')).text()
```

### Stripping data-test in Production (Optional)

If you want to remove `data-test` attributes from production builds:

```typescript
// nuxt.config.ts
export default defineNuxtConfig({
  vite: {
    vue: {
      template: {
        compilerOptions: {
          nodeTransforms: process.env.NODE_ENV === 'production'
            ? [(node) => {
                if (node.type === 1 /* ELEMENT */) {
                  node.props = node.props.filter(
                    (prop) => prop.type !== 6 || prop.name !== 'data-test'
                  )
                }
              }]
            : [],
        },
      },
    },
  },
})
```

## Fast Visual Regression Testing

### The Goal

Catch visual issues like:
- Buttons overlapping
- Elements not visible
- Missing content
- Broken layouts

**Without** the overhead of Playwright/browser rendering (which is slow).

### Approach: HTML Structure Snapshots

Vitest snapshots capture the rendered HTML structure. Changes to structure are caught automatically:

```typescript
it('renders correct structure', async () => {
  const wrapper = await mountModMember()
  expect(wrapper.html()).toMatchSnapshot()
})
```

**Pros:**
- Super fast (no browser)
- Catches structural changes (missing elements, wrong nesting)
- Easy to update when intentional changes made

**Cons:**
- Doesn't catch CSS-only issues (overlapping from absolute positioning)

### Approach: Targeted Structure Assertions

More maintainable than full snapshots - assert specific structural requirements:

```typescript
describe('visual structure', () => {
  it('has all required sections visible', async () => {
    const wrapper = await mountModMember()

    // Verify key sections exist
    expect(wrapper.find(testId('member-header')).exists()).toBe(true)
    expect(wrapper.find(testId('member-actions')).exists()).toBe(true)
    expect(wrapper.find(testId('member-footer')).exists()).toBe(true)
  })

  it('buttons are not duplicated', async () => {
    const wrapper = await mountModMember()

    // Should have exactly one save button
    expect(wrapper.findAll(testId('save-member-button'))).toHaveLength(1)
    expect(wrapper.findAll(testId('view-chat-button'))).toHaveLength(1)
  })

  it('conditional content hidden when appropriate', async () => {
    const wrapper = await mountModMember({ banned: false })

    expect(wrapper.find(testId('banned-notice')).exists()).toBe(false)
  })
})
```

### Approach: Class-Based Visibility Checks

Check that visibility-related classes are correctly applied:

```typescript
// tests/unit/helpers/visibility.ts
export function isVisuallyHidden(element: DOMWrapper<Element>) {
  const classes = element.classes()
  const style = element.attributes('style') || ''

  return (
    classes.includes('d-none') ||
    classes.includes('invisible') ||
    classes.includes('visually-hidden') ||
    style.includes('display: none') ||
    style.includes('visibility: hidden')
  )
}

export function assertVisible(wrapper: VueWrapper, selector: string) {
  const element = wrapper.find(selector)
  expect(element.exists()).toBe(true)
  expect(isVisuallyHidden(element)).toBe(false)
}

export function assertHidden(wrapper: VueWrapper, selector: string) {
  const element = wrapper.find(selector)
  if (element.exists()) {
    expect(isVisuallyHidden(element)).toBe(true)
  }
}
```

```typescript
// Usage in tests
it('shows email list when toggled', async () => {
  const wrapper = await mountModMember()

  // Initially hidden
  assertHidden(wrapper, testId('emails-list'))

  // Click toggle
  await wrapper.find(testId('toggle-emails-button')).trigger('click')

  // Now visible
  assertVisible(wrapper, testId('emails-list'))
})
```

### Approach: Layout Integrity Checks

For components with specific layout requirements:

```typescript
// tests/unit/helpers/layout.ts
export function getChildOrder(wrapper: VueWrapper, parentSelector: string) {
  const parent = wrapper.find(parentSelector)
  return parent.findAll('[data-test]').map(el => el.attributes('data-test'))
}

export function assertElementOrder(wrapper: VueWrapper, parentSelector: string, expectedOrder: string[]) {
  const actual = getChildOrder(wrapper, parentSelector)
  expectedOrder.forEach((expected, index) => {
    expect(actual[index]).toBe(expected)
  })
}
```

```typescript
// Usage - ensure buttons appear in correct order
it('action buttons in correct order', async () => {
  const wrapper = await mountModMember()

  assertElementOrder(wrapper, testId('member-actions'), [
    'save-member-button',
    'view-chat-button',
    'view-logs-button',
  ])
})
```

### Approach: Component Snapshot Sections

Snapshot specific sections rather than entire component:

```typescript
describe('visual snapshots', () => {
  it('header section structure', async () => {
    const wrapper = await mountModMember()
    expect(wrapper.find(testId('member-header')).html()).toMatchSnapshot()
  })

  it('actions section structure', async () => {
    const wrapper = await mountModMember()
    expect(wrapper.find(testId('member-actions')).html()).toMatchSnapshot()
  })

  it('banned state structure', async () => {
    const wrapper = await mountModMember({ banned: true, bandate: '2024-01-01' })
    expect(wrapper.find(testId('banned-notice')).html()).toMatchSnapshot()
  })
})
```

### Summary: Fast Visual Testing Strategy

| Technique | What It Catches | Speed |
|-----------|-----------------|-------|
| HTML snapshots | Structural changes, missing elements | ⚡ Fast |
| Targeted existence checks | Missing/extra elements | ⚡ Fast |
| Class visibility checks | d-none, invisible classes | ⚡ Fast |
| Element count assertions | Duplicate buttons/elements | ⚡ Fast |
| Element order assertions | Misordered layout | ⚡ Fast |
| Section snapshots | Focused structural changes | ⚡ Fast |

**What we CAN'T catch without a browser:**
- CSS overlap from `position: absolute`
- Actual pixel dimensions
- Font rendering issues
- Z-index stacking problems

For those, rely on Playwright E2E tests which already exist.

### Verifying Mock Interactions

The key benefit of isolation is verifying HOW the component interacts with dependencies:

```typescript
describe('API interactions', () => {
  it('calls fetchMT with correct params on mount', async () => {
    await mountComponent({ member: { userid: 456 } })

    expect(mockUserStore.fetchMT).toHaveBeenCalledTimes(1)
    expect(mockUserStore.fetchMT).toHaveBeenCalledWith({
      id: 456,
      info: true,
    })
  })

  it('calls updateUser when save clicked', async () => {
    const wrapper = await mountComponent()

    await wrapper.find('[data-test="save"]').trigger('click')

    expect(mockUserStore.updateUser).toHaveBeenCalledWith(
      expect.objectContaining({ userid: 123 })
    )
  })

  it('does not call API when validation fails', async () => {
    const wrapper = await mountComponent()

    // Leave required field empty
    await wrapper.find('[data-test="save"]').trigger('click')

    expect(mockUserStore.updateUser).not.toHaveBeenCalled()
  })
})
```

## Proposed Architecture

### File Structure
```
iznik-nuxt3/
├── vitest.config.ts              # Vitest configuration
├── tests/
│   ├── e2e/                      # Existing Playwright tests
│   └── unit/                     # New unit tests
│       ├── setup.ts              # Test setup/helpers
│       ├── components/
│       │   ├── modtools/         # Mirror modtools/components structure
│       │   │   ├── ModMember.spec.ts
│       │   │   └── ModMessage.spec.ts
│       │   └── ...
│       └── __snapshots__/        # Snapshot files (auto-generated)
```

### Test File Template
```typescript
// tests/unit/components/modtools/ModMember.spec.ts
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import ModMember from '~/modtools/components/ModMember.vue'

// Mock stores and composables as needed
const mockUserStore = {
  fetchMT: vi.fn()
}

vi.mock('~/stores/user', () => ({
  useUserStore: () => mockUserStore
}))

describe('ModMember', () => {
  const defaultProps = {
    member: {
      userid: 123,
      displayname: 'Test User',
      email: 'test@example.com',
      profile: { turl: 'https://example.com/photo.jpg' },
      // ... minimal required props
    }
  }

  // Helper to mount with isolation (shallow)
  const mountComponent = (props = defaultProps, options = {}) => {
    return mountSuspended(ModMember, {
      props,
      shallow: true,  // IMPORTANT: Isolate from child components
      ...options,
    })
  }

  beforeEach(() => {
    vi.clearAllMocks()
  })

  describe('rendering', () => {
    it('displays member email in own markup', async () => {
      const wrapper = await mountComponent()
      // Test text rendered by THIS component, not children
      expect(wrapper.text()).toContain('test@example.com')
    })

    it('shows banned notice when member is banned', async () => {
      const wrapper = await mountComponent({
        ...defaultProps,
        member: { ...defaultProps.member, bandate: '2024-01-01' }
      })
      // NoticeMessage is stubbed, but we can check it exists with right props
      const notice = wrapper.findComponent({ name: 'NoticeMessage' })
      expect(notice.exists()).toBe(true)
    })
  })

  describe('child component props', () => {
    it('passes correct props to ProfileImage', async () => {
      const wrapper = await mountComponent()

      const profileImage = wrapper.findComponent({ name: 'ProfileImage' })
      expect(profileImage.exists()).toBe(true)
      expect(profileImage.props('image')).toBe('https://example.com/photo.jpg')
      expect(profileImage.props('name')).toBe('Test User')
    })

    it('passes member to ModComments', async () => {
      const wrapper = await mountComponent()

      const modComments = wrapper.findComponent({ name: 'ModComments' })
      expect(modComments.props('user')).toEqual(defaultProps.member)
    })

    it('conditionally renders ModSpammer when spammer data exists', async () => {
      const wrapper = await mountComponent({
        ...defaultProps,
        member: { ...defaultProps.member, spammer: { id: 1 } }
      })

      expect(wrapper.findComponent({ name: 'ModSpammer' }).exists()).toBe(true)
    })

    it('does not render ModSpammer when no spammer data', async () => {
      const wrapper = await mountComponent()

      expect(wrapper.findComponent({ name: 'ModSpammer' }).exists()).toBe(false)
    })
  })

  describe('child component events', () => {
    it('handles settings change from SettingsGroup', async () => {
      const wrapper = await mountComponent()

      const settingsGroup = wrapper.findComponent({ name: 'SettingsGroup' })
      await settingsGroup.vm.$emit('update:emailfrequency', 'daily')

      // Verify parent handled the event (e.g., called store action)
      // expect(mockMemberStore.updateSettings).toHaveBeenCalled()
    })
  })

  describe('user interactions', () => {
    it('toggles email visibility on button click', async () => {
      const wrapper = await mountComponent({
        ...defaultProps,
        member: { ...defaultProps.member, emails: [{ email: 'a@b.com' }] }
      })

      // Find and click the toggle button
      const toggleBtn = wrapper.find('button') // Use more specific selector
      await toggleBtn.trigger('click')

      // Verify state change affected rendering
      expect(wrapper.text()).toContain('a@b.com')
    })
  })

  describe('emitted events', () => {
    it('emits expected event when action completed', async () => {
      const wrapper = await mountComponent()

      // Trigger some action
      await wrapper.find('[data-test="some-action"]').trigger('click')

      // Verify this component emitted an event
      expect(wrapper.emitted('actioncomplete')).toBeTruthy()
      expect(wrapper.emitted('actioncomplete')[0]).toEqual([expectedPayload])
    })
  })
})
```

## Migration Workflow (Per Component)

### Phase 0: Analyze Real Component Usage (CRITICAL)

Before writing tests, search the codebase to understand how the component is actually used. This reveals critical test cases that might not be obvious from reading the component in isolation.

**Steps:**

1. **Search for all usages of the component:**
   ```bash
   grep -rn "<ComponentName" --include="*.vue" .
   grep -rn "ComponentName" --include="*.ts" .
   ```

2. **Document usage patterns:**
   - What props are commonly passed?
   - What prop combinations occur together?
   - What events are listened to (`@eventname`)?
   - Are slots used? What content is passed?
   - Are refs used to access component methods?

3. **Identify edge cases from real usage:**
   - Null/undefined prop values passed?
   - Dynamic props that change at runtime?
   - Conditional rendering contexts?
   - Error handling scenarios?

4. **Create a usage summary:**
   ```markdown
   ## ComponentName Usage Analysis

   ### Locations Used
   - `path/to/file1.vue:42` - [context description]
   - `path/to/file2.vue:108` - [context description]

   ### Common Prop Patterns
   - `:propA="value"` - always required
   - `:propB="optionalValue"` - sometimes omitted
   - `:propC` combined with `:propD` in 3 places

   ### Events Listened To
   - `@eventX` in 2 places - handled by [description]

   ### Critical Test Cases (from real usage)
   - [ ] Test case derived from usage in file1.vue
   - [ ] Test case for edge case found in file2.vue
   ```

**Why This Matters:**
- Components may have props that are declared but never used
- Some prop combinations may be critical but not obvious
- Real usage reveals integration patterns that need testing
- Prevents writing tests that don't reflect actual behavior

### Phase 1: Test First (Red)
1. Create `ComponentName.spec.ts` in corresponding `tests/unit/` path
2. Review the Phase 0 usage analysis to prioritize test cases
3. Analyze the component to identify all testable behaviors:
   - **Props**: List all props and their effects on rendering
   - **Emits**: List all emitted events and their payloads
   - **Slots**: Test slot content rendering
   - **User interactions**: Buttons, inputs, toggles
   - **Conditional rendering**: v-if branches
   - **Computed values**: Test their effects on DOM
4. Write tests for each behavior, prioritizing patterns found in Phase 0
5. Run tests - they should pass with current Options API code

### Phase 2: Verify (Green)
1. Run tests against the existing Options API component
2. All tests must pass - if not, fix the tests
3. This validates the test suite captures actual behavior

### Phase 3: Migrate (Refactor)
1. Convert the component to `<script setup>`:
   - `props` → `defineProps()`
   - `emits` → `defineEmits()`
   - `data()` → `ref()` / `reactive()`
   - `computed` → `computed()`
   - `methods` → regular functions
   - `mounted/created` → `onMounted()` / inline
   - `watch` → `watch()` / `watchEffect()`
   - Store setup → keep in top level
   - `$refs` → `useTemplateRef()` or `ref()`
2. Run tests after each small change
3. Tests should continue passing

### Phase 4: Cleanup
1. Run `eslint --fix` on the migrated component
2. Remove any unused imports
3. Final test run to confirm

## Test Categories Checklist

For each component, ensure tests cover:

### Rendering Tests (This Component's Own Markup)
- [ ] Default state renders correctly
- [ ] All conditional branches (`v-if`, `v-else`) tested
- [ ] Dynamic classes applied correctly
- [ ] Text content rendered by THIS component (not children)

### Props Tests (Incoming)
- [ ] Required props accepted
- [ ] Optional props have correct defaults
- [ ] Props affect THIS component's behavior/rendering

### Events Tests (Outgoing from This Component)
- [ ] All `$emit` calls tested with `wrapper.emitted()`
- [ ] Event payloads are correct
- [ ] Events emitted at the right times

### Interaction Tests (User Actions on This Component)
- [ ] Button clicks trigger expected behavior
- [ ] Form inputs work (v-model on elements THIS component owns)
- [ ] Toggles/checkboxes change local state
- [ ] Keyboard events handled

### Child Component Props (Verify Props Passed DOWN)
- [ ] Each child component receives correct props
- [ ] Conditional child rendering (`v-if` on children)
- [ ] Dynamic props update correctly
- [ ] Slots passed to children contain expected content

### Child Component Events (Verify Events FROM Children Handled)
- [ ] Each `@event` handler on children tested
- [ ] Event payloads processed correctly
- [ ] Parent state/behavior changes appropriately

### Store Interactions (Verify Calls to Mocked Stores)
- [ ] Store actions called with correct arguments
- [ ] Store getters/state used correctly
- [ ] Calls happen at correct lifecycle points (mount, click, etc.)

### API/Composable Interactions (Verify Calls to Mocked APIs)
- [ ] API calls made with correct parameters
- [ ] Loading states handled
- [ ] Error states handled
- [ ] Success responses processed correctly

### Router Interactions (If Applicable)
- [ ] Navigation triggered at right times
- [ ] Correct routes/params passed

## Configuration Files

### vitest.config.ts
```typescript
import { defineVitestConfig } from '@nuxt/test-utils/config'

export default defineVitestConfig({
  test: {
    environment: 'nuxt',
    include: ['tests/unit/**/*.spec.ts'],
    coverage: {
      provider: 'v8',
      reporter: ['text', 'html'],
      include: ['components/**/*.vue', 'modtools/components/**/*.vue'],
    },
    globals: true,
  },
})
```

### package.json additions
```json
{
  "scripts": {
    "test:unit": "vitest",
    "test:unit:ui": "vitest --ui",
    "test:unit:coverage": "vitest --coverage"
  },
  "devDependencies": {
    "@vitest/ui": "^2.0.0",
    "@vitest/coverage-v8": "^2.0.0",
    "happy-dom": "^15.0.0"
  }
}
```

## Component Complexity Tiers

### Tier 1: Simple (Start Here)
Components with minimal state and few interactions:
- `ModClipboard.vue` - simple copy functionality
- `DiffPart.vue` - pure display component
- `ModPhoto.vue` - image display
- `ScrollToTop.vue` - single button

### Tier 2: Medium
Components with some state and interactions:
- `ModMemberButton.vue` - button with state
- `ModGroupSelect.vue` - select dropdown
- `ModRole.vue` - role selector

### Tier 3: Complex
Large components with many features:
- `ModMember.vue` - member display/edit
- `ModMessage.vue` - message display/edit
- `ModChatReview.vue` - chat review interface

## Migration Priorities

Suggested order based on:
1. Component isolation (fewer dependencies first)
2. Reuse frequency (shared components first)
3. Complexity (simple to complex)

### Batch 1: Foundational Components
1. `ModClipboard.vue`
2. `DiffPart.vue`
3. `ModPhoto.vue`
4. `ModPhotoModal.vue`

### Batch 2: Buttons and Controls
1. `ModMemberButton.vue`
2. `ModMessageButton.vue`
3. `ModGroupSelect.vue`
4. `ModMemberTypeSelect.vue`

### Batch 3: Display Components
1. `ModMemberSummary.vue`
2. `ModMessageDuplicate.vue`
3. `ModMessageCrosspost.vue`

### Batch 4: Complex Components
1. `ModMember.vue`
2. `ModMessage.vue`
3. `ModChatReview.vue`

## Known Challenges

### 1. Nuxt Auto-Imports
Components using auto-imported composables need the Nuxt test environment.

**Solution**: Use `mountSuspended` from `@nuxt/test-utils/runtime` instead of `mount` from `@vue/test-utils`.

### 2. Store Mocking
Many components depend on Pinia stores.

**Solution**: Mock stores in test setup or use `mockNuxtImport`:
```typescript
import { mockNuxtImport } from '@nuxt/test-utils/runtime'

mockNuxtImport('useUserStore', () => ({
  fetchMT: vi.fn()
}))
```

### 3. Template Refs in Script Setup
Options API uses `this.$refs.xxx`, script setup uses `const xxxRef = ref()`.

**Solution**: This is a safe refactor if tests don't directly access refs (they shouldn't).

### 4. setData() Limitation
`wrapper.setData()` doesn't work with Composition API.

**Solution**: Test through user interactions instead of manipulating internal state.

## Validation Criteria

A migration is complete when:
1. All unit tests pass
2. ESLint passes with no errors
3. TypeScript compilation succeeds (if applicable)
4. Existing Playwright E2E tests pass
5. Manual smoke test in browser

## Estimated Effort

| Tier | Components | Est. Time/Component | Total |
|------|------------|---------------------|-------|
| Simple | ~40 | 30 min | 20 hrs |
| Medium | ~80 | 1-2 hrs | 120 hrs |
| Complex | ~40 | 3-4 hrs | 140 hrs |
| **Total** | ~160 | - | **280 hrs** |

This is a rough estimate. Actual time will vary based on:
- Component complexity
- Existing test coverage
- Store/composable dependencies
- Team familiarity with Composition API

## Alignment with Nuxt Official Patterns

This section documents findings from reviewing the [Nuxt test-utils examples](https://github.com/nuxt/test-utils/tree/main/examples/app-vitest-full/tests/nuxt) and official documentation, with adjustments to our approach.

### Official Nuxt Patterns We're Adopting

#### 1. Use `mockNuxtImport` for Composables (Not vi.mock)

The official Nuxt way to mock auto-imported composables:

```typescript
// ✅ CORRECT - Nuxt pattern
import { mockNuxtImport } from '@nuxt/test-utils/runtime'

mockNuxtImport('useRuntimeConfig', () => {
  return () => ({
    public: { apiBase: 'https://test.api' }
  })
})

// For dynamic mocks per test, use vi.hoisted:
const { useStorageMock } = vi.hoisted(() => ({
  useStorageMock: vi.fn().mockReturnValue({ value: 'mocked' })
}))

mockNuxtImport('useStorage', () => useStorageMock)

// In test:
useStorageMock.mockReturnValue({ value: 'different' })
```

#### 2. Use `mockComponent` for Child Components

Official way to mock child components:

```typescript
import { mockComponent, mountSuspended } from '@nuxt/test-utils/runtime'

// Mock before mounting
mockComponent('ProfileImage', {
  template: '<div data-test="profile-image-stub">{{ $attrs.name }}</div>',
  props: ['image', 'name', 'size'],
})

// Or with setup function:
mockComponent('ModComments', async () => {
  const { h } = await import('vue')
  return {
    props: ['user', 'expandComments'],
    setup(props, { emit }) {
      return () => h('div', {
        'data-test': 'mod-comments-stub',
        onClick: () => emit('commentadded', { id: 1 })
      })
    }
  }
})
```

#### 3. Use `registerEndpoint` for API Mocking

```typescript
import { registerEndpoint } from '@nuxt/test-utils/runtime'

registerEndpoint('/api/user/:id', {
  method: 'GET',
  handler: (event) => ({
    id: event.context.params.id,
    name: 'Test User'
  })
})

registerEndpoint('/api/member/update', {
  method: 'POST',
  handler: () => ({ success: true })
})
```

#### 4. Prefer `toMatchInlineSnapshot()` for HTML Assertions

Nuxt examples consistently use inline snapshots:

```typescript
it('renders member header', async () => {
  const wrapper = await mountSuspended(ModMember, { props })
  expect(wrapper.find(testId('member-header')).html()).toMatchInlineSnapshot(`
    "<div data-test="member-header">
      <span>Test User</span>
    </div>"
  `)
})
```

#### 5. File Naming Convention

Use `.nuxt.spec.ts` extension for tests needing Nuxt runtime:

```
tests/
└── unit/
    ├── utils/
    │   └── helpers.spec.ts         # Pure unit tests (node env)
    └── components/
        └── modtools/
            └── ModMember.nuxt.spec.ts  # Needs Nuxt env
```

### Adjustments from Our Original Design

#### Update: Vitest Configuration

Use projects-based config for environment separation:

```typescript
// vitest.config.ts
import { defineConfig } from 'vitest/config'
import { defineVitestProject } from '@nuxt/test-utils/config'

export default defineConfig({
  test: {
    projects: [
      // Pure unit tests (utilities, helpers)
      {
        test: {
          name: 'unit',
          include: ['tests/unit/**/*.spec.ts'],
          exclude: ['tests/unit/**/*.nuxt.spec.ts'],
          environment: 'node',
        },
      },
      // Component tests needing Nuxt runtime
      await defineVitestProject({
        test: {
          name: 'nuxt',
          include: ['tests/unit/**/*.nuxt.spec.ts'],
          environment: 'nuxt',
          environmentOptions: {
            nuxt: {
              mock: {
                intersectionObserver: true,
                indexedDB: true,
              }
            }
          }
        },
      }),
    ],
  },
})
```

#### Update: Global Test Setup

```typescript
// tests/unit/setup.ts
import { vi, beforeEach } from 'vitest'
import { mockNuxtImport, mockComponent } from '@nuxt/test-utils/runtime'

// ============================================
// MOCK NUXT IMPORTS (use mockNuxtImport, not vi.mock)
// ============================================
const { useRuntimeConfigMock } = vi.hoisted(() => ({
  useRuntimeConfigMock: vi.fn().mockReturnValue({
    public: { apiBase: 'https://test.api' }
  })
}))

mockNuxtImport('useRuntimeConfig', () => useRuntimeConfigMock)
mockNuxtImport('navigateTo', () => vi.fn())

// ============================================
// MOCK COMMON CHILD COMPONENTS
// ============================================
mockComponent('ProfileImage', {
  template: '<div data-test="profile-image-stub" />',
  props: ['image', 'name', 'size'],
})

mockComponent('NoticeMessage', {
  template: '<div data-test="notice-message-stub"><slot /></div>',
  props: ['variant'],
})

// Reset between tests
beforeEach(() => {
  vi.clearAllMocks()
})
```

#### Update: Store Mocking Pattern

For Pinia stores with Nuxt, prefer `mockNuxtImport`:

```typescript
// Using vi.hoisted for dynamic control
const { mockUserStore } = vi.hoisted(() => ({
  mockUserStore: {
    user: null,
    fetchMT: vi.fn().mockResolvedValue({}),
    updateUser: vi.fn().mockResolvedValue({}),
  }
}))

mockNuxtImport('useUserStore', () => () => mockUserStore)

// In tests:
it('fetches user on mount', async () => {
  await mountSuspended(ModMember, { props: defaultProps })
  expect(mockUserStore.fetchMT).toHaveBeenCalledWith({ id: 123, info: true })
})
```

### Patterns Nuxt Doesn't Use That We Can Skip

1. **Deep vi.mock for #imports** - Use `mockNuxtImport` instead
2. **Manual global stubs config** - Use `mockComponent` for specific components
3. **Complex composable mocking** - `mockNuxtImport` handles hoisting automatically

### TypeScript Benefit: Prop Mismatch Detection

A key benefit of migrating to script setup with typed `defineProps<{...}>()` is that TypeScript will catch when a parent passes props that a child doesn't declare:

```typescript
// ChildComponent.vue
const props = defineProps<{
  member: Member
  showActions?: boolean
}>()
```

```vue
<!-- ParentComponent.vue - TypeScript will error -->
<ChildComponent
  :member="member"
  :extraProp="value"  <!-- ❌ TS2353: 'extraProp' does not exist -->
/>
```

No extra test infrastructure needed for this - proper TypeScript types on migrated components provide compile-time detection of prop mismatches.

### Known Limitations

1. **shallow:true had issues** - Fixed in [PR #632](https://github.com/nuxt/test-utils/issues/525), but `mockComponent` gives more control
2. **Options API data/computed** - [Issue #961](https://github.com/nuxt/test-utils/issues/961) reported rendering issues with Options API - migration to script setup avoids this
3. **Page component mocking** - [Issue #666](https://github.com/nuxt/test-utils/issues/666) notes limitations with `mockNuxtImport` in page components

## Implementation Learnings (Updated 2026-01-22)

This section documents practical learnings from the first migration (DiffPart.vue).

### Key Finding: Simple happy-dom Environment Works Better

The full Nuxt test environment (`@nuxt/test-utils` with `environment: 'nuxt'`) had significant compatibility issues with the complex `nuxt.config.ts` in this project:

- **"entry is not a function" errors**: Version mismatches between nuxt and @nuxt/test-utils
- **"window is not defined" errors**: Client plugins loading before happy-dom setup
- **process.exit() interference**: The nuxt.config.ts `close` hook was killing vitest

**Solution**: For simple components without Nuxt-specific features (auto-imports, composables, etc.), use a simpler setup:

```typescript
// vitest.config.mts
import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath } from 'node:url'

const rootDir = fileURLToPath(new URL('./', import.meta.url))

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '~': rootDir,
      '@': rootDir,
    },
  },
  test: {
    include: ['tests/unit/**/*.spec.{js,ts}'],
    setupFiles: ['tests/unit/setup.ts'],
    globals: true,
    environment: 'happy-dom',
    testTimeout: 30000,
    coverage: {
      provider: 'v8',
      reporter: ['text', 'html'],
      include: ['components/**/*.vue', 'modtools/components/**/*.vue'],
    },
  },
})
```

### Explicit Vue Imports Required

When not using the full Nuxt environment, auto-imports don't work. Components must explicitly import Vue APIs:

```vue
<script setup>
// ❌ Won't work without Nuxt environment
const diffClass = computed(() => { ... })

// ✅ Works in simple happy-dom environment
import { computed } from 'vue'
const diffClass = computed(() => { ... })
</script>
```

### Multi-Root Component Testing

Vue Test Utils wraps multi-root components (e.g., comment + element) in a `<div>`. Use `.find()` to locate specific elements:

```typescript
// Component has: <!-- eslint-disable --> + <span>
// wrapper.element is a DIV wrapper

// ❌ This gets the wrapper div
expect(wrapper.element.tagName).toBe('SPAN')

// ✅ This finds the actual span inside
expect(wrapper.find('span').element.tagName).toBe('SPAN')
```

### ESLint Configuration Limitation

The project's ESLint config uses the `espree` parser which doesn't support TypeScript syntax. Use plain JavaScript for:

- Test files (`.spec.js` not `.spec.ts`)
- Migrated components (`<script setup>` not `<script setup lang="ts">`)

### nuxt.config.ts Fix Required

The `close` hook was killing vitest. Added a check to skip in test mode:

```typescript
close: (nuxt) => {
  // Skip in test mode to avoid killing vitest
  if (!nuxt.options._prepare && config.NODE_ENV !== 'test') {
    process.exit()
  }
},
```

### Test Setup File

```typescript
// tests/unit/setup.ts
import { vi, beforeEach, afterEach } from 'vitest'
import { config } from '@vue/test-utils'

config.global.stubs = {
  'b-button': { template: '<button :disabled="disabled"><slot /></button>', props: ['variant', 'disabled', 'size'] },
  'b-card': { template: '<div class="card"><slot /><slot name="header" /><slot name="footer" /></div>' },
  'v-icon': { template: '<i :class="icon"></i>', props: ['icon'] },
  NuxtLink: { template: '<a :href="to"><slot /></a>', props: ['to'] },
}

beforeEach(() => { vi.clearAllMocks() })
afterEach(() => { vi.useRealTimers() })
```

### Working Test Pattern (DiffPart.vue)

```javascript
// tests/unit/components/modtools/DiffPart.spec.js
import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import DiffPart from '~/modtools/components/DiffPart.vue'

describe('DiffPart', () => {
  function mountDiffPart(partOverrides = {}) {
    const defaultPart = {
      value: 'test text',
      added: false,
      removed: false,
      ...partOverrides,
    }
    return mount(DiffPart, {
      props: { part: defaultPart },
    })
  }

  function getSpan(wrapper) {
    return wrapper.find('span')
  }

  it('renders the part value text', () => {
    const wrapper = mountDiffPart({ value: 'Hello World' })
    expect(getSpan(wrapper).text()).toBe('Hello World')
  })
})
```

### Revised Test Strategy

Based on these learnings, the strategy is:

1. **Tier 1 (Simple) components**: Use `@vue/test-utils` + `happy-dom` directly
2. **Tier 2-3 (Complex) components**: May need full Nuxt environment if they use:
   - Nuxt composables (useAsyncData, useFetch, etc.)
   - Auto-imported stores
   - Nuxt-specific features

For components that need Nuxt features, consider either:
- Refactoring to accept data as props (more testable)
- Using the full `@nuxt/test-utils` setup with careful configuration

## References

- [Vue Test Utils Documentation](https://test-utils.vuejs.org/)
- [Nuxt Testing Guide](https://nuxt.com/docs/getting-started/testing)
- [Nuxt test-utils GitHub Examples](https://github.com/nuxt/test-utils/tree/main/examples/app-vitest-full/tests/nuxt)
- [Vue Testing Handbook - Composition API](https://lmiller1990.github.io/vue-testing-handbook/composition-api.html)
- [Vue Migration Guide](https://markaicode.com/vue-js-composition-api-migration-guide/)
- [Options to Composition Cheat Sheet](https://tomaszs2.medium.com/%EF%B8%8F-vue-options-api-to-composition-api-migration-cheat-sheet-2b9dc7a0fc93)
