<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Infrastructure\Persistence\Doctrine\DoctrineExchangeRateRepository::class)]
#[ORM\Table(name: 'exchange_rates')]
#[ORM\Index(
    name: 'idx_rate_pair_date',
    columns: ['base_currency_id', 'target_currency_id', 'fetched_at'],
)]
#[ORM\Index(
    name: 'idx_rate_pair',
    columns: ['base_currency_id', 'target_currency_id'],
)]
#[ORM\Index(
    name: 'idx_rate_fetched',
    columns: ['fetched_at'],
)]
class ExchangeRate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Currency::class)]
    #[ORM\JoinColumn(
        name: 'base_currency_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'RESTRICT',
    )]
    private Currency $baseCurrency;

    #[ORM\ManyToOne(targetEntity: Currency::class)]
    #[ORM\JoinColumn(
        name: 'target_currency_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'RESTRICT',
    )]
    private Currency $targetCurrency;

    /**
     * Stored as DECIMAL(20,10) in MySQL, kept as string in PHP
     * to preserve precision for bcmath operations.
     */
    #[ORM\Column(
        type: Types::DECIMAL,
        precision: 20,
        scale: 10,
    )]
    private string $rate;

    /** When the rate was fetched from the external API. */
    #[ORM\Column(name: 'fetched_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $fetchedAt;

    /** When this record was persisted to the DB. */
    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Currency $baseCurrency,
        Currency $targetCurrency,
        string $rate,
        \DateTimeImmutable $fetchedAt,
    ) {
        if (bccomp($rate, '0', 10) <= 0) {
            throw new \InvalidArgumentException(
                sprintf('Exchange rate must be positive, got "%s".', $rate),
            );
        }

        $this->baseCurrency = $baseCurrency;
        $this->targetCurrency = $targetCurrency;
        $this->rate = $rate;
        $this->fetchedAt = $fetchedAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    // ── Getters ─────────────────────────────────────────

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getBaseCurrency(): Currency
    {
        return $this->baseCurrency;
    }

    public function getTargetCurrency(): Currency
    {
        return $this->targetCurrency;
    }

    /**
     * Returns rate as string for bcmath.
     * 1 unit of baseCurrency = {rate} units of targetCurrency.
     */
    public function getRate(): string
    {
        return $this->rate;
    }

    public function getFetchedAt(): \DateTimeImmutable
    {
        return $this->fetchedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    // ── Convenience ─────────────────────────────────────

    public function getPairCode(): string
    {
        return sprintf('%s/%s', $this->baseCurrency->getCode(), $this->targetCurrency->getCode());
    }

    public function __toString(): string
    {
        return sprintf(
            '%s = %s',
            $this->getPairCode(),
            $this->rate,
        );
    }
}
