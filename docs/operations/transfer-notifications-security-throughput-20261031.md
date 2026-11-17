# Transfer notifications, optional verification and SMS throughput

## Scope

Owner-approved SMS Portal release. No bulk-email campaign activation, Central Auth release, payment processing, customer credit adjustment or SMS resend.

The live diagnosis identified two deployment gaps: `notification_events` was absent and the deployed `NotificationController::index` did not invoke the notification reconciler. Repair both; creating the table alone is insufficient.

## Database and activation

- Apply only `api/migrations/024_transfer_notifications.php` after a recoverable schema/notification/history backup. It adds `notification_events` (the missing 013 prerequisite) and `credit_transfer_receipts`; it does not alter financial rows or run other migrations.
- Record only `024_transfer_notifications` in `schema_migrations`. Do not use the general migration runner; deferred email migration 019 remains excluded.
- Deploy the receipt service before its callers. Deploy the mail renderer before the email worker. Set `CREDIT_TRANSFER_RECEIPTS_ENABLED=true` only after the schema and code are verified.
- Existing recent transfers get deduplicated sender/recipient in-app notifications during account-scoped reconciliation. The release reconciles the latest transfer's two parties explicitly. No historic transfer emails are automatically backfilled.
- New customer and admin transfers queue exactly one email to the source account, including an administrator-initiated transfer. The queue insert participates in the financial transaction. A missing queue when enabled fails the transaction rather than silently losing the promised receipt.
- The existing `payment-receipt-worker.php` cron dispatches committed receipts outside the financial transaction. Concurrent worker claims are atomic. Original verified email is snapshotted; changed/unverified/inactive identities are held for review rather than redirected.
- SMTP uncertainty and expired processing leases become `held`, never automatic resends. Inspect the SMTP/provider evidence and transfer reference before any operator requeue. Do not repeat the financial transfer to repair an email.
- Completion emails contain the committed credit amount, reference, timestamp and fixed wallet link; no verification code, balance, password or recovery secret. The configured From is unchanged; security help/Reply-To is `security@ieosuia.com`.

## Optional app-only sign-in check

Keep `SMS_LOGIN_ENROLLMENT_ENABLED=true`, clear `SMS_LOGIN_ENROLLMENT_PILOT_USERS`, and retain the existing dedicated secret and required security guard. This opens enrollment; it does not enroll anyone or change another IEOSUIA application's verification setting.

Customers explicitly enable in Settings > Security, confirm their emailed six-digit code, and save their one-time recovery codes. Email verification is an extra sign-in step, not phishing-resistant MFA. No real customer enrollment was performed by this release.

Password copy now explains that the secure reset link changes the shared Central Auth password, may require signing in again, and does not delete contacts, campaigns or credits. The app-local extra verification setting is not reset by the UI link.

## Throughput

Production cron uses `sms-worker.php --once`, a time-bounded cycle, not a ten-message test. The old ten-recipient batch override is removed. Configuration:

```
SMS_PROVIDER_RATE_PER_SECOND=40
SMS_WORKER_CONCURRENCY=20
SMS_WORKER_BATCH_SIZE=100
```

The 55-second cycle still reserves provider timeout plus cleanup headroom. The larger start budget accommodates that shorter submission window. There is still one advisory-lock-owning worker, a hard ceiling of 20 HTTP requests in flight, pacing across batches, original message references, duplicate checking, serialized settlements and durable uncertain-result holds.

The final loopback test exercises 1,000 synthetic messages across 100-message batches with the actual cron cutoff. See the private verification receipt for the measured duration. This proves the local transport/settlement path, not live LogicSMS or handset-delivery throughput. Queue wake-up, provider latency, database contention and network conditions can increase elapsed customer time. Never promise delivery of 1,000 messages in a minute.

## Acceptance and rollback

Private release evidence: `.sms-audit.local/transfer-security-throughput-20261030/`. Preserve backups, manifests, schema receipts and test logs; they may contain private configuration or notification content and must not be committed.

Required gates: SQLite transfer/receipt/security tests, real MariaDB transfer concurrency and notification-controller tests using captured notification DDL, pacing/rollback/no-resend transport tests, curated frontend typecheck and unit tests, Chrome/Edge desktop/mobile checks, no-network email previews, live file readback, schema verification, authenticated endpoint boundaries, natural worker heartbeat and cron checks.

Before publishing, capture current files and environment, not an old checkout. Independently changed WhatsApp configuration must remain untouched. Retain old immutable frontend assets; switch HTML only after new assets are uploaded.

Rollback: disable new receipt dispatch/enqueue together, restore scoped PHP/HTML files and SMS worker rate/concurrency/batch from the captured baseline. Preserve other current environment keys and all financial/receipt records. Do not drop migration 024 tables, remove ledger entries, reverse customer transfers or weaken login guards. Keep existing enrolled accounts' verification functional even if new enrollment is paused. Resume held SMTP outcomes only after evidence-backed reconciliation.

Live checks do not substitute for a customer's opt-in/sign-out/sign-in acceptance or confirmation that a real receipt arrived. No test SMS, transfer, payment, password reset or email was initiated by the release.
