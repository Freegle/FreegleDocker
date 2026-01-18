<script setup lang="ts">
import type { ServiceStatus } from '~/types/service'

const props = defineProps<{
  status: ServiceStatus
  message?: string
}>()

const statusVariant = computed(() => {
  switch (props.status) {
    case 'online': return 'success'
    case 'offline': return 'danger'
    case 'loading': return 'warning'
    default: return 'secondary'
  }
})

const statusText = computed(() => {
  switch (props.status) {
    case 'online': return 'Online'
    case 'offline': return 'Offline'
    case 'loading': return 'Starting...'
    default: return 'Unknown'
  }
})
</script>

<template>
  <div class="d-flex align-items-center gap-2">
    <span class="status-indicator" :class="status" />
    <BBadge :variant="statusVariant">
      {{ statusText }}
    </BBadge>
    <small v-if="message" class="text-muted">{{ message }}</small>
  </div>
</template>
