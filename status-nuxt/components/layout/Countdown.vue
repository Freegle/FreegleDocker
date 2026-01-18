<script setup lang="ts">
import { useStatusStore } from '~/stores/status'

const statusStore = useStatusStore()

const secondsUntilRefresh = ref(30)
let intervalId: ReturnType<typeof setInterval> | null = null

onMounted(() => {
  intervalId = setInterval(() => {
    secondsUntilRefresh.value--
    if (secondsUntilRefresh.value <= 0) {
      statusStore.refreshStatus()
      secondsUntilRefresh.value = 30
    }
  }, 1000)
})

onUnmounted(() => {
  if (intervalId) {
    clearInterval(intervalId)
  }
})

const refreshNow = () => {
  statusStore.refreshStatus()
  secondsUntilRefresh.value = 30
}
</script>

<template>
  <div class="countdown-timer d-flex align-items-center gap-2">
    <span>Next refresh in</span>
    <span class="countdown-value">{{ secondsUntilRefresh }}s</span>
    <button
      class="btn btn-sm btn-outline-light"
      title="Refresh now"
      @click="refreshNow"
    >
      🔄
    </button>
  </div>
</template>
