import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, h, Suspense } from 'vue'
import ReplyJobInterstitial from '~/components/ReplyJobInterstitial.vue'
import {
  replyJobInterstitialRequest,
  shownRecently,
} from '~/composables/useReplyJobInterstitial'

const jobs = [1, 2, 3, 4, 5].map((id) => ({ id, title: 'Job ' + id }))

const mockJobStore = {
  available: jobs,
  fetch: vi.fn().mockResolvedValue(jobs),
}
const mockAuth = { user: { id: 1, lat: 55.9, lng: -3.2 } }
const mockAction = vi.fn()
const modal = vi.hoisted(() => ({ show: vi.fn(), hide: vi.fn() }))

// The page:finish hooks, so a test can finish a navigation.
let pageFinishHooks = []
const mockNuxtApp = {
  hook: (name, fn) => {
    if (name === 'page:finish') pageFinishHooks.push(fn)
    return () => {
      pageFinishHooks = pageFinishHooks.filter((f) => f !== fn)
    }
  },
}

vi.mock('~/stores/job', () => ({ useJobStore: () => mockJobStore }))
vi.mock('~/stores/auth', () => ({ useAuthStore: () => mockAuth }))
vi.mock('~/composables/useClientLog', () => ({
  action: (...args) => mockAction(...args),
}))
vi.mock('~/composables/useFlowTelemetry', () => ({
  FlowName: { REPLY: 'reply' },
  flowArm: () => 'b',
}))
vi.mock('#imports', async () => {
  const actual = await vi.importActual('#imports')
  return { ...actual, useNuxtApp: () => mockNuxtApp }
})
vi.mock('~/composables/useOurModal', async () => {
  const { ref } = await vi.importActual('vue')
  return {
    useOurModal: () => ({
      modal: ref(null),
      show: modal.show,
      hide: modal.hide,
    }),
  }
})
vi.mock('vue', async () => {
  const actual = await vi.importActual('vue')
  return {
    ...actual,
    defineAsyncComponent: () => ({
      props: ['id', 'summary', 'context', 'position', 'listLength'],
      template: '<div class="job-one" :data-id="id" :data-context="context" />',
    }),
  }
})

// A request as the reply pane makes it, from the page the member is on now.
function request(expectNavigation) {
  return { expectNavigation, from: window.location.href, at: Date.now() }
}

// Finish a navigation: the URL changes, then the next page finishes rendering.
function navigate(path) {
  window.history.pushState({}, '', path)
  pageFinishHooks.slice().forEach((fn) => fn())
}

function createWrapper() {
  const TestWrapper = defineComponent({
    setup() {
      return () =>
        h(Suspense, null, {
          default: () => h(ReplyJobInterstitial),
          fallback: () => h('div', 'Loading...'),
        })
    },
  })

  return mount(TestWrapper, {
    global: {
      stubs: {
        'b-modal': {
          template: '<div class="b-modal"><slot name="default" /></div>',
          props: [
            'size',
            'hideHeader',
            'hideFooter',
            'noStacking',
            'modalClass',
          ],
        },
        'b-button': {
          template:
            '<button class="b-button" @click="$emit(\'click\')"><slot /></button>',
          emits: ['click'],
        },
        'v-icon': { template: '<span />', props: ['icon'] },
      },
    },
  })
}

