<template>
  <ModSysAdminUxPanel
    title="Still want it?"
    explanation="Before passing on a reply to an offer whose photo looks damaged, or too big to carry home, Freegle asks the replier whether they still want it. Only a yes sends the reply. Writing again in the chat counts as yes; no answer within three days drops it."
    :loading="store.replyconfirm.loading"
    :error="store.replyconfirm.error"
    :empty="!rows.length"
    empty-text="No replies were asked about in the selected period."
  >
    <b-table :items="rows" :fields="fields" small responsive />
  </ModSysAdminUxPanel>
</template>

<script setup>
import { computed } from 'vue'
import { useUxStore } from '~/modtools/stores/ux'
import { withCount } from '~/modtools/composables/useUxFormat'
import ModSysAdminUxPanel from '~/modtools/components/ModSysAdminUxPanel.vue'

const store = useUxStore()

const REASONS = {
  damaged: 'Looks damaged',
  car: 'Needs a car',
  both: 'Both',
}

const fields = [
  { key: 'reason', label: 'Asked because' },
  { key: 'asked', label: 'Asked' },
  { key: 'yesButton', label: 'Yes' },
  { key: 'yesMessage', label: 'Yes, by writing again' },
  { key: 'no', label: 'No' },
  { key: 'noAnswer', label: 'No answer' },
  { key: 'waiting', label: 'Still waiting' },
  { key: 'previous', label: 'Previous period: yes' },
]

const rows = computed(() => {
  const previous = {}
  for (const p of store.replyconfirm.data?.previous?.reasons || []) {
    previous[p.reason] = p
  }

  const order = Object.keys(REASONS)
  const rank = (reason) => {
    const i = order.indexOf(reason)
    return i === -1 ? order.length : i
  }

  return [...(store.replyconfirm.data?.reasons || [])]
    .sort((a, b) => rank(a.reason) - rank(b.reason))
    .map((r) => {
      const p = previous[r.reason]

      return {
        reason: REASONS[r.reason] || r.reason,
        asked: (r.asked || 0).toLocaleString(),
        yesButton: withCount(r.yes_button, r.asked),
        yesMessage: withCount(r.yes_message, r.asked),
        no: withCount(r.no, r.asked),
        noAnswer: withCount(r.no_answer, r.asked),
        waiting: (r.waiting || 0).toLocaleString(),
        previous: p
          ? withCount((p.yes_button || 0) + (p.yes_message || 0), p.asked)
          : '–',
      }
    })
})
</script>
