# Finding where members struggle: instrumenting for confusion, second thoughts and underuse

**Date:** 2026-10-11
**Status:** plan only, nothing implemented. Written from the code as it stands on master, not from
the docs.
**Builds on:** `2026-07-03-photo-upload-telemetry-gap-and-give-funnel.md` (the give funnel work),
`flow-explorer-design.md` and issue #89 (the visual layer), #107 (component names, impressions,
interaction capture, now on master).

## 1. The question

We log a great deal, and nearly all of it answers "what did the system do". The question here is
"where do people get stuck, hesitate, go back, give up, or never find a thing at all", and that is
a different question with different data. The system logs cannot answer it after the fact because
the signal is in what people did *not* do, and in the order and timing of what they did.

The right way to instrument for it is not more logging. It is:

1. Decide the **pain signals** we want to see (section 3). Each one is a precise definition, not a
   vibe.
2. Emit a small set of **semantic events** that make those signals computable (section 5), on top of
   the automatic page, click and impression events we already have.
3. **Roll the raw stream up nightly** into tables that keep for years, because the raw stream keeps
   for seven days (section 6).
4. Put the results somewhere a human looks weekly, with a path from "this step is worse" down to
   ten real sessions (section 7).
5. Add the one channel that tells you **why**: a tiny, rare, in-flow prompt at the moment someone
   gives up (section 8).
6. Close the loop with a **controlled change** and a before/after that uses the same tables
   (section 9).

Sections 10 to 12 cover privacy, cost and the guardrails that keep the data honest, then the phased
plan.

## 2. What exists today, and what it can and cannot tell us

Mapped from the code on 2026-10-11.

**Pipeline.** `composables/useClientLog.js` batches browser events (every 5s or 10 entries, flushed
on tab hide and unload, waits up to 10s for login state so `user_id` is attached) to
`POST /apiv2/clientlog`. `clientlog/clientlog.go` relays them to Loki as `source="client"` with
`event_type` as a label, `user_id` and `user_bucket` as structured metadata. Nothing from it
reaches MySQL. Retention is 7 days. There is no sampling and no rate limit, and every `/clientlog`
POST is also logged as a `source="api"` line by the general middleware.

**Automatic events** (fire everywhere, no per-feature code):

| Event | Source | What it gives us |
|---|---|---|
| `page_view` on every route change | `plugins/sentry.client.ts` | The route sequence per session. The spine of any funnel. |
| `session_start` | same, plus `stores/mobile.js` in the app | Device, viewport, connection, app version and build. The segmentation keys. |
| `interaction` (click, change, submit, swipe, scroll every 500ms) with `component`, `tag`, `label` in the message, `interactive` | `plugins/interactionCapture.client.js` | Which component people touch; raw material for dead and rage clicks. |
| `impressions` every 30s per component type | `plugins/impressionTracker.client.js` | What was on screen, so clicks have a denominator. |
| `modal_open` / `modal_close`, `viewport_resize`, `orientation_change`, `back_nav_popstate` | `useClientLog.js`, `back-navigation.client.js` | Context, and one half of "went back". |
| `api_abandoned`, `connection_wait_abandoned`, `image_host_unreachable` | `BaseAPI.js`, `stores/misc.js` | Infrastructure friction the member felt. |

**Semantic events** (explicit `action()` calls) exist in patches: the photo uploaders
(`upload_file_selected` through `upload_failed`, added after the 07-03 analysis), the reply state
machine (`reply_state_transition` with `from_state`, `to_state`, `event`, in
`composables/useReplyStateMachine.js`), the donation asks, the job ads, `help_option_click`, the
session restore outcomes. The reply state machine is the model to copy: states and events are
enums, every transition is one log line, and the whole reply journey can be rebuilt from them.

**What is missing.** The give and ask flows (`pages/give/**`, `pages/ask/**`,
`stores/compose.js`) emit no funnel events at all: no step entered or completed, no validation
failure, no submit result. The login modal records bandit `shown` and `chosen` but not which stage
people dismiss it at. Settings and ModTools have no explicit events. Nothing anywhere records
*going back a step*, *changing an answer*, or *time spent on a step*. And nothing is kept past a
week, so "is this getting worse" cannot be asked.

