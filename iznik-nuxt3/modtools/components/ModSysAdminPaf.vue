<template>
  <div>
    <p class="text-muted">
      Royal Mail address data (PAF) is used for the address picker. A new
      release comes out each month. AFD email the board an unlock key for it.
      Paste that key here to download the release and apply it. It takes a few
      hours, and runs on the server, so you can leave this page.
    </p>

    <b-card v-if="!active" class="mb-3">
      <b-form @submit.prevent="start">
        <b-form-group
          label="Unlock key"
          label-for="paf-key"
          description="As in the email, for example 7E7 / 3W7 / RRJ / KCM / 2AJ / 0QW / J4G / P4J"
        >
          <b-form-input
            id="paf-key"
            v-model="key"
            autocomplete="off"
            spellcheck="false"
          />
        </b-form-group>
        <b-form-group
          label="Release"
          label-for="paf-edition"
          description="Leave empty for the latest. For an older one, give its folder name on the AFD site, for example Y26M08 for August 2026."
        >
          <b-form-input
            id="paf-edition"
            v-model="edition"
            autocomplete="off"
            class="edition-input"
          />
        </b-form-group>
        <b-button type="submit" variant="primary" :disabled="starting">
          <b-spinner v-if="starting" small class="me-1" />
          Download and apply
        </b-button>
        <div v-if="startError" class="text-danger mt-2">
          {{ startError }}
        </div>
      </b-form>
    </b-card>

    <div v-if="loading" class="text-center py-3">
      <b-spinner />
    </div>
    <div v-else-if="error" class="text-danger">Failed to load: {{ error }}</div>
    <div v-else>
      <b-card v-if="active" class="mb-3 current-import">
        <h5>
          {{ stepLabel(active.status) }}
          <span v-if="active.edition" class="text-muted">
            ({{ active.edition }})
          </span>
        </h5>
        <div class="steps mb-2">
          <span
            v-for="(step, i) in steps"
            :key="step.status"
            :class="stepClass(active.status, i)"
          >
            {{ step.label }}
          </span>
        </div>
        <b-progress
          :value="active.progress"
          max="100"
          show-progress
          animated
          class="mb-2"
        />
        <div v-if="active.detail" class="text-muted">{{ active.detail }}</div>
      </b-card>

      <h5 v-if="imports.length">Recent imports</h5>
      <b-table-simple v-if="imports.length" hover responsive small>
        <b-thead>
          <b-tr>
            <b-th>Requested</b-th>
            <b-th>Release</b-th>
            <b-th>Status</b-th>
            <b-th>Lines</b-th>
            <b-th>New</b-th>
            <b-th>Changed</b-th>
            <b-th>Waiting</b-th>
          </b-tr>
        </b-thead>
        <b-tbody>
          <b-tr
            v-for="imp in imports"
            :key="imp.id"
            :class="{ 'table-danger': imp.status === 'failed' }"
          >
            <b-td class="text-nowrap">{{ timeago(imp.created_at) }}</b-td>
            <b-td>{{ imp.edition || 'Latest' }}</b-td>
            <b-td>
              {{ stepLabel(imp.status) }}
              <div v-if="imp.error" class="small">{{ imp.error }}</div>
            </b-td>
            <b-td>
              <span v-if="imp.lines_to_apply !== null">
                {{ imp.lines_applied.toLocaleString() }} of
                {{ imp.lines_to_apply.toLocaleString() }}
              </span>
            </b-td>
            <b-td>{{ imp.inserted.toLocaleString() }}</b-td>
            <b-td>{{ imp.updated.toLocaleString() }}</b-td>
            <b-td :title="waitingHelp">{{ imp.skipped.toLocaleString() }}</b-td>
          </b-tr>
        </b-tbody>
      </b-table-simple>
      <p v-else class="text-muted">No imports yet.</p>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import api from '~/api'
import { timeago } from '~/composables/useTimeFormat'

// Polled while an import is running, so the progress bar moves.
const POLL_MS = 5000

const steps = [
  { status: 'queued', label: 'Waiting' },
  { status: 'downloading', label: 'Download' },
  { status: 'extracting', label: 'Unlock' },
  { status: 'comparing', label: 'Compare' },
  { status: 'fetching', label: 'Fetch' },
  { status: 'applying', label: 'Apply' },
]

const labels = {
  queued: 'Waiting to start',
  downloading: 'Downloading from AFD',
  extracting: 'Unlocking and extracting',
  comparing: 'Finding what has changed',
  fetching: 'Fetching the changes',
  applying: 'Applying to the database',
  done: 'Done',
  failed: 'Failed',
}

const waitingHelp =
  'Addresses whose postcode we do not have yet. They are tried again with the next release.'

const runtimeConfig = useRuntimeConfig()
const apiInstance = api(runtimeConfig)

const loading = ref(true)
const error = ref(null)
const imports = ref([])
const key = ref('')
const edition = ref('')
const starting = ref(false)
const startError = ref(null)
let timer = null

const active = computed(() =>
  imports.value.find((i) => i.status !== 'done' && i.status !== 'failed')
)

function stepLabel(status) {
  return labels[status] || status
}

function stepClass(status, i) {
  const current = steps.findIndex((s) => s.status === status)
  if (i < current) return 'step step-done'
  if (i === current) return 'step step-current'
  return 'step'
}

async function fetchImports(quiet = false) {
  if (!quiet) loading.value = true
  error.value = null

  try {
    imports.value = (await apiInstance.paf.fetchImports()) || []
  } catch (e) {
    error.value = e.message || 'Unknown error'
  } finally {
    loading.value = false
  }
}

async function start() {
  starting.value = true
  startError.value = null

  try {
    await apiInstance.paf.startImport(key.value, edition.value.trim())
    key.value = ''
    edition.value = ''
    await fetchImports(true)
  } catch (e) {
    startError.value =
      e.response?.data?.error || e.message || 'Could not start the import'
  } finally {
    starting.value = false
  }
}

onMounted(() => {
  fetchImports()
  timer = setInterval(() => {
    if (active.value) fetchImports(true)
  }, POLL_MS)
})

onUnmounted(() => {
  if (timer) clearInterval(timer)
})
</script>

<style scoped>
.edition-input {
  max-width: 10rem;
}

.steps {
  display: flex;
  flex-wrap: wrap;
  gap: 0.25rem 1rem;
}

.step {
  color: #6c757d;
}

.step-done {
  color: #2e7d32;
}

.step-current {
  color: #212529;
  font-weight: 600;
}
</style>
