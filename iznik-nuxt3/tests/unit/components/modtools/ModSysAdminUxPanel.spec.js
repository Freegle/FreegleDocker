import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ModSysAdminUxPanel from '~/modtools/components/ModSysAdminUxPanel.vue'
import { uxStubs } from './uxStubs'

const make = (props) =>
  mount(ModSysAdminUxPanel, {
    props: { title: 'My panel', explanation: 'What this means.', ...props },
    slots: { default: '<div class="body">content</div>' },
    global: { stubs: uxStubs },
  })

describe('ModSysAdminUxPanel', () => {
  it('shows the title, explanation and content', () => {
    const w = make({})
    expect(w.text()).toContain('My panel')
    expect(w.text()).toContain('What this means.')
    expect(w.find('.body').exists()).toBe(true)
  })

  it('shows a spinner and hides the content while loading', () => {
    const w = make({ loading: true })
    expect(w.find('.spinner').exists()).toBe(true)
    expect(w.find('.body').exists()).toBe(false)
  })

  it('shows the error in a danger notice and hides the content', () => {
    const w = make({ error: 'boom' })
    expect(w.find('.notice-message.danger').text()).toContain('boom')
    expect(w.find('.body').exists()).toBe(false)
  })

  it('shows the empty text when empty', () => {
    const w = make({ empty: true, emptyText: 'Nothing yet.' })
    expect(w.text()).toContain('Nothing yet.')
    expect(w.find('.body').exists()).toBe(false)
  })

  it('still shows the explanation in every state', () => {
    expect(make({ loading: true }).text()).toContain('What this means.')
    expect(make({ error: 'x' }).text()).toContain('What this means.')
    expect(make({ empty: true }).text()).toContain('What this means.')
  })
})
