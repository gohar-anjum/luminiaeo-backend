.PHONY: help build up down restart logs shell migrate fresh seed test

help: ## Show this help message
	@echo 'Usage: make [target]'
	@echo ''
	@echo 'Available targets:'
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "  %-15s %s\n", $$1, $$2}' $(MAKEFILE_LIST)

build: ## Build all Docker containers
	docker-compose build

up: ## Start all containers
	docker-compose up -d

down: ## Stop all containers
	docker-compose down

restart: ## Restart all containers
	docker-compose restart

logs: ## Show logs from all containers
	docker-compose logs -f

logs-app: ## Show logs from Laravel app
	docker-compose logs -f app

logs-queue: ## Show logs from queue worker
	docker-compose logs -f queue

shell: ## Open shell in Laravel app container
	docker-compose exec app bash

shell-root: ## Open shell as root in Laravel app container
	docker-compose exec -u root app bash

migrate: ## Run database migrations
	docker-compose exec app php artisan migrate

migrate-fresh: ## Fresh migration with seeding
	docker-compose exec app php artisan migrate:fresh --seed

seed: ## Run database seeders
	docker-compose exec app php artisan db:seed

test: ## Run PHPUnit tests
	docker-compose exec app php artisan test

composer-install: ## Install Composer dependencies
	docker-compose exec app composer install

composer-update: ## Update Composer dependencies
	docker-compose exec app composer update

npm-install: ## Install NPM dependencies
	docker-compose exec app npm install

npm-build: ## Build frontend assets
	docker-compose exec app npm run build

key-generate: ## Generate application key
	docker-compose exec app php artisan key:generate

cache-clear: ## Clear all caches
	docker-compose exec app php artisan cache:clear
	docker-compose exec app php artisan config:clear
	docker-compose exec app php artisan route:clear
	docker-compose exec app php artisan view:clear

optimize: ## Optimize Laravel
	docker-compose exec app php artisan config:cache
	docker-compose exec app php artisan route:cache
	docker-compose exec app php artisan view:cache

queue-work: ## Start queue worker manually
	docker-compose exec queue php artisan queue:work

setup: build up migrate key-generate ## Initial setup (build, start, migrate, generate key)

