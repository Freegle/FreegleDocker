import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import MicroVolunteeringUxPrompt from '~/components/MicroVolunteeringUxPrompt.vue'

const store = {
  respond: vi.fn().mockResolvedValue(undefined),
}

vi.mock('~/stores/microvolunteering', () => ({
  useMicroVolunteeringStore: () => store,
}))

const experimentPrompt = {
  id: 7,
  kind: 'experiment',
  question: 'Would this change be better or worse for you?',
  body: 'A plain "Add photos later" link above the camera button.\n\nMore people finish posting.',
  options: [
    { key: 'better', label: 'Better' },
    { key: 'worse', label: 'Worse' },
    { key: 'dont_care', label: "Don't care" },
    { key: 'dont_understand', label: "Don't understand" },
  ],
  images: [
    { url: 'https://img/now.png', caption: 'How it is now' },
    { url: 'https://img/change.png', caption: 'The change' },
  ],
}

const surveyPrompt = {
  id: 9,
  kind: 'survey',
  question: 'Which part of Freegle works best for you, and which worst?',
  body: 'Think about the last few times you used it.',
  options: [
    { key: 'give', label: 'Posting an offer' },
    { key: 'reply', label: 'Replying to a post' },
    { key: 'login', label: 'Signing in' },
  ],
  images: [],
}

function mountWith(prompt) {
  return mount(MicroVolunteeringUxPrompt, {
    props: { prompt },
    global: {
      stubs: {
        SpinButton: {
          template:
            '<button class="spin-button" :disabled="disabled" @click="$emit(\'handle\', () => {})">{{ label }}</button>',
          props: ['iconName', 'variant', 'label', 'disabled'],
          emits: ['handle'],
        },
        'b-button': {
          template:
            '<button class="b-button" :class="variant" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
          props: ['variant', 'disabled', 'size'],
          emits: ['click'],
        },
        'b-form-group': { template: '<div><slot /></div>', props: ['label'] },
        'b-form-textarea': {
          template:
            '<textarea :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
          props: ['modelValue', 'rows', 'maxlength', 'placeholder'],
          emits: ['update:modelValue'],
        },
      },
    },
  })
}

describe('MicroVolunteeringUxPrompt', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  describe('an experiment question', () => {
    it('shows the question, the plain-English body and both pictures', () => {
      const w = mountWith(experimentPrompt)
      expect(w.text()).toContain(
        'Would this change be better or worse for you?'
      )
      expect(w.text()).toContain('Add photos later')
      const imgs = w.findAll('img')
      expect(imgs).toHaveLength(2)
      expect(imgs[0].attributes('src')).toBe('https://img/now.png')
      expect(w.text()).toContain('The change')
      expect(w.text()).toContain('may or may not stay')
    })

    it('offers every option including dont care and dont understand', () => {
      const w = mountWith(experimentPrompt)
      for (const key of ['better', 'worse', 'dont_care', 'dont_understand']) {
        expect(w.find(`[data-testid="choice-${key}"]`).exists()).toBe(true)
      }
    })

    it('cannot send until a choice is made', async () => {
      const w = mountWith(experimentPrompt)
      expect(w.find('.spin-button').attributes('disabled')).toBeDefined()
      await w.find('[data-testid="choice-worse"]').trigger('click')
      expect(w.find('.spin-button').attributes('disabled')).toBeUndefined()
    })

    it('sends the choice and the trimmed comment, then moves on', async () => {
      const w = mountWith(experimentPrompt)
      await w.find('[data-testid="choice-dont_understand"]').trigger('click')
      await w.find('textarea').setValue('  What is a camera button?  ')
      await w.find('.spin-button').trigger('click')
      await flushPromises()
      expect(store.respond).toHaveBeenCalledWith({
        ux_prompt_id: 7,
        ux_answer: { choice: 'dont_understand' },
        comments: 'What is a camera button?',
      })
      expect(w.emitted('next')).toHaveLength(1)
    })

    it('leaves the comment out when empty', async () => {
      const w = mountWith(experimentPrompt)
      await w.find('[data-testid="choice-better"]').trigger('click')
      await w.find('.spin-button').trigger('click')
      await flushPromises()
      expect(store.respond).toHaveBeenCalledWith({
        ux_prompt_id: 7,
        ux_answer: { choice: 'better' },
        comments: undefined,
      })
    })

    it('can be skipped without sending anything', async () => {
      const w = mountWith(experimentPrompt)
      await w.find('[data-testid="skip-prompt"]').trigger('click')
      expect(store.respond).not.toHaveBeenCalled()
      expect(w.emitted('next')).toHaveLength(1)
    })

    it('still moves on if the send fails', async () => {
      store.respond.mockRejectedValueOnce(new Error('down'))
      const w = mountWith(experimentPrompt)
      await w.find('[data-testid="choice-worse"]').trigger('click')
      await w.find('.spin-button').trigger('click')
      await flushPromises()
      expect(store.respond).toHaveBeenCalled()
      expect(w.emitted('next')).toHaveLength(1)
    })

    it('hides a picture that fails to load', async () => {
      const w = mountWith(experimentPrompt)
      const img = w.findAll('img')[1]
      await img.trigger('error')
      expect(img.element.style.display).toBe('none')
    })
  })

  describe('a best and worst survey', () => {
    it('needs a best and a worst that differ', async () => {
      const w = mountWith(surveyPrompt)
      expect(w.find('.spin-button').attributes('disabled')).toBeDefined()
      await w.find('[data-testid="best-give"]').trigger('click')
      expect(w.find('.spin-button').attributes('disabled')).toBeDefined()
      // The same area cannot be both.
      expect(
        w.find('[data-testid="worst-give"]').attributes('disabled')
      ).toBeDefined()
      await w.find('[data-testid="worst-login"]').trigger('click')
      expect(w.find('.spin-button').attributes('disabled')).toBeUndefined()
      await w.find('.spin-button').trigger('click')
      await flushPromises()
      expect(store.respond).toHaveBeenCalledWith({
        ux_prompt_id: 9,
        ux_answer: { best: 'give', worst: 'login' },
        comments: undefined,
      })
    })

    it('accepts dont care or dont understand instead, clearing any picks', async () => {
      const w = mountWith(surveyPrompt)
      await w.find('[data-testid="best-give"]').trigger('click')
      await w.find('[data-testid="escape-dont_care"]').trigger('click')
      expect(w.find('.spin-button').attributes('disabled')).toBeUndefined()
      await w.find('.spin-button').trigger('click')
      await flushPromises()
      expect(store.respond).toHaveBeenCalledWith({
        ux_prompt_id: 9,
        ux_answer: { choice: 'dont_care' },
        comments: undefined,
      })
    })

    it('pressing the escape again un-picks it', async () => {
      const w = mountWith(surveyPrompt)
      await w.find('[data-testid="escape-dont_understand"]').trigger('click')
      await w.find('[data-testid="escape-dont_understand"]').trigger('click')
      expect(w.find('.spin-button').attributes('disabled')).toBeDefined()
    })

    it('shows the survey intro, not the experiment one', () => {
      const w = mountWith(surveyPrompt)
      expect(w.text()).toContain('put our effort where it helps most')
      expect(w.text()).not.toContain('may or may not stay')
    })
  })
})
