import { describe, it, expect, vi, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import CalendarEventButton from '~/components/CalendarEventButton.vue'

const event = {
  name: 'Handover: Alice',
  description: 'Bring a bag',
  location: 'High St',
  startDate: '2026-07-01',
  startTime: '14:00',
  endTime: '14:15',
  timeZone: 'Europe/London',
}

function mountButton() {
  return mount(CalendarEventButton, {
    props: { event },
    global: {
      stubs: {
        'b-dropdown': { template: '<div><slot /></div>' },
        'b-dropdown-item': {
          props: ['href'],
          template: '<a :href="href" @click="$emit(\'click\')"><slot /></a>',
        },
        'v-icon': true,
      },
    },
  })
}

describe('CalendarEventButton', () => {
  afterEach(() => vi.restoreAllMocks())

  it('offers Google, ics, Microsoft 365 and Yahoo', () => {
    const w = mountButton()
    expect(w.find('[data-testid="atc-google"]').attributes('href')).toContain(
      'calendar.google.com'
    )
    expect(
      w.find('[data-testid="atc-microsoft"]').attributes('href')
    ).toContain('outlook.office.com')
    expect(w.find('[data-testid="atc-yahoo"]').attributes('href')).toContain(
      'calendar.yahoo.com'
    )
    expect(w.find('[data-testid="atc-ics"]').exists()).toBe(true)
  })

  it('downloads an ics file when the ics item is clicked', async () => {
    let blob = null
    global.URL.createObjectURL = vi.fn((b) => {
      blob = b
      return 'blob:x'
    })
    global.URL.revokeObjectURL = vi.fn()
    const click = vi
      .spyOn(HTMLAnchorElement.prototype, 'click')
      .mockImplementation(() => {})

    const w = mountButton()
    await w.find('[data-testid="atc-ics"]').trigger('click')

    expect(click).toHaveBeenCalled()
    expect(blob.type).toContain('text/calendar')
    expect(await blob.text()).toContain('SUMMARY:Handover: Alice')
  })
})
