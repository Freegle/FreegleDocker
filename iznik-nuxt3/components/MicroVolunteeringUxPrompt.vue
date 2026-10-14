<template>
  <div class="ux-prompt">
    <p class="intro-text mb-2">
      <span v-if="prompt.kind === 'survey'">
        We want to put our effort where it helps most. There is no right answer:
        tell us what you think.
      </span>
      <span v-else-if="prompt.kind === 'awareness'">
        We want to know which things people have found, and which they have not.
        Not knowing is a useful answer.
      </span>
      <span v-else-if="prompt.kind === 'open'">
        We want to find what gets in people's way, so we can fix it. There is no
        right answer. Please leave out names, addresses and phone numbers.
      </span>
      <span v-else>
        We are trying out a change. Tell us what you think of it. There is no
        right answer, and the change may or may not stay.
      </span>
    </p>

    <h2 :id="headingId" class="question header--size4 mb-2">
      {{ prompt.question }}
    </h2>
    <p v-if="prompt.body" class="body mb-3">{{ prompt.body }}</p>

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

    <template v-if="prompt.kind === 'survey'">
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
      <div class="question-block mb-3">
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
        <p :id="escapeLabelId" class="question-label">Or, if neither fits</p>
        <div
          class="option-row"
          role="group"
          :aria-labelledby="escapeLabelId"
          data-testid="group-escape"
        >
          <b-button
            v-for="opt in SURVEY_ESCAPES"
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
          placeholder="A sentence or two is plenty."
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
          v-for="opt in prompt.options"
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
      v-if="prompt.kind !== 'open'"
      label="Anything you'd like to add? (optional)"
      label-for="ux-prompt-comment"
      class="mb-3"
    >
      <b-form-textarea
        id="ux-prompt-comment"
        v-model="comment"
        rows="2"
        maxlength="1000"
        placeholder="A sentence is plenty."
      />
    </b-form-group>

    <div class="d-flex justify-content-between align-items-center mb-3">
      <b-button
        variant="link"
        size="sm"
        class="skip"
        data-testid="skip-prompt"
        :disabled="skipping"
        @click="skip"
      >
        Skip this one
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

// One question for microvolunteers: a UX experiment ("would this be better or worse for you", with
// a picture of now and of the change), a survey of what works best and worst (or, in a follow-up,
// what gets in the way most and least), an awareness check, or an open question with a free-text
// answer. The answer is a reaction gauge, never a vote that decides anything; the intro says so.
//
// Answers, as the API takes them (docs/developers/reference/ux-telemetry.md section 8):
//   experiment and awareness  {choice}
//   survey                    {best, worst} or {choice: 'dont_care' | 'dont_understand'}
//   open                      {text} (3 to 1000 characters) or {choice: 'nothing' | 'dont_understand'}
//   any kind, skipped         {skipped: true}
const props = defineProps({
  prompt: {
    type: Object,
    required: true,
  },
})

const emit = defineEmits(['next', 'skipped'])

const store = useMicroVolunteeringStore()

const MIN_TEXT = 3
const MAX_TEXT = 1000

// A survey can be answered with a best and a worst, or with one of these instead: both are
// answers the tab counts, not refusals. The wording covers both halves of the question, so
// choosing it clears any pick made so far.
const SURVEY_ESCAPES = [
  { key: 'dont_care', label: 'None of these stands out for me' },
  { key: 'dont_understand', label: "I don't understand the question" },
]

const OPEN_ESCAPES = [
  { key: 'nothing', label: 'Nothing comes to mind' },
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
const broken = reactive({})

const settings = computed(() => props.prompt.settings || {})
const bestLabel = computed(
  () => settings.value.best_label || 'Works best for you'
)
const worstLabel = computed(
  () => settings.value.worst_label || 'Most needs improving'
)

// Surveys are tallied, so the order the options come in would add a position bias: a member
// reading top to bottom favours the first. Shuffle once per member when the prompt asks for it.
function shuffled(list) {
  const out = [...list]
  for (let i = out.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1))
    ;[out[i], out[j]] = [out[j], out[i]]
  }
  return out
}

const options = ref(
  props.prompt.kind === 'survey' && props.prompt.settings?.randomise
    ? shuffled(props.prompt.options || [])
    : props.prompt.options || []
)

const openEscapes = computed(() => {
  const given = (props.prompt.options || []).filter((o) =>
    ['nothing', 'dont_understand'].includes(o.key)
  )
  return given.length ? given : OPEN_ESCAPES
})

const enough = computed(() => text.value.trim().length >= MIN_TEXT)

const complete = computed(() => {
  if (props.prompt.kind === 'survey') {
    return Boolean(
      escape.value || (best.value && worst.value && best.value !== worst.value)
    )
  }
  if (props.prompt.kind === 'open') {
    return Boolean(escape.value || enough.value)
  }
  return Boolean(choice.value)
})

// Picking a best or a worst is picking an answer, so it takes back an escape chosen earlier.
// Otherwise the escape would stay selected, look like part of the answer, and be what is sent.
function pickBest(key) {
  best.value = key
  escape.value = null
}

function pickWorst(key) {
  worst.value = key
  escape.value = null
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

async function submit(callback) {
  if (!complete.value) {
    callback?.()
    return
  }
  let answer
  if (props.prompt.kind === 'open') {
    answer = escape.value
      ? { choice: escape.value }
      : { text: text.value.trim().slice(0, MAX_TEXT) }
  } else if (props.prompt.kind !== 'survey') {
    answer = { choice: choice.value }
  } else if (escape.value) {
    answer = { choice: escape.value }
  } else {
    answer = { best: best.value, worst: worst.value }
  }
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

.image {
  flex: 1 1 14rem;
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
}

.body {
  white-space: pre-line;
}
</style>
