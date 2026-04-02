<?php

declare(strict_types=1);

namespace App\Infrastructure\ExternalApi;

use App\Exception\ExchangeRateApiException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use Psr\Log\LoggerInterface;

/**
 * Low-level HTTP client for freecurrencyapi.com.
 *
 * Responsibilities:
 *   - Build request URL with query parameters
 *   - Send HTTP request via Guzzle
 *   - Map HTTP errors to domain exceptions
 *   - Log every request with timing
 *
 * This class knows nothing about DTOs or Entities — it returns
 * the raw decoded JSON array. The provider adapter handles mapping.
 */
class FreeCurrencyApiClient
{
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * GET /v1/latest — fetch latest exchange rates.
     *
     * @param string   $baseCurrency ISO 4217 code
     * @param string[] $currencies   Target currency codes
     *
     * @return array<string, float|int|string> Currency code => rate pairs
     *                                         e.g. ["EUR" => 0.9213, "GBP" => 0.7903]
     *
     * @throws ExchangeRateApiException
     */
    public function getLatestRates(string $baseCurrency, array $currencies): array
    {
        $url = rtrim($this->baseUrl, '/') . '/latest';

        $queryParams = [
            'apikey'        => $this->apiKey,
            'base_currency' => strtoupper($baseCurrency),
        ];

        if (count($currencies) > 0) {
            $queryParams['currencies'] = implode(',', array_map('strtoupper', $currencies));
        }

        $startTime = microtime(true);
        $statusCode = 0;

        try {
            $response = $this->httpClient->request('GET', $url, [
                'query'           => $queryParams,
                'timeout'         => 10,
                'connect_timeout' => 5,
                'http_errors'     => false,
            ]);

            $statusCode = $response->getStatusCode();
            $body = (string) $response->getBody();

            $this->logRequest($url, $baseCurrency, $statusCode, $startTime);

            // ── Handle HTTP errors ──────────────────────
            $this->guardHttpStatus($statusCode, $body);

            // ── Parse JSON ──────────────────────────────
            return $this->decodeResponse($body);

        } catch (ExchangeRateApiException $e) {
            // Re-throw domain exceptions as-is.
            throw $e;

        } catch (ConnectException $e) {
            $elapsed = $this->elapsed($startTime);

            // ConnectException covers both timeouts and DNS/network failures.
            if (str_contains($e->getMessage(), 'timed out')) {
                $this->logger->error('FreeCurrencyAPI request timed out.', [
                    'url'     => $url,
                    'elapsed' => $elapsed,
                ]);
                throw ExchangeRateApiException::timeout($e);
            }

            $this->logger->error('FreeCurrencyAPI connection failed.', [
                'url'     => $url,
                'error'   => $e->getMessage(),
                'elapsed' => $elapsed,
            ]);
            throw ExchangeRateApiException::networkError($e->getMessage(), $e);

        } catch (RequestException $e) {
            $this->logRequest($url, $baseCurrency, $statusCode, $startTime);

            if ($e->hasResponse()) {
                $resp = $e->getResponse();
                $this->guardHttpStatus($resp->getStatusCode(), (string) $resp->getBody());
            }

            throw ExchangeRateApiException::networkError($e->getMessage(), $e);

        } catch (TransferException $e) {
            $this->logger->error('FreeCurrencyAPI transfer error.', [
                'error'   => $e->getMessage(),
                'elapsed' => $this->elapsed($startTime),
            ]);
            throw ExchangeRateApiException::networkError($e->getMessage(), $e);
        }
    }

    // ── Private helpers ─────────────────────────────────

    /**
     * Map HTTP status codes to specific domain exceptions.
     *
     * @throws ExchangeRateApiException
     */
    private function guardHttpStatus(int $statusCode, string $body): void
    {
        if ($statusCode >= 200 && $statusCode < 300) {
            return;
        }

        match (true) {
            $statusCode === 401, $statusCode === 403
                => throw ExchangeRateApiException::invalidApiKey(),

            $statusCode === 429
                => throw ExchangeRateApiException::rateLimitExceeded(),

            $statusCode === 422
                => throw ExchangeRateApiException::invalidResponse(
                    'Unprocessable request — check currency codes. Response: ' . mb_substr($body, 0, 200),
                ),

            $statusCode >= 500
                => throw ExchangeRateApiException::serverError($statusCode, $body),

            default
                => throw ExchangeRateApiException::httpError($statusCode, $body),
        };
    }

    /**
     * Decode the JSON body and extract the "data" key.
     *
     * @return array<string, float|int|string>
     *
     * @throws ExchangeRateApiException
     */
    private function decodeResponse(string $body): array
    {
        try {
            $decoded = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw ExchangeRateApiException::invalidResponse(
                'Invalid JSON: ' . $e->getMessage(),
                $e,
            );
        }

        if (!is_array($decoded) || !isset($decoded['data']) || !is_array($decoded['data'])) {
            throw ExchangeRateApiException::invalidResponse(
                'Response missing "data" key or "data" is not an object.',
            );
        }

        return $decoded['data'];
    }

    private function logRequest(string $url, string $baseCurrency, int $statusCode, float $startTime): void
    {
        $this->logger->info('FreeCurrencyAPI request completed.', [
            'url'           => $url,
            'base_currency' => $baseCurrency,
            'status'        => $statusCode,
            'elapsed_ms'    => $this->elapsed($startTime),
        ]);
    }

    private function elapsed(float $startTime): float
    {
        return round((microtime(true) - $startTime) * 1000, 1);
    }
}
