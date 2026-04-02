<?php

declare(strict_types=1);

namespace App\Infrastructure\ExternalApi;

use App\Domain\Service\ExchangeRateProviderInterface;
use App\DTO\ExchangeRateDTO;
use App\Exception\ExchangeRateApiException;
use Psr\Log\LoggerInterface;

/**
 * Adapter: translates FreeCurrencyApi raw response into domain DTOs.
 *
 * This class is the only place that knows the shape of the
 * freecurrencyapi.com response. If you switch to a different API,
 * create a new class implementing ExchangeRateProviderInterface
 * and rebind it in services.yaml.
 */
final class FreeCurrencyApiProvider implements ExchangeRateProviderInterface
{
    public function __construct(
        private readonly FreeCurrencyApiClient $client,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function fetchRates(string $baseCurrency, array $targetCurrencies): array
    {
        if (count($targetCurrencies) === 0) {
            return [];
        }

        $baseCurrency = strtoupper(trim($baseCurrency));
        $targetCurrencies = array_map(
            fn (string $code): string => strtoupper(trim($code)),
            $targetCurrencies,
        );

        // Remove base currency from targets if accidentally included.
        $targetCurrencies = array_values(
            array_filter($targetCurrencies, fn (string $c): bool => $c !== $baseCurrency),
        );

        if (count($targetCurrencies) === 0) {
            return [];
        }

        // ── Call the HTTP client ────────────────────────
        $rawRates = $this->client->getLatestRates($baseCurrency, $targetCurrencies);

        // ── Map raw data → DTOs ─────────────────────────
        $fetchedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $dtos = [];
        $skipped = [];

        foreach ($targetCurrencies as $targetCode) {
            if (!isset($rawRates[$targetCode])) {
                $skipped[] = $targetCode;
                continue;
            }

            $rateValue = $rawRates[$targetCode];

            // Validate rate: must be numeric and positive.
            if (!is_numeric($rateValue)) {
                $this->logger->warning('Non-numeric rate received from API.', [
                    'pair'  => "{$baseCurrency}/{$targetCode}",
                    'value' => $rateValue,
                ]);
                $skipped[] = $targetCode;
                continue;
            }

            $rateString = $this->toStringRate($rateValue);

            if (bccomp($rateString, '0', 10) <= 0) {
                $this->logger->warning('Zero or negative rate received from API.', [
                    'pair'  => "{$baseCurrency}/{$targetCode}",
                    'value' => $rateString,
                ]);
                $skipped[] = $targetCode;
                continue;
            }

            $dtos[] = new ExchangeRateDTO(
                baseCurrency: $baseCurrency,
                targetCurrency: $targetCode,
                rate: $rateString,
                fetchedAt: $fetchedAt,
            );
        }

        // ── Log missing currencies ──────────────────────
        if (count($skipped) > 0) {
            $this->logger->warning('Some currencies were not returned or had invalid rates.', [
                'base'    => $baseCurrency,
                'missing' => $skipped,
                'fetched' => count($dtos),
            ]);
        }

        if (count($dtos) === 0) {
            throw ExchangeRateApiException::invalidResponse(
                sprintf(
                    'API returned no valid rates for base=%s, requested=%s',
                    $baseCurrency,
                    implode(',', $targetCurrencies),
                ),
            );
        }

        $this->logger->info('Exchange rates fetched successfully.', [
            'base'    => $baseCurrency,
            'count'   => count($dtos),
            'skipped' => count($skipped),
        ]);

        return $dtos;
    }

    /**
     * Convert a numeric value (float/int/string) to a bcmath-safe string
     * with 10 decimal places, avoiding float precision issues.
     */
    private function toStringRate(mixed $value): string
    {
        if (is_float($value)) {
            // number_format avoids scientific notation and locale issues.
            return number_format($value, 10, '.', '');
        }

        // int or string — cast and normalize through bcadd.
        return bcadd((string) $value, '0', 10);
    }
}
