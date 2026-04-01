# Backlog

## Webhook retry & operator ergonomics

- Consider adding a UI/page or WP-CLI `headless retry-queue` command to inspect and manually flush the queue.
  - Purpose: let operators view queued webhook payloads, examine error details, retry or drop items, and drain the queue during incidents.
  - Implementation notes: expose the queue stored in the `headless_webhook_queue` option, provide per-item retry/dismiss actions, and protect the UI with capability checks (manage_options).

## Retry scheduling

- Decide retry schedule: the current implementation processes the queue via the `headless_retry_webhooks` cron hook on an `hourly` schedule.
  - Option A: change the cron schedule to a custom interval (e.g., every 5 minutes) for faster retries.
  - Option B: use `wp_schedule_single_event()` when enqueuing to schedule per-item retries with exponential backoff (faster and more targeted).
  - Considerations: monitor queue length, max attempts (`HEADLESS_WEBHOOK_MAX_ATTEMPTS`), backoff strategy, and admin-configurable settings.
