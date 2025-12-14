<?php

namespace Tests\Unit\Models;

use App\Models\MessageAttachment;
use Tests\TestCase;

class MessageAttachmentModelTest extends TestCase
{
    public function test_attachment_can_be_created(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        $attachment = MessageAttachment::create([
            'msgid' => $message->id,
            'contenttype' => 'image/jpeg',
            'archived' => false,
            'primary' => true,
        ]);

        $this->assertDatabaseHas('messages_attachments', ['id' => $attachment->id]);
    }

    public function test_message_relationship(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        $attachment = MessageAttachment::create([
            'msgid' => $message->id,
            'contenttype' => 'image/jpeg',
            'archived' => false,
            'primary' => true,
        ]);

        $this->assertEquals($message->id, $attachment->message->id);
    }

    public function test_is_primary(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        $primary = MessageAttachment::create([
            'msgid' => $message->id,
            'contenttype' => 'image/jpeg',
            'archived' => false,
            'primary' => true,
        ]);

        $secondary = MessageAttachment::create([
            'msgid' => $message->id,
            'contenttype' => 'image/jpeg',
            'archived' => false,
            'primary' => false,
        ]);

        $this->assertTrue($primary->isPrimary());
        $this->assertFalse($secondary->isPrimary());
    }
}
