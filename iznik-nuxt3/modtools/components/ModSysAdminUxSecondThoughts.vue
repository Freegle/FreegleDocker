<template>
  <ModSysAdminUxPanel
    title="Second thoughts"
    explanation="Where people go back a step, return to a field they already filled in, hit a validation error or change their choice. These are signs the step was unclear."
    collapsible
    :open="open"
    :summary="summary"
    :notes="notes"
    :loading="store.funnels.loading || store.fields.loading"
    :error="store.funnels.error || store.fields.error"
    :missing="store.funnels.missing || store.fields.missing"
    :empty="!stepRows.length && !fieldRows.length"
    empty-text="No data for this funnel in this period."
    @toggle="emit('toggle')"
  >
    <h6>Going back, by step</h6>
    <b-table :items="stepRows" :fields="stepFields" small responsive />
    <h6>Fields</h6>
    <b-table :items="fieldRows" :fields="fieldFields" small responsive />
  </ModSysAdminUxPanel>
</template>

<script setup>
import { computed } from 'vue'
import { useUxStore } from '~/modtools/stores/ux'
import {
  withCount,
  pct,
  stepsWithPrevious,
  filterNotes,
} from '~/modtools/composables/useUxFormat'
import {
  stepLabel,
  MIN_VISITS_FOR_WORST,
} from '~/modtools/composables/useUxFunnel'
import ModSysAdminUxPanel from '~/modtools/components/ModSysAdminUxPanel.vue'

const props = defineProps({
  // The funnel to show. Only its rows are drawn.
  flow: { type: String, required: true },
  // Whether the person opened it. The section keeps this in the address.
  open: { type: Boolean, default: false },
})
const emit = defineEmits(['toggle'])

const store = useUxStore()

const stepFields = [
  { key: 'step', label: 'Step' },
  { key: 'backed', label: 'Went back' },
  { key: 'previous', label: 'Previous period' },
]
const fieldFields = [
  { key: 'step', label: 'Step' },
  { key: 'field', label: 'Field' },
  { key: 'revisits', label: 'Revisits' },
  { key: 'validation_failures', label: 'Validation failures' },
  { key: 'choice_switches', label: 'Choice switches' },
]

const steps = computed(() =>
  stepsWithPrevious(store.funnels.data).filter((s) => s.flow === props.flow)
)

const stepRows = computed(() =>
  steps.value.map((s) => ({
    step: stepLabel(s.step),
    backed: withCount(s.backed, s.visits),
    previous: s.prev ? withCount(s.prev.backed, s.prev.visits) : '–',
  }))
)

const fieldRows = computed(() =>
  (store.fields.data?.fields || [])
    .filter((f) => f.flow === props.flow)
    .map((f) => ({ ...f, step: stepLabel(f.step) }))
)

// The step where the biggest share of visits went back. A step needs enough visits to count.
const summary = computed(() => {
  let top = null
  for (const s of steps.value) {
    if ((s.visits || 0) < MIN_VISITS_FOR_WORST || !s.backed) continue
    if (!top || s.backed / s.visits > top.backed / top.visits) top = s
  }
  if (!top) return 'Nothing stood out.'
  return `Most went back at ${stepLabel(top.step)}: ${pct(top.backed, top.visits)} (${top.backed.toLocaleString()} of ${top.visits.toLocaleString()}).`
})

// What the server did with the filters, for the two answers this panel draws, without repeats.
const notes = computed(() => [
  ...new Set([
    ...filterNotes('funnels', store.funnels.data, store.requested('funnels')),
    ...filterNotes('fields', store.fields.data, store.requested('fields')),
  ]),
])
</script>
