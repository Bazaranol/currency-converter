#!/usr/bin/env bash
# ============================================================
#  init-symfony.sh
#  Run from HOST machine after: make build && make up
#  Usage: bash init-symfony.sh
# ============================================================

set -euo pipefail

COMPOSE="docker compose"
EXEC="$COMPOSE exec app"
EXEC_ROOT="$COMPOSE exec -u root app"
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

step() { echo -e "\n${GREEN}▶ $1${NC}"; }
warn() { echo -e "${YELLOW}  ⚠ $1${NC}"; }

# ── Pre-flight checks ──
if ! $COMPOSE ps --format '{{.Name}}' 2>/dev/null | grep -q currency_app && \
   ! $COMPOSE ps 2>/dev/null | grep -q currency_app; then
    echo -e "${RED}✖ Containers not running. Run: make build && make up${NC}"
    exit 1
fi

# ============================================================
#  STEP 1 — Create Symfony skeleton
# ============================================================
step "Creating Symfony 7 skeleton project..."

# Composer creates project into a temp dir, then we move contents
# because our workdir /var/www/html is already mounted
$EXEC composer create-project symfony/skeleton:"7.1.*" /tmp/symfony-init --no-interaction

# Move everything (including hidden files) to the workdir
$EXEC_ROOT bash -c 'shopt -s dotglob && cp -rn /tmp/symfony-init/* /var/www/html/ && rm -rf /tmp/symfony-init'

echo -e "  Symfony skeleton created."

# ============================================================
#  STEP 2 — Install required packages
# ============================================================
step "Installing production dependencies..."

$EXEC composer require --no-interaction \
    symfony/orm-pack \
    symfony/twig-bundle \
    symfony/asset \
    symfony/cache \
    symfony/lock \
    symfony/validator \
    symfony/form \
    symfony/monolog-bundle \
    symfony/security-csrf \
    guzzlehttp/guzzle

step "Installing dev dependencies..."

$EXEC composer require --dev --no-interaction \
    symfony/maker-bundle \
    phpunit/phpunit \
    symfony/test-pack \
    doctrine/doctrine-fixtures-bundle \
    symfony/var-dumper \
    php-cs-fixer/shim \
    phpstan/phpstan

# ============================================================
#  STEP 3 — Copy config files
# ============================================================
step "Deploying configuration files..."

# doctrine.yaml
$EXEC_ROOT bash -c 'cat > /var/www/html/config/packages/doctrine.yaml << '\''YAML'\''
doctrine:
    dbal:
        url: "%env(resolve:DATABASE_URL)%"
        charset: utf8mb4
        default_table_options:
            charset: utf8mb4
            collate: utf8mb4_unicode_ci
            engine: InnoDB
        profiling_collect_backtrace: "%kernel.debug%"

    orm:
        auto_generate_proxy_classes: true
        enable_lazy_ghost_objects: true
        naming_strategy: doctrine.orm.naming_strategy.underscore_number_aware
        auto_mapping: true
        mappings:
            App:
                type: attribute
                is_bundle: false
                dir: "%kernel.project_dir%/src/Entity"
                prefix: App\Entity
                alias: App

when@prod:
    doctrine:
        orm:
            auto_generate_proxy_classes: false
            proxy_dir: "%kernel.build_dir%/doctrine/orm/Proxies"
            query_cache_driver:
                type: pool
                pool: doctrine.system_cache_pool
            result_cache_driver:
                type: pool
                pool: doctrine.result_cache_pool
YAML'

# cache.yaml
$EXEC_ROOT bash -c 'cat > /var/www/html/config/packages/cache.yaml << '\''YAML'\''
framework:
    cache:
        # Redis as default cache adapter
        app: cache.adapter.redis
        default_redis_provider: "%env(REDIS_URL)%"

        pools:
            cache.exchange_rates:
                adapter: cache.adapter.redis
                default_lifetime: "%env(int:CACHE_EXCHANGE_RATES_TTL)%"

            doctrine.result_cache_pool:
                adapter: cache.adapter.redis

            doctrine.system_cache_pool:
                adapter: cache.adapter.system
