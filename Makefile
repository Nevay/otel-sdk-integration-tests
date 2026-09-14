#!/usr/bin/make -f

CURRENT_UID := $(shell id -u)
CURRENT_GID := $(shell id -g)

COMPOSE = UID=$(CURRENT_UID) GID=$(CURRENT_GID) docker compose
PHP = $(COMPOSE) run --rm --no-deps php
SDKS = tbachert official

php:
	$(PHP) sh
# The container's PHP version can be overridden, e.g. `PHP_VERSION=8.4 make build`.
build:
	$(COMPOSE) build

dependencies-install:
	@for sdk in $(SDKS); do \
		echo "==> $$sdk"; \
		$(PHP) sh -c "cd sdks/$$sdk && composer install" || exit 1; \
	done

dependencies-update:
	@for sdk in $(SDKS); do \
		echo "==> $$sdk"; \
		$(PHP) sh -c "cd sdks/$$sdk && composer update" || exit 1; \
	done

test:
	@test -n "$(SDK)" || { echo "Usage: make test SDK=<tbachert|official> [ARGS=...]"; exit 1; }
	$(PHP) sh -c "cd sdks/$(SDK) && vendor/bin/phpunit $(ARGS)"
