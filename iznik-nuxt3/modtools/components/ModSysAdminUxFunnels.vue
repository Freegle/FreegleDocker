<template>
  <ModSysAdminUxPanel
    title="Funnels"
    explanation="For each flow, how many people who reached a step went on to finish it. Click a step to see who dropped out and how long they spent there."
    :loading="store.funnels.loading || store.outcomes.loading"
    :error="store.funnels.error || store.outcomes.error"
    :empty="!flows.length"
    empty-text="No flow data for the selected period."
  >
    <div v-for="f in flows" :key="f.flow" class="flow-strip mb-4">
      <h5>{{ f.flow }}</h5>
      <p v-if="outcome(f.flow)" class="small mb-2 outcome">
        Started {{ outcome(f.flow).started.toLocaleString() }}.
        {{ withCount(outcome(f.flow).submitted, outcome(f.flow).started) }}
        submitted,
        {{
          withCount(outcome(f.flow).submit_failed, outcome(f.flow).submitted)
        }}
        of those failed.
        {{ withCount(outcome(f.flow).completed, outcome(f.flow).started) }}
        completed,
        {{ withCount(outcome(f.flow).abandoned, outcome(f.flow).started) }}
        abandoned, {{ outcome(f.flow).resumed.toLocaleString() }} resumed later.
      </p>
      <div class="strip d-flex gap-2 pb-2">
        <button
          v-for="s in f.steps"
          :key="s.step"
          type="button"
          class="step-card"
          :class="{ active: isSelected(f.flow, s.step) }"
          @click="select(f.flow, s.step)"
        >
          <strong>{{ s.step }}</strong>
          <div class="small">{{ withCount(s.completed, s.visits) }}</div>
          <div class="small text-muted">
            Previous: {{ previousText(f.flow, s.step) }}
          </div>
        </button>
      </div>
    </div>

    <div v-if="detail" class="step-detail border rounded p-3">
      <h5>{{ selected.flow }} / {{ selected.step }}</h5>
      <p class="small mb-2">
        Entered {{ detail.entered.toLocaleString() }}, reentered
        {{ detail.reentered.toLocaleString() }}. Of the
        {{ detail.visits.toLocaleString() }} visits,
        {{ withCount(detail.completed, detail.visits) }} completed,
        {{ withCount(detail.backed, detail.visits) }} went back,
        {{ withCount(detail.abandoned, detail.visits) }} abandoned. Typical time
        on the step {{ ms(detail.dwell_p50_ms) }}, slowest tenth
        {{ ms(detail.dwell_p90_ms) }}.
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
import ModSysAdminUxPanel from '~/modtools/components/ModSysAdminUxPanel.vue'
import ModSysAdminUxSamples from '~/modtools/components/ModSysAdminUxSamples.vue'

const store = useUxStore()
const selected = ref(null)
const open = ref(false)

const dims = [
  { key: 'device_class', label: 'By device' },
  { key: 'logged_in', label: 'By logged in' },
  { key: 'variant', label: 'By variant' },
]
const breakdownFields = [
  { key: 'key', label: 'Group' },
  { key: 'visits', label: 'Visits' },
  { key: 'completed', label: 'Completed' },
  { key: 'abandoned', label: 'Abandoned' },
]

const flows = computed(() => store.funnels.data?.flows || [])
const outcomeFlows = computed(() => store.outcomes.data?.flows || [])

const outcome = (flow) => outcomeFlows.value.find((o) => o.flow === flow)

function stepIn(list, flow, step) {
  return list?.find((f) => f.flow === flow)?.steps?.find((s) => s.step === step)
}

function previousText(flow, step) {
  const p = stepIn(store.funnels.data?.previous?.flows, flow, step)
  return p ? withCount(p.completed, p.visits) : '–'
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
.strip {
  overflow-x: auto;
}

.step-card {
  min-width: 11rem;
  text-align: left;
  background: #fff;
  border: 1px solid #ced4da;
  border-radius: 0.5rem;
  padding: 0.5rem 0.75rem;

  &.active {
    border-color: #61ae24;
    box-shadow: 0 0 0 2px rgba(97, 174, 36, 0.35);
  }
}
</style>
