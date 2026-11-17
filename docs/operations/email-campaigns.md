# Consent-based email campaigns: controlled first release

Historical PDO/SMTP implementation notes. The current bulk-email build uses the isolated [AWS campaign service](email-aws-service-20261112.md); do not deploy the worker described below on Axxess.

Status: implemented locally, disabled by default. No deployment, live migration, cron installation, customer email, payment or production-wallet operation was performed for this feature.

## Sender identity and customer experience

The customer workspace is `/email-campaigns`. Administrators use `/guymhan/email-campaigns` (Email Review). Existing SMS, imports, transactional emails and payment purchasing remain separate.

The first release deliberately supports only a platform-owned, authenticated From address. A recipient sees `Approved business name via IEOSUIA`. Reply-To is forced to the customer's currently verified portal login email. Supplying `from`, `headers`, `html` or `attachments` is rejected. Customer-owned From domains and separately verified Reply-To mailboxes are future work, not existing capabilities.

An administrator must independently verify business identity, postal address and authority to represent that business. Email verification alone is not organization authorization. Sender edits reset approval and invalidate earlier sender snapshots. Version checks prevent an administrator approving details changed during review. Administrators cannot approve their own sender or campaign.

Customers select owned contact groups/contacts (API), or paste addresses. The workspace exposes groups and pasted addresses. Every submitted audience is normalized, deduplicated and snapshotted. Existing email opt-outs and new email suppressions apply; SMS subscription status is never treated as email consent. Customers record verifiable consent evidence and explicitly attest to consent for this sender and purpose. An administrator checks that evidence against the exact audience, message and linked destinations. An attestation is not independent proof.

Messages are plain text rendered into escaped, branded HTML plus a text alternative. No custom HTML, file attachments, tracking pixels or recipient-list disclosure. A permanent footer identifies the sender, address, Reply-To, unsubscribe and abuse-report links. Public HTTPS link syntax checks are not malware scanning: no user URL is fetched by the server. Human content/link review remains mandatory; phishing, impersonation, fraud, malware, harassment and unlawful content must be rejected. These controls reduce risk; they are not a guarantee of content safety or legal compliance.

## Billing: five emails per existing SMS credit

- One recipient's **SMTP-accepted** email costs **0.2 SMS credit**, including for fewer than five recipients. There is no rounding up to a full credit per campaign.
- `SMS_PRICE_PER_SEGMENT / 5` determines the ZAR charge. At R0.35 per SMS credit, an email is R0.07. The server computes all amounts; request-supplied prices, balances or user IDs cannot authorize spending.
- Exact integer cents are used. A configuration that cannot represent exactly one-fifth of a credit in whole currency cents is blocked; existing wallet precision and history are not silently changed.
- Review reserves nothing. Explicit queueing of an approved, unchanged campaign reserves its maximum charge against the existing wallet's available amount.
- SMTP acceptance is first persisted as `accepted_pending`. An independent accounting-only transaction then debits balance and reservation exactly once and marks `accepted`. A local accounting retry cannot send email.
- A definite SMTP rejection releases that recipient's reservation and charges nothing. An uncertain acknowledgement or stale in-flight submission is held with funds reserved, never automatically retried. Cancellation releases queued/pending recipients only, not in-flight, held or already accepted funds.
- A later hard bounce is **not** a failed SMTP submission: it remains charged under this acceptance-based billing model. Inbox placement, message opens and handset-style delivery are not promised.
- Email ledger entries use `email_*` operations, `email-campaign:*`/`email-message:*` idempotency keys and channel metadata. The existing `wallet_ledger.campaign_id` stays NULL because it references SMS campaigns. Each email campaign tracks its own remaining reservation, so releasing email cannot consume an unrelated SMS reservation.
- Email outbox/audit timestamps use UTC. Existing financial table timestamps continue to use `APP_TIMEZONE`.

## Review and sending workflow

