<?php

declare(strict_types=1);

namespace App\Exception;

final class ConversionException extends \RuntimeException
{
    public static function invalidAmount(string $amount): self
    {
        return new self(
            sprintf('Invalid amount for conversion: "%s". Must be a positive numeric string.', $amount),
        );
    }

    public static function zeroDivision(string $base, string $target): self
    {
        return new self(
            sprintf('Cannot convert %s → %s: exchange rate is zero.', $base, $target),
        );
    }

    public static function sameCurrency(string $code): self
    {
        return new self(
            sprintf('Cannot convert %s to itself.', $code),
        );
    }
}