**Analysis side.** Four Grafana dashboards, none of which query `source="client"`. Two Python
audit scripts (`scripts/freegle-user-audit.py`, `scripts/modtools-usage-audit.py`) that pull Loki
into an HTML report by hand. One sysadmin panel that is the right shape: `ModSysAdminBrowseScroll`
reads a scroll depth curve from a MySQL table written by `POST /scrolldepth`, through
`browse/scroll.go`. That is a measurement endpoint, a table, a Go read endpoint and a Vue panel, and
it is the pattern this plan generalises.

**Server side.** The MySQL `logs` table and the message tables already hold the "regret" signals
that need no client work at all: posts withdrawn soon after posting, type changed on edit, the
same item posted twice, a promise undone. Nobody reads them for this purpose.

**Lessons already paid for.** The 07-03 analysis first concluded that 55% of logged-out posters
abandoned at the login modal. That was wrong: the message create write was not tagged with the
session id, so completions were invisible. The rule that falls out of it is in section 11:
**every pain figure needs a check that the instrumentation itself did not change.**

## 3. The pain signals

Each row is a definition we can compute, what it suggests, and where the data comes from. "New"
means a semantic event from section 5; "exists" means it is derivable from today's stream.

| Signal | Definition | Suggests | Data |
|---|---|---|---|
| **Drop-off** | Flow instances that entered step N and never produced a later event in that instance within 30 minutes | A step that costs more than people will pay, or breaks | New: `step_enter`, `step_complete`, `flow_abandon` |
| **Went back** | `step_back` within a flow, or a `page_view` to an earlier step of the same instance | The step asked for something people had not decided yet, or the previous step was unclear | New (`step_back`); partially exists via `back_nav_popstate` and route order |
| **Changed an answer** | A field or choice set, then set again to a different value in the same instance (`field_revisit`), photo added then removed, Offer switched to Wanted | Second thoughts; a label that reads two ways | New |
| **Hesitation** | Dwell time per step, p50 and p90, by device class; time from step enter to first interaction | Reading, searching for the control, or not sure what is wanted | New (`step_enter` and `step_complete` timestamps); the automatic events make it approximate today |
| **Validation friction** | `validation_failed` per field and rule, and the count per instance before success | The rule is not explained, or the input control invites the wrong format | New |
| **Repeated intent** | Same labelled control clicked 2+ times, 2 to 30 seconds apart, with no matching API arrival | Nothing happened and the member retried. The 2026-09 iOS Facebook login regression looked exactly like this | Exists (`interaction` plus `source="api"`) |
| **Rage click** | 3+ clicks on one target within 1 second | The control looks live and is not, or is slow | Exists (`interaction`) |
| **Dead click** | Click on an element with `interactive=false` whose tag or class is in a "looks clickable" list | Affordance without action | Exists; the list is new |
| **Help sought during a flow** | `/help` view, `help_option_click`, or Contact Support opened within 10 minutes of a flow step, with the step attached | Confusion at that step | Exists in part (`help_option_click`); needs the originating page carried |
| **Searched and left** | Search with zero results; re-search within 60s with a changed term; scroll to the end of browse without opening anything | The content is not there, or the ranking hides it | Exists in part (`source="search"`, browse scroll depth); zero-result and re-search need marking |
| **Underuse** | For a curated list of features: impressions versus clicks per component; distinct users using the feature in 28 days over active users | Not found, not understood, or not wanted. The three need the drill-down to tell apart | Exists (`impressions`, `interaction`, `user_id`); needs a feature list and a rollup |
| **Regret after the fact** | Post withdrawn within 24h of posting; type edited; duplicate post within 10 minutes; promise withdrawn; "is this still available" reply to a taken post | A misunderstanding that only shows up downstream | Exists in MySQL (`logs`, `messages`, `chat_messages`); needs a nightly query |
| **Platform split** | Every signal above by mobile web, iOS app, Android app, desktop, and new versus returning, logged in versus out | Where to look first; a mobile-only drop is a layout bug, an everywhere drop is a design one | Exists (`session_start`, `user_id`, `url` scheme) |

The signals that need **no new client code** (repeated intent, rage, dead clicks, help seeking,
regret after the fact, platform split) can be built first and are worth building first: they
calibrate the rollup on data we already trust.

## 4. Approaches considered

