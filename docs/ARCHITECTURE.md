# Архитектура модуля конвертации валют

Symfony 7 · PHP 8.2 · MySQL · Redis · Docker  
Принципы: SOLID, Clean Architecture, simplified DDD

---

## 1. Структура директорий

```
project-root/
├── docker/
│   ├── nginx/
│   │   └── default.conf
│   ├── php/
│   │   └── Dockerfile
│   └── redis/
│       └── redis.conf
├── docker-compose.yml
│
├── config/
│   ├── packages/
│   │   ├── cache.yaml              # Redis pool, adapter config
│   │   ├── doctrine.yaml           # MySQL, mapping paths
│   │   ├── framework.yaml
│   │   └── currency.yaml           # Список валют, API-ключ, TTL кэша
│   ├── routes/
│   │   └── admin.yaml              # Маршруты админки
│   └── services.yaml               # DI-биндинги
│
├── migrations/
│   └── Version20250401_CreateCurrencyTables.php
│
├── src/
│   ├── Domain/                     # ── ЯДРО: чистая бизнес-логика ──
│   │   ├── Repository/             # Только интерфейсы (порты)
│   │   │   ├── CurrencyRepositoryInterface.php
│   │   │   └── ExchangeRateRepositoryInterface.php
│   │   └── Service/
│   │       └── ExchangeRateProviderInterface.php
│   ├── Entity/
│   │   ├── Currency.php
│   │   └── ExchangeRate.php
│   ├── DTO/
│   │   ├── ConversionResultDTO.php
│   │   ├── ExchangeRateDTO.php
│   │   └── SyncResultDTO.php
│   ├── Exception/
│   │   ├── CurrencyNotFoundException.php
│   │   ├── RateNotFoundException.php
│   │   ├── ExchangeRateApiException.php
│   │   └── ConversionException.php
│   │   │   ├── CurrencyRepositoryInterface.php
│   │   │   └── ExchangeRateRepositoryInterface.php
│   │   └── Service/                # Только интерфейсы (порты)
│   │       ├── CurrencyConverterInterface.php
│   │       └── ExchangeRateProviderInterface.php
│   │
│   ├── Application/                # ── СОБЫТИЯ ──
│   │   └── Event/
│   │       ├── RatesUpdatedEvent.php
│   │       └── RatesSyncFailedEvent.php
│   │
│   ├── Infrastructure/             # ── АДАПТЕРЫ: внешний мир ──
│   │   ├── Persistence/
│   │   │   └── Doctrine/
│   │   │       ├── DoctrineCurrencyRepository.php
│   │   │       └── DoctrineExchangeRateRepository.php
│   │   ├── ExternalApi/
│   │   │   ├── FreeCurrencyApiClient.php
│   │   │   └── FreeCurrencyApiProvider.php    # implements ExchangeRateProviderInterface
│   │   └── EventListener/
│   │       └── RatesUpdateLogger.php
│   │
│   ├── Service/                    # ── БИЗНЕС-ЛОГИКА ──
│   │   ├── CurrencyConverterInterface.php
│   │   ├── CurrencyConverter.php
│   │   └── ExchangeRateSyncService.php
│   │
│   ├── Command/                    # ── CLI ──
│   │   ├── SyncExchangeRatesCommand.php
│   │   └── ListCurrenciesCommand.php
│   │
│   ├── Controller/Admin/          # ── WEB ──
│   │   └── ExchangeRateController.php
│   │
│   └── DataFixtures/
│       └── CurrencyFixtures.php
│
├── templates/
│   ├── base.html.twig
│   └── admin/
│       └── exchange_rate/
│           └── index.html.twig     # Таблица курсов + конвертер
│
├── tests/
│   ├── Unit/
│   │   └── Service/
│   │       ├── CurrencyConverterTest.php
│   │       ├── FreeCurrencyApiProviderTest.php
│   │       └── ExchangeRateSyncServiceTest.php
│   └── Integration/
│       └── Repository/
│           └── ExchangeRateRepositoryTest.php
│           └── UpdateExchangeRatesCommandTest.php
│
├── fixtures/
│   └── CurrencyFixtures.php        # Начальный набор валют
│
└── public/
    └── index.php
```

---

## 2. Классы и их ответственности

### 2.1 Domain Layer — ядро, ноль зависимостей от фреймворка

#### Entity

