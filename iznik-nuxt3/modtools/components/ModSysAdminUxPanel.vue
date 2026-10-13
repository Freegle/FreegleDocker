<template>
  <section class="ux-panel mb-4">
    <h4>{{ title }}</h4>
    <p class="text-muted">{{ explanation }}</p>
    <div v-if="loading" class="text-center py-3">
      <b-spinner small class="me-2" />
      Loading...
    </div>
    <NoticeMessage v-else-if="error" variant="danger" class="mb-3">
      {{ error }}
    </NoticeMessage>
    <div v-else-if="empty" class="text-muted py-3">
      {{ emptyText }}
    </div>
    <slot v-else />
  </section>
</template>

<script setup>
defineProps({
  title: { type: String, required: true },
  explanation: { type: String, required: true },
  loading: { type: Boolean, default: false },
  error: { type: String, default: null },
  empty: { type: Boolean, default: false },
  emptyText: { type: String, default: 'No data for the selected period.' },
})
</script>