**A. Adopt a product analytics tool (PostHog, Matomo).** Funnels, retention and session replay out
of the box. Rejected. We removed Google Analytics and Inspectlet deliberately (the privacy page
records both), session replay is a data protection stance we have already taken, it is a second
pipeline next to one that works, and it is a third stack to run on a host that is already short of
CPU.

**B. Compute from raw Loki on demand** (the flow explorer design as written). A Go endpoint reads
seven days of `source="client"` and builds the funnel per request. Rejected as the primary store.
Retention caps every question at a week, `count by (session_id)` hits the 500 series cap so
sessionising has to happen in code over the raw lines, the 07-03 queries timed out at a two day
window, and there is no trend. It stays as the **drill-down**: from an aggregate to the last few
days of real sessions.

**C. Semantic events, rolled up nightly into MySQL, shown in the sysadmin dashboard, with Loki
for drill-down and a rare in-flow "why" prompt.** Recommended. It reuses every pattern we have:
the reply state machine for events, `stats:generate-daily` and `mail:tracking:fold` for the nightly
job, `LokiService::queryRange` for reading Loki from Laravel, `browse/scroll.go` and
`ModSysAdminBrowseScroll` for the panel, `ExperimentBucket` and the bandit for variants. It gives
multi-year trends for the cost of a few small tables, and the sysadmin dashboard is where the
people who would act on it already look.

## 5. The event contract

One file owns the vocabulary: `iznik-nuxt3/composables/useFlowTelemetry.js`, with the flow and step
names and the event shapes as exported constants, the way `useReplyStateMachine.js` exports
`ReplyState` and `ReplyEvent`. A query that is written against a name in that file is written
against something the tests enforce (section 11).

**Envelope** on every semantic event, added by the composable, never by the caller:

| Field | Meaning |
|---|---|
| `flow` | `give`, `ask`, `reply`, `login`, `settings`, `chat`. A bounded set; safe as a Loki label if we want it indexed. |
| `flow_instance_id` | New uuid each time a flow starts. Lets a retry or a restored draft be told from a fresh attempt, and makes a funnel a count of instances, not of page views. |
| `step` | Route name for routed flows (`give-mobile-photos`), state name for state machines. |
| `step_seq` | 1, 2, 3 within the instance, so "went back" is `step_seq` decreasing. |
| `dwell_ms` | On `step_complete`, `step_back` and `flow_abandon`: time since the matching `step_enter`. Computed in the browser so the rollup does not have to pair lines. |
| `entry` | On `flow_start`: what started it. Navbar, FAB, home card, deep link, email, draft restored. |
| `device_class`, `logged_in`, `is_app`, `app_version` | Copied from the session start so every line is self-sufficient and the rollup never has to join to `session_start`. |
| `variant` | The experiment arm when one applies (section 9). |

Plus the existing `session_id`, `page_id`, `user_id`, `url`, `trace_id` from `useClientLog`.

**Events** (the `action_name`):

| Event | When | Extra fields |
|---|---|---|
| `flow_start` | The flow's first page or state | `entry` |
| `step_enter` | Each step shown | |
| `step_complete` | The step's forward control succeeded | `dwell_ms` |
| `step_back` | Back button, back gesture, or earlier step chosen from the progress bar | `dwell_ms`, `to_step` |
| `field_revisit` | A field that already held a value is changed to a different one | `field`, `change_kind` (`replaced`, `cleared`, `toggled`) |
| `validation_failed` | A forward attempt refused client side | `field`, `rule`, `attempt` |
| `choice_switched` | A top level choice changed: Offer to Wanted, group, delivery option | `from`, `to` |
| `flow_submit` | The write was sent | `attempt` |
| `flow_submit_result` | The write returned | `ok`, `status`, `error_code`, `dwell_ms` from `flow_submit` |
| `flow_complete` | The success page or state | total `dwell_ms` from `flow_start` |
| `flow_abandon` | Route left for a non-flow route, modal closed, `pagehide`, or 30 minutes idle | `reason` (`navigated`, `closed`, `hidden`, `idle`), `last_step` |
| `flow_resume` | A saved draft restored into the flow | `age_ms` of the draft |
| `help_sought` | `/help`, `help_option_click` or Contact Support opened while a flow instance is live | the live `flow` and `step` |

