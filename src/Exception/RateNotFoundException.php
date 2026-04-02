<?php

declare(strict_types=1);

namespace App\Exception;

final class RateNotFoundException extends \DomainException
{
    public static function forPair(string $base, string $target): self
    {
        return new self(
            sprintf(
                'Exchange rate for pair %s → %s not found.',
                $base,
                $target,
            ),
        );
    }
}