| Класс | Ответственность |
|---|---|
| **`Currency`** | Сущность валюты. Поля: `id` (int), `code` (string, ISO 4217), `name` (string), `symbol` (string), `isActive` (bool). Содержит бизнес-методы: `activate()`, `deactivate()`. |
| **`ExchangeRate`** | Курс валютной пары за определённую дату. Поля: `id` (bigint), `baseCurrency` (Currency), `targetCurrency` (Currency), `rate` (string — DECIMAL(20,10) для bcmath), `fetchedAt` (DateTimeImmutable). Бизнес-правило: `rate` > 0, валидация в конструкторе. |

#### DTO (data-transfer, без логики)

| Класс | Ответственность |
|---|---|
| **`ExchangeRateDTO`** | Переносит данные от API-провайдера в SyncService. Поля: `baseCurrency` (string), `targetCurrency` (string), `rate` (string), `fetchedAt` (DateTimeImmutable). Readonly class. |
| **`ConversionResultDTO`** | Результат конвертации. Поля: `originalAmount`, `originalCurrency`, `convertedAmount`, `targetCurrency`, `rate` (все string), `convertedAt` (DateTimeImmutable). Readonly class. |
| **`SyncResultDTO`** | Статистика синхронизации: `updatedCount`, `skippedCount`, `errorCount`, `errors[]`, `syncedAt`. |

#### Exceptions

| Класс | Ответственность |
|---|---|
| **`CurrencyNotFoundException`** | Запрошенная валюта не найдена в системе. Factory-метод `withCode()`. |
| **`RateNotFoundException`** | Курс для пары не найден (ни в кэше, ни в БД). Factory-метод `forPair()`. |
| **`ExchangeRateApiException`** | Ошибки внешнего API: невалидный ключ, rate limit, таймаут, сетевые ошибки. 7 factory-методов для разных сценариев. |
| **`ConversionException`** | Ошибка бизнес-логики: невалидная сумма, деление на ноль. |

#### Repository Interfaces (порты — контракт для Infrastructure)

| Интерфейс | Методы |
|---|---|
| **`CurrencyRepositoryInterface`** | `findByCode(string): ?Currency`, `findAllActive(): Currency[]`, `findAll(): Currency[]`, `save(Currency): void` |
| **`ExchangeRateRepositoryInterface`** | `findLatestRate(Currency, Currency): ?ExchangeRate`, `findAllLatestRates(): ExchangeRate[]`, `findRatesByDate(DateTimeInterface): ExchangeRate[]`, `save(ExchangeRate): void`, `saveMany(ExchangeRate[]): void`, `findLatestFetchedAt(): ?DateTimeImmutable` |

#### Service Interfaces (порты)

| Интерфейс | Методы |
|---|---|
| **`CurrencyConverterInterface`** | `convert(float\|string $amount, string $from, string $to): ConversionResultDTO`, `getRate(string $from, string $to): string` |
| **`ExchangeRateProviderInterface`** | `fetchRates(string $baseCurrency, string[] $targets): ExchangeRateDTO[]` — абстракция над любым внешним источником курсов. Для замены на ECB/Fixer — новый класс + rebind в services.yaml. |

---

### 2.2 Application Layer — события и оркестрация

| Класс | Ответственность |
|---|---|
| **`CurrencyConverter`** | **Implements `CurrencyConverterInterface`**. Стратегия поиска курса: (1) прямой курс из кэша/БД, (2) обратный курс (инверсия), (3) кросс-курс через pivot (USD). Все вычисления через bcmath (scale=10). Кэширование в Redis с null-маркерами для защиты от cache stampede. |
| **`ExchangeRateSyncService`** | Координирует загрузку курсов. Метод `sync(?string $baseCurrency)` — (1) загружает активные валюты, (2) запрашивает курсы у провайдера, (3) сохраняет в БД, (4) инвалидирует кэш в обе стороны, (5) диспатчит `RatesUpdatedEvent`. При ошибке API — старые курсы сохраняются, диспатчится `RatesSyncFailedEvent`. |
| **`RatesUpdatedEvent`** | Symfony event: `ratesCount`, `skippedCount`, `providerName`, `syncedAt`. |
| **`RatesSyncFailedEvent`** | Symfony event: `reason`, `exception`, `failedAt`. |

---

### 2.3 Infrastructure Layer — адаптеры к внешнему миру

#### Persistence (Doctrine)

| Класс | Ответственность |
|---|---|
| **`DoctrineCurrencyRepository`** | Implements `CurrencyRepositoryInterface`. Наследует `ServiceEntityRepository`. Оптимизированные DQL-запросы с использованием индексов. |
| **`DoctrineExchangeRateRepository`** | Implements `ExchangeRateRepositoryInterface`. `findLatestRate` — ORDER BY fetchedAt DESC LIMIT 1 с JOIN FETCH. `findAllLatestRates` — подзапрос MAX(fetched_at). `saveMany` — batch flush каждые 50 записей. |

