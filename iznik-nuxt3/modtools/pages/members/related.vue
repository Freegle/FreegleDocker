<template>
  <div>
    <client-only>
      <ScrollToTop />
      <ModHelpRelated />
      <div
        v-for="member in members"
        :key="'memberlist-' + member.id"
        class="p-0 mt-2"
      >
        <ModRelatedMember :memberid="member.id" />
      </div>
      <NoticeMessage v-if="!members.length" variant="info">
        No possible duplicate accounts to review right now.
      </NoticeMessage>
    </client-only>
  </div>
</template>
<script setup>
// .claude/rules/conventions.md).
import { computed, watch } from 'vue'
import { useMemberStore } from '~/modtools/stores/member'
import { useMe } from '~/composables/useMe'

const memberStore = useMemberStore()
const { loggedIn } = useMe()

function hasPair(member) {
  return member && member.user1 != null && member.user2 != null
}

const members = computed(() => Object.values(memberStore.list).filter(hasPair))

// Not until the moderator is known: the layout renders pages before sign-in has finished
// loading, and a fetch sent then goes without the token and comes back 401.
watch(
  loggedIn,
  async (known) => {
    if (known) {
      await memberStore.fetchMembers({ filter: 'related' })
    }
  },
  { immediate: true }
)
</script>
