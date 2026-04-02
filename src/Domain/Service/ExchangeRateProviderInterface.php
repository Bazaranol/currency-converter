<?php

declare(strict_types=1);

namespace App\Domain\Service;

use App\DTO\ExchangeRateDTO;
use App\Exception\ExchangeRateApiException;

/**
 * Abstraction over any external exchange rate source.
 *
 * To add a new provider (e.g. ECB, Fixer, OpenExchangeRates):
 *   1. Create a class that implements this interface
 *   2. Rebind in services.yaml
 *   3. No other code changes needed
 */
interface ExchangeRateProviderInterface
{
    /**
     * Fetch the latest exchange rates from an external source.
     *
     * @param string   $baseCurrency     ISO 4217 base currency code (e.g. "USD")
     * @param string[] $targetCurrencies ISO 4217 codes to fetch rates for
     *
     * @return ExchangeRateDTO[] One DTO per successfully fetched pair
     *
     * @throws ExchangeRateApiException On any API communication or parsing error
     */
    public function fetchRates(string $baseCurrency, array $targetCurrencies): array;
}
