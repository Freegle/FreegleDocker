import { defineStore } from 'pinia'
import type { ServiceState, ServiceStatus } from '~/types/service'

interface StatusStoreState {
  serviceStates: Map<string, ServiceState>
  cpuStats: Map<string, number>
  isLoading: boolean
  lastUpdated: Date | null
  error: string | null
}

export const useStatusStore = defineStore('status', {
  state: (): StatusStoreState => ({
    serviceStates: new Map(),
    cpuStats: new Map(),
    isLoading: false,
    lastUpdated: null,
    error: null,
  }),

  getters: {
    getServiceState: (state) => (serviceId: string): ServiceState | undefined => {
      return state.serviceStates.get(serviceId)
    },

    getServiceStatus: (state) => (serviceId: string): ServiceStatus => {
      return state.serviceStates.get(serviceId)?.status || 'unknown'
    },

    getCpuUsage: (state) => (serviceId: string): number | undefined => {
      return state.cpuStats.get(serviceId)
    },

    onlineCount: (state): number => {
      let count = 0
      for (const s of state.serviceStates.values()) {
        if (s.status === 'online') count++
      }
      return count
    },

    offlineCount: (state): number => {
      let count = 0
      for (const s of state.serviceStates.values()) {
        if (s.status === 'offline') count++
      }
      return count
    },

    totalCount: (state): number => {
      return state.serviceStates.size
    },
  },

  actions: {
    async refreshStatus() {
      this.isLoading = true
      this.error = null

      try {
        const response = await $fetch<Record<string, any>>('/api/status')

        // Update service states
        this.serviceStates.clear()
        for (const [id, data] of Object.entries(response.services || {})) {
          this.serviceStates.set(id, {
            id,
            status: data.status as ServiceStatus,
            message: data.message,
            uptime: data.uptime,
            lastChecked: new Date(),
          })
        }

        // Update CPU stats if available
        if (response.cpu) {
          this.cpuStats.clear()
          for (const [id, cpu] of Object.entries(response.cpu)) {
            this.cpuStats.set(id, cpu as number)
          }
        }

        this.lastUpdated = new Date()
      }
      catch (err) {
        this.error = err instanceof Error ? err.message : 'Failed to fetch status'
        console.error('Status refresh error:', err)
      }
      finally {
        this.isLoading = false
      }
    },

    async restartContainer(containerId: string) {
      try {
        await $fetch('/api/container/restart', {
          method: 'POST',
          body: { container: containerId },
        })
        // Refresh status after restart
        setTimeout(() => this.refreshStatus(), 2000)
      }
      catch (err) {
        console.error('Restart error:', err)
        throw err
      }
    },

    async rebuildContainer(containerId: string) {
      try {
        await $fetch('/api/container/rebuild', {
          method: 'POST',
          body: { container: containerId },
        })
      }
      catch (err) {
        console.error('Rebuild error:', err)
        throw err
      }
    },
  },
})
