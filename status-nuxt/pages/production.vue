<script setup lang="ts">
import { services } from '~/server/utils/services'
import { useStatusStore } from '~/stores/status'

const statusStore = useStatusStore()

// Filter services that are marked as production (live APIs)
const productionServices = computed(() => {
  return services.filter(s => s.production === true)
})

// Fetch status on mount
onMounted(() => {
  statusStore.refreshStatus()
})
</script>

<template>
  <div>
    <div class="alert alert-warning mb-3">
      <strong>Warning:</strong> These services connect to PRODUCTION APIs.
      Changes here affect real users.
    </div>
    <ServiceGrid :services="productionServices" title="Production Services" />
  </div>
</template>
