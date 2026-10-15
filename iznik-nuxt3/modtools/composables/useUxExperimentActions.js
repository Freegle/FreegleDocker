// What the Experiments section does when someone presses Start, Stop now or Mark as cleaned up, and
// how it finds out what happened. The batch applies a request within a few minutes, and it may refuse
// it, so the section keeps a note of each request until the experiment changes or a refusal arrives.
//
// The store is passed in, so this can be tested alone. `getList` gives the experiments the section
// holds, which is how it knows whether any request is still waiting.
import { computed, getCurrentScope, onScopeDispose, reactive, ref } from 'vue'

// The batch applies a request within seconds, but the tab only knows when it asks again. Ask a few
// times, further apart each time, for as long as some request is waiting.
export const ASK_AFTER_MS = [5000, 10000, 20000, 40000, 80000, 160000]
// A request that nothing has answered after this long probably has no batch to answer it.
export const TOO_LONG_MS = 10 * 60 * 1000
// How often the clock behind "too long" moves on while something waits.
const TICK_MS = 30000

export const REQUESTED_LABEL = {
  start: 'Start requested',
  stop: 'Stop requested',
  close: 'Cleanup noted',
}

const FALLBACK = 'That did not work.'

/** The message the server sent with a refusal, else a plain fallback. Never the technical text. */
export function serverMessage(err, fallback = FALLBACK) {
  const message = err?.response?.data?.message
  return typeof message === 'string' && message.trim()
    ? message.trim()
    : fallback
}

// The batch's last "no" for an experiment (the cap, a second change on the funnel, a change too small
// to read in a day). It is kept on the experiment so it can be shown here.
const lastRefusal = (e) => e?.result?.last_refusal ?? null
const keyOf = (e) => `${e.id}:${e.status}`

export function useUxExperimentActions(store, getList = () => []) {
  // Per experiment, so one click does not freeze every card, and so the answer appears on the card
  // that was clicked.
  const busy = reactive({})
  const errors = reactive({})
  // "id:status" -> the action asked for. It holds while the experiment is still in that status, so a
  // second click cannot queue a duplicate, and lets go by itself once the batch has changed it.
  const requested = reactive({})
  // "id:status" -> the time of the refusal already on the experiment when the click was made (or
  // null), so a refusal the batch writes afterwards can be told from an old one without comparing
  // clocks.
  const refusalWhenAsked = reactive({})
  // "id:status" -> when the click was made, in milliseconds.
  const askedAt = reactive({})
  // "stop:<id>" while the person is being asked whether to stop that experiment, else null.
  const confirming = ref(null)
  // Moves on every 30 seconds while a request waits, so "too long" shows without a reload.
  const now = ref(Date.now())

  const pending = (e) => {
    const key = keyOf(e)
    const asked = requested[key]
    if (!asked) return null
    const refusal = lastRefusal(e)
    if (refusal && refusal.at !== refusalWhenAsked[key]) return null
    return asked
  }

  const problem = (e) => {
    if (errors[e.id]) return errors[e.id]
    const refusal = pending(e) ? null : lastRefusal(e)
    return refusal ? `That was not done: ${refusal.message}` : ''
  }

  const pendingTooLong = (e) => {
    if (!pending(e)) return false
    const at = askedAt[keyOf(e)]
    return at !== undefined && now.value - at >= TOO_LONG_MS
  }

  // Stopping takes everyone back to the old version, so it asks first.
  const askToStop = (e) => {
    confirming.value = `stop:${e.id}`
  }
  const cancelStop = () => {
    confirming.value = null
  }
  const isConfirmingStop = (e) => confirming.value === `stop:${e.id}`

  const anyPending = computed(() => getList().some((e) => pending(e)))

  let asked = 0
  let timer = null
  let ticker = null

  function askAgainSoon() {
    if (timer || asked >= ASK_AFTER_MS.length) return
    timer = setTimeout(async () => {
      timer = null
      asked++
      try {
        await store.load()
      } catch {
        // The next look will try again.
      }
      if (anyPending.value) askAgainSoon()
      else asked = 0
    }, ASK_AFTER_MS[asked])
  }

  function startClock() {
    if (ticker) return
    ticker = setInterval(() => {
      now.value = Date.now()
      if (!anyPending.value) stopClock()
    }, TICK_MS)
  }

  function stopClock() {
    if (ticker) clearInterval(ticker)
    ticker = null
  }

  async function act(e, action) {
    busy[e.id] = true
    errors[e.id] = null
    try {
      await store.experimentAction(e.id, { action })
      const key = keyOf(e)
      refusalWhenAsked[key] = lastRefusal(e)?.at ?? null
      requested[key] = action
      now.value = Date.now()
      askedAt[key] = now.value
      confirming.value = null
      // Each request gets its own run of looks.
      asked = 0
      askAgainSoon()
      startClock()
    } catch (err) {
      errors[e.id] = serverMessage(err)
    } finally {
      busy[e.id] = false
    }
  }

  // Let go of the timers. Done by itself when the section is left.
  function stop() {
    if (timer) clearTimeout(timer)
    timer = null
    stopClock()
  }
  if (getCurrentScope()) onScopeDispose(stop)

  return {
    busy,
    errors,
    requested,
    refusalWhenAsked,
    confirming,
    askToStop,
    cancelStop,
    isConfirmingStop,
    now,
    pending,
    problem,
    pendingTooLong,
    anyPending,
    act,
    stop,
  }
}
