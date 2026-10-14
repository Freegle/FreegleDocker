<template>
  <ModSysAdminUxPanel
    title="Funnels"
    explanation="For each flow: how many started and finished, where people left, what problems members told us about, and what we have tried about it. Bar width is how many reached the step; the hatched red end is how many of its visits ended there. The dashed outline is the same step in the period before. Steps that are different ways into the same stage are grouped, not drawn as a drop-off. Click a bar for who left and how long they spent."
    :loading="store.funnels.loading || store.outcomes.loading"
    :error="store.funnels.error || store.outcomes.error"
    :empty="!sections.length"
    empty-text="No flow data for the selected period."
  >
    <NoticeMessage v-if="experimentsError" variant="warning" class="mb-3">
      The experiment history could not be loaded: {{ experimentsError }}
    </NoticeMessage>
    <p v-if="experimentsData" class="small text-muted mb-3 cap">
      {{ experimentsData.running_count }} of
      {{ experimentsData.max_running }} experiment slots in use.
    </p>

    <!-- Every flow the experiments response lists gets a section, whether or not it had traffic
         in the period: a running experiment and its Stop button must not vanish on a quiet day. -->
    <section v-for="f in sections" :key="f.flow" class="flow mb-5">
      <h5 class="mb-1">{{ flowTitle(f.flow) }}</h5>
      <p class="headline mb-2">{{ headlineFor(f) }}</p>

      <div
        v-for="fn in funnelsFor(f.flow, f.steps)"
        :key="fn.family"
        class="mb-2"
      >
        <ModSysAdminUxFunnelChart
          :title="familyLabel(fn.family)"
          :steps="fn.steps"
          :exits="fn.exits"
          :previous-reach="previousReach(f.flow, fn)"
          :selected="selected?.flow === f.flow ? selected.step : ''"
          @select="(step) => select(f.flow, step)"
        />
      </div>

      <div
        v-if="detail && selected.flow === f.flow"
        class="step-detail border rounded p-3 mb-3"
      >
        <h6>{{ stepLabel(selected.step) }}</h6>
        <p class="small mb-2">
          Entered {{ detail.entered.toLocaleString() }}, reentered
          {{ detail.reentered.toLocaleString() }}. Of the
          {{ detail.visits.toLocaleString() }} visits,
          {{ withCount(detail.completed, detail.visits) }} completed,
          {{ withCount(detail.backed, detail.visits) }} went back,
          {{ withCount(detail.abandoned, detail.visits) }} abandoned. Typical
          time on the step {{ ms(detail.dwell_p50_ms) }}, slowest tenth
          {{ ms(detail.dwell_p90_ms) }}. Previous period:
          {{ previousText(f.flow, selected.step) }} completed.
        </p>
        <div v-for="dim in dims" :key="dim.key" class="mb-2">
          <h6 class="mb-1">{{ dim.label }}</h6>
          <b-table
            :items="breakdown(dim.key)"
            :fields="breakdownFields"
            small
            responsive
          />
        </div>
        <b-button
          class="sample-btn"
          size="sm"
          variant="secondary"
          @click="open = true"
        >
          Sample sessions
        </b-button>
      </div>

      <template v-if="experimentsFor(f.flow)">
        <ModSysAdminUxProblems
          title="Problems members raised"
          :items="experimentsFor(f.flow).problems || []"
          empty-text="No problems raised about this funnel yet."
        />
        <ModSysAdminUxExperiments
          :flow-data="experimentsFor(f.flow)"
          :full="full"
        />
      </template>
    </section>

    <section
      v-if="
        experimentsData &&
        (values.length || generalProblems.length || generalIdeas.length)
      "
      class="general mb-4"
    >
      <h5 class="mb-1">Across all of Freegle</h5>
      <ModSysAdminUxProblems
        title="What members value"
        mode="values"
        note="Things members said they value. These are what a change must not break."
        :items="values"
        empty-text="Nobody has said yet."
      />
      <ModSysAdminUxProblems
        title="Problems not tied to one flow"
        :items="generalProblems"
        empty-text="No problems like that yet."
      />
      <div v-if="generalIdeas.length" class="general-ideas">
        <h6 class="mb-1">Suggestions not tied to one flow</h6>
        <p class="small text-muted mb-1">
          Members' own suggestions that no problem or funnel is behind. They
          cannot be tried as they stand.
        </p>
        <ul class="small mb-0 ps-3">
          <li v-for="i in generalIdeas" :key="i.id" class="mb-1">
            <strong>{{ i.title }}</strong>
            <span class="text-muted">({{ ideaSourceLabel(i.source) }})</span>
            <div>{{ i.rationale }}</div>
          </li>
        </ul>
      </div>
    </section>

    <ModSysAdminUxSamples
      v-model="open"
      :flow="selected?.flow || ''"
      :step="selected?.step || ''"
      :start="store.funnels.data?.start || ''"
      :end="store.funnels.data?.end || ''"
    />
  </ModSysAdminUxPanel>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useUxStore } from '~/modtools/stores/ux'