describe('ReplyJobInterstitial', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    localStorage.clear()
    pageFinishHooks = []
    window.history.pushState({}, '', '/message/1')
    replyJobInterstitialRequest.value = null
    mockJobStore.available = jobs
    mockAuth.user = { id: 1, lat: 55.9, lng: -3.2 }
    vi.clearAllMocks()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('shows up to three local adverts, the fundraising line and a way to carry on', async () => {
    const wrapper = createWrapper()
    await flushPromises()

    const ads = wrapper.findAll('.job-one')
    expect(ads.map((a) => a.attributes('data-id'))).toEqual(['1', '2', '3'])
    expect(ads[0].attributes('data-context')).toBe('reply_interstitial')
    expect(wrapper.text()).toContain('Your reply has been sent')
    expect(wrapper.text()).toContain('clicking it raises money for Freegle')
    expect(wrapper.find('.continue').text()).toBe('Continue')
    // Nothing tells the member they must click an advert first.
    expect(wrapper.text()).not.toMatch(/must|have to|before you/i)
  })

  it('opens once the move to the chat has finished, and records it', async () => {
    createWrapper()
    await flushPromises()

    replyJobInterstitialRequest.value = request(true)
    await flushPromises()
    expect(modal.show).not.toHaveBeenCalled()

    navigate('/chats/1')
    await flushPromises()
    expect(mockJobStore.fetch).toHaveBeenCalledWith(55.9, -3.2)

    expect(modal.show).toHaveBeenCalledTimes(1)
    expect(shownRecently()).toBe(true)
    expect(mockAction).toHaveBeenCalledWith('reply_job_interstitial_shown', {
      job_count: 3,
    })
    expect(replyJobInterstitialRequest.value).toBe(null)
  })

  it('opens straight away when the reply leaves the member where they are', async () => {
    createWrapper()
    await flushPromises()

    replyJobInterstitialRequest.value = request(false)
    await flushPromises()

    expect(modal.show).toHaveBeenCalledTimes(1)
  })

  it('does not open if the move to the chat never finishes', async () => {
    createWrapper()
    await flushPromises()

    replyJobInterstitialRequest.value = request(true)
    await flushPromises()
    vi.advanceTimersByTime(10001)
    await flushPromises()

    expect(modal.show).not.toHaveBeenCalled()
    expect(shownRecently()).toBe(false)
    expect(pageFinishHooks).toEqual([])
    expect(replyJobInterstitialRequest.value).toBe(null)
  })

  it('is shown by the copy in the new layout when the move to the chat changes layout', async () => {
    // /message and /chats use different layouts, so the copy the reply was sent under goes away
    // during the move and LayoutCommon mounts a new one on the chat page.
    const first = createWrapper()
    await flushPromises()
    replyJobInterstitialRequest.value = request(true)
    await flushPromises()

    first.unmount()
    window.history.pushState({}, '', '/chats/1')
    expect(replyJobInterstitialRequest.value).not.toBe(null)

    createWrapper()
    await flushPromises()

    expect(modal.show).toHaveBeenCalledTimes(1)
    expect(replyJobInterstitialRequest.value).toBe(null)
    expect(pageFinishHooks).toEqual([])
  })

  it('drops a request that is too old to belong to this page', async () => {
    replyJobInterstitialRequest.value = {
      ...request(true),
      at: Date.now() - 20000,
    }
    createWrapper()
    await flushPromises()

    expect(modal.show).not.toHaveBeenCalled()
    expect(replyJobInterstitialRequest.value).toBe(null)
  })

  it('shows nothing, and records nothing, when there are no adverts for the member', async () => {
    mockJobStore.available = []
    createWrapper()
    await flushPromises()

    replyJobInterstitialRequest.value = request(false)
    await flushPromises()

    expect(modal.show).not.toHaveBeenCalled()
    expect(shownRecently()).toBe(false)
  })

  it('does not fetch without a location to fetch for', async () => {
    mockAuth.user = { id: 1 }
    mockJobStore.available = []
    createWrapper()
    await flushPromises()

    replyJobInterstitialRequest.value = request(false)
    await flushPromises()

    expect(mockJobStore.fetch).not.toHaveBeenCalled()
    expect(modal.show).not.toHaveBeenCalled()
  })

  it('closes on Continue', async () => {
    const wrapper = createWrapper()
    await flushPromises()

    await wrapper.find('.continue').trigger('click')

    expect(modal.hide).toHaveBeenCalled()
  })
})
