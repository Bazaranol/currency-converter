<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Entity\Currency;
use App\Entity\ExchangeRate;

interface ExchangeRateRepositoryInterface
{
    /**
     * Find the most recent rate for a specific currency pair.
     *
     * Uses the idx_rate_pair_date index for efficient lookup.
     */
    public function findLatestRate(Currency $base, Currency $target): ?ExchangeRate;

    /**
     * Return the most recent rate for every base/target pair.
     *
     * Uses a subquery with MAX(fetched_at) to avoid returning
     * historical duplicates. Eagerly joins both Currency relations
     * to prevent N+1 queries.
     *
     * @return ExchangeRate[]
     */
    public function findAllLatestRates(): array;

    /**
     * Return all rates fetched on a specific date (UTC day).
     *
     * @return ExchangeRate[]
     */
    public function findRatesByDate(\DateTimeInterface $date): array;

    /**
     * Persist a single exchange rate.
     */
    public function save(ExchangeRate $rate): void;

    /**
     * Batch-persist multiple exchange rates efficiently.
     *
     * Flushes every 50 entities and clears the identity map
     * to keep memory usage constant regardless of batch size.
     *
     * @param ExchangeRate[] $rates
     */
    public function saveMany(array $rates): void;

    /**
     * Return the most recent fetchedAt timestamp across all rates,
     * or null if the table is empty.
     */
    public function findLatestFetchedAt(): ?\DateTimeImmutable;
}
