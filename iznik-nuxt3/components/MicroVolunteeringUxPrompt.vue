<template>
  <div class="ux-prompt">
    <p v-if="!revealing" class="intro-text mb-2">
      <span v-if="prompt.kind === 'survey'">
        We want to work on what matters most. There is no right answer. Tell us
        what you think.
      </span>
      <span v-else-if="prompt.kind === 'awareness'">
        We want to know which things people know about. It's fine not to know.
      </span>
      <span v-else-if="prompt.kind === 'open'">
        <template v-if="prompt.settings?.preset === 'values'">
          We want to know what works well, so we keep it when we change things.
        </template>
        <template v-else-if="prompt.settings?.preset === 'improve'">
          We want to know what would make the most difference.
        </template>
        <template v-else>
          We want to find the problems people have, so we can fix them.
        </template>
        There is no right answer. Please leave out names, addresses and phone
        numbers.
      </span>
      <span v-else>
        We are trying out a change. Tell us what you think of it. There is no
        right answer, and the change may or may not stay.
      </span>
    </p>

    <h2 :id="headingId" class="question header--size4 mb-2">
      {{ prompt.question }}
    </h2>
    <p v-if="prompt.body && !revealing" class="body mb-3">
      {{ prompt.body }}
    </p>

    <div v-if="prompt.images?.length" class="images mb-3">
      <figure v-for="(img, i) in prompt.images" :key="i" class="image m-0">
        <!-- The words above describe the change, so the picture is decoration for a screen reader
             and the caption says which picture it is. Reading both would say it twice. -->
        <img
          v-show="!broken[i]"
          :src="img.url"
          alt=""
          class="prompt-image"
          loading="lazy"
          @error="brokenImage($event, i)"
        />
        <p v-if="broken[i]" class="small text-muted broken-image mb-0">
          The picture would not load. The words above describe it, so you can
          still answer, or skip this one.
        </p>
        <figcaption class="small text-muted mt-1">{{ img.caption }}</figcaption>
      </figure>
    </div>

    <div v-if="revealing" class="reveal mb-3" data-testid="reveal">
      <!-- Plain text from the prompt's settings: interpolated, so escaped. -->
      <p class="reveal-text" data-testid="reveal-text">{{ revealText }}</p>
      <p v-if="revealLink" class="mb-3">
        <nuxt-link :to="revealLink" data-testid="reveal-link">
          Show me
        </nuxt-link>
      </p>
      <b-button
        variant="success"
        data-testid="reveal-next"
        @click="emit('next')"
      >
        Next
      </b-button>
    </div>

    <template v-else-if="prompt.kind === 'survey'">
      <div class="question-block mb-3">
        <p :id="bestLabelId" class="question-label">{{ bestLabel }}</p>
        <div
          class="option-row"
          role="group"
          :aria-labelledby="bestLabelId"
          data-testid="group-best"
        >
          <b-button
            v-for="opt in options"
            :key="'best-' + opt.key"
            :data-testid="`best-${opt.key}`"
            :variant="best === opt.key ? 'primary' : 'outline-primary'"
            :aria-pressed="best === opt.key ? 'true' : 'false'"
            :disabled="worst === opt.key"
            @click="pickBest(opt.key)"
          >
            {{ opt.label }}
          </b-button>
        </div>
      </div>
      <!-- The second tap is optional, and only makes sense once there is a first. -->
      <div v-if="best" class="question-block mb-3">
        <p :id="worstLabelId" class="question-label">{{ worstLabel }}</p>
        <div
          class="option-row"
          role="group"
          :aria-labelledby="worstLabelId"
          data-testid="group-worst"
        >
          <b-button
            v-for="opt in options"
            :key="'worst-' + opt.key"
            :data-testid="`worst-${opt.key}`"
            :variant="worst === opt.key ? 'primary' : 'outline-primary'"
            :aria-pressed="worst === opt.key ? 'true' : 'false'"
            :disabled="best === opt.key"
            @click="pickWorst(opt.key)"
          >
            {{ opt.label }}
          </b-button>
        </div>
      </div>
      <div class="question-block mb-3">
        <p :id="escapeLabelId" class="question-label">
          Or, if none of these fits
        </p>
        <div
          class="option-row"
          role="group"
          :aria-labelledby="escapeLabelId"
          data-testid="group-escape"
        >
          <b-button
            v-for="opt in surveyEscapes"
            :key="opt.key"
            :data-testid="`escape-${opt.key}`"
            :variant="escape === opt.key ? 'primary' : 'outline-secondary'"
            :aria-pressed="escape === opt.key ? 'true' : 'false'"
            @click="pickEscape(opt.key)"
          >
            {{ opt.label }}
          </b-button>
        </div>
      </div>
    </template>

    <template v-else-if="prompt.kind === 'open'">
      <div class="question-block mb-2">
        <b-form-textarea
          :id="textId"
          v-model="text"
          data-testid="open-text"
          rows="4"
          :maxlength="MAX_TEXT"
          :aria-labelledby="headingId"
          :aria-describedby="countId"
          @update:model-value="typed"
        />
        <p
          :id="countId"
          class="small text-muted mt-1 mb-0"
          data-testid="open-count"
        >
          {{ text.length }} of {{ MAX_TEXT }} characters<span
            v-if="text.length && !enough"
          >
            (please write at least {{ MIN_TEXT }})</span
          >
        </p>
      </div>
      <div class="question-block mb-3">
        <p :id="escapeLabelId" class="question-label">Or</p>
        <div
          class="option-row"
          role="group"
          :aria-labelledby="escapeLabelId"
          data-testid="group-escape"
        >
          <b-button
            v-for="opt in openEscapes"
            :key="opt.key"
            :data-testid="`escape-${opt.key}`"
            :variant="escape === opt.key ? 'primary' : 'outline-secondary'"
            :aria-pressed="escape === opt.key ? 'true' : 'false'"
            @click="pickOpenEscape(opt.key)"
          >
            {{ opt.label }}
          </b-button>
        </div>
      </div>
    </template>

    <div v-else class="question-block mb-3">
      <div class="option-row" role="group" :aria-labelledby="headingId">
        <b-button
          v-for="opt in choiceOptions"
          :key="opt.key"
          :data-testid="`choice-${opt.key}`"
          :variant="choice === opt.key ? 'primary' : 'outline-primary'"
          :aria-pressed="choice === opt.key ? 'true' : 'false'"
          @click="choice = opt.key"
        >
          {{ opt.label }}
        </b-button>
      </div>
    </div>

    <b-form-group
      v-if="prompt.kind !== 'open' && !revealing"
      label="Anything you'd like to add? (optional)"
      label-for="ux-prompt-comment"
      class="mb-3"
    >
      <b-form-textarea
        id="ux-prompt-comment"
        v-model="comment"
        rows="2"
        maxlength="1000"
        placeholder="One sentence is enough."
      />
    </b-form-group>

    <div
      v-if="!revealing"
      class="d-flex justify-content-between align-items-center mb-3"
    >
      <b-button
        variant="link"
        size="sm"
        class="skip"
        data-testid="skip-prompt"
        :disabled="skipping"
        @click="skip"
      >
        {{ prompt.kind === 'open' ? 'Skip' : 'Skip this one' }}
      </b-button>
      <SpinButton
        data-testid="submit-prompt"
        variant="success"
        icon-name="check"
        label="Send"
        :disabled="!complete"
        @handle="submit"
      />
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed } from 'vue'
import SpinButton from './SpinButton'
import { useMicroVolunteeringStore } from '~/stores/microvolunteering'
import { useMe } from '~/composables/useMe'

