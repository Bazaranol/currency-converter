<?php

declare(strict_types=1);

namespace App\Application\Event;

final readonly class RatesSyncFailedEvent
{
    public function __construct(
        public string $reason,
        public \Throwable $exception,
        public \DateTimeImmutable $failedAt,
    ) {
    }
}
