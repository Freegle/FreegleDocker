<script setup lang="ts">
import type { ServiceConfig, ServiceState } from '~/types/service'
import { useStatusStore } from '~/stores/status'

const props = defineProps<{
  service: ServiceConfig
}>()

const statusStore = useStatusStore()

const state = computed((): ServiceState => {
  return statusStore.getServiceState(props.service.id) || {
    id: props.service.id,
    status: 'unknown',
    lastChecked: new Date(),
  }
})

const cpu = computed(() => {
  return statusStore.getCpuUsage(props.service.id)
})

// For now, assume we can manage containers (local Docker environment)
const canManage = ref(true)

const handleRestart = async (serviceId: string) => {
  try {
    await statusStore.restartContainer(props.service.container || serviceId)
  }
  catch (err) {
    console.error('Restart failed:', err)
  }
}

const handleRebuild = async (serviceId: string) => {
  try {
    await statusStore.rebuildContainer(props.service.container || serviceId)
  }
  catch (err) {
    console.error('Rebuild failed:', err)
  }
}

const handleLogs = (serviceId: string) => {
  // Navigate to logs page
  navigateTo(`/logs/${props.service.container || serviceId}`)
}
</script>

<template>
  <BCard class="service-card h-100">
    <template #header>
      <div class="d-flex justify-content-between align-items-center">
        <span class="fw-bold">{{ service.name }}</span>
        <ServiceCpu :cpu="cpu" />
      </div>
    </template>

    <BCardText class="text-muted small mb-2">
      {{ service.description }}
    </BCardText>

    <div v-if="service.url" class="mb-2">
      <a :href="service.url" target="_blank" class="small text-break">
        {{ service.url }}
      </a>
    </div>

    <div v-if="service.credentials" class="credentials-display mb-2">
      <span class="label">User:</span>{{ service.credentials.username }}
      <span class="label ms-2">Pass:</span>{{ service.credentials.password }}
    </div>

    <div v-if="state.uptime" class="small text-muted mb-2">
      Uptime: {{ state.uptime }}
    </div>

    <template #footer>
      <div class="d-flex justify-content-between align-items-center">
        <ServiceStatus :status="state.status" :message="state.message" />
        <ServiceActions
          :service="service"
          :can-manage="canManage"
          @restart="handleRestart"
          @rebuild="handleRebuild"
          @logs="handleLogs"
        />
      </div>
    </template>
  </BCard>
</template>