#### External API

| Класс | Ответственность |
|---|---|
| **`FreeCurrencyApiClient`** | Low-level HTTP-обёртка через Guzzle. Маппинг HTTP-ошибок: 401→invalidApiKey, 429→rateLimitExceeded, 5xx→serverError, timeout→timeout. Логирование каждого запроса с таймингом. |
| **`FreeCurrencyApiProvider`** | **Implements `ExchangeRateProviderInterface`**. Преобразует сырой JSON в `ExchangeRateDTO[]`. Валидирует значения (numeric, > 0), конвертирует float→string через number_format для bcmath-совместимости. |

#### Event Listeners

| Класс | Ответственность |
|---|---|
| **`RatesUpdateLogger`** | Слушает `RatesUpdatedEvent` и `RatesSyncFailedEvent`. Зависимость: `Psr\Log\LoggerInterface`. На успех — `info("Exchange rates updated: {count} rates synced")`. На ошибку — `error("Rates sync failed: {reason}")`. |

---

### 2.4 UI Layer — точки входа

| Класс | Ответственность |
|---|---|
| **`UpdateExchangeRatesCommand`** | Symfony Console Command: `app:exchange-rates:update`. Зависимость: `ExchangeRatesSyncService`. Вызывает `sync()`, выводит результат в консоль. Cron: `0 3 * * * cd /app && php bin/console app:exchange-rates:update`. Обрабатывает ошибки, возвращает корректный exit code. |
| **`ExchangeRateController`** | Контроллер админки. Зависимости: `ExchangeRateRepositoryInterface`, `CurrencyRepositoryInterface`. Actions: `index()` — таблица актуальных курсов всех активных валют, `history(string $base, string $target)` — история курса пары с пагинацией и фильтрацией по дате. |

---

## 3. Диаграмма зависимостей

```
┌─────────────────────────────────────────────────────────────────────────┐
│  UI LAYER                                                               │
│                                                                         │
│  UpdateExchangeRatesCommand ──────────► ExchangeRatesSyncService        │
│                                                                         │
│  ExchangeRateController ──────────────► CurrencyRepositoryInterface     │
│                          ──────────────► ExchangeRateRepositoryInterface │
└─────────────────────────────────┬───────────────────────────────────────┘
                                  │ зависит от
                                  ▼
┌─────────────────────────────────────────────────────────────────────────┐
│  APPLICATION LAYER                                                      │
│                                                                         │
│  ExchangeRatesSyncService                                               │
│     ├──► ExchangeRateProviderInterface  (Domain port)                   │
│     ├──► CurrencyRepositoryInterface    (Domain port)                   │
│     ├──► ExchangeRateRepositoryInterface(Domain port)                   │
│     ├──► CacheInterface                 (Symfony contract, Redis)       │
│     └──► EventDispatcherInterface       (Symfony contract)              │
│                                                                         │
│  CurrencyConverter (implements CurrencyConverterInterface)              │
│     ├──► ExchangeRateRepositoryInterface(Domain port)                   │
│     ├──► CurrencyRepositoryInterface    (Domain port)                   │
│     └──► CacheInterface                 (Symfony contract, Redis)       │
│                                                                         │
│  RatesUpdatedEvent ◄─────────────────── dispatched by SyncService       │
│  RatesSyncFailedEvent ◄─────────────── dispatched by SyncService        │
└─────────────────────────────────┬───────────────────────────────────────┘
                                  │ зависит от
                                  ▼
┌─────────────────────────────────────────────────────────────────────────┐
│  DOMAIN LAYER (ноль зависимостей, всё чисто)                            │
│                                                                         │
│  Entity: Currency, ExchangeRate                                         │
│  DTO:    ConversionResultDTO, ExchangeRateDTO, SyncResultDTO            │
│  Port:   CurrencyRepositoryInterface                                    │
│  Port:   ExchangeRateRepositoryInterface                                │
│  Port:   CurrencyConverterInterface                                     │
│  Port:   ExchangeRateProviderInterface                                  │
│  Exceptions: все 4 exception-класса                                     │
└─────────────────────────────────────────────────────────────────────────┘
                                  ▲ реализуется
                                  │
┌─────────────────────────────────────────────────────────────────────────┐
│  INFRASTRUCTURE LAYER (адаптеры)                                        │
│                                                                         │
│  DoctrineCurrencyRepository ────implements──► CurrencyRepositoryInterface│
│  DoctrineExchangeRateRepository─implements──► ExchangeRateRepositoryI.  │
│  FreeCurrencyApiProvider ───────implements──► ExchangeRateProviderI.    │
│                                                                         │
│  FreeCurrencyApiClient ──► GuzzleHttp\ClientInterface                   │
│  FreeCurrencyApiProvider ──► FreeCurrencyApiClient                      │
│                                                                         │
│  RatesUpdateLogger ──► Psr\Log\LoggerInterface                          │
│                        listens: RatesUpdatedEvent, RatesSyncFailedEvent  │
└─────────────────────────────────────────────────────────────────────────┘
```

