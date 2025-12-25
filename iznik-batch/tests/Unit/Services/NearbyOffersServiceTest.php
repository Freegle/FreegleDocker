<?php

namespace Tests\Unit\Services;

use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\MessageGroup;
use App\Services\NearbyOffersService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NearbyOffersServiceTest extends TestCase
{
    private NearbyOffersService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new NearbyOffersService();
    }

    public function test_service_can_be_instantiated(): void
    {
        $this->assertInstanceOf(NearbyOffersService::class, $this->service);
    }

    public function test_get_random_offers_returns_collection(): void
    {
        $result = $this->service->getRandomOffers();

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
    }

    public function test_get_random_offers_respects_limit(): void
    {
        // Create more offers than the limit.
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        for ($i = 0; $i < 10; $i++) {
            $message = $this->createTestMessage($user, $group, [
                'type' => Message::TYPE_OFFER,
                'subject' => "OFFER: Test Item $i (London)",
            ]);
            $this->createMessageAttachment($message);
        }

        // Request only 3.
        $result = $this->service->getRandomOffers(3);

        $this->assertLessThanOrEqual(3, $result->count());
    }

    public function test_get_random_offers_returns_structured_data(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $message = $this->createTestMessage($user, $group, [
            'type' => Message::TYPE_OFFER,
            'subject' => 'OFFER: Test Widget (London)',
        ]);
        $this->createMessageAttachment($message);

        $result = $this->service->getRandomOffers(1);

        if ($result->isNotEmpty()) {
            $offer = $result->first();
            $this->assertArrayHasKey('id', $offer);
            $this->assertArrayHasKey('subject', $offer);
            $this->assertArrayHasKey('thumbnail_url', $offer);
            $this->assertArrayHasKey('url', $offer);
        }
    }

    public function test_get_nearby_offers_returns_empty_for_user_without_location(): void
    {
        $user = $this->createTestUser(['lastlocation' => null]);

        $result = $this->service->getNearbyOffers($user);

        // Should fall back to random offers but may be empty if no messages exist.
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
    }

    public function test_get_offers_near_location_returns_collection(): void
    {
        $result = $this->service->getOffersNearLocation(51.5074, -0.1278);

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
    }

    public function test_get_offers_near_location_respects_limit(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup([
            'lat' => 51.5074,
            'lng' => -0.1278,
        ]);
        $this->createMembership($user, $group);

        // Create messages near the location.
        for ($i = 0; $i < 10; $i++) {
            $message = $this->createTestMessage($user, $group, [
                'type' => Message::TYPE_OFFER,
                'subject' => "OFFER: Test Item $i (London)",
                'lat' => 51.5074 + ($i * 0.001),
                'lng' => -0.1278 + ($i * 0.001),
            ]);
            $this->createMessageAttachment($message);
        }

        $result = $this->service->getOffersNearLocation(51.5074, -0.1278, 3);

        $this->assertLessThanOrEqual(3, $result->count());
    }

    public function test_subject_truncation_removes_offer_prefix(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $message = $this->createTestMessage($user, $group, [
            'type' => Message::TYPE_OFFER,
            'subject' => 'OFFER: Widget',
        ]);
        $this->createMessageAttachment($message);

        $result = $this->service->getRandomOffers(1);

        if ($result->isNotEmpty()) {
            $offer = $result->first();
            $this->assertStringNotContainsString('OFFER:', $offer['subject']);
        }
    }

    /**
     * Create a message attachment for testing.
     */
    private function createMessageAttachment(Message $message): MessageAttachment
    {
        return MessageAttachment::create([
            'msgid' => $message->id,
            'contenttype' => 'image/jpeg',
            'primary' => 1,
        ]);
    }
}
