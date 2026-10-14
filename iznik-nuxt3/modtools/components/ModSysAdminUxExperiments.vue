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
        <div v-if="problem(e)" class="small text-danger mt-1">
          {{ problem(e) }}
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
          <div v-if="item.holdoutUntil" class="text-muted holdout">
            {{ holdoutText(item) }}
          </div>
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
            :title="
              item.holdoutUntil
                ? 'Say that the switch has been removed from the code. This also ends the holdout.'
                : 'Say that the switch has been removed from the code'
            "
            @click="act(item, 'close')"
          >
            Mark as cleaned up
          </b-button>
          <div v-if="problem(item)" class="small text-danger mt-1">
            {{ problem(item) }}
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
import { computed, onBeforeUnmount, reactive, ref } from 'vue'
import { useUxStore } from '~/modtools/stores/ux'
import { ideaSourceLabel as sourceLabel } from '~/modtools/composables/useUxFormat'
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
// "id:status" -> when the click was made, the time of the refusal already on the card (if any), so
// a refusal the batch writes afterwards can be told from an old one without comparing clocks.
const refusalWhenAsked = reactive({})
// The batch's last "no" for an experiment (the cap, a second test on the funnel, a change too small
// to read in a day): it is kept on the experiment so it can be shown here.
const lastRefusal = (e) => e.refusalObj ?? e.result?.last_refusal ?? null
const pending = (e) => {
  const key = `${e.id}:${e.status}`
  const asked = requested[key]
  if (!asked) return null
  const refusal = lastRefusal(e)
  if (refusal && refusal.at !== refusalWhenAsked[key]) return null
  return asked
}
const problem = (e) => {
  if (errors[e.id]) return errors[e.id]
  const refusal = pending(e) ? null : lastRefusal(e)
  return refusal ? `That was not done: ${refusal.message}` : ''
}
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
    // Adopted is provisional while the holdout runs: the batch can still take it off again.
    outcome: e.holdout_until ? 'Kept for now' : OUTCOME[e.status] || e.status,
    holdoutUntil: e.holdout_until || '',
    holdoutCheck: e.result?.holdout_check || null,
    refusalObj: e.result?.last_refusal || null,
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
  return `Of the ${r.total} who answered: ${r.better} said better, ${r.worse} worse, ${r.dont_care} said it makes no difference to them and ${r.dont_understand} were not sure what it is for.`
}

function holdoutText(item) {
  let text = `Some members still have the old version until ${item.holdoutUntil.slice(0, 10)}, and it is compared with the new one once a day. The change is taken off again if the old version does clearly better on repeated looks.`
  const check = item.holdoutCheck
  if (check) {
    const p =
      check.p_better === null || check.p_better === undefined
        ? 'too few people to say'
        : `chance the change is better ${Math.round(check.p_better * 100)}%`
    text += ` Last look, ${check.days} days in: ${p}, ${check.strikes || 0} against so far.`
  } else {
    text += ' The first look is a day after it was kept.'
  }
  return text
}

async function act(e, action) {
  busy[e.id] = true
  errors[e.id] = null
  try {
    await store.experimentAction(e.id, { action })
    const key = `${e.id}:${e.status}`
    refusalWhenAsked[key] = lastRefusal(e)?.at ?? null
    requested[key] = action
    confirming.value = null
    askAgainSoon()
  } catch (err) {
    errors[e.id] = err?.message || 'That did not work.'
  } finally {
    busy[e.id] = false
  }
}

// The batch applies a request within seconds, but the tab only knows when it asks again. Ask a few
// times, further apart each time, for as long as some request is waiting, so the card shows what
// happened (it started, or why it did not) without the page being reloaded.
const ASK_AFTER_MS = [5000, 10000, 20000, 40000, 80000, 160000]
let asked = 0
let timer = null
const anyPending = computed(() =>
  [...running.value, ...history.value].some((e) => pending(e))
)

function askAgainSoon() {
  if (timer || asked >= ASK_AFTER_MS.length) return
  timer = setTimeout(async () => {
    timer = null
    asked++
    try {
      await store.load('experiments')
    } catch {
      // The next look will try again.
    }
    if (anyPending.value) askAgainSoon()
    else asked = 0
  }, ASK_AFTER_MS[asked])
}

onBeforeUnmount(() => {
  if (timer) clearTimeout(timer)
})
</script>

<style scoped lang="scss">
.running {
  background: #f6fbf2;
}

.read {
  white-space: pre-line;
}
</style>
