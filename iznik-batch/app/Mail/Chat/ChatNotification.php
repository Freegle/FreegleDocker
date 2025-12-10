<?php

namespace App\Mail\Chat;

use App\Mail\MjmlMailable;
use App\Models\ChatRoom;
use App\Models\User;
use Illuminate\Support\Collection;

class ChatNotification extends MjmlMailable
{
    public User $recipient;

    public ?User $sender;

    public ChatRoom $chatRoom;

    public Collection $messages;

    public string $chatType;

    public string $userSite;

    public string $chatUrl;

    public string $replySubject;

    /**
     * Create a new message instance.
     */
    public function __construct(
        User $recipient,
        ?User $sender,
        ChatRoom $chatRoom,
        Collection $messages,
        string $chatType
    ) {
        $this->recipient = $recipient;
        $this->sender = $sender;
        $this->chatRoom = $chatRoom;
        $this->messages = $messages;
        $this->chatType = $chatType;
        $this->userSite = config('freegle.sites.user');
        $this->chatUrl = $this->userSite . '/chats/' . $chatRoom->id;

        // Build the subject line.
        $this->replySubject = $this->generateSubject();
    }

    /**
     * Build the message.
     */
    public function build(): static
    {
        return $this->to($this->recipient->email_preferred, $this->recipient->displayname)
            ->subject($this->replySubject)
            ->mjmlView('emails.mjml.chat.notification', [
                'recipient' => $this->recipient,
                'sender' => $this->sender,
                'chatRoom' => $this->chatRoom,
                'messages' => $this->messages,
                'chatType' => $this->chatType,
                'userSite' => $this->userSite,
                'chatUrl' => $this->chatUrl,
                'messageCount' => $this->messages->count(),
            ]);
    }

    /**
     * Get the subject line for the email.
     */
    protected function getSubject(): string
    {
        return $this->replySubject;
    }

    /**
     * Generate the subject line based on context.
     */
    protected function generateSubject(): string
    {
        $senderName = $this->sender?->displayname ?? 'Someone';

        if ($this->chatType === ChatRoom::TYPE_USER2MOD) {
            $group = $this->chatRoom->group;
            $groupName = $group?->nameshort ?? 'your local Freegle group';
            return "Message from {$groupName} volunteers";
        }

        // Check if there's a referenced message in any of the chat messages.
        $refMessage = null;
        foreach ($this->messages as $message) {
            if ($message->refMessage) {
                $refMessage = $message->refMessage;
                break;
            }
        }

        if ($refMessage) {
            return "Re: {$refMessage->subject}";
        }

        return "{$senderName} sent you a message on Freegle";
    }
}
