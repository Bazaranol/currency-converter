# AI-Assisted Development — Currency Converter Module

## Используемые модели

- **Claude Sonnet 4** — генерация кода, реализация отдельных компонентов
- **Claude Opus 4** — архитектурные решения, code review, отладка

## Подход

AI использовался как инструмент ускорения разработки: проектирование архитектуры, генерация boilerplate-кода, написание тестов, создание конфигураций. Выбор стека (Symfony, MySQL, Redis, Docker) и финальные решения — мои. После каждого этапа я проверял работоспособность, вносил ручные правки и исправлял баги где требовалось.

Разработка велась итеративно в 12 этапов — от архитектуры до документации.

---

## Этап 1 — Архитектура и структура проекта

```
Я создаю модуль конвертации валют на Symfony 7 + PHP 8.2 + MySQL + Redis + Docker.

Спроектируй архитектуру проекта с соблюдением SOLID, Clean Architecture и Domain-Driven Design (в упрощённом варианте). Проект должен быть легко расширяемым (добавление новых источников курсов, новых валют, новых форматов вывода).

Требования к модулю:
- Предопределённый список валют (через конфиг или fixtures)
- Загрузка курсов с freecurrencyapi.com (без готовых библиотек, через Guzzle)
- Хранение курсов в БД с историей
- Обновление курсов раз в сутки (Symfony Command + cron)
- Сервис конвертации: $converter->convert(123, 'USD', 'RUB')
- Админка для отображения курсов
- Кэширование курсов в Redis
- Точные вычисления через bcmath

Опиши:
1. Полную структуру директорий проекта (src/, config/, etc.)
2. Список всех классов с их ответственностями:
   - Entity (Currency, ExchangeRate)
   - Repository interfaces и реализации
   - DTO (ConversionResult, ExchangeRateDTO)
   - Service interfaces и реализации (CurrencyConverterInterface, ExchangeRateProviderInterface, ExchangeRatesSyncService)
   - HTTP Client wrapper для freecurrencyapi
   - Symfony Command для обновления курсов
   - Controller для админки
   - Custom Exceptions (CurrencyNotFoundException, RateNotFoundException, ExchangeRateApiException, ConversionException)
   - Event/Listener для логирования обновлений
3. Диаграмму зависимостей между классами (текстом)
4. Схему БД (таблицы currencies и exchange_rates с полями, типами, индексами)
5. Конфигурацию services.yaml с DI-биндингами

Не пиши код — только архитектуру, структуру и описание ответственностей каждого класса. Это фундамент, на котором будет строиться вся реализация.
```

---

## Этап 2 — Docker-окружение

```
На основе спроектированной архитектуры создай Docker-окружение для проекта конвертации валют.

Стек: PHP 8.2-FPM, Nginx, MySQL 8, Redis 7.

Создай следующие файлы:

1. docker-compose.yml с сервисами:
   - app (PHP-FPM 8.2): с volume монтированием кода, зависимость от db и redis
   - nginx: конфиг для Symfony, проброс порта 8080
   - db (MySQL 8): volume для данных, переменные для root-пароля, БД, пользователя
   - redis: стандартный образ, volume для данных

2. docker/php/Dockerfile:
   - На базе php:8.2-fpm
   - Установка расширений: pdo_mysql, bcmath, redis, intl, zip, opcache
   - Установка Composer
   - Установка Xdebug для dev-режима
   - Рабочая директория /var/www/html

3. docker/nginx/default.conf:
   - Настройка для Symfony (public/index.php как entry point)
   - Правильная передача PHP-FPM

4. .env.example с переменными:
   - DATABASE_URL=mysql://user:password@db:3306/currency_converter
   - REDIS_URL=redis://redis:6379
   - FREECURRENCY_API_KEY=your_api_key_here
   - FREECURRENCY_API_BASE_URL=https://api.freecurrencyapi.com/v1

5. Makefile с командами:
   - make up, make down, make build
   - make composer-install
   - make migrate, make fixtures
   - make sync-rates (запуск команды обновления курсов)
   - make test
   - make shell (bash в контейнер app)

Убедись что все сервисы корректно связаны через docker network, volumes персистентны, и проект запускается одной командой make build && make up.
```

---

## Этап 3 — Инициализация Symfony-проекта и базовая конфигурация

