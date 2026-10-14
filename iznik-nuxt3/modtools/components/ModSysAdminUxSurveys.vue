<template>
  <ModSysAdminUxPanel
    title="What members say"
    explanation="Microvolunteers are asked what problems they have had, whether they know features exist, and which parts of Freegle work best. They chose to answer, tend to be keen, and prefer what they know, so read this as what the members who answered said, not what members think. Counts are out of the number who answered, with no percentages below 100 answers, and no error range is shown because one does not apply to a sample that picked itself. Skipped questions are counted separately and are in none of the figures."
    :loading="store.experiments.loading"
    :error="store.experiments.error"
    :empty="!surveys.length"
    empty-text="No survey or awareness questions have been asked yet."
  >
    <div v-for="s in surveys" :key="s.prompt_id" class="survey mb-4">
      <h6 class="mb-1">
        {{ s.question }}
        <span class="text-muted small fw-normal">
          ({{ s.responses }} answered{{
            s.status === 'open' ? ', still open' : ''
          }})
        </span>
        <span
          v-if="s.kind === 'awareness' && s.decoy"
          class="small fw-normal decoy-tag"
        >
          Decoy: Freegle does not do this
        </span>
      </h6>
      <p
        v-if="baseText(s)"
        class="small text-muted mb-1 base"
        data-testid="base"
      >
        {{ baseText(s) }}
      </p>
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
      <template v-else>
        <b-table
          :items="awarenessRows(s)"
          :fields="awarenessFields"
          small
          responsive
        />
        <p v-if="isNewAwareness(s)" class="small mb-1 right-answer">
          {{ rightText(s) }}
        </p>
        <p
          v-if="isNewAwareness(s) && !s.decoy && decoyTally.questions"
          class="small mb-1 decoy-check"
          data-testid="decoy-check"
        >
          {{ decoyText }}
        </p>
      </template>
      <p
        v-if="s.kind !== 'open' && escapeText(s)"
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
import {
  ofAnswered,
  PERCENT_MIN_ANSWERS,
} from '~/modtools/composables/useUxFormat'

const store = useUxStore()

// The API sends at most this many comments per question.
const COMMENT_CAP = 20

const surveys = computed(() => store.experiments.data?.surveys || [])

// Every figure is "of the N who answered". Below 100 answers it is a count with its base and no
// percentage; from 100 the percentage is added beside the count.
function baseText(s) {
  if (s.kind === 'open') return ''
  const n = s.responses || 0
  if (!n) return 'Nobody has answered yet.'
  return n < PERCENT_MIN_ANSWERS
    ? `Of the ${n} who answered. Below ${PERCENT_MIN_ANSWERS} answers these are counts, not percentages: the next answer can still change the picture.`
    : `Of the ${n.toLocaleString()} who answered.`
}

// The follow-up over the top problems asks which was the "biggest problem" (and, optionally, the "smallest problem")
// among the few shown to each member; its options are keyed problem:<id>.
const isFollowup = (s) => (s.tallies || []).some((t) => /^problem:/.test(t.key))

// Older tallies called the two picks best and worst; the newer ones most and least.
const mostOf = (t) => t.most ?? t.best ?? 0
const leastOf = (t) => t.least ?? t.worst ?? 0

const hasShown = (s) => (s.tallies || []).some((t) => Number(t.times_shown) > 0)

function surveyFieldsFor(s) {
  const fields = [{ key: 'label', label: isFollowup(s) ? 'Problem' : 'Area' }]
  if (hasShown(s)) fields.push({ key: 'shown', label: 'Times shown' })
  fields.push(
    {
      key: 'best',
      label: isFollowup(s) ? 'Biggest problem' : 'Works best',
    },
    {
      key: 'worst',
      label: isFollowup(s) ? 'Smallest problem' : 'Most needs work',
    }
  )
  return fields
}

const awarenessFields = [
  { key: 'label', label: 'Answer' },
  { key: 'count', label: 'Members' },
]

