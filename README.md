# Currency Converter

Модуль конвертации валют на Symfony 7 с загрузкой курсов из freecurrencyapi.com, хранением истории в MySQL, кэшированием в Redis и точными вычислениями через bcmath.

**Стек:** PHP 8.2 · Symfony 7.1 · MySQL 8 · Redis 7 · Docker · Guzzle · PHPUnit

---

## Использование AI
[Описание работы с AI](docs/AI_DEVELOPMENT_PROMPTS.md)

Другая документация лежит в папке *docs/*

Ссылки на другие документации находятся в конце файла

## Архитектура

Проект следует принципам Clean Architecture и SOLID. Зависимости направлены внутрь — ядро не знает о фреймворке, базе данных или внешних API.

```
┌─────────────────────────────────────────────────────────────────┐
│  Presentation (UI)                                              │
│  Controllers, Console Commands, Twig Templates                  │
│                                                                 │
│  ExchangeRateController          SyncExchangeRatesCommand       │
│  (GET /admin/exchange-rates)     (app:sync-exchange-rates)      │
│  (POST .../convert)              ListCurrenciesCommand          │
└────────────────────────┬────────────────────────────────────────┘
                         │ использует
                         ▼
┌─────────────────────────────────────────────────────────────────┐
│  Service Layer                                                  │
│  Бизнес-логика, оркестрация                                     │
│                                                                 │
│  CurrencyConverter               ExchangeRateSyncService        │
│  ├─ прямой курс                  ├─ загрузка курсов             │
│  ├─ инверсный курс               ├─ batch-сохранение            │
│  └─ кросс-курс через USD         ├─ инвалидация кэша            │
│                                   └─ dispatch events             │
└────────────────────────┬────────────────────────────────────────┘
                         │ зависит от интерфейсов
                         ▼
┌─────────────────────────────────────────────────────────────────┐
│  Domain Layer (ядро — ноль зависимостей)                        │
│                                                                 │
│  Entity:     Currency, ExchangeRate                             │
│  DTO:        ConversionResultDTO, ExchangeRateDTO, SyncResultDTO│
│  Exception:  4 типизированных исключений                        │
│  Interfaces: CurrencyRepositoryInterface                        │
│              ExchangeRateRepositoryInterface                    │
│              ExchangeRateProviderInterface                      │
└─────────────────────────────────────────────────────────────────┘
                         ▲ реализуют
                         │
┌─────────────────────────────────────────────────────────────────┐
│  Infrastructure (адаптеры к внешнему миру)                      │
│                                                                 │
│  Doctrine:   DoctrineCurrencyRepository                         │
│              DoctrineExchangeRateRepository                     │
│  API:        FreeCurrencyApiClient (HTTP/Guzzle)                │
│              FreeCurrencyApiProvider (DTO-маппинг)               │
│  Events:     RatesUpdateLogger                                  │
│  Cache:      Symfony Cache + Redis adapter                      │
└─────────────────────────────────────────────────────────────────┘
```

### Ключевые паттерны

**Repository** — интерфейсы в Domain, реализации в Infrastructure. Сервисы зависят от контрактов, не от Doctrine. Можно заменить ORM без изменения бизнес-логики.

**Provider** — `ExchangeRateProviderInterface` абстрагирует источник курсов. Текущая реализация — freecurrencyapi.com. Для замены на ECB/Fixer достаточно нового класса + одной строки в services.yaml.

**DTO** — иммутабельные readonly-объекты для передачи данных между слоями. `ConversionResultDTO` возвращается из конвертера, `ExchangeRateDTO` несёт данные от API в sync-сервис.

**Lock** — `LockFactory` предотвращает параллельный запуск синхронизации (и через CLI, и через веб-интерфейс). Используется filesystem lock через `flock`.

---

## Требования

- Docker и Docker Compose (v2+)
- Make (входит в Xcode CLI Tools на macOS)
- API-ключ freecurrencyapi.com (бесплатный: https://freecurrencyapi.com)

---

## Быстрый старт

```bash
# 1. Клонировать проект
git clone <URL> currency-converter
cd currency-converter

# 2. Настроить окружение
cp .env.example .env
# Вписать API-ключ:
#   FREECURRENCY_API_KEY=fca_live_ваш_ключ

# 3. Собрать и запустить
make build
make up

# 4. Инициализировать Symfony (первый раз)
bash init-symfony.sh

# 5. Создать БД, применить миграции, загрузить валюты
make db-create
make migrate
make fixtures

# 6. Загрузить курсы с API
# Проверьте api key в .env
make sync-rates

# 7. Открыть админку
open http://localhost:8080/admin/exchange-rates
```

Или одной командой (после настройки .env):

```bash
make init          # build + up + composer + db + migrate + fixtures
make sync-rates    # загрузить курсы
```

---

## Использование конвертера

### В PHP-коде (через DI)

```php
use App\Service\CurrencyConverterInterface;

class MyService
{
    public function __construct(
        private readonly CurrencyConverterInterface $converter,
    ) {}

    public function example(): void
    {
        // Конвертация
        $result = $this->converter->convert(100, 'USD', 'EUR');

        echo $result->convertedAmount;   // "92.13"
        echo $result->rate;              // "0.9213000000"
        echo $result->originalCurrency;  // "USD"
        echo $result->targetCurrency;    // "EUR"

        // Получить курс без конвертации
        $rate = $this->converter->getRate('USD', 'RUB');
        // "92.4500000000"
    }
}
```

### Через админку (браузер)

Откройте `/admin/exchange-rates` — блок «Currency Converter» — введите сумму, выберите валюты, нажмите Convert. Результат отображается без перезагрузки (AJAX).

### Через AJAX API

```bash
curl -X POST http://localhost:8080/admin/exchange-rates/convert \
  -H "Content-Type: application/json" \
  -d '{"amount": "100", "from": "USD", "to": "EUR"}'
```

Ответ:

```json
{
  "success": true,
  "originalAmount": "100",
  "originalCurrency": "USD",
  "convertedAmount": "92.13",
  "targetCurrency": "EUR",
  "rate": "0.9213000000",
  "convertedAt": "2025-04-01 12:00:00"
}
```

---

## CLI-команды

### Синхронизация курсов

```bash
# Стандартный запуск
php bin/console app:sync-exchange-rates

# Принудительно (даже если уже синхронизировано сегодня)
php bin/console app:sync-exchange-rates --force

# Предпросмотр без сохранения
php bin/console app:sync-exchange-rates --dry-run

# Через Makefile
make sync-rates
```

### Список валют

```bash
php bin/console app:currencies:list              # все
php bin/console app:currencies:list --active      # только активные
php bin/console app:currencies:list --inactive    # только неактивные
```

---

## Настройка cron

### Вариант A — из хоста через Docker

```crontab
0 6 * * * docker compose exec -T app php bin/console app:sync-exchange-rates >> /var/log/currency-sync.log 2>&1
```

### Вариант B — внутри контейнера

```bash
make shell
crontab /var/www/html/docker/cron/crontab
```

### Вариант C — выделенный cron-контейнер (production)

Добавить в docker-compose.yml сервис cron, использующий тот же образ app, с entrypoint `cron -f` и примонтированным `docker/cron/crontab`.

---

## Тесты

```bash
docker compose exec app php bin/console doctrine:migrations:migrate --env=test --no-interaction # миграции для тестового окружения
make test              # все тесты
make test-unit         # только unit (быстро, без БД)
make test-coverage     # HTML-отчёт покрытия → var/coverage/
```

### Для integration-тестов (нужна тестовая БД)

```bash
make shell
php bin/console doctrine:database:create --env=test --if-not-exists
php bin/console doctrine:migrations:migrate --env=test --no-interaction
php bin/phpunit --testsuite=integration
```

### Что покрыто

| Suite | Файл | Тестов | Что проверяет |
|---|---|---|---|
| unit | CurrencyConverterTest | 10 | Прямой курс, cross-rate, bcmath, ошибки |
| unit | FreeCurrencyApiProviderTest | 9 | Парсинг API, невалидные данные, пустой ответ |
| unit | ExchangeRateSyncServiceTest | 5 | Sync успех/ошибка, partial response, events |
| integration | ExchangeRateRepositoryTest | 7 | findLatestRate, findAllLatestRates, saveMany |

---

## Структура проекта

```
currency-converter/
├── docker/
│   ├── php/Dockerfile                  # PHP 8.2-FPM + extensions + Xdebug
│   ├── nginx/default.conf              # Nginx → Symfony front controller
│   └── cron/crontab                    # Расписание синхронизации
├── config/
│   ├── packages/
│   │   ├── doctrine.yaml               # MySQL, attribute mapping
│   │   ├── doctrine_migrations.yaml    # Путь к миграциям
│   │   ├── cache.yaml                  # Redis pools
│   │   ├── monolog.yaml                # Каналы логирования
│   │   └── currency_converter.yaml     # Валюты, TTL, API settings
│   ├── routes/admin.yaml               # Маршруты админки
│   └── services.yaml                   # DI: интерфейсы → реализации
├── migrations/
│   └── Version20250401...php           # CREATE TABLE currencies, exchange_rates
├── src/
│   ├── Entity/                         # Doctrine-сущности
│   │   ├── Currency.php
│   │   └── ExchangeRate.php
│   ├── Domain/
│   │   ├── Repository/                 # Интерфейсы репозиториев
│   │   └── Service/                    # ExchangeRateProviderInterface
│   ├── DTO/                            # ConversionResultDTO, ExchangeRateDTO, SyncResultDTO
│   ├── Exception/                      # 4 типизированных исключения
│   ├── Service/                        # CurrencyConverter, ExchangeRateSyncService
│   ├── Infrastructure/
│   │   ├── ExternalApi/                # FreeCurrencyApiClient, FreeCurrencyApiProvider
│   │   ├── Persistence/Doctrine/       # Repository реализации
│   │   └── EventListener/             # RatesUpdateLogger
│   ├── Command/                        # CLI: sync, list
│   ├── Controller/Admin/              # Админка + AJAX
│   └── DataFixtures/                  # 17 валют
├── templates/                          # Twig: base layout + админка
├── tests/                              # Unit + Integration (40 тестов)
├── docs/                               # ARCHITECTURE, SETUP, MACOS_SETUP
├── docker-compose.yml
├── Makefile
├── init-symfony.sh
├── phpunit.xml.dist
├── .env.example
└── .env.test
```

---

## Принятые технические решения

### Почему bcmath для вычислений

Плавающая точка теряет точность при операциях с деньгами: `0.1 + 0.2 = 0.30000000000000004`. bcmath работает со строками и гарантирует точность до заданного количества знаков. Все суммы хранятся как string, все операции — через bcmul, bcdiv, bcadd, bccomp с scale=10.

### Почему Redis для кэша

Курсы обновляются раз в сутки, но читаются при каждой конвертации. Redis обеспечивает микросекундный доступ без обращения к MySQL. TTL совпадает с интервалом cron (24 часа). При синхронизации кэш инвалидируется в обе стороны пары.

### Почему интерфейсы для провайдера

ExchangeRateProviderInterface — единственная точка зависимости от внешнего API. Для замены freecurrencyapi на ECB/Fixer: создать новый класс, реализовать один метод fetchRates(), перебиндить в services.yaml. Ни один сервис не меняется.

### Почему хранение истории курсов

Таблица exchange_rates хранит все исторические записи. Это позволяет анализировать тренды, аудировать конвертации по дате, восстанавливать данные при сбое кэша.

### Почему cross-rate через USD

freecurrencyapi.com отдаёт курсы относительно одной базовой валюты. Для пар без прямого курса (EUR→RUB) конвертер рассчитывает через USD: rate(EUR/RUB) = rate(USD/RUB) / rate(USD/EUR). Стандартная практика в финансовых системах.

---

## Конфигурация

### Добавление новой валюты

1. Добавить строку в `src/DataFixtures/CurrencyFixtures.php` → `CURRENCIES`
2. Запустить: `php bin/console doctrine:fixtures:load --append`
3. Запустить: `make sync-rates`

### Переменные окружения

| Переменная | Описание | По умолчанию |
|---|---|---|
| `FREECURRENCY_API_KEY` | API-ключ freecurrencyapi.com | — (обязательно) |
| `FREECURRENCY_API_BASE_URL` | Base URL API | `https://api.freecurrencyapi.com/v1` |
| `DATABASE_URL` | DSN подключения к MySQL | `mysql://...@db:3306/currency_converter` |
| `REDIS_URL` | DSN подключения к Redis | `redis://redis:6379` |
| `CACHE_EXCHANGE_RATES_TTL` | TTL кэша курсов (секунды) | `86400` |

---

## Возможные улучшения

- **Дополнительные API-провайдеры** — ECB, Fixer.io, OpenExchangeRates с fallback-цепочкой
- **REST API** — `GET /api/v1/rates`, `POST /api/v1/convert` с rate limiting и API-ключами
- **WebSocket** — Mercure Hub для push-уведомлений при синхронизации
- **Асинхронная синхронизация** — Symfony Messenger для фоновой обработки
- **Графики** — Chart.js в админке для визуализации истории курсов
- **Уведомления** — Telegram/Slack при критических изменениях курса
- **OpenAPI** — автогенерация документации через NelmioApiDocBundle

---

## Makefile — все команды

```bash
make help              # список всех команд
make init              # полная инициализация проекта
make build             # собрать Docker-образы
make up / down         # запустить / остановить
make composer-install  # установить зависимости
make db-create         # создать БД
make migrate           # применить миграции
make fixtures          # загрузить валюты
make sync-rates        # синхронизировать курсы
make test              # все тесты
make test-unit         # только unit
make shell             # bash в контейнер PHP
make db-shell          # MySQL CLI
make redis-cli         # Redis CLI
```

---

## Документация

| Документ | Содержание |
|---|---|
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Детальная архитектура, классы, схема БД |
| [docs/SETUP.md](docs/SETUP.md) | Инициализация Symfony внутри Docker |
| [docs/MACOS_SETUP.md](docs/MACOS_SETUP.md) | macOS: установка, Xdebug, Apple Silicon, troubleshooting |
| [docs/AI_DEVELOPMENT_PROMPTS.md](docs/AI_DEVELOPMENT_PROMPTS.md) | описание работы с LLM, промпты |

---

## Лицензия

MIT
