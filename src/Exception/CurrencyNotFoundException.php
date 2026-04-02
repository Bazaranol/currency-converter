<?php

declare(strict_types=1);

namespace App\Exception;

final class CurrencyNotFoundException extends \DomainException
{
    public static function withCode(string $code): self
    {
        return new self(
            sprintf('Currency with code "%s" not found.', $code),
        );
    }
}
