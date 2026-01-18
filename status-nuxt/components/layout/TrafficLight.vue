<script setup lang="ts">
import { useStatusStore } from '~/stores/status'

const statusStore = useStatusStore()

const overallHealth = computed(() => {
  const states = statusStore.serviceStates
  if (states.size === 0) return 'unknown'

  let online = 0
  let offline = 0
  let loading = 0

  for (const state of states.values()) {
    if (state.status === 'online') online++
    else if (state.status === 'offline') offline++
    else if (state.status === 'loading') loading++
  }

  // If any service is offline, show red
  if (offline > 0) return 'red'
  // If any service is loading/starting, show yellow
  if (loading > 0) return 'yellow'
  // If all services are online, show green
  if (online > 0) return 'green'
  // Unknown state
  return 'unknown'
})
</script>

<template>
  <div class="traffic-light" :title="`Overall health: ${overallHealth}`">
    <div class="light red" :class="{ active: overallHealth === 'red' }" />
    <div class="light yellow" :class="{ active: overallHealth === 'yellow' }" />
    <div class="light green" :class="{ active: overallHealth === 'green' }" />
  </div>
</template>
