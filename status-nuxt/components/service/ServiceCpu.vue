<script setup lang="ts">
const props = defineProps<{
  cpu?: number
}>()

const cpuClass = computed(() => {
  if (props.cpu === undefined) return ''
  if (props.cpu < 30) return 'low'
  if (props.cpu < 70) return 'medium'
  return 'high'
})

const cpuDisplay = computed(() => {
  if (props.cpu === undefined) return '-'
  return `${props.cpu.toFixed(1)}%`
})
</script>

<template>
  <span v-if="cpu !== undefined" class="cpu-indicator" :class="cpuClass">
    CPU: {{ cpuDisplay }}
  </span>
</template>
