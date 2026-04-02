<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Transfers exchange rate data from an API provider into the application.
 *
 * Decouples the raw API response format from the Entity layer:
 * the provider adapter creates DTOs, the sync service persists them.
 */
final readonly class ExchangeRateDTO
{
    public function __construct(
        /** ISO 4217 code of the base currency (e.g. "USD"). */
        public string $baseCurrency,

        /** ISO 4217 code of the target currency (e.g. "RUB"). */
        public string $targetCurrency,

        /** Exchange rate as string for bcmath precision. */
        public string $rate,

        /** Timestamp when the rate was fetched from the API. */
        public \DateTimeImmutable $fetchedAt,
    ) {
    }
}
