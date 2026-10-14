---
last_reviewed: 2026-10-14
owner: Freegle dev team
covers:
  - iznik-nuxt3/composables/useFlowTelemetry.js
  - iznik-nuxt3/composables/useExperiment.js
  - iznik-nuxt3/plugins/uxExperiments.client.js
  - iznik-nuxt3/ux-experiments.manifest.json
  - iznik-nuxt3/tests/unit/uxExperimentsManifest.spec.js
  - iznik-nuxt3/components/MicroVolunteeringUxPrompt.vue
  - iznik-nuxt3/composables/useFlowRoutes.js
  - iznik-nuxt3/composables/useComposeTelemetry.js
  - iznik-nuxt3/composables/useLoginTelemetry.js
  - iznik-nuxt3/composables/useSettingsTelemetry.js
  - iznik-nuxt3/plugins/flowTelemetry.client.js
  - iznik-nuxt3/composables/useReplyStateMachine.js
  - iznik-nuxt3/composables/useClientLog.js
  - iznik-nuxt3/plugins/interactionCapture.client.js
  - iznik-nuxt3/plugins/impressionTracker.client.js
  - iznik-batch/app/Services/Ux/UxRollupService.php
  - iznik-batch/app/Console/Commands/Ux/RollupDailyCommand.php
  - iznik-batch/app/Console/Commands/Ux/WeeklyReportCommand.php
  - iznik-batch/app/Services/Ux/UxExperimentService.php
  - iznik-batch/app/Services/Ux/UxPromptService.php
  - iznik-batch/app/Services/Ux/BetaBinomial.php
  - iznik-batch/app/Services/Ux/UxRng.php
  - iznik-batch/app/Services/Ux/UxScrub.php
  - iznik-batch/app/Services/UserManagementService.php
  - iznik-batch/app/Services/UserDataExportService.php
  - iznik-server-go/userdump/collect_db.go
  - iznik-server-go/user/user.go
  - iznik-batch/app/Console/Commands/Ux/ExperimentsTickCommand.php
  - iznik-batch/app/Console/Commands/Ux/ExperimentCommand.php
  - iznik-batch/app/Console/Commands/Ux/PromptCommand.php
  - iznik-batch/config/freegle.php
  - iznik-batch/database/migrations/2026_10_12_000001_create_ux_telemetry_tables.php
  - iznik-batch/database/migrations/2026_10_14_000001_create_ux_experiment_tables.php
  - iznik-batch/database/migrations/2026_10_14_000001_create_ux_experiment_tables_migration.sql
  - iznik-server-go/ux/ux.go
  - iznik-server-go/ux/experiments.go
  - iznik-server-go/microvolunteering/uxprompt.go
  - iznik-server-go/config/config.go
  - claude-agent-sdk/ux-ideas-prompt.js
  - claude-agent-sdk/ux-ideas.js
  - claude-agent-sdk/ux-themes.js
  - claude-agent-sdk/server.js
  - iznik-batch/app/Services/Ux/UxIdeasService.php
  - iznik-batch/app/Console/Commands/Ux/IdeasRefreshCommand.php
  - scripts/ux-ideas-snapshot.sh
  - .claude/workflows/ux-ideas-backtest.js
  - iznik-nuxt3/modtools/components/ModSysAdminUx.vue
  - iznik-nuxt3/modtools/components/ModSysAdminUxFunnels.vue
  - iznik-nuxt3/modtools/components/ModSysAdminUxExperiments.vue
  - iznik-nuxt3/modtools/components/ModSysAdminUxSurveys.vue
  - iznik-nuxt3/modtools/components/ModSysAdminUxFunnelChart.vue
  - iznik-nuxt3/modtools/components/ModSysAdminUxProblems.vue
  - iznik-nuxt3/modtools/components/ModSysAdminUxSaid.vue
  - iznik-nuxt3/modtools/composables/useUxFunnel.js
  - iznik-nuxt3/tests/e2e/test-modtools-sysadmin-ux.spec.js
  - iznik-batch/tests/Feature/Ux/UxExperimentServiceTest.php
  - iznik-batch/tests/Unit/Ux/BetaBinomialTest.php
  - iznik-server-go/test/ux_experiments_test.go
  - claude-agent-sdk/ux-ideas-prompt.test.js
  - claude-agent-sdk/ux-ideas.test.js
  - claude-agent-sdk/ux-themes.test.js
  - iznik-batch/tests/Feature/Ux/UxPromptFarmingTest.php
  - iznik-batch/tests/Unit/Ux/UxRngTest.php
  - iznik-batch/tests/Feature/Ux/UxIdeasRefreshTest.php
---

# UX telemetry

How we find where members get stuck, go back, change their minds, give up, or never find a
feature. The rationale and the alternatives considered are in
`plans/2026-10-11-ux-pain-point-instrumentation.md`; this page is the contract the code is held to.

```mermaid
flowchart LR
    B["Browser: useFlowTelemetry,<br/>interaction and impression plugins"] -->|POST /clientlog| G["apiv2"]
    G --> L[("Loki, source=client<br/>7 days")]
    L -->|nightly ux:rollup-daily| M[("MySQL ux_* tables<br/>years")]
    D[("MySQL messages, logs,<br/>chat, browse scroll")] -->|same job| M
    M --> A["GET /modtools/ux/*"] --> T["SysAdmin UX tab"]
    L -->|sample sessions drill-down| A
    M -->|weekly| W["ux:weekly-report mail"]
```

## 1. Fields on every client log line

`useClientLog.js` `queueLog` adds these to every entry, so no line needs a join to `session_start`:

| Field | Values |
|---|---|
| `device_class` | `app-ios`, `app-android`, `mobile` (width < 768), `tablet` (768 to 1199), `desktop` |
| `logged_in` | `true` / `false` |
| `is_app` | `true` / `false` |
| `page_route` | The Vue route **name** (`give-mobile-photos`, `message-id`), never the URL. Bounded, so it can be grouped by. |

`page_view` also carries `page_id` and the modal context, like interaction events do.

Every **flow** event also carries two fields frozen when its flow started, `start_logged_in` (whether
the member was signed in then) and `started_ms` (when, in epoch milliseconds). `logged_in` itself is
read at send time, so it changes mid-flow when a member signs in as part of Give, Ask or Reply, and
is wrong for grouping the start and the completion of one flow. The experiment read groups by
`start_logged_in` and cuts by `started_ms` (8.3).

## 2. Flow events

Emitted only through `composables/useFlowTelemetry.js`, which owns the names as exported
constants (`FlowName`, `FlowEvent`). Each is a `/clientlog` entry with **`event_type="flow"`**
(a Loki label, so the rollup selects them cheaply) and `action_name` set to the event.

**Envelope**, added by the composable:

| Field | Meaning |
|---|---|
| `flow` | One of `FlowName`: `give`, `ask`, `reply`, `login`, `chat_promise`, `chat_outcome`, `settings` |
| `flow_instance_id` | uuid, new on each `flow_start` |
| `step` | Route name for routed flows, pane or state name otherwise |
| `step_seq` | 1, 2, 3 within the instance: the number of `step_enter`s so far, so `flow_start` carries 0 |
| `first_visit` | On `step_enter`: true the first time this instance enters this step |
| `dwell_ms` | On `step_complete`, `step_back`, `flow_abandon`: ms since the matching `step_enter`. On `flow_complete`: ms since `flow_start` |
| `variant` | The member's experiment arms as `name:arm` joined by `,`, sorted; `''` when none. Frozen at `flow_start`: every event of an instance carries the same value, even if logging in at the end changes the id the arm is computed from |
| `entry` | On `flow_start` only |

**Events** (`FlowEvent`):

