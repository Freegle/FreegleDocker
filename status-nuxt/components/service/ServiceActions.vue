<script setup lang="ts">
import type { ServiceConfig, ServiceAction } from '~/types/service'

const props = defineProps<{
  service: ServiceConfig
  canManage?: boolean
}>()

const emit = defineEmits<{
  restart: [serviceId: string]
  rebuild: [serviceId: string]
  logs: [serviceId: string]
}>()

const hasAction = (action: ServiceAction) => {
  return props.service.actions.includes(action)
}

const isRestarting = ref(false)
const isRebuilding = ref(false)

const handleRestart = async () => {
  if (isRestarting.value) return
  isRestarting.value = true
  try {
    emit('restart', props.service.id)
  }
  finally {
    setTimeout(() => {
      isRestarting.value = false
    }, 3000)
  }
}

const handleRebuild = async () => {
  if (isRebuilding.value) return
  isRebuilding.value = true
  emit('rebuild', props.service.id)
  // Rebuild takes longer, don't auto-reset
}

const openUrl = () => {
  if (props.service.url) {
    window.open(props.service.url, '_blank')
  }
}

const openLogs = () => {
  emit('logs', props.service.id)
}
</script>

<template>
  <BButtonGroup size="sm">
    <BButton
      v-if="hasAction('visit') && service.url"
      variant="outline-primary"
      title="Open in browser"
      @click="openUrl"
    >
      🔗
    </BButton>
    <BButton
      v-if="hasAction('logs')"
      variant="outline-secondary"
      title="View logs"
      @click="openLogs"
    >
      📋
    </BButton>
    <BButton
      v-if="hasAction('restart') && canManage"
      variant="outline-warning"
      :disabled="isRestarting"
      title="Restart container"
      @click="handleRestart"
    >
      {{ isRestarting ? '⏳' : '🔄' }}
    </BButton>
    <BButton
      v-if="hasAction('rebuild') && canManage"
      variant="outline-danger"
      :disabled="isRebuilding"
      title="Rebuild container"
      @click="handleRebuild"
    >
      {{ isRebuilding ? '⏳' : '🏗️' }}
    </BButton>
  </BButtonGroup>
</template>
