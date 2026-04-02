<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Statistics returned after an exchange rate synchronization run.
 */
final readonly class SyncResultDTO
{
    public function __construct(
        public int $updatedCount,
        public int $skippedCount,
        public int $errorCount,

        /** @var string[] Currencies that failed to sync. */
        public array $errors,
        public \DateTimeImmutable $syncedAt,
    ) {
    }

    public function isFullSuccess(): bool
    {
        return $this->errorCount === 0;
    }

    public function __toString(): string
    {
        return sprintf(
            'Synced: %d updated, %d skipped, %d errors.',
            $this->updatedCount,
            $this->skippedCount,
            $this->errorCount,
        );
    }
}