import {
  withCount,
  ms,
  ideaSourceLabel,
} from '~/modtools/composables/useUxFormat'
import {
  funnelsFor,
  headline,
  flowTitle,
  stepLabel,
  familyLabel,
  deviceLabel,
} from '~/modtools/composables/useUxFunnel'
import ModSysAdminUxPanel from '~/modtools/components/ModSysAdminUxPanel.vue'
import ModSysAdminUxSamples from '~/modtools/components/ModSysAdminUxSamples.vue'
import ModSysAdminUxFunnelChart from '~/modtools/components/ModSysAdminUxFunnelChart.vue'
import ModSysAdminUxExperiments from '~/modtools/components/ModSysAdminUxExperiments.vue'
import ModSysAdminUxProblems from '~/modtools/components/ModSysAdminUxProblems.vue'

const store = useUxStore()
const selected = ref(null)
const open = ref(false)

const dims = [
  { key: 'device_class', label: 'By device' },
  { key: 'logged_in', label: 'By logged in' },
  { key: 'variant', label: 'By experiment arm' },
]
const breakdownFields = [
  { key: 'key', label: 'Group' },
  { key: 'visits', label: 'Visits' },
  { key: 'completed', label: 'Completed' },
  { key: 'abandoned', label: 'Abandoned' },
]

const flows = computed(() => store.funnels.data?.flows || [])
// The funnel flows in their order, then any flow the experiments response lists that had no
// funnel rows in this period, so its experiments are still there.
const sections = computed(() => {
  const out = [...flows.value]
  const have = new Set(out.map((f) => f.flow))
  for (const f of experimentsData.value?.flows || []) {
    if (!have.has(f.flow)) out.push({ flow: f.flow, steps: [] })
  }
  return out
})
const outcomeFlows = computed(() => store.outcomes.data?.flows || [])
const previousOutcomes = computed(
  () => store.outcomes.data?.previous?.flows || []
)
const experimentsData = computed(() => store.experiments.data || null)
const values = computed(() => experimentsData.value?.values || [])
const generalProblems = computed(
  () => experimentsData.value?.general_problems || []
)
const generalIdeas = computed(() => experimentsData.value?.general_ideas || [])
const experimentsError = computed(() => store.experiments.error)
const full = computed(
  () =>
    !!experimentsData.value &&
    experimentsData.value.running_count >= experimentsData.value.max_running
)

const outcome = (flow) => outcomeFlows.value.find((o) => o.flow === flow)
const previousOutcome = (flow) =>
  previousOutcomes.value.find((o) => o.flow === flow)

function headlineFor(f) {
  return headline(f.flow, outcome(f.flow), f.steps, previousOutcome(f.flow))
}

function experimentsFor(flow) {
  return experimentsData.value?.flows?.find((x) => x.flow === flow) || null
}

function stepIn(list, flow, step) {
  return list?.find((f) => f.flow === flow)?.steps?.find((s) => s.step === step)
}

// step -> reach in the previous period. It is a share of the previous period's own first step, not
// a count, so the chart draws it as an outline of the same kind of width and says so.
function previousReach(flow, fn) {
  const prevFlow = store.funnels.data?.previous?.flows?.find(
    (p) => p.flow === flow
  )
  if (!prevFlow) return {}
  const prev = funnelsFor(flow, prevFlow.steps).find(
    (p) => p.family === fn.family
  )
  const out = {}
  ;(prev?.steps || []).forEach((s, i) => {
    // The heading of a stage with ways in is the sum of them, each way in is its own bar, and
    // they must not share a key or the heading is outlined at one way in's share. The stage is
    // keyed by its position, because which way in is commonest can differ between periods.
    out['stage:' + i] = s.reach
    out[s.step] = s.reach
    for (const a of s.alternatives || []) out[a.step] = a.reach
  })
  return out
}

function previousText(flow, step) {
  const p = stepIn(store.funnels.data?.previous?.flows, flow, step)
  return p ? withCount(p.completed, p.visits) : 'no data'
}

const isSelected = (flow, step) =>
  selected.value?.flow === flow && selected.value?.step === step

function select(flow, step) {
  selected.value = isSelected(flow, step) ? null : { flow, step }
}

const detail = computed(() =>
  selected.value
    ? stepIn(flows.value, selected.value.flow, selected.value.step)
    : null
)

// What a breakdown row is called. The keys are what the rollup stores; a reader wants words.
function groupLabel(dim, key) {
  if (dim === 'device_class') return deviceLabel(key)
  if (dim === 'logged_in') {
    return String(key) === 'true' ? 'Logged in' : 'Not logged in'
  }
  if (dim === 'variant') {
    if (!key) return 'Not in an experiment'
    return String(key)
      .split(',')
      .map((part) => part.replace(':', ': '))
      .join('; ')
  }
  return key
}

function breakdown(dim) {
  return (detail.value?.by?.[dim] || []).map((r) => ({
    key: groupLabel(dim, r.key),
    visits: r.visits.toLocaleString(),
    completed: withCount(r.completed, r.visits),
    abandoned: withCount(r.abandoned, r.visits),
  }))
}
</script>

<style scoped lang="scss">
.headline {
  font-weight: 500;
}
</style>
