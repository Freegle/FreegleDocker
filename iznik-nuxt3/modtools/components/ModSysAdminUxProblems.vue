<template>
  <div class="ux-problems mb-3">
    <h6 class="mb-1">{{ title }}</h6>
    <p v-if="note" class="small text-muted mb-2">{{ note }}</p>

    <ul v-if="mode === 'values' && items.length" class="small ps-3 mb-0 values">
      <li v-for="v in items" :key="v.id" class="mb-1 value">
        <strong>{{ v.title }}</strong>
        <span class="text-muted">
          ({{ mentionedBy(v.mentions, v.answers) }})</span
        >
        <ModSysAdminUxSaid
          v-if="quotesOf(v).length"
          :comments="quotesOf(v)"
          :limit="5"
          :label="`What members wrote (${quotesOf(v).length})`"
        />
      </li>
    </ul>

    <ol v-else-if="items.length" class="small ps-3 mb-0 problems">
      <li v-for="p in items" :key="p.id" class="mb-2 problem">
        <strong>{{ p.title }}</strong>
        <span class="text-muted"> ({{ factsFor(p) }}) </span>
        <div v-if="p.description">{{ p.description }}</div>
        <!-- Members' own words behind a toggle, escaped: they were checked against the answers,
             but they are still member text. -->
        <ModSysAdminUxSaid
          v-if="quotesOf(p).length"
          :comments="quotesOf(p)"
          :limit="5"
          :label="`What members wrote (${quotesOf(p).length})`"
        />
        <ul v-if="p.ideas?.length" class="ps-3 mb-0 ideas">
          <li v-for="i in p.ideas" :key="'i' + i.id">
            {{ i.type === 'fix' ? 'Fix' : 'Idea' }}: {{ i.title }}
            <span class="text-muted"
              >({{ ideaStatus(i.status) }}, score
              {{ Number(i.score || 0).toFixed(1) }})</span
            >
          </li>
        </ul>
        <ul v-if="p.experiments?.length" class="ps-3 mb-0 experiments">
          <li v-for="e in p.experiments" :key="'e' + e.id">
            Experiment: {{ e.title }}
            <span class="text-muted">({{ e.status }})</span>
          </li>
        </ul>
        <div
          v-if="!p.ideas?.length && !p.experiments?.length"
          class="text-muted"
        >
          Nothing tried or suggested for this yet.
        </div>
      </li>
    </ol>

    <p v-else class="small text-muted mb-0">{{ emptyText }}</p>
  </div>
</template>

<script setup>
import ModSysAdminUxSaid from '~/modtools/components/ModSysAdminUxSaid.vue'
import { PERCENT_MIN_ANSWERS } from '~/modtools/composables/useUxFormat'

// Problems members raised (or values they named), each with how many mentioned it, how other
// members ranked it, and the ideas and experiments aimed at it. Every figure carries its base, so
// "14" is always "14 of 120 who answered". Solutions are never asked of members: they sit under the
// problem as ideas and experiments the loop generated and tested. The base is each item's own: the
// number of members who answered the question it came from, which differs between questions.
defineProps({
  title: { type: String, required: true },
  note: { type: String, default: '' },
  // 'problems': contract 2 problem rows. 'values': [{ id, title, mentions }].
  mode: { type: String, default: 'problems' },
  items: { type: Array, default: () => [] },
  emptyText: {
    type: String,
    default: 'Nothing raised yet.',
  },
})

const SOURCE = {
  members: 'raised by members',
  ai: 'noticed by the idea generator',
  reports: 'from bug reports',
  figures: 'from the figures',
}

const IDEA_STATUS = {
  open: 'waiting',
  scheduled: 'queued',
  running: 'being tried',
  done: 'tried',
  rejected: 'set aside',
}

const count = (n) => Number(n || 0).toLocaleString()

// "mentioned by 14 of the 45 who answered" below 100 answers; from 100 the percentage is added.
// A theme is a respondent count, so several themes can each be mentioned by the same member.
function mentionedBy(n, answers) {
  const base = Number(answers) || 0
  if (!(base > 0)) return `mentioned by ${count(n)}`
  const pct =
    base >= PERCENT_MIN_ANSWERS
      ? ` (${Math.round(((Number(n) || 0) / base) * 100)}%)`
      : ''
  return `mentioned by ${count(n)} of the ${count(base)} who answered${pct}`
}

// The quotes the helper kept for a theme: each was checked to be words from a real answer. They
// arrive as { answer_id, span }; a bare string is accepted too.
function quotesOf(item) {
  const raw = item.quotes || item.evidence?.quotes || []
  return raw
    .map((q) => (typeof q === 'string' ? q : q?.span))
    .filter((q) => typeof q === 'string' && q.trim() !== '')
}

// How other members ranked a problem in the follow-up. Below 100 answers it says how often it was
// picked, as counts; from 100 it gives the margin between "most" and "least" as a percentage.
function weightText(p) {
  const w = p.weight
  if (w === null || w === undefined) return 'not ranked by other members yet'
  const asked = Number(p.asked) || 0
  if (asked < PERCENT_MIN_ANSWERS) {
    const shown =
      Number(p.times_shown) > 0 ? ` in ${count(p.times_shown)} showings` : ''
    if (p.most !== undefined && p.least !== undefined) {
      return `${count(p.most)} said it got in their way most and ${count(p.least)} least${shown}, of the ${count(asked)} asked`
    }
    return `ranked by the ${count(asked)} members asked; too few answers yet to give a figure`
  }
  const pct = Math.round(Math.abs(Number(w)) * 100)
  if (!pct) return 'other members ranked it evenly'
  // The weight is per time the problem was shown, not per member asked: each problem is shown to
  // only some of the members (four of eight at a time), so a "per member asked" figure would be
  // about double what the data says.
  const of = `the ${count(asked)} members asked`
  return Number(w) > 0
    ? `picked as getting in their way most ${pct}% more than least, per time it was shown to ${of}`
    : `picked as getting in their way least ${pct}% more than most, per time it was shown to ${of}`
}

function factsFor(p) {
  const parts = []
  if (p.source === 'members' || p.mentions)
    parts.push(mentionedBy(p.mentions, p.answers))
  parts.push(weightText(p))
  parts.push(SOURCE[p.source] || p.source)
  return parts.join('; ')
}

const ideaStatus = (s) => IDEA_STATUS[s] || s
</script>
