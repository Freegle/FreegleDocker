# Standard message action: Edit and Message

Status: design, no code. 2026-10-19.

## Why

Some posts need text added and the poster told why, for example knives (age and in-person
handover) and firewood. Today that is two standard messages and two clicks: an Edit, which
changes the post and sends nothing, and a Leave, which messages the poster and changes nothing.

## Decisions

- A new standard message action, **Edit and Message**. Existing Edit messages are unchanged.
- The poster's message text is configured in the standard message and can be changed in the
  modal before sending, the same as any other standard message.
- One API call does both: new parameters on `PATCH /message`.
- No automatic offering of the button by keyword.

## Data

`mod_stdmsgs`:

- Add `Edit and Message` to the `action` enum.
- New column `replybody mediumtext NULL`: the message to the poster. `body` and `insert` keep
  their Edit meaning, the text added to the post at the top or bottom.
- `subjpref` and `subjsuff` apply to the message subject, as they do for Leave. The post
  subject is rebuilt from type, item and location, as Edit does now.

Migration in `iznik-batch/database/migrations/`. The enum change is a full rewrite unless the
new value goes on the end, so append it to keep it INSTANT. The PR carries the raw SQL.

The copy paths need the new column too: `stdMsgColumns` and the config copy in
`iznik-server-go/modconfig/modconfig.go`, plus create and patch in
`iznik-server-go/stdmsg/stdmsg.go`.

## API

`PATCH /message` gains three optional fields on `patchMessageRequest`:

| Field | Meaning |
|---|---|
| `replysubject` | Subject of the message to the poster |
| `replybody` | Text of the message to the poster |
| `stdmsgid` | The standard message used. Recorded on both the edit and the message |

If `replybody` is absent, the call behaves exactly as it does now.

If `replybody` is present, the server:

1. Runs every check that can refuse the message **before changing anything**: the caller
   moderates the post, the post is not an unaddressed TN post (`modmessaging.PostIsUnaddressed`),
   and the caller's community may message the poster (`NotifyPosterFlag`; only the home
   community can write about a rippled post). If any check fails, it returns the same error
   `handleReply` would and the post is not touched.
2. Applies the edit through the existing patch path, logging `LOG_SUBTYPE_EDIT` with the
   stdmsgid, not 0.
3. Sends the message through the same code `handleReply` uses. Factor its post-check body into
   a helper that both call, so the group resolution and chat delivery are not copied.
4. If step 3 fails after step 2 succeeded, it returns an error that says the edit was saved and
   the message was not sent, so the modal can say so plainly.

Partner (TN) calls to `PATCH /message` must not be able to use the new fields. Ignore them, or
refuse them, when the call is made with `?partner=`.

## ModTools

`ModSettingsStandardMessageModal.vue`

- Add the action to the options, offered wherever Edit is (pending and approved posts).
- When it is selected, show the existing Edit controls (text case, insert top or bottom) and
  the text box, labelled **Added to post**, plus a second box, **Message to poster**, bound to
  `replybody`.

`ModMessageButtons.vue`

- Add the action to both valid-action lists next to `Edit`.
- If the post's poster can't be messaged (an unaddressed TN post), hide it as Leave is hidden.

`ModStdMessageModal.vue`

- Show the edit fields (type, item, location) and the post body box as for Edit, labelled
  **Post will read**.
- Below it, show From and To, the subject, and a **Message to [name]** box filled from
  `replybody` with the usual substitutions.
- Button label: **Save Edit and Send**.
- The "edit this" and optional-section checks cover both boxes. There is no 30-character
  minimum on the message, because it starts from a prepared text.
- On send, one `messageStore.patch` call with the new fields, then refresh as Edit does.
- If the error says the edit was saved and the message was not, show that message and
  close nothing.

`stores/message.js` and the API wrapper pass the new fields through.

## Tests

- Go:
  - The edit and the message both happen, and both log entries carry the stdmsgid.
  - An unaddressed TN post is refused with the post unchanged.
  - A rippled-in community is refused with the post unchanged.
  - Without `replybody`, the patch behaves as before.
  - Partner calls can't send a message.
- Laravel: the migration, and that config copy carries `replybody`.
- Vitest:
  - The settings modal shows the second box only for the new action.
  - The modal sends one patch with the edited subject and body.
  - The 30-character rule does not apply.
  - The directive checks cover the message box.
- Playwright: a ModTools flow on a pending post, checking that the post text changes and the
  poster's chat gets the message.

## Docs

Update `docs/moderators/moderating-posts.md`, the section on standard messages, with the new
action and a knives example.