No field **values** are ever logged. `field_revisit` carries the field name and the kind of change,
not the text. That keeps the stream free of member content and keeps the privacy page true.

**Flows to instrument, in order:**

1. **Give and Ask** (`stores/compose.js`, `pages/give/**`, `pages/ask/**`, `PostMessage*`,
   `WizardProgress*`). The highest volume and the one we have already half analysed. Steps are the
   routes. `choice_switched` covers Offer/Wanted and group. `field_revisit` on title, description,
   postcode, photos (`photo_removed` after `upload_succeeded` is a revisit). `validation_failed`
   where `PostMessage` refuses to continue.
2. **Reply** (`useReplyStateMachine.js`). Already has the transitions. Work is to wrap them in the
   envelope so the same rollup reads them: `flow_instance_id`, `dwell_ms`, and `flow_abandon` when
   the composer is left in `COMPOSING`.
3. **Login and signup** (`LoginModal.vue`). `flow_start` with `entry` (what forced the modal),
   `step_enter` per pane (choose method, email, password, forgot), `flow_abandon` with the pane it
   was closed on. This is where "changed their mind" about joining lives.
4. **Chat actions** (`ChatFooter`, `PromiseModal`, outcome buttons). Promise, mark taken, address
   book: short flows, one or two steps each.
5. **Settings** (`pages/settings/**`). Not a funnel; `field_revisit` and `choice_switched` here
   are the signal, with the saved result.

**Correlating writes.** `BaseAPI.js` already sends `X-Freegle-Session` and `X-Freegle-Page`, and
`lokiMiddleware.go` records them on `source="api"` lines. Phase 0 verifies on production that the
message create write now carries the give session id (the 07-03 analysis found it did not), and
adds `X-Freegle-Flow-Instance` so the API line can be joined to the instance without the session.

## 6. Keep it: the nightly rollup

A Laravel command `ux:rollup-daily`, scheduled after the Loki day is complete, reading
`LokiService::queryRange` the way `monitor:deprecated-endpoints` does, writing MySQL. Idempotent
per day; backfills any of the last six days that are missing, so a missed night is not a hole.

Tables (every table gets an `id`, per the conventions):

| Table | Grain | Columns beyond the key |
|---|---|---|
| `ux_flow_daily` | date, flow, step, device_class, logged_in, is_app, variant | `entered`, `completed`, `backed`, `abandoned`, `revisits`, `validation_failures`, `help_sought`, `dwell_p50_ms`, `dwell_p90_ms` |
| `ux_flow_outcome_daily` | date, flow, device_class, logged_in, variant | `started`, `submitted`, `submit_ok`, `submit_failed`, `completed`, `resumed`, `total_dwell_p50_ms` |
| `ux_component_daily` | date, page, component, device_class | `impressions`, `clicks`, `dead_clicks`, `rage_clicks`, `repeated_intent` |
| `ux_feature_adoption_daily` | date, feature, device_class | `active_users`, `using_users`, `sessions_using` |
| `ux_search_daily` | date, device_class | `searches`, `zero_result`, `re_searched_60s`, `browse_scrolled_out` |
| `ux_regret_daily` | date, signal, group_id | `count`, with `signal` in (`withdrawn_24h`, `type_edited`, `duplicate_10m`, `promise_withdrawn`, `reply_after_taken`). Written from MySQL, not Loki, by the same command. |
| `ux_telemetry_health_daily` | date, flow or event | `expected`, `observed`, `ratio`. See section 11. |

Nothing per member is stored here. The rollup keeps counts and percentiles; the member level
detail stays in Loki for its seven days and is reached through the drill-down.

The `features` list for adoption is a small table or config: feature key, the route or event that
counts as "used". Saved searches, noticeboards, volunteering, stories, chitchat, address book,
calendar, giftaid, the settings page, promise, mark taken, the app itself. Add to it when a feature
ships; that is the moment someone cares whether it gets used.

**Session sampling for the drill-down.** For each (flow, step) with abandonment, the rollup also
stores up to 20 `session_id`s from the day in `ux_sample_sessions` (date, flow, step, signal,
session_id). The ids are useless after seven days and the row says so; they exist only so the panel
can offer "show me ten of these" while the data is still there.

## 7. Look at it: the sysadmin UX tab