| Event | Extra fields |
|---|---|
| `flow_start` | `entry` |
| `step_enter` | `first_visit` |
| `step_complete` | `dwell_ms` |
| `step_back` | `dwell_ms`, `to_step` |
| `field_revisit` | `field`, `change_kind` (`replaced`, `cleared`, `toggled`, `removed`). For give and ask, only a change to the same draft counts: the submit emptying the draft, or another draft replacing it, sends nothing |
| `validation_failed` | `field`, `rule`, `attempt` |
| `choice_switched` | `field`, `from`, `to` (only for enumerated choices, never free text) |
| `flow_submit` | `attempt` |
| `flow_submit_result` | `ok`, `status`, `error_code` |
| `flow_complete` | `dwell_ms`. A step still open when the flow completes (the one it submitted from) gets its `step_complete` first |
| `flow_abandon` | `reason` (`navigated`, `closed`, `hidden`, `idle`), `last_step`, `dwell_ms` |
| `flow_resume` | `age_ms`; `reason=idle` when it brings back an instance the idle timer ended |
| `help_sought` | (the live `flow` and `step` are in the envelope) |

**Never** a field value typed by a member: names, kinds, rules and counts only. The vitest suite
for the composable asserts this. The field helpers take no value arguments, and a field, rule,
step, entry or choice that is not a plain token (letters, digits and `_:.-`, up to 40) is sent as
`other` instead of being dropped.

An instance ends at `flow_complete` or `flow_abandon`; at most one of them is sent per instance,
except that an `idle` abandon can be followed by a `flow_resume` with `reason=idle` if the member
carries on, and the instance then continues to its own end. The rollup takes those abandons back
off, and counts only the other resumes (restored drafts) as `resumed`.
A hidden tab alone is not an abandon (the camera, photo picker or an app switch hides it and the
member returns); it only flushes the log. `flow_abandon` with `reason=hidden` is sent on a `pagehide`
that is not persisted into the back/forward cache, `idle` after 30 minutes with
no flow event, `navigated` when the route leaves the flow's routes, `closed` when a modal flow is
dismissed.

### Routed flows: steps from the router

For `give` and `ask` the pages call nothing. `composables/useFlowRoutes.js` holds a route-name to
rank map and `plugins/flowTelemetry.client.js` feeds it every navigation:

| Flow | Routes by rank |
|---|---|
| give | 1 `give`, `give-mobile`, `give-mobile-photos`, `give-clearance`; 2 `give-whereami`, `give-mobile-details`; 3 `give-options`, `give-mobile-options`; 4 `give-whoami`, `give-mobile-whereami` |
| ask | 1 `ask`, `ask-mobile`, `ask-mobile-photos`; 2 `ask-whereami`, `ask-mobile-details`; 3 `ask-whoami`, `ask-mobile-whereami` |

A move to a higher rank sends `step_complete` then `step_enter`; to a lower rank `step_back` then
`step_enter` (so `first_visit` is false); to an equal rank (a redirect between the desktop and
mobile page of the same step) only `step_enter`. Entering the first route starts the instance, with
`entry` taken from the last clicked component (`navbar`, `post_button`, `give_ask_buttons`,
`myposts`, `notification`), else `home`, `post_page`, `from_<route>` or `deep_link`; a saved draft
with content older than five seconds starts it as `draft_restored` and sends `flow_resume`.

Leaving the flow's routes ends the instance: `flow_complete` if a `flow_submit_result` with
`ok=true` was sent, otherwise `flow_abandon` with `reason=navigated`. Going from one flow to the
other also sends a `choice_switched` on `type` (`Offer`, `Wanted`). `flow_submit` and
`flow_submit_result` come from `stores/compose.js`, with a short `error_code`, never message text.

### Flows driven by their own component

| Flow | Where | Steps |
|---|---|---|
| `reply` | `useReplyStateMachine.js` `transitionTo` | the machine states; `entry` is the reply source, or `restored` |
| `login` | `LoginModal.vue` via `useLoginTelemetry.js` | `signup`, `signin`, `forgot`; `entry` is `forced` or `navbar` |
| `chat_promise` | `PromiseModal.vue` | `choose`; `entry` is `chat` or `maybe` |
| `chat_outcome` | `OutcomeModal.vue` | `choose`; `entry` is the outcome type |
| `settings` | `pages/settings` via `useSettingsTelemetry.js` | `settings`; the instance starts at the first save in a visit, so a visit that only looks sends nothing. It completes when the page is left after a save that worked, and abandons if every save failed. A setting saved a second time in the visit is a `field_revisit` |

API calls made while an instance is live carry the header `X-Freegle-Flow-Instance`, so the
`source="api"` line for the message create can be joined to the instance.

## 3. Interaction and impression fields

Flow, interaction and impressions lines carry `url` as origin and path only: the query string and hash are dropped, because they can hold an auto-login key (`?u=&k=`) or search terms. `interactive` is true when the click landed in or on anything matching the control selector (button, link, form field, label, `[role=button]`, `[tabindex]`, `.btn`, `[data-bs-toggle]`), or else within an element styled `cursor: pointer`. A Vue `@click` on a div leaves nothing for the selector to find, and the pointer cursor is what tells the member it can be clicked; the control reported is the outermost element of that run of pointer-cursor ancestors.

`plugins/interactionCapture.client.js` sends `label` as its own field (as well as in the message),
and on clicks:

| Field | True when |
|---|---|
| `rage` | This click is the third on the same element within 1 second; set once per burst, not on the clicks that follow it |
| `repeat_no_api` | The same labelled element was clicked 2 to 30 seconds ago and no API request started in the 1.5 seconds after that earlier click (background polling later does not count) |

Anything inside an element marked `data-ux-ignore` (the ModTools UX tab) is skipped: no clicks, scrolls or impressions.

Scroll interactions and `impressions` events are **sampled per session**: a coin flipped once per
browser session (sessionStorage) at `SAMPLE_RATE = 0.1`. Sampled-out sessions send neither; sent
lines carry `sample_rate`. Flow events, clicks, errors and `session_start` are never sampled.

`/clientlog` is not logged a second time as a `source="api"` line, and is rate limited per IP
(`CLIENTLOG_RATE_PER_MIN`, default 600 entries a minute). Flow events are counted separately
against ten times that, so other traffic from a shared address cannot crowd them out; entries
over either limit are dropped in place, keeping the batch's order. A tripped limit is logged once
a minute.

## 4. Experiments

`composables/useExperiment.js`: `useExperiment(name)` returns the member's arm for a configured
experiment, or `null`, which is control. Assignment is `crc32(<id>|<name>) % 100`, the same function
as `iznik-batch/app/Support/ExperimentBucket.php`, where `<id>` is the user id when logged in and the
client `session_id` otherwise. Arms come from the live config key `ux_experiments` (section 8.2):

```
running: {"<name>": {"arms": {"control": [0, 34], "b": [35, 99]}, "until": <unix seconds>}}
adopted: {"<name>": {"arms": {"b": [0, 89], "control": [90, 99]}, "adopted": true, "until": <unix seconds>}}
```

Bounds are inclusive. Every flow event carries the member's arms in `variant` as comma-joined
`name:arm` pairs, so the funnel splits by arm with no per-experiment recording code. The client's
safety rules (a kill switch, a sticky arm, an exposure event, a cap of five) are in section 8.2. Use
a control arm; the epsilon-greedy bandit in `abtest` is for choosing between asks, not for measuring
a change. How an experiment is run, read and decided is section 8.

## 5. Rollup tables

