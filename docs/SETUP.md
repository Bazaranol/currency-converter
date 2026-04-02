# Инициализация Symfony 7 — руководство

## Быстрый старт

```bash
# 1. Поднять Docker (из предыдущего шага)
make build && make up

# 2. Запустить скрипт инициализации
bash init-symfony.sh

# 3. Готово — открыть http://localhost:8080
```

Скрипт `init-symfony.sh` автоматически выполнит все шаги ниже.
Если нужно сделать что-то вручную — инструкция по каждому шагу далее.

---

## Шаг 1. Создание Symfony-скелета

```bash
docker compose exec app composer create-project \
    symfony/skeleton:"7.1.*" /tmp/sf-init --no-interaction

# Перенести в рабочую директорию (уже примонтирована)
docker compose exec -u root app bash -c \
    'shopt -s dotglob && cp -rn /tmp/sf-init/* /var/www/html/ && rm -rf /tmp/sf-init'
```

## Шаг 2. Установка пакетов

### Production-зависимости

| Пакет | Назначение |
|---|---|
| `symfony/orm-pack` | Doctrine ORM + DBAL + Migrations |
| `symfony/twig-bundle` | Шаблонизатор для админки |
| `symfony/asset` | Подключение CSS/JS в шаблонах |
| `symfony/cache` | Абстракция кэширования (Redis adapter) |
| `symfony/validator` | Валидация DTO и входных данных |
| `symfony/form` | Формы в админке (фильтры, настройки) |
| `symfony/monolog-bundle` | Структурированное логирование |
| `symfony/security-csrf` | CSRF-защита форм |
| `guzzlehttp/guzzle` | HTTP-клиент для freecurrencyapi |

```bash
docker compose exec app composer require --no-interaction \
    symfony/orm-pack \
    symfony/twig-bundle \
    symfony/asset \
    symfony/cache \
    symfony/validator \
    symfony/form \
    symfony/monolog-bundle \
    symfony/security-csrf \
    guzzlehttp/guzzle
```

### Dev-зависимости

| Пакет | Назначение |
|---|---|
| `symfony/maker-bundle` | Кодогенерация (make:entity и пр.) |
| `phpunit/phpunit` | Unit / Integration тесты |
| `symfony/test-pack` | PHPUnit bridge + WebTestCase |
| `doctrine/doctrine-fixtures-bundle` | Загрузка начальных данных |
| `symfony/var-dumper` | Отладка: dump() / dd() |
| `php-cs-fixer/shim` | Стиль кода (PSR-12) |
| `phpstan/phpstan` | Статический анализ |

```bash
docker compose exec app composer require --dev --no-interaction \
    symfony/maker-bundle \
    phpunit/phpunit \
    symfony/test-pack \
    doctrine/doctrine-fixtures-bundle \
    symfony/var-dumper \
    php-cs-fixer/shim \
    phpstan/phpstan
```

## Шаг 3. Конфигурационные файлы

Все файлы уже созданы и готовы к копированию в проект.

### `config/packages/doctrine.yaml`

Подключение к MySQL через `DATABASE_URL`, mapping XML-файлов из
`src/Infrastructure/Persistence/Doctrine/Mapping`, регистрация
прокси-классов и query-результатов.

### `config/packages/cache.yaml`

Redis как `default_redis_provider`, выделенный пул `cache.exchange_rates`
с TTL из env-переменной `CACHE_EXCHANGE_RATES_TTL`, отдельные пулы
для Doctrine result cache и system cache.

### `config/packages/monolog.yaml`

Два кастомных канала: `exchange_rates` (синхронизация курсов) и
`api_client` (HTTP-запросы к API). В dev — файлы в `var/log/`,
в prod — JSON на stderr (готово для ELK/Datadog).

### `config/packages/currency_converter.yaml`

Параметры модуля: список валют (15 штук), базовая валюта (`USD`),
TTL кэша, точность bcmath, настройки API. Расширение —
добавить строку в массив `app.currency.supported`.

### `config/services.yaml`

DI-биндинги по Clean Architecture:
- Domain интерфейсы → Infrastructure реализации
- Guzzle client с таймаутами
- Redis cache с TTL из параметров
- Event listeners для логирования синхронизации

## Шаг 4. Переменные окружения

Файл `.env` дополняется блоком:

```dotenv
FREECURRENCY_API_KEY=your_api_key_here
FREECURRENCY_API_BASE_URL=https://api.freecurrencyapi.com/v1
CACHE_EXCHANGE_RATES_TTL=86400
```

