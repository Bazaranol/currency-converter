<?php

declare(strict_types=1);

namespace App\Infrastructure\EventListener;

use App\Application\Event\RatesUpdatedEvent;
use App\Application\Event\RatesSyncFailedEvent;
use Psr\Log\LoggerInterface;

final readonly class RatesUpdateLogger
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function onRatesUpdated(RatesUpdatedEvent $event): void
    {
        $this->logger->info('Exchange rates synced successfully.', [
            'rates_count' => $event->ratesCount,
            'skipped_count' => $event->skippedCount,
            'provider' => $event->providerName,
            'synced_at' => $event->syncedAt->format('Y-m-d H:i:s'),
        ]);
    }

    public function onSyncFailed(RatesSyncFailedEvent $event): void
    {
        $this->logger->error('Exchange rates sync failed.', [
            'reason' => $event->reason,
            'exception' => $event->exception->getMessage(),
            'failed_at' => $event->failedAt->format('Y-m-d H:i:s'),
        ]);
    }
}
