<?php

namespace Tests\Unit\Mail;

use App\Mail\Digest\MultipleDigest;
use App\Mail\Digest\SingleDigest;
use App\Models\Group;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Collection;
use Tests\TestCase;

class DigestMailTest extends TestCase
{
    public function test_single_digest_can_be_constructed(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        $mail = new SingleDigest($user, $group, $message, 24);

        $this->assertInstanceOf(SingleDigest::class, $mail);
    }

    public function test_single_digest_build_returns_self(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        $mail = new SingleDigest($user, $group, $message, 24);
        $result = $mail->build();

        $this->assertInstanceOf(SingleDigest::class, $result);
    }

    public function test_single_digest_has_correct_subject(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group, [
            'subject' => 'OFFER: Test Item (Location)',
        ]);

        $mail = new SingleDigest($user, $group, $message, 24);
        $envelope = $mail->envelope();

        $this->assertEquals('OFFER: Test Item (Location)', $envelope->subject);
    }

    public function test_multiple_digest_can_be_constructed(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $messages = collect($this->createTestMessages($user, $group, 3));

        $mail = new MultipleDigest($user, $group, $messages, 24);

        $this->assertInstanceOf(MultipleDigest::class, $mail);
    }

    public function test_multiple_digest_build_returns_self(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $messages = collect($this->createTestMessages($user, $group, 3));

        $mail = new MultipleDigest($user, $group, $messages, 24);
        $result = $mail->build();

        $this->assertInstanceOf(MultipleDigest::class, $result);
    }

    public function test_multiple_digest_subject_single_message(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $messages = collect([$this->createTestMessage($user, $group)]);

        $mail = new MultipleDigest($user, $group, $messages, 24);
        $envelope = $mail->envelope();

        $this->assertEquals("1 new post on {$group->nameshort}", $envelope->subject);
    }

    public function test_multiple_digest_subject_multiple_messages(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $messages = collect($this->createTestMessages($user, $group, 5));

        $mail = new MultipleDigest($user, $group, $messages, 24);
        $envelope = $mail->envelope();

        $this->assertEquals("5 new posts on {$group->nameshort}", $envelope->subject);
    }
}
