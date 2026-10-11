<template>
  <div
    v-if="lineText"
    class="automod-line small text-muted d-flex align-items-center flex-wrap"
  >
    <v-icon icon="robot" class="me-1" />
    <span>{{ lineText }}</span>
    <b-button
      variant="link"
      size="sm"
      class="p-0 ms-1 align-baseline automod-line__why"
      @click="modal?.show()"
    >
      Why?
    </b-button>
    <span
      v-if="canFlagPublished && publishedFlagged"
      class="ms-2 automod-line__flagged"
      data-testid="automod-flagged"
    >
      Noted: should not have published
    </span>
    <b-button
      v-else-if="canFlagPublished"
      variant="link"
      size="sm"
      class="p-0 ms-2 align-baseline automod-line__wrong"
      data-testid="automod-flag-published"
      :disabled="flagging"
      @click="flagPublished"
    >
      Should not have published
    </b-button>
    <ModAutomodModal
      ref="modal"
      :msgid="message?.id"
      :groupid="groupid"
      :automod="automod"
    />
  </div>
</template>
<script setup>
// One muted line under a post showing what the automated review flowchart
// decided (or would have decided, in shadow mode) about it, with a link to
// the full path behind that decision. Renders nothing at all for
// communities not in the automod trial, or when this group has no automod
// data on it yet.
import { computed, ref } from 'vue'
import { useAutomod } from '@/modtools/composables/useAutoapproveTrial'
import { useMessageStore } from '@/stores/message'
import ModAutomodModal from '@/modtools/components/ModAutomodModal.vue'

const props = defineProps({
  message: {
    type: Object,
    default: null,
  },
  groupid: {
    type: Number,
    default: null,
  },
  pending: {
    type: Boolean,
    default: false,
  },
})

const { isAutomodGroup } = useAutomod()
const messageStore = useMessageStore()

const group = computed(() => {
  const groups = props.message?.groups
  if (!groups || !groups.length) return null

  return groups.find((g) => parseInt(g.groupid) === props.groupid) || null
})

const automod = computed(() => group.value?.automod || null)

const eligible = computed(
  () => isAutomodGroup(props.groupid) && !!automod.value
)

// The review did not run (service down, timeout): the batch records a hold with this end
// node so the post waits for a moderator, and retries. That is not a decision, so it must
// not read like one.
const unavailable = computed(() => automod.value?.end === 'UNAVAILABLE')

const lineText = computed(() => {
  if (!eligible.value) return ''

  const a = automod.value

  if (props.pending) {
    if (unavailable.value) {
      return 'Automated review could not run; waiting for a moderator (it will retry)'
    }

    if (a.mode === 'shadow') {
      return a.verdict === 'approve'
        ? 'Automated review: would approve'
        : `Automated review: would hold - ${a.reason}`
    }

    return a.verdict === 'approve'
      ? 'Automated review: will publish after the wait'
      : `Automated review: holding - ${a.reason}`
  }

  const approvedby = group.value?.approvedby

  if (unavailable.value) {
    return approvedby
      ? 'Approved by a moderator - automated review could not run'
      : 'Published without a moderator; automated review could not run'
  }

  if (a.verdict === 'approve' && a.mode !== 'shadow' && !approvedby) {
    return 'Auto-approved by automated review'
  }

  if (a.verdict === 'approve' && approvedby) {
    return 'Approved by a moderator - automated review agreed'
  }

  if (a.verdict === 'hold' && approvedby) {
    return `Approved by a moderator - automated review would have held: ${a.reason}`
  }

  // Live with nobody's approval on it, against a stored hold: the 48-hour fallback, or the
  // clock after a late review. This is the case the trial exists to count, so it is said
  // plainly rather than left blank.
  if (a.verdict === 'hold' && !approvedby) {
    return `Published without a moderator; automated review would have held: ${a.reason}`
  }

  if (a.mode === 'shadow' && a.verdict === 'approve' && !approvedby) {
    return 'Automated review would have approved'
  }

  return ''
})

// "Should not have published": one click on a post that went live by itself, recorded as
// feedback against the decision's end node, so a bad auto-approval is a single tap rather
// than a hunt through the path for a question to mark wrong (every answer on an approved
// post is "no"). Offered wherever the auto-approved line shows - the Check queue, and the
// Approved list the post moves on to once checked or after a week.
const canFlagPublished = computed(
  () =>
    eligible.value &&
    !props.pending &&
    group.value?.collection === 'Approved' &&
    !group.value?.approvedby &&
    automod.value.verdict === 'approve' &&
    automod.value.mode !== 'shadow' &&
    !unavailable.value
)

// The server lists the nodes this moderator has already marked (automod.feedback), so the
// state survives a reload and a second click is never a second row.
const flaggedLocally = ref(false)
const flagging = ref(false)
const publishedFlagged = computed(
  () =>
    flaggedLocally.value ||
    (automod.value?.feedback || []).includes(automod.value?.end)
)

async function flagPublished() {
  if (flagging.value || publishedFlagged.value) return
  flagging.value = true
  try {
    await messageStore.postAutomodFeedback({
      msgid: props.message?.id,
      groupid: props.groupid,
      node: automod.value.end,
    })
    flaggedLocally.value = true
  } finally {
    flagging.value = false
  }
}

const modal = ref(null)

defineExpose({
  group,
  automod,
  eligible,
  lineText,
  modal,
  canFlagPublished,
  publishedFlagged,
  flagPublished,
})
</script>
