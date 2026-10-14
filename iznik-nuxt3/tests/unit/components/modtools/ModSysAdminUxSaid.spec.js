import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ModSysAdminUxSaid from '~/modtools/components/ModSysAdminUxSaid.vue'
import { uxStubs } from './uxStubs'

const make = (props) =>
  mount(ModSysAdminUxSaid, {
    props: { label: 'What members wrote (7)', ...props },
    global: { stubs: uxStubs },
  })

describe('ModSysAdminUxSaid', () => {
  it('hides the text until asked', async () => {
    const w = make({ comments: ['one', 'two'] })
    expect(w.text()).not.toContain('one')
    expect(w.find('.toggle').attributes('aria-expanded')).toBe('false')
    await w.find('.toggle').trigger('click')
    expect(w.text()).toContain('one')
    expect(w.find('.toggle').attributes('aria-expanded')).toBe('true')
    expect(w.find('.toggle').text()).toContain('Hide')
  })

  it('shows the first few and says how many there are', async () => {
    const comments = Array.from({ length: 7 }, (_, i) => `c${i}`)
    const w = make({ comments })
    await w.find('.toggle').trigger('click')
    expect(w.findAll('li')).toHaveLength(5)
    expect(w.text()).toContain('Showing 5 of 7.')
  })

  it('does not say showing when everything is shown', async () => {
    const w = make({ comments: ['a', 'b'] })
    await w.find('.toggle').trigger('click')
    expect(w.text()).not.toContain('Showing')
  })

  it('escapes the text', async () => {
    const w = make({ comments: ['<script>alert(1)</script>'] })
    await w.find('.toggle').trigger('click')
    expect(w.find('script').exists()).toBe(false)
    expect(w.text()).toContain('<script>alert(1)</script>')
  })
})
