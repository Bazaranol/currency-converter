<?php

declare(strict_types=1);

namespace App\Exception;

final class ExchangeRateApiException extends \RuntimeException
{
    public static function invalidApiKey(): self
    {
        return new self('Invalid API key. Check the FREECURRENCY_API_KEY environment variable.');
    }

    public static function rateLimitExceeded(): self
    {
        return new self('API rate limit exceeded. Try again later.');
    }

    public static function serverError(int $statusCode, string $body): self
    {
        return new self(
            sprintf('API server error (HTTP %d): %s', $statusCode, mb_substr($body, 0, 200)),
        );
    }

    public static function timeout(?\Throwable $previous = null): self
    {
        return new self(
            'API request timed out. The service may be temporarily unavailable.',
            previous: $previous,
        );
    }

    public static function networkError(string $reason, ?\Throwable $previous = null): self
    {
        return new self(
            sprintf('Network error while contacting API: %s', $reason),
            previous: $previous,
        );
    }

    public static function invalidResponse(string $details, ?\Throwable $previous = null): self
    {
        return new self(
            sprintf('API returned an invalid response: %s', $details),
            previous: $previous,
        );
    }

    public static function httpError(int $statusCode, string $body): self
    {
        return new self(
            sprintf('API returned HTTP %d: %s', $statusCode, mb_substr($body, 0, 200)),
        );
    }

    public static function unavailable(string $reason, ?\Throwable $previous = null): self
    {
        return new self(
            sprintf('Exchange rate API is unavailable: %s', $reason),
            previous: $previous,
        );
    }
}