A new tab on `modtools/pages/sysadmin/index.vue` next to Scrolling, Recommendations and
Reengagement, Support and Admin only, following `ModSysAdminBrowseScroll` and `browse/scroll.go`:
Go read endpoints under `/modtools/ux/*` over the tables above, with the `dashboard` package's
TTL and single flight cache, and Vue panels using the existing `ModEmailDateFilter` and `GChart`.

Panels, each a question:

1. **Funnels.** Per flow, step conversion as a horizontal strip, with the previous period greyed
   behind it. Click a step for the split by device class, logged in, variant, and the dwell
   percentiles. This is the flow explorer's strip view over the rollup instead of over raw Loki;
   #89's tree view and D3 layout can come later if the strip is not enough.
2. **Friction leaderboard.** Top 20 (page, component) by rage clicks, dead clicks, repeated
   intent, with the week on week change. Sorted by change, not by count, so a new problem beats a
   big old one.
3. **Second thoughts.** `step_back`, `field_revisit` and `choice_switched` rates per step and
   field. The fields people change most are the fields whose first ask was unclear.
4. **Help sought.** Which steps people leave for help, and which help option they pick.
5. **Underuse.** The feature adoption table, and the component impressions versus clicks table
   filtered to components with many impressions and few clicks.
6. **Regret.** The `ux_regret_daily` signals as a time series.
7. **Search.** Zero result and re-search rates.
8. **Data health.** The telemetry health ratios, red when a flow's events fell while its page
   views did not. This panel is first in the tab, not last, so nobody reads a drop in events as a
   drop in people.

**Drill-down.** Every row that is a (flow, step, day) offers "sample sessions". The Go endpoint
pulls each sampled `session_id`'s client lines from Loki through the existing `systemlogs` Loki
client and renders a compact timeline: step, event, dwell, interaction labels. Ten of these next to
a 30% drop tells a person what the number cannot. It is also the right input for the AI support
helper (`supportai`) if we later want a first pass at "what do these ten have in common".

**Alerting.** A weekly summary mail to geeks@ from the same command family as
`monitor:deprecated-endpoints`: the five biggest week on week changes in step conversion, friction
and regret, each with the sysadmin link. Alerts name a change, never a level, because levels have
no agreed threshold and a change always deserves one look. The monitor FSM is not fit for
unattended action and this does not change that; the mail is for a person.

## 8. Ask them: the in-flow "why" prompt

Everything above says where. Only members can say why, and the cheapest honest way to ask is at
the moment they leave.

A small inline card, not a modal, shown on the page a member lands on after `flow_abandon` with
`reason=navigated` from give, ask or reply (not on `hidden` or `idle`; they are gone):

> Didn't finish your post? One tap tells us why: **Just looking** · **Too many steps** ·
> **Photo trouble** · **Changed my mind** · **Something didn't work** · **Other**

Rules that keep it from being a nuisance and a liability:

- Shown to a 10% sample of abandoning instances, at most once per member per 30 days, never to a
  member in their first session, never in the app's first launch.
- "Other" opens a 200 character box. That text is member content: it goes in MySQL
  (`ux_feedback`: id, timestamp, flow, last_step, reason, text, user_id nullable, device_class),
  appears in the user data dump, and is purged on the same schedule as other member text.
- The card itself emits `why_prompt_shown`, `why_prompt_answered`, `why_prompt_dismissed`, so its
  own answer rate is known and it can be switched off by config if it is unwelcome.
- Panel 9 on the UX tab: reasons by flow and step, and the free text with a per entry link to the
  session timeline if still within seven days.

This is a product decision as much as an engineering one, and it is the one item in this plan that
Edward or the board may want to say no to. It is phased last and is independent of everything
else, so a no costs nothing.

## 9. Change it: experiments that use the same tables

A pain point found is a hypothesis, not a fix. The fix should ship as a variant with a holdout and
be read from the same `ux_flow_daily` rows, split by `variant`.

- **Assignment**: `ExperimentBucket` (crc32 of user id and experiment name mod 100) for logged in
  members, the same function over `session_id` for logged out, so a member keeps the arm across
  the whole flow. Fixed ratios with a true control arm, as the re-engagement experiment does. The
  epsilon-greedy bandit in `abtest/abtest.go` is for picking the best of many asks; it erodes the
  control and should not be used for UX changes we want to measure.
