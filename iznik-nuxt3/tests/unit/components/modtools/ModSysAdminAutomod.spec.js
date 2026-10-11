import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import ModSysAdminAutomod from '~/modtools/components/ModSysAdminAutomod.vue'

const mockFetchAutomodAgreement = vi.fn()
vi.mock('@/stores/message', () => ({
  useMessageStore: () => ({ fetchAutomodAgreement: mockFetchAutomodAgreement }),
}))

const tableStub = { template: '<div><slot /></div>' }
const stubs = {
  'b-form-group': tableStub,
  'b-form-select': { template: '<select />', props: ['modelValue', 'options'] },
  'b-card': { template: '<div class="card"><slot /></div>', props: ['header'] },
  'b-table-simple': { template: '<table><slot /></table>' },
  'b-thead': { template: '<thead><slot /></thead>' },
  'b-tbody': { template: '<tbody><slot /></tbody>' },
  'b-tr': { template: '<tr><slot /></tr>' },
  'b-th': { template: '<th><slot /></th>' },
  'b-td': { template: '<td><slot /></td>' },
  'b-spinner': true,
  NoticeMessage: { template: '<div class="notice"><slot /></div>' },
}

const stats = {
  days: 30,
  total: 12,
  shadow: { count: 8 },
  approve: { count: 4 },
  unavailable: 2,
  late: 1,
  latency: { avgSeconds: 90, maxSeconds: 700 },
  byEnd: [
    {
      end: 'APPROVE',
      verdict: 'approve',
      count: 9,
      modDisagreed: 1,
      neighbourDisagreed: 3,
    },
    {
      end: 'HOLD_LOAN',
      verdict: 'hold',
      count: 3,
      modDisagreed: 0,
      neighbourDisagreed: 0,
    },
  ],
  byGroup: [
    {
      groupid: 5,
      nameshort: 'FreegleTown',
      decisions: 10,
      approves: 8,
      holds: 2,
      unavailable: 1,
      modDisagreed: 1,
      neighbourDisagreed: 2,
      late: 1,
    },
    {
      groupid: 6,
      nameshort: 'OtherTown',
      decisions: 2,
      approves: 1,
      holds: 1,
      unavailable: 1,
      modDisagreed: 0,
      neighbourDisagreed: 1,
      late: 0,
    },
  ],
  feedback: [{ node: 'LOAN', count: 2 }],
}

describe('ModSysAdminAutomod', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockFetchAutomodAgreement.mockResolvedValue(stats)
  })

  it('fetches the last 30 days on mount', async () => {
    mount(ModSysAdminAutomod, { global: { stubs } })
    await flushPromises()
    expect(mockFetchAutomodAgreement).toHaveBeenCalledWith({ days: 30 })
  })

  it('shows the totals, one row per end node with the disagreement rate, and the feedback', async () => {
    const wrapper = mount(ModSysAdminAutomod, { global: { stubs } })
    await flushPromises()
    const text = wrapper.text()
    expect(text).toContain('12')
    expect(text).toContain('HOLD_LOAN')
    expect(text).toContain('11.1%')
    expect(text).toContain('LOAN')
  })

  // The three numbers that say whether the trial is safe to widen: how often the review
  // could not run, how often it decided after the wait had already run out, and how long
  // it takes; plus the neighbours' verdicts on what it approved.
  it('shows the unavailable and late counts, the latency, and neighbour disagreement', async () => {
    const wrapper = mount(ModSysAdminAutomod, { global: { stubs } })
    await flushPromises()
    expect(
      wrapper.find('[data-testid="automod-unavailable"]').text()
    ).toContain('2')
    expect(
      wrapper.find('[data-testid="automod-unavailable"]').text()
    ).toContain('16.7%')
    expect(wrapper.find('[data-testid="automod-late"]').text()).toContain('1')
    expect(wrapper.find('[data-testid="automod-latency"]').text()).toContain(
      'average 2 min, longest 12 min'
    )
    const byEnd = wrapper.text()
    expect(byEnd).toContain('Neighbour disagreed')
    expect(byEnd).toContain('3')
  })

  it('lists each community and lets the panel be narrowed to one', async () => {
    const wrapper = mount(ModSysAdminAutomod, { global: { stubs } })
    await flushPromises()
    const byGroup = wrapper.find('[data-testid="automod-by-group"]')
    expect(byGroup.exists()).toBe(true)
    expect(byGroup.text()).toContain('FreegleTown')
    expect(byGroup.text()).toContain('OtherTown')
    // The community choices come from the unfiltered fetch, and stay once narrowed.
    expect(wrapper.vm.groupOptions.map((o) => o.text)).toEqual([
      'All communities',
      'FreegleTown',
      'OtherTown',
    ])
    mockFetchAutomodAgreement.mockResolvedValue({
      ...stats,
      byGroup: [stats.byGroup[0]],
    })
    wrapper.vm.groupid = 5
    await wrapper.vm.fetchStats()
    expect(mockFetchAutomodAgreement).toHaveBeenLastCalledWith({
      days: 30,
      groupid: 5,
    })
    expect(wrapper.vm.groupOptions).toHaveLength(3)
  })

  it('shows the error when the request fails', async () => {
    mockFetchAutomodAgreement.mockRejectedValue(new Error('boom'))
    const wrapper = mount(ModSysAdminAutomod, { global: { stubs } })
    await flushPromises()
    expect(wrapper.find('.notice').text()).toContain('boom')
  })
})
