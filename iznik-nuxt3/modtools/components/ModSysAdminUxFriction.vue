<template>
  <ModSysAdminUxPanel
    title="Friction"
    explanation="Where people click things that do nothing (dead clicks), click the same thing again and again (rage clicks) or repeat the same action. Biggest change since the previous period first."
    collapsible
    :open="open"
    :summary="summary"
    :notes="notes"
    :loading="store.friction.loading"
    :error="store.friction.error"
    :missing="store.friction.missing"
    :empty="!rows.length"
    empty-text="Nothing to report."
    @toggle="emit('toggle')"
  >
    <b-table :items="rows" :fields="fields" small responsive />
  </ModSysAdminUxPanel>
</template>

<script setup>
import { computed } from 'vue'
import { useUxStore } from '~/modtools/stores/ux'
import {
  withCount,
  signed,
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

const components = computed(() => store.friction.data?.components || [])

const rows = computed(() =>
  components.value.map((c) => {
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
      // The server's change is a difference of two shares, so a rise of 0.3 is 30 points.
      change: `${signed((c.change || 0) * 100)} points`,
    }
  })
)

// The server sends the components with the biggest rise first, so the first is the one to name.
const summary = computed(() => {
  const first = components.value[0]
  if (!first) return ''
  const n = components.value.length
  return `${n} ${n === 1 ? 'component' : 'components'} listed. Most trouble: ${first.page} / ${first.component}.`
})

const notes = computed(() =>
  filterNotes('friction', store.friction.data, store.requested('friction'))
)
</script>
