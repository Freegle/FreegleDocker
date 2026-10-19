# Support tooling improvements

Status: placeholder. Nothing is implemented. Each item needs a decision before it is built.

## Problems raised by the support team

- Password and login questions are constant, and "reinstall the app" is now a stock answer.
- Finding a member's other email address when they have two accounts takes many emails.
- A thread of conversation with moderators through chat is hard to follow.
- Small disagreements inside the support team go unresolved.
- Questions arrive at random (gift aid, for example) with no single route to an answer.
- Bewildered, angry or confused members are hard to reply to well.

## Live measurements (read-only, 2026-10-19)

| Measure | Value |
|---|---|
| Support assistant runs since 2026-10-04 | 10 runs, 3 people, 3 errors, 0 ratings |
| Members active in the last 7 days who sign in only with Google or Facebook | about 2,600 of 20,000 |
| Members active in the last 14 days on a build older than 30 days | 724 of 30,000 |
| `users_builddates.appversion` filled in | 0 rows of 490,712 |
| Merge offers in the last year | 957 offered, 100 accepted, 18 rejected, 839 unanswered |
| `users_related` rows in the last 90 days | 1,672, of which 1,349 never notified |
| User2Mod chats active in the last 30 days | 3,383 chats, 2.4 messages each |
| Mod2Mod chats active in the last 30 days | 5 chats |
| Gift aid declarations awaiting review | 170 |

## Options

### 1. Logins

- Record the app version. The client sends `webversion` on `GET /session` but not `appversion`, so support cannot tell which app version a member runs.
- Put the sign-in method at the top of the member summary and the support snapshot, for example "signs in with Google, no password".
- Show a gentle "please update" prompt in the app for builds older than a few weeks. The forced-update switch (`app_min_webversion`) stays as it is.

### 2. Duplicate accounts

- Show a linked-accounts panel on the ModTools member page, built from `users_related`.
- Let support search by part of an email, by name with postcode sector, or by phone number.
- Make merging easier for the member: one link sent to both addresses, which merges once either is clicked. This needs a data-protection decision. TrashNothing accounts stay excluded from merging.

### 3. Threads with moderators

- A support case that holds the member, the support emails, the moderator chat and private notes.
- Until then, a convention: one chat per case, opened with the member number and a one-line summary.

### 4. Team decisions

- Ask the team to rate the support assistant's answers, which gives a low-conflict way to disagree.
- Keep a short page of settled answers. Each one becomes the assistant's default reply.

### 5. Random questions

- Sort each question into one of four outcomes: answered from docs, answered from the member's data, needs a named owner to decide, or a bug.
- Group the assistant's questions weekly so a new wave of problems shows up within days.

### 6. Difficult conversations

- Have the assistant draft replies in a fixed shape: acknowledge, one action, plain words, no history.
- For angry messages, draft a calm opening and suggest when to hand over or close.
- Let support see a screenshot of what the member sees.

## Suggested order

1. Record the app version and show the sign-in method.
2. Add the linked-accounts panel.
3. Two weeks of the team using and rating the assistant, with a settled-answers page started at the team call.

Support cases and the one-click merge are larger decisions for the team call.
