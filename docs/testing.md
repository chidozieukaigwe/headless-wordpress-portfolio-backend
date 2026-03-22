# Dockerized PHPUnit Test Suite

This document explains the project's Dockerized PHPUnit test setup: how it works, how to use it, why it was chosen, and important implementation details to avoid accidental commits of WordPress core during test runs.

## Approach

- Tests run inside a disposable PHP container with a transient MySQL container created by Docker Compose (`docker-compose.test.yml`).
- The helper script `./scripts/run-tests-docker.sh` orchestrates starting containers, waiting for DB readiness, installing dependencies (when needed), running `vendor/bin/phpunit`, and tearing down containers.
- The test bootstrap (`tests/bootstrap.php`) uses the bundled WP test library (configured via `phpunit.xml` -> `WP_TESTS_DIR`) and manually loads the headless theme and a few lightweight ACF and CPT stubs for unit tests.

## How it works / Usage

- Requirements: Docker and Docker Compose installed locally.
- Typical run (from project root):

```bash
./scripts/run-tests-docker.sh
```

- Pass PHPUnit args after `--` (they are forwarded to PHPUnit). Example:

```bash
./scripts/run-tests-docker.sh -- --filter CustomPostTypeTest
```

- Behavior notes:
  - The script exports `WP_TEST_DB_HOST`, `WP_TEST_DB_NAME`, `WP_TEST_DB_USER`, and `WP_TEST_DB_PASSWORD` for the PHP container so the WP test bootstrap can connect to the DB.
  - The script will skip running `composer install` inside the container if `vendor/` already exists on the host. This avoids the `johnpbloch/wordpress` installer/plugin creating a `wordpress/` directory in the project root during test runs.
  - If `vendor/` is not present, the script runs `composer install` inside the container; in that case the composer install may (depending on flags/plugins) create additional files such as a `wordpress/` directory. See the "Composer and WordPress core" section below.

## Benefits of this architecture

- Reproducible test environment: the same PHP version and extensions are used on every run (via the Dockerfile), avoiding "works on my machine" mismatches.
- No local DB required: tests run against an isolated MySQL container and are torn down after tests complete.
- Clean state: containers and test DB volume are removed after test runs, keeping the host filesystem clean.
- CI-friendly: the same script and compose file can be used in CI with minimal changes.

## Impact of test suite paths and Composer behavior

- Composer package `johnpbloch/wordpress` (or similar installers) may run installer scripts that place WordPress core into `./wordpress` by default. That is why a `wordpress/` folder may appear in the project root after running `composer install` inside the container.
- To prevent accidental creation/commit of WordPress core:
  - The repository `.gitignore` explicitly ignores `wordpress/`.
  - The test runner is conservative: it skips `composer install` inside the container when `vendor/` exists on the host (the most common local workflow). When a fresh environment like CI runs `composer install`, configure CI to use `--no-plugins` or `--no-scripts`, or to set an alternate install path for core.

## Important implementation details (doc-worthy points)

- `tests/bootstrap.php` loads the WP test bootstrap from `WP_TESTS_DIR`. By default `phpunit.xml` sets `WP_TESTS_DIR` to `vendor/wp-phpunit/wp-phpunit` but the tests will fall back to `/tmp/wordpress-tests-lib` if not set.
- `tests/bootstrap.php` registers a small set of test helpers:
  - Lightweight stubs for ACF functions (`update_field`, `get_field`, `get_fields`) to run unit tests without the full ACF plugin installed.
  - Registering required custom post types (`project`, `testimonial`) when test runs need them and the theme does not register them in the test environment.
  - These test-time stubs keep unit tests fast and decoupled from optional plugin behavior.
- PHP runtime: the Docker test runner uses `docker/php/Dockerfile` which sets the PHP version used for testing. Make sure the PHP version declared in Dockerfile matches composer.lock platform requirements (we use PHP 8.4 for the current lock file).

## Recommendations for CI / Teams

- In CI, run `composer install` before tests in a controlled way; prefer `composer install --no-interaction --prefer-dist --no-plugins --no-scripts` unless you explicitly want installers to run. This prevents core being written into the repo.
- Cache Composer artifacts between CI runs (e.g., cache `~/.composer/cache` or the project's `vendor/`) to speed builds.
- If you want composer-managed WordPress core in a different place, configure the install path in `composer.json` `extra` settings or set the `wordpress-install-dir` for the installer plugin.

## Troubleshooting

- If you see a `wordpress/` directory created after a test run:
  1. Check whether `vendor/` existed before the run. If not, the script ran `composer install` inside the container and an installer placed core files in `./wordpress`.
  2. Either delete `wordpress/` and re-run tests with `vendor/` present, or re-run install with `--no-plugins --no-scripts`.
- If tests fail due to missing ACF or CPT behavior, consider either enabling the ACF plugin in the test bootstrap or extending the lightweight stubs in `tests/bootstrap.php`.

## Files of interest

- `scripts/run-tests-docker.sh` — orchestrates Docker Compose and PHPUnit runs.
- `docker-compose.test.yml` — defines `db` and `php` services used by the tests.
- `docker/php/Dockerfile` — test runner image (PHP + extensions + Composer).
- `phpunit.xml` — PHPUnit configuration and `WP_TESTS_DIR` / `WP_PHPUNIT__TESTS_CONFIG` env settings.
- `tests/bootstrap.php` — WP PHPUnit bootstrap and manual theme/plugin loading.

If you want, I can also add a short CI example (GitHub Actions workflow) that runs the tests in a clean environment and avoids writing `wordpress/` into the repo.
