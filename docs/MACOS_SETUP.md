# Запуск проекта на macOS

Пошаговая инструкция от чистой системы до работающего приложения.

---

## 1. Установка зависимостей

### Homebrew (если ещё не установлен)

```bash
/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"
```

### Docker Desktop

```bash
brew install --cask docker
```

После установки запустить Docker Desktop из Launchpad.
Дождаться, пока иконка в menu bar перестанет анимироваться —
это значит, что Docker daemon готов.

Проверка:

```bash
docker --version
# Docker version 27.x.x

docker compose version
# Docker Compose version v2.x.x
```

### Make и Git

```bash
# make входит в Xcode Command Line Tools
xcode-select --install

# Git (если ещё нет)
brew install git
```

---

## 2. Клонирование и настройка

```bash
# Клонировать репозиторий
git clone <URL_РЕПОЗИТОРИЯ> currency-converter
cd currency-converter

# Создать .env из шаблона
cp .env.example .env
```

Открыть `.env` и вписать свой API-ключ:

```bash
# Получить бесплатный ключ: https://freecurrencyapi.com/register
nano .env
```

Найти строку и заменить значение:

```
FREECURRENCY_API_KEY=fca_live_ваш_ключ_здесь
```

---

## 3. Запуск (один раз)

### Вариант A — автоматический (рекомендуется)

```bash
make build && make up
bash init-symfony.sh
```

Скрипт сам создаст Symfony-проект, установит все пакеты
и разложит конфигурацию.

### Вариант B — полный автомат

```bash
make init
```

Эта команда выполнит build → up → composer install → migrate → fixtures
последовательно.

---

## 4. Проверка

```bash
# Все контейнеры запущены?
make ps
```

Ожидаемый вывод — четыре контейнера в статусе `running (healthy)`:

```
NAME              STATUS
currency_app      Up (healthy)
currency_nginx    Up
currency_db       Up (healthy)
currency_redis    Up (healthy)
```

```bash
# Symfony отвечает?
docker compose exec app php bin/console --version
# → Symfony 7.1.x

# БД подключена?
docker compose exec app php bin/console doctrine:database:create --if-not-exists

# Redis работает?
docker compose exec redis redis-cli ping
# → PONG

# Веб-сервер?
curl -I http://localhost:8080
# → HTTP/1.1 200 OK (или 404 — маршруты ещё не созданы)
```

Открыть в браузере: **http://localhost:8080**

---

## 5. Повседневные команды

| Что сделать | Команда |
|---|---|
| Запустить проект | `make up` |
| Остановить проект | `make down` |
| Перезапустить | `make restart` |
| Зайти в контейнер PHP | `make shell` |
| Зайти в MySQL | `make db-shell` |
| Зайти в Redis | `make redis-cli` |
| Установить зависимости | `make composer-install` |
| Выполнить миграции | `make migrate` |
| Загрузить фикстуры | `make fixtures` |
| Обновить курсы валют | `make sync-rates` |
| Запустить тесты | `make test` |
| Только unit-тесты | `make test-unit` |
| Покрытие кода (HTML) | `make test-coverage` |
| Проверить стиль кода | `make lint` |
| Исправить стиль кода | `make lint-fix` |
| Логи всех контейнеров | `make logs` |
| Все доступные команды | `make help` |

---

## 6. Оптимизация Docker на macOS

Docker на macOS работает через виртуальную машину, и дисковые
операции с volume-монтированием заметно медленнее, чем на Linux.
Несколько способов это ускорить.

### VirtioFS (рекомендуется)

Docker Desktop → Settings → General → включить
**Use VirtioFS for file sharing**. Это самый быстрый файловый
драйвер на macOS, даёт прирост в 2-5 раз по сравнению с gRPC FUSE.

### Ресурсы VM

Docker Desktop → Settings → Resources:

| Параметр | Рекомендация |
|---|---|
| CPUs | 4+ (половина от доступных) |
| Memory | 4 GB минимум, 6 GB для комфорта |
| Disk | 30 GB+ |

### Исключение vendor/ из синхронизации

Директория `vendor/` содержит тысячи мелких файлов.
Хранение её в named volume вместо bind mount значительно
ускоряет Composer и автозагрузку.

Для этого в `docker-compose.yml` уже предусмотрен
`composer_cache` volume. Если тормоза ощутимые, можно
дополнительно вынести `vendor/` в volume:

