<template>
  <ModSysAdminUxPanel
    title="What members say"
    explanation="Microvolunteers are asked which part of Freegle works best and worst for them, whether they know features exist, and what gets in their way. They tend to prefer what they know, so read this as how members feel, not as a verdict. Skipped questions are counted separately and are in none of the figures."
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
        :fields="surveyFieldsFor(s)"
        small
        responsive
      />
      <p v-else-if="s.kind === 'open'" class="small mb-1 open-counts">
        {{ openText(s) }}
      </p>
      <b-table
        v-else
        :items="awarenessRows(s)"
        :fields="awarenessFields"
        small
        responsive
      />
      <p
        v-if="s.kind !== 'open' && (s.dont_care || s.dont_understand)"
        class="small text-muted mb-1"
      >
        {{ escapeText(s) }}
      </p>
      <p v-if="s.skipped" class="small text-muted mb-1">
        {{ s.skipped }} skipped it.
      </p>
      <ModSysAdminUxSaid
        v-if="s.comments?.length"
        :comments="s.comments"
        :limit="8"
        :capped="s.comments.length >= COMMENT_CAP"
        :label="`What members wrote (${s.comments.length})`"
      />
    </div>
  </ModSysAdminUxPanel>
</template>

<script setup>
import { computed } from 'vue'
import { useUxStore } from '~/modtools/stores/ux'
import ModSysAdminUxPanel from '~/modtools/components/ModSysAdminUxPanel.vue'
import ModSysAdminUxSaid from '~/modtools/components/ModSysAdminUxSaid.vue'

const store = useUxStore()

// The API sends at most this many comments per question.
const COMMENT_CAP = 20

const surveys = computed(() => store.experiments.data?.surveys || [])

// The follow-up over the top problems asks "gets in my way most" and "bothers me least"; its
// options are keyed problem:<id>. Its columns take those words, and it lists the biggest
// nuisance first, which is the "best" count there.
const isFollowup = (s) => (s.tallies || []).some((t) => /^problem:/.test(t.key))

function surveyFieldsFor(s) {
  return [
    { key: 'label', label: isFollowup(s) ? 'Problem' : 'Area' },
    {
      key: 'best',
      label: isFollowup(s) ? 'Gets in the way most' : 'Works best',
    },
    {
      key: 'worst',
      label: isFollowup(s) ? 'Bothers least' : 'Most needs work',
    },
  ]
}
const awarenessFields = [
  { key: 'label', label: 'Answer' },
  { key: 'count', label: 'Members' },
]

function surveyRows(s) {
  const total = s.responses || 0
  return (s.tallies || [])
    .slice()
    .sort((a, b) => (isFollowup(s) ? b.best - a.best : b.worst - a.worst))
    .map((t) => ({
      label: t.label,
      best: total ? `${t.best} (${Math.round((t.best / total) * 100)}%)` : '0',
      worst: total
        ? `${t.worst} (${Math.round((t.worst / total) * 100)}%)`
        : '0',
    }))
}

// An awareness question lists "don't understand" among its answers. The server counts those into
// s.dont_understand, so the row takes its figure from there; reading only the tally showed 0
// beside a note saying some members were confused.
function awarenessRows(s) {
  const total = s.responses || 0
  return (s.tallies || []).map((t) => {
    const n = t.key === 'dont_understand' ? s.dont_understand || 0 : t.count
    return {
      label: t.label,
      count: total ? `${n} (${Math.round((n / total) * 100)}%)` : '0',
    }
  })
}

// The answers that are neither a pick nor a tally row: none of these stood out (a best and worst
// survey only; it means nothing for an awareness question) and did not understand.
function escapeText(s) {
  const parts = []
  if (s.kind === 'survey' && s.dont_care) {
    parts.push(`${s.dont_care} said none of these stood out`)
  }
  if (s.dont_understand) {
    parts.push(`${s.dont_understand} did not understand the question`)
  }
  return parts.length ? parts.join('; ') + '.' : ''
}

// An open question has no tally: members wrote, or chose "nothing", or did not understand. The
// text is read through the analysis (the problems above), never counted here.
function openText(s) {
  const wrote = Math.max(
    0,
    (s.responses || 0) - (s.nothing || 0) - (s.dont_understand || 0)
  )
  return `${wrote} wrote something, ${s.nothing || 0} said nothing came to mind, ${
    s.dont_understand || 0
  } did not understand the question. ${
    s.analysed
      ? 'The answers have been analysed; see the problems above.'
      : 'The answers have not been analysed yet.'
  }`
}
</script>

<style scoped lang="scss">
.comments {
  padding-left: 1.2rem;
}
</style>
