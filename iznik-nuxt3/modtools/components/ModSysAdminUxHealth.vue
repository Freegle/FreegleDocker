<template>
  <ModSysAdminUxPanel
    title="Data health"
    explanation="Is the instrumentation itself delivering? Each metric compares what we expected to receive with what arrived, per day. Check this first."
    :loading="store.health.loading"
    :error="store.health.error"
    :empty="!rows.length"
    empty-text="No health data for the selected period."
  >
    <NoticeMessage v-if="failing.length" variant="danger" class="mb-3">
      Not delivering as expected: {{ failing.join(', ') }}. The figures below
      may reflect instrumentation rather than members.
    </NoticeMessage>
    <b-table :items="rows" :fields="fields" small responsive />
  </ModSysAdminUxPanel>
</template>

<script setup>
import { computed } from 'vue'
import { useUxStore } from '~/modtools/stores/ux'
import ModSysAdminUxPanel from '~/modtools/components/ModSysAdminUxPanel.vue'

const store = useUxStore()

const fields = [
  { key: 'metric', label: 'Metric' },
  { key: 'status', label: 'Status' },
  { key: 'off', label: 'Days outside the expected range' },
  { key: 'latest', label: 'Latest ratio (observed / expected)' },
]

const metrics = computed(() => store.health.data?.metrics || [])
const failing = computed(() =>
  metrics.value.filter((m) => !m.ok).map((m) => m.metric)
)

const rows = computed(() =>
  metrics.value.map((m) => {
    const days = m.days || []
    const last = days[days.length - 1]
    return {
      metric: m.metric,
      status: m.ok ? 'OK' : 'Not OK',
      off: `${days.filter((d) => !d.ok).length} of ${days.length} days`,
      latest: last ? Number(last.ratio).toFixed(2) : '–',
    }
  })
)
</script>
