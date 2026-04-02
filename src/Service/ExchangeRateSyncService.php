<?php

declare(strict_types=1);

namespace App\Service;

use App\Application\Event\RatesSyncFailedEvent;
use App\Application\Event\RatesUpdatedEvent;
use App\Domain\Repository\CurrencyRepositoryInterface;
use App\Domain\Repository\ExchangeRateRepositoryInterface;
use App\Domain\Service\ExchangeRateProviderInterface;
use App\DTO\ExchangeRateDTO;
use App\DTO\SyncResultDTO;
use App\Entity\Currency;
use App\Entity\ExchangeRate;
use App\Exception\ExchangeRateApiException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class ExchangeRateSyncService
{
    public function __construct(
        private readonly ExchangeRateProviderInterface $rateProvider,
        private readonly ExchangeRateRepositoryInterface $exchangeRateRepository,
        private readonly CurrencyRepositoryInterface $currencyRepository,
        private readonly CacheInterface $cache,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
        private readonly string $baseCurrencyCode = 'USD',
    ) {
    }

    /**
     * Synchronise exchange rates from the external provider.
     *
     * @param string|null $baseCurrency Override the default base currency (ISO 4217).
     *                                  When null, uses the configured default.
     *
     * Guarantees:
     *  - Existing rates are NEVER deleted — if the API fails, stale
     *    data remains available for reads.
     *  - Partial success is handled: currencies the API skips are
     *    logged as warnings but don't block the rest.
     *  - Cache entries for every persisted pair (both directions)
     *    are invalidated so the next read picks up fresh values.
     */
    public function sync(?string $baseCurrency = null): SyncResultDTO
    {
        $baseCurrency = $baseCurrency ?? $this->baseCurrencyCode;
        $syncedAt = new \DateTimeImmutable();

        // ── 1. Load active currencies ────────────────────
        $currencies = $this->currencyRepository->findAllActive();

        if (\count($currencies) === 0) {
            $this->logger->warning('Sync skipped: no active currencies in the database.');

            return $this->emptyResult($syncedAt);
        }

        $currencyMap = $this->buildCurrencyMap($currencies);
        $targetCodes = $this->targetCodes($currencyMap, $baseCurrency);

        if (\count($targetCodes) === 0) {
            $this->logger->warning('Sync skipped: only the base currency is active.');

            return $this->emptyResult($syncedAt);
        }

        // ── 2. Fetch rates from the external API ─────────
        try {
            $rateDtos = $this->rateProvider->fetchRates(
                $baseCurrency,
                $targetCodes,
            );
        } catch (ExchangeRateApiException $e) {
            return $this->handleApiFailed($e, $targetCodes, $syncedAt);
        }

        // ── 3. Map DTOs → entities, collect stats ────────
        [$entities, $skipped, $errors] = $this->mapDtosToEntities($rateDtos, $currencyMap);

        // Detect currencies the API didn't return at all.
        $missingCodes = $this->detectMissing($rateDtos, $targetCodes);
        if (\count($missingCodes) > 0) {
            $skipped += \count($missingCodes);
            $this->logger->warning('API did not return rates for some currencies.', [
                'missing' => $missingCodes,
            ]);
        }

        // ── 4. Persist to DB ─────────────────────────────
        if (\count($entities) > 0) {
            $this->exchangeRateRepository->saveMany($entities);
        }

        // ── 5. Invalidate cache ──────────────────────────
        $this->invalidateCache($entities);

        // ── 6. Dispatch success event ────────────────────
        $this->eventDispatcher->dispatch(new RatesUpdatedEvent(
            ratesCount:   \count($entities),
            skippedCount: $skipped,
            providerName: $this->rateProvider::class,
            syncedAt:     $syncedAt,
        ));

        $this->logger->info('Exchange rates sync completed.', [
            'updated' => \count($entities),
            'skipped' => $skipped,
            'errors'  => \count($errors),
        ]);

        return new SyncResultDTO(
            updatedCount: \count($entities),
            skippedCount: $skipped,
            errorCount:   \count($errors),
            errors:       $errors,
            syncedAt:     $syncedAt,
        );
    }

    // ═══════════════════════════════════════════════════════
    //  Private helpers
    // ═══════════════════════════════════════════════════════

    /**
     * Handle total API failure: log, dispatch event, return stats.
     * Existing rates in DB remain untouched.
     */
    private function handleApiFailed(
        ExchangeRateApiException $e,
        array $targetCodes,
        \DateTimeImmutable $syncedAt,
    ): SyncResultDTO {
        $this->logger->error('API call failed during sync. Existing rates preserved.', [
            'error' => $e->getMessage(),
        ]);

        $this->eventDispatcher->dispatch(new RatesSyncFailedEvent(
            reason:    $e->getMessage(),
            exception: $e,
            failedAt:  $syncedAt,
        ));

        return new SyncResultDTO(
            updatedCount: 0,
            skippedCount: 0,
            errorCount:   \count($targetCodes),
            errors:       ['API unavailable: ' . $e->getMessage()],
            syncedAt:     $syncedAt,
        );
    }

    /**
     * Convert ExchangeRateDTO[] into ExchangeRate entities.
     * Malformed DTOs are skipped with a warning, not thrown.
     *
     * @param ExchangeRateDTO[]      $dtos
     * @param array<string, Currency> $currencyMap
     * @return array{ExchangeRate[], int, string[]}  [entities, skippedCount, errorMessages]
     */
    private function mapDtosToEntities(array $dtos, array $currencyMap): array
    {
        $entities = [];
        $errors   = [];
        $skipped  = 0;

        foreach ($dtos as $dto) {
            try {
                $base   = $currencyMap[$dto->baseCurrency] ?? null;
                $target = $currencyMap[$dto->targetCurrency] ?? null;

                if ($base === null) {
                    throw new \RuntimeException(
                        sprintf('Base currency "%s" not found in active currencies.', $dto->baseCurrency),
                    );
                }

                if ($target === null) {
                    throw new \RuntimeException(
                        sprintf('Target currency "%s" not found in active currencies.', $dto->targetCurrency),
                    );
                }

                $entities[] = new ExchangeRate(
                    baseCurrency:   $base,
                    targetCurrency: $target,
                    rate:           $dto->rate,
                    fetchedAt:      $dto->fetchedAt,
                );
            } catch (\Throwable $e) {
                $skipped++;
                $errors[] = sprintf(
                    '%s→%s: %s',
                    $dto->baseCurrency,
                    $dto->targetCurrency,
                    $e->getMessage(),
                );

                $this->logger->warning('Skipped rate during sync.', [
                    'pair'   => "{$dto->baseCurrency}/{$dto->targetCurrency}",
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        return [$entities, $skipped, $errors];
    }

    /**
     * Find target currencies the API didn't return.
     *
     * @param ExchangeRateDTO[] $dtos
     * @param string[]          $expectedCodes
     * @return string[]
     */
    private function detectMissing(array $dtos, array $expectedCodes): array
    {
        $returned = array_map(
            static fn (ExchangeRateDTO $dto): string => $dto->targetCurrency,
            $dtos,
        );

        return array_values(array_diff($expectedCodes, $returned));
    }

    /**
     * Invalidate cache for every saved pair in both directions.
     *
     * Both FROM→TO and TO→FROM are cleared because the converter
     * may have cached an inverse or cross-rate derived from this pair.
     *
     * @param ExchangeRate[] $rates
     */
    private function invalidateCache(array $rates): void
    {
        $cleared = 0;

        foreach ($rates as $rate) {
            $base   = $rate->getBaseCurrency()->getCode();
            $target = $rate->getTargetCurrency()->getCode();

            $this->cache->delete(sprintf('exchange_rate.%s.%s', $base, $target));
            $this->cache->delete(sprintf('exchange_rate.%s.%s', $target, $base));
            $cleared += 2;
        }

        $this->logger->debug('Cache invalidated after sync.', [
            'keys_cleared' => $cleared,
        ]);
    }

    /**
     * @param Currency[] $currencies
     * @return array<string, Currency>
     */
    private function buildCurrencyMap(array $currencies): array
    {
        $map = [];
        foreach ($currencies as $currency) {
            $map[$currency->getCode()] = $currency;
        }

        return $map;
    }

    /**
     * All active codes except the base currency.
     *
     * @param array<string, Currency> $map
     * @return string[]
     */
    private function targetCodes(array $map, string $baseCurrency): array
    {
        return array_values(
            array_filter(
                array_keys($map),
                fn (string $code): bool => $code !== $baseCurrency,
            ),
        );
    }

    private function emptyResult(\DateTimeImmutable $syncedAt): SyncResultDTO
    {
        return new SyncResultDTO(
            updatedCount: 0,
            skippedCount: 0,
            errorCount:   0,
            errors:       [],
            syncedAt:     $syncedAt,
        );
    }
}
