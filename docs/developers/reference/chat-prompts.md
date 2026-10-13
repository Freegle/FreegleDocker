---
last_reviewed: 2026-10-13
covers:
  - iznik-server-go/chat/chatprompt.go
  - iznik-server-go/chat/replyconfirm.go
  - iznik-nuxt3/components/ChatMessagePrompt.vue
  - iznik-nuxt3/components/ChatPromptPost.vue
---

# Chat Prompts

A **prompt** is a chat message from Freegle with tappable answers - "Could you deliver?", "When
does it need to go?" - sent into an ordinary `User2User` chat so that delivery, push,
notification, unread state and history all work with no new machinery.

This page covers the **mechanism**: the message type, how a client renders it, and how an answer
is applied. It does not cover what decides to send one; that is a separate feature.

## What sends prompts

- `App\Services\FirstReply\PromptService` asks a poster about their outstanding posts, in their
  own chat with Freegle.
- `chat/replyconfirm.go` asks a **replier**, in their chat with the poster - see "Confirming a
  reply" below.

The client side shipped first, on its own - see "Why this shipped on its own".

## Confirming a reply

When someone replies to an OFFER whose newest `messages_eee` row says it looks damaged
(`item_condition = 'damaged'`), or needs a car (`size_bucket = 'large'`, or `weight_bucket` of
20kg or more, unless the poster has said they might deliver), `CreateChatMessage` asks them in
the same chat whether they still want it, and holds the reply until they answer. Not when either
member is a TrashNothing account, and only once per post per chat.

| Piece | How |
|---|---|
| The question | A `confirm_reply` prompt by the Freegle account, starting "@name," and with `chat_prompts.userid` = the replier |
| Who sees it | Written `processingsuccessful = 0`, so no delivery path gives it to the poster; `FetchChatMessages` shows it to `chat_prompts.userid` |
| Who answers it | Only `chat_prompts.userid`; anyone else gets 403 |
| The hold | `chat_prompts.heldchatmsgid` names the reply; `ChatProcessService` leaves it unprocessed while the prompt is unanswered |
| Yes | The next `chats:process-incoming` run delivers the reply as normal, and in the same step marks the question successful, so the poster gets the question, the answer and the reply together |
| No | The reply is marked processed and unsuccessful, `processingfailreason = 'ReplyWithdrawn'` |
| Another message from the replier | Counts as yes, so it cannot reach the poster ahead of the reply |
| No answer in 3 days | Dropped by `ChatProcessService`, `processingfailreason = 'ReplyNotConfirmed'` |

ModTools chat review explains both reasons. The `chats:process-incoming` backlog check ignores a
reply waiting for its answer.

A Freegle message in a chat between two members names who it is for with an @, so whoever reads
it later can tell. The served prompt carries `userid` and `askedname`, and
`ChatMessagePrompt.vue` shows "@name said:" rather than "You said:" to anyone else. In a chat
between two members the card also carries the Freegle logo and name, and "This is an automated
message."; in a member's own chat with Freegle the header already says both, once.

`messages_eee` is filled hourly, so a reply in a post's first hour usually finds no row and goes
straight through. The model's own accuracy is about 93% for condition, 72% for size and 65% for
weight, which is why the question says "from the photo, this looks like" and lets the member
decide.

## The shape

| Piece | Where |
|---|---|
| The message | `chat_messages` with `type = 'Prompt'` and readable text in `message` |
| The tappable part | `chat_prompts`, keyed on `chatmsgid` |
| Which posts it covers | `chat_prompts.msgids`, a JSON array |
| Serving it | `attachPrompts()` in `chat/chatmessage.go` fills `ChatMessage.prompt` |
| Answering it | `POST /chat/{id}/message/{mid}/prompt` (`chat.AnswerChatPrompt`) |
| Rendering it | `ChatMessagePrompt.vue`, one `ChatPromptPost.vue` per covered post |

The message carries **normal text whatever happens**, so anything that does not understand the
type - an email notification, a push, a mod tool, an older app - still has something sensible to
show.

Answering is server-side only. The server applies what the answer means (setting
`messages.deadline`, `messages.deliverypossible`) as well as recording it, so a client never has
to know what "by this weekend" resolves to, and two clients cannot disagree about it.

Rules the endpoint enforces: only the member the prompt was sent to (or, where
`chat_prompts.userid` is set, that member alone) may answer; only an option
that was actually offered is accepted; only once; and not after the prompt has expired - a
week-old "could you deliver?" on a long-gone item still renders, but its buttons no longer work.

## Why this shipped on its own

**Old apps cannot be upgraded in arrears.** A client that does not know a message type falls
through to the catch-all branch in `ChatMessage.vue`. Until this change that branch rendered

```
Unknown chat message type Prompt, [object] [object]
```

directly into the member's conversation. That is not hypothetical - it already happened once in
the wild with the `System` type (`6d833bb62`, "render System chat messages instead of a raw error
dump").

So the fallback now renders `message` instead of a debug dump, and **any** future type degrades to
plain readable text rather than something broken.

That fixes clients carrying this build. It does nothing for the app somebody is already running,
which is why the sending side is a separate change: every client has to be able to understand a
prompt *before* anything starts sending them.

**There is no way to gate this per member.** `users_builddates` has an `appversion` column, but it
is empty for every user seen in the last 30 days on live (41,402 of 41,402) - only `webversion` is
populated. So we can neither suppress prompts for old clients nor measure app adoption from it. The
control is therefore time and release adoption, not a query, and that is worth fixing before the
sending side is switched on.

## Testing

`iznik-server-go/test/chatprompt_test.go` covers serving a prompt with its options and every
answer rule (wrong member, unoffered option, twice, expired, picked date, out-of-range date, and
"no rush" which records without patching the post). `ChatMessagePrompt.spec.js` and
`ChatPromptPost.spec.js` cover rendering and the answered state.

`iznik-server-go/test/chatreplyconfirm_test.go` covers confirming a reply: which posts ask and
which do not (TrashNothing on either side, a poster who delivers, a WANTED, no classification,
a newer classification), that only the replier sees and answers it, and yes, no and a follow-up
message. `ChatProcessServiceTest` covers the hold, delivery after yes, and the drop on expiry.
