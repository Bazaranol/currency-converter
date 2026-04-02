<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Application\Event\RatesSyncFailedEvent;
use App\Application\Event\RatesUpdatedEvent;
use App\Domain\Repository\CurrencyRepositoryInterface;
use App\Domain\Repository\ExchangeRateRepositoryInterface;
use App\Domain\Service\ExchangeRateProviderInterface;
use App\DTO\ExchangeRateDTO;
use App\Entity\Currency;
use App\Exception\ExchangeRateApiException;
use App\Service\ExchangeRateSyncService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class ExchangeRateSyncServiceTest extends TestCase
{
    private ExchangeRateProviderInterface&MockObject $provider;
    private ExchangeRateRepositoryInterface&MockObject $rateRepo;
    private CurrencyRepositoryInterface&MockObject $currencyRepo;
    private CacheInterface&MockObject $cache;
    private EventDispatcherInterface&MockObject $dispatcher;
    private ExchangeRateSyncService $service;

    private Currency $usd;
    private Currency $eur;
    private Currency $gbp;

    protected function setUp(): void
    {
        $this->provider = $this->createMock(ExchangeRateProviderInterface::class);
        $this->rateRepo = $this->createMock(ExchangeRateRepositoryInterface::class);
        $this->currencyRepo = $this->createMock(CurrencyRepositoryInterface::class);
        $this->cache = $this->createMock(CacheInterface::class);
        $this->dispatcher = $this->createMock(EventDispatcherInterface::class);

        $this->service = new ExchangeRateSyncService(
            rateProvider: $this->provider,
            exchangeRateRepository: $this->rateRepo,
            currencyRepository: $this->currencyRepo,
            cache: $this->cache,
            eventDispatcher: $this->dispatcher,
            logger: new NullLogger(),
            baseCurrencyCode: 'USD',
        );

        $this->usd = new Currency('USD', 'US Dollar', '$');
        $this->eur = new Currency('EUR', 'Euro', '€');
        $this->gbp = new Currency('GBP', 'British Pound', '£');
    }

    // ── Successful sync ─────────────────────────────────

    #[Test]
    public function syncs_rates_successfully(): void
    {
        $this->currencyRepo->method('findAllActive')
            ->willReturn([$this->usd, $this->eur, $this->gbp]);

        $now = new \DateTimeImmutable();
        $this->provider->method('fetchRates')
            ->with('USD', ['EUR', 'GBP'])
            ->willReturn([
                new ExchangeRateDTO('USD', 'EUR', '0.9200000000', $now),
                new ExchangeRateDTO('USD', 'GBP', '0.7900000000', $now),
            ]);

        $this->rateRepo->expects(self::once())
            ->method('saveMany')
            ->with(self::callback(fn (array $rates) => count($rates) === 2));

        // Cache invalidation: 2 pairs × 2 directions = 4 deletes.
        $this->cache->expects(self::exactly(4))->method('delete');

        // Success event dispatched.
        $this->dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(RatesUpdatedEvent::class));

        $result = $this->service->sync();

        self::assertSame(2, $result->updatedCount);
        self::assertSame(0, $result->errorCount);
        self::assertTrue($result->isFullSuccess());
    }

    // ── API failure ─────────────────────────────────────

    #[Test]
    public function preserves_existing_rates_on_api_failure(): void
    {
        $this->currencyRepo->method('findAllActive')
            ->willReturn([$this->usd, $this->eur]);

        $this->provider->method('fetchRates')
            ->willThrowException(ExchangeRateApiException::timeout());

        // saveMany must NOT be called — we don't delete old data.
        $this->rateRepo->expects(self::never())->method('saveMany');

        // Failure event dispatched.
        $this->dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(RatesSyncFailedEvent::class));

        $result = $this->service->sync();

        self::assertSame(0, $result->updatedCount);
        self::assertGreaterThan(0, $result->errorCount);
        self::assertFalse($result->isFullSuccess());
    }

    // ── No active currencies ────────────────────────────

    #[Test]
    public function returns_empty_result_when_no_currencies(): void
    {
        $this->currencyRepo->method('findAllActive')->willReturn([]);

        $this->provider->expects(self::never())->method('fetchRates');

        $result = $this->service->sync();

        self::assertSame(0, $result->updatedCount);
        self::assertSame(0, $result->errorCount);
    }

    // ── Only base currency active ───────────────────────

    #[Test]
    public function returns_empty_when_only_base_currency_active(): void
    {
        $this->currencyRepo->method('findAllActive')
            ->willReturn([$this->usd]);

        $this->provider->expects(self::never())->method('fetchRates');

        $result = $this->service->sync();

        self::assertSame(0, $result->updatedCount);
    }

    // ── Partial API response ────────────────────────────

    #[Test]
    public function handles_partial_api_response(): void
    {
        $this->currencyRepo->method('findAllActive')
            ->willReturn([$this->usd, $this->eur, $this->gbp]);

        $now = new \DateTimeImmutable();
        // API returns only EUR, skips GBP.
        $this->provider->method('fetchRates')
            ->willReturn([
                new ExchangeRateDTO('USD', 'EUR', '0.9200000000', $now),
            ]);

        $this->rateRepo->expects(self::once())
            ->method('saveMany')
            ->with(self::callback(fn (array $rates) => count($rates) === 1));

        $this->dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(RatesUpdatedEvent::class));

        $result = $this->service->sync();

        self::assertSame(1, $result->updatedCount);
        self::assertGreaterThan(0, $result->skippedCount);
    }
}
