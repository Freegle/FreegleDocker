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
      </li>
    </ul>

    <ol v-else-if="items.length" class="small ps-3 mb-0 problems">
      <li v-for="p in items" :key="p.id" class="mb-2 problem">
        <strong>{{ p.title }}</strong>
        <span class="text-muted"> ({{ factsFor(p) }}) </span>
        <div v-if="p.description">{{ p.description }}</div>
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

function mentionedBy(n, answers) {
  const base = Number(answers) > 0 ? ` of ${count(answers)} who answered` : ''
  return `mentioned by ${count(n)}${base}`
}

function weightText(w, asked) {
  if (w === null || w === undefined) return 'not ranked by other members yet'
  const pct = Math.round(Math.abs(Number(w)) * 100)
  if (!pct) return 'other members ranked it evenly'
  const of =
    Number(asked) > 0 ? `the ${count(asked)} members asked` : 'those asked'
  return Number(w) > 0
    ? `${pct}% more of ${of} said it gets in their way most than least`
    : `${pct}% more of ${of} said it bothers them least than most`
}

function factsFor(p) {
  const parts = []
  if (p.source === 'members' || p.mentions)
    parts.push(mentionedBy(p.mentions, p.answers))
  parts.push(weightText(p.weight, p.asked))
  parts.push(SOURCE[p.source] || p.source)
  return parts.join('; ')
}

const ideaStatus = (s) => IDEA_STATUS[s] || s
</script>
