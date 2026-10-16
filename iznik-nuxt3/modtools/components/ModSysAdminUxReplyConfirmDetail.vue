<template>
  <section
    v-if="mine"
    class="ux-reply-confirm mb-2"
    data-testid="ux-reply-confirm"
  >
    <h6 class="ux-exp-h">Still want it?</h6>
    <p class="what mb-1">
      Before a reply to an offer that looks damaged, or too big to carry home,
      goes to the poster, Freegle asks the replier whether they still want it. A
      yes sends the reply. Writing again in the chat counts as a yes, and
      writing again within a minute is shown apart as sent twice, because a
      reply sent twice by mistake looks the same. No answer in three days drops
      the reply. Only some repliers are asked while the experiment runs.
    </p>
    <p v-if="!tables.length" class="none text-muted mb-0">
      No replies counted yet.
    </p>
    <template v-else>
      <b-table
        :items="tables[0].items"
        :fields="fields"
        small
        class="all mb-1"
      />
      <button
        v-if="tables.length > 1"
        type="button"
        class="reasons-toggle btn btn-link btn-sm p-0"
        :aria-expanded="showReasons ? 'true' : 'false'"
        @click="showReasons = !showReasons"
      >
        {{ showReasons ? 'Hide the reasons' : 'Show each reason' }}
      </button>
      <template v-if="showReasons">
        <div v-for="t in tables.slice(1)" :key="t.key" class="reason mt-2">
          <p class="reason-name fw-bold mb-1">{{ t.label }}</p>
          <b-table :items="t.items" :fields="fields" small class="mb-1" />
        </div>
      </template>
    </template>
  </section>
</template>

<script setup>
// What is particular to the experiment confirm-reply, in the details of the Experiments section: how repliers
// answered the question "Do you still want it?", all together and for each reason it was asked. The Channel block
// above it says what each version does, how long members are reached and how many were counted, so none of that
// is repeated. The figures are the experiment's own last read (result.detail.reasons, written by
// ConfirmReplySource), so this needs no store and no call, and it draws nothing for any other experiment.
import { computed, ref } from 'vue'
import { withCount } from '~/modtools/composables/useUxFormat'

const props = defineProps({
  // One experiment of the experiments response.
  experiment: { type: Object, required: true },
})

const showReasons = ref(false)

const mine = computed(() => props.experiment?.name === 'confirm-reply')

// The reasons in the order staff read them. A reason the read does not have is left out.
const REASONS = [
  ['all', 'All reasons'],
  ['damaged', 'Looks damaged'],
  ['car', 'Needs a car'],
  ['both', 'Both'],
]

const ARM_LABEL = {
  control: 'Without the question',
  ask: 'With the question',
}

const num = (n) => (n || 0).toLocaleString()
const share = (n, d) => (d ? withCount(n, d) : '–')

// Each row is a measure; each cell is one arm's figure for it, with the count behind it.
const MEASURES = [
  ['Replies', (c) => num(c.replies)],
  ['Asked', (c) => share(c.asked, c.replies)],
  ['Said yes', (c) => share(c.yes_button, c.asked)],
  [
    'Wrote again instead, which counts as yes',
    (c) => share(c.yes_message, c.asked),
  ],
  ['Sent twice, which counts as yes', (c) => share(c.sent_twice, c.asked)],
  ['Said no', (c) => share(c.no, c.asked)],
  ['Did not answer', (c) => share(c.no_answer, c.asked)],
  ['Still waiting', (c) => num(c.waiting)],
  ['Reached the poster', (c) => share(c.reached, c.replies)],
  ['Counted', (c) => num(c.counted)],
  ['Promised or taken', (c) => share(c.handed_over, c.counted)],
]

const reasons = computed(() => {
  const r = props.experiment?.result?.detail?.reasons
  return r && typeof r === 'object' && !Array.isArray(r) ? r : null
})

// Control first, then the others by name.
const arms = computed(() => {
  const names = Object.keys(reasons.value?.all || {})
  return names.sort((a, b) =>
    a === 'control' ? -1 : b === 'control' ? 1 : a.localeCompare(b)
  )
})

const fields = computed(() => [
  { key: 'measure', label: '' },
  ...arms.value.map((a) => ({
    key: a,
    label: ARM_LABEL[a] || a.charAt(0).toUpperCase() + a.slice(1),
  })),
])

const tables = computed(() => {
  if (!reasons.value || !arms.value.length) return []
  return REASONS.filter(([key]) => reasons.value[key]).map(([key, label]) => ({
    key,
    label,
    items: MEASURES.map(([measure, cell]) => {
      const row = { measure }
      for (const a of arms.value) row[a] = cell(reasons.value[key]?.[a] || {})
      return row
    }),
  }))
})
</script>

<style scoped lang="scss">
// A sub-heading in the style of the headings of the details, as the Channel block draws its own.
.ux-exp-h {
  margin-bottom: 0.15rem;
  font-size: 0.8rem;
  font-weight: 700;
  text-transform: none;
  color: #495057;
}

// Three narrow columns on a phone: the long measure name wraps instead of widening the table.
.ux-reply-confirm :deep(td),
.ux-reply-confirm :deep(th) {
  overflow-wrap: anywhere;
  font-size: 0.8rem;
}
</style>
