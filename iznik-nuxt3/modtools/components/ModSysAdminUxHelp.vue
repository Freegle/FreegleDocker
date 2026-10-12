<template>
  <ModSysAdminUxPanel
    title="Help"
    explanation="Where people stop to look for help while in a flow. A step with a lot of help-seeking is one people find confusing."
    :loading="store.funnels.loading"
    :error="store.funnels.error"
    :empty="!rows.length"
    empty-text="No help data for the selected period."
  >
    <b-table :items="rows" :fields="fields" small responsive />
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

const fields = [
  { key: 'flow', label: 'Flow' },
  { key: 'step', label: 'Step' },
  { key: 'help', label: 'Sought help' },
  { key: 'previous', label: 'Previous period' },
]

const rows = computed(() =>
  stepsWithPrevious(store.funnels.data).map((s) => ({
    flow: s.flow,
    step: s.step,
    help: withCount(s.help_sought, s.entered),
    previous: s.prev ? withCount(s.prev.help_sought, s.prev.entered) : '–',
  }))
)
</script>
