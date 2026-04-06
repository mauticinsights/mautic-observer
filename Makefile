# Mautic Insights plugin — dev tooling
COMPOSER ?= composer
PHP_CS_FIXER ?= vendor/bin/php-cs-fixer
PHPUNIT ?= vendor/bin/phpunit
PHPSTAN ?= vendor/bin/phpstan
PHPSTAN_CONFIG ?= phpstan.neon.dist

.PHONY: help install cs-fixer cs-check phpstan test test-coverage ci clean

help:
	@echo "Targets:"
	@echo "  make install       composer install"
	@echo "  make cs-fixer      run PHP CS Fixer (@Symfony rules, fix files)"
	@echo "  make cs-check      PHP CS Fixer dry-run (CI)"
	@echo "  make phpstan       PHPStan static analysis ($(PHPSTAN_CONFIG))"
	@echo "  make test          PHPUnit"
	@echo "  make test-coverage PHPUnit with text coverage"
	@echo "  make ci            cs-check + phpstan + test (same as composer ci)"
	@echo "  make clean         remove vendor and caches"

install:
	$(COMPOSER) install

cs-fix: install
	$(PHP_CS_FIXER) fix --verbose

cs-check: install
	$(PHP_CS_FIXER) fix --dry-run --diff --verbose

phpstan: install
	$(PHPSTAN) analyse -c $(PHPSTAN_CONFIG) --no-progress --memory-limit=512M

all:
	make cs-fix
	make phpstan
	make test

test: install
	$(PHPUNIT)

test-coverage: install
	$(PHPUNIT) --coverage-text

ci: install
	$(COMPOSER) run-script ci

clean:
	rm -rf vendor
	rm -f .php-cs-fixer.cache .phpunit.result.cache
	rm -rf .phpstan-cache
