<?php

namespace App\Services\Mail\Incoming;

/**
 * Wraps a RoutingResult with additional context about what was routed where.
 *
 * Used by routeDryRun() to provide detailed comparison data for shadow testing.
 */
class RoutingOutcome
{
    public function __construct(
        public readonly RoutingResult $result,
        public readonly ?int $userId = null,
        public readonly ?int $groupId = null,
        public readonly ?int $chatId = null,
        public readonly ?string $spamReason = null,
    ) {}
}