1. Request sender review with business name and postal address. Reply-To comes from the verified account, not the request.
2. Administrator checks identity/authorization, approves a bounded daily attempt allowance, and records evidence.
3. Customer previews recipients and costs, supplies consent evidence and submits for review. Content/audience/sender snapshots are immutable.
4. A different administrator checks consent, content and destination links; approves or rejects with evidence. Review does not send.
5. Customer explicitly queues the approved campaign. The server checks launch gates, current sender identity, unchanged pricing and available wallet funds.
6. A separately installed CLI worker claims one recipient at a time, commits a durable pre-network checkpoint, then submits via dedicated TLS SMTP. SMTP debug and PHP `mail()` fallback are prohibited.
7. Acceptance/rejection/uncertainty and financial settlement are audited separately. Exact-message, authenticated provider evidence may reconcile a held submission without resending.

Initial safeguards: 1 global worker database advisory lock, configurable maximum recipients (default 1,000; hard cap 10,000), maximum 20 open campaigns/account, 50 new review requests/account/UTC day, and 20 sensitive API attempts/minute/account. All SMTP attempts, including rejected and uncertain submissions, count against shared **rolling hour and rolling 24-hour** provider limits and the sender's rolling 24-hour limit. Crossing midnight or an hour boundary does not reset these sending quotas. A provider with additional per-minute, per-domain or connection limits needs corresponding approval/configuration/transport adaptation before launch; throughput has not been measured.

The worker checks a 40-second run budget between messages, with a maximum batch of 25 and default 10. SMTP operations have their own timeouts; 40 seconds is not a hard whole-process deadline. Do not add more cron jobs to bypass limits or the global lock.

## API surface

Existing portal Bearer authentication, verified active user and server-side ownership apply. Admin methods additionally check the current database role. Financial operations use account-owned locked wallets, not caller-selected payers.

| Method/path | Purpose |
| --- | --- |
| GET `/email/limits` | Feature/readiness state; no credentials |
| GET, POST `/email/sender` | Own sender identity/request review |
| POST `/email/quote` | Server-side audience/cost preview; no send/reservation |
| GET, POST `/email/campaigns` | Own recent history/immutable review submission |
| GET `/email/campaigns/{id}` | Own audience, submission and feedback statuses |
| POST `/email/campaigns/{id}/send` | Queue approved snapshot; idempotent reservation |
| POST `/email/campaigns/{id}/cancel` | Cancel unsent recipients and release their unspent reserve |
| GET `/admin/email-campaigns` | Prioritized sender/campaign review queues |
| GET `/admin/email-campaigns/{id}` | Admin review of exact content and audience |
| POST `/admin/email-senders/{userId}/review` | Approve/reject/suspend with evidence, expected version and allowance |
| POST `/admin/email-campaigns/{id}/review` | Approve/reject exact fingerprint with review evidence |
| GET, POST `/email/preferences/{recipientId}?token=...` | Capability-based confirmation / unsubscribe; optional `report=1` |
| POST `/email/provider-events` | Signed provider-adapter delivery/bounce/complaint evidence |

Existing unused duplicate/export/delete email placeholders are not activated by this release. No scheduling or external multi-product email API is claimed. User history is capped at 100 recent campaigns; admin lists show up to 200, prioritizing pending/held work. Larger-scale pagination, advanced templates, custom sender domains and automated campaign notifications remain follow-up work.

Example customer request (`idempotency_key` stays unchanged when retrying the same lost HTTP response):

```json
{
  "idempotency_key": "caller-generated-unique-request-id",
  "name": "Member update",
  "subject": "Your requested update",
  "body": "Hello, here is the update you requested.",
  "recipients": {"manual": ["member@example.org"], "group_ids": [], "contact_ids": []},
  "consent_confirmed": true,
  "consent_evidence": "Signed email signup form, dated reference membership-2026-10."
}
```

