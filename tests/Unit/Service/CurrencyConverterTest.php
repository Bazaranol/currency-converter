<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Domain\Repository\CurrencyRepositoryInterface;
use App\Domain\Repository\ExchangeRateRepositoryInterface;
use App\Entity\Currency;
use App\Entity\ExchangeRate;
use App\Exception\ConversionException;
use App\Exception\CurrencyNotFoundException;
use App\Exception\RateNotFoundException;
use App\Service\CurrencyConverter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class CurrencyConverterTest extends TestCase
{
    private CurrencyRepositoryInterface&MockObject $currencyRepo;
    private ExchangeRateRepositoryInterface&MockObject $rateRepo;
    private CacheInterface&MockObject $cache;
    private CurrencyConverter $converter;

    private Currency $usd;
    private Currency $eur;
    private Currency $rub;

    protected function setUp(): void
    {
        $this->currencyRepo = $this->createMock(CurrencyRepositoryInterface::class);
        $this->rateRepo = $this->createMock(ExchangeRateRepositoryInterface::class);
        $this->cache = $this->createMock(CacheInterface::class);

        $this->converter = new CurrencyConverter(
            exchangeRateRepository: $this->rateRepo,
            currencyRepository: $this->currencyRepo,
            cache: $this->cache,
            logger: new NullLogger(),
            cacheTtl: 3600,
        );

        $this->usd = new Currency('USD', 'US Dollar', '$');
        $this->eur = new Currency('EUR', 'Euro', '€');
        $this->rub = new Currency('RUB', 'Russian Ruble', '₽');
    }

    // ── Same currency ───────────────────────────────────

    #[Test]
    public function same_currency_returns_same_amount(): void
    {
        $result = $this->converter->convert('100.50', 'USD', 'USD');

        self::assertSame('100.50', $result->convertedAmount);
        self::assertSame('USD', $result->originalCurrency);
        self::assertSame('USD', $result->targetCurrency);
        self::assertSame('1.0000000000', $result->rate);
    }

    // ── Direct rate conversion ──────────────────────────

    #[Test]
    public function converts_with_direct_rate(): void
    {
        $this->stubCurrencyLookup(['USD' => $this->usd, 'EUR' => $this->eur]);
        $this->stubCachePassthrough();
        $this->stubDirectRate($this->usd, $this->eur, '0.9200000000');

        $result = $this->converter->convert('100', 'USD', 'EUR');

        self::assertSame('92.00', $result->convertedAmount);
        self::assertSame('USD', $result->originalCurrency);
        self::assertSame('EUR', $result->targetCurrency);
    }

    #[Test]
    public function accepts_float_amount(): void
    {
        $this->stubCurrencyLookup(['USD' => $this->usd, 'EUR' => $this->eur]);
        $this->stubCachePassthrough();
        $this->stubDirectRate($this->usd, $this->eur, '0.9200000000');

        $result = $this->converter->convert(100.0, 'USD', 'EUR');

        self::assertSame('92.00', $result->convertedAmount);
    }

    // ── bcmath precision ────────────────────────────────

    #[Test]
    public function preserves_bcmath_precision(): void
    {
        $this->stubCurrencyLookup(['USD' => $this->usd, 'RUB' => $this->rub]);
        $this->stubCachePassthrough();
        $this->stubDirectRate($this->usd, $this->rub, '92.4567890123');

        $result = $this->converter->convert('1000.99', 'USD', 'RUB');

        // 1000.99 × 92.4567890123 = 92547.3264..., rounded to 2 places
        $expected = bcmul('1000.99', '92.4567890123', 10);
        $expected = bcadd($expected, '0', 2);

        self::assertSame($expected, $result->convertedAmount);
    }

    // ── Cross-rate ──────────────────────────────────────

    #[Test]
    public function calculates_cross_rate_through_pivot(): void
    {
        $this->stubCurrencyLookup([
            'EUR' => $this->eur,
            'RUB' => $this->rub,
            'USD' => $this->usd,
        ]);
        $this->stubCachePassthrough();

        // No direct EUR→RUB or RUB→EUR rate.
        // But USD→EUR = 0.92 and USD→RUB = 92.50 exist.
        $this->rateRepo->method('findLatestRate')
            ->willReturnCallback(function (Currency $base, Currency $target): ?ExchangeRate {
                $pair = $base->getCode() . '/' . $target->getCode();

                return match ($pair) {
                    'USD/EUR' => new ExchangeRate($base, $target, '0.9200000000', new \DateTimeImmutable()),
                    'USD/RUB' => new ExchangeRate($base, $target, '92.5000000000', new \DateTimeImmutable()),
                    default   => null,
                };
            });

        $result = $this->converter->convert('100', 'EUR', 'RUB');

        // Cross-rate: rate(EUR/RUB) = rate(USD/RUB) / rate(USD/EUR) = 92.50 / 0.92 ≈ 100.54
        $crossRate = bcdiv('92.5000000000', '0.9200000000', 10);
        $expected = bcmul('100', $crossRate, 10);
        $expected = bcadd($expected, '0', 2);

        self::assertSame($expected, $result->convertedAmount);
    }

    // ── getRate ─────────────────────────────────────────

    #[Test]
    public function get_rate_returns_one_for_same_currency(): void
    {
        $rate = $this->converter->getRate('USD', 'USD');

        self::assertSame('1.0000000000', $rate);
    }

    // ── Error cases ─────────────────────────────────────

    #[Test]
    public function throws_on_negative_amount(): void
    {
        $this->expectException(ConversionException::class);

        $this->converter->convert('-10', 'USD', 'EUR');
    }

    #[Test]
    public function throws_on_zero_amount(): void
    {
        $this->expectException(ConversionException::class);

        $this->converter->convert('0', 'USD', 'EUR');
    }

    #[Test]
    public function throws_on_non_numeric_amount(): void
    {
        $this->expectException(ConversionException::class);

        $this->converter->convert('abc', 'USD', 'EUR');
    }

    #[Test]
    public function throws_when_currency_not_found(): void
    {
        $this->currencyRepo->method('findByCode')->willReturn(null);

        $this->expectException(CurrencyNotFoundException::class);

        $this->converter->convert('100', 'USD', 'XYZ');
    }

    #[Test]
    public function throws_when_no_rate_exists(): void
    {
        $this->stubCurrencyLookup([
            'EUR' => $this->eur,
            'RUB' => $this->rub,
            'USD' => $this->usd,
        ]);
        $this->stubCachePassthrough();

        // No rates at all.
        $this->rateRepo->method('findLatestRate')->willReturn(null);

        $this->expectException(RateNotFoundException::class);

        $this->converter->convert('100', 'EUR', 'RUB');
    }

    // ── Test helpers ────────────────────────────────────

    /**
     * @param array<string, Currency> $map
     */
    private function stubCurrencyLookup(array $map): void
    {
        $this->currencyRepo->method('findByCode')
            ->willReturnCallback(fn (string $code): ?Currency => $map[strtoupper($code)] ?? null);
    }

    /**
     * Make cache->get() always call the callback (no caching in tests).
     */
    private function stubCachePassthrough(): void
    {
        $this->cache->method('get')
            ->willReturnCallback(function (string $key, callable $callback) {
                $item = $this->createMock(ItemInterface::class);
                return $callback($item);
            });

        $this->cache->method('delete')->willReturn(true);
    }

    private function stubDirectRate(Currency $base, Currency $target, string $rate): void
    {
        $this->rateRepo->method('findLatestRate')
            ->willReturnCallback(function (Currency $b, Currency $t) use ($base, $target, $rate): ?ExchangeRate {
                if ($b->getCode() === $base->getCode() && $t->getCode() === $target->getCode()) {
                    return new ExchangeRate($b, $t, $rate, new \DateTimeImmutable());
                }
                return null;
            });
    }
}