- **Recording**: the composable reads the current arms and stamps `variant` on every event, so no
  new recording code per experiment.
- **Reading**: the funnel panel's variant split is the before/after. Significance is a sample size
  table in the panel, not a p value we would misread.
- **Bandit housekeeping** found while mapping: `landing` and `landing-button` record `chosen`
  but never `shown`, so their rate is stuck at 0; `SuggestedAddress` in ModTools records `shown`
  and never `chosen`; `signUpModal` marks all four variants shown at once so it is a click share,
  not a test; the `info` field is sent and dropped. These are small fixes for Phase 0.

## 10. Privacy and governance

- First party only. No third party analytics, no session replay. Already the stated position in
  `pages/privacy.vue` ("We record information about your browser/device and how you use the
  site... We don't pass anything identifying you to other people"). Nothing here needs the policy
  to change except, if section 8 ships, a line that we may ask why you did not finish and keep
  what you type.
- No member content in events. Field names, change kinds, rule names, lengths and counts only.
  Search terms stay in `source="search"` where they already are, not in the UX stream.
- Member identity stays as it is: `user_id` as structured metadata and `user_bucket` as the
  label. Nothing in the rollup is per member. The sampled session ids are a seven day pointer,
  not a record.
- ModTools traffic is excluded from member analytics by the `site` field the logging context
  already sends. Moderators are a different population and talk to us on Discourse.
- Cardinality discipline: `flow` is the only new label candidate and has a dozen values. `step`,
  `component`, `field` stay in the JSON body. No per instance or per member labels, ever; the
  2026-08-23 `user_id` label incident silently discarded half a million lines.
- Loki on the live hosts reads labels from `conf/alloy/config.alloy`'s promote list. Adding
  `flow` as a label is an ops change on each host, documented in `docs/ops/reference/logging.md`,
  and the rollup must work without it (filter after `| json`) so a host that has not been updated
  does not lose data.

## 11. Keep it honest: cost, volume and data quality

**Cost and volume.** Issue #107 measured 86k clicks a day and 330k page views a week. Before adding
anything, Phase 0 measures the `source="client"` bytes per day on production and the share from
`scroll` and `impressions`. Then:

- Sample `scroll` interactions and `impressions` per **session**, not per event (a coin flipped
  once at `session_start`, carried in the envelope as `sampled`), at 10%, and scale in the rollup.
  Semantic flow events, errors and `session_start` are never sampled; a funnel with holes is worse
  than no funnel.
- Stop double logging `/clientlog` POSTs as `source="api"` lines in `lokiMiddleware.go`.
- A per IP rate limit on `/clientlog` in Go, generous, logged when it trips.
- The composable's `pageView` carries `page_id` and the modal context like the other events do
  (it does not today), and `interaction` carries `label` as its own field rather than inside the
  message string, so the rollup does not parse prose.

**Data quality**, because the 07-03 mistake will happen again otherwise:

- **A telemetry contract test.** A Playwright test that walks the give flow on the production
  container with `/apiv2/clientlog` intercepted, and asserts the exact ordered event sequence
  against the constants in `useFlowTelemetry.js`. Same for reply and login. A refactor that drops
  an event fails CI, not next month's analysis.
- **Vitest** on the composable: instance ids, dwell maths, `step_back` detection, sampling
  determinism, the abandon reasons, no field values in any payload.
- **A name check in CI**: every `action('...')` string in `iznik-nuxt3` that starts with a flow
  prefix must be a constant from the contract file.
- **The health rollup** (`ux_telemetry_health_daily`): per flow, `flow_start` count against
  `page_view` count of the flow's first route; per automatic event type, today against the
  trailing 14 day median. A ratio outside 0.7 to 1.3 reddens the health panel and goes in the
  weekly mail ahead of any pain finding. The question "did people change or did the code change"
  is answered before anyone reads a funnel.
- **Reconciliation on first run**: the give funnel from `ux_flow_daily` must agree with a hand
  count from Loki and with `messages` rows created, within a stated tolerance, before the tab is
  shown to anyone but the people building it.

## 12. Phases

Each phase is one PR sized piece of work with its own test gate, in the order below. Phases 2 and 4
can proceed in parallel with 1. Phase 6 is independent and gated on a product decision.

### Phase 0: fix the ruler

Measure production `source="client"` volume by event type. Verify on production that the message
create write carries the session id. Stop the double logging; add the rate limit; add per session
sampling for `scroll` and `impressions`; put `page_id` and modal context on `page_view`; make
`label` a field on `interaction`; fix the four bandit recording gaps; fix the latent `uint64`
versus `float64` `user_id` type in `clientlog.go:94` so a JWT bearing client line keeps its label.
Update `docs/ops/reference/logging.md`.
*Done when:* volume is known and reduced by the sampled share; every bandit has non-zero `shown`
where it records `chosen`; the logging doc matches the stream.

### Phase 1: the contract and the give/ask flow

`useFlowTelemetry.js` with the constants and envelope. Instrument give and ask: `flow_start`
with `entry`, `step_enter`/`step_complete`/`step_back`, `field_revisit`, `choice_switched`,
`validation_failed`, `flow_submit`/`flow_submit_result`, `flow_complete`, `flow_abandon`,
`flow_resume`, `help_sought`. `X-Freegle-Flow-Instance` on API calls from within a flow. Vitest on
the composable; the Playwright contract test for give and ask. Developer doc
`docs/developers/reference/ux-telemetry.md` with `covers:` on the contract file and the
instrumented pages, so the freshness check holds it to the code.
*Done when:* the contract test passes against the production container and a day of local use
produces a funnel by hand from Loki that matches the page view funnel.

### Phase 2: the rollup

`ux:rollup-daily` with the tables in section 6, including the regret signals from MySQL and the
health ratios. Idempotent, backfilling six days. Laravel feature tests with fixture Loki responses
through a mocked `LokiService`. Migration with the raw production SQL in the PR description.
*Done when:* seven days of production rollup reconcile with hand counts within tolerance, and a
deliberately skipped night is backfilled by the next run.

### Phase 3: the UX tab

Go endpoints under `/modtools/ux/*` with cache and single flight; the eight panels, health first;
the session timeline drill-down over Loki; the weekly change mail. Go tests over fixture rows;
vitest on the panels; a Playwright spec on the sysadmin page as the density panel has.
*Done when:* a Support user can go from "give/details conversion fell 8 points on Android this
week" to ten session timelines in three clicks.

### Phase 4: reply, login, chat and settings

Wrap the reply state machine in the envelope; instrument the login modal panes and dismissals;
chat actions; settings revisits. Extend the contract tests.
*Done when:* each flow has a funnel row and a passing contract test.

### Phase 5: experiments on the same rails

`variant` stamping from `ExperimentBucket` for logged in and from `session_id` for logged out;
the funnel panel's variant split with sample sizes. Run the first one on the biggest Phase 3
finding; the obvious candidate from the 07-03 work is details before photos on mobile give.
*Done when:* one UX change has shipped behind a holdout and its effect is read from the tab.

### Phase 6: the "why" prompt (product decision first)

Section 8 as specified, behind a config switch defaulting to off, with the `ux_feedback` table,
dump and purge wiring, and the reasons panel. Needs a yes from Edward or the board and a line in
the privacy page before the switch is turned on.

## 13. What this does not do

- It does not watch moderators. ModTools has its own population and its own feedback channel.
- It does not replace talking to members. It tells you where to look and lets you read ten
  sessions; the "why" prompt gives one tap of reason. Discourse, the support mailbox and the
  moderators remain the qualitative source, and the AI support helper can be pointed at the
  session timelines later if the volume warrants it.
- It does not promise unattended action. Alerts go to people. The system reaches an outcome when
  nobody is there only in the sense that the tables are filled and the mail is sent; what is done
  about it is a person's call.

## 14. Open decisions, with the default taken here

| Decision | Default in this plan | Who might want otherwise |
|---|---|---|
| Promote `flow` to a Loki label | Yes, bounded set, but the rollup never depends on it | Ops, if Alloy changes on the live hosts are unwelcome |
| Sampling rate for scroll and impressions | 10% per session, scaled in the rollup | Anyone wanting exact dead click counts on rare components |
| Where the plan's doc lives | `docs/developers/reference/ux-telemetry.md` for the contract; this file for the rationale | |
| The "why" prompt | Built last, off by default, needs a yes | Edward, board, data protection |
| Flow explorer tree view (#89) | Deferred; the strip view over the rollup first | Whoever wanted the picture |
