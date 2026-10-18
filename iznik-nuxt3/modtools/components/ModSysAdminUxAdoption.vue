<template>
  <ModSysAdminUxPanel
    title="Adoption"
    explanation="How many of the people who were active used each feature. A feature almost nobody uses may be hidden, or not wanted."
    :loading="store.adoption.loading"
    :error="store.adoption.error"
    :empty="!rows.length"
    empty-text="No adoption data for the selected period."
  >
    <b-table :items="rows" :fields="fields" small responsive />
  </ModSysAdminUxPanel>
</template>

<script setup>
import { computed } from 'vue'
import { useUxStore } from '~/modtools/stores/ux'
import { withCount, signed } from '~/modtools/composables/useUxFormat'
import ModSysAdminUxPanel from '~/modtools/components/ModSysAdminUxPanel.vue'

const store = useUxStore()

const fields = [
  { key: 'feature', label: 'Feature' },
  { key: 'using', label: 'Users of it / active users' },
  { key: 'sessions', label: 'Sessions using it' },
  { key: 'previous', label: 'Previous period' },
  { key: 'change', label: 'Change' },
]

// share and previous_share are fractions (0 to 1).
const asPct = (x) => `${(x * 100).toFixed(1)}%`

const rows = computed(() =>
  (store.adoption.data?.features || []).map((f) => ({
    feature: f.feature,
    using: withCount(f.using_users, f.active_users),
    sessions: (f.sessions_using || 0).toLocaleString(),
    previous: asPct(f.previous_share || 0),
    change: `${signed((f.share - (f.previous_share || 0)) * 100)} points`,
  }))
)
</script>
