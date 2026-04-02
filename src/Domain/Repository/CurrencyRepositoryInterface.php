<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Entity\Currency;

interface CurrencyRepositoryInterface
{
    /**
     * Find a currency by its ISO 4217 code (case-insensitive).
     */
    public function findByCode(string $code): ?Currency;

    /**
     * Return all currencies regardless of status, ordered by code.
     *
     * @return Currency[]
     */
    public function findAll(): array;

    /**
     * Return only active currencies, ordered by code.
     *
     * @return Currency[]
     */
    public function findAllActive(): array;

    /**
     * Persist a currency (insert or update).
     */
    public function save(Currency $currency): void;
}
