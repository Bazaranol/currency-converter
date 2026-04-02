<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the core tables for the currency converter module:
 *   - currencies        : list of supported currencies (ISO 4217)
 *   - exchange_rates    : historical exchange rate records
 */
final class Version20250401000000CreateCurrencyTables extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create currencies and exchange_rates tables with indexes and foreign keys';
    }

    public function up(Schema $schema): void
    {
        // ── currencies ──────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE currencies (
                id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                code        VARCHAR(3)   NOT NULL,
                name        VARCHAR(100) NOT NULL,
                symbol      VARCHAR(8)   NOT NULL DEFAULT '',
                is_active   TINYINT(1)   NOT NULL DEFAULT 1,
                created_at  DATETIME     NOT NULL COMMENT '(DC2Type:datetime_immutable)',

                UNIQUE INDEX uniq_currency_code (code),
                INDEX idx_currency_active (is_active)
            )
            ENGINE=InnoDB
            DEFAULT CHARSET=utf8mb4
            COLLATE=utf8mb4_unicode_ci
        SQL);

        // ── exchange_rates ──────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE exchange_rates (
                id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                base_currency_id    INT UNSIGNED NOT NULL,
                target_currency_id  INT UNSIGNED NOT NULL,
                rate                DECIMAL(20,10) NOT NULL,
                fetched_at          DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_at          DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',

                INDEX idx_rate_pair (base_currency_id, target_currency_id),
                INDEX idx_rate_pair_date (base_currency_id, target_currency_id, fetched_at),
                INDEX idx_rate_fetched (fetched_at),

                UNIQUE INDEX idx_rate_unique (base_currency_id, target_currency_id, fetched_at),

                CONSTRAINT fk_rate_base_currency
                    FOREIGN KEY (base_currency_id) REFERENCES currencies (id)
                    ON DELETE RESTRICT ON UPDATE CASCADE,

                CONSTRAINT fk_rate_target_currency
                    FOREIGN KEY (target_currency_id) REFERENCES currencies (id)
                    ON DELETE RESTRICT ON UPDATE CASCADE
            )
            ENGINE=InnoDB
            DEFAULT CHARSET=utf8mb4
            COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS exchange_rates');
        $this->addSql('DROP TABLE IF EXISTS currencies');
    }
}
