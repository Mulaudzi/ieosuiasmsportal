# Security review

- Secrets: local deployment/env files must stay ignored; no credential values may appear in source, logs, artifacts, or reports.
- Authentication: central identity is canonical; Google and local password paths are intentionally removed/disabled.
- Authorization: user resources require ownership filters; admin endpoints require admin middleware. Live cross-tenant negative testing remains open.
- Payments: only verified PayOS callbacks may finalize a payment; callback idempotency and amount/reference binding are release-critical.
- Webhooks: LogicSMS and PayOS endpoints require secret/signature verification, replay-safe persistence, bounded payloads, and safe logging.
- Inputs: contacts/import/campaign data require server validation; SMS preview is repeated during creation to prevent client tampering.
- Output: React escapes text by default; CSV exports still require formula-injection review.
- Abuse: auth, contact form, campaign creation/queueing, and webhooks require rate limiting appropriate to their trust boundary.

Current security verdict: NOT YET RELEASE-CLEARED pending live authorization, payment-callback, dependency, and secret scans.

