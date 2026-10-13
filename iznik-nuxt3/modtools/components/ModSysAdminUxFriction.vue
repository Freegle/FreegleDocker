<template>
  <ModSysAdminUxPanel
    title="Friction"
    explanation="Where people click things that do nothing (dead clicks), click the same thing again and again (rage clicks) or repeat the same action. Biggest change since the previous period first."
    :loading="store.friction.loading"
    :error="store.friction.error"
    :empty="!rows.length"
    empty-text="No friction data for the selected period."
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
  { key: 'page', label: 'Page' },
  { key: 'component', label: 'Component' },
  { key: 'clicks', label: 'Clicks' },
  { key: 'dead', label: 'Dead clicks' },
  { key: 'deadPrev', label: 'Dead, previous' },
  { key: 'rage', label: 'Rage clicks' },
  { key: 'ragePrev', label: 'Rage, previous' },
  { key: 'repeated', label: 'Repeated intent' },
  { key: 'change', label: 'Change' },
]

const rows = computed(() =>
  (store.friction.data?.components || []).map((c) => {
    const p = c.previous || {}
    return {
      page: c.page,
      component: c.component,
      clicks: (c.clicks || 0).toLocaleString(),
      dead: withCount(c.dead_clicks, c.clicks),
      deadPrev: withCount(p.dead_clicks, p.clicks),
      rage: withCount(c.rage_clicks, c.clicks),
      ragePrev: withCount(p.rage_clicks, p.clicks),
      repeated: (c.repeated_intent || 0).toLocaleString(),
      change: signed(c.change),
    }
  })
)
</script>
