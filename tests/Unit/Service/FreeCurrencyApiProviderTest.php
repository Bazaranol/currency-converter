<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Exception\ExchangeRateApiException;
use App\Infrastructure\ExternalApi\FreeCurrencyApiClient;
use App\Infrastructure\ExternalApi\FreeCurrencyApiProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class FreeCurrencyApiProviderTest extends TestCase
{
    private FreeCurrencyApiClient&MockObject $client;
    private FreeCurrencyApiProvider $provider;

    protected function setUp(): void
    {
        $this->client = $this->createMock(FreeCurrencyApiClient::class);
        $this->provider = new FreeCurrencyApiProvider(
            client: $this->client,
            logger: new NullLogger(),
        );
    }

    #[Test]
    public function parses_valid_api_response(): void
    {
        $this->client->method('getLatestRates')
            ->willReturn([
                'EUR' => 0.9213,
                'GBP' => 0.7903,
                'RUB' => 89.4532,
            ]);

        $dtos = $this->provider->fetchRates('USD', ['EUR', 'GBP', 'RUB']);

        self::assertCount(3, $dtos);

        self::assertSame('USD', $dtos[0]->baseCurrency);
        self::assertSame('EUR', $dtos[0]->targetCurrency);
        self::assertSame('0.9213000000', $dtos[0]->rate);

        self::assertSame('GBP', $dtos[1]->targetCurrency);
        self::assertSame('RUB', $dtos[2]->targetCurrency);
    }

    #[Test]
    public function handles_partial_response_gracefully(): void
    {
        // API returns EUR but not GBP.
        $this->client->method('getLatestRates')
            ->willReturn(['EUR' => 0.9213]);

        $dtos = $this->provider->fetchRates('USD', ['EUR', 'GBP']);

        self::assertCount(1, $dtos);
        self::assertSame('EUR', $dtos[0]->targetCurrency);
    }

    #[Test]
    public function skips_non_numeric_rates(): void
    {
        $this->client->method('getLatestRates')
            ->willReturn([
                'EUR' => 0.92,
                'GBP' => 'invalid',
            ]);

        $dtos = $this->provider->fetchRates('USD', ['EUR', 'GBP']);

        self::assertCount(1, $dtos);
        self::assertSame('EUR', $dtos[0]->targetCurrency);
    }

    #[Test]
    public function skips_zero_and_negative_rates(): void
    {
        $this->client->method('getLatestRates')
            ->willReturn([
                'EUR' => 0.92,
                'GBP' => 0,
                'RUB' => -1.5,
            ]);

        $dtos = $this->provider->fetchRates('USD', ['EUR', 'GBP', 'RUB']);

        self::assertCount(1, $dtos);
    }

    #[Test]
    public function throws_when_all_rates_invalid(): void
    {
        $this->client->method('getLatestRates')
            ->willReturn(['EUR' => 'bad', 'GBP' => 0]);

        $this->expectException(ExchangeRateApiException::class);
        $this->expectExceptionMessageMatches('/no valid rates/i');

        $this->provider->fetchRates('USD', ['EUR', 'GBP']);
    }

    #[Test]
    public function returns_empty_when_no_targets(): void
    {
        $dtos = $this->provider->fetchRates('USD', []);

        self::assertCount(0, $dtos);
    }

    #[Test]
    public function removes_base_from_targets(): void
    {
        // If USD is in targets, it should be filtered out.
        $dtos = $this->provider->fetchRates('USD', ['USD']);

        self::assertCount(0, $dtos);
    }

    #[Test]
    public function normalizes_currency_codes_to_uppercase(): void
    {
        $this->client->method('getLatestRates')
            ->willReturn(['EUR' => 0.92]);

        $dtos = $this->provider->fetchRates('usd', ['eur']);

        self::assertCount(1, $dtos);
        self::assertSame('USD', $dtos[0]->baseCurrency);
        self::assertSame('EUR', $dtos[0]->targetCurrency);
    }

    #[Test]
    public function propagates_client_exceptions(): void
    {
        $this->client->method('getLatestRates')
            ->willThrowException(ExchangeRateApiException::timeout());

        $this->expectException(ExchangeRateApiException::class);

        $this->provider->fetchRates('USD', ['EUR']);
    }
}
