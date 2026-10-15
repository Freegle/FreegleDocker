<template>
  <ModSysAdminUxPanel
    title="Data health"
    explanation="Is the instrumentation itself delivering? Each metric compares what we expected to receive with what arrived, per day. Check this first."
    collapsible
    :open="open"
    :force-open="failing.length > 0"
    :summary="summary"
    :notes="notes"
    :loading="store.health.loading"
    :error="store.health.error"
    :missing="store.health.missing"
    :empty="!rows.length"
    empty-text="No health data for this period."
    @toggle="emit('toggle')"
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
import { filterNotes } from '~/modtools/composables/useUxFormat'
import ModSysAdminUxPanel from '~/modtools/components/ModSysAdminUxPanel.vue'

defineProps({
  // Whether the person opened it. The section keeps this in the address.
  open: { type: Boolean, default: false },
})
const emit = defineEmits(['toggle'])

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

// The line shown while the row is closed: three names at most, then how many more.
const summary = computed(() => {
  const n = metrics.value.length
  if (!n) return ''
  const bad = failing.value
  if (!bad.length) {
    return n === 1
      ? 'The check is inside the expected range.'
      : `All ${n} checks are inside the expected range.`
  }
  const more = bad.length - 3
  return `${bad.length} of ${n} ${n === 1 ? 'check is' : 'checks are'} outside the expected range: ${bad.slice(0, 3).join(', ')}${more > 0 ? ` and ${more} more` : ''}.`
})

const notes = computed(() =>
  filterNotes('health', store.health.data, store.requested('health'))
)
</script>