```
Создай инструкцию и файлы для инициализации Symfony 7 проекта внутри Docker-контейнера.

Шаги:
1. Команды для создания Symfony-проекта (symfony/skeleton) внутри контейнера
2. Установка необходимых пакетов через composer:
   - symfony/orm-pack (Doctrine ORM)
   - symfony/maker-bundle (dev)
   - symfony/twig-bundle (для админки)
   - symfony/asset (для CSS админки)
   - symfony/cache (для Redis кэширования)
   - symfony/lock (для блокировки параллельных запусков)
   - symfony/console (для команд)
   - guzzlehttp/guzzle (HTTP клиент)
   - symfony/monolog-bundle (логирование)
   - phpunit/phpunit (тесты)
   - symfony/validator
   - symfony/form (если нужно для админки)

3. Конфигурационные файлы:
   - config/packages/doctrine.yaml — подключение к MySQL, настройка mapping
   - config/packages/cache.yaml — настройка Redis как провайдера кэша
   - config/packages/lock.yaml — filesystem lock
   - config/packages/monolog.yaml — логирование в файл и stderr
   - config/services.yaml — биндинг интерфейсов к реализациям, автоконфигурация параметров API (api_key, base_url из .env)

4. Создай файл config/packages/currency_converter.yaml с параметрами модуля:
   - Список поддерживаемых валют (массив кодов: USD, EUR, GBP, RUB, TRY, JPY, CNY и т.д.)
   - TTL кэша (86400 секунд)
   - Базовая валюта для хранения курсов (USD)

Покажи итоговую структуру файлов проекта после инициализации.
```

---

## Этап 4 — Domain-слой: Entity, DTO, Exceptions

```
Реализуй Domain-слой проекта конвертации валют на Symfony 7 / PHP 8.2.

Создай следующие классы:

1. Entity: src/Entity/Currency.php (Doctrine Entity)
   - id (int, auto-increment)
   - code (string, unique, length 3) — ISO код
   - name (string) — полное название
   - symbol (string) — символ валюты
   - isActive (bool, default true)
   - createdAt (DateTimeImmutable)
   - Doctrine mapping через атрибуты PHP 8

2. Entity: src/Entity/ExchangeRate.php (Doctrine Entity)
   - id (bigint, auto-increment)
   - baseCurrency (ManyToOne -> Currency)
   - targetCurrency (ManyToOne -> Currency)
   - rate (string/DECIMAL(20,10)) — курс, хранится как строка для bcmath
   - fetchedAt (DateTimeImmutable) — когда получен с API
   - createdAt (DateTimeImmutable)
   - Составной уникальный индекс: (baseCurrency, targetCurrency, fetchedAt)
   - Валидация rate > 0 в конструкторе

3. DTO: src/DTO/ConversionResultDTO.php
   - readonly class с полями: originalAmount, originalCurrency, convertedAmount, targetCurrency, rate, convertedAt

4. DTO: src/DTO/ExchangeRateDTO.php
   - readonly class: baseCurrency, targetCurrency, rate, fetchedAt

5. DTO: src/DTO/SyncResultDTO.php
   - readonly class: updatedCount, skippedCount, errorCount, errors[], syncedAt

6. Exceptions в src/Exception/:
   - CurrencyNotFoundException extends \DomainException
   - RateNotFoundException extends \DomainException
   - ExchangeRateApiException extends \RuntimeException (с factory-методами для разных ошибок)
   - ConversionException extends \RuntimeException

Используй PHP 8.2+ фичи: readonly properties, named arguments. Все классы должны быть строго типизированы (declare strict_types=1).
```

---

## Этап 5 — Repository-слой

```
Реализуй Repository-слой для проекта конвертации валют на Symfony 7 + Doctrine ORM.

1. Interface: src/Domain/Repository/CurrencyRepositoryInterface.php
   - findByCode(string $code): ?Currency
   - findAllActive(): array
   - findAll(): array
   - save(Currency $currency): void

2. Interface: src/Domain/Repository/ExchangeRateRepositoryInterface.php
   - findLatestRate(Currency $base, Currency $target): ?ExchangeRate
   - findAllLatestRates(): array (последний курс для каждой пары)
   - findRatesByDate(\DateTimeInterface $date): array
   - saveMany(array $rates): void (batch insert для эффективности)
   - save(ExchangeRate $rate): void
   - findLatestFetchedAt(): ?DateTimeImmutable

3. Реализация: src/Infrastructure/Persistence/Doctrine/DoctrineCurrencyRepository.php
   - extends ServiceEntityRepository
   - Реализует CurrencyRepositoryInterface
   - Оптимизированные DQL-запросы

4. Реализация: src/Infrastructure/Persistence/Doctrine/DoctrineExchangeRateRepository.php
   - extends ServiceEntityRepository
   - Реализует ExchangeRateRepositoryInterface
   - findAllLatestRates(): использует подзапрос с MAX(fetchedAt)
   - saveMany(): batch flush каждые 50 записей

5. Регистрация в services.yaml:
   - Привязка интерфейсов к Doctrine-реализациям

Убедись, что:
- Все запросы оптимизированы (нет N+1, есть JOIN FETCH где нужно)
- findAllLatestRates() возвращает только самый свежий курс для каждой пары валют
```

