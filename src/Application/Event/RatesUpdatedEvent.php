<?php

declare(strict_types=1);

namespace App\Application\Event;

final readonly class RatesUpdatedEvent
{
    public function __construct(
        public int $ratesCount,
        public int $skippedCount,
        public string $providerName,
        public \DateTimeImmutable $syncedAt,
    ) {
    }
}