YAML'

# lock.yaml
$EXEC_ROOT bash -c 'cat > /var/www/html/config/packages/lock.yaml << '\''YAML'\''
framework:
    lock: '\''flock'\''
YAML'

# monolog.yaml
$EXEC_ROOT bash -c 'cat > /var/www/html/config/packages/monolog.yaml << '\''YAML'\''
monolog:
    channels:
        - exchange_rates
        - api_client

when@dev:
    monolog:
        handlers:
            main:
                type: stream
                path: "%kernel.logs_dir%/%kernel.environment%.log"
                level: debug
                channels: ["!event"]

            exchange_rates:
                type: stream
                path: "%kernel.logs_dir%/exchange_rates.log"
                level: info
                channels: [exchange_rates]

            console:
                type: console
                process_psr_3_messages: false
                channels: ["!event", "!doctrine"]

when@prod:
    monolog:
        handlers:
            main:
                type: fingers_crossed
                action_level: error
                handler: nested
                buffer_size: 50
                excluded_http_codes: [404, 405]

            nested:
                type: stream
                path: "php://stderr"
                level: debug
                formatter: monolog.formatter.json

            exchange_rates:
                type: stream
                path: "php://stderr"
                level: info
                channels: [exchange_rates]
                formatter: monolog.formatter.json

            console:
                type: console
                process_psr_3_messages: false
                channels: ["!event", "!doctrine"]
YAML'

# currency_converter.yaml — module configuration
$EXEC_ROOT bash -c 'cat > /var/www/html/config/packages/currency_converter.yaml << '\''YAML'\''
# ──────────────────────────────────────────────
#  Currency Converter Module Configuration
# ──────────────────────────────────────────────

parameters:
    # ── Supported currencies (ISO 4217) ──
    # Add new entries here — no code changes needed.
    # Fixtures will sync this list into the DB.
    app.currency.supported:
        - { code: "USD", name: "US Dollar",         symbol: "$" }
        - { code: "EUR", name: "Euro",               symbol: "€" }
        - { code: "GBP", name: "British Pound",      symbol: "£" }
        - { code: "RUB", name: "Russian Ruble",      symbol: "₽" }
        - { code: "TRY", name: "Turkish Lira",       symbol: "₺" }
        - { code: "JPY", name: "Japanese Yen",       symbol: "¥" }
        - { code: "CNY", name: "Chinese Yuan",       symbol: "¥" }
        - { code: "KZT", name: "Kazakhstani Tenge",  symbol: "₸" }
        - { code: "CHF", name: "Swiss Franc",        symbol: "Fr" }
        - { code: "AED", name: "UAE Dirham",         symbol: "د.إ" }
        - { code: "GEL", name: "Georgian Lari",      symbol: "₾" }

    # ── Base currency ──
    # All rates are stored relative to this currency.
    # Cross-rate conversion uses it as the pivot.
    app.currency.base_currency: "USD"

    # ── Cache TTL (seconds) ──
    # How long exchange rates stay in Redis before expiration.
    # Default: 86400 = 24 hours (matches the cron sync interval).
    app.currency.cache_ttl: 86400

    # ── bcmath precision ──
    # Scale (decimal places) for all currency arithmetic.
    app.currency.bcmath_scale: 6

    # ── External API ──
    app.currency.api_key:      "%env(FREECURRENCY_API_KEY)%"
    app.currency.api_base_url: "%env(FREECURRENCY_API_BASE_URL)%"
    app.currency.api_timeout:  10
YAML'

