<template>
  <div class="container-fluid py-3">
    <h2>Unknown Log Fields</h2>
    <p class="text-muted">
      Fields encountered in logs that aren't in the schema. Classify them as
      PUBLIC (safe) or SENSITIVE (contains PII).
    </p>

    <!-- Summary -->
    <div class="row mb-4">
      <div class="col-md-3">
        <div class="card bg-warning text-dark">
          <div class="card-body text-center">
            <h3>{{ totalUnclassified }}</h3>
            <div>Unclassified</div>
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card bg-success text-white">
          <div class="card-body text-center">
            <h3>{{ totalClassified }}</h3>
            <div>Classified</div>
          </div>
        </div>
      </div>
      <div class="col-md-6">
        <div class="card">
          <div class="card-body">
            <strong>By Source:</strong>
            <div class="d-flex flex-wrap gap-2 mt-2">
              <span
                v-for="(counts, source) in summary"
                :key="source"
                class="badge"
                :class="
                  counts.unclassified > 0 ? 'bg-warning text-dark' : 'bg-secondary'
                "
              >
                {{ source }}: {{ counts.unclassified }}/{{ counts.total }}
              </span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Actions -->
    <div class="mb-3 d-flex gap-2">
      <button class="btn btn-primary" @click="refresh">Refresh</button>
      <button
        class="btn btn-outline-secondary"
        @click="showCode = !showCode"
      >
        {{ showCode ? 'Hide' : 'Show' }} Code Snippet
      </button>
      <button
        v-if="totalClassified > 0"
        class="btn btn-outline-danger"
        @click="clearClassified"
      >
        Clear Classified ({{ totalClassified }})
      </button>
    </div>

    <!-- Code snippet -->
    <div v-if="showCode" class="mb-4">
      <div class="card">
        <div class="card-header">
          Code to add to log-schema.ts
          <button
            class="btn btn-sm btn-outline-secondary float-end"
            @click="copyCode"
          >
            Copy
          </button>
        </div>
        <div class="card-body">
          <pre class="mb-0"><code>{{ codeSnippet }}</code></pre>
        </div>
      </div>
    </div>

    <!-- Fields table -->
    <div class="card">
      <div class="card-header">
        <span class="badge bg-secondary me-2">{{ fields.length }}</span>
        Unknown Fields
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
          <thead>
            <tr>
              <th>Source</th>
              <th>Field</th>
              <th>Count</th>
              <th>First Seen</th>
              <th>Sample Values</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="field in fields"
              :key="`${field.source}:${field.field}`"
              :class="{ 'table-success': field.classified }"
            >
              <td>
                <code>{{ field.source }}</code>
              </td>
              <td>
                <code>{{ field.field }}</code>
              </td>
              <td>{{ field.count.toLocaleString() }}</td>
              <td>
                <small>{{ formatDate(field.firstSeen) }}</small>
              </td>
              <td>
                <small class="text-muted">
                  {{ field.sampleValues.slice(0, 3).join(', ') }}
                  <span v-if="field.sampleValues.length > 3">...</span>
                </small>
              </td>
              <td>
                <span
                  v-if="field.classified"
                  class="badge"
                  :class="
                    field.classified === 'PUBLIC' ? 'bg-info' : 'bg-danger'
                  "
                >
                  {{ field.classified }}
                </span>
                <span v-else class="badge bg-warning text-dark">
                  UNKNOWN
                </span>
              </td>
              <td>
                <div v-if="!field.classified" class="btn-group btn-group-sm">
                  <button
                    class="btn btn-outline-info"
                    @click="classify(field, 'PUBLIC')"
                    title="Safe - no PII"
                  >
                    PUBLIC
                  </button>
                  <button
                    class="btn btn-outline-danger"
                    @click="classify(field, 'SENSITIVE')"
                    title="Contains PII - pseudonymize"
                  >
                    SENSITIVE
                  </button>
                </div>
                <small v-else class="text-muted">
                  by {{ field.classifiedBy || 'unknown' }}
                </small>
              </td>
            </tr>
            <tr v-if="fields.length === 0">
              <td colspan="7" class="text-center text-muted py-4">
                No unknown fields. Schema is up to date!
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
interface UnknownField {
  source: string
  field: string
  firstSeen: string
  lastSeen: string
  sampleValues: string[]
  count: number
  classified?: 'PUBLIC' | 'SENSITIVE'
  classifiedAt?: string
  classifiedBy?: string
}

interface ApiResponse {
  summary: Record<string, { total: number; unclassified: number }>
  fields: UnknownField[]
  totalUnclassified: number
  totalClassified: number
}

const summary = ref<Record<string, { total: number; unclassified: number }>>({})
const fields = ref<UnknownField[]>([])
const totalUnclassified = ref(0)
const totalClassified = ref(0)
const showCode = ref(false)
const codeSnippet = ref('')

async function refresh() {
  try {
    const data = await $fetch<ApiResponse>('/api/mcp/unknown-fields')
    summary.value = data.summary
    fields.value = data.fields
    totalUnclassified.value = data.totalUnclassified
    totalClassified.value = data.totalClassified

    // Also fetch code snippet
    const code = await $fetch<string>('/api/mcp/unknown-fields?format=code')
    codeSnippet.value = code
  } catch (err) {
    console.error('Failed to fetch unknown fields:', err)
  }
}

async function classify(
  field: UnknownField,
  classification: 'PUBLIC' | 'SENSITIVE'
) {
  try {
    const result = await $fetch('/api/mcp/unknown-fields', {
      method: 'POST',
      body: {
        action: 'classify',
        source: field.source,
        field: field.field,
        classification,
        classifiedBy: 'admin', // Could get from auth
      },
    })

    // Refresh to get updated list
    await refresh()

    // Update code snippet
    if ((result as any).codeSnippet) {
      codeSnippet.value = (result as any).codeSnippet
    }
  } catch (err) {
    console.error('Failed to classify field:', err)
    alert('Failed to classify field')
  }
}

async function clearClassified() {
  if (!confirm('Clear all classified fields from the tracker?')) {
    return
  }

  try {
    await $fetch('/api/mcp/unknown-fields', {
      method: 'POST',
      body: { action: 'clear-classified' },
    })

    await refresh()
  } catch (err) {
    console.error('Failed to clear classified:', err)
    alert('Failed to clear classified fields')
  }
}

function copyCode() {
  navigator.clipboard.writeText(codeSnippet.value)
  alert('Code copied to clipboard')
}

function formatDate(iso: string): string {
  const date = new Date(iso)
  return date.toLocaleDateString() + ' ' + date.toLocaleTimeString()
}

// Load on mount
onMounted(() => {
  refresh()
})
</script>

<style scoped>
pre {
  background: #f8f9fa;
  padding: 1rem;
  border-radius: 4px;
  overflow-x: auto;
}
</style>
