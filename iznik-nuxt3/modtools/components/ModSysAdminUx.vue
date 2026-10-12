<template>
  <div class="sysadmin-ux" data-ux-ignore>
    <!-- data-ux-ignore: this tab must not feed its own telemetry. -->
    <ModEmailDateFilter
      :loading="loading"
      fetch-label="Fetch"
      default-preset="7days"
      @fetch="onFilterFetch"
    />
    <ModSysAdminUxHealth />
    <ModSysAdminUxFunnels />
    <ModSysAdminUxFriction />
    <ModSysAdminUxSecondThoughts />
    <ModSysAdminUxHelp />
    <ModSysAdminUxAdoption />
    <ModSysAdminUxRegret />
    <ModSysAdminUxSearch />
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useUxStore } from '~/modtools/stores/ux'
import ModEmailDateFilter from '~/modtools/components/ModEmailDateFilter.vue'
import ModSysAdminUxHealth from '~/modtools/components/ModSysAdminUxHealth.vue'
import ModSysAdminUxFunnels from '~/modtools/components/ModSysAdminUxFunnels.vue'
import ModSysAdminUxFriction from '~/modtools/components/ModSysAdminUxFriction.vue'
import ModSysAdminUxSecondThoughts from '~/modtools/components/ModSysAdminUxSecondThoughts.vue'
import ModSysAdminUxHelp from '~/modtools/components/ModSysAdminUxHelp.vue'
import ModSysAdminUxAdoption from '~/modtools/components/ModSysAdminUxAdoption.vue'
import ModSysAdminUxRegret from '~/modtools/components/ModSysAdminUxRegret.vue'
import ModSysAdminUxSearch from '~/modtools/components/ModSysAdminUxSearch.vue'

const store = useUxStore()

const loading = computed(() =>
  [
    'health',
    'funnels',
    'outcomes',
    'fields',
    'friction',
    'adoption',
    'regret',
    'search',
  ].some((k) => store[k].loading)
)

// The date filter fires on mount and whenever the period changes, so it drives
// the initial load too.
function onFilterFetch({ start, end }) {
  store.loadAll({ start: start || '', end: end || '' })
}
</script>
