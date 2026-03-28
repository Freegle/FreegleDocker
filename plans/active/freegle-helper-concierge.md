# Freegle Helper: Automated Reply Concierge

**Status**: Design
**Created**: 2026-03-27

## The Problem

When someone bulk-posts many items (e.g. Mind Brighton's 47 office furniture offers), they may receive dozens or hundreds of replies across those items. Managing this manually — checking who can collect when, whether they have transport, whether they match the criteria (e.g. charities only), who wants which items, how many — is overwhelming. The offerer needs a concierge that handles the back-and-forth and presents them with simple decisions: "This person can come Tuesday at 2pm and take 6 chairs and 2 desks. Accept?"

## The Name

**Freegle Helper** — friendly, descriptive, fits the brand. Messages sent by it are clearly marked as automated. The bot user could be called "Freegle Helper" with a distinctive bot avatar.

## How It Works

Freegle Helper is Claude operating as a loop-based agent, monitoring the offerer's chat inbox and managing conversations on their behalf. It sends messages through Freegle's existing chat system, flagged as coming from the Helper bot. The offerer can override or intervene at any time.

The decision-making is done by Claude, not by rigid code. Claude reads each reply, understands context, applies the criteria, and composes natural conversational messages. The "algorithm" below is guidance for Claude's judgement, not a state machine.

## Architecture

```
┌─────────────┐     ┌──────────────┐     ┌─────────────┐
│  Freegle     │────>│  Claude Loop │────>│  Freegle    │
│  Chat API    │<────│  (Agent)     │<────│  Chat API   │
│  (read msgs) │     │              │     │  (send msgs)│
└─────────────┘     │  Reads inbox │     └─────────────┘
                    │  Assesses    │
                    │  Composes    │     ┌─────────────┐
                    │  Decides     │────>│  Offerer    │
                    │              │<────│  Dashboard  │
                    └──────────────┘     └─────────────┘
```

**Execution model**: A `/loop` command runs at intervals (e.g. every 5-10 minutes), authenticating as the offerer via the Go API. Each iteration:
1. Fetches new/unread chat messages across all the bulk-posted items
2. Assesses each conversation's state
3. Sends appropriate follow-up messages via the chat API
4. When a conversation reaches "ready to present" state, notifies the offerer

**Message identity**: Messages are sent as the offerer. The Helper is invisible to the replier — they think they're talking to the person who posted the items. This means the tone must be natural and human, not robotic or formulaic. No "I'm a bot" disclosure.

## Batch Configuration

When the Helper starts managing a batch of posts, it needs a briefing — extracted from the post body and listing metadata, not hardcoded:

- **Collection constraints**: dates, times, location (from post body + `message.deadline`)
- **Recipient criteria**: who should get the items, if anyone specific (from post body — e.g. "charities only", "families", or none)
- **Items list**: what's available, quantities, sizes (from the CSV / listing data)
- **Offerer preferences**: any stated priorities (from post body — e.g. "prefer someone who can take multiple items")

The Helper reads the post body once and extracts these as its operating rules. Everything below uses these generically — "collection constraints" not "Tue 7th April", "recipient criteria" not "charities".

## Conversation Stages

### Stage 1: Acknowledge & Gather

When a new reply arrives, the Helper sends ONE message that does as much as possible in a single round-trip:

**What it always does:**
- Thanks them for their interest
- States the collection constraints (if they haven't already matched them)
- Asks about anything still unknown that matters for allocation

**What it checks from their message before composing:**
- Did they already state when they can collect? → Don't ask again
- Did they already satisfy the recipient criteria? → Don't ask again
- Did they mention transport? → Don't ask again
- Did they ask a question? → Answer it if possible from listing data
- Did they reply to multiple items? → Address all of them in one message
- Is their message a mismatch (asking about something we're not offering)? → Redirect to actual items

**What it never does:**
- Make any commitment or promise
- Ask questions out of curiosity that don't affect allocation
- Ask about things they've already told us

Every message ends with a no-commitment phrase: "We're still collecting interest and will confirm allocations shortly."

**The aim is to get to QUALIFIED in as few messages as possible.** If their first message answers everything, skip straight there. If it answers nothing, ask everything in one go. Don't drip-feed questions across multiple round-trips.

### Stage 2: Scoring & Waiting

The Helper doesn't immediately allocate. It responds immediately (Phase A) but waits before deciding (Phase B).

**Phase A (immediate):** Respond to every message — answer questions, acknowledge, gather info. No commitment.

**Phase B (after waiting period, ~24 hours):** Review all candidates, score, present to offerer.

The waiting period is configurable per batch (default ~24 hours, or deadline minus collection lead time, whichever is sooner). This ensures fair access — someone who replies at hour 20 gets the same consideration as someone who replied at hour 1.

### Stage 3: Scoring & Waiting

The Helper doesn't immediately allocate. It waits and scores.

**Two-phase timing:**

The Helper responds to messages immediately but doesn't allocate until the waiting period is up. This is critical — repliers expect a response within hours, but allocation should wait for the full candidate pool.

- **Phase A: Immediate response (within minutes)**: Answer questions, confirm dates, gather missing info. No commitment. Every response includes "we're still collecting interest and will confirm allocations shortly."
- **Phase B: Allocation (after ~24 hours)**: Review all candidates, score, present recommendations to offerer. Once approved, send confirmations to winners and polite rejections to others.

This means the Helper is always responsive (no one is left waiting like rebeccaryan63 was) but never commits too early (the community farm that replied at hour 16 still gets fair consideration).

**Scoring factors** (Claude weighs these with judgement, not a rigid formula):

| Factor | Signal | Weight |
|--------|--------|--------|
| Criteria match | Matches whatever the post specified (if anything) | High (if criteria exist) |
| Quantity appetite | Taking more items = fewer collection slots needed | Medium |
| Transport confirmed | Mentioned suitable transport for the items | Medium |
| Availability flexibility | Flexible on collection times vs narrow window | Medium |
| Responsiveness | Time between our message and their reply | Medium |
| Reputation | Thumbs up/down ratio, completion history, reneged count | Medium |
| Multi-item interest | Wants items across multiple posts = one trip | High |
| Self-awareness | "Only if no one else wants it" = honest, lower priority but good fallback | Low |
| Reply quality | Specific vs vague, polite vs demanding, reads the post vs generic | Low |

**What Claude considers:**
- If criteria exist, someone who matches them wanting many items is generally better than multiple people wanting one each (fewer collection slots, better criteria match)
- But spreading items across multiple worthy recipients may be preferred — the offerer decides
- Someone with poor reputation (many thumbs down, reneged promises) should be ranked lower
- Someone whose message is clearly a template/mismatch (asking for something we're not offering) ranks lowest
- Someone who hasn't replied to the availability check within 24 hours should be deprioritised

### Stage 4: Presentation to Offerer

When the waiting period ends (or when Claude judges there are enough strong candidates):

**What the offerer sees** (via a summary message in their chat, or a structured notification):

```
Ready for your decision — Blue Fabric Chairs (14 available):

1. Brighton Community Centre (charity) — wants 8 chairs
   Can collect: Tue 7th, has van, replied in 1hr, 12 thumbs up

2. St Luke's Primary School — wants 6 chairs
   Can collect: Tue or Wed, has van, replied in 3hrs, new user

3. Jane (individual) — wants 2 chairs
   Can collect: Wed only, has car, replied in 6hrs, 5 thumbs up

Recommendation: Allocate 8 to Brighton CC and 6 to St Luke's.
Reply "yes" to confirm, or tell me how you'd like to allocate.
```

**The offerer's options:**
- Accept the recommendation
- Modify allocation (e.g. "give 4 to each")
- Ask for more info about a candidate
- Override with their own choice

### Stage 5: Confirmation & Logistics

Once the offerer decides:

**What the Helper does for successful candidates:**
- Sends a message confirming they've been allocated the items
- Confirms collection date/time
- Shares collection address (via the Address chat message type)
- Asks them to confirm they'll be there

**What the Helper does for unsuccessful candidates:**
- Sends a polite message: "Thanks for your interest. Unfortunately these items have been allocated to other organisations. Keep an eye on Freegle for more items!"
- This mirrors the existing "completion message" pattern in OutcomeModal

### Stage 6: Collection Day Follow-up

On collection day (or day before):

- Sends a reminder to confirmed collectors: "Just a reminder — you're collecting 8 blue fabric chairs tomorrow (Tuesday 7th) between 10am-4pm from [address]."
- If someone hasn't confirmed: flags to the offerer and suggests moving to the next candidate
- After collection: prompts the offerer to mark items as Taken with the appropriate user via the outcomes API

## Data the Helper Needs

All available via existing APIs:

| Data | Source |
|------|--------|
| Replies to offers | `GET /message/{id}` → replies array, or chat messages with `refmsgid` |
| Chat messages | `GET /chat/{id}/message` |
| User reputation | `GET /user/{id}/info` → ratings, replytime, reneged, collected |
| Item availability | `message.availablenow` / `message.availableinitially` |
| Collection criteria | The body.txt content (parsed by Claude from the post text) |
| Deadline | `message.deadline` |

## What Needs Building

### Phase 1: Bot User & Flagged Messages (Required)
- Create a "Freegle Helper" system user account
- Add a `botuser` field to `chat_messages` — when set, the frontend renders the message with a bot badge/avatar instead of the sending user's identity
- The Helper authenticates as the offerer but sets `botuser` on each message it sends
- Frontend: `ChatMessageText.vue` shows a "Freegle Helper" badge when `botuser` is set

### Phase 2: Claude Loop Agent
- A skill or loop command that:
  - Authenticates to the API as the offerer
  - Fetches all chats related to the bulk-posted message IDs
  - Reads new messages since last check
  - Applies the stage logic above
  - Sends follow-up messages via `POST /chat/{id}/message`
  - Maintains conversation state (which stage each replier is at)
- State stored in a simple JSON file or in memory across loop iterations

### Phase 3: Offerer Dashboard
- A view (could be a simple chat summary or a dedicated page) showing:
  - All items and their allocation status
  - Candidate rankings per item
  - Accept/modify/override controls
- This could initially just be messages in the offerer's own chat, formatted as structured summaries

## Conversation State Tracking

Each replier has a state (tracked per person, not per item, since one person may want multiple items):

```
NEW              → Reply received, not yet acknowledged
GATHERING        → We've replied, waiting for more info from them
QUALIFIED        → All info gathered, in scoring pool for human decision
ALLOCATED        → Human approved allocation, replier not yet told
CONFIRMED        → Replier told and confirmed collection
COLLECTED        → Item collected, outcome recorded
PARKED_REPLIED   → Can't meet requirements, told them, kept as fallback
PARKED_QUIET     → Not prioritised, no reply sent, still in pool
ESCALATED        → Needs human input (photo request, subjective question, etc)
TIMED_OUT        → Didn't respond within threshold
REJECTED         → Items allocated to others, polite rejection sent
```

### State Transitions

Each incoming message is processed against the replier's current state and a checklist of what we still need to know. The Helper maintains a **knowledge record** per replier:

```
replier: {
  name, userid, chatid,
  items_wanted: [{msgid, item_name, qty_wanted}],
  collection_ok: true/false/unknown,
  criteria_met: true/false/unknown/not_applicable,
  transport_ok: true/false/unknown,
  distance_miles: <calculated from API lat/lng via haversine, never from LLM>,
  other_items_mentioned: true/false,
  escalation_reason: null or string,
  state: <one of above>,
  next_action: <locked in until trigger fires>,
  parked_reason: null or string,
}
```

**Transition rules:**

| From | Trigger | To | Action |
|------|---------|----|--------|
| NEW | Helper sends first message | GATHERING | Ask whatever is still unknown in one message |
| NEW | First message answers everything | QUALIFIED | Acknowledge, no commitment |
| GATHERING | Reply answers remaining questions | QUALIFIED | Acknowledge, no commitment |
| GATHERING | Reply answers some questions | GATHERING | Acknowledge what they said, ask what's still missing |
| GATHERING | Reply asks question we can answer | GATHERING | Answer from listing data, continue gathering |
| GATHERING | Reply asks question we can't answer | ESCALATED | Flag to human, tell replier we'll check |
| GATHERING | Reply reveals they can't meet constraints | PARKED_REPLIED | Soft decline, keep as fallback |
| GATHERING | No reply within timeout | TIMED_OUT | No message sent |
| PARKED_REPLIED | Primary candidate falls through | GATHERING | Re-engage: "Are you still interested?" |
| PARKED_QUIET | Primary candidate falls through | GATHERING | First contact: treat as NEW |
| ESCALATED | Human provides answer | Re-check | Pass answer to replier, then re-check knowledge gaps. If all answered → QUALIFIED. If gaps remain → GATHERING |
| ESCALATED | Replier sends new info while waiting | ESCALATED | Update knowledge record but stay escalated — human question still pending |
| QUALIFIED | Human approves allocation | ALLOCATED | — |
| ALLOCATED | Helper sends confirmation | CONFIRMED | "Great news, you've been allocated..." |
| CONFIRMED | Collection happens | COLLECTED | Mark outcome via API |
| QUALIFIED | Human allocates to someone else | REJECTED | "Sorry, these have been allocated..." |
| ANY | Replier sends new message | Re-evaluate | Check if message changes anything |

**What the Helper checks on every incoming message to decide what to ask:**

1. Do we know which items they want and how many? (from `refmsgid` + their message)
2. Have we mentioned that other items are available too? (if they only replied to one item in a bulk batch, they may have missed the others — ask once)
3. Do we know they can meet the collection constraints? (from their stated times vs batch config)
4. If criteria exist, do we know they meet them? (from their message content)
5. For large/heavy items, do we know about transport? (from their message)
6. Have they asked a question we need to answer?

If all of 1-5 are answered and 6 is clear, they're QUALIFIED. Otherwise ask about the gaps — all in one message, not drip-fed.

Check 2 matters when the offerer has multiple items available. A replier who found one listing may not have seen the others. The Helper should mention the other items once — but not repeat if they don't bite. Add `other_items_mentioned: true/false` to the knowledge record.

### No LLM Geography

**CRITICAL: Never use LLM knowledge to interpret place names or estimate distances.** Place names are ambiguous — "Chesterfield" could be a city 200 miles away or a street in the next postcode. The Helper must:

- Use the replier's `lat`/`lng` from `GET /user/{id}` and the item's `lat`/`lng` from `GET /message/{id}` to calculate distance. Simple haversine — no database access needed.
- Never assume a place name mentioned in chat refers to any particular location
- Never tell a replier their location is too far based on a name — only based on their API-provided coordinates
- The Helper operates only through API calls as the offerer. No direct database access. All data comes from what the offerer can see via the API.

### Non-Responsive Handling

Responsiveness is both a filter and a scoring signal.

**Timeline for any state where we're waiting for a reply (GATHERING, ESCALATED):**

1. **T+0**: We send our message.
2. **T+24h**: No reply. Send a polite follow-up + nudge (via the API's nudge feature). Friendly tone: "Just checking you got my message — still interested?"
3. **T+48h (24h after nudge)**: Still no reply. Mark as TIMED_OUT. No further messages. They're excluded from allocation unless all other candidates for that item have also fallen through.

**Scoring impact:**
- Someone who replies within a few hours scores higher than someone who takes 20 hours, even if both are within the 24h window.
- A replier who needed a nudge before responding is viable but ranks below someone who replied promptly.
- The `users_replytime` data from their history reinforces this — a user with a historically slow reply time is more likely to be a no-show on collection day.

**After allocation:**
The same timeline applies to ALLOCATED → CONFIRMED. If we tell someone they've got the items and they don't confirm within 24h, nudge. If still nothing after 48h, revoke the allocation and offer to the next candidate.

**Transition additions:**

| From | Trigger | To | Action |
|------|---------|----|--------|
| GATHERING | 24h no reply | GATHERING | Send nudge: "Just checking — still interested?" |
| GATHERING | 48h no reply (24h after nudge) | TIMED_OUT | Exclude from allocation |
| ALLOCATED | 24h no confirmation | ALLOCATED | Send nudge: "Can you confirm you'll be collecting?" |
| ALLOCATED | 48h no confirmation | TIMED_OUT | Revoke, offer to next candidate |
| TIMED_OUT | All other candidates exhausted | GATHERING | Re-engage: "Are you still interested in [item]?" |
| TIMED_OUT | Replier sends new message, item not yet allocated | GATHERING | Resume: treat their message normally, re-check gaps |
| TIMED_OUT | Replier sends new message, item already allocated | REJECTED | "Sorry, these have been allocated" |
| REJECTED | Replier sends new message, item has become available again (revoked/no-show) | GATHERING | "Actually these are available again — still interested?" |

**Late replies principle:** A timed-out replier who comes back to life is always welcome if the item is still available — they just lost their priority position. They go back into the pool but score lower than someone who was responsive throughout. If the item has already been allocated and collected, they get a polite rejection. If allocated but not yet collected, they go onto the waitlist in case the allocation falls through.

**Lock-in principle:** When a replier's state changes, decide the next action once and record it. Don't re-evaluate on every monitoring cycle. Only reconsider when: the replier sends a new message, a competing replier changes the picture for the same item, a timeout threshold is hit, or the offerer intervenes.

## Example Full Flow

```
Day 1, 09:00 — 47 items bulk-posted with 2026-04-03 deadline

Day 1, 10:30 — Bob replies to "Blue Chairs x14": "Hi, I run a community centre"
  Helper: "Thanks Bob! Collection is Tue 7th or Wed 8th April, 10am-4pm.
           Would either day work? And how many chairs would you need?"

Day 1, 11:00 — Bob: "Tuesday works, we'd love all 14 if possible. I have a van."
  Helper: (marks Bob as QUALIFIED — criteria match: community centre,
           wants all 14, has transport, available Tue, fast reply)

Day 1, 14:00 — Alice replies to "Blue Chairs x14": "These for our school?"
  Helper: "Hi Alice! Yes, these chairs are available for local schools and
           charities. Collection is Tue 7th or Wed 8th, 10am-4pm.
           How many would your school need, and would you have transport?"

Day 1, 16:00 — Alice: "We'd need 6. The caretaker has a van. Wed works best."
  Helper: (marks Alice as QUALIFIED — school, wants 6, has van, Wed)

Day 2, 09:00 — Carol replies to "Blue Chairs x14": "interested"
  Helper: "Hi Carol! Collection is Tue 7th or Wed 8th, 10am-4pm.
           Would either day work? These are being offered to local charities
           and community organisations — could you tell me a bit about
           what the chairs would be used for?"

Day 3 (deadline) — Carol hasn't replied. Helper marks TIMED_OUT.

Day 3, 12:00 — Helper presents to offerer:
  "Blue Chairs (14 available):
   1. Bob (community centre) — wants 14, Tue, van, replied 30min ★
   2. Alice (school) — wants 6, Wed, van, replied 2hrs
   Recommendation: 8 to Bob + 6 to Alice (covers all 14, both verified orgs)
   Or: all 14 to Bob if you prefer single collection."

Offerer: "8 and 6 sounds good"

Helper to Bob: "Great news! 8 blue chairs have been allocated to you.
               Collection: Tuesday 7th April, 10am-4pm at [address].
               Can you confirm you'll be there?"

Helper to Alice: "Great news! 6 blue chairs have been allocated to you.
                  Collection: Wednesday 8th April, 10am-4pm at [address].
                  Can you confirm you'll be there?"

Helper to Carol: "Thanks for your interest in the blue chairs. These have
                  now been allocated. Keep an eye on Freegle for more items!"
```

## Tone Guidelines for the Helper

- Warm and friendly, not robotic — "Thanks for your interest!" not "Your request has been logged."
- Brief — people on Freegle send short messages, the Helper should too
- Sounds like a real person — the replier thinks they're talking to the offerer
- Never makes promises it can't keep — always "items are subject to availability"
- Matches the casual tone of Freegle conversations
- Uses the replier's name when known

## Real-World Insights

### How experienced offerers choose
Weight politeness, friendliness, and track record over first-come-first-served. Collection times in initial replies are often aspirational — don't take them literally.

### "First come first served" is an anti-pattern
Giving out addresses and inviting people to just turn up causes arguments, no-shows, and safety issues. The Helper approach solves this: structured allocation, no address until confirmed.

### Many first replies are questions, not commitments
Freegle requires a collection time even for questions like "Will it fit in a car?" The Helper should recognise questions and answer them from listing data before proceeding to qualification.

### Reply structure
Freegle replies include: user's message text, a stated collection time (mandatory, often meaningless), and a `refmsgid` linking to the specific offer. The Helper uses `refmsgid` to know which item is being discussed.

### Connector/broker pattern
Some repliers aren't the end recipient — they forward listings to charities/orgs on behalf of someone else. The Helper should:
- Recognise connector language ("sent to", "forwarded to", "on behalf of")
- Score highly if the named org matches criteria, but track that the actual collector may be different
- Don't timeout a connector too quickly — they're waiting on someone else to respond

### Conversation Failure Modes

From codebase analysis of nudge/renege/timeout patterns:
- **Ghost repliers**: Express interest then never respond. The `replyexpected`/`replyreceived` flags and `users_nudges` table show this is common enough to have dedicated infrastructure.
- **Promise breakers**: The `messages_reneged` table tracks reliability. Users with high renege counts should be ranked lower.
- **Multi-item cherry pickers**: Someone replies to 20 items but only wants the best one. The Helper should ask upfront: "I see you're interested in several items. Which ones are you most keen on?"

## Open Questions

1. **Bot identity**: Should Helper join chats as a third participant, or send messages "as" the offerer with a bot badge? Third participant is cleaner but changes chat room dynamics.
2. **Opt-in**: Should the Helper be opt-in per batch? Or always available? Probably opt-in initially — activated when someone runs the bulk post command.
3. **Manual override**: If the offerer sends a message directly in a chat the Helper is managing, should the Helper step back from that conversation?
4. **Multiple batches**: If the same offerer has multiple active batches, the Helper needs to keep context separate.
5. **Edge cases**: What if someone replies to 20 different items? Consolidate into one conversation about all of them, or handle per-item?
6. **Answerable questions**: If someone asks "Can these chairs be stacked?" and the item description says "stackable", should the Helper answer directly? Probably yes for factual questions from the listing, but flag subjective ones to the offerer.

## Live Monitoring Log

### Trial context
47 items bulk-posted for a Brighton charity clearance. Criteria: charities/community orgs preferred. Collection: Tue 7 / Wed 8 April. Helper operated manually (human-supervised AI), so responses were delayed hours — in production would be minutes.

### 2026-03-27 17:24 — Replier A, Fridge
- Individual, no org. Wrong dates ("collect today on Monday"). 4↑, 8 collected.
- **Pattern**: people don't read post body. Helper must clarify dates.

### 2026-03-27 17:34 — Replier B, Fridge
- Connector for charity. "Sent this to friends at [charity]". Collection TBA. 3↑, 8 collected.
- **Pattern added to plan**: connector/broker — not end recipient, brokering for org.

### 2026-03-27 19:11 — Replier C, Clocks + Ladder (multi-item)
- "Not a charity so only if a charity doesn't ask for". 21↑, 55 collected.
- **Pattern**: self-selecting backup. Don't re-ask criteria they've already addressed.

### 2026-03-27 18:54 — Replier D, Desks
- Template reply asking for computers, not desks. 13↑, 10 collected.
- **Pattern**: mismatch/spam. Redirected to actual items rather than ignoring.

### 2026-03-28 08:32 — Replier E, Trestle Tables (then Ladder)
- Community farm. "Need tables for cafe area". Collection 7th. 1↑, 8 collected.
- Later confirmed: wants all 3 tables + ladder, has transport. → QUALIFIED.
- **Pattern**: ideal reply — criteria met, specific date, clear need. Skip to QUALIFIED.

### 2026-03-28 06:54 — Replier F, C5 Envelopes
- Question: "How many envelopes?" Then chased: "Hope you can see my message".
- Later: wants ~250, confirmed charity, asked for photos (escalated to human).
- **Pattern**: question replies need answering first. Chasing = Helper too slow.

### 2026-03-28 12:20 — First responses sent (delayed by manual process)
- 6 repliers got personalised responses. No commitments. All include "still collecting interest".
- Wording refined: "you're down for" → "we've noted your interest in" (avoids implied commitment).
- Replier A confirmed can't make dates → PARKED_REPLIED.
- Replier E confirmed all details → QUALIFIED.
- Replier F needs photos → ESCALATED.

### Design findings from production data analysis
- Greenwich furniture clearance: 88 repliers, offerer replied to 40, post expired without outcome. Overwhelmed.
- Samsung TV: 87 repliers, offerer replied to 2, ghosted 85. No rejection messages sent.
- Multi-item repeat collectors exist but are a different pattern (ongoing relationship, not bulk event).

### Key rules derived from trial
1. Answer factual questions from listing data immediately — don't make people wait.
2. One message, all questions — don't drip-feed across round-trips.
3. Mention other available items to single-item repliers — they may have missed them.
4. Never use LLM geography — use API lat/lng and haversine only.
5. Keep parked people warm — they may become best option if primaries fall through.
6. Detect mismatch replies — redirect rather than ignore.
7. Messages sent as offerer, not identified as AI.
8. Human makes allocation decisions, Helper manages conversation flow.
