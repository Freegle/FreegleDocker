<template>
  <div class="ux-experiments">
    <h6 class="mb-2">
      Experiments on this funnel
      <span class="text-muted small fw-normal">
        ({{ running.length }} running, {{ decided.length }} finished,
        {{ ideas.length }} ideas left)
      </span>
    </h6>

    <div v-if="running.length" class="mb-3">
      <div
        v-for="e in running"
        :key="e.id"
        class="experiment running border rounded p-2 mb-2"
      >
        <div class="d-flex justify-content-between flex-wrap gap-2">
          <strong>{{ e.title }}</strong>
          <span class="text-muted small">
            Running {{ hours(e.hours) }}, ends {{ e.ends_at }} UTC.
            <a v-if="e.pr_url" :href="e.pr_url" target="_blank" rel="noopener"
              >PR</a
            >
          </span>
        </div>
        <p class="small mb-1">{{ e.change }}</p>
        <p v-if="e.latest_read" class="small mb-1 read">
          {{ e.latest_read.summary }}
        </p>
        <p v-else class="small text-muted mb-1">
          No read yet; the first comes within the hour.
        </p>
        <p class="small mb-1">{{ reactionText(e.reaction) }}</p>
        <b-button
          size="sm"
          variant="outline-danger"
          class="stop"
          :disabled="busy"
          @click="act(e, 'stop')"
        >
          Stop now
        </b-button>
      </div>
    </div>

    <div v-if="decided.length" class="mb-3">
      <b-table
        :items="historyRows"
        :fields="historyFields"
        small
        responsive
        stacked="md"
      >
        <template #cell(result)="{ item }">
          <span>{{ item.result }}</span>
          <div v-if="item.reaction" class="text-muted">{{ item.reaction }}</div>
          <div v-if="item.cleanupOverdue" class="text-danger">
            Adopted but its switch is still in the code: cleanup overdue.
          </div>
        </template>
        <template #cell(actions)="{ item }">
          <a
            v-if="item.pr_url"
            :href="item.pr_url"
            target="_blank"
            rel="noopener"
            class="me-2"
            >PR</a
          >
          <a
            v-if="item.cleanup_pr_url"
            :href="item.cleanup_pr_url"
            target="_blank"
            rel="noopener"
            class="me-2"
            >Cleanup</a
          >
          <b-button
            v-if="item.status === 'draft'"
            size="sm"
            variant="outline-primary"
            class="start"
            :disabled="busy || full"
            :title="full ? 'Already at the cap of running experiments' : ''"
            @click="act(item, 'start')"
          >
            Start
          </b-button>
          <b-button
            v-else-if="item.status === 'adopted'"
            size="sm"
            variant="outline-secondary"
            class="close"
            :disabled="busy"
            @click="act(item, 'close')"
          >
            Cleaned up
          </b-button>
        </template>
      </b-table>
    </div>
    <p v-else-if="!running.length" class="small text-muted mb-3">
      Nothing has been tried on this funnel yet.
    </p>

    <div v-if="ideas.length" class="ideas">
      <h6 class="mb-1">Ideas left, best first</h6>
      <ol class="small mb-0 ps-3">
        <li v-for="i in ideas" :key="i.id" class="mb-1">
          <strong>{{ i.title }}</strong>
          <span class="text-muted"
            >(score {{ Number(i.score).toFixed(1) }},
            {{ sourceLabel(i.source) }})</span
          >
          <div>{{ i.rationale }}</div>
          <div class="text-muted">Expected: {{ i.expected }}</div>
        </li>
      </ol>
    </div>
    <p v-else class="small text-muted mb-0">
      No ideas waiting for this funnel.
    </p>

    <NoticeMessage v-if="error" variant="danger" class="mt-2">{{
      error
    }}</NoticeMessage>
    <NoticeMessage v-if="queued" variant="info" class="mt-2">{{
      queued
    }}</NoticeMessage>
  </div>
</template>

<script setup>
// The per-funnel experiment story: what is running (with the latest hourly read), what ran and
// how it ended (one plain-English sentence each, with what members said), and the ideas left.
// Starting, stopping and closing go to the batch through the store; it applies the rules.
import { computed, ref } from 'vue'
import { useUxStore } from '~/modtools/stores/ux'

const props = defineProps({
  // One entry of the experiments response's flows: { flow, running, history, ideas }
  flowData: { type: Object, required: true },
  // True when the cap of running experiments is reached.
  full: { type: Boolean, default: false },
})

const store = useUxStore()
const busy = ref(false)
const error = ref(null)
const queued = ref(null)

const running = computed(() => props.flowData.running || [])
const history = computed(() => props.flowData.history || [])
const decided = computed(() => history.value)
const ideas = computed(() => props.flowData.ideas || [])

const historyFields = [
  { key: 'when', label: 'When' },
  { key: 'title', label: 'What we tried' },
  { key: 'outcome', label: 'Outcome' },
  { key: 'result', label: 'What happened' },
  { key: 'actions', label: '' },
]

const OUTCOME = {
  draft: 'Not started',
  adopted: 'Kept',
  closed: 'Kept and cleaned up',
  reverted: 'Taken off',
  inconclusive: 'Could not tell, taken off',
  stopped: 'Stopped by hand',
}

const historyRows = computed(() =>
  history.value.map((e) => ({
    id: e.id,
    status: e.status,
    when: e.started_at ? e.started_at.slice(0, 10) : 'draft',
    title: e.title,
    outcome: OUTCOME[e.status] || e.status,
    result: e.decision || e.change,
    reaction: e.reaction?.total ? reactionText(e.reaction) : '',
    cleanupOverdue: e.cleanup_overdue,
    pr_url: e.pr_url,
    cleanup_pr_url: e.cleanup_pr_url,
  }))
)

function hours(h) {
  if (h === null || h === undefined) return ''
  return h < 1
    ? `${Math.round(h * 60)} minutes`
    : `${Number(h).toFixed(1)} hours`
}

function reactionText(r) {
  if (!r || !r.total) return 'Members asked: no answers yet.'
  return `Members asked (${r.total}): ${r.better} better, ${r.worse} worse, ${r.dont_care} don't care, ${r.dont_understand} didn't understand.`
}

function sourceLabel(s) {
  return (
    {
      ai: 'suggested by the loop',
      survey: 'from the survey',
      human: 'from a person',
    }[s] || s
  )
}

async function act(e, action) {
  busy.value = true
  error.value = null
  queued.value = null
  try {
    await store.experimentAction(e.id, { action })
    queued.value = `${action === 'start' ? 'Start' : action === 'stop' ? 'Stop' : 'Close'} requested; the batch applies it within a minute. Reload to see it.`
  } catch (err) {
    error.value = err?.message || 'That did not work.'
  } finally {
    busy.value = false
  }
}
</script>

<style scoped lang="scss">
.running {
  background: #f6fbf2;
}

.read {
  white-space: pre-line;
}
</style>
