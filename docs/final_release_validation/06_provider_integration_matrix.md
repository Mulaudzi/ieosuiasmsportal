# Provider integration matrix

| Provider | Purpose | Internal controls | Validation |
|---|---|---|---|
| LogicSMS | SMS submission/credit check/DLR | normalized provider adapter, webhook secret, durable provider events | INTERNAL PASS; LIVE DLR EXTERNAL |
| PayOS | Hosted credit purchase | server-side create, callback signature, local reference/idempotency | INTERNAL PARTIAL; LIVE CALLBACK EXTERNAL |
| SMTP | transactional/support email | PHPMailer, DB/env configuration, redacted diagnostics | INTERNAL PARTIAL; LIVE EXTERNAL |
| IEOSUIA central identity | user/admin authentication | signed state/token validation and central user mapping | INTERNAL PARTIAL; LIVE EXTERNAL |
| Google OAuth | intentionally removed | routes/client code removed | PASS (out of scope) |
| Telnyx | intentionally removed | active routes/services removed | PASS (out of scope; stale docs must not govern runtime) |

