# ──────────────────────────────────────────────
#  Currency Converter — project commands
# ──────────────────────────────────────────────

.DEFAULT_GOAL := help

DOCKER_COMPOSE = docker compose
PHP_CONTAINER  = currency_app
EXEC           = $(DOCKER_COMPOSE) exec app
EXEC_ROOT      = $(DOCKER_COMPOSE) exec -u root app
CONSOLE        = $(EXEC) php bin/console

# ── Colors ──
GREEN  := \033[0;32m
YELLOW := \033[0;33m
RESET  := \033[0m

## ── Docker ──────────────────────────────────

.PHONY: build
build: ## Build all containers
	@test -f .env || cp .env.example .env && echo "$(YELLOW).env created from .env.example$(RESET)"
	$(DOCKER_COMPOSE) build --no-cache

.PHONY: up
up: ## Start all containers in background
	$(DOCKER_COMPOSE) up -d
	@echo "$(GREEN)✔ App running at http://localhost:8080$(RESET)"

.PHONY: down
down: ## Stop and remove all containers
	$(DOCKER_COMPOSE) down

.PHONY: restart
restart: down up ## Restart all containers

.PHONY: logs
logs: ## Tail logs from all containers
	$(DOCKER_COMPOSE) logs -f

.PHONY: ps
ps: ## Show running containers
	$(DOCKER_COMPOSE) ps

## ── Application ─────────────────────────────

.PHONY: composer-install
composer-install: ## Install PHP dependencies
	$(EXEC) composer install --prefer-dist --no-interaction

.PHONY: composer-update
composer-update: ## Update PHP dependencies
	$(EXEC) composer update --prefer-dist --no-interaction

.PHONY: migrate
migrate: ## Run database migrations
	$(CONSOLE) doctrine:migrations:migrate --no-interaction

.PHONY: fixtures
fixtures: ## Load data fixtures
	$(CONSOLE) doctrine:fixtures:load --no-interaction

.PHONY: sync-rates
sync-rates: ## Fetch latest exchange rates from API
	$(CONSOLE) app:sync-exchange-rates

.PHONY: cache-clear
cache-clear: ## Clear Symfony cache
	$(CONSOLE) cache:clear

## ── Testing ─────────────────────────────────

.PHONY: test
test: ## Run full test suite
	$(EXEC) php bin/phpunit

.PHONY: test-unit
test-unit: ## Run unit tests only
	$(EXEC) php bin/phpunit --testsuite=unit

.PHONY: test-coverage
test-coverage: ## Run tests with HTML coverage report
	$(EXEC) php -d xdebug.mode=coverage bin/phpunit --coverage-html var/coverage

## ── Code Quality ────────────────────────────

.PHONY: lint
lint: ## Run PHP CS Fixer in dry-run mode
	$(EXEC) vendor/bin/php-cs-fixer fix --dry-run --diff

.PHONY: lint-fix
lint-fix: ## Fix code style issues
	$(EXEC) vendor/bin/php-cs-fixer fix

.PHONY: phpstan
phpstan: ## Run PHPStan static analysis
	$(EXEC) vendor/bin/phpstan analyse

## ── Shell & Debug ───────────────────────────

.PHONY: shell
shell: ## Open bash shell in app container
	$(DOCKER_COMPOSE) exec app bash

.PHONY: shell-root
shell-root: ## Open root bash shell in app container
	$(DOCKER_COMPOSE) exec -u root app bash

.PHONY: db-shell
db-shell: ## Open MySQL CLI
	$(DOCKER_COMPOSE) exec db mysql -u currency_user -pcurrency_pass currency_converter

.PHONY: redis-cli
redis-cli: ## Open Redis CLI
	$(DOCKER_COMPOSE) exec redis redis-cli

## ── Full Setup ──────────────────────────────

.PHONY: db-create
db-create: ## Create database if not exists
	$(CONSOLE) doctrine:database:create --if-not-exists --no-interaction

.PHONY: init
init: build up composer-install db-create migrate fixtures ## Full project init: build → up → deps → DB → fixtures
	@echo ""
	@echo "$(GREEN)════════════════════════════════════════════════$(RESET)"
	@echo "$(GREEN)  ✔ Project ready at http://localhost:8080      $(RESET)"
	@echo "$(GREEN)════════════════════════════════════════════════$(RESET)"
	@echo ""
	@echo "  Next: $(YELLOW)make sync-rates$(RESET) to fetch live exchange rates"
	@echo "  Then: open $(YELLOW)http://localhost:8080/admin/exchange-rates$(RESET)"
	@echo ""

## ── Help ────────────────────────────────────

.PHONY: help
help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "  $(GREEN)%-18s$(RESET) %s\n", $$1, $$2}'
