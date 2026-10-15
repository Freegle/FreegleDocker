<template>
  <ModSysAdminUxPanel
    title="Regret"
    explanation="Signs that someone wished they had done something differently, such as editing a post straight after sending it. Each is shown against the number of times the action could have happened."
    collapsible
    :open="open"
    :summary="summary"
    :notes="notes"
    :loading="store.regret.loading"
    :error="store.regret.error"
    :missing="store.regret.missing"
    :empty="!signals.length"
    empty-text="No regret data for this period."
    @toggle="emit('toggle')"
  >
    <div class="chart-container mb-3">
      <GChart
        type="LineChart"
        :data="chartData"
        :options="chartOptions"
        class="chart"
      />
    </div>
    <b-table :items="rows" :fields="fields" small responsive />
  </ModSysAdminUxPanel>
</template>

<script setup>
import { computed } from 'vue'
import { GChart } from 'vue-google-charts'
import { useUxStore } from '~/modtools/stores/ux'
import {
  withCount,
  pct,
  signed,
  sumBy,
  regretLabel,
  filterNotes,
} from '~/modtools/composables/useUxFormat'
import ModSysAdminUxPanel from '~/modtools/components/ModSysAdminUxPanel.vue'

defineProps({
  // Whether the person opened it. The section keeps this in the address.
  open: { type: Boolean, default: false },
})
const emit = defineEmits(['toggle'])

const store = useUxStore()

const fields = [
  { key: 'signal', label: 'Signal' },
  { key: 'rate', label: 'Occurrences / opportunities' },
  { key: 'previous', label: 'Previous period (count)' },
  { key: 'change', label: 'Change (count)' },
]

const signals = computed(() => store.regret.data?.signals || [])

const rows = computed(() =>
  signals.value.map((s) => ({
    signal: regretLabel(s.signal),
    rate: withCount(s.total, sumBy(s.days, 'base')),
    previous: (s.previous_total || 0).toLocaleString(),
    change: signed((s.total || 0) - (s.previous_total || 0)),
  }))
)

// The signal with the highest share of the times it could have happened. A signal that could not
// have happened at all (no base) has no share, so it only wins when none has one.
const summary = computed(() => {
  let best = null
  let bestShare = -1
  for (const s of signals.value) {
    const base = sumBy(s.days, 'base')
    const share = base ? (s.total || 0) / base : -1
    if (!best || share > bestShare) {
      best = { signal: s.signal, total: s.total || 0, base }
      bestShare = share
    }
  }
  if (!best) return ''
  return `Highest: ${regretLabel(best.signal)}, ${pct(best.total, best.base)} (${best.total.toLocaleString()} of ${best.base.toLocaleString()}).`
})

const notes = computed(() =>
  filterNotes('regret', store.regret.data, store.requested('regret'))
)

const chartData = computed(() => {
  const dates = [
    ...new Set(signals.value.flatMap((s) => (s.days || []).map((d) => d.date))),
  ].sort()
  return [
    ['Date', ...signals.value.map((s) => regretLabel(s.signal))],
    ...dates.map((date) => [
      date,
      ...signals.value.map(
        (s) => (s.days || []).find((d) => d.date === date)?.count || 0
      ),
    ]),
  ]
})

const chartOptions = {
  legend: { position: 'bottom' },
  chartArea: { width: '80%', height: '65%' },
  vAxis: { title: 'Occurrences', viewWindow: { min: 0 } },
}

defineExpose({ chartData })
</script>

<style scoped lang="scss">
.chart-container,
.chart {
  width: 100%;
  height: 300px;
}
</style>
