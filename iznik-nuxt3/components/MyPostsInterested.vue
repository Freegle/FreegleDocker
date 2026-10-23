<template>
  <div
    v-if="marks.length"
    class="myposts-interested"
    data-testid="myposts-interested"
  >
    <h5 class="myposts-interested__head">
      <v-icon icon="check" class="text-success me-1" />Posts you're interested
      in
    </h5>
    <div
      v-for="m in marks"
      :key="m.msgid"
      class="myposts-interested__row"
      :data-testid="'interested-row-' + m.msgid"
    >
      <MessageSummary :id="m.msgid" class="mb-2" />
      <div class="myposts-interested__buttons">
        <b-button
          variant="primary"
          :to="'/message/' + m.msgid + '?reply=1'"
          :data-testid="'interested-reply-' + m.msgid"
        >
          Reply
        </b-button>
        <b-button
          variant="secondary"
          :data-testid="'interested-remove-' + m.msgid"
          @click="remove(m.msgid)"
        >
          Remove
        </b-button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useMessageStore } from '~/stores/message'
import {
  listInterested,
  removeInterested,
} from '~/composables/useInterested'
import MessageSummary from '~/components/MessageSummary'

// The posts the member marked "I'm interested" that are still open, newest first. Shown in the app
// too: listing needs no arm, and a member who marked keeps their list whatever happens to the
// experiment. Hidden when there are none, and when the API has no such list.
const messageStore = useMessageStore()
const marks = ref([])

onMounted(async () => {
  const list = (await listInterested()).filter((m) => m && m.open && m.msgid)
  await Promise.all(list.map((m) => messageStore.fetch(m.msgid)))
  marks.value = list
})

async function remove(id) {
  if (await removeInterested(id)) {
    marks.value = marks.value.filter((m) => m.msgid !== id)
  }
}
</script>

<style scoped lang="scss">
.myposts-interested {
  margin-bottom: 1rem;
  padding: 0 0.5rem;
}

.myposts-interested__buttons {
  display: flex;
  gap: 0.75rem;
  margin-bottom: 1rem;

  .btn {
    flex: 1;
  }
}
</style>
