<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\ConversionResultDTO;
use App\Exception\ConversionException;
use App\Exception\CurrencyNotFoundException;
use App\Exception\RateNotFoundException;

interface CurrencyConverterInterface
{
    /**
     * Convert a monetary amount from one currency to another.
     *
     * @param float|string $amount       Amount to convert (cast to string for bcmath)
     * @param string       $fromCurrency ISO 4217 source currency code
     * @param string       $toCurrency   ISO 4217 target currency code
     *
     * @throws ConversionException       If amount is invalid (non-numeric, zero, negative)
     * @throws CurrencyNotFoundException If either currency is not in the system
     * @throws RateNotFoundException     If no rate (direct, inverse, or cross) can be resolved
     */
    public function convert(float|string $amount, string $fromCurrency, string $toCurrency): ConversionResultDTO;

    /**
     * Get the current exchange rate between two currencies.
     *
     * Returns rate as a bcmath-precision string.
     * Semantics: 1 unit of $fromCurrency = {rate} units of $toCurrency.
     *
     * @throws CurrencyNotFoundException
     * @throws RateNotFoundException
     */
    public function getRate(string $fromCurrency, string $toCurrency): string;
}
