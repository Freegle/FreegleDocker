<template>
  <b-dropdown variant="primary" text="Add to calendar" class="add-to-calendar">
    <b-dropdown-item
      :href="google"
      target="_blank"
      rel="noopener noreferrer"
      data-testid="atc-google"
    >
      <v-icon icon="calendar-alt" class="me-2" />Google Calendar
    </b-dropdown-item>
    <b-dropdown-item data-testid="atc-ics" @click="downloadIcs">
      <v-icon icon="download" class="me-2" />Apple or other (.ics file)
    </b-dropdown-item>
    <b-dropdown-item
      :href="microsoft"
      target="_blank"
      rel="noopener noreferrer"
      data-testid="atc-microsoft"
    >
      <v-icon icon="calendar-alt" class="me-2" />Microsoft 365
    </b-dropdown-item>
    <b-dropdown-item
      :href="yahoo"
      target="_blank"
      rel="noopener noreferrer"
      data-testid="atc-yahoo"
    >
      <v-icon icon="calendar-alt" class="me-2" />Yahoo
    </b-dropdown-item>
  </b-dropdown>
</template>

<script setup>
import { computed } from 'vue'
import {
  buildIcs,
  googleUrl,
  microsoft365Url,
  yahooUrl,
} from '~/composables/calendarLinks'

const props = defineProps({
  event: {
    type: Object,
    required: true,
  },
})

const google = computed(() => googleUrl(props.event))
const microsoft = computed(() => microsoft365Url(props.event))
const yahoo = computed(() => yahooUrl(props.event))

function downloadIcs() {
  const ics = buildIcs(props.event)

  if (!ics) {
    return
  }

  const blob = new Blob([ics], { type: 'text/calendar;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = 'freegle-event.ics'
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}
</script>