# services.yaml
$EXEC_ROOT bash -c 'cat > /var/www/html/config/services.yaml << '\''YAML'\''
services:
    _defaults:
        autowire: true
        autoconfigure: true

    # ── Auto-register all classes in src/ ──
    App\:
        resource: "../src/"
        exclude:
            - "../src/Entity/"
            - "../src/DTO/"
            - "../src/Exception/"
            - "../src/Kernel.php"

    # ═══════════════════════════════════════════
    #  Domain Port → Infrastructure Adapter
    # ═══════════════════════════════════════════

    # Repository bindings
    App\Domain\Repository\CurrencyRepositoryInterface:
        class: App\Infrastructure\Persistence\Doctrine\DoctrineCurrencyRepository

    App\Domain\Repository\ExchangeRateRepositoryInterface:
        class: App\Infrastructure\Persistence\Doctrine\DoctrineExchangeRateRepository

    # Service bindings
    App\Domain\Service\ExchangeRateProviderInterface:
        class: App\Infrastructure\ExternalApi\FreeCurrencyApiProvider

    App\Service\CurrencyConverterInterface:
        class: App\Service\CurrencyConverter

    # ═══════════════════════════════════════════
    #  Infrastructure wiring
    # ═══════════════════════════════════════════

    # Guzzle HTTP client
    GuzzleHttp\ClientInterface:
        class: GuzzleHttp\Client
        arguments:
            -   connect_timeout: 5
                timeout: "%app.currency.api_timeout%"
                http_errors: false
                headers:
                    Accept: "application/json"

    # FreeCurrencyApi HTTP wrapper
    App\Infrastructure\ExternalApi\FreeCurrencyApiClient:
        arguments:
            $apiKey:   "%app.currency.api_key%"
            $baseUrl:  "%app.currency.api_base_url%"

    # ═══════════════════════════════════════════
    #  Service layer
    # ═══════════════════════════════════════════

    App\Service\CurrencyConverter:
        arguments:
            $cache:    "@cache.exchange_rates"
            $cacheTtl: "%app.currency.cache_ttl%"

    App\Service\ExchangeRateSyncService:
        arguments:
            $cache:            "@cache.exchange_rates"
            $baseCurrencyCode: "%app.currency.base_currency%"

    # ═══════════════════════════════════════════
    #  Event listeners
    # ═══════════════════════════════════════════

    App\Infrastructure\EventListener\RatesUpdateLogger:
        tags:
            -   name: kernel.event_listener
                event: App\Application\Event\RatesUpdatedEvent
                method: onRatesUpdated
            -   name: kernel.event_listener
                event: App\Application\Event\RatesSyncFailedEvent
                method: onSyncFailed
YAML'

echo -e "  Config files deployed."

# ============================================================
#  STEP 5 — Create src/ directory skeleton
# ============================================================
step "Creating source directory structure..."

$EXEC mkdir -p \
    src/Entity \
    src/DTO \
    src/Exception \
    src/Domain/Repository \
    src/Domain/Service \
    src/Application/Event \
    src/Infrastructure/Persistence/Doctrine \
    src/Infrastructure/ExternalApi \
    src/Infrastructure/EventListener \
    src/Service \
    src/Command \
    src/Controller/Admin \
    src/DataFixtures \
    templates/admin/exchange_rate \
    fixtures

echo -e "  Directories created."

# ============================================================
#  STEP 6 — Verify installation
# ============================================================
step "Verifying installation..."

echo -e "  Symfony version:"
$EXEC php bin/console --version

echo -e "\n  Installed packages:"
$EXEC composer show --direct | head -20

echo -e "\n  Registered bundles:"
$EXEC php bin/console debug:config --list 2>/dev/null | head -10 || true

# ============================================================
#  Done
# ============================================================
echo -e "\n${GREEN}════════════════════════════════════════════════════${NC}"
echo -e "${GREEN}  ✔ Symfony project initialized successfully!${NC}"
echo -e "${GREEN}════════════════════════════════════════════════════${NC}"
echo -e ""
echo -e "  Next steps:"
echo -e "  ${YELLOW}1.${NC} Set your API key in .env:  FREECURRENCY_API_KEY=..."
echo -e "  ${YELLOW}2.${NC} Generate migrations:        make shell → php bin/console make:migration"
echo -e "  ${YELLOW}3.${NC} Run migrations:             make migrate"
echo -e "  ${YELLOW}4.${NC} Load fixtures:              make fixtures"
echo -e ""
