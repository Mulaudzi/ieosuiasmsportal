# API contract inventory

API responses use `{success: true, ...payload}` and failures use `{success: false, message, errors?}`. Authenticated application routes are inside the JWT middleware group; admin routes additionally require the admin role/session controls.

| Domain | Routes | Contract note | Result |
|---|---|---|---|
| Auth | `/auth/central/*`, `/auth/me`, `/auth/logout` | Central identity is canonical; local credential routes disabled | PARTIAL |
| Contacts/groups | `/contacts*`, `/contact-groups*` | Tenant ownership required for every identifier | PARTIAL |
| Templates | `/templates*` | Tenant-scoped CRUD | PARTIAL |
| SMS | `/sms/preview`, `/sms/campaigns-v2*` | Canonical SMS API; preview repeated on create | PASS/PARTIAL |
| Wallet | `/wallet*` | Returns `sms_credits` and accounting values | PASS/PARTIAL |
| PayOS | `/payments/payos/callback` | Public signed callback; idempotent finalization required | PARTIAL |
| LogicSMS DLR | `/webhooks/logicsms/dlr[/{token}]` | Secret validated; status transition processing | EXTERNAL |
| Email campaigns | `/email/campaigns*`, `/email/limits` | Deliberately returns HTTP 410 Coming Soon | PASS |
| Reports | `/reports/*` | Authenticated; legacy/canonical consistency needs consolidation | PARTIAL |
| Admin | `/admin/*` | Must reject users lacking admin authorization | PARTIAL |

