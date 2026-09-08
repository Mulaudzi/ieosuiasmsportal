# Database and migrations

Run `php api/bin/migrate.php` with the production API environment configured. The runner records each ordered migration in `schema_migrations`; it never resets the database.

Migration `001_mvp_foundation` adds canonical auth/contact fields and creates `sms_campaigns`, `sms_messages`, `sms_provider_events`, and `wallet_ledger`. Existing legacy tables remain in place. The old destructive PayOS rebuild SQL is not part of the migration runner and must not be executed.

MySQL 8+ or MariaDB 10.6+ is required for worker `FOR UPDATE SKIP LOCKED` semantics. The supplied MariaDB 11.4 schema satisfies this version requirement. Back up the database and test migrations on a production snapshot before first deployment.

The supplied schema is MariaDB 11.4.13. Before migration, run `php api/bin/schema-preflight.php` against a restored snapshot. It reports orphan ownership records, duplicate payment references, non-ZAR wallets, invalid wallet balances and normalized-phone collisions without changing data. A non-zero exit means the migration must not proceed.

After the schema migration, run `php api/bin/backfill-phone-normalization.php` in dry-run mode. Resolve every reported per-tenant collision, then rerun it with `--apply`. Apply mode refuses to continue while collisions remain and normalizes legacy SMS opt-outs in the same transaction.
