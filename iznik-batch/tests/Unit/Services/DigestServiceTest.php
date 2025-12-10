<?php

namespace Tests\Unit\Services;

use App\Mail\Digest\MultipleDigest;
use App\Mail\Digest\SingleDigest;
use App\Models\Group;
use App\Models\GroupDigest;
use App\Models\Membership;
use App\Services\DigestService;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DigestServiceTest extends TestCase
{
    protected DigestService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DigestService();
        Mail::fake();
    }

    public function test_send_digest_for_closed_group_does_nothing(): void
    {
        // Create a closed group.
        $group = $this->createTestGroup([
            'settings' => ['closed' => true],
        ]);

        $stats = $this->service->sendDigestForGroup($group, Membership::EMAIL_DIGEST_IMMEDIATE);

        $this->assertEquals(0, $stats['emails_sent']);
        $this->assertEquals(0, $stats['members_processed']);
        $this->assertEquals(0, $stats['errors']);

        Mail::assertNothingSent();
    }

    public function test_send_digest_with_no_new_messages_does_nothing(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser();
        $this->createMembership($user, $group, [
            'emailfrequency' => Membership::EMAIL_DIGEST_IMMEDIATE,
        ]);

        $stats = $this->service->sendDigestForGroup($group, Membership::EMAIL_DIGEST_IMMEDIATE);

        $this->assertEquals(0, $stats['emails_sent']);
        Mail::assertNothingSent();
    }

    public function test_send_single_message_digest(): void
    {
        $group = $this->createTestGroup();
        $poster = $this->createTestUser(['fullname' => 'Poster User']);
        $recipient = $this->createTestUser(['fullname' => 'Recipient User']);

        $this->createMembership($poster, $group);
        $this->createMembership($recipient, $group, [
            'emailfrequency' => Membership::EMAIL_DIGEST_IMMEDIATE,
        ]);

        // Create a single message.
        $this->createTestMessage($poster, $group);

        $stats = $this->service->sendDigestForGroup($group, Membership::EMAIL_DIGEST_IMMEDIATE);

        // Should send to recipient (not poster, who created the message).
        $this->assertGreaterThanOrEqual(1, $stats['emails_sent']);

        Mail::assertSent(SingleDigest::class);
    }

    public function test_send_multiple_message_digest(): void
    {
        $group = $this->createTestGroup();
        $poster = $this->createTestUser(['fullname' => 'Poster User']);
        $recipient = $this->createTestUser(['fullname' => 'Recipient User']);

        $this->createMembership($poster, $group);
        $this->createMembership($recipient, $group, [
            'emailfrequency' => Membership::EMAIL_DIGEST_IMMEDIATE,
        ]);

        // Create multiple messages.
        $this->createTestMessages($poster, $group, 3);

        $stats = $this->service->sendDigestForGroup($group, Membership::EMAIL_DIGEST_IMMEDIATE);

        $this->assertGreaterThanOrEqual(1, $stats['emails_sent']);

        Mail::assertSent(MultipleDigest::class);
    }

    public function test_digest_updates_record_with_last_message(): void
    {
        $group = $this->createTestGroup();
        $poster = $this->createTestUser();
        $recipient = $this->createTestUser();

        $this->createMembership($poster, $group);
        $this->createMembership($recipient, $group, [
            'emailfrequency' => Membership::EMAIL_DIGEST_HOURLY,
        ]);

        $messages = $this->createTestMessages($poster, $group, 2);

        $this->service->sendDigestForGroup($group, Membership::EMAIL_DIGEST_HOURLY);

        // Check digest record was updated.
        $digest = GroupDigest::where('groupid', $group->id)
            ->where('frequency', Membership::EMAIL_DIGEST_HOURLY)
            ->first();

        $this->assertNotNull($digest);
        $this->assertEquals($messages[1]->id, $digest->msgid);
    }

    public function test_digest_only_sends_to_members_with_matching_frequency(): void
    {
        $group = $this->createTestGroup();
        $poster = $this->createTestUser();
        $immediateUser = $this->createTestUser(['fullname' => 'Immediate User']);
        $hourlyUser = $this->createTestUser(['fullname' => 'Hourly User']);

        $this->createMembership($poster, $group);
        $this->createMembership($immediateUser, $group, [
            'emailfrequency' => Membership::EMAIL_DIGEST_IMMEDIATE,
        ]);
        $this->createMembership($hourlyUser, $group, [
            'emailfrequency' => Membership::EMAIL_DIGEST_HOURLY,
        ]);

        $this->createTestMessage($poster, $group);

        // Run immediate digest.
        $stats = $this->service->sendDigestForGroup($group, Membership::EMAIL_DIGEST_IMMEDIATE);

        // Should only send to immediate user.
        $this->assertEquals(1, $stats['emails_sent']);
    }

    public function test_get_valid_frequencies(): void
    {
        $frequencies = DigestService::getValidFrequencies();

        $this->assertContains(-1, $frequencies);  // Immediate.
        $this->assertContains(1, $frequencies);   // Hourly.
        $this->assertContains(24, $frequencies);  // Daily.
        $this->assertCount(6, $frequencies);
    }

    public function test_get_active_groups_returns_freegle_groups(): void
    {
        // Create Freegle group.
        $freegleGroup = $this->createTestGroup();

        // Create non-Freegle group.
        Group::create([
            'nameshort' => 'NonFreegleGroup',
            'namefull' => 'Non Freegle Group',
            'type' => 'Other',
            'region' => 'TestRegion',
            'lat' => 51.5074,
            'lng' => -0.1278,
        ]);

        $activeGroups = $this->service->getActiveGroups();

        // Should only contain Freegle group.
        $this->assertTrue($activeGroups->contains('id', $freegleGroup->id));
    }
}
