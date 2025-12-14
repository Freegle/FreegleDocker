<?php

namespace Tests\Unit\Mail;

use App\Mail\Message\DeadlineReached;
use App\Models\Message;
use App\Models\User;
use Tests\TestCase;

class MessageMailTest extends TestCase
{
    public function test_deadline_reached_can_be_constructed(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        $mail = new DeadlineReached($message, $user);

        $this->assertInstanceOf(DeadlineReached::class, $mail);
    }

    public function test_deadline_reached_has_message(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        $mail = new DeadlineReached($message, $user);

        $this->assertSame($message->id, $mail->message->id);
    }

    public function test_deadline_reached_has_user(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        $mail = new DeadlineReached($message, $user);

        $this->assertSame($user->id, $mail->user->id);
    }

    public function test_deadline_reached_has_correct_urls(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        $mail = new DeadlineReached($message, $user);

        $this->assertStringContainsString('/mypost/' . $message->id . '/extend', $mail->extendUrl);
        $this->assertStringContainsString('/mypost/' . $message->id . '/completed', $mail->completedUrl);
        $this->assertStringContainsString('/mypost/' . $message->id . '/withdraw', $mail->withdrawUrl);
    }

    public function test_deadline_reached_offer_has_taken_outcome(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group, [
            'type' => Message::TYPE_OFFER,
        ]);

        $mail = new DeadlineReached($message, $user);

        $this->assertEquals(Message::OUTCOME_TAKEN, $mail->outcomeType);
    }

    public function test_deadline_reached_wanted_has_received_outcome(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group, [
            'type' => Message::TYPE_WANTED,
        ]);

        $mail = new DeadlineReached($message, $user);

        $this->assertEquals(Message::OUTCOME_RECEIVED, $mail->outcomeType);
    }

    public function test_deadline_reached_build_returns_self(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        $mail = new DeadlineReached($message, $user);
        $result = $mail->build();

        $this->assertInstanceOf(DeadlineReached::class, $result);
    }

    public function test_deadline_reached_has_correct_subject(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group, [
            'subject' => 'OFFER: Test Item (Location)',
        ]);

        $mail = new DeadlineReached($message, $user);
        $envelope = $mail->envelope();

        $this->assertEquals('Deadline reached: OFFER: Test Item (Location)', $envelope->subject);
    }
}