Written by `php artisan ux:rollup-daily [--date=YYYY-MM-DD]` (scheduled 02:20 UTC for yesterday,
and backfilling any of the previous five days that has no `ux_telemetry_health_daily` rows; five
because every one of them is still wholly inside Loki's seven-day retention). Rerunning a day
replaces it. A Loki error other than the series limit aborts the day and leaves its old rows; only
the series limit is split or skipped. Raw lines (impressions) are read 1000 to a request, halving
the window while a page comes back full: Loki passes each result internally in one message capped
at 4MB, and a larger page hangs until the client times out, which reads as "Loki unavailable".
Schema in the migration above.

| Table | From | Notes |
|---|---|---|
| `ux_flow_daily` | flow events | `entered` counts `step_enter` with `first_visit`; `reentered` the rest. `completed`, `backed`, `abandoned` and `help_sought` count once per visit, so rates over them divide by `entered + reentered` |
| `ux_flow_outcome_daily` | flow events | per flow |
| `ux_field_daily` | `field_revisit`, `validation_failed`, `choice_switched` | per field |
| `ux_component_daily` | `interaction`, `impressions` | impressions scaled by `1/sample_rate`; dead click = click with `interactive=false` |
| `ux_feature_adoption_daily` | `page_view` for the routes in `config('freegle.ux.features')`, `users.lastaccess` | |
| `ux_search_daily` | `source="vector_search"` lines, `browse_scroll_depth` | `re_searched_60s` is a floor: only logged-in searches can be linked |
| `ux_regret_daily` | MySQL | `withdrawn_24h`, `duplicate_10m`, `promise_reneged`, `reply_after_taken`; `base` is the denominator |
| `ux_telemetry_health_daily` | all of the above | `flow_start:<flow>` against the flow's entry `page_view`s; `event:<type>` against the trailing 14-day median |
| `ux_sample_sessions` | `flow_abandon` | up to 20 per (flow, step); deleted after 8 days |

## 6. Read API

All Support/Admin only, `start` and `end` as `YYYY-MM-DD` (default the last 7 days), and each
also returns the same figures for the preceding period of equal length as `previous`.

| Route | Returns |
|---|---|
| `GET /modtools/ux/funnels?flow=` | per flow and step: entered, reentered, visits (their sum, the denominator for rates), completed, backed, abandoned, revisits, validation_failures, help_sought, dwell p50/p90; `by` breakdown on `device_class`, `logged_in`, `variant` |
| `GET /modtools/ux/outcomes` | per flow: started, submitted, submit_ok, submit_failed, completed, abandoned, resumed |
| `GET /modtools/ux/fields?flow=` | per field: revisits, validation_failures, choice_switches |
| `GET /modtools/ux/friction` | top 20 (page, component) by rage, dead, repeated intent, sorted by change against previous |
| `GET /modtools/ux/adoption` | per feature: using_users / active_users |
| `GET /modtools/ux/regret` | per signal, per day |
| `GET /modtools/ux/search` | per day |
| `GET /modtools/ux/health` | per metric, per day, with `ok` false when ratio is outside 0.7 to 1.3 |
| `GET /modtools/ux/samples?date=&flow=&step=` | the sampled session ids and each one's client timeline from Loki (flow and interaction events, in order, with no member text) |

## 7. Where to look

The **UX** sub-tab of ModTools SysAdmin, with Data health first. `ux:weekly-report` mails the five
largest week-on-week changes to `config('freegle.ux.report_to')` every Monday.

`tests/e2e/test-ux-telemetry.spec.js` holds the flows to the event contract in section 2, and
`tests/e2e/test-modtools-sysadmin-ux.spec.js` checks that the tab loads every panel from the read
API without an error.

## 8. Experiments, prompts and ideas

The measurement above exists so that changes to the member site can be tried, judged and kept or
removed without a person in the loop. This section is how that works. The code is the contract;
this says where to look and why it is shaped the way it is. The numbers quoted are the defaults;
every one is a setting, listed with its environment variable in
[deployment-switches.md](deployment-switches.md).

```mermaid
flowchart LR
    Q["Microvolunteers: open questions<br/>(problems, values, what to change)"] -->|ux:prompt analyse, no tools| P[("ux_problems<br/>ranked by other members")]
    P --> G["Idea generator<br/>(themes only, never words)"]
    G --> I[("ux_ideas<br/>backlog per funnel")]
    I -->|one idea| PR["One PR: a variant behind<br/>useExperiment('name')"]
    PR -->|Netlify ships it| S["start: power check, one per flow,<br/>arms into config key ux_experiments"]
    S --> C["Client reads the key,<br/>sticky arm stamped in variant"]
    C --> L[("Loki")]
    L -->|hourly ux:experiments-tick| T["Read by arm in strata,<br/>decide within 24h"]
    T -->|adopted| H["90% new, 10% old holdout,<br/>daily decay check"]
    H --> K["Cleanup PR removes the switch,<br/>ux:experiment close"]
    T -->|reverted or inconclusive| R["Back to control; cleanup PR<br/>removes the variant"]
    T -.->|history read by| G
    M["Microvolunteers: better, worse,<br/>no difference, not sure what it is for"] -.->|reaction, never a gate| T
```

### 8.1 What an experiment is

One experiment is **one pull request, one switch and one row**:

- The PR adds the variant to the frontend only, behind `useExperiment('<name>') === 'b'`. Anything
  else, including `null`, is control. Nothing in the batch or the Go API changes per experiment,
  and neither does the schema.
- The switch is the `name`: letters, digits, `_` and `-`, up to 64, and not `__proto__`,
  `constructor` or `prototype` (the name becomes a key in a JSON object the browser reads). The read
  finds the arm by matching the name only at the start of the `variant` field or after a comma, so
  `cta` is not confused with `login_cta`. It is still kinder to the next reader not to reuse a
  name as a suffix of another.
- The row is in `ux_experiments` (`UxExperimentService`): the funnel (`flow`), the name, a title,
  the hypothesis, what the change does, the effect expected, the arms, the PR, optional before and
  after screenshots, the idea it came from, and a `design` object. The wording is plain English
  because the SysAdmin tab and the microvolunteer prompt show it as written.
- `design` holds `touches` (the flows the change can affect, which are the only other flows read
  as guardrails; default its own flow and `login`), `expected_points` (the effect expected, from the
  idea or 5 points) and, once started, `power` (8.4). It is set with `ux:experiment create
  --touches=a,b --expected-points=N`, or in the `design` of `POST /modtools/ux/experiments`.
- Status runs `draft`, `running`, then one of `adopted`, `reverted`, `inconclusive` or `stopped`.
  An adopted experiment becomes `closed` when its switch has been removed from the code (8.10);
  only an adopted one can be closed. A finished experiment that was reverted, inconclusive or
  stopped can be started again.
- At most **`max_concurrent` (5) run at once**, **one per flow**, and none for longer than
  **`max_hours` (24)**. `start` refuses a sixth, a second on the same flow (two changes to one funnel
  cannot be told apart) and an experiment the power check rejects (8.4), and sets the end time.

The six tables (`ux_experiments`, `ux_experiment_reads`, `ux_ideas`, `ux_prompts`,
`ux_prompt_responses`, `ux_problems`) are created once, by
`iznik-batch/database/migrations/2026_10_14_000001_create_ux_experiment_tables.php`. A new
experiment, idea, prompt, survey or problem is a row, never a migration.

### 8.2 How arms reach members, live

The arms of every running experiment are published as JSON in the `config` table, key
`ux_experiments`. Starting, re-weighting, adopting and reverting are all a change to that one row,
so none of them needs a deploy.

- **Server side.** `UxExperimentService::rebuildConfig()` writes the row from the `running` and
  `adopted` experiments. A running entry is `{"arms": {...}, "until": <ends_at + 1 hour>}`. An
  adopted entry serves the winner to most members and keeps the **holdout** on the old version:
  `{"arms": {"b": [0, 89], "control": [90, 99]}, "adopted": true, "until": <decided_at + 15
  days>}` (`holdout_share` is the 10). It runs on every start, stop, close and decision and at the
  end of every hourly tick, so the row cannot drift from the table. Do not edit it by hand; the
  next tick rewrites it. `GET /config/ux_experiments` is public (`isPublicConfigKey` in
  `iznik-server-go/config/config.go`) because it holds names, bucket ranges and times only, nothing
  about a member.
- **The `until` kill switch.** The batch is one process on one host. If it dies, a half-served
  experiment must not stay on for ever, so the client (`normaliseConfig` in `useExperiment.js`)
  treats a running entry as control unless its `until` is in the future **and less than 25 hours
  away**. A missing, past or implausibly distant `until` is control. At most five running entries
  are honoured (the first five by name). An **adopted entry's `until` is ignored**: expiring it
  would flip members back to the old design, which is worse than a stale entry. The batch writes the
  `until` on adopted entries anyway, for a reader of the row.
- **Fetching.** `plugins/uxExperiments.client.js` fetches the key at boot without awaiting it and
  hands it to `setLiveExperiments`, which keeps a copy and its fetch time in `localStorage`
  (`freegle_ux_experiments`). A deep link into a flow has the stored copy before the fetch returns.
  The copy is ignored for running entries once it is more than two hours old, so a member offline
  for a day is not held in an experiment that ended. If the fetch fails the stored copy stands
  (within that age); with none, the member is in control. The old flat shape
  `{"name": {"control": [0, 49], "b": [50, 99]}}` is read only from runtime config
  `public.UX_EXPERIMENTS` (local testing), never from the live key. The fetch is made quietly
  (`logError` false): see 8.12 for why.
- **A sticky arm.** The arm is worked out once, at the member's first exposure to the experiment,
  and kept in `localStorage` under `freegle_ux_arm:<name>` with the entry's `until`. Later calls
  return it even after the member signs in mid-flow (when the id the bucket is computed from would
  change) and even after the hourly re-weighting moves the boundaries, so a member does not see
  control on one visit and the change on the next. It is dropped when the experiment leaves the
  config, when `until` passes or differs (a restarted run of the same name starts fresh) or when the
  arm no longer exists. Adopted entries are served from the config and are not sticky. With
  `localStorage` blocked, the arm is held in memory for the page view.
- **An exposure event.** The first time an arm is evaluated in a page view the client sends one
  client log, `experiment_exposure`, with the name and arm, through `useClientLog` like any other
  event.
- **The arm is pinned to the flow instance.** `flowArm(flow, name)` in `useFlowTelemetry.js`
  returns the arm frozen into the live flow instance at `flow_start`, and only falls back to
  `useExperiment` when there is none. Variant UI inside a flow should use it, so what the member
  sees is what the events are stamped with.
- **Only switches this build contains are stamped.** `experimentVariant()` adds `name:arm` to a flow
  event only for an experiment the running bundle declares (a `useExperiment` call made in this page
  view, or a name in `ux-experiments.manifest.json`). A long-lived tab or an installed app from before
  the variant shipped fetches the new config but has no variant code, renders control, and so must
  not be counted in the variant arm: that would dilute the effect towards zero.

### 8.3 The hourly tick

`php artisan ux:experiments-tick`, scheduled at 17 minutes past each hour in
`iznik-batch/routes/console.php`. It is in the backup drain's always-run list, because it only reads
Loki and writes small rows; skipped, a decision slips past the 24 hours. For each running
experiment, `UxExperimentService::tick`:

1. **Reads Loki by arm** (`read`). The window is from `started_at` to the end of the experiment or
   now, and only flows that **started at least `settle_minutes` (45) before then** are counted
   (`started_ms <= cut`), with everything they did counted up to the end of the window. A flow
   started in the last three quarters of an hour has not had time to finish, and whichever arm just
   got more traffic would look worse for it; cutting on when the flow started, not on when each event
   happened, means a flow that began before the cut and finished after it still counts as a
   completion. A line from a browser that does not send `started_ms` is left out of both arms. The
   counts are fetched **hour by hour** with `start_logged_in`, and only whole hours inside the window
   are kept,
   because an hour that began before the experiment could hold an earlier run of the same name. The
   events are `flow_start`, `flow_complete`, `flow_submit_result`, `validation_failed` and
   `help_sought`. Name and flow are checked against their patterns again before they reach LogQL.
2. **Compares each variant with control**, within strata. The primary measure is the funnel's
   completion rate, `flow_complete` over `flow_start`, end to end, never one step, because fixing
   one drop-off can move the loss to the next. The counts are split into strata of six-hour block of
   the day times **signed in when the flow started** (not at the event: a login flow's completions
   are all signed in and its starts all signed out, which would make every stratum one-sided and
   every P near one half), each stratum's difference is computed, and they are weighted back
   together by their share of starts (`BetaBinomial::stratifiedDiff`). **Why:** the bandit moves
   the split every hour and the traffic mix changes with the clock (evenings, signed-in members). A
   pooled total would credit the arm that happened to get more traffic in the better hours;
   comparing like with like removes that (Simpson's paradox). The outputs are P(variant beats
   control), a 90% interval, and the **expected loss** of choosing the variant (8.4).
3. **Records the read** in `ux_experiment_reads` with a plain-English summary (8.6).
4. **Decides** (8.4), or else **re-weights**.

**Re-weighting (a bandit, not a fixed split).** While undecided, and only once **every arm has
`early_min_starts_per_arm` (300) starts**, `allocate` gives each variant the share of traffic
equal to its **stratified P(better than control)** from the same comparison `decide` uses (so the
split and the decision cannot disagree, and a difference that is only a swing in who arrived moves
nothing), held in a band: **every arm, control and variants alike, keeps at least
`min_control_share` (35, scaled by 2/k for k arms) and none takes more than `max_variant_share`
(65)**. The split is never an empty or inverted range; a variant that has a bad first few hours still
has a third of the traffic to recover on. Ranges are rewritten as contiguous blocks over 0 to 99,
control first, and `rebuildConfig` drops any empty range rather than publish it. A restarted
experiment (stopped, inconclusive or reverted before) begins from an even split, not where the bandit
left off. **Why a band:** a 10% floor
starved control and left too few people to read in a day; the band keeps both sides readable.
**Why 300 first:** with fewer, the posteriors are wide and the split would chase noise, moving
members between arms on no evidence. **Why a bandit at all:** waiting for classical purity makes
24 hours of a clearly worse variant half of traffic. `bandit` set false keeps the opening split.

**When Loki is down.** A failed query raises `UxLokiUnavailable`. The tick then reuses the stored
read only if it is under `stale_read_hours` (2) old, marked stale and with no new read row; a stale
read can revert but **can never adopt early**. With no usable read it records nothing that hour. Past
the end it waits up to `stale_wait_hours` (6) for Loki, then marks the experiment `inconclusive`
("taken off, nothing can be said about it"). It never adopts on old numbers, and never leaves an
experiment running unread.

**Concurrency.** `start`, `stop`, `close` and each experiment's step in the tick run inside
`GET_LOCK('ux_experiments')` taken on the write node (`locked()`), so two writers never interleave.
Every status change is an `UPDATE ... WHERE status = <the status expected>` that checks it changed a
row, and the tick **re-reads the row after its slow Loki query and before it finishes it**. So a
human "Stop now" that lands while a read is in flight wins, and two simultaneous starts cannot both
pass the cap of five. A tick that finds the row no longer running leaves it alone.

**Other duties of the same command**: close prompts that have collected their answers, turn
follow-up rankings into problem weights, blank the words of old open answers (8.8), run the holdout
check (8.5) and log `Cleanup overdue` for adopted experiments (8.10).

### 8.4 The decision rule, and why each number

`UxExperimentService::decide`. `p` is P(the best variant is better than control) and `loss` is its
expected loss in points; "starts" are flow starts on each arm.

| When | Result |
|---|---|
| Any hour, both arms have 300 starts, a guardrail is worse by more than 2 points with probability at least `1 - 0.03/k` | `reverted`, early |
| Any hour, both arms have 300 starts, read not stale, `p >= 0.99`, `loss <= 0.5`, and no guardrail at `1 - 0.10/k` | `adopted`, early |
| Any hour, both arms have 300 starts, `p <= 0.02` | `reverted`, early |
| At `ends_at`, either arm under 50 starts | `inconclusive` |
| At `ends_at`, a guardrail worse by more than 2 points with probability at least `1 - 0.10/k` | `reverted`, whatever the main result |
| At `ends_at`, `p >= 0.80` and `loss <= 0.5` | `adopted` |
| At `ends_at`, `p >= 0.80` but `loss > 0.5` | `inconclusive` |
| At `ends_at`, `p <= 0.20` | `reverted` |
| At `ends_at`, otherwise | `inconclusive` |

The default is the status quo: only `adopted` keeps a change, and `reverted`, `inconclusive` and
`stopped` all leave the config key, so everyone is back in control. Why each piece:

- **Expected loss as well as probability.** `p >= 0.80` alone would adopt a change worth +0.01
  points, because a tiny positive effect on a lot of traffic is "probably better". The loss bar asks
  what we would give up, on average, if we keep it and it is not better; over 0.5 points we say
  "inconclusive" instead.
- **Early stops need 300 starts an arm and a stricter bar (0.99 and 0.02).** The rule is run every
  hour, so there are up to 24 looks, and every look is another chance to be fooled by noise. A
  decision at the end asks for 50 starts and 0.80; an early one has to be unmistakable.
- **A guardrail trips only past a margin.** Guardrails are the failure and validation rates, help
  sought, and the completion of every other flow the change `touches` (8.5). Each is the
  probability the variant is worse than control by **more than 2 points**
  (`guardrail_margin_points`), a non-inferiority form: a null change read 24 times would otherwise
  cross a bare "probably worse" line by chance, and a good change would be stopped by noise in an
  unrelated flow. Each guardrail also needs 100 events behind it across both arms.
- **Multiplicity.** With `k` guardrails (three on the experiment's own flow plus one per other flow
  it touches) the chance of at least one false alarm grows with `k`. The error budget is split `k`
  ways: 0.10 for blocking at the end, 0.03 for the early revert, so each guardrail needs
  `1 - budget/k`. The early revert also waits for 300 starts an arm.
- **The settle lag and the strata** are in 8.3. **The bandit band** is in 8.3.
- **A power gate before it starts.** `start` takes the flow's recent daily starts and completion rate
  from the rollup (`ux_flow_outcome_daily`, last 7 days), and works out the chance a day of that
  flow would adopt a change of `design.expected_points` under exactly this rule
  (`BetaBinomial::adoptPower`, a normal approximation that ignores the stratification and the
  bandit). The figure is stored as `design.power`. Below `min_power` (0.30) `start` refuses with
  advice: merge the idea with another, or make the change bigger. A test that could almost never
  reach a decision would spend a flow's only slot for a day to say "inconclusive". It only runs when
  the rollup has data for the flow, and `min_power` 0 turns it off. The smallest effect a day
  could show at 80% power (`mde_points`) is recorded at start and shown in every read.
- **One experiment per flow.** Two changes to one funnel at once confound each other, and the
  guardrails already read across flows, so five slots across seven flows is enough.
- **Inconclusive does not burn an idea.** A day too short is not evidence the idea is bad, so the
  linked idea returns to `open` with `attempts` plus one, and is used up (`done`) after
  `idea_max_attempts` (2). Adopted and reverted ideas are `done` at once; a `rejected` idea is
  never revived.

### 8.5 Guardrails and the holdout

**Guardrails.** A change can raise the measure it aims at and hurt something else. For each variant
the read also compares, against control, with the same arithmetic and margin:

- the share of submissions that failed (`flow_submit_result` with `ok` false);
- validation failures per start;
- people who went looking for help per start;
- **the completion rate of every other flow in `design.touches`**.

The last works because `variant` is stamped on every flow event, not only the experiment's own
flow. A Give change that lowers Reply completion shows up as a guardrail on `reply` with no
per-experiment code, provided the experiment declared that it touches `reply`; flows it cannot
reach are not read, so they cannot cause a false alarm. The per-start rates are clamped at 1
because the events can repeat within one start, and are only meaningful while well below that.

**Holdout after adoption, and the decay check.** A gain that only looked good while it was new is
not a gain. So an adopted experiment is served as winner 90%, **old version 10%** until it is
closed (`rebuildConfig`). From 23 hours after the decision, then once a day for `holdout_days` (14)
while it stays `adopted`, `checkAdopted` re-reads the whole time since adoption, the adopted arm
against the holdout. When the holdout is better with probability at least
`holdout_revert_probability` (0.99) on `holdout_revert_looks` (2) looks in a row and **both sides
have 300 starts**, it reverts the adoption (status `reverted`, `result.decayed = true`, decision
"Reverted after adoption: the people who kept the old version did better over N days ..."). **Why a
high bar and a repeat:** there are up to fourteen daily looks, so a loose bar would undo good
changes by chance, and the holdout is the small side (a ninth of the adopted arm), so its noise is
the largest; a look that is not clear starts the count again (`result.holdout_check.strikes`). One
adopted experiment that cannot be checked (a lock timeout, a bad row) is logged and left; the others
are still checked and the config is still rebuilt. Closing the experiment (after the cleanup PR) ends the
holdout, so with the 7-day cleanup target the check usually runs about a week of the fourteen. A
Loki failure skips that day's check; it is never a reason to revert.

### 8.6 The plain-English reads

Every hourly read stores a paragraph: how many started with and without the change, the completion
rate each way and the difference in points, the probability the change is better, any guardrail
that looks worse, what we would expect to lose if we kept the change and it were no better, the
traffic share on the change, the smallest effect a day could show, and
whether enough people have been through to read it. A decision is one more sentence that says which
rule fired and carries the same figures, and, when microvolunteers have answered, what they said.
These are stored text, so the SysAdmin tab shows them as written and a person reading the history
needs no statistics.

### 8.7 Microvolunteer prompts

Microvolunteers are asked, in plain English, what they think. They are existing members who chose
to help and who prefer what they know (status-quo bias), so the answers measure how much existing
members might like or dislike a change, and say in their own words where the friction is. **They
never decide an experiment and never gate anything**: the decision rule in 8.4 does not read them,
and a prompt is an optional microvolunteering task, never a step inside a member flow. The idea
generator is told never to propose a prompt or a "why did you leave" question inside a flow.

A prompt is a row in `ux_prompts`; answers are rows in `ux_prompt_responses`, never in
`microactions`, so there is no schema change per prompt. Four kinds:

| Kind | Asks | Opened by |
|---|---|---|
| `experiment` | "For what you were doing, is it better or worse?" under "Here is a change we are trying: " and the plain-English change, never the effect we hope for, which would lead members to "Better"; up to two images side by side ("How it is now", "The change") | `start` opens it automatically; the decision closes it |
| `survey` | Which part of Freegle works best and which most needs improving, over the funnels in `UxPromptService::SURVEY_AREAS`, four at a time | `ux:prompt survey` (one general survey open at a time); `ux:prompt followup` for a ranking of problems |
| `awareness` | "On Freegle, is it possible to ...?" about one feature, answered yes, no or not sure, then the answer; separates "not found" from "not wanted" | `ux:prompt awareness` |
| `open` | A free-text question: what got in your way, what do you value, what would you change (8.8) | `ux:prompt open --preset=problems\|values\|improve` |

An experiment prompt has **four answers**, each a real answer: **Better**, **Worse**, **Makes no
difference to me** and **I'm not sure what it's for**. Better and Worse swap places for members
with an odd user id, so neither gets the first place's advantage. A change nobody minds is cheap to
adopt, and one people do not understand needs its wording fixed before its result means much.

A survey shows `display`: at most `settings.show` (4) of its options, chosen so each is shown about
equally often (fewest times shown so far first, from the answers already stored), in a random
order. The member must pick one for `best_label`, may pick a different one for `worst_label`, or
answers "none of these" or "don't understand". **Every closed answer records `shown`**, the option
keys in the order the member saw them. Go checks it: known keys only, no repeats, and the picks
among them. A tally can then be read per time an option was shown, which is what makes four at a
time fair. Asking about four at once keeps the question answerable on a phone.

An awareness answer is yes, no or not sure. After it the member sees `settings.reveal`, and a link
when `settings.link` is a path on our own site. About one awareness question in five should be a
**decoy** (`--decoy`): something Freegle does not do, whose right answer is no. The tab shows the
share who said yes to the decoys next to the real questions, so a reader can discount a sample
that says yes to anything.

An open question must have text (3 to 1000 characters after trimming, with control characters
other than newline and tab dropped) **or** one of two ways out that are always offered, "nothing"
and "don't understand", so nobody has to invent an answer.

Delivery is the `UxPrompt` challenge in `GET /microvolunteering`
(`iznik-server-go/microvolunteering/uxprompt.go`), and `POST /microvolunteering` with `ux_prompt_id`
and `ux_answer` records the reply. The rules: after the Invite task and ahead of the review
backlogs; at most one prompt per member per 7 days, and none for 30 days after two skips in a row;
the open prompt with the fewest answers
first, so they fill evenly; a member answers a prompt once and a second answer is ignored, so
nobody rewrites a vote after seeing the tally; a prompt closes itself at `max_responses` (200 by
default, 300 for an open question; the hourly tick closes full ones). **"Skip this one" is
recorded** (`{"skipped": true}`, accepted for any kind): without that, the member was handed the
same prompt again at once, ahead of the real review work, and on every visit after. A skip counts
towards the 7-day gap and makes the prompt not be offered again, and counts as nothing else: it is
in no tally, no reaction count, no quota and no weight. The component is
`components/MicroVolunteeringUxPrompt.vue`.

The tab shows the tally next to the measured result as "existing members' reaction", per
experiment, and the survey, awareness and open-question tallies in a "What members say" panel.
Free text is shown only behind a "show" toggle (8.8).

### 8.8 Idea farming: problems before solutions

Members are good at saying what annoys them and poor at designing the fix. So the loop asks about
**problems and what they value**, and solutions are generated and tested separately. A suggestion
("make the photo button bigger") is a solution; the problem behind it ("I could not find the
photo button") is what is kept and ranked, so a suggestion is never mistaken for evidence of a
problem.

1. **Ask.** `ux:prompt open --preset=problems|values|improve` opens an `open` prompt with one of
   three wordings (`UxPromptService::OPEN_PRESETS`), neutral and about one topic, each with a true
   way out. At most 300 answers.
2. **Code, with no tools.** `ux:prompt analyse <id>` (`--force` replaces an earlier analysis) sends
   the answers to the helper's `POST /internal/ux-themes`
   ([ai-support-helper.md](ai-support-helper.md)). What goes is the answer id and the text after
   `UxScrub` has taken out email addresses, postcodes and long digit runs; **no user id**. The
   model has **no tools at all**, so nothing an answer says can read a file or run a command. It
   works in two calls. The first splits each answer into statements tagged problem, value,
   suggestion or other, copied from the answer. The second codes themes over the statements, with
   the answers in a shuffled order, because a model's reading depends on order. The coder's rules:
   one theme per code; split a suggestion from the problem behind it; an answer may carry several
   themes; never invent a theme no answer states; statements that fit no theme go to one called
   "Other"; titles in plain words a member would use, at most 100 characters; every `answer_id`
   cited must be one that was sent. **Every theme quotes the answers it came from**, and the helper
   keeps a quote only when its words, ignoring case and spacing, appear in that answer. A theme
   left with no checked quote is dropped.
3. **File.** `UxPromptService::analyse` re-checks what came back (unknown answer ids dropped, a
   theme with none dropped, flow not a real funnel becomes "not tied to one funnel") and writes
   `ux_problems` rows: `kind` `problem` or `value`, `source` `members`, `mentions` the number of
   distinct answers, `evidence` the answer ids and the quotes. It checks each quote again against
   the scrubbed answer, and against an answer in its own theme, and keeps at most 8 of up to 300
   characters. A problem or value is filed only when **at least 2 members** raised it and one quote
   survived. The member suggestions are filed as `ux_ideas` rows
   (`source` `member`, `score` 0, `problem_id` set). A suggestion for a problem that belongs to no
   funnel is filed with flow `general`. The loop takes only `ai` ideas; member suggestions are for
   people reading the tab.
4. **Rank.** `ux:prompt followup <id>` opens a `survey` over the top 8 problems from that analysis
   by mentions, **only those raised by at least 3 members** (a single member's complaint, which may
   name a person, is never shown to others) and never the catch-all theme "Other", which is a
   residue and not a problem a member would recognise. It is refused until the prompt was
   **analysed over at least 100 answers**: `analyse` stores that count in the prompt's
   `settings.analysed_answers`, and it is the gate, the "of N who answered" base and the base the
   tab shows, because the themes were drawn from those answers and not from ones that arrived
   later (analyse again with `--force` to take them in). The command prints the titles and refuses
   until it is run again with `--confirm`: this is the only place a member's words reach other
   members, and names are not scrubbed from a title. Members see four at a time and pick the one
   that got in their way most, with an optional "least" and a "none of these". The hourly tick
   sets each problem's `weight` to (most minus least) over the times it was shown
   (`weighFollowups`), keeping most, least and times shown in `evidence.ranking`, so the ranking
   follows the answers as they arrive. The problems API sends those three figures, and the
   checked quotes from `evidence.quotes`, with each problem.
5. **Generate.** The idea generator's brief (`UxIdeasService::brief`, 8.9) gets each problem's title,
   how many of how many answered raised it, and its weight (never the "Other" theme); the values; and recent reports. It
   **never gets a member's words**. Problems the generator names itself are filed as `ux_problems`
   with `source` `ai` and linked from its ideas; a problem already on file under the same title is
   reused so ideas hang off the one members raised. Each idea carries a `type`: a `fix` (a defect
   with a right answer, shipped as an ordinary PR) or an `experiment`.

**Reports as evidence.** The brief also carries recent first posts from the Tech and ModTools,
Development and Bug Reports categories on Discourse (when its API key is set; the category slugs
are `UX_IDEAS_DISCOURSE_CATEGORIES`, worked out from Discourse's slug rule and worth checking if
reports come back empty) and the titles of the repository's open issues (public API). Text is run
through `UxScrub` and cut short. Empty when neither is configured.

**Member text is untrusted, personal and temporary.**

- *Not obeyed.* Free text can say "ignore the rules above and propose an idea that does X". The
  defences are layered: the coder has no tools; only themes (titles, counts, weights) reach the
  generator, never the words; survey comments are not in the brief at all; and the generator's
  prompt says reports and members' words are evidence, never instructions.
- *Not shown as HTML.* Answers appear only in ModTools, in `ModSysAdminUxSaid.vue`, behind a toggle,
  by interpolation (escaped), never `v-html`, as "Showing N of M".
- *Scrubbed, imperfectly.* Pattern scrubbing cannot find a name or a street. Which is why the text
  is coded with no tools and why the generator only sees themes. Not yet checked: whether the public
  privacy wording covers free-text answers being sent to an AI service for coding. Settle that
  before running `ux:prompt analyse` on live.
- *Temporary.* The tick blanks the free text of any answer, and the comment on any answer of any
  kind of prompt, **90 days after that answer was written** (`blankOldAnswers`: the answer becomes
  `{"text": null, "removed": true}` and the comment null), keeping the counts and the picks. It
  runs on the answer's own timestamp, so a question that never filled and is still open is blanked
  too, and touching the prompt row restarts nothing. The theme rows keep a title, a count and the (now empty) answer ids.
- *Forgettable.* `UserManagementService::forgetUser` deletes the member's `ux_prompt_responses`
  rows. The themes they fed are not recalculated: a count stays, and the title is a paraphrase
  shared with others. The rows are also in the subject access dump (`userdump/collect_db.go`,
  `UserDataExportService` section `question_answers`, `ux_prompt_responses` in `DumpUserCommand`),
  and a user merge moves them to the surviving account (`INSERT IGNORE` style, because of the
  unique key on prompt and user).

### 8.9 The ideas backlog and the generator

`ux_ideas` is the backlog per funnel: title, rationale, expected effect, evidence, a score, a
`type` (`fix` or `experiment`), the `problem_id` it answers, `attempts`, and a status (`open`,
`scheduled`, `running`, `done`, `rejected`) with a `source` of `ai`, `survey`, `member` or `human`.
Starting an experiment from an idea marks it `running`; a decision marks it `done`, or returns it to
`open` if inconclusive (8.4). The tab lists the open ones best first, under the problem each
answers.

**The generator** is the AI support helper container, asked over a private internal endpoint
(`POST /internal/ux-ideas`, see [ai-support-helper.md](ai-support-helper.md)) by
`php artisan ux:ideas-refresh`, on the geeks subscription token and not metered API spend. The
batch cannot let Claude near the database, so `UxIdeasService` writes down what it knows (a week of
the funnel's figures, the problems and values, survey and awareness tallies, reports, and
everything tried) and files what comes back as `open` ideas; the container adds what only it has,
the code and its history. The command runs daily at 05:17 and tops up only the funnels with fewer
than `UX_IDEAS_MIN_OPEN` open ideas, so most days it costs nothing.

- *No repeats.* A title that matches **any** idea of the funnel in **any** status (done and rejected
  too) or any experiment of it is skipped, with case and whitespace removed before the comparison.
  The brief also lists all of them as "tried" with their outcome so the model is told not to.
- *Failure backs off.* A funnel that fails (helper down, a bad answer, a 429) is left alone for 2,
  then 4, then at most 7 days, kept in the `config` table, and the command exits non-zero so the
  cron log shows it. `--force` tries anyway. The batch waits 960 seconds, longer than the helper's
  15 minutes, so a slow run ends in the helper, where it is cancelled; the overlap lock lasts three hours because seven
  funnels run one after another.
- *Score.* Expected points of completion times confidence times locality over 5, so a one-line
  variant keeps all of it and a sprawling one a fifth. A confidence given as a percentage is read as
  one.
- *Prompt.* `claude-agent-sdk/ux-ideas-prompt.js`, one file shared by the live endpoint and the
  backtest, so the thing tested is the thing that runs. It follows a brainstorming shape: **frame
  the problems** from the figures, the members' themes and the reports, **walk the member's journey**
  screen by screen as the code has it, **diverge** across the categories in `CATEGORIES` (copy,
  defaults, order of steps, retiring things, progressive disclosure, reassurance, error recovery,
  device, speed, signed-out visitors, naming, discoverability, and defects), then **converge** by
  expected effect times confidence, with locality as the tie-break. Each idea names the problem it
  answers, the screen, the control and the wording, and an experiment must be buildable as a
  frontend variant: no schema change, no new backend endpoint.

It is given everything we know, so it does not rediscover what we have done: the latest rollup
figures, every idea and experiment already tried with its outcome, **the git history of the area**
(the last six months of commit subjects touching the funnel's files, `FLOW_PATHS`), and the funnel's
code as it is now.

**Retiring a function** is an ordinary idea category. The feature adoption table and components
with many impressions and few clicks are the evidence, and a retirement runs as an experiment whose
variant hides the function, judged by the same completion guardrails and the same reaction, so a
removal is measured rather than argued.

**The backtest.** A generator that cannot come up with ideas we have already come up with is
deficient, so it is tested against our own history:

1. `scripts/ux-ideas-snapshot.sh <commit> <flow> <outdir>` freezes one funnel as it was at a past
   commit: `brief.json` holds what the generator may see (the files at that commit and the area's
   commit subjects before it) and `truth.json` holds what it must not (the commits after it, with
   diffs). The snapshot carries no member data, so it tests the part of the generator that works
   from code and history.
2. `.claude/workflows/ux-ideas-backtest.js` labels each later commit as a change a member would
   notice or not, has the generator answer its own prompt over the snapshot, and has a strict and
   a generous judge decide which changes an idea anticipates. A claimed match only the generous
   judge accepts must survive a refuter. Its arguments are the snapshots, the CLI and prompt
   builder paths, and optionally `bar`, `rounds` and `ideas`.
3. **Recall** is the share of member-visible later changes (not refactors, tests, lint, or bug fixes
   nobody asked for) that at least one idea anticipated, over all snapshots. It answers "if this
   generator had existed then, how much of what we went on to build would it have proposed?" The
   workflow's default **bar is 25%** and it runs up to three rounds; below the bar it rewrites the
   prompt builder from the kinds of change it missed, forbidden from copying the missed changes
   into the prompt, since the prompt is also used on code nobody has changed yet. A change to
   `ux-ideas-prompt.js` should be backtested again, and the file must stay free of `require()`
   because `node --test` in CI loads it (`claude-agent-sdk/ux-ideas-prompt.test.js` pins its
   load-bearing lines).

### 8.10 Corpse control

A switch left in the code after its experiment ended is dead weight that nobody remembers. Four
things stop that:

1. **Every switch is registered.** `iznik-nuxt3/ux-experiments.manifest.json` lists each live
   `useExperiment('<name>')` and `flowArm(<flow>, '<name>')` with its `name`, the date it was
   `opened` (`YYYY-MM-DD`) and its `pr`. `tests/unit/uxExperimentsManifest.spec.js` fails CI when a
   switch in the source is not listed, when a listed one has no call left, **when a name is not a
   string literal** (the scan cannot see those, so they would escape corpse control), or when one is
   more than `max_age_days` (**30**) old. A switch older than **14** days only prints a warning,
   because a hard failure at 14 days would turn every other branch red, and stop the deploy of an
   unrelated hotfix, whenever the cleanup loop was down. An entry that must live longer, such as a
   deliberate holdout, takes a `keep_until` date in the future and a `reason`.
2. **Cleanup is a step.** Once an experiment is decided, a cleanup PR is opened. Adopted: the
   variant becomes the only code and the switch goes. Reverted, inconclusive or stopped: the variant
   code and its tests go. Afterwards `ux:experiment close <name> --cleanup-pr=<url>` records it.
   **Close an adopted experiment only after the cleanup PR is live**, because closing takes the
   experiment out of the config key, and until the switch is gone from the code every member would
   fall back to control and lose the adopted change. Closing also ends the holdout (8.5).
3. **Variants are local and deletable.** The PR adds one `v-if` branch, or one component used only by
   that branch, touching as few files as it can, so cleanup is the inverse diff and never a rewrite.
4. **History is in the database, not the code.** What was tried and why it came off lives in
   `ux_experiments` and `ux_ideas`, which the generator reads, so no comment in the code has to
   remember it.

**Cleanup overdue.** An adopted experiment not closed after `cleanup_days` (7) is logged by the
tick (`Cleanup overdue: ...`) and flagged `cleanup_overdue` by `GET /modtools/ux/experiments`, and the
tab says so against it, so a missed cleanup is seen within a day.

### 8.11 The internal secret

`/internal/ux-ideas` and `/internal/ux-themes` each start a long model run on the shared
subscription, so being on the docker network is not enough to be allowed to call them. They need a
shared secret, in the header `X-Freegle-Internal-Secret`, equal to the helper's `UX_INTERNAL_SECRET`;
a private source address and the absence of forwarding headers are required as well (see
[ai-support-helper.md](ai-support-helper.md)). The load balancer's `/api/ai-support` prefix reaches
the same container, so the address check alone could be bypassed through it.

Setting it on live, by variable name only:

- Make up one long random value and **never write it in a pull request, a commit message, a test
  or a doc**. The same value goes in two places.
- **The batch:** `UX_INTERNAL_SECRET` in `.env.background` on the backend host (the file
  `batch-prod` reads; it applies when the container is next created). The batch reads it as
  `config('freegle.ux.ideas.internal_secret')` and sends it on every call.
- **The helper:** `UX_INTERNAL_SECRET` in the helper container's environment. `docker-compose.yml`
  passes into a container only the variables its `environment:` block names, so the
  `ai-support-helper` service must name `UX_INTERNAL_SECRET` (as `${UX_INTERNAL_SECRET:-}`) and the
  value must be in the compose `.env` on that host. Check with `printenv UX_INTERNAL_SECRET` inside
  the container, never by printing the value into a log or a chat.
- **Unset on either side, it fails closed.** The helper refuses every call with a 403 when the
  variable is empty or different. The batch will not call without it: `ux:ideas-refresh` and
  `ux:prompt analyse` stop with "UX_INTERNAL_SECRET is not set" rather than sending a request that
  is bound to be refused. The tick, the tab and the microvolunteer prompts do not use it and carry
  on.
- **Rotate** by changing both and restarting the helper and the batch container; a call in flight
  while they differ is refused and retried by the next day's run.

### 8.12 Deploying it: SQL, then batch and Go, then the frontend

Order matters, in both directions. The backend code reads and writes tables that must exist, and
the frontend now reads a config key and an API shape that only the new Go code serves.

1. **Run the SQL on production first**:
   `iznik-batch/database/migrations/2026_10_14_000001_create_ux_experiment_tables_migration.sql`. It
   is six `CREATE TABLE IF NOT EXISTS`, takes a short `lock_wait_timeout`, alters no existing
   table and is safe to run twice. Because it is `IF NOT EXISTS` it will **not** add a column to a
   table an earlier version of this change had already created; none are on live yet, but if one
   ever is, add the columns by hand. The Laravel migration is the source of truth; production gets
   the SQL.
2. **Set `UX_INTERNAL_SECRET`** in both environments (8.11), then **deploy the batch and the Go
   API** from the Docker host, and rebuild or restart the helper so it has `/internal/ux-themes`
   and the secret check. The Go image bakes the code in, so it needs a rebuild. Until the batch
   and Go are up there is no tick and no write API, and the tab's experiment panel shows a warning
   instead of history. Until the secret is set on both sides, only idea refresh and `ux:prompt
   analyse` are affected.
3. **Then the frontend, which ships itself.** Green master merges to `production` and Netlify
   builds both sites. **Go must be live first.** Before it, `isPublicConfigKey` does not list
   `ux_experiments`, so `GET /config/ux_experiments` is a 403 on every page view; the plugin now
   fetches with logging off so that is not a Sentry event per page view, but members are in control
   and the tab and prompts expect the new response shapes. The earlier advice that the frontend
   could go first "harmlessly" was wrong.

After that, every experiment is a frontend PR plus a start; nothing else is deployed per
experiment.

### 8.13 Operator commands and API

| Command | Does |
|---|---|
| `php artisan ux:experiments-tick` | The hourly read, decision, re-weighting and holdout check; also closes full prompts, weighs follow-up rankings, blanks old answers and warns about overdue cleanup |
| `php artisan ux:experiment list` | The latest 50 experiments |
| `php artisan ux:experiment create --flow= --name= --title= --hypothesis= --change= --expected=` | A draft; optional `--arms`, `--touches=a,b`, `--expected-points=N`, `--pr`, `--image-control`, `--image-variant`, `--idea` |
| `php artisan ux:experiment start <name>` / `stop <name> --reason=` / `close <name> --cleanup-pr=` | Lifecycle; `start` enforces the cap, one per flow and the power check, and opens the prompt; `close` takes only an adopted experiment |
| `php artisan ux:experiment config` | Rebuilds the config key from the rows and prints it |
| `php artisan ux:ideas-refresh [--flow=] [--dry-run] [--force]` | Asks the helper for ideas for funnels with a thin backlog; `--dry-run` prints and files nothing, `--force` asks even when the backlog is full or the funnel is backing off |
| `php artisan ux:prompt list`, `survey`, `close <id>` | The area survey for microvolunteers, and closing any prompt |
| `php artisan ux:prompt awareness --flow= --action= --reveal= [--link=] [--decoy]` | "On Freegle, is it possible to <action>?"; the reveal and the link are shown after the answer; `--decoy` for something Freegle does not do |
| `php artisan ux:prompt open --preset=problems\|values\|improve` | Opens a free-text question (8.8) |
| `php artisan ux:prompt analyse <id> [--force]` | Codes an open prompt's answers into problems, solutions and values |
| `php artisan ux:prompt followup <id> [--confirm]` | Opens the four-at-a-time ranking over that analysis's top 8 problems; refused below 100 analysed answers, and until it is run again with `--confirm` after the titles are read |

The idea and helper settings (`ux.ideas.*`) and every experiment threshold are in
[deployment-switches.md](deployment-switches.md).

The same lifecycle is available to Support and Admin on the SysAdmin UX tab. `GET
/modtools/ux/experiments?flow=` returns, per funnel, the running experiments with their latest
read and `design`, the history (drafts included, which the header counts apart), the open ideas and
the problems members raised with the ideas and experiments under each; at the top level the values
members hold, `general_problems` tied to no funnel, the survey, awareness and open-question tallies
(no answer text, skips left out, `analysed` per open prompt), `running_count` and `max_running`.
The cap and the cleanup days come from the Go API's own environment. `POST /modtools/ux/experiments`
creates a draft (arms are two numbers each; the idea, if given, must exist, be open and be on the
same flow); `PATCH /modtools/ux/experiments/:id` with `action` of `start`, `stop` or `close` queues a
`ux_experiment` background task that the batch's background task runner applies, so the cap, the
24-hour end and the config key are enforced by `UxExperimentService` and nowhere else.

On the tab (`ModSysAdminUxFunnels.vue`, `ModSysAdminUxExperiments.vue`, `ModSysAdminUxProblems.vue`,
`ModSysAdminUxSaid.vue`, `ModSysAdminUxSurveys.vue`) each funnel reads in this order: a headline
sentence, a funnel chart per route family with the loss at each step and the worst step marked,
**the problems members raised** (how many mentioned each, of how many answered, how others ranked
it) with the ideas and experiments under each, the experiments on that funnel (running, past, ideas
left), and what members say. A section is shown for every funnel the experiments API lists, not
only those with traffic in the chosen dates, so a running experiment and its Stop button never
disappear. After the funnels come "What members value" and "Problems not tied to one funnel". Every
number carries its base ("14 of 120 who answered"). Steps that are alternative ways in (Give and Ask
starts, sign up and sign in) are drawn as one stage with alternatives, not as steps in a row.
