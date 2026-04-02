<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Repository\CurrencyRepositoryInterface;
use App\Domain\Repository\ExchangeRateRepositoryInterface;
use App\DTO\ConversionResultDTO;
use App\Entity\Currency;
use App\Exception\ConversionException;
use App\Exception\CurrencyNotFoundException;
use App\Exception\RateNotFoundException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class CurrencyConverter implements CurrencyConverterInterface
{
    /**
     * bcmath scale for all intermediate calculations.
     * Kept high to avoid accumulated rounding errors in cross-rates.
     */
    private const CALC_SCALE = 10;

    /** Decimal places in the final converted amount. */
    private const RESULT_SCALE = 2;

    /** The pivot currency used for cross-rate calculation (FROM → PIVOT → TO). */
    private const CROSS_RATE_PIVOT = 'USD';

    /**
     * Short TTL for "not found" cache markers.
     * Prevents DB hammering when a pair genuinely doesn't exist,
     * while keeping the window small so newly added rates appear quickly.
     */
    private const NULL_MARKER_TTL = 60;

    /**
     * Sentinel value cached when a DB lookup returns nothing.
     * Distinguishes "we checked, it's missing" from "cache miss".
     */
    private const NULL_MARKER = '__NULL__';

    public function __construct(
        private readonly ExchangeRateRepositoryInterface $exchangeRateRepository,
        private readonly CurrencyRepositoryInterface $currencyRepository,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly int $cacheTtl = 86_400,
    ) {
    }

    // ═══════════════════════════════════════════════════════
    //  Public API
    // ═══════════════════════════════════════════════════════

    public function convert(float|string $amount, string $fromCurrency, string $toCurrency): ConversionResultDTO
    {
        $amount = $this->normalizeAmount($amount);
        $from = strtoupper(trim($fromCurrency));
        $to = strtoupper(trim($toCurrency));

        // Same currency — short circuit. No DB/cache hit needed.
        if ($from === $to) {
            return $this->buildResult(
                amount: $amount,
                from: $from,
                to: $to,
                rate: '1.0000000000',
            );
        }

        $rate = $this->getRate($from, $to);

        // Core conversion: converted = amount × rate
        $convertedRaw = bcmul($amount, $rate, self::CALC_SCALE);
        $converted = bcadd($convertedRaw, '0', self::RESULT_SCALE);

        return $this->buildResult(
            amount: $amount,
            from: $from,
            to: $to,
            rate: $rate,
            converted: $converted,
        );
    }

    public function getRate(string $fromCurrency, string $toCurrency): string
    {
        $from = strtoupper(trim($fromCurrency));
        $to = strtoupper(trim($toCurrency));

        if ($from === $to) {
            return '1.0000000000';
        }

        // Validate that both currencies exist in the system.
        $baseEntity = $this->resolveCurrency($from);
        $targetEntity = $this->resolveCurrency($to);

        // Strategy 1: Direct rate (FROM → TO).
        $direct = $this->findRate($baseEntity, $targetEntity);
        if ($direct !== null) {
            return $direct;
        }

        // Strategy 2: Inverse rate (TO → FROM), then invert.
        $inverse = $this->findRate($targetEntity, $baseEntity);
        if ($inverse !== null) {
            $this->guardAgainstZero($inverse, $from, $to);
            $rate = bcdiv('1', $inverse, self::CALC_SCALE);
            $this->storeInCache($from, $to, $rate);

            return $rate;
        }

        // Strategy 3: Cross-rate through the pivot currency.
        return $this->calculateCrossRate($from, $to, $baseEntity, $targetEntity);
    }

    // ═══════════════════════════════════════════════════════
    //  Rate resolution (cache → DB)
    // ═══════════════════════════════════════════════════════

    /**
     * Look up a direct rate: cache first, then DB.
     *
     * Returns null if neither has the pair — the caller decides
     * whether to try inverse or cross-rate.
     */
    private function findRate(Currency $base, Currency $target): ?string
    {
        $cacheKey = self::cacheKey($base->getCode(), $target->getCode());

        $cached = $this->cache->get($cacheKey, function (ItemInterface $item) use ($base, $target): string {
            $exchangeRate = $this->exchangeRateRepository->findLatestRate($base, $target);

            if ($exchangeRate === null) {
                // Cache a short-lived marker so we don't hit the DB again immediately.
                $item->expiresAfter(self::NULL_MARKER_TTL);

                return self::NULL_MARKER;
            }

            $item->expiresAfter($this->cacheTtl);

            $this->logger->debug('Rate loaded from DB and cached.', [
                'pair' => $exchangeRate->getPairCode(),
                'rate' => $exchangeRate->getRate(),
            ]);

            return $exchangeRate->getRate();
        });

        return $cached === self::NULL_MARKER ? null : $cached;
    }

    // ═══════════════════════════════════════════════════════
    //  Cross-rate calculation
    // ═══════════════════════════════════════════════════════

    /**
     * Calculate: FROM → PIVOT → TO.
     *
     * Mathematical basis:
     *   rate(FROM/TO) = rate(PIVOT/TO) / rate(PIVOT/FROM)
     *
     * Example: EUR → RUB via USD
     *   rate(USD/RUB) = 92.50
     *   rate(USD/EUR) = 0.92
     *   rate(EUR/RUB) = 92.50 / 0.92 ≈ 100.54
     */
    private function calculateCrossRate(
        string $fromCode,
        string $toCode,
        Currency $fromEntity,
        Currency $toEntity,
    ): string {
        $pivot = $this->resolveCurrency(self::CROSS_RATE_PIVOT);

        $pivotToFrom = $this->resolveRateInAnyDirection($pivot, $fromEntity, $fromCode);
        $pivotToTarget = $this->resolveRateInAnyDirection($pivot, $toEntity, $toCode);

        $this->guardAgainstZero($pivotToFrom, $fromCode, $toCode);

        // rate(FROM/TO) = rate(PIVOT/TO) / rate(PIVOT/FROM)
        $crossRate = bcdiv($pivotToTarget, $pivotToFrom, self::CALC_SCALE);

        $this->storeInCache($fromCode, $toCode, $crossRate);

        $this->logger->info('Cross-rate calculated.', [
            'pair'       => "{$fromCode}/{$toCode}",
            'cross_rate' => $crossRate,
            'pivot'      => self::CROSS_RATE_PIVOT,
        ]);

        return $crossRate;
    }

    /**
     * Try PIVOT → TARGET direct, then TARGET → PIVOT inverted.
     * Throws RateNotFoundException if neither direction exists.
     */
    private function resolveRateInAnyDirection(
        Currency $pivot,
        Currency $targetEntity,
        string $targetCode,
    ): string {
        // Direct: PIVOT → TARGET
        $rate = $this->findRate($pivot, $targetEntity);
        if ($rate !== null) {
            return $rate;
        }

        // Inverse: TARGET → PIVOT, then invert.
        $inverse = $this->findRate($targetEntity, $pivot);
        if ($inverse !== null) {
            $this->guardAgainstZero($inverse, $targetCode, self::CROSS_RATE_PIVOT);

            return bcdiv('1', $inverse, self::CALC_SCALE);
        }

        throw RateNotFoundException::forPair(self::CROSS_RATE_PIVOT, $targetCode);
    }

    // ═══════════════════════════════════════════════════════
    //  Input validation & normalization
    // ═══════════════════════════════════════════════════════

    /**
     * Normalize input to a valid bcmath string.
     * Accepts float (converted without locale issues) and numeric strings.
     */
    private function normalizeAmount(float|string $amount): string
    {
        if (is_float($amount)) {
            $amount = number_format($amount, self::CALC_SCALE, '.', '');
        }

        $amount = trim((string) $amount);

        if (!is_numeric($amount)) {
            throw ConversionException::invalidAmount($amount);
        }

        if (bccomp($amount, '0', self::CALC_SCALE) <= 0) {
            throw ConversionException::invalidAmount($amount);
        }

        return $amount;
    }

    /**
     * Fetch Currency entity by code or throw.
     */
    private function resolveCurrency(string $code): Currency
    {
        $currency = $this->currencyRepository->findByCode($code);

        if ($currency === null) {
            throw CurrencyNotFoundException::withCode($code);
        }

        return $currency;
    }

    // ═══════════════════════════════════════════════════════
    //  Cache helpers
    // ═══════════════════════════════════════════════════════

    /**
     * Write a computed rate into the cache (for inverse / cross-rate results).
     *
     * Uses delete-then-get because Symfony CacheInterface's get() is
     * "compute-if-absent" — it won't overwrite an existing entry.
     */
    private function storeInCache(string $from, string $to, string $rate): void
    {
        $key = self::cacheKey($from, $to);
        $ttl = $this->cacheTtl;

        $this->cache->delete($key);
        $this->cache->get($key, static function (ItemInterface $item) use ($rate, $ttl): string {
            $item->expiresAfter($ttl);

            return $rate;
        });
    }

    private static function cacheKey(string $from, string $to): string
    {
        return sprintf('exchange_rate.%s.%s', $from, $to);
    }

    // ═══════════════════════════════════════════════════════
    //  Guards
    // ═══════════════════════════════════════════════════════

    private function guardAgainstZero(string $rate, string $from, string $to): void
    {
        if (bccomp($rate, '0', self::CALC_SCALE) === 0) {
            throw ConversionException::zeroDivision($from, $to);
        }
    }

    // ═══════════════════════════════════════════════════════
    //  Result builder
    // ═══════════════════════════════════════════════════════

    private function buildResult(
        string $amount,
        string $from,
        string $to,
        string $rate,
        ?string $converted = null,
    ): ConversionResultDTO {
        return new ConversionResultDTO(
            originalAmount:  $amount,
            originalCurrency: $from,
            convertedAmount: $converted ?? $amount,
            targetCurrency:  $to,
            rate:            $rate,
            convertedAt:     new \DateTimeImmutable(),
        );
    }
}
