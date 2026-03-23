# Makefile — test helpers for developers
# Usage:
#   make test            # run the dockerized test suite (recommended)
#   make test ARGS="-- --filter Name"  # pass args to PHPUnit via the test script
#   make phpunit         # run vendor/bin/phpunit directly (requires vendor/ present)

SHELL := /bin/bash

.PHONY: help test phpunit docker-test

help:
	@echo "Makefile targets:"
	@echo "  test        Run the dockerized PHPUnit suite (./scripts/run-tests-docker.sh)"
	@echo "  phpunit     Run vendor/bin/phpunit directly (requires vendor/ present)"
	@echo "Usage: make test ARGS=\"-- --filter FooTest\""

# Default: run dockerized test runner. Pass PHPUnit args in ARGS.
test:
	@echo "Running dockerized test suite..."
	@./scripts/run-tests-docker.sh $(ARGS)

# Run phpunit directly from vendor/bin — useful when vendor/ exists locally.
phpunit:
	@if [ -x vendor/bin/phpunit ]; then \
		vendor/bin/phpunit $(ARGS); \
	else \
		echo "vendor/bin/phpunit not found. Run 'composer install' or use 'make test' to run via Docker."; exit 1; \
	fi
