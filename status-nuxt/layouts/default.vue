<script setup lang="ts">
import { useStatusStore } from '~/stores/status'

const route = useRoute()
const statusStore = useStatusStore()

const tabs = [
  { name: 'Freegle', path: '/freegle', id: 'freegle' },
  { name: 'ModTools', path: '/modtools', id: 'modtools' },
  { name: 'Backend', path: '/backend', id: 'backend' },
  { name: 'Dev Tools', path: '/devtools', id: 'devtools' },
  { name: 'Testing', path: '/testing', id: 'testing' },
  { name: 'Infrastructure', path: '/infrastructure', id: 'infrastructure' },
]

const currentTab = computed(() => {
  return tabs.find(tab => route.path.startsWith(tab.path))?.path || '/freegle'
})

// Overall status for the circle indicator
const overallStatus = computed(() => {
  const online = statusStore.onlineCount
  const total = statusStore.totalCount
  if (total === 0) return 'amber'
  if (online === total) return 'green'
  if (online === 0) return 'red'
  return 'amber'
})

// Get tab status color
const getTabStatus = (tabId: string): string => {
  // This would need to be computed based on services in each category
  // For now return grey as default
  return 'grey'
}
</script>

<template>
  <div class="min-vh-100" style="background: #f5f5f5;">
    <div class="container-main">
      <!-- Header with logo and status circle -->
      <div class="status-header">
        <img
          src="https://www.ilovefreegle.org/icon.png"
          alt="Freegle Logo"
          class="logo"
        >
        <div :class="['status-circle', overallStatus]" />
        <h1>Freegle Environment Status</h1>
      </div>

      <!-- Countdown -->
      <div class="refresh-info">
        <LayoutCountdown />
      </div>

      <!-- Tab Navigation -->
      <nav class="status-tabs">
        <NuxtLink
          v-for="tab in tabs"
          :key="tab.path"
          :to="tab.path"
          class="nav-link"
          :class="{ active: currentTab === tab.path }"
        >
          <span :class="['tab-light', getTabStatus(tab.id)]" />
          {{ tab.name }}
        </NuxtLink>
      </nav>

      <!-- Main Content -->
      <main>
        <slot />
      </main>

      <!-- Footer -->
      <footer class="status-footer">
        Freegle Docker Development Environment
      </footer>
    </div>
  </div>
</template>