**Правило зависимостей Clean Architecture соблюдено:**
- Domain ← не знает ни о ком
- Application → знает Domain (интерфейсы)
- Infrastructure → знает Domain (реализует интерфейсы)
- UI → знает Application + Domain (интерфейсы)
- Infrastructure и UI **не зависят друг от друга**

---

## 4. Схема базы данных

### Таблица `currencies`

| Поле       | Тип              | Ограничения                     | Описание                  |
|------------|------------------|---------------------------------|---------------------------|
| id         | INT UNSIGNED     | PK, AUTO_INCREMENT              |                           |
| code       | VARCHAR(3)       | NOT NULL, UNIQUE                | ISO 4217 код              |
| name       | VARCHAR(64)      | NOT NULL                        | Полное название           |
| symbol     | VARCHAR(8)       | NOT NULL                        | Символ (₽, $, €)         |
| is_active  | TINYINT(1)       | NOT NULL, DEFAULT 1             | Флаг активности           |
| created_at | DATETIME         | NOT NULL, DEFAULT CURRENT_TS    |                           |

**Индексы:**
- `UNIQUE idx_currency_code (code)`
- `INDEX idx_currency_active (is_active)`

---

### Таблица `exchange_rates`

| Поле               | Тип              | Ограничения                     | Описание                        |
|--------------------|------------------|---------------------------------|---------------------------------|
| id                 | BIGINT UNSIGNED  | PK, AUTO_INCREMENT              |                                 |
| base_currency_id   | INT UNSIGNED     | NOT NULL, FK → currencies.id    | Базовая валюта                  |
| target_currency_id | INT UNSIGNED     | NOT NULL, FK → currencies.id    | Целевая валюта                  |
| rate               | DECIMAL(20,10)   | NOT NULL                        | Курс с высокой точностью       |
| fetched_at         | DATETIME         | NOT NULL                        | Время получения от провайдера   |
| created_at         | DATETIME         | NOT NULL, DEFAULT CURRENT_TS    | Время записи в БД              |

**Индексы:**
- `INDEX idx_rate_pair_date (base_currency_id, target_currency_id, fetched_at DESC)` — основной поиск по паре + сортировка по дате (покрывает `findLatestRate` и `findRatesByDate`)
- `INDEX idx_rate_fetched (fetched_at)` — для выборки «курсы за сегодня» и очистки старых записей
- `FOREIGN KEY fk_rate_base (base_currency_id) REFERENCES currencies(id) ON DELETE RESTRICT`
- `FOREIGN KEY fk_rate_target (target_currency_id) REFERENCES currencies(id) ON DELETE RESTRICT`

**SQL для справки:**

```sql
CREATE TABLE currencies (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code       VARCHAR(3)   NOT NULL,
    name       VARCHAR(64)  NOT NULL,
    symbol     VARCHAR(8)   NOT NULL,
    is_active  TINYINT(1)   NOT NULL DEFAULT 1,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_currency_code (code),
    INDEX idx_currency_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE exchange_rates (
    id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    base_currency_id   INT UNSIGNED NOT NULL,
    target_currency_id INT UNSIGNED NOT NULL,
    rate               DECIMAL(20,10) NOT NULL,
    fetched_at         DATETIME NOT NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rate_pair_date (base_currency_id, target_currency_id, fetched_at DESC),
    INDEX idx_rate_fetched (fetched_at),
    CONSTRAINT fk_rate_base   FOREIGN KEY (base_currency_id)   REFERENCES currencies(id) ON DELETE RESTRICT,
    CONSTRAINT fk_rate_target FOREIGN KEY (target_currency_id) REFERENCES currencies(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## 5. Конфигурация services.yaml

```yaml
# config/services.yaml

