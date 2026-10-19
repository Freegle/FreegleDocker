import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

import ModSysAdminPaf from '~/modtools/components/ModSysAdminPaf.vue'

const mockFetchImports = vi.fn()
const mockStartImport = vi.fn()

vi.mock('~/api', () => ({
  default: () => ({
    paf: {
      fetchImports: mockFetchImports,
      startImport: mockStartImport,
    },
  }),
}))

vi.mock('#app', () => ({
  useRuntimeConfig: () => ({ public: { apiUrl: 'http://test' } }),
}))

vi.mock('~/composables/useTimeFormat', () => ({
  timeago: vi.fn(() => 'a while ago'),
}))

const doneImport = {
  id: 1,
  status: 'done',
  edition: 'Y26M08',
  progress: 100,
  detail: 'Applied 1000 lines',
  lines_in_release: 32429019,
  lines_to_apply: 1000,
  lines_applied: 1000,
  inserted: 12,
  updated: 980,
  skipped: 8,
  error: null,
  created_at: '2026-10-19T09:00:00Z',
}

const applyingImport = {
  ...doneImport,
  id: 2,
  status: 'applying',
  edition: null,
  progress: 42,
  detail: 'Applied 420 of 1000 lines',
  lines_applied: 420,
  inserted: 3,
  updated: 400,
  skipped: 2,
}

const failedImport = {
  ...doneImport,
  id: 3,
  status: 'failed',
  error: 'The key was not accepted for this release.',
}

function mountComponent() {
  return mount(ModSysAdminPaf, {
    global: {
      stubs: {
        'b-card': { template: '<div class="card"><slot /></div>' },
        'b-form': {
          emits: ['submit'],
          template:
            '<form @submit.prevent="$emit(\'submit\', $event)"><slot /></form>',
        },
        'b-form-group': {
          template: '<div class="form-group"><slot /></div>',
        },
        'b-form-input': {
          props: ['modelValue', 'id'],
          emits: ['update:modelValue'],
          template:
            '<input :id="id" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
        },
        'b-button': {
          props: ['disabled'],
          template:
            '<button type="submit" :disabled="disabled"><slot /></button>',
        },
        'b-spinner': { template: '<span class="spinner" />' },
        'b-progress': {
          props: ['value'],
          template: '<div class="progress" :data-value="value" />',
        },
        'b-table-simple': { template: '<table><slot /></table>' },
        'b-thead': { template: '<thead><slot /></thead>' },
        'b-tbody': { template: '<tbody><slot /></tbody>' },
        'b-tr': { template: '<tr><slot /></tr>' },
        'b-th': { template: '<th><slot /></th>' },
        'b-td': { template: '<td><slot /></td>' },
      },
    },
  })
}

describe('ModSysAdminPaf', () => {
  let wrapper = null

  beforeEach(() => {
    vi.clearAllMocks()
    vi.useFakeTimers()
  })

  // The component polls while an import is running; unmount to stop it.
  afterEach(() => {
    wrapper?.unmount()
    wrapper = null
    vi.useRealTimers()
  })

  it('offers the key form when nothing is running, and lists past imports', async () => {
    mockFetchImports.mockResolvedValue([doneImport, failedImport])
    wrapper = mountComponent()
    await flushPromises()

    expect(wrapper.find('#paf-key').exists()).toBe(true)
    expect(wrapper.text()).toContain('Y26M08')
    expect(wrapper.text()).toContain('Done')
    expect(wrapper.text()).toContain('980')
    expect(wrapper.text()).toContain('The key was not accepted')
    expect(wrapper.find('.progress').exists()).toBe(false)
  })

  it('shows the running import with its progress instead of the form', async () => {
    mockFetchImports.mockResolvedValue([applyingImport, doneImport])
    wrapper = mountComponent()
    await flushPromises()

    expect(wrapper.find('#paf-key').exists()).toBe(false)
    expect(wrapper.find('.progress').attributes('data-value')).toBe('42')
    expect(wrapper.text()).toContain('Applying to the database')
    expect(wrapper.text()).toContain('Applied 420 of 1000 lines')
    expect(wrapper.find('.step-current').text()).toBe('Apply')
    expect(wrapper.findAll('.step-done')).toHaveLength(5)
  })

  it('polls while an import is running, and stops polling once it has finished', async () => {
    mockFetchImports.mockResolvedValueOnce([applyingImport])
    wrapper = mountComponent()
    await flushPromises()
    expect(mockFetchImports).toHaveBeenCalledTimes(1)

    mockFetchImports.mockResolvedValueOnce([{ ...applyingImport, status: 'done' }])
    await vi.advanceTimersByTimeAsync(5000)
    expect(mockFetchImports).toHaveBeenCalledTimes(2)

    await vi.advanceTimersByTimeAsync(15000)
    expect(mockFetchImports).toHaveBeenCalledTimes(2)
  })

  it('starts an import with the key and release given, then refreshes', async () => {
    mockFetchImports.mockResolvedValue([])
    mockStartImport.mockResolvedValue({ id: 4, status: 'queued' })
    wrapper = mountComponent()
    await flushPromises()

    await wrapper.find('#paf-key').setValue('7E7 / 3W7 / RRJ')
    await wrapper.find('#paf-edition').setValue(' Y26M08 ')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(mockStartImport).toHaveBeenCalledWith('7E7 / 3W7 / RRJ', 'Y26M08')
    expect(mockFetchImports).toHaveBeenCalledTimes(2)
    expect(wrapper.find('#paf-key').element.value).toBe('')
  })

  it('shows the reason when the server refuses the request', async () => {
    mockFetchImports.mockResolvedValue([])
    mockStartImport.mockRejectedValue({
      message: 'API Error',
      response: {
        status: 400,
        data: { error: 'The key should be 24 letters and numbers' },
      },
    })
    wrapper = mountComponent()
    await flushPromises()

    await wrapper.find('#paf-key').setValue('short')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('The key should be 24 letters and numbers')
  })

  it('says so when the list cannot be loaded', async () => {
    mockFetchImports.mockRejectedValue(new Error('boom'))
    wrapper = mountComponent()
    await flushPromises()

    expect(wrapper.text()).toContain('Failed to load: boom')
  })
})