---

## Этап 6 — HTTP-клиент и провайдер курсов

```
Реализуй интеграцию с freecurrencyapi.com для проекта конвертации валют на Symfony 7.

Требование: НЕ использовать готовые библиотеки для freecurrencyapi. Реализовать через Guzzle.

1. Interface: src/Domain/Service/ExchangeRateProviderInterface.php
   - fetchRates(string $baseCurrency, array $currencies): array<ExchangeRateDTO>
   - Возвращает массив DTO, не зависит от конкретного API

2. HTTP Client wrapper: src/Infrastructure/ExternalApi/FreeCurrencyApiClient.php
   - Конструктор принимает: Guzzle Client, string $apiKey, string $baseUrl, LoggerInterface
   - Метод getLatestRates(string $baseCurrency, array $currencies): array (сырые данные)
   - Обработка HTTP ошибок:
     * 401 → ExchangeRateApiException('Invalid API key')
     * 429 → ExchangeRateApiException('Rate limit exceeded')
     * 5xx → ExchangeRateApiException('API server error')
     * Timeout → ExchangeRateApiException('API request timeout')
   - Guzzle настройки: timeout 10 сек, connect_timeout 5 сек
   - Логирование каждого запроса (URL, статус, время ответа)

3. Реализация провайдера: src/Infrastructure/ExternalApi/FreeCurrencyApiProvider.php
   - implements ExchangeRateProviderInterface
   - Маппит сырой JSON-ответ API в массив ExchangeRateDTO
   - Валидирует ответ (проверяет наличие поля 'data', корректность значений)
   - Конвертирует float→string через number_format для bcmath-совместимости

4. Конфигурация в services.yaml:
   - FreeCurrencyApiClient получает api_key и base_url из параметров (env переменные)
   - Guzzle Client создаётся через factory
   - ExchangeRateProviderInterface привязан к FreeCurrencyApiProvider

Убедись что клиент полностью изолирован от бизнес-логики и может быть заменён без изменения остального кода.
```

---

## Этап 7 — Сервис конвертации и синхронизации

```
Реализуй сервисный слой для проекта конвертации валют на Symfony 7.

1. Interface: src/Service/CurrencyConverterInterface.php
   - convert(float|string $amount, string $fromCurrency, string $toCurrency): ConversionResultDTO
   - getRate(string $fromCurrency, string $toCurrency): string

2. Реализация: src/Service/CurrencyConverter.php
   - implements CurrencyConverterInterface
   - Зависимости: ExchangeRateRepositoryInterface, CurrencyRepositoryInterface, CacheInterface (Symfony Cache), LoggerInterface

   Логика convert():
   - Валидация входных параметров (amount > 0, валюты существуют)
   - Если fromCurrency === toCurrency → вернуть без конвертации
   - Получить курс: сначала из кэша (Redis), если нет — из БД
   - Стратегия разрешения курса:
     * 1) Прямой курс (FROM → TO)
     * 2) Обратный курс (TO → FROM), затем инверсия
     * 3) Кросс-курс через pivot (USD): FROM → USD → TO
   - Вычисление через bcmath: bcmul($amount, $rate, 10)
   - Кэширование с null-маркерами для защиты от cache stampede

3. Сервис синхронизации: src/Service/ExchangeRateSyncService.php
   - Метод sync(?string $baseCurrency = null):
   - Получить список активных валют из БД
   - Запросить курсы через провайдер
   - Сохранить все курсы в БД через saveMany()
   - Инвалидировать кэш курсов в обе стороны
   - Диспатчить события (RatesUpdatedEvent / RatesSyncFailedEvent)
   - Graceful degradation: если API упал — старые курсы НЕ удаляются

Все вычисления с деньгами — ТОЛЬКО через bcmath. Никаких float-операций для денежных сумм.
```

---

## Этап 8 — Symfony Console Commands

```
Создай Symfony Console Commands для управления курсами валют.

1. src/Command/SyncExchangeRatesCommand.php
   - Имя: app:sync-exchange-rates
   - Опции:
     * --base-currency (default: USD) — базовая валюта, передаётся в sync()
     * --force — принудительное обновление, даже если курсы уже обновлены сегодня
     * --dry-run — показать что будет обновлено, но НЕ вызывать sync()
   - Filesystem lock через LockFactory для предотвращения параллельного запуска
   - Проверка wasSyncedToday() через findLatestFetchedAt()
   - Коды возврата: 0 — успех, 1 — ошибка, 2 — уже обновлено, 3 — заблокировано

2. src/Command/ListCurrenciesCommand.php
   - Имя: app:currencies:list
   - Опции: --active, --inactive
   - Выводит таблицу всех валют через Symfony Console Table helper

Команды должны быть хорошо задокументированы (help-текст) и обрабатывать все возможные ошибки.
```

