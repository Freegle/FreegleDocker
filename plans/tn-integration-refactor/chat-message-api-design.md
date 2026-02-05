# Chat Message API Design

## What tasks does this API need to accomplish?
- Public/called by TN:
    - Receive message
    - Receive read receipt
    - Receive message deleted
        - Doesn't look like TN users can delete/edit their messages, but they can be deleted by moderation
    - ??? Interest in post withdrawn (future)*
- Internal:
    - receive message (for user or mod(s))
    - validate chat room exists
    - validate user is in chat room
    - create user2user chat room
    - create user2mod chat room
    - check message for spam (either discard or flag for review)
        - sender
        - body text
        - images
    - receive read receipt
    - report user
    - ??? Interest in post withdrawn (future)*

_*The "interest in post withdrawn" bit is related to something I'm working on with Andrew right now. It's a formalized way for users to mark themselves as no longer interested in a post._

---

- Relevant files:
	- [`IncomingMailService.php`](../../iznik-batch/app/Services/Mail/Incoming/IncomingMailService.php)
		- especially `handleChatNotificationReply` and `createChatMessageFromEmail`
	- [`MailParserService.php`](../../iznik-batch/app/Services/Mail/Incoming/MailParserService.php)
	- Database schema for chat messages (in [`iznik-server/install/schema.sql`](../../iznik-server/install/schema.sql)):
		- table `chat_rooms` -> [`ChatRoom.php`](../../iznik-batch/app/Models/ChatRoom.php) ORM model
		- table `chat_messages` -> [`ChatMessage.php`](../../iznik-batch/app/Models/ChatMessage.php) ORM model

- Functionality from [`IncomingMailService.php`](../../iznik-batch/app/Services/Mail/Incoming/IncomingMailService.php) currently handled by email
	- `getOrCreateUserChat(int $userId1, int $userId2): ?ChatRoom`
	- `getOrCreateUser2ModChat(int $userId, int $groupId): ?ChatRoom`
	- `isReadReceipt(ParsedEmail $email): bool`
	- `handleReadReceipt(ParsedEmail $email): RoutingResult`
	- `handleChatNotificationReply(ParsedEmail $email): RoutingResult`
	- `isStaleChatWithUnfamiliarSender(ChatRoom $chat, ParsedEmail $email): bool`
	- `isUserInChat(int $userId, ChatRoom $chat): bool`
	- `createChatMessageFromEmail(ChatRoom $chat, int $userId, ParsedEmail $email, bool $forceReview = false, ?string $forceReviewReason = null): void`
	- `checkChatImageSpam(ChatMessage $chatMsg): void`
	- `mapReportReason(?string $reason): ?string`
	- `handleVolunteersMessage(ParsedEmail $email): RoutingResult`
	- `trackEmailReply(int $chatId, int $userId): void`

- Calling TN's API:
	- These calls will go throughout the rest of the codebase where we send messages, e.g. [`ChatNotificationService.php`](../../iznik-batch/app/Services/ChatNotificationService.php)
	- Sending message
	- Sending read receipt
	- Sending message deletions

- Can remove anything related to scheduling:
	- `ScheduleUpdated`, `Schedule`, `System` not used in years
	- Removing these can be done separately. They're not used in any of the code that needs porting, so it's an unrelated 
    