import { ref } from 'vue'
import { flowArm, FlowName } from '~/composables/useFlowTelemetry'

// Experiment reply-job-interstitial: once a reply has been sent, a pop-up of local job adverts with
// a button to carry on. WhatJobs agreed to the pop-up and to saying a click raises money for
// Freegle; a member is never asked to click one before carrying on. ReplyJobInterstitial.vue, which
// LayoutCommon mounts (a copy per layout), shows it; the reply pane asks for it here.

// How long after showing it a member does not see it again, on this device.
export const REPLY_JOB_INTERSTITIAL_GAP = 24 * 60 * 60 * 1000
const SHOWN_KEY = 'freegle_reply_job_interstitial_shown'

// How long after the reply the move to the chat may take before the pop-up is given up on.
export const REPLY_JOB_INTERSTITIAL_WAIT = 10000

// Set when a reply has been sent by a member in the variant, and cleared when the pop-up opens or is
// given up on. expectNavigation says the reply pane is about to move to the chat, and from is the
// page it is leaving, so the pop-up waits for that: a modal open while a navigation is under way
// cancels it (useOurModal's back-button guard), and the member would never reach the chat.
export const replyJobInterstitialRequest = ref(null)

export function shownRecently(now = Date.now()) {
  try {
    const at = Number(localStorage.getItem(SHOWN_KEY))
    return Boolean(at) && now - at < REPLY_JOB_INTERSTITIAL_GAP
  } catch (e) {
    return false
  }
}

export function recordShown(now = Date.now()) {
  try {
    localStorage.setItem(SHOWN_KEY, String(now))
  } catch (e) {
    // Storage blocked: the member may see it again on a later reply, which is no worse than control.
  }
}

// Called by the reply pane when a reply has been sent. Reading the arm counts the member as exposed,
// so it is read for every sent reply in both arms, before the once-a-day check.
export function offerReplyJobInterstitial({ expectNavigation }) {
  const arm = flowArm(FlowName.REPLY, 'reply-job-interstitial')

  if (arm !== 'b' || shownRecently()) {
    return false
  }

  replyJobInterstitialRequest.value = {
    expectNavigation,
    from: window.location.href,
    at: Date.now(),
  }
  return true
}