// One question for microvolunteers: a reaction to a running experiment, a survey of which problems
// were the biggest (or which areas work best), an awareness check, or an open question with a
// free-text answer. The answer is a gauge, never a vote that decides anything; the intro says so.
//
// Answers, as the API takes them (docs/developers/reference/ux-telemetry.md section 8):
//   experiment    {choice, shown}
//   awareness     {choice: 'yes' | 'no' | 'not_sure', shown}
//   survey        {best, worst?, shown} or {choice: 'none', shown} or {choice: 'dont_understand'}
//   open          {text} (3 to 1000 characters) or {choice: 'nothing' | 'dont_understand'}
//   any kind, skipped  {skipped: true}
// "shown" is the option keys in the order this member saw them, so the tab can say how often each
// was on screen. The server decides which few options a survey shows (prompt.display); this
// component shows exactly those, in that order.
const props = defineProps({
  prompt: {
    type: Object,
    required: true,
  },
})

const emit = defineEmits(['next', 'skipped'])

const store = useMicroVolunteeringStore()
const { myid } = useMe()

const MIN_TEXT = 3
const MAX_TEXT = 1000

const DEFAULT_NONE_LABEL = 'None of these has been a problem for me'
const DEFAULT_NOTHING_LABEL = 'No problems'

const OPEN_ESCAPES = [
  { key: 'nothing', label: DEFAULT_NOTHING_LABEL },
  { key: 'dont_understand', label: "I don't understand the question" },
]

