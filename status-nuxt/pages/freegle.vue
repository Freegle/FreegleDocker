<script setup lang="ts">
import { services } from '~/server/utils/services'
import { useStatusStore } from '~/stores/status'

const statusStore = useStatusStore()

// Filter services for Freegle category
const freegleServices = computed(() => {
  return services.filter(s => s.category === 'freegle')
})

// Fetch status on mount
onMounted(() => {
  statusStore.refreshStatus()
})
</script>

<template>
  <div>
    <ServiceGrid :services="freegleServices" title="Freegle Containers" />
  </div>
</template>
