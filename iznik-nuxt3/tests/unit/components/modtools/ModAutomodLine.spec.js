import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

const mockIsAutomodGroup = vi.fn()
vi.mock('@/modtools/composables/useAutoapproveTrial', () => ({
  useAutomod: () => ({ isAutomodGroup: mockIsAutomodGroup }),
}))

const mockPostAutomodFeedback = vi.fn()
vi.mock('@/stores/message', () => ({
  useMessageStore: () => ({ postAutomodFeedback: mockPostAutomodFeedback }),
}))

const mockModalShow = vi.fn()
vi.mock('@/modtools/components/ModAutomodModal.vue', () => ({
  default: {
    name: 'ModAutomodModal',
    props: ['msgid', 'groupid', 'automod'],
    template: '<div class="automod-modal-stub" />',
    methods: { show: mockModalShow },
  },
}))

// Imported after the mocks above so the component picks up the stubbed modal.
const { default: ModAutomodLine } =
  await import('~/modtools/components/ModAutomodLine.vue')

const stubs = {
  'b-button': {
    template: '<button @click="$emit(\'click\')"><slot /></button>',
  },
  'v-icon': true,
}

function messageWithAutomod(groupid, automod, approvedby = null, extra = {}) {
  return {
    id: 42,
    groups: [{ groupid, automod, approvedby, ...extra }],
  }
}

function mountLine(props) {
  return mount(ModAutomodLine, {
    props: {
      message: null,
      groupid: 7,
      pending: false,
      ...props,
    },
    global: { stubs },
  })
}