const idBase = `ux-prompt-${props.prompt.id ?? 'x'}`
const headingId = `${idBase}-question`
const bestLabelId = `${idBase}-best`
const worstLabelId = `${idBase}-worst`
const escapeLabelId = `${idBase}-escape`
const textId = `${idBase}-text`
const countId = `${idBase}-count`

const choice = ref(null)
const best = ref(null)
const worst = ref(null)
const escape = ref(null)
const comment = ref('')
const text = ref('')
const skipping = ref(false)
const revealing = ref(false)
const broken = reactive({})

const settings = computed(() => props.prompt.settings || {})
const bestLabel = computed(
  () => settings.value.best_label || 'Works best for you'
)
const worstLabel = computed(
  () => settings.value.worst_label || 'Most needs improving (optional)'
)

// A survey is tallied, so the order the options come in would add a position bias: a member
// reading top to bottom favours the first. The server sends the few to show (display) already in
// a random order. An older prompt without display falls back to every option, shuffled once when
// it asks for that.
function shuffled(list) {
  const out = [...list]
  for (let i = out.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1))
    ;[out[i], out[j]] = [out[j], out[i]]
  }
  return out
}

const options = ref(surveyOptions())

function surveyOptions() {
  const all = props.prompt.options || []
  if (props.prompt.kind !== 'survey') return all
  const display = props.prompt.display
  if (Array.isArray(display) && display.length) {
    const byKey = new Map(all.map((o) => [o.key, o]))
    return display.map((k) => byKey.get(k)).filter(Boolean)
  }
  return props.prompt.settings?.randomise ? shuffled(all) : all
}

// Better and Worse swap places for members with an odd id, so being first is not always Better.
// The two options after them stay put.
const choiceOptions = computed(() => {
  const list = [...(props.prompt.options || [])]
  const id = Number(myid.value)
  const b = list.findIndex((o) => o.key === 'better')
  const w = list.findIndex((o) => o.key === 'worse')
  if (props.prompt.kind === 'experiment' && b >= 0 && w >= 0 && id % 2 === 1) {
    ;[list[b], list[w]] = [list[w], list[b]]
  }
  return list
})

const surveyEscapes = computed(() => [
  { key: 'none', label: settings.value.none_label || DEFAULT_NONE_LABEL },
  { key: 'dont_understand', label: "I don't understand the question" },
])

const openEscapes = computed(() => {
  const given = (props.prompt.options || []).filter((o) =>
    ['nothing', 'dont_understand'].includes(o.key)
  )
  const list = given.length ? given : OPEN_ESCAPES
  // The preset says in its settings what "nothing" is called ("Can't think of one" for values).
  return list.map((o) =>
    o.key === 'nothing' && settings.value.nothing_label
      ? { ...o, label: settings.value.nothing_label }
      : o
  )
})

const enough = computed(() => text.value.trim().length >= MIN_TEXT)

const complete = computed(() => {
  if (props.prompt.kind === 'survey') {
    // A worst is optional; a best (or one of the two ways out) is the answer.
    return Boolean(escape.value || best.value)
  }
  if (props.prompt.kind === 'open') {
    return Boolean(escape.value || enough.value)
  }
  return Boolean(choice.value)
})