Responses use the existing `{success:true, campaign:...}` / `{success:false,message:...}` envelope. Validation 422, permission 403, not found/foreign resources 404, stale/idempotency conflict 409, abuse throttle 429 and unavailable/launch-blocked 503. Raw SMTP errors, credentials and verification tokens are never returned in authenticated campaign details.

## Unsubscribe, abuse and signed feedback

Every claimed recipient gets a random 256-bit preference token; only its hash is stored. GET validates and shows a confirmation form, making link-scanner GET requests non-mutating. POST performs unsubscribe; the same URL supports RFC 8058 one-click `List-Unsubscribe`/`List-Unsubscribe-Post` headers. Suppression is scoped to that sender account and email channel, persists across new imports, and is rechecked at claim time. Already in-flight email cannot be recalled. The endpoint remains operational with sending/feature switches off.

An authenticated preference-link abuse report suppresses that address and suspends the sender. Replaying an already recorded complaint cannot repeatedly suspend an investigated/reapproved sender. The existing contact-form bounce endpoint is **not** reused and cannot update these email outbox records.

Provider integration is an explicit adapter contract, **not** an already connected provider service. The adapter must first verify the real provider's webhook signature, then map the exact saved SMTP Message-ID to the email recipient ID and sign this envelope. Do not sign unverified provider input.

```json
{
  "event_id": "stable-provider-event-reference",
  "recipient_id": "32-character-email-recipient-reference",
  "message_id": "<the-exact-saved-message-id@email.ieosuia.com>",
  "event": "delivered"
}
```

Allowed events: `delivered`, `hard_bounce`, `complaint`. Send `X-Email-Timestamp` (Unix seconds) and `X-Email-Signature` (lowercase hex HMAC-SHA256 of `timestamp + '.' + exact raw JSON body`). A 5-minute timestamp tolerance, 32 KiB body cap, exact Message-ID match, submitted-attempt check and unique event reference reject spoofing, stale requests and replay changes. A duplicate with the same bytes is accepted idempotently. Retry with the same body/event ID and a fresh timestamp/signature. Current and previous signing keys permit a controlled rotation overlap; use cryptographically random secrets of at least 32 bytes.

Hard bounces suppress the address. Complaints suppress it and pause the sender for admin review. Delayed delivery events cannot erase a stronger bounce/complaint state. Accepted delivery evidence can transition a held record to accounting-only settlement; it never invokes SMTP. Unsigned/ambiguous evidence cannot release a hold. Do not blindly mark held messages failed or resend them. SMTP Message-ID is a correlation identifier, not a provider-side deduplication guarantee.

## Launch gates and infrastructure blocker

Do **not** deploy the dispatch scripts or enable this feature on current shared hosting without written permission or a move to permitted infrastructure. Axxess's published policy prohibits storing/running mass-mailing scripts and sending bulk mail on its administered hosting platforms: [Axxess hosting AUP](https://www.axxess.co.za/docs?tab=terms). A dedicated external SMTP account alone does not establish permission to run the dispatcher on that hosting account.

Before live rollout:

