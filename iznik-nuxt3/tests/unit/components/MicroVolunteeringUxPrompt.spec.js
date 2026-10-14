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
          inheritAttrs: true,
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

    it('records a skip before moving on, so the same question is not offered back', async () => {
      const w = mountWith(experimentPrompt)
      await w.find('[data-testid="skip-prompt"]').trigger('click')
      await flushPromises()
      expect(store.respond).toHaveBeenCalledTimes(1)
      expect(store.respond).toHaveBeenCalledWith({
        ux_prompt_id: 7,
        ux_answer: { skipped: true },
      })
      expect(w.emitted('skipped')).toHaveLength(1)
      expect(w.emitted('next')).toHaveLength(1)
    })

    it('moves on from a skip even if recording it fails, and only once', async () => {
      store.respond.mockRejectedValueOnce(new Error('down'))
      const w = mountWith(experimentPrompt)
      await w.find('[data-testid="skip-prompt"]').trigger('click')
      await w.find('[data-testid="skip-prompt"]').trigger('click')
      await flushPromises()
      expect(store.respond).toHaveBeenCalledTimes(1)
      expect(w.emitted('next')).toHaveLength(1)
    })

    it('marks the chosen option as pressed', async () => {
      const w = mountWith(experimentPrompt)
      const better = w.find('[data-testid="choice-better"]')
      expect(better.attributes('aria-pressed')).toBe('false')
      await better.trigger('click')
      expect(better.attributes('aria-pressed')).toBe('true')
      expect(
        w.find('[data-testid="choice-worse"]').attributes('aria-pressed')
      ).toBe('false')
    })

    it('does not read the caption twice: the picture has no alt, the caption names it', () => {
      const w = mountWith(experimentPrompt)
      for (const img of w.findAll('img')) {
        expect(img.attributes('alt')).toBe('')
      }
      expect(w.findAll('figcaption')).toHaveLength(2)
    })

    it('says so when a picture fails to load, instead of leaving a gap', async () => {
      const w = mountWith(experimentPrompt)
      expect(w.text()).not.toContain('would not load')
      await w.findAll('img')[1].trigger('error')
      expect(w.text()).toContain('The picture would not load')
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

  describe('survey details', () => {
    it('uses the labels the prompt gives, and the defaults otherwise', () => {
      const plain = mountWith(surveyPrompt)
      expect(plain.text()).toContain('Works best for you')
      expect(plain.text()).toContain('Most needs improving')
      const follow = mountWith({
        ...surveyPrompt,
        settings: {
          best_label: 'Gets in my way most',
          worst_label: 'Bothers me least',
        },
      })
      expect(follow.text()).toContain('Gets in my way most')
      expect(follow.text()).toContain('Bothers me least')
      expect(follow.text()).not.toContain('Works best for you')
    })

    it('keeps the server order unless the prompt asks for a shuffle', () => {
      const w = mountWith(surveyPrompt)
      const keys = w
        .findAll('[data-testid^="best-"]')
        .map((b) => b.attributes('data-testid'))
      expect(keys).toEqual(['best-give', 'best-reply', 'best-login'])
    })

    it('shuffles the options once per member when randomise is set', () => {
      const many = Array.from({ length: 7 }, (_, i) => ({
        key: `problem:${i}`,
        label: `Problem ${i}`,
      }))
      const orders = new Set()
      for (let n = 0; n < 8; n++) {
        const w = mountWith({
          ...surveyPrompt,
          options: many,
          settings: { randomise: true },
        })
        const best = w
          .findAll('[data-testid^="best-"]')
          .map((b) => b.attributes('data-testid'))
        const worst = w
          .findAll('[data-testid^="worst-"]')
          .map((b) => b.attributes('data-testid'))
        // Same members of the list, same order in both columns.
        expect([...best].sort()).toEqual(
          many.map((o) => `best-${o.key}`).sort()
        )
        expect(best.map((k) => k.replace('best-', ''))).toEqual(
          worst.map((k) => k.replace('worst-', ''))
        )
        orders.add(best.join('|'))
      }
      expect(orders.size).toBeGreaterThan(1)
    })

    it('does not send the escape after the member changed their mind', async () => {
      const w = mountWith(surveyPrompt)
      await w.find('[data-testid="escape-dont_care"]').trigger('click')
      await w.find('[data-testid="best-give"]').trigger('click')
      await w.find('[data-testid="worst-login"]').trigger('click')
      expect(
        w.find('[data-testid="escape-dont_care"]').attributes('aria-pressed')
      ).toBe('false')
      await w.find('.spin-button').trigger('click')
      await flushPromises()
      expect(store.respond).toHaveBeenCalledWith({
        ux_prompt_id: 9,
        ux_answer: { best: 'give', worst: 'login' },
        comments: undefined,
      })
    })

    it('a pick alone, after an escape, is not an answer yet', async () => {
      const w = mountWith(surveyPrompt)
      await w.find('[data-testid="escape-dont_care"]').trigger('click')
      await w.find('[data-testid="best-give"]').trigger('click')
      expect(w.find('.spin-button').attributes('disabled')).toBeDefined()
    })

    it('words the escape so it covers both halves of the question', () => {
      const w = mountWith(surveyPrompt)
      expect(w.find('[data-testid="escape-dont_care"]').text()).toBe(
        'None of these stands out for me'
      )
    })

    it('groups the buttons under their labels and marks the picks', async () => {
      const w = mountWith(surveyPrompt)
      const group = w.find('[data-testid="group-best"]')
      expect(group.attributes('role')).toBe('group')
      const labelId = group.attributes('aria-labelledby')
      expect(w.find(`#${labelId}`).text()).toBe('Works best for you')
      const give = w.find('[data-testid="best-give"]')
      expect(give.attributes('aria-pressed')).toBe('false')
      await give.trigger('click')
      expect(give.attributes('aria-pressed')).toBe('true')
    })
  })

  describe('an open question', () => {
    const openPrompt = {
      id: 12,
      kind: 'open',
      question:
        'Think about the last time you used Freegle. Was there anything that got in your way?',
      body: '',
      options: [
        { key: 'nothing', label: 'Nothing got in my way' },
        { key: 'dont_understand', label: "Don't understand" },
      ],
      images: [],
      settings: {},
    }

    it('shows the question, a counter and both escapes, and no comment box', () => {
      const w = mountWith(openPrompt)
      expect(w.text()).toContain('got in your way')
      expect(w.find('[data-testid="open-count"]').text()).toContain(
        '0 of 1000 characters'
      )
      expect(w.find('[data-testid="escape-nothing"]').text()).toBe(
        'Nothing got in my way'
      )
      expect(w.find('[data-testid="escape-dont_understand"]').exists()).toBe(
        true
      )
      expect(w.find('#ux-prompt-comment').exists()).toBe(false)
      expect(w.text()).toContain('leave out names, addresses and phone numbers')
    })

    it('cannot send with nothing, or with fewer than three characters', async () => {
      const w = mountWith(openPrompt)
      expect(w.find('.spin-button').attributes('disabled')).toBeDefined()
      await w.find('textarea').setValue('ab')
      expect(w.find('.spin-button').attributes('disabled')).toBeDefined()
      expect(w.find('[data-testid="open-count"]').text()).toContain(
        'at least 3'
      )
      await w.find('textarea').setValue('  a  ')
      expect(w.find('.spin-button').attributes('disabled')).toBeDefined()
    })

    it('counts the characters as they are typed', async () => {
      const w = mountWith(openPrompt)
      await w.find('textarea').setValue('hello there')
      expect(w.find('[data-testid="open-count"]').text()).toContain(
        '11 of 1000'
      )
    })

    it('sends the trimmed text', async () => {
      const w = mountWith(openPrompt)
      await w.find('textarea').setValue('  The photo upload took ages  ')
      expect(w.find('.spin-button').attributes('disabled')).toBeUndefined()
      await w.find('.spin-button').trigger('click')
      await flushPromises()
      expect(store.respond).toHaveBeenCalledWith({
        ux_prompt_id: 12,
        ux_answer: { text: 'The photo upload took ages' },
        comments: undefined,
      })
      expect(w.emitted('next')).toHaveLength(1)
    })

    it('sends an escape instead of text', async () => {
      const w = mountWith(openPrompt)
      await w.find('[data-testid="escape-nothing"]').trigger('click')
      expect(w.find('.spin-button').attributes('disabled')).toBeUndefined()
      await w.find('.spin-button').trigger('click')
      await flushPromises()
      expect(store.respond).toHaveBeenCalledWith({
        ux_prompt_id: 12,
        ux_answer: { choice: 'nothing' },
        comments: undefined,
      })
    })

    it('typing after an escape takes the escape back', async () => {
      const w = mountWith(openPrompt)
      await w.find('[data-testid="escape-nothing"]').trigger('click')
      await w.find('textarea').setValue('Actually the login was slow')
      expect(
        w.find('[data-testid="escape-nothing"]').attributes('aria-pressed')
      ).toBe('false')
      await w.find('.spin-button').trigger('click')
      await flushPromises()
      expect(store.respond).toHaveBeenCalledWith({
        ux_prompt_id: 12,
        ux_answer: { text: 'Actually the login was slow' },
        comments: undefined,
      })
    })

    it('an escape after typing clears the text and sends the escape', async () => {
      const w = mountWith(openPrompt)
      await w.find('textarea').setValue('something typed')
      await w.find('[data-testid="escape-dont_understand"]').trigger('click')
      expect(w.find('textarea').element.value).toBe('')
      await w.find('.spin-button').trigger('click')
      await flushPromises()
      expect(store.respond).toHaveBeenCalledWith({
        ux_prompt_id: 12,
        ux_answer: { choice: 'dont_understand' },
        comments: undefined,
      })
    })

    it('falls back to the standard escapes when the prompt lists none', () => {
      const w = mountWith({ ...openPrompt, options: [] })
      expect(w.find('[data-testid="escape-nothing"]').text()).toBe(
        'Nothing comes to mind'
      )
    })

    it('can be skipped, recording a skip', async () => {
      const w = mountWith(openPrompt)
      await w.find('[data-testid="skip-prompt"]').trigger('click')
      await flushPromises()
      expect(store.respond).toHaveBeenCalledWith({
        ux_prompt_id: 12,
        ux_answer: { skipped: true },
      })
    })

    it('shows the typed text as text, never as markup', async () => {
      const w = mountWith(openPrompt)
      await w.find('textarea').setValue('<img src=x onerror=alert(1)>')
      expect(w.find('img').exists()).toBe(false)
    })
  })
})
