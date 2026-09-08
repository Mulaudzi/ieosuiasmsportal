# SMS pipeline

1. `POST /api/sms/preview` normalizes and deduplicates manual/contact/group recipients, removes tenant opt-outs, analyzes encoding/segments and prices the result.
2. `POST /api/sms/campaigns-v2` repeats the authoritative preview, writes campaign/messages, and reserves wallet funds in one transaction.
3. Immediate campaigns are idempotently queued through `/queue`; scheduled campaigns are queued by `sms-scheduler.php`.
4. `sms-worker.php` leases batches using row locks and `SKIP LOCKED`, rechecks cancellation and opt-out, calls the configured provider, settles accepted sends, retries bounded failures, and releases rejected reservations.
5. Authenticated LogicSMS delivery callbacks are stored idempotently before applying monotonic message state updates. Configure a random 32+ character `LOGICSMS_WEBHOOK_SECRET` and the provider callback URL as `/api/webhooks/logicsms/dlr/{secret}` if LogicSMS enables URL callbacks for the account.

LogicSMS is the only live provider adapter. Automatic cross-provider fallback is disabled so retries and billing remain deterministic. LogicSMS publicly documents status polling through `querysms.aspx`; push-callback availability and its exact payload must be confirmed with LogicSMS support before treating webhook delivery as production evidence.
