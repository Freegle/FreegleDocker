<template>
  <ModSysAdminUxPanel
    title="Funnels"
    explanation="For each flow: how many started and finished, where people left, and what we have tried about it. Bar width is how many reached the step; the red end is how many of its visits ended there. Click a bar for who left and how long they spent."
    :loading="store.funnels.loading || store.outcomes.loading"
    :error="store.funnels.error || store.outcomes.error"
    :empty="!flows.length"
    empty-text="No flow data for the selected period."
  >
    <NoticeMessage v-if="experimentsError" variant="warning" class="mb-3">
      The experiment history could not be loaded: {{ experimentsError }}
    </NoticeMessage>
    <p v-if="experimentsData" class="small text-muted mb-3 cap">
      {{ experimentsData.running_count }} of
      {{ experimentsData.max_running }} experiment slots in use.
    </p>

    <section v-for="f in flows" :key="f.flow" class="flow mb-5">
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

      <ModSysAdminUxExperiments
        v-if="experimentsFor(f.flow)"
        :flow-data="experimentsFor(f.flow)"
        :full="full"
      />
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
import { withCount, ms } from '~/modtools/composables/useUxFormat'
import {
  funnelsFor,
  headline,
  flowTitle,
  stepLabel,
  familyLabel,
} from '~/modtools/composables/useUxFunnel'
import ModSysAdminUxPanel from '~/modtools/components/ModSysAdminUxPanel.vue'
import ModSysAdminUxSamples from '~/modtools/components/ModSysAdminUxSamples.vue'
import ModSysAdminUxFunnelChart from '~/modtools/components/ModSysAdminUxFunnelChart.vue'
import ModSysAdminUxExperiments from '~/modtools/components/ModSysAdminUxExperiments.vue'

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
const outcomeFlows = computed(() => store.outcomes.data?.flows || [])
const previousOutcomes = computed(
  () => store.outcomes.data?.previous?.flows || []
)
const experimentsData = computed(() => store.experiments.data || null)
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

// step -> reach in the previous period, on the same base as the current funnel.
function previousReach(flow, fn) {
  const prevFlow = store.funnels.data?.previous?.flows?.find(
    (p) => p.flow === flow
  )
  if (!prevFlow) return {}
  const prev = funnelsFor(flow, prevFlow.steps).find(
    (p) => p.family === fn.family
  )
  const out = {}
  for (const s of prev?.steps || []) out[s.step] = s.reach
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

function breakdown(dim) {
  return (detail.value?.by?.[dim] || []).map((r) => ({
    key: r.key,
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
