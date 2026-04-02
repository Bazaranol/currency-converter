<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Immutable result of a currency conversion.
 *
 * Carries all data needed to display or serialize the conversion
 * without coupling to any Entity or Value Object.
 */
final readonly class ConversionResultDTO
{
    public function __construct(
        /** Original amount as string for precision. */
        public string $originalAmount,

        /** ISO 4217 code of the source currency. */
        public string $originalCurrency,

        /** Converted amount as string for precision. */
        public string $convertedAmount,

        /** ISO 4217 code of the target currency. */
        public string $targetCurrency,

        /** The exchange rate applied (base → target). */
        public string $rate,

        /** When the conversion was performed. */
        public \DateTimeImmutable $convertedAt,
    ) {
    }

    /**
     * Convenience string: "123.45 USD → 9 876.54 RUB (rate: 80.004500)"
     */
    public function __toString(): string
    {
        return sprintf(
            '%s %s → %s %s (rate: %s)',
            $this->originalAmount,
            $this->originalCurrency,
            $this->convertedAmount,
            $this->targetCurrency,
            $this->rate,
        );
    }
}
