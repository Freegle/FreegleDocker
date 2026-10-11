<template>
  <b-modal
    ref="modal"
    title="Why automated review decided this"
    size="md"
    hide-footer
  >
    <div v-if="automod">
      <p class="mb-2">
        <strong>{{ summary }}</strong>
      </p>
      <p class="small text-muted mb-3">
        The questions below were asked in this order; the first answered yes
        holds a post. Chart version {{ automod.version
        }}<span v-if="ranAt"> &middot; ran {{ ranAt }}</span>
      </p>
      <div
        v-for="(node, index) in automod.path"
        :key="index"
        class="automod-node"
        :class="
          isDeciding(index)
            ? 'automod-node--deciding border-start border-3 border-warning ps-2 mb-3'
            : 'mb-2 small'
        "
      >
        <div class="d-flex justify-content-between align-items-baseline">
          <span :class="{ 'fw-bold': isDeciding(index) }">{{
            node.question
          }}</span>
          <span class="ms-2 text-nowrap">
            <strong>{{ node.answer === 'yes' ? 'Yes' : 'No' }}</strong>
            <span v-if="feedbackSent[node.node]" class="small text-muted ms-1"
              >Thanks, noted</span
            >
            <b-button
              v-else
              v-b-tooltip.hover
              variant="link"
              size="sm"
              class="p-0 ms-1 small align-baseline"
              title="This step is wrong"
              :disabled="sending[node.node]"
              @click="sendFeedback(node)"
            >
              wrong?
            </b-button>
          </span>
        </div>
        <div
          v-if="node.kind === 'text' && node.p !== null && node.p !== undefined"
          class="text-muted"
        >
          {{ Math.round(node.p * 100) }}% sure, holds above
          {{ Math.round(node.threshold * 100) }}%
        </div>
        <!-- A quote from the post for a question the model answered; a finding, in plain words, for a fact. -->
        <div v-if="node.evidence && node.kind === 'text'" class="fst-italic">
          "{{ node.evidence }}"
        </div>
        <div v-else-if="node.evidence" class="text-muted">
          {{ node.evidence }}
        </div>
        <div v-if="isDeciding(index)" class="small text-muted">
          This step decided the outcome. Answered by {{ node.model }}.
        </div>
      </div>
    </div>
  </b-modal>
</template>
<script setup>
// Full detail behind an automated review decision: the questions the flowchart
// asked, in order, what it decided at each one, and how sure it was. Always
// mounted (behind the line that owns it) and shown only when triggered via
// its exposed show().
import { computed, reactive, watch } from 'vue'
import { useMessageStore } from '@/stores/message'
import { useOurModal } from '@/composables/useOurModal'

const props = defineProps({
  msgid: {
    type: Number,
    default: null,
  },
  groupid: {
    type: Number,
    default: null,
  },
  automod: {
    type: Object,
    default: null,
  },
})

const messageStore = useMessageStore()

const { modal, show, hide } = useOurModal({ autoShow: false })

// One line saying what happened, before the detail.
const summary = computed(() => {
  const a = props.automod
  if (!a) return ''
  const shadow = a.mode === 'shadow'
  if (a.verdict === 'hold') {
    return (shadow ? 'Would hold: ' : 'Held: ') + a.reason
  }
  return shadow
    ? 'Would approve: no question below needed a moderator.'
    : 'Approved: no question below needed a moderator.'
})

const ranAt = computed(() => {
  if (!props.automod?.created) return null
  return new Date(props.automod.created).toLocaleString()
})

// A hold stops at the first question answered yes, so the last node on a held
// post's path is the one that held it. An approved post passed every question and
// has no single deciding node.
const decidingIndex = computed(() => {
  const path = props.automod?.path
  if (props.automod?.verdict !== 'hold' || !path || !path.length) return -1
  return path.length - 1
})

function isDeciding(index) {
  return index === decidingIndex.value
}

// Keyed by node id, not by position in the path, and seeded from automod.feedback - the
// nodes this moderator has already marked, which the server sends back - so a mark
// survives a reload and cannot be posted twice from here.
const feedbackSent = reactive({})
const sending = reactive({})

watch(
  () => props.automod?.feedback,
  (marked) => {
    for (const node of marked || []) {
      feedbackSent[node] = true
    }
  },
  { immediate: true }
)

async function sendFeedback(node) {
  const id = node.node
  if (feedbackSent[id] || sending[id]) return
  sending[id] = true
  try {
    // {already: true} means the server had it from this moderator already, which
    // is the same thing as far as the moderator is concerned: it is marked.
    await messageStore.postAutomodFeedback({
      msgid: props.msgid,
      groupid: props.groupid,
      node: id,
    })
    feedbackSent[id] = true
  } finally {
    sending[id] = false
  }
}

defineExpose({
  summary,
  modal,
  show,
  hide,
  ranAt,
  decidingIndex,
  isDeciding,
  feedbackSent,
  sendFeedback,
})
</script>