// The key list sent as "shown": what was on screen, in screen order.
function shownKeys() {
  const list =
    props.prompt.kind === 'survey' ? options.value : choiceOptions.value
  return list.map((o) => o.key)
}

// After answering an awareness question the member is told the answer. Only a site path is
// followed (settings come from an operator, but a link off the site has no business here).
const revealText = computed(() => String(settings.value.reveal || ''))
const revealLink = computed(() => {
  const l = settings.value.link
  return typeof l === 'string' && /^\/(?!\/)/.test(l) ? l : ''
})

// Picking a best is picking an answer, so it takes back an escape chosen earlier. Otherwise the
// escape would stay selected, look like part of the answer, and be what is sent.
function pickBest(key) {
  best.value = key
  escape.value = null
}

// The second tap can be taken back, because it is optional.
function pickWorst(key) {
  worst.value = worst.value === key ? null : key
}

function pickEscape(key) {
  escape.value = escape.value === key ? null : key
  if (escape.value) {
    best.value = null
    worst.value = null
  }
}

function pickOpenEscape(key) {
  escape.value = escape.value === key ? null : key
  if (escape.value) text.value = ''
}

// Typing is an answer, so it takes back an escape chosen earlier.
function typed() {
  if (text.value) escape.value = null
}

function buildAnswer() {
  if (props.prompt.kind === 'open') {
    return escape.value
      ? { choice: escape.value }
      : { text: text.value.trim().slice(0, MAX_TEXT) }
  }
  if (props.prompt.kind !== 'survey') {
    return { choice: choice.value, shown: shownKeys() }
  }
  if (escape.value === 'dont_understand') return { choice: 'dont_understand' }
  if (escape.value) return { choice: escape.value, shown: shownKeys() }
  const answer = { best: best.value, shown: shownKeys() }
  if (worst.value && worst.value !== best.value) answer.worst = worst.value
  return answer
}

async function submit(callback) {
  if (!complete.value) {
    callback?.()
    return
  }
  const answer = buildAnswer()
  try {
    await store.respond({
      ux_prompt_id: props.prompt.id,
      ux_answer: answer,
      comments:
        props.prompt.kind === 'open'
          ? undefined
          : comment.value.trim() || undefined,
    })
  } catch (e) {
    // The answer is a gauge, not a vote: losing one is fine, and the API layer has already
    // reported the failure. Do not leave the member stuck on a question that cannot be sent.
  } finally {
    callback?.()
  }
  if (props.prompt.kind === 'awareness' && revealText.value) {
    // Tell them the answer; Next moves on.
    revealing.value = true
    return
  }
  emit('next')
}

// Skipping is recorded, so the same question is not offered straight back. If it were not, the
// picker would hand out the identical prompt again, every time, ahead of the real review work.
async function skip() {
  if (skipping.value) return
  skipping.value = true
  try {
    await store.respond({
      ux_prompt_id: props.prompt.id,
      ux_answer: { skipped: true },
    })
  } catch (e) {
    // Moving on matters more than the record.
  }
  emit('skipped')
  emit('next')
}

function brokenImage(event, i) {
  event.target.style.display = 'none'
  broken[i] = true
}
</script>

<style scoped lang="scss">
.images {
  display: flex;
  gap: 1rem;
  flex-wrap: wrap;
}

// Narrow enough that "how it is now" and "the change" sit side by side on a phone, so they can be
// compared without scrolling between them.
.image {
  flex: 1 1 8rem;
  max-width: 24rem;
}

.prompt-image {
  width: 100%;
  border: 1px solid #ced4da;
  border-radius: 0.5rem;
}

.broken-image {
  border: 1px dashed #ced4da;
  border-radius: 0.5rem;
  padding: 1rem;
}

.question-label {
  font-weight: bold;
  margin-bottom: 0.5rem;
}

.option-row {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;

  // A problem's title can be most of a sentence; on a phone it must wrap inside the button
  // rather than run off the edge of the screen. assets/css/buttons.scss keeps every button on
  // one line with !important, so this needs it too.
  :deep(.btn) {
    white-space: normal !important;
    text-align: start;
    max-width: 100%;
  }
}

.body {
  white-space: pre-line;
}
</style>
