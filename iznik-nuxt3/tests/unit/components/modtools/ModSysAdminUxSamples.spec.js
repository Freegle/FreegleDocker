import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import ModSysAdminUxSamples from '~/modtools/components/ModSysAdminUxSamples.vue'
import { useUxStore } from '~/modtools/stores/ux'
import { uxStubs } from './uxStubs'

const sampleData = {
  date: '2026-10-07',
  flow: 'post',
  step: 'photos',
  sessions: [
    {
      session_id: 'abc123',
      events: [
        {
          ts: '2026-10-07T10:00:00Z',
          event_type: 'flow_step',
          action_name: 'enter',
          step: 'item',
          dwell_ms: null,
          label: '',
          component: '',
          page_route: '/give',
        },
        {
          ts: '2026-10-07T10:00:09Z',
          event_type: 'interaction',
          action_name: 'click',
          step: 'photos',
          dwell_ms: 9000,
          label: 'Add photo',
          component: 'PhotoUpload',
          page_route: '/give',
        },
      ],
    },
  ],
}

describe('ModSysAdminUxSamples', () => {
  let store
  beforeEach(() => {
    setActivePinia(createPinia())
    store = useUxStore()
    store.load = vi.fn().mockResolvedValue()
  })
  const make = (props = {}) =>
    mount(ModSysAdminUxSamples, {
      props: {
        modelValue: true,
        flow: 'post',
        step: 'photos',
        date: '2026-10-07',
        ...props,
      },
      global: {
        stubs: {
          ...uxStubs,
          'b-modal': {
            name: 'ModalStub',
            template:
              '<div class="modal-stub" v-if="modelValue"><slot /></div>',
            props: ['modelValue', 'title'],
          },
        },
      },
    })

  it('loads the samples for the flow, step and date when opened', async () => {
    make()
    await flushPromises()
    expect(store.load).toHaveBeenCalledWith('samples', {
      date: '2026-10-07',
      flow: 'post',
      step: 'photos',
    })
  })

  it('does not load while closed', async () => {
    make({ modelValue: false })
    await flushPromises()
    expect(store.load).not.toHaveBeenCalled()
  })

  it('loads when it becomes open', async () => {
    const w = make({ modelValue: false })
    await w.setProps({ modelValue: true })
    await flushPromises()
    expect(store.load).toHaveBeenCalledTimes(1)
  })

  it('shows each session as a timeline of its events in order', () => {
    store.samples.data = sampleData
    const w = make()
    const sessions = w.findAll('.session')
    expect(sessions).toHaveLength(1)
    expect(sessions[0].text()).toContain('abc123')
    const events = sessions[0].findAll('.event')
    expect(events).toHaveLength(2)
    expect(events[0].text()).toContain('flow_step')
    expect(events[0].text()).toContain('enter')
    expect(events[1].text()).toContain('click')
    expect(events[1].text()).toContain('Add photo')
    expect(events[1].text()).toContain('PhotoUpload')
    expect(events[1].text()).toContain('9.0 s')
  })

  it('warns when Loki could not be read', () => {
    store.samples.data = { ...sampleData, sessions: [], loki_error: 'timeout' }
    const w = make()
    expect(w.find('.notice-message.warning').text()).toContain('timeout')
  })

  it('shows the empty message with no sessions', () => {
    store.samples.data = { sessions: [] }
    expect(make().text()).toContain('No sampled sessions')
  })

  it('emits update:modelValue when the modal asks to close', async () => {
    const w = make()
    w.findComponent({ name: 'ModalStub' }).vm.$emit('update:modelValue', false)
    expect(w.emitted('update:modelValue')[0]).toEqual([false])
  })
})
