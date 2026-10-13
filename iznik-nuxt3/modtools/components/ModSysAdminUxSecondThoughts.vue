<template>
  <ModSysAdminUxPanel
    title="Second thoughts"
    explanation="Where people go back a step, return to a field they already filled in, hit a validation error or change their choice. These are signs the step was unclear."
    :loading="store.funnels.loading || store.fields.loading"
    :error="store.funnels.error || store.fields.error"
    :empty="!stepRows.length && !fieldRows.length"
    empty-text="No second-thoughts data for the selected period."
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
  stepsWithPrevious,
} from '~/modtools/composables/useUxFormat'
import ModSysAdminUxPanel from '~/modtools/components/ModSysAdminUxPanel.vue'

const store = useUxStore()

const stepFields = [
  { key: 'flow', label: 'Flow' },
  { key: 'step', label: 'Step' },
  { key: 'backed', label: 'Went back' },
  { key: 'previous', label: 'Previous period' },
]
const fieldFields = [
  { key: 'flow', label: 'Flow' },
  { key: 'step', label: 'Step' },
  { key: 'field', label: 'Field' },
  { key: 'revisits', label: 'Revisits' },
  { key: 'validation_failures', label: 'Validation failures' },
  { key: 'choice_switches', label: 'Choice switches' },
]

const stepRows = computed(() =>
  stepsWithPrevious(store.funnels.data).map((s) => ({
    flow: s.flow,
    step: s.step,
    backed: withCount(s.backed, s.visits),
    previous: s.prev ? withCount(s.prev.backed, s.prev.visits) : '–',
  }))
)

const fieldRows = computed(() => store.fields.data?.fields || [])
</script>