Для тестов — отдельный `.env.test` с тестовой БД
(`currency_converter_test`), Redis db 1, фейковым API-ключом.

---

## Итоговая структура после инициализации

```
currency-converter/
│
├── docker-compose.yml
├── init-symfony.sh                 ◄── скрипт инициализации
├── Makefile
├── .dockerignore
├── .env.example
├── .env.test
├── phpunit.xml.dist
│
├── docker/
│   ├── php/Dockerfile
│   ├── nginx/default.conf
│   └── cron/crontab
│
├── config/
│   ├── packages/
│   │   ├── doctrine.yaml           ◄── MySQL + XML mapping + custom type
│   │   ├── cache.yaml              ◄── Redis pools
│   │   ├── monolog.yaml            ◄── Logging channels
│   │   └── currency_converter.yaml ◄── Модульный конфиг (валюты, TTL)
│   ├── routes/
│   │   └── admin.yaml              ◄── Маршруты админки
│   └── services.yaml               ◄── DI: порты → адаптеры
│
├── public/
│   └── index.php                   ◄── Symfony front controller
│
├── src/
│   ├── Domain/                     ◄── Чистое ядро (0 зависимостей)
│   │   ├── Entity/
│   │   │   ├── Currency.php
│   │   │   └── ExchangeRate.php
│   │   ├── DTO/
│   │   │   ├── ConversionResult.php
│   │   │   └── ExchangeRateDTO.php
│   │   ├── Exception/
│   │   │   ├── CurrencyNotFoundException.php
│   │   │   ├── RateNotFoundException.php
│   │   │   ├── ExchangeRateApiException.php
│   │   │   └── ConversionException.php
│   │   ├── Repository/
│   │   │   ├── CurrencyRepositoryInterface.php
│   │   │   └── ExchangeRateRepositoryInterface.php
│   │   └── Service/
│   │       ├── CurrencyConverterInterface.php
│   │       └── ExchangeRateProviderInterface.php
│   │
│   ├── Application/                ◄── Use-cases
│   │   ├── Service/
│   │   │   ├── CurrencyConverter.php
│   │   │   └── ExchangeRatesSyncService.php
│   │   └── Event/
│   │       ├── RatesUpdatedEvent.php
│   │       └── RatesSyncFailedEvent.php
│   │
│   ├── Infrastructure/             ◄── Адаптеры
│   │   ├── Persistence/
│   │   │   ├── Doctrine/
│   │   │   │   ├── Mapping/
│   │   │   │   │   ├── Currency.orm.xml
│   │   │   │   │   └── ExchangeRate.orm.xml
│   │   │   │   ├── DoctrineCurrencyRepository.php
│   │   │   │   └── DoctrineExchangeRateRepository.php
│   │   │   └── Type/
│   │   ├── Cache/
│   │   ├── ExternalApi/
│   │   │   ├── FreeCurrencyApiClient.php
│   │   │   └── FreeCurrencyApiProvider.php
│   │   └── EventListener/
│   │       └── RatesUpdateLogger.php
│   │
│   └── UI/                         ◄── Точки входа
│       ├── Command/
│       │   └── UpdateExchangeRatesCommand.php
│       └── Controller/
│           └── Admin/
│               └── ExchangeRateController.php
│
├── templates/
│   └── admin/
│       └── exchange_rates/
│           ├── index.html.twig
│           └── history.html.twig
│
├── fixtures/
│   └── CurrencyFixtures.php
│
├── tests/
│   ├── Unit/
│   │   ├── Domain/
│   │   │   └── Entity/
│   │   └── Application/
│   │       └── Service/
│   ├── Integration/
│   │   ├── Repository/
│   │   └── Cache/
│   └── Functional/
│       └── Command/
│
├── migrations/
│   └── (будут сгенерированы через make:migration)
│
└── vendor/                         ◄── (после composer install)
```

---

## Проверка работоспособности

```bash
# Symfony отвечает?
docker compose exec app php bin/console --version
# → Symfony 7.1.x

# БД доступна?
docker compose exec app php bin/console doctrine:database:create --if-not-exists
# → Database `currency_converter` already exists. Skipped.

# Redis доступен?
docker compose exec redis redis-cli ping
# → PONG

# Web-сервер?
curl -s -o /dev/null -w "%{http_code}" http://localhost:8080
# → 200 (или 404 — нормально, маршруты ещё не созданы)
```

---

## Следующий шаг

Реализация Domain Layer: Entity, Value Objects, DTO, Exceptions.
