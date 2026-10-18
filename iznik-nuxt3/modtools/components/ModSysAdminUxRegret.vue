<template>
  <ModSysAdminUxPanel
    title="Regret"
    explanation="Signs that someone wished they had done something differently, such as editing a post straight after sending it. Each is shown against the number of times the action could have happened."
    :loading="store.regret.loading"
    :error="store.regret.error"
    :empty="!signals.length"
    empty-text="No regret data for the selected period."
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
import { withCount, signed, sumBy } from '~/modtools/composables/useUxFormat'
import ModSysAdminUxPanel from '~/modtools/components/ModSysAdminUxPanel.vue'

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
    signal: s.signal,
    rate: withCount(s.total, sumBy(s.days, 'base')),
    previous: (s.previous_total || 0).toLocaleString(),
    change: signed((s.total || 0) - (s.previous_total || 0)),
  }))
)

const chartData = computed(() => {
  const dates = [
    ...new Set(signals.value.flatMap((s) => (s.days || []).map((d) => d.date))),
  ].sort()
  return [
    ['Date', ...signals.value.map((s) => s.signal)],
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
