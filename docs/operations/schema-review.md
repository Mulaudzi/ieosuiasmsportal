# Supplied database schema review

Sources reviewed: the schema-only export and the later data-bearing MariaDB 11.4.13 export for `ejetffbz_sms`. No database was modified and no sensitive row values are reproduced here.

## Preserve

All 29 existing tables remain. The legacy `campaigns` and `messages` tables are still required by email and reporting features. `contacts.group_id` and `group_contacts` currently coexist in application behavior, so neither is removed in the MVP migration. The existing `migrations` table remains separate from the new raw-PHP `schema_migrations` ledger.

## Add

- `users.auth_version` for token invalidation.
- `contacts.phone_normalized` and `contacts.phone_original`.
- A conservative existing-contact backfill for South African and already-valid international numbers; unrecognizable values remain `NULL` for review.
- Unique `(contacts.user_id, contacts.phone_normalized)` after collision preflight.
- `sms_campaigns` for canonical tenant-owned SMS campaigns.
- `sms_messages` for durable recipient-level queue and delivery state.
- `sms_provider_events` for idempotent LogicSMS receipts and polling results.
- `wallet_ledger` for idempotent reservation, settlement, release and payment-credit operations.
- `service_heartbeats` for worker, scheduler and DLR-poller health.
- Unique `(payments.gateway, payments.merchant_reference)` after duplicate preflight.

## Alter safely

- Widen `payments.user_id`, `payments.wallet_id`, and `payments.transaction_id` from unsigned `INT` to unsigned `BIGINT` so they match the supplied user, wallet and wallet-transaction identifiers.
- Use unsigned `BIGINT` ownership keys in all new canonical tables.
- Rename legacy `messages.telnyx_id` to `messages.legacy_provider_id`, preserving data, and rename its index. No historical identifier is dropped.

## Do not remove yet

- `campaigns`, `messages`, `dlr_logs`: legacy email/reporting and historical records still depend on them.
- `admin_users`, `account_type`: retained for migration compatibility while `users.role` is canonical.
- `payments.credits_added`: deprecated and written as zero, but older receipt/report code still references it.
- `contacts.group_id`: redundant with `group_contacts`, but older contact flows still read it.

These can be removed only in a later migration after code-reference checks, historical-data retention decisions, and a production snapshot rehearsal.

## Data decisions required from snapshot preflight

- Any wallet whose currency is not `ZAR` must be reviewed; balances are not automatically converted.
- Duplicate payment merchant references must be resolved before the new unique index.
- Duplicate normalized phone numbers must be resolved per tenant before backfill.
- Orphan ownership rows and invalid wallet states block migration.
