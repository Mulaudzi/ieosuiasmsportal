# Database inventory

Canonical MVP tables are introduced by migrations `001_mvp_foundation`, `002_payment_reference_hardening`, and subsequent central-identity/admin migrations.

| Area | Tables | Integrity mechanism | Result |
|---|---|---|---|
| Identity | users and central identity link fields | unique identity/email constraints | PARTIAL |
| Contacts | contacts, contact_groups, group_contacts, opt_outs | tenant IDs and junction uniqueness | PARTIAL |
| Canonical SMS | sms_campaigns, sms_messages, sms_provider_events | ownership, idempotency key, durable state | PASS (schema review) |
| Billing | wallets, wallet_ledger, payments | row locks, references, ledger idempotency | PARTIAL |
| Legacy campaign/reporting | campaigns, messages | retained for compatibility | RELEASE RISK |
| Administration | admin/user roles, audit_logs, notifications | role checks and immutable event intent | PARTIAL |

No destructive schema cleanup is authorized in this pass. Legacy tables must remain until report/contact-email dependencies are migrated and production backup/restore is verified.