1. Obtain written hosting and bulk-email transport approval and numeric limits, or choose permitted hosting plus an appropriate bulk provider. No account upgrade, purchase or provider choice is authorized by this implementation.
2. Provision a dedicated platform sender/SMTP account. Verify SPF, DKIM, DMARC alignment, TLS and reverse DNS where applicable, and monitor a real abuse mailbox. These are operator attestations backed by evidence, not automatic DNS tests. [Google sender requirements](https://support.google.com/mail/answer/81126?hl=en) explain authentication and unsubscribe expectations; configuring flags alone does not establish acceptance/compliance.
3. Implement/test the selected provider's real feedback adapter, including hard-bounce, complaint, replay and unknown-reference cases. Namespace event references by provider. Configure the HMAC secret outside web root/source history and set `EMAIL_CAMPAIGN_FEEDBACK_VERIFIED=true` only after evidence exists. Verify public callbacks and one-click unsubscribe work without a login session or Imunify/browser bot challenge, and that the unsubscribe headers are covered by the provider's DKIM signature. Any hosting/WAF exception must be narrowly scoped, not a general authentication bypass.
4. On a restored MariaDB/MySQL snapshot, back up schema/data; apply **only reviewed migrations**, including additive `019_email_campaigns.php`. Its preflight requires InnoDB identity/financial tables, unambiguous account wallets and sufficient decimal precision. It does not modify historical tables/rows. MySQL DDL is not transactional: preserve partial-attempt evidence on failure and rerun only after inspection.
5. Run genuine multi-process MySQL acceptance tests for simultaneous SMS/email reservation, duplicate queue/settlement, cancellation/acceptance races, worker advisory-lock contention, callback concurrency and rollback. SQLite tests do not prove MySQL production concurrency.
6. Configure the dedicated `EMAIL_CAMPAIGN_*` environment values from `.env.example`, provider-approved rolling limits, approval references and verified DNS evidence. Both `EMAIL_CAMPAIGNS_ENABLED` and `EMAIL_CAMPAIGNS_SEND_ENABLED` default false. Never fall back to system `SMTP_*`/cPanel configuration.
7. Install the separate CLI worker only on approved infrastructure. Its entrypoint is `php api/bin/email-campaign-worker.php`; this document is not permission to run it against production. Monitor scheduler exit status/output, queue age, held records, complaint rates and unspent reserves. No cron or existing SMS readiness requirement is changed automatically.
8. Perform an explicitly authorized, very small opt-in inbox pilot, inspect real From/Reply-To/DKIM results, unsubscribe, accepted-vs-delivered statuses and wallet/ledger invariants. Check actual Gmail/Outlook/mobile email-client rendering; a Chromium HTML preview is not inbox-client proof. Warm up conservatively; provider numeric limits are ceilings, not safe warm-up targets.
9. Set retention/access controls for consent evidence, email bodies, recipients, suppressions, audit and callback replay records. Redact preference tokens from access/proxy logs; do not place secrets or excessive personal data in evidence notes. Keep authentication and transactional mail isolated from campaign reputation.

## Rollback

Turn live sending off and stop new claims first. A running/in-flight SMTP operation may still complete. Keep preference and signed-feedback endpoints available. Preserve all new tables, recipient checkpoints, suppressions, accepted evidence, wallet history and unspent reservation ownership. Continue accounting-only reconciliation of already accepted messages; never delete reservations, use an automatic SQL down-migration, restore a stale whole-wallet backup over other activity, or reset submitted/held records to queued. Cancel only definitely unsent recipients through the domain workflow. A scoped code rollback must preserve reconciliation/unsubscribe access for already sent campaigns and must not alter the SMS pipeline.

Local evidence and remaining acceptance gaps are in `docs/verification/email-campaigns-20261029.md`.

## Provider selection follow-up — 29 October 2026

The owner subsequently selected **Amazon SES à-la-carte** and confirmed the
existing AWS account ending 0326 in Stockholm. The plan was switched from the
unused Essentials default and read back as `NONE`; a dedicated campaign identity
and **paused** configuration set were prepared. DNS authentication is pending:
the hosting DNS API returned 403, so no DNS records were changed. No SMTP keys,
feedback adapter, production-access request, email migration or portal release
was activated. Both portal email switches remain unchanged. Full evidence and
remaining gates are in `docs/operations/amazon-ses-onboarding-20261029.md`.

## SES readiness follow-up — 30 October 2026

DNS/DKIM/custom MAIL FROM now verify successfully. Production access is still
false and campaign configuration remains paused. An SNS signature adapter,
SES-specific correlation/pacing, exact fractional wallet history and operator
artifacts are now implemented and tested locally, not connected to live SES.
MySQL and anonymous preference HTTP fixtures passed. Migration 019 has not run.
The existing SMS/tags/transfer release remains unchanged. See
[current launch checklist](email-ses-launch-readiness-20261030.md) and
[verification boundaries](../verification/email-ses-readiness-20261030.md).
