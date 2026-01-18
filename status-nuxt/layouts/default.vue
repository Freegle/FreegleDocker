<script setup lang="ts">
const route = useRoute()

const tabs = [
  { name: 'Freegle', path: '/freegle', icon: '🏠' },
  { name: 'ModTools', path: '/modtools', icon: '🔧' },
  { name: 'Backend', path: '/backend', icon: '⚙️' },
  { name: 'Dev Tools', path: '/devtools', icon: '🛠️' },
  { name: 'Testing', path: '/testing', icon: '🧪' },
  { name: 'Infrastructure', path: '/infrastructure', icon: '🏗️' },
]

const currentTab = computed(() => {
  return tabs.find(tab => route.path.startsWith(tab.path))?.path || '/freegle'
})
</script>

<template>
  <div class="d-flex flex-column min-vh-100">
    <!-- Header -->
    <header class="bg-primary text-white py-3">
      <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center">
          <div class="d-flex align-items-center gap-3">
            <h1 class="h4 mb-0">Freegle Status</h1>
            <LayoutTrafficLight />
          </div>
          <div class="d-flex align-items-center gap-3">
            <LayoutCountdown />
          </div>
        </div>
      </div>
    </header>

    <!-- Navigation Tabs -->
    <nav class="bg-light border-bottom">
      <div class="container-fluid">
        <ul class="nav nav-tabs status-tabs border-0">
          <li v-for="tab in tabs" :key="tab.path" class="nav-item">
            <NuxtLink
              :to="tab.path"
              class="nav-link"
              :class="{ active: currentTab === tab.path }"
            >
              <span class="me-1">{{ tab.icon }}</span>
              {{ tab.name }}
            </NuxtLink>
          </li>
        </ul>
      </div>
    </nav>

    <!-- Main Content -->
    <main class="flex-grow-1 py-4">
      <div class="container-fluid">
        <slot />
      </div>
    </main>

    <!-- Footer -->
    <footer class="bg-light border-top py-2 text-center text-muted small">
      <div class="container-fluid">
        Freegle Docker Development Environment
      </div>
    </footer>
  </div>
</template>