function surveyRows(s) {
  const total = s.responses || 0
  return (s.tallies || [])
    .slice()
    .sort((a, b) =>
      isFollowup(s) ? mostOf(b) - mostOf(a) : leastOf(b) - leastOf(a)
    )
    .map((t) => {
      // An option is judged against the times it was on screen, where the server says, because
      // each member sees only a few of the options.
      const base = Number(t.times_shown) > 0 ? Number(t.times_shown) : total
      return {
        label: t.label,
        shown: Number(t.times_shown) > 0 ? t.times_shown : '',
        best: base ? ofAnswered(mostOf(t), base, total) : '0',
        worst: base ? ofAnswered(leastOf(t), base, total) : '0',
      }
    })
}

// The newer awareness answers are yes / no / not sure with their own counts; older ones are a
// list of tally rows.
const isNewAwareness = (s) =>
  s.yes !== undefined || s.no !== undefined || s.not_sure !== undefined

function awarenessRows(s) {
  const total = s.responses || 0
  if (isNewAwareness(s)) {
    return [
      { label: 'Yes', count: ofAnswered(s.yes, total) },
      { label: 'No', count: ofAnswered(s.no, total) },
      { label: 'Not sure', count: ofAnswered(s.not_sure, total) },
    ]
  }
  return (s.tallies || []).map((t) => {
    const n = t.key === 'dont_understand' ? s.dont_understand || 0 : t.count
    return { label: t.label, count: total ? ofAnswered(n, total) : '0' }
  })
}

// A real feature is rightly answered Yes; a decoy (something Freegle does not do) is rightly
// answered No. Counted here from the answers so that below 100 it is a count, not a share.
const rightCount = (s) => (s.decoy ? s.no : s.yes) || 0

function rightText(s) {
  const total = s.responses || 0
  return `Right answer (${s.decoy ? 'No' : 'Yes'}): ${ofAnswered(rightCount(s), total)}.`
}

// Across the decoys, how many said Yes to something Freegle does not do. It is shown beside the
// real questions so a reader can discount their Yes answers by about that much.
const decoyTally = computed(() => {
  const decoys = surveys.value.filter(
    (s) => s.kind === 'awareness' && s.decoy && (s.responses || 0) > 0
  )
  return {
    questions: decoys.length,
    answers: decoys.reduce((t, s) => t + (s.responses || 0), 0),
    yes: decoys.reduce((t, s) => t + (s.yes || 0), 0),
  }
})

const decoyText = computed(() => {
  const d = decoyTally.value
  return `Yes-saying check: across ${d.questions} decoy question${
    d.questions === 1 ? '' : 's'
  } about things Freegle does not do, ${ofAnswered(
    d.yes,
    d.answers
  )} answers were Yes. Allow for that when reading a Yes here.`
})

// The answers that are neither a pick nor a tally row: none of these (a ranking only) and did not
// understand.
function escapeText(s) {
  const parts = []
  const none = s.none ?? s.dont_care
  if (s.kind === 'survey' && none) {
    parts.push(`${none} said none of these`)
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
  return `Of the ${s.responses || 0} who answered, ${wrote} wrote something, ${
    s.nothing || 0
  } said nothing came to mind, ${
    s.dont_understand || 0
  } did not understand the question. ${analysisText(s, wrote)}`
}

// The problems above are drawn from the answers there were when the question was analysed. A
// question that stays open keeps collecting, so say how many the analysis has not read.
function analysisText(s, wrote) {
  if (!s.analysed) return 'The answers have not been analysed yet.'
  const read = s.analysed_answers || 0
  if (read && read < wrote) {
    return `The problems above were drawn from ${read} of the ${wrote} written answers; ${
      wrote - read
    } have arrived since and have not been read.`
  }
  return 'The answers have been analysed; see the problems above.'
}
</script>

<style scoped lang="scss">
.comments {
  padding-left: 1.2rem;
}
</style>
