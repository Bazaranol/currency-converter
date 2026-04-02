<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Infrastructure\Persistence\Doctrine\DoctrineCurrencyRepository::class)]
#[ORM\Table(name: 'currencies')]
#[ORM\Index(name: 'idx_currency_active', columns: ['is_active'])]
#[ORM\HasLifecycleCallbacks]
class Currency
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 3, unique: true)]
    private string $code;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 8)]
    private string $symbol;

    #[ORM\Column(name: 'is_active', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $code,
        string $name,
        string $symbol = '',
        bool $isActive = true,
    ) {
        $this->code = strtoupper($code);
        $this->name = $name;
        $this->symbol = $symbol;
        $this->isActive = $isActive;
        $this->createdAt = new \DateTimeImmutable();
    }

    // ── Getters ─────────────────────────────────────────

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSymbol(): string
    {
        return $this->symbol;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    // ── Business methods ────────────────────────────────

    public function activate(): void
    {
        $this->isActive = true;
    }

    public function deactivate(): void
    {
        $this->isActive = false;
    }

    public function rename(string $name): void
    {
        $this->name = $name;
    }

    public function updateSymbol(string $symbol): void
    {
        $this->symbol = $symbol;
    }

    // ── Identity ────────────────────────────────────────

    public function __toString(): string
    {
        return $this->code;
    }
}
