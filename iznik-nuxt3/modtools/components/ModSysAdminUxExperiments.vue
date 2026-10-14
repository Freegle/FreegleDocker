<template>
  <div class="ux-experiments">
    <h6 class="mb-2">
      Experiments on this funnel
      <span class="text-muted small fw-normal">
        ({{ running.length }} running, {{ finished.length }} finished,
        <template v-if="prepared.length"
          >{{ prepared.length }} prepared, </template
        >{{ ideas.length }} ideas left)
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
        <ModSysAdminUxSaid
          v-if="e.reaction?.comments?.length"
          :comments="e.reaction.comments"
          :label="`What members said about this (${e.reaction.comments.length})`"
        />
        <template v-if="pending(e) === 'stop'">
          <b-button size="sm" variant="outline-danger" class="stop" disabled>
            Stop requested
          </b-button>
          <span class="small text-muted ms-2 d-block d-sm-inline">
            The batch applies it within the hour; this card changes when it has.
          </span>
        </template>
        <template v-else-if="confirming === 'stop:' + e.id">
          <span class="small me-2">
            Stop "{{ e.title }}" now? Everyone goes back to the old version.
          </span>
          <b-button
            size="sm"
            variant="danger"
            class="confirm-stop me-1"
            :disabled="busy[e.id]"
            @click="act(e, 'stop')"
          >
            Yes, stop it
          </b-button>
          <b-button
            size="sm"
            variant="outline-secondary"
            class="cancel"
            @click="confirming = null"
          >
            Keep it running
          </b-button>
        </template>
        <b-button
          v-else
          size="sm"
          variant="outline-danger"
          class="stop"
          @click="confirming = 'stop:' + e.id"
        >
          Stop now
        </b-button>
        <div v-if="errors[e.id]" class="small text-danger mt-1">
          {{ errors[e.id] }}
        </div>
      </div>
    </div>

    <div v-if="history.length" class="mb-3">
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
          <ModSysAdminUxSaid
            v-if="item.comments.length"
            :comments="item.comments"
            :label="`What members said about this (${item.comments.length})`"
          />
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
          <template v-if="pending(item)">
            <b-button size="sm" variant="outline-secondary" disabled>
              {{ REQUESTED_LABEL[pending(item)] }}
            </b-button>
          </template>
          <b-button
            v-else-if="item.status === 'draft'"
            size="sm"
            variant="outline-primary"
            class="start"
            :disabled="busy[item.id] || full"
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
            :disabled="busy[item.id]"
            title="Say that the switch has been removed from the code"
            @click="act(item, 'close')"
          >
            Mark as cleaned up
          </b-button>
          <div v-if="errors[item.id]" class="small text-danger mt-1">
            {{ errors[item.id] }}
          </div>
        </template>
      </b-table>
    </div>
    <p v-if="!running.length && !finished.length" class="small text-muted mb-3">
      Nothing has been tried on this funnel yet.
    </p>

    <div v-if="looseIdeas.length" class="ideas">
      <h6 class="mb-1">Other ideas, best first</h6>
      <ol class="small mb-0 ps-3">
        <li v-for="i in looseIdeas" :key="i.id" class="mb-1">
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
    <p v-else-if="!ideas.length" class="small text-muted mb-0">
      No ideas waiting for this funnel.
    </p>
  </div>
</template>

<script setup>
// The per-funnel experiment story: what is running (with the latest hourly read), what ran and
// how it ended (one plain-English sentence each, with what members said), and the ideas left.
// Starting, stopping and closing go to the batch through the store; it applies the rules.
import { computed, reactive, ref } from 'vue'
import { useUxStore } from '~/modtools/stores/ux'
import ModSysAdminUxSaid from '~/modtools/components/ModSysAdminUxSaid.vue'

const props = defineProps({
  // One entry of the experiments response's flows: { flow, running, history, ideas }
  flowData: { type: Object, required: true },
  // True when the cap of running experiments is reached.
  full: { type: Boolean, default: false },
})

const store = useUxStore()
// Per experiment, so one click does not freeze every card, and so the answer appears on the card
// that was clicked rather than at the bottom of the section.
const busy = reactive({})
const errors = reactive({})
// "id:status" -> the action asked for. It holds while the experiment is still in that status, so a
// second click cannot queue a duplicate, and lets go by itself once the batch has changed it.
const requested = reactive({})
const pending = (e) => requested[`${e.id}:${e.status}`] || null
const confirming = ref(null)

const REQUESTED_LABEL = {
  start: 'Start requested',
  stop: 'Stop requested',
  close: 'Cleanup noted',
}

const running = computed(() => props.flowData.running || [])
const history = computed(() => props.flowData.history || [])
// The history also holds drafts, which have not started: they are counted as prepared, not as
// something that finished.
const finished = computed(() =>
  history.value.filter((e) => e.status !== 'draft')
)
const prepared = computed(() =>
  history.value.filter((e) => e.status === 'draft')
)
const ideas = computed(() => props.flowData.ideas || [])
// Ideas that belong to a problem are shown under it (ModSysAdminUxProblems), not twice.
const problemIds = computed(
  () => new Set((props.flowData.problems || []).map((p) => p.id))
)
const looseIdeas = computed(() =>
  ideas.value.filter(
    (i) => !i.problem_id || !problemIds.value.has(i.problem_id)
  )
)

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
    comments: e.reaction?.comments || [],
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
  busy[e.id] = true
  errors[e.id] = null
  try {
    await store.experimentAction(e.id, { action })
    requested[`${e.id}:${e.status}`] = action
    confirming.value = null
  } catch (err) {
    errors[e.id] = err?.message || 'That did not work.'
  } finally {
    busy[e.id] = false
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