```yaml
# docker-compose.override.yml (создать в корне проекта)
services:
  app:
    volumes:
      - .:/var/www/html
      - vendor_data:/var/www/html/vendor

volumes:
  vendor_data:
```

После этого `composer install` будет писать vendor/ внутрь
Docker volume, а не синхронизировать с хостом. Минус —
IDE на хосте не увидит vendor/ для автокомплита.
Компромисс: запустить `composer install` и на хосте тоже
(потребуется локальный PHP 8.2).

---

## 7. Xdebug + PhpStorm

### Настройка PhpStorm

1. **Settings → PHP → Servers**:
   - Name: `currency-converter` (совпадает с `PHP_IDE_CONFIG` в docker-compose.yml)
   - Host: `localhost`
   - Port: `8080`
   - Debugger: Xdebug
   - ✅ Use path mappings:
     - `/path/to/project` → `/var/www/html`

2. **Settings → PHP → Debug**:
   - Xdebug port: `9003`
   - ✅ Can accept external connections

3. **Включить прослушивание**: кнопка «Start Listening for PHP Debug Connections» (иконка телефона в toolbar).

### Запуск отладки

```bash
# В браузере — установить расширение Xdebug Helper,
# включить режим Debug и перезагрузить страницу.

# Для CLI-команд:
docker compose exec -e XDEBUG_TRIGGER=1 app \
    php bin/console app:exchange-rates:update
```

### Xdebug + VS Code

Установить расширение **PHP Debug** (xdebug.php-debug).

`.vscode/launch.json`:

```json
{
    "version": "0.2.0",
    "configurations": [
        {
            "name": "Listen for Xdebug",
            "type": "php",
            "request": "launch",
            "port": 9003,
            "pathMappings": {
                "/var/www/html": "${workspaceFolder}"
            }
        }
    ]
}
```

---

## 8. Apple Silicon (M1/M2/M3/M4)

Все образы в проекте совместимы с ARM64:

| Образ | ARM64-поддержка |
|---|---|
| `php:8.2-fpm` | Нативный |
| `nginx:1.25-alpine` | Нативный |
| `mysql:8.0` | Нативный (с 8.0.29) |
| `redis:7-alpine` | Нативный |
| `composer:2.7` | Нативный |

Принудительная эмуляция не нужна.
Если по какой-то причине образ тянет amd64-вариант:

```bash
# Явно указать платформу
DOCKER_DEFAULT_PLATFORM=linux/arm64 make build
```

---

## 9. Решение типичных проблем

### Порт 8080 занят

```bash
# Найти, кто слушает порт
lsof -i :8080

# Вариант 1 — убить процесс
kill -9 <PID>

# Вариант 2 — сменить порт
# В docker-compose.yml изменить "8080:80" на "8081:80"
```

### Порт 3306 занят (локальный MySQL)

```bash
# Остановить локальный MySQL
brew services stop mysql

# Или сменить порт в docker-compose.yml: "33060:3306"
# Тогда подключаться к MySQL: localhost:33060
```

### «Cannot connect to Docker daemon»

```bash
# Проверить, запущен ли Docker Desktop
open -a Docker

# Подождать 10-15 секунд и повторить
docker ps
```

### MySQL не стартует / health check fails

```bash
# Посмотреть логи
docker compose logs db

# Частая причина — битый volume. Пересоздать:
make down
docker volume rm currency-converter_mysql_data
make up
```

### Composer: out of memory

```bash
# Увеличить лимит памяти PHP для Composer
docker compose exec app bash -c \
    "COMPOSER_MEMORY_LIMIT=-1 composer install"
```

### Права на файлы (permission denied)

```bash
# Внутри контейнера PHP работает от www-data (uid 33).
# Если файлы создаются от root, поправить:
docker compose exec -u root app chown -R www-data:www-data var/
```

---

## 10. Полезные алиасы

Добавить в `~/.zshrc` (или `~/.bashrc`):

```bash
# Быстрый доступ к проекту
alias cc="cd ~/projects/currency-converter"
alias ccup="cc && make up"
alias ccdown="cc && make down"
alias ccsh="cc && make shell"
alias cclog="cc && make logs"
alias ccsync="cc && make sync-rates"
```

Применить: `source ~/.zshrc`
