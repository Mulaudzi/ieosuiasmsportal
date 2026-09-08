# Background-job matrix

| Job | Entry point | Lock/idempotency expectation | Result |
|---|---|---|---|
| Scheduled campaign promotion | cron campaign processing endpoint/script | atomic state claim | PARTIAL |
| SMS dispatch | `api/bin/sms-worker.php --once` each minute | global worker lock, row lease, shutdown recovery, bounded retry | IMPLEMENTED; CRON REQUIRED |
| Provider status polling | configured cron status job | monotonic status update | EXTERNAL |
| Notification processing | notification endpoints/job | user-scoped, retry-safe | PARTIAL |
| Payment reconciliation | callback/status check | payment-reference idempotency | PARTIAL |
| Payment receipt delivery | `api/bin/payment-receipt-worker.php` every 5 minutes | unique payment outbox row, claim lock, max 8 attempts | IMPLEMENTED; CRON REQUIRED |

Production cron presence was reported by the operator, but execution logs, overlap behavior, failure alerting, and recovery still need captured evidence.
