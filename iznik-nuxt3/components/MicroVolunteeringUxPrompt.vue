<template>
  <div class="ux-prompt">
    <p class="intro-text mb-2">
      <span v-if="prompt.kind === 'survey'">
        We want to put our effort where it helps most. There is no right
        answer: tell us what you think.
      </span>
      <span v-else-if="prompt.kind === 'awareness'">
        We want to know which things people have found, and which they have
        not. Not knowing is a useful answer.
      </span>
      <span v-else>
        We are trying out a change. Tell us what you think of it. There is no
        right answer, and the change may or may not stay.
      </span>
    </p>

    <h2 class="question header--size4 mb-2">{{ prompt.question }}</h2>
    <p class="body mb-3">{{ prompt.body }}</p>

    <div v-if="prompt.images?.length" class="images mb-3">
      <figure v-for="(img, i) in prompt.images" :key="i" class="image m-0">
        <img
          :src="img.url"
          :alt="img.caption"
          class="prompt-image"
          loading="lazy"
          @error="brokenImage"
        />
        <figcaption class="small text-muted mt-1">{{ img.caption }}</figcaption>
      </figure>
    </div>

    <template v-if="prompt.kind === 'survey'">
      <div class="question-block mb-3">
        <p class="question-label">Works best for you</p>
        <div class="option-row">
          <b-button
            v-for="opt in prompt.options"
            :key="'best-' + opt.key"
            :data-testid="`best-${opt.key}`"
            :variant="best === opt.key ? 'primary' : 'outline-primary'"
            :disabled="worst === opt.key"
            @click="best = opt.key"
          >
            {{ opt.label }}
          </b-button>
        </div>
      </div>
      <div class="question-block mb-3">
        <p class="question-label">Most needs improving</p>
        <div class="option-row">
          <b-button
            v-for="opt in prompt.options"
            :key="'worst-' + opt.key"
            :data-testid="`worst-${opt.key}`"
            :variant="worst === opt.key ? 'primary' : 'outline-primary'"
            :disabled="best === opt.key"
            @click="worst = opt.key"
          >
            {{ opt.label }}
          </b-button>
        </div>
      </div>
      <div class="question-block mb-3">
        <p class="question-label">Or, if neither fits</p>
        <div class="option-row">
          <b-button
            v-for="opt in SURVEY_ESCAPES"
            :key="opt.key"
            :data-testid="`escape-${opt.key}`"
            :variant="escape === opt.key ? 'primary' : 'outline-secondary'"
            @click="pickEscape(opt.key)"
          >
            {{ opt.label }}
          </b-button>
        </div>
      </div>
    </template>
    <div v-else class="question-block mb-3">
      <div class="option-row">
        <b-button
          v-for="opt in prompt.options"
          :key="opt.key"
          :data-testid="`choice-${opt.key}`"
          :variant="choice === opt.key ? 'primary' : 'outline-primary'"
          @click="choice = opt.key"
        >
          {{ opt.label }}
        </b-button>
      </div>
    </div>

    <b-form-group
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
import { ref, computed } from 'vue'
import SpinButton from './SpinButton'
import { useMicroVolunteeringStore } from '~/stores/microvolunteering'

// One question for microvolunteers about a UX experiment ("would this be better or worse for
// you", with a picture of now and of the change) or a survey of what works best and worst. The
// answer is a reaction gauge, never a vote that decides anything; the intro says so.
const props = defineProps({
  prompt: {
    type: Object,
    required: true,
  },
})

const emit = defineEmits(['next'])

const store = useMicroVolunteeringStore()

// A survey can be answered with a best and a worst, or with one of these instead: both are
// answers the tab counts, not refusals.
const SURVEY_ESCAPES = [
  { key: 'dont_care', label: "None of these bother me" },
  { key: 'dont_understand', label: "I don't understand the question" },
]

const choice = ref(null)
const best = ref(null)
const worst = ref(null)
const escape = ref(null)
const comment = ref('')

const complete = computed(() =>
  props.prompt.kind === 'survey'
    ? Boolean(
        escape.value ||
          (best.value && worst.value && best.value !== worst.value)
      )
    : Boolean(choice.value)
)

function pickEscape(key) {
  escape.value = escape.value === key ? null : key
  if (escape.value) {
    best.value = null
    worst.value = null
  }
}

async function submit(callback) {
  if (!complete.value) {
    callback?.()
    return
  }
  let answer
  if (props.prompt.kind !== 'survey') {
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
      comments: comment.value.trim() || undefined,
    })
  } finally {
    callback?.()
  }
  emit('next')
}

function skip() {
  emit('next')
}

function brokenImage(event) {
  event.target.style.display = 'none'
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