parameters:
    app.currency.api_key: '%env(FREECURRENCY_API_KEY)%'
    app.currency.api_base_url: 'https://api.freecurrencyapi.com/v1'
    app.currency.cache_ttl: 86400   # 24 часа
    app.currency.bcmath_scale: 6

services:
    _defaults:
        autowire: true
        autoconfigure: true

    # ── Domain Layer: интерфейсы → реализации ──

    App\Domain\Repository\CurrencyRepositoryInterface:
        class: App\Infrastructure\Persistence\Doctrine\DoctrineCurrencyRepository

    App\Domain\Repository\ExchangeRateRepositoryInterface:
        class: App\Infrastructure\Persistence\Doctrine\DoctrineExchangeRateRepository

    App\Domain\Service\ExchangeRateProviderInterface:
        class: App\Infrastructure\ExternalApi\FreeCurrencyApiProvider

    App\Domain\Service\CurrencyConverterInterface:
        class: App\Application\Service\CurrencyConverter

    # ── Infrastructure: HTTP-клиент для API ──

    App\Infrastructure\ExternalApi\FreeCurrencyApiClient:
        arguments:
            $apiKey: '%app.currency.api_key%'
            $baseUrl: '%app.currency.api_base_url%'
            $httpClient: '@GuzzleHttp\ClientInterface'

    GuzzleHttp\ClientInterface:
        class: GuzzleHttp\Client
        arguments:
            - connect_timeout: 5
              timeout: 10

    # ── Infrastructure: кэш через Symfony Cache ──

    App\Service\CurrencyConverter:
        arguments:
            $cache: '@cache.exchange_rates'
            $cacheTtl: '%app.currency.cache_ttl%'

    App\Service\ExchangeRateSyncService:
        arguments:
            $cache: '@cache.exchange_rates'
            $baseCurrencyCode: '%app.currency.base_currency%'

    # ── UI Layer ──

    App\UI\Command\UpdateExchangeRatesCommand: ~
    App\UI\Controller\Admin\ExchangeRateController: ~

    # ── Event Listeners ──

    App\Infrastructure\EventListener\RatesUpdateLogger:
        tags:
            - { name: kernel.event_listener, event: App\Application\Event\RatesUpdatedEvent }
            - { name: kernel.event_listener, event: App\Application\Event\RatesSyncFailedEvent }
```

```yaml
# config/packages/cache.yaml

framework:
    cache:
        pools:
            cache.exchange_rates:
                adapter: cache.adapter.redis
                provider: 'redis://redis:6379'
                default_lifetime: 86400
```

```yaml
# config/packages/currency.yaml
# Предопределённый список валют — загружается через Fixtures

currency:
    supported:
        - { code: 'USD', name: 'US Dollar',        symbol: '$' }
        - { code: 'EUR', name: 'Euro',              symbol: '€' }
        - { code: 'GBP', name: 'British Pound',     symbol: '£' }
        - { code: 'RUB', name: 'Russian Ruble',     symbol: '₽' }
        - { code: 'TRY', name: 'Turkish Lira',      symbol: '₺' }
        - { code: 'JPY', name: 'Japanese Yen',      symbol: '¥' }
        - { code: 'CNY', name: 'Chinese Yuan',      symbol: '¥' }
        - { code: 'CHF', name: 'Swiss Franc',       symbol: 'Fr' }
    base_currency: 'USD'
```

---

## Ключевые архитектурные решения

**Расширяемость источников курсов** — достаточно создать новый класс, реализующий `ExchangeRateProviderInterface`, и перебиндить его в `services.yaml`. Код приложения не меняется.

**Расширяемость валют** — добавление записи в `currency.yaml` + запуск fixtures. Никаких хардкодов в коде.

**Расширяемость форматов вывода** — `ConversionResult` — чистый DTO. Любой контроллер/сериализатор может отдать его в JSON, XML, Twig-шаблон или gRPC-response.

**Cross-rate конвертация** — если нет прямого курса EUR→RUB, конвертер выполнит EUR→USD→RUB через базовую валюту. Это единственная бизнес-логика, которая живёт в Application Layer.

**Кэш как прокси, не как источник правды** — Redis (через Symfony Cache) ускоряет чтение, но при промахе всегда идёт fallback в БД. При синхронизации кэш инвалидируется в обе стороны (FROM→TO и TO→FROM). Null-маркеры с коротким TTL предотвращают повторные запросы к БД для несуществующих пар.

**DECIMAL(20,10) + bcmath** — курсы хранятся с точностью до 10 знаков; все вычисления через `bcmul`/`bcdiv` со `scale=6`, что гарантирует отсутствие ошибок округления с плавающей точкой.
