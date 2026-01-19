<template>
  <div class="p-4">
    <h1 class="text-2xl font-bold mb-4">MCP Log Query</h1>

    <div class="mb-4 p-4 bg-blue-50 rounded">
      <p class="text-sm text-blue-800">
        Query Freegle logs with automatic pseudonymization.
        Emails and IPs are replaced with tokens for GDPR compliance.
      </p>
    </div>

    <div class="mb-4">
      <label class="block text-sm font-medium mb-1">LogQL Query</label>
      <input
        v-model="query"
        type="text"
        class="w-full p-2 border rounded"
        placeholder='{job="freegle"} |= "error"'
      >
    </div>

    <div class="flex gap-4 mb-4">
      <div>
        <label class="block text-sm font-medium mb-1">Time Range</label>
        <select v-model="timeRange" class="p-2 border rounded">
          <option value="15m">15 minutes</option>
          <option value="1h">1 hour</option>
          <option value="6h">6 hours</option>
          <option value="24h">24 hours</option>
          <option value="7d">7 days</option>
        </select>
      </div>
      <div>
        <label class="block text-sm font-medium mb-1">Limit</label>
        <input v-model.number="limit" type="number" class="w-24 p-2 border rounded">
      </div>
      <div class="flex items-end">
        <label class="flex items-center gap-2">
          <input v-model="debug" type="checkbox">
          <span class="text-sm">Debug mode</span>
        </label>
      </div>
    </div>

    <button
      class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 disabled:opacity-50"
      :disabled="loading || !query"
      @click="runQuery"
    >
      {{ loading ? 'Querying...' : 'Run Query' }}
    </button>

    <div v-if="error" class="mt-4 p-4 bg-red-50 text-red-800 rounded">
      {{ error }}
    </div>

    <div v-if="results" class="mt-4">
      <h2 class="text-lg font-semibold mb-2">
        Results ({{ resultCount }} entries)
        <span v-if="sessionId" class="text-sm font-normal text-gray-500">
          Session: {{ sessionId }}
        </span>
      </h2>

      <div class="bg-gray-900 text-green-400 p-4 rounded font-mono text-sm overflow-x-auto max-h-96 overflow-y-auto">
        <div v-for="(stream, i) in results" :key="i" class="mb-4">
          <div class="text-yellow-400 mb-2">{{ JSON.stringify(stream.stream) }}</div>
          <div v-for="(entry, j) in stream.values" :key="j" class="pl-4 mb-1">
            <span class="text-gray-500">{{ formatTimestamp(entry[0]) }}</span>
            <span class="ml-2">{{ entry[1] }}</span>
          </div>
        </div>
      </div>
    </div>

    <div v-if="debug && debugInfo" class="mt-4">
      <h2 class="text-lg font-semibold mb-2">Debug Info</h2>
      <pre class="bg-gray-100 p-4 rounded text-sm overflow-x-auto">{{ JSON.stringify(debugInfo, null, 2) }}</pre>
    </div>
  </div>
</template>

<script setup lang="ts">
const query = ref('{job="freegle"}')
const timeRange = ref('1h')
const limit = ref(100)
const debug = ref(false)
const loading = ref(false)
const error = ref('')
const results = ref<any[] | null>(null)
const resultCount = ref(0)
const sessionId = ref('')
const debugInfo = ref<any>(null)

function formatTimestamp(ns: string): string {
  const ms = parseInt(ns) / 1000000
  return new Date(ms).toISOString().replace('T', ' ').slice(0, 19)
}

async function runQuery() {
  loading.value = true
  error.value = ''
  results.value = null
  debugInfo.value = null

  try {
    const response = await fetch('/api/mcp/query', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        query: query.value,
        start: timeRange.value,
        limit: limit.value,
        debug: debug.value,
      }),
    })

    const data = await response.json()

    if (!response.ok) {
      throw new Error(data.message || 'Query failed')
    }

    results.value = data.data?.result || []
    resultCount.value = results.value.reduce((sum: number, s: any) => sum + (s.values?.length || 0), 0)
    sessionId.value = data.sessionId || ''

    if (debug.value && data.debug) {
      debugInfo.value = data.debug
    }
  } catch (err: any) {
    error.value = err.message
  } finally {
    loading.value = false
  }
}
</script>
