import { describe, it, expect, vi, beforeEach } from 'vitest'
import { ref } from 'vue'
import { mount, flushPromises } from '@vue/test-utils'
import MicroVolunteeringUxPrompt from '~/components/MicroVolunteeringUxPrompt.vue'

const store = {
  respond: vi.fn().mockResolvedValue(undefined),
}

vi.mock('~/stores/microvolunteering', () => ({
  useMicroVolunteeringStore: () => store,
}))

// The member's id decides which way round Better and Worse are shown.
const myid = ref(2)
vi.mock('~/composables/useMe', () => ({
  useMe: () => ({ myid }),
}))

const experimentPrompt = {
  id: 7,
  kind: 'experiment',
  question: 'Would this change be better or worse for you?',
  body: 'A plain "Add photos later" link above the camera button.\n\nMore people finish posting.',
  options: [
    { key: 'better', label: 'Better' },
    { key: 'worse', label: 'Worse' },
    { key: 'dont_care', label: 'Makes no difference to me' },
    { key: 'dont_understand', label: "I'm not sure what it's for" },
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
    { key: 'search', label: 'Finding things' },
    { key: 'chat', label: 'Chatting' },
  ],
  display: ['reply', 'login', 'chat', 'give'],
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
        'nuxt-link': {
          template: '<a :href="to"><slot /></a>',
          props: ['to'],
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
    myid.value = 2
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
        ux_answer: {
          choice: 'dont_understand',
          shown: ['better', 'worse', 'dont_care', 'dont_understand'],
        },
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
        ux_answer: {
          choice: 'better',
          shown: ['better', 'worse', 'dont_care', 'dont_understand'],
        },
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

  describe('a four-at-a-time survey', () => {
    const buttons = (w, prefix) =>
      w
        .findAll(`[data-testid^="${prefix}-"]`)
        .map((b) => b.attributes('data-testid').replace(`${prefix}-`, ''))

    it('shows exactly the options the server chose, in its order', () => {
      const w = mountWith(surveyPrompt)
      expect(buttons(w, 'best')).toEqual(['reply', 'login', 'chat', 'give'])
      expect(w.find('[data-testid="best-search"]').exists()).toBe(false)
    })

    it('falls back to every option when the server sent no display list', () => {
      const w = mountWith({ ...surveyPrompt, display: undefined })
      expect(buttons(w, 'best')).toEqual([
        'give',
        'reply',
        'login',
        'search',
        'chat',
      ])
    })

    it('skips a display key that is not an option rather than showing a blank', () => {
      const w = mountWith({
        ...surveyPrompt,
        display: ['reply', 'nope', 'give'],
      })
      expect(buttons(w, 'best')).toEqual(['reply', 'give'])
    })

    it('needs only a best: the second tap is optional and appears after the first', async () => {
      const w = mountWith(surveyPrompt)
      expect(w.find('.spin-button').attributes('disabled')).toBeDefined()
      expect(w.find('[data-testid="group-worst"]').exists()).toBe(false)
      await w.find('[data-testid="best-give"]').trigger('click')
      expect(w.find('.spin-button').attributes('disabled')).toBeUndefined()
      expect(w.find('[data-testid="group-worst"]').exists()).toBe(true)
      // The same one cannot be both.
      expect(
        w.find('[data-testid="worst-give"]').attributes('disabled')
      ).toBeDefined()
      await w.find('.spin-button').trigger('click')
      await flushPromises()
      expect(store.respond).toHaveBeenCalledWith({
        ux_prompt_id: 9,
        ux_answer: { best: 'give', shown: ['reply', 'login', 'chat', 'give'] },
        comments: undefined,
      })
    })

    it('sends the least pick too when one is made, and lets it be taken back', async () => {
      const w = mountWith(surveyPrompt)
      await w.find('[data-testid="best-give"]').trigger('click')
      await w.find('[data-testid="worst-login"]').trigger('click')
      expect(
        w.find('[data-testid="worst-login"]').attributes('aria-pressed')
      ).toBe('true')
      await w.find('[data-testid="worst-login"]').trigger('click')
      expect(
        w.find('[data-testid="worst-login"]').attributes('aria-pressed')
      ).toBe('false')
      await w.find('[data-testid="worst-chat"]').trigger('click')
      await w.find('.spin-button').trigger('click')
      await flushPromises()
      expect(store.respond).toHaveBeenCalledWith({
        ux_prompt_id: 9,
        ux_answer: {
          best: 'give',
          worst: 'chat',
          shown: ['reply', 'login', 'chat', 'give'],
        },
        comments: undefined,
      })
    })

    it('offers none of these, which sends none with what was shown', async () => {
      const w = mountWith(surveyPrompt)
      // Four options are shown, so the way out says "none", not "neither".
      expect(w.findAll('.question-label').map((p) => p.text())).toContain(
        'Or, if none of these fits'
      )
      expect(w.find('[data-testid="escape-none"]').text()).toBe(
        'None of these has got in my way'
      )
      await w.find('[data-testid="best-give"]').trigger('click')
      await w.find('[data-testid="escape-none"]').trigger('click')
      // The pick is taken back, so no least group is left hanging.
      expect(w.find('[data-testid="group-worst"]').exists()).toBe(false)
      expect(w.find('.spin-button').attributes('disabled')).toBeUndefined()
      await w.find('.spin-button').trigger('click')
      await flushPromises()
      expect(store.respond).toHaveBeenCalledWith({
        ux_prompt_id: 9,
        ux_answer: {
          choice: 'none',
          shown: ['reply', 'login', 'chat', 'give'],
        },
        comments: undefined,
      })
    })

    it('sends dont understand alone, with no shown list', async () => {
      const w = mountWith(surveyPrompt)
      await w.find('[data-testid="escape-dont_understand"]').trigger('click')
      await w.find('.spin-button').trigger('click')
      await flushPromises()
      expect(store.respond).toHaveBeenCalledWith({
        ux_prompt_id: 9,
        ux_answer: { choice: 'dont_understand' },
        comments: undefined,
      })
    })

    it('pressing the escape again un-picks it', async () => {
      const w = mountWith(surveyPrompt)
      await w.find('[data-testid="escape-dont_understand"]').trigger('click')
      await w.find('[data-testid="escape-dont_understand"]').trigger('click')
      expect(w.find('.spin-button').attributes('disabled')).toBeDefined()
    })

    it('does not send the escape after the member changed their mind', async () => {
      const w = mountWith(surveyPrompt)
      await w.find('[data-testid="escape-none"]').trigger('click')
      await w.find('[data-testid="best-give"]').trigger('click')
      expect(
        w.find('[data-testid="escape-none"]').attributes('aria-pressed')
      ).toBe('false')
      await w.find('.spin-button').trigger('click')
      await flushPromises()
      expect(store.respond.mock.calls[0][0].ux_answer.best).toBe('give')
      expect(store.respond.mock.calls[0][0].ux_answer.choice).toBeUndefined()
    })

    it('shows the survey intro, not the experiment one', () => {
      const w = mountWith(surveyPrompt)
      expect(w.text()).toContain('put our effort where it helps most')
      expect(w.text()).not.toContain('may or may not stay')
    })
  })

  describe('survey details', () => {
    it('uses the labels the prompt gives, and the defaults otherwise', async () => {
      const plain = mountWith(surveyPrompt)
      await plain.find('[data-testid="best-give"]').trigger('click')
      expect(plain.text()).toContain('Works best for you')
      expect(plain.text()).toContain('Most needs improving')
      const follow = mountWith({
        ...surveyPrompt,
        settings: {
          best_label: 'Got in my way most',
          worst_label: 'Got in my way least (optional)',
          none_label: 'None of these has got in my way',
        },
      })
      await follow.find('[data-testid="best-give"]').trigger('click')
      expect(follow.text()).toContain('Got in my way most')
      expect(follow.text()).toContain('Got in my way least (optional)')
      expect(follow.text()).not.toContain('Works best for you')
    })

    it('words the none escape from the prompt settings', () => {
      const w = mountWith({
        ...surveyPrompt,
        settings: { none_label: 'None of these' },
      })
      expect(w.find('[data-testid="escape-none"]').text()).toBe('None of these')
    })

    it('shuffles an old-style prompt once per member when randomise is set', () => {
      const many = Array.from({ length: 7 }, (_, i) => ({
        key: `problem:${i}`,
        label: `Problem ${i}`,
      }))
      const orders = new Set()
      for (let n = 0; n < 8; n++) {
        const w = mountWith({
          ...surveyPrompt,
          options: many,
          display: undefined,
          settings: { randomise: true },
        })
        const best = w
          .findAll('[data-testid^="best-"]')
          .map((b) => b.attributes('data-testid'))
        expect([...best].sort()).toEqual(
          many.map((o) => `best-${o.key}`).sort()
        )
        orders.add(best.join('|'))
      }
      expect(orders.size).toBeGreaterThan(1)
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

  describe('the reaction to a running experiment', () => {
    const order = (w) =>
      w
        .findAll('[data-testid^="choice-"]')
        .map((b) => b.attributes('data-testid').replace('choice-', ''))

    it('shows Better first for an even user id and sends the order it showed', async () => {
      myid.value = 2
      const w = mountWith(experimentPrompt)
      expect(order(w)).toEqual([
        'better',
        'worse',
        'dont_care',
        'dont_understand',
      ])
    })

    it('swaps Better and Worse for an odd user id and keeps the last two fixed', async () => {
      myid.value = 3
      const w = mountWith(experimentPrompt)
      expect(order(w)).toEqual([
        'worse',
        'better',
        'dont_care',
        'dont_understand',
      ])
      await w.find('[data-testid="choice-better"]').trigger('click')
      await w.find('.spin-button').trigger('click')
      await flushPromises()
      expect(store.respond.mock.calls[0][0].ux_answer).toEqual({
        choice: 'better',
        shown: ['worse', 'better', 'dont_care', 'dont_understand'],
      })
    })

    it('does not touch the order when the member id is not known', () => {
      myid.value = undefined
      const w = mountWith(experimentPrompt)
      expect(order(w)[0]).toBe('better')
    })

    it('uses the labels from the prompt', () => {
      const w = mountWith(experimentPrompt)
      expect(w.find('[data-testid="choice-dont_care"]').text()).toBe(
        'Makes no difference to me'
      )
      expect(w.find('[data-testid="choice-dont_understand"]').text()).toBe(
        "I'm not sure what it's for"
      )
    })
  })

  describe('an awareness question', () => {
    const awareness = {
      id: 21,
      kind: 'awareness',
      question: 'On Freegle, is it possible to promise an item in a chat?',
      body: '',
      options: [
        { key: 'yes', label: 'Yes' },
        { key: 'no', label: 'No' },
        { key: 'not_sure', label: 'Not sure' },
      ],
      images: [],
      settings: {
        reveal: 'Yes. Tap the three dots in the chat and choose Promise.',
        link: '/chats',
        decoy: false,
      },
    }

    it('shows Yes, No and Not sure, and sends the choice with what was shown', async () => {
      const w = mountWith(awareness)
      await w.find('[data-testid="choice-no"]').trigger('click')
      await w.find('.spin-button').trigger('click')
      await flushPromises()
      expect(store.respond).toHaveBeenCalledWith({
        ux_prompt_id: 21,
        ux_answer: { choice: 'no', shown: ['yes', 'no', 'not_sure'] },
        comments: undefined,
      })
    })

    it('tells them the answer with a link and waits for Next', async () => {
      const w = mountWith(awareness)
      await w.find('[data-testid="choice-no"]').trigger('click')
      await w.find('.spin-button').trigger('click')
      await flushPromises()
      expect(w.emitted('next')).toBeUndefined()
      expect(w.find('[data-testid="reveal-text"]').text()).toBe(
        'Yes. Tap the three dots in the chat and choose Promise.'
      )
      expect(w.find('[data-testid="reveal-link"]').attributes('href')).toBe(
        '/chats'
      )
      // The question and the options are gone, and so is the Send button.
      expect(w.find('[data-testid="choice-yes"]').exists()).toBe(false)
      expect(w.find('[data-testid="submit-prompt"]').exists()).toBe(false)
      await w.find('[data-testid="reveal-next"]').trigger('click')
      expect(w.emitted('next')).toHaveLength(1)
    })

    it('drops the answering instruction once the answer is shown', async () => {
      const w = mountWith({
        ...awareness,
        body: 'Answer from what you know. Not sure is a fine answer.',
      })
      expect(w.find('.body').text()).toContain('Answer from what you know')
      await w.find('[data-testid="choice-no"]').trigger('click')
      await w.find('.spin-button').trigger('click')
      await flushPromises()
      expect(w.find('[data-testid="reveal-text"]').exists()).toBe(true)
      expect(w.find('.body').exists()).toBe(false)
    })

    it('shows no link when none is set', async () => {
      const w = mountWith({
        ...awareness,
        settings: { reveal: 'No, Freegle does not do that.' },
      })
      await w.find('[data-testid="choice-no"]').trigger('click')
      await w.find('.spin-button').trigger('click')
      await flushPromises()
      expect(w.find('[data-testid="reveal-text"]').exists()).toBe(true)
      expect(w.find('[data-testid="reveal-link"]').exists()).toBe(false)
    })

    it('does not follow a link that leaves the site', async () => {
      for (const link of [
        'https://example.com/x',
        '//example.com/x',
        'chats',
      ]) {
        const w = mountWith({
          ...awareness,
          settings: { reveal: 'Here.', link },
        })
        await w.find('[data-testid="choice-yes"]').trigger('click')
        await w.find('.spin-button').trigger('click')
        await flushPromises()
        expect(w.find('[data-testid="reveal-link"]').exists()).toBe(false)
      }
    })

    it('shows the reveal as text, never as markup', async () => {
      const w = mountWith({
        ...awareness,
        settings: { reveal: '<img src=x onerror=alert(1)>' },
      })
      await w.find('[data-testid="choice-yes"]').trigger('click')
      await w.find('.spin-button').trigger('click')
      await flushPromises()
      expect(w.find('img').exists()).toBe(false)
      expect(w.find('[data-testid="reveal-text"]').text()).toContain(
        '<img src=x'
      )
    })

    it('still reveals when the answer could not be sent', async () => {
      store.respond.mockRejectedValueOnce(new Error('down'))
      const w = mountWith(awareness)
      await w.find('[data-testid="choice-yes"]').trigger('click')
      await w.find('.spin-button').trigger('click')
      await flushPromises()
      expect(w.find('[data-testid="reveal-text"]').exists()).toBe(true)
    })

    it('moves straight on when there is nothing to reveal', async () => {
      const w = mountWith({ ...awareness, settings: {} })
      await w.find('[data-testid="choice-yes"]').trigger('click')
      await w.find('.spin-button').trigger('click')
      await flushPromises()
      expect(w.emitted('next')).toHaveLength(1)
      expect(w.find('[data-testid="reveal"]').exists()).toBe(false)
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

    it('introduces each open question by what it is for', () => {
      const intro = (preset) =>
        mountWith({ ...openPrompt, settings: { preset } })
          .find('.intro-text')
          .text()
      expect(intro('problems')).toContain('what gets in people')
      expect(intro('values')).toContain('what works well')
      expect(intro('values')).not.toContain('gets in people')
      expect(intro('improve')).toContain('most difference')
      // Every one asks them to keep personal details out.
      for (const p of ['problems', 'values', 'improve']) {
        expect(intro(p)).toContain('leave out names')
      }
    })

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
        'Nothing got in my way'
      )
    })

    it('puts the box first, then the way out, then Skip', () => {
      const w = mountWith(openPrompt)
      const html = w.html()
      const box = html.indexOf('<textarea')
      const nothing = html.indexOf('data-testid="escape-nothing"')
      const skip = html.indexOf('data-testid="skip-prompt"')
      expect(box).toBeGreaterThan(-1)
      expect(nothing).toBeGreaterThan(box)
      expect(skip).toBeGreaterThan(nothing)
      expect(w.find('[data-testid="skip-prompt"]').text()).toBe('Skip')
    })

    it('words the nothing button from the prompt settings', () => {
      const w = mountWith({
        ...openPrompt,
        options: [
          { key: 'nothing', label: 'Nothing got in my way' },
          { key: 'dont_understand', label: "Don't understand" },
        ],
        settings: { nothing_label: "Can't think of one" },
      })
      expect(w.find('[data-testid="escape-nothing"]').text()).toBe(
        "Can't think of one"
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
