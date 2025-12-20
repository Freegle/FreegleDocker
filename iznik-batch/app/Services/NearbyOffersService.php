<?php

namespace App\Services;

use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Collection;

class NearbyOffersService
{
    /**
     * Default search radius in kilometers.
     */
    private const DEFAULT_RADIUS_KM = 10;

    /**
     * Maximum search radius in kilometers.
     */
    private const MAX_RADIUS_KM = 50;

    /**
     * Number of offers to fetch.
     */
    private const OFFER_LIMIT = 6;

    /**
     * Get nearby offers with attachments for a user.
     * Falls back to random offers if no location is set.
     */
    public function getNearbyOffers(User $user, int $limit = self::OFFER_LIMIT): Collection
    {
        $location = $user->lastLocation;

        if (!$location || !$location->lat || !$location->lng) {
            // No location - return random recent offers instead.
            return $this->getRandomOffers($limit);
        }

        return $this->getOffersNearLocation(
            (float) $location->lat,
            (float) $location->lng,
            $limit
        );
    }

    /**
     * Get random recent offers with attachments.
     * Used as fallback when user location is not known.
     */
    public function getRandomOffers(int $limit = self::OFFER_LIMIT): Collection
    {
        $offers = Message::query()
            ->offers()
            ->approved()
            ->notDeleted()
            ->recent(7)
            ->whereHas('attachments')
            ->with(['attachments' => function ($query) {
                $query->orderByDesc('primary')->limit(1);
            }])
            ->inRandomOrder()
            ->limit($limit)
            ->get();

        return $offers->map(function ($offer) {
            return [
                'id' => $offer->id,
                'subject' => $this->truncateSubject($offer->subject),
                'thumbnail_url' => $this->getThumbnailUrl($offer),
                'url' => $this->getOfferUrl($offer),
            ];
        });
    }

    /**
     * Get offers near a specific lat/lng.
     */
    public function getOffersNearLocation(
        float $lat,
        float $lng,
        int $limit = self::OFFER_LIMIT,
        int $radiusKm = self::DEFAULT_RADIUS_KM
    ): Collection {
        // Convert radius to approximate degrees (1 degree ~ 111km at equator).
        $radiusDegrees = $radiusKm / 111.0;

        $offers = Message::query()
            ->offers()
            ->approved()
            ->notDeleted()
            ->withLocation()
            ->recent(7)
            ->whereHas('attachments')
            ->whereBetween('lat', [$lat - $radiusDegrees, $lat + $radiusDegrees])
            ->whereBetween('lng', [$lng - $radiusDegrees, $lng + $radiusDegrees])
            ->with(['attachments' => function ($query) {
                $query->orderByDesc('primary')->limit(1);
            }])
            ->orderByDesc('arrival')
            ->limit($limit)
            ->get();

        // If we didn't find enough, expand the radius.
        if ($offers->count() < $limit && $radiusKm < self::MAX_RADIUS_KM) {
            return $this->getOffersNearLocation($lat, $lng, $limit, $radiusKm * 2);
        }

        return $offers->map(function ($offer) {
            return [
                'id' => $offer->id,
                'subject' => $this->truncateSubject($offer->subject),
                'thumbnail_url' => $this->getThumbnailUrl($offer),
                'url' => $this->getOfferUrl($offer),
            ];
        });
    }

    /**
     * Truncate subject to fit in email thumbnail.
     */
    private function truncateSubject(?string $subject): string
    {
        if (!$subject) {
            return 'Item';
        }

        // Remove common prefixes like "OFFER:" or "Offer:".
        $subject = preg_replace('/^(OFFER|Offer|WANTED|Wanted):\s*/i', '', $subject);

        return strlen($subject) > 20 ? substr($subject, 0, 17) . '...' : $subject;
    }

    /**
     * Get thumbnail URL for an offer.
     */
    private function getThumbnailUrl(Message $offer): ?string
    {
        $attachment = $offer->attachments->first();

        if (!$attachment) {
            return NULL;
        }

        // Image URLs are served from the main site API.
        $baseUrl = config('freegle.sites.user');

        return "{$baseUrl}/api/image/{$attachment->id}?w=120&h=120";
    }

    /**
     * Get URL to view the offer.
     */
    private function getOfferUrl(Message $offer): string
    {
        $baseUrl = config('freegle.sites.user');

        return "{$baseUrl}/message/{$offer->id}";
    }
}