---

## Этап 9 — Админ-панель (Controller + Twig)

```
Создай админ-панель для отображения курсов валют на Symfony 7 + Twig.

1. Controller: src/Controller/Admin/ExchangeRateController.php
   - Route prefix: /admin/exchange-rates

   Методы:
   a) index() — GET /admin/exchange-rates
      - Получает все последние курсы, активные валюты, дату последней синхронизации

   b) convert() — POST /admin/exchange-rates/convert (AJAX)
      - Принимает JSON: {amount, from, to}
      - Возвращает JsonResponse с результатом конвертации

   c) sync() — POST /admin/exchange-rates/sync (AJAX)
      - Cooldown-защита (300 сек между синхронизациями)
      - Lock через LockFactory

2. Twig Templates:
   - Базовый layout с Bootstrap 5 (CDN)
   - Таблица курсов с сортировкой и фильтрацией на клиенте (vanilla JS)
   - Форма конвертации с AJAX
   - Кнопка "Sync Rates" с toast-уведомлениями
   - Responsive layout

3. Минимальные стили через Bootstrap 5 из CDN, без сборки.
```

---

## Этап 10 — Миграции, Fixtures и начальные данные

```
Создай Doctrine миграции и DataFixtures для проекта конвертации валют.

1. Миграция: создание таблиц currencies и exchange_rates
   - currencies: id, code (VARCHAR 3, unique), name, symbol, is_active, created_at
   - exchange_rates: id (bigint), base_currency_id (FK), target_currency_id (FK), rate (DECIMAL 20,10), fetched_at, created_at
   - Индексы: idx_rate_pair, idx_rate_pair_date, idx_rate_fetched, idx_rate_unique
   - FK с ON DELETE RESTRICT

2. DataFixtures: src/DataFixtures/CurrencyFixtures.php
   - Загружает валюты из конфига currency_converter.yaml
   - Идемпотентный: обновляет существующие, создаёт новые

3. Makefile команды: make migrate, make fixtures

4. Полная последовательность команд для первоначальной настройки.
```

---

## Этап 11 — Unit и Integration тесты

```
Напиши тесты для проекта конвертации валют на PHPUnit.

1. Unit тесты:

   a) tests/Unit/Service/CurrencyConverterTest.php
      - Mock ExchangeRateRepositoryInterface и CacheInterface
      - Конвертация с прямым курсом
      - Конвертация одинаковых валют (возвращает ту же сумму)
      - Кросс-курс через pivot
      - Исключения: отсутствие курса, невалидная сумма, валюта не найдена
      - Проверка точности bcmath

   b) tests/Unit/Service/FreeCurrencyApiProviderTest.php
      - Mock HTTP-клиента
      - Парсинг валидного ответа API
      - Обработка partial response, невалидных данных, нулевых курсов
      - Нормализация currency codes

   c) tests/Unit/Service/ExchangeRateSyncServiceTest.php
      - Успешная синхронизация, проверка saveMany и cache invalidation
      - Обработка ошибки API (старые курсы сохраняются)
      - Пустой список валют, только base currency

2. Integration тесты:
   a) tests/Integration/Repository/ExchangeRateRepositoryTest.php
      - Тестовая MySQL БД (currency_converter_test)
      - save и findLatestRate, findAllLatestRates, saveMany, findLatestFetchedAt

3. phpunit.xml.dist с настройками тестовой БД и .env.test
```

---

## Этап 12 — README и финальная сборка

```
Создай полную документацию проекта (README.md).

README.md должен содержать:
1. Описание проекта
2. Архитектура: схема слоёв, описание ключевых паттернов (Repository, Provider, DTO)
3. Быстрый старт: git clone → cp .env → make init → make sync-rates → открыть http://localhost:8080
4. Пример использования сервиса конвертации в коде
5. CLI-команды
6. Настройка cron
7. Запуск тестов
8. Структура проекта (дерево директорий)
9. Принятые технические решения (почему bcmath, Redis, интерфейсы)
10. Возможные улучшения
```

---

## Постобработка

После генерации кода по каждому этапу я проверял работоспособность проекта и вручную вносил исправления:

- Исправление конфигурации окружения (`.env`, Docker Compose)
- Удаление автоматически сгенерированных, но неиспользуемых файлов
- Приведение списка валют в соответствие с API freecurrencyapi.com
- Настройка тестовой базы данных
- Прочие мелкие правки для корректной работы всех компонентов вместе