<script setup lang="ts">
import { useStatusStore } from '~/stores/status'

const route = useRoute()
const statusStore = useStatusStore()

// Service IDs grouped by tab/category
const tabServices: Record<string, string[]> = {
  freegle: ['freegle-dev-local', 'freegle-dev-live', 'freegle-prod-local'],
  modtools: ['modtools-dev-local', 'modtools-dev-live', 'modtools-prod-local'],
  backend: ['apiv1', 'apiv2', 'batch', 'host-scripts'],
  devtools: ['phpmyadmin', 'mailpit', 'loki', 'grafana', 'playwright', 'status'],
  testing: [], // Testing tab shows test runners, not services
  infrastructure: ['percona', 'postgres', 'redis', 'beanstalkd', 'spamassassin', 'traefik', 'tusd', 'delivery'],
  production: ['freegle-dev-live', 'modtools-dev-live'], // Live/production services
}

const tabs = [
  { name: 'Freegle', path: '/freegle', id: 'freegle' },
  { name: 'ModTools', path: '/modtools', id: 'modtools' },
  { name: 'Backend', path: '/backend', id: 'backend' },
  { name: 'Dev Tools', path: '/devtools', id: 'devtools' },
  { name: 'Testing', path: '/testing', id: 'testing' },
  { name: 'Infrastructure', path: '/infrastructure', id: 'infrastructure' },
  { name: 'Production', path: '/production', id: 'production', badge: 'LIVE' },
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

// Get tab status color based on services in that category
const getTabStatus = (tabId: string): string => {
  const serviceIds = tabServices[tabId] || []
  if (serviceIds.length === 0) {
    // For testing tab with no services, return green
    return 'green'
  }

  let online = 0
  let offline = 0
  let total = 0

  for (const id of serviceIds) {
    const state = statusStore.getServiceState(id)
    if (state) {
      total++
      if (state.status === 'online') online++
      else if (state.status === 'offline') offline++
    }
  }

  if (total === 0) return 'grey'
  if (online === total) return 'green'
  if (offline === total) return 'red'
  return 'amber'
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
        <h1>Freegle Status</h1>
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
          <span v-if="tab.badge" class="live-badge">{{ tab.badge }}</span>
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
