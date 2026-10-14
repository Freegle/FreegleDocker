<template>
  <ModSysAdminUxPanel
    title="What members say"
    explanation="Microvolunteers are asked which part of Freegle works best and worst for them, and whether they know features exist. They tend to prefer what they know, so read this as how members feel, not as a verdict."
    :loading="store.experiments.loading"
    :error="store.experiments.error"
    :empty="!surveys.length"
    empty-text="No survey or awareness questions have been asked yet."
  >
    <div v-for="s in surveys" :key="s.prompt_id" class="survey mb-4">
      <h6 class="mb-1">
        {{ s.question }}
        <span class="text-muted small fw-normal">
          ({{ s.responses }} answers{{
            s.status === 'open' ? ', still open' : ''
          }})
        </span>
      </h6>
      <b-table
        v-if="s.kind === 'survey'"
        :items="surveyRows(s)"
        :fields="surveyFields"
        small
        responsive
      />
      <b-table
        v-else
        :items="awarenessRows(s)"
        :fields="awarenessFields"
        small
        responsive
      />
      <p v-if="s.dont_care || s.dont_understand" class="small text-muted mb-1">
        {{ s.dont_care }} said none of these bother them;
        {{ s.dont_understand }} did not understand the question.
      </p>
      <ul v-if="s.comments?.length" class="small comments mb-0">
        <li v-for="(c, i) in s.comments.slice(0, 8)" :key="i">{{ c }}</li>
      </ul>
    </div>
  </ModSysAdminUxPanel>
</template>

<script setup>
import { computed } from 'vue'
import { useUxStore } from '~/modtools/stores/ux'
import ModSysAdminUxPanel from '~/modtools/components/ModSysAdminUxPanel.vue'

const store = useUxStore()

const surveys = computed(() => store.experiments.data?.surveys || [])

const surveyFields = [
  { key: 'label', label: 'Area' },
  { key: 'best', label: 'Works best' },
  { key: 'worst', label: 'Most needs work' },
]
const awarenessFields = [
  { key: 'label', label: 'Answer' },
  { key: 'count', label: 'Members' },
]

function surveyRows(s) {
  const total = s.responses || 0
  return (s.tallies || [])
    .slice()
    .sort((a, b) => b.worst - a.worst)
    .map((t) => ({
      label: t.label,
      best: total ? `${t.best} (${Math.round((t.best / total) * 100)}%)` : '0',
      worst: total
        ? `${t.worst} (${Math.round((t.worst / total) * 100)}%)`
        : '0',
    }))
}

function awarenessRows(s) {
  const total = s.responses || 0
  return (s.tallies || []).map((t) => ({
    label: t.label,
    count: total ? `${t.count} (${Math.round((t.count / total) * 100)}%)` : '0',
  }))
}
</script>

<style scoped lang="scss">
.comments {
  padding-left: 1.2rem;
}
</style>
