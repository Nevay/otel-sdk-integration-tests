#!/usr/bin/make -f

CURRENT_UID := $(shell id -u)
CURRENT_GID := $(shell id -g)

COMPOSE = UID=$(CURRENT_UID) GID=$(CURRENT_GID) docker compose
PHP = $(COMPOSE) run --rm --no-deps php

php:
	$(PHP) sh
build:
	$(COMPOSE) build

dependencies-install:
	$(PHP) composer install
dependencies-update:
	$(PHP) composer update