describe('ModAutomodLine', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockIsAutomodGroup.mockReturnValue(true)
    mockPostAutomodFeedback.mockResolvedValue({ success: true })
  })

  it('renders nothing for a community not in the automod trial', () => {
    mockIsAutomodGroup.mockReturnValue(false)
    const message = messageWithAutomod(7, {
      verdict: 'approve',
      mode: 'approve',
    })
    const wrapper = mountLine({ message, groupid: 7 })
    expect(wrapper.find('.automod-line').exists()).toBe(false)
  })

  it('renders nothing when the group has no automod data', () => {
    const message = messageWithAutomod(7, null)
    const wrapper = mountLine({ message, groupid: 7 })
    expect(wrapper.find('.automod-line').exists()).toBe(false)
  })

  it('renders nothing when there is no message at all', () => {
    const wrapper = mountLine({ message: null, groupid: 7 })
    expect(wrapper.find('.automod-line').exists()).toBe(false)
  })

  describe('decided view', () => {
    it('shows auto-approved when the verdict is approve, mode is not shadow, and no moderator approved it', () => {
      const message = messageWithAutomod(7, {
        verdict: 'approve',
        mode: 'approve',
      })
      const wrapper = mountLine({ message, groupid: 7 })
      expect(wrapper.text()).toContain('Auto-approved by automated review')
    })

    it('shows moderator agreed when the verdict is approve and a moderator approved it', () => {
      const message = messageWithAutomod(
        7,
        { verdict: 'approve', mode: 'approve' },
        99
      )
      const wrapper = mountLine({ message, groupid: 7 })
      expect(wrapper.text()).toContain(
        'Approved by a moderator - automated review agreed'
      )
    })

    it('shows the hold reason when the verdict is hold but a moderator approved it', () => {
      const message = messageWithAutomod(
        7,
        { verdict: 'hold', mode: 'approve', reason: 'looks like spam' },
        99
      )
      const wrapper = mountLine({ message, groupid: 7 })
      expect(wrapper.text()).toContain(
        'Approved by a moderator - automated review would have held: looks like spam'
      )
    })

    it('shows what shadow mode would have approved, when nobody has approved it yet', () => {
      const message = messageWithAutomod(7, {
        verdict: 'approve',
        mode: 'shadow',
      })
      const wrapper = mountLine({ message, groupid: 7 })
      expect(wrapper.text()).toContain('Automated review would have approved')
    })
  })

  describe('pending view', () => {
    it('shows shadow mode would approve', () => {
      const message = messageWithAutomod(7, {
        verdict: 'approve',
        mode: 'shadow',
      })
      const wrapper = mountLine({ message, groupid: 7, pending: true })
      expect(wrapper.text()).toContain('Automated review: would approve')
    })

    it('shows shadow mode would hold with the reason', () => {
      const message = messageWithAutomod(7, {
        verdict: 'hold',
        mode: 'shadow',
        reason: 'no photo',
      })
      const wrapper = mountLine({ message, groupid: 7, pending: true })
      expect(wrapper.text()).toContain(
        'Automated review: would hold - no photo'
      )
    })

    it('shows approve mode will publish after the wait', () => {
      const message = messageWithAutomod(7, {
        verdict: 'approve',
        mode: 'approve',
      })
      const wrapper = mountLine({ message, groupid: 7, pending: true })
      expect(wrapper.text()).toContain(
        'Automated review: will publish after the wait'
      )
    })

    it('shows approve mode holding with the reason', () => {
      const message = messageWithAutomod(7, {
        verdict: 'hold',
        mode: 'approve',
        reason: 'suspicious wording',
      })
      const wrapper = mountLine({ message, groupid: 7, pending: true })
      expect(wrapper.text()).toContain(
        'Automated review: holding - suspicious wording'
      )
    })

    it('explains an approved decision that is held back as a quality-check sample', () => {
      const message = messageWithAutomod(7, {
        verdict: 'approve',
        mode: 'approve',
        qualitysample: true,
      })
      const wrapper = mountLine({ message, groupid: 7, pending: true })
      expect(wrapper.text()).toContain(
        'held back as a quality-check sample for a moderator'
      )
      expect(wrapper.text()).not.toContain('will publish after the wait')
    })
  })

  it('opens the modal when Why? is clicked', async () => {
    const message = messageWithAutomod(7, {
      verdict: 'approve',
      mode: 'approve',
    })
    const wrapper = mountLine({ message, groupid: 7 })
    await wrapper.find('button').trigger('click')
    expect(mockModalShow).toHaveBeenCalled()
  })

  // The service being down is recorded as a hold with end UNAVAILABLE so the post waits
  // for a moderator; it is not a decision and must not read like one.
  describe('review could not run', () => {
    const unavailable = {
      verdict: 'hold',
      mode: 'approve',
      end: 'UNAVAILABLE',
      reason: 'Automated review unavailable',
    }

    it('says so on a pending post instead of calling it a hold', () => {
      const wrapper = mountLine({
        message: messageWithAutomod(7, unavailable),
        groupid: 7,
        pending: true,
      })
      expect(wrapper.text()).toContain(
        'Automated review could not run; waiting for a moderator (it will retry)'
      )
      expect(wrapper.text()).not.toContain('holding')
    })

    it('says so on a post that went live without a moderator', () => {
      const wrapper = mountLine({
        message: messageWithAutomod(7, unavailable, null, {
          collection: 'Approved',
        }),
        groupid: 7,
      })
      expect(wrapper.text()).toContain(
        'Published without a moderator; automated review could not run'
      )
    })

    it('says so on a post a moderator approved', () => {
      const wrapper = mountLine({
        message: messageWithAutomod(7, unavailable, 99),
        groupid: 7,
      })
      expect(wrapper.text()).toContain(
        'Approved by a moderator - automated review could not run'
      )
    })
  })

  // Live with nobody's approval on it, against a stored hold - the 48-hour fallback, or
  // the clock after a late review. The case the trial exists to count.
  it('shows a stored hold on a post that published with no moderator', () => {
    const wrapper = mountLine({
      message: messageWithAutomod(
        7,
        { verdict: 'hold', mode: 'approve', reason: 'asks for money' },
        null,
        { collection: 'Approved' }
      ),
      groupid: 7,
    })
    expect(wrapper.text()).toContain(
      'Published without a moderator; automated review would have held: asks for money'
    )
  })

  describe('Should not have published', () => {
    const autoApproved = (extra = {}) =>
      messageWithAutomod(
        7,
        { verdict: 'approve', mode: 'approve', end: 'APPROVE', ...extra },
        null,
        { collection: 'Approved' }
      )
    const flagButton = (wrapper) =>
      wrapper.find('[data-testid="automod-flag-published"]')

    it('offers one click on a post that published itself', () => {
      const wrapper = mountLine({ message: autoApproved(), groupid: 7 })
      expect(flagButton(wrapper).exists()).toBe(true)
    })

    it('records feedback against the decision and says so', async () => {
      const wrapper = mountLine({ message: autoApproved(), groupid: 7 })
      await flagButton(wrapper).trigger('click')
      await flushPromises()
      expect(mockPostAutomodFeedback).toHaveBeenCalledWith({
        msgid: 42,
        groupid: 7,
        node: 'APPROVE',
      })
      expect(flagButton(wrapper).exists()).toBe(false)
      expect(wrapper.find('[data-testid="automod-flagged"]').text()).toContain(
        'Noted: should not have published'
      )
    })

    it('starts marked when this moderator already said so', () => {
      const wrapper = mountLine({
        message: autoApproved({ feedback: ['APPROVE'] }),
        groupid: 7,
      })
      expect(flagButton(wrapper).exists()).toBe(false)
      expect(wrapper.find('[data-testid="automod-flagged"]').exists()).toBe(
        true
      )
    })

    it('is not offered on a post a moderator approved, a pending post, or a shadow decision', () => {
      expect(
        flagButton(
          mountLine({
            message: messageWithAutomod(
              7,
              { verdict: 'approve', mode: 'approve', end: 'APPROVE' },
              99
            ),
            groupid: 7,
          })
        ).exists()
      ).toBe(false)
      expect(
        flagButton(
          mountLine({ message: autoApproved(), groupid: 7, pending: true })
        ).exists()
      ).toBe(false)
      expect(
        flagButton(
          mountLine({ message: autoApproved({ mode: 'shadow' }), groupid: 7 })
        ).exists()
      ).toBe(false)
    })
  })
})
