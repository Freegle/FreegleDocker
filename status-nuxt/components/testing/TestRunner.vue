<script setup lang="ts">
import { testConfigs, type TestType } from '~/types/test'
import { useTestStore } from '~/stores/tests'

const props = defineProps<{
  testType: TestType
}>()

const testStore = useTestStore()

const config = computed(() => testConfigs[props.testType])
const state = computed(() => testStore.getTestState(props.testType))

const filter = ref('')
const showLogs = ref(false)

const statusVariant = computed(() => {
  switch (state.value.status) {
    case 'running': return 'primary'
    case 'completed': return 'success'
    case 'failed': return 'danger'
    default: return 'secondary'
  }
})

const progressPercent = computed(() => {
  const { total, completed } = state.value.progress
  if (total === 0) return 0
  return Math.round((completed / total) * 100)
})

const progressVariant = computed(() => {
  const { failed } = state.value.progress
  if (failed > 0) return 'danger'
  return 'success'
})

const handleRun = () => {
  testStore.runTests(props.testType, filter.value || undefined)
  showLogs.value = true
}

const openReport = () => {
  if (state.value.reportUrl) {
    window.open(state.value.reportUrl, '_blank')
  }
}
</script>

<template>
  <BCard class="test-runner">
    <template #header>
      <div class="d-flex justify-content-between align-items-center">
        <span class="fw-bold">{{ config.name }}</span>
        <BBadge :variant="statusVariant">{{ state.status }}</BBadge>
      </div>
    </template>

    <BCardText class="text-muted small mb-3">
      {{ config.description }}
    </BCardText>

    <!-- Filter input -->
    <BFormGroup label="Filter (optional)" label-class="small" class="mb-3">
      <BFormInput
        v-model="filter"
        :placeholder="config.filterPlaceholder"
        :disabled="state.status === 'running'"
        size="sm"
      />
    </BFormGroup>

    <!-- Progress bar -->
    <div v-if="state.status === 'running'" class="mb-3">
      <div class="d-flex justify-content-between small mb-1">
        <span>Progress</span>
        <span>
          {{ state.progress.completed }}/{{ state.progress.total }}
          ({{ state.progress.passed }} passed, {{ state.progress.failed }} failed)
        </span>
      </div>
      <BProgress :max="100" height="1.5rem">
        <BProgressBar :value="progressPercent" :variant="progressVariant">
          {{ progressPercent }}%
        </BProgressBar>
      </BProgress>
      <div v-if="state.progress.current" class="small text-muted mt-1">
        Running: {{ state.progress.current }}
      </div>
    </div>

    <!-- Action buttons -->
    <div class="d-flex gap-2">
      <BButton
        :variant="state.status === 'running' ? 'secondary' : 'primary'"
        :disabled="state.status === 'running'"
        @click="handleRun"
      >
        {{ state.status === 'running' ? 'Running...' : 'Run Tests' }}
      </BButton>

      <BButton
        v-if="config.hasReport && state.reportUrl"
        variant="outline-secondary"
        @click="openReport"
      >
        View Report
      </BButton>

      <BButton
        variant="outline-secondary"
        @click="showLogs = !showLogs"
      >
        {{ showLogs ? 'Hide' : 'Show' }} Logs
      </BButton>
    </div>

    <!-- Logs viewer -->
    <BCollapse v-model="showLogs" class="mt-3">
      <div class="test-logs">
        <pre v-if="state.logs">{{ state.logs }}</pre>
        <div v-else class="text-muted">No logs yet</div>
      </div>
    </BCollapse>

    <!-- Status message -->
    <div v-if="state.message" class="mt-3 small" :class="state.status === 'failed' ? 'text-danger' : 'text-muted'">
      {{ state.message }}
    </div>
  </BCard>
</template>
