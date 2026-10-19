<template>
  <b-modal
    ref="modal"
    size="lg"
    hide-header
    hide-footer
    no-stacking
    modal-class="reply-job-interstitial"
  >
    <template #default>
      <div class="interstitial">
        <button class="close-btn" aria-label="Close" @click="hide">
          <v-icon icon="times" />
        </button>
        <h5 class="heading">Your reply has been sent</h5>
        <p class="lead-in">
          Jobs near you. If one interests you, clicking it raises money for
          Freegle.
        </p>
        <div class="job-list">
          <JobOne
            v-for="(job, index) in jobs"
            :id="job.id"
            :key="'reply-job-' + job.id"
            :summary="true"
            context="reply_interstitial"
            :position="index"
            :list-length="jobs.length"
          />
        </div>
        <b-button variant="primary" class="continue" @click="hide">
          Continue
        </b-button>
      </div>
    </template>
  </b-modal>
</template>

<script setup>
import { computed, watch, onBeforeUnmount, defineAsyncComponent } from 'vue'
import { useNuxtApp } from '#imports'
import { useJobStore } from '~/stores/job'
import { useAuthStore } from '~/stores/auth'
import { action } from '~/composables/useClientLog'
import { useOurModal } from '~/composables/useOurModal'
import {
  recordShown,
  replyJobInterstitialRequest,
  REPLY_JOB_INTERSTITIAL_WAIT,
} from '~/composables/useReplyJobInterstitial'

const JobOne = defineAsyncComponent(() => import('./JobOne'))

// The most adverts shown.
const MAX_JOBS = 3

const { modal, show, hide } = useOurModal({ autoShow: false })
const jobStore = useJobStore()
const authStore = useAuthStore()
const nuxtApp = useNuxtApp()

const jobs = computed(() => jobStore.available.slice(0, MAX_JOBS))

// The move to the chat can change the layout, which unmounts this copy and mounts another. A copy
// that is going away leaves the request where it is, for the new one to pick up.
let gone = false
let stopWaiting = null
onBeforeUnmount(() => {
  gone = true
  stopWaiting?.()
})

// Resolves true once the next page has finished rendering, or false if none does in time. Not the
// router's afterEach: that runs before a change of layout unmounts this copy, which would then open
// a modal that is thrown away with it.
function navigationDone(request) {
  return new Promise((resolve) => {
    const timer = setTimeout(
      () => {
        stopWaiting?.()
        resolve(false)
      },
      Math.max(0, request.at + REPLY_JOB_INTERSTITIAL_WAIT - Date.now())
    )
    const stop = nuxtApp.hook('page:finish', () => {
      clearTimeout(timer)
      stop()
      resolve(true)
    })
    stopWaiting = () => {
      clearTimeout(timer)
      stop()
      resolve(false)
    }
  })
}

async function consider(request) {
  if (!request) return

  if (Date.now() - request.at > REPLY_JOB_INTERSTITIAL_WAIT) {
    // The member never reached the chat in time: nothing is shown, so a later reply can show it.
    replyJobInterstitialRequest.value = null
    return
  }

  // Still on the page the reply was sent from: wait for the move to the chat. Opening a modal
  // while that navigation is under way would cancel it (useOurModal's back-button guard).
  if (request.expectNavigation && window.location.href === request.from) {
    const arrived = await navigationDone(request)
    if (gone) return
    if (!arrived) {
      if (replyJobInterstitialRequest.value === request) {
        replyJobInterstitialRequest.value = null
      }
      return
    }
  }

  const me = authStore.user
  if (me?.lat && me?.lng) {
    await jobStore.fetch(me.lat, me.lng)
  }

  // Another copy (the new layout's) may have taken it, or this one may be on its way out.
  if (gone || replyJobInterstitialRequest.value !== request) return
  replyJobInterstitialRequest.value = null

  if (!jobs.value.length) {
    // No adverts for this member, or they are blocked: nothing shown and nothing recorded.
    return
  }

  recordShown()
  show()
  action('reply_job_interstitial_shown', { job_count: jobs.value.length })
}

watch(replyJobInterstitialRequest, consider, { immediate: true })

defineExpose({ hide })
</script>

<style lang="scss">
/* Not scoped, so Bootstrap's modal-class hook picks it up: a sheet from the bottom on a phone. */
@import 'bootstrap/scss/functions';
@import 'bootstrap/scss/variables';
@import 'bootstrap/scss/mixins/_breakpoints';

@include media-breakpoint-down(sm) {
  .reply-job-interstitial .modal-dialog {
    position: fixed;
    bottom: 0;
    margin: 0;
    width: 100%;
    max-width: 100%;
  }

  .reply-job-interstitial .modal-content {
    border-radius: 12px 12px 0 0;
  }
}
</style>

<style scoped lang="scss">
@import 'bootstrap/scss/functions';
@import 'bootstrap/scss/variables';

.interstitial {
  position: relative;
  padding: 1rem;
}

.close-btn {
  position: absolute;
  top: 0.5rem;
  right: 0.5rem;
  background: none;
  border: none;
  cursor: pointer;
  color: $gray-500;
  font-size: 1.1rem;
  line-height: 1;
  padding: 0.25rem;

  &:hover {
    color: $gray-800;
  }
}

.heading {
  font-size: 1rem;
  font-weight: 600;
  color: $gray-800;
  margin: 0 0 0.25rem 0;
  padding-right: 2rem;
}

.lead-in {
  font-size: 0.875rem;
  color: $gray-700;
  margin: 0 0 0.75rem 0;
}

.job-list {
  margin-bottom: 0.75rem;

  :deep(.job-item) {
    margin-bottom: 0;
  }
}

.continue {
  width: 100%;
}
</style>
