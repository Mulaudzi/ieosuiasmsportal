# IEOSUIA SMS integration API v1

Released and enabled on 5 November 2026 after scoped schema setup, code release and runtime verification. Public documentation: https://sms.ieosuia.com/developers. No account was enrolled, API key created or real SMS sent by the release. The existing 80/second SMS worker settings were unchanged. Authenticated customer-key issuance and a real send still require a consenting account/recipient acceptance test.

**Follow-up deployed 5 November 2026, verified 15:27 UTC:** the 1.1 result contract below is live for simple sending confirmation and owner-only campaign attribution. The public guide and downloadable specification match it. No keys were created or real SMS sent during deployment. A valid-key Blu-T quote/send acceptance check remains separate. This file is internal implementation documentation, not a public webpage.

## Start with one SMS Portal account as payer

Each connection belongs to the account that creates it. Events, Tenant Management, Security OS and other websites call this API from their **backend**. They receive no database, provider-credential or administrator access. Billing always uses the authorizing account’s existing wallet, reservations, pricing, ledger and queue. An initiating app/user reference is attribution, not authority to charge another person.

This release does not introduce organizations, memberships, alternative payers, client-credentials OAuth or Central Auth changes. A product label is selected by the account owner and is not a verified IEOSUIA product identity. Tenant/property-owner billing must use the owner's authorized connection or a future separately authorized payer model; never accept a tenant-supplied payer/account ID.

## Credentials

After activation, open **Settings → API access**. Enable the SMS Portal's optional extra sign-in check, then sign out and back in before issuing a key. This requirement is only for key issuance; it does not enroll other IEOSUIA apps or require an email code on every server request.

1. Create a connection per application and environment, with a name, product label, daily admitted-credit allowance and least-privilege permissions.
2. Copy the newly displayed key to a backend secret store. It is shown once. The database stores only a random public identifier and SHA-256 hash of a 256-bit random secret. The key is not saved to browser storage or the frontend query cache.
3. Use HTTPS and the `Authorization: Bearer <key>` header. Do not put keys in query parameters, logs, browser JavaScript or distributable mobile app code. Requests carrying an `Origin` header are rejected deliberately; do not weaken CORS to expose this API to browsers.
4. Keys expire in at most 90 days. Two active keys may overlap for rotation: create a replacement, update the backend, verify your integration, then revoke the previous key. Revocation blocks new API requests; it does not cancel already queued messages.
5. Password/auth-version changes, account suspension, expiry, revocation and changes to the SMS sign-in-security version invalidate credentials. Revoke invalidated keys before replacing them if both rotation slots are occupied.

Default connection limit: 60 authenticated API requests/minute, shared across all its keys. Owner-configurable server-side range is 1–600; the initial UI uses 60. A separate 120 requests/minute source-IP admission guard protects credential authentication and may be shared by applications behind the same IP. This is not the outbound SMS worker rate.

## Endpoints

Base URL after activation: `https://sms.ieosuia.com/api/v1`

| Method/path | Required scope | Purpose |
| --- | --- | --- |
| POST `/sms/quotes` | `sms:quote` | Resolve recipients, segment/encoding and current credits; no reservation |
| POST `/sms/messages` | `sms:send` | Queue one or up to 1,000 supplied phone numbers |
| POST `/sms/messages` with `scheduled_at` | `sms:send` and `sms:schedule` | Reserve and schedule, preserving the supplied instant |
| GET `/sms/messages/{id}` | `sms:read` | Simple sending status for that connection only; detailed delivery results stay in the portal |
| POST `/sms/messages/{id}/cancel` | `sms:cancel` | Cancel queued/scheduled work before sending starts |
| GET `/balance` | `wallet:read` | Available account wallet balance and credits |
| GET `/usage` | `sms:read` | Today's connection admission usage and allowance |

Scheduling a quote also requires `sms:schedule`. No API key can list other applications' messages, even when the same account owns both. Private portal key-management endpoints require a portal session and never accept machine credentials.

## Quote/send contract

Use JSON objects, at most 64 KiB. `content` and a list of 1–1,000 phone-number strings are required. Optional fields: `name`, `include_opt_out` (boolean, default false), `external_reference` (maximum 120 bytes), `max_credits` (positive integer, at most 6,000) and `scheduled_at` (ISO 8601 with seconds and an explicit offset or `Z`). Unknown fields are rejected, including caller-selected account, payer and sender IDs. The sender follows existing server configuration and opt-out handling.

No phone digits are invented. The existing normalization, validation, duplicate removal and account opt-out suppression apply. This version accepts direct phone numbers, not contact/group database IDs, attachments or portal template variables. Personalize messages in the calling application; API access does not grant contact-profile access. Compliance/consent remains the sending account's responsibility. The opt-out footer setting does not disable opt-out suppression.

```json
{
  "name": "Event reminder",
  "content": "Your event starts at 10:00. Please arrive early.",
  "recipients": ["+27820000001"],
  "include_opt_out": true,
  "external_reference": "events:reminder:example",
  "max_credits": 2
}
```

For `POST /sms/messages`, supply a stable **`Idempotency-Key`** header of 16–100 letters, digits or `._:-` characters. For example `events-reminder-example-0001`. Keep it unchanged when retrying an HTTP timeout or 503. Same connection + same key + same canonical payload returns the original result, including after credential rotation. Changed message details with the same key return `409 IDEMPOTENCY_CONFLICT`. Idempotency records are retained; there is no automatic expiry that permits a later duplicate send.

Do not switch keys or generate a new request ID to bypass a held/uncertain result. Existing durable submission holds and reconciliation remain authoritative. Quotes are not binding; send recalculates actual segments and reserves funds. `max_credits` bounds actual admitted segments atomically, including enabled footer text, not a supplied monetary charge.

An accepted API submission returns **202**, not handset delivery:

```json
{
  "success": true,
  "replayed": false,
  "message": {
    "id": "0123456789abcdef0123456789abcdef",
    "external_reference": "events:reminder:example",
    "reference": "API-EXAMPLE-NOT-A-CREDENTIAL",
    "status": "queued",
    "state": "queued",
    "complete": false,
    "scheduled_at": null,
    "created_at": 1793872800,
    "credits_required": 1,
    "recipients": 1,
    "acceptance_is_not_delivery": true
  }
}
```

The opaque ID is local to this API, not a LogicSMS provider ID. Poll status with reasonable backoff. The existing provider postback/status reconciliation updates the same message records. Outbound signed webhooks, message-by-message recipient exports and historical date-range API reports are **not implemented in this first release**. Do not provide webhook/callback URLs; they are rejected rather than silently accepted.

### Sending-result contract (1.1)

The service projects message-backed evidence through an explicit response allowlist, never through raw database-row serialization. `status` and its deprecated `state` alias use exactly `queued`, `sent`, `not_sent`, or `partially_sent`. `complete` is false while any recipient's submission remains unresolved. A partially processed batch is still queued; only a fully resolved mixed batch is partially_sent. Network non-delivery after acceptance remains sent for submission purposes. A cancellation only classifies unsubmitted pending/queued rows as not_sent; retries and processing rows stay unresolved. Zero message rows are unresolved, not assumed successful or failed.

This is an intentional narrowing of the previous v1 result shape: old lifecycle state values and detailed result counters are removed. Clients must migrate to `message.status` and `message.complete` before the follow-up is deployed. The JSON `state` field is retained only as an alias; it is not behavioural backward compatibility for the old state enum. Quote, authorization, wallet, usage, idempotency and send inputs are unchanged. `credits_required` records credits required on admission, not actual charged credits.

One API request still creates one ordinary account-owned campaign. For an empty name, the integration transaction assigns `API | connection | DD Mon YYYY HH:mm SAST | short-request-reference`. The full unique reference is `API-` plus the uppercase opaque request ID. Caller names and historical campaign names are not overwritten. Auto-generated naming is applied after the canonical campaign insert inside the same transaction, so generated identifiers/timestamps cannot alter request fingerprints or break concurrent replay. Source attribution remains visible after connection revocation, without disclosing keys or granting API keys portal access.

### Blu-T integration handoff

Create a separate `Blu-T` connection in Settings, using the website/custom label and the authorizing account's credits. Store the key only in Blu-T's backend environment. Quote finalized content first, then send with a stable order/event-specific `Idempotency-Key` and `external_reference` (for example `blu-t:order:123:ready`). Persist the returned ID and poll `GET /sms/messages/{id}` after about 10 seconds, backing off to 30–60 seconds while incomplete. Display Queued / Sent / Not sent / Partially sent from `status`. Never automatically resubmit a queued or partially_sent batch. View the owner-authenticated portal for recipient-level results. No Blu-T code, account, credentials or customer send was changed by this implementation.

## Atomic billing and limits

Campaign creation, the existing account daily quota, wallet reservation, API attribution, daily app allowance and message audit commit in one transaction. If authorization changes or a cap/audit fails before commit, all roll back. The optional CampaignService transaction hook is internal-only; existing portal calls use its unchanged default path.

The daily app allowance measures **admitted credits**, not charged credits. All credentials share it; scheduling counts on admission day. Cancelled and failed submissions still count until midnight South African time, conservatively limiting abuse. Cancellation releases reserved money through the existing ledger and does not refund this admission allowance. Actual charging occurs only through normal provider-acceptance settlement; never accept caller-supplied prices, charges or wallet IDs.

The 80-starts/second, 40-in-flight worker limit is shared by portal/API campaigns. It is not an 80/second allowance per key or a delivery-time guarantee. Existing account, campaign and segment limits still apply.

## Errors

```json
{"success":false,"message":"This API key does not have the required permission.","error":{"code":"INSUFFICIENT_SCOPE","message":"This API key does not have the required permission."},"code":"INSUFFICIENT_SCOPE"}
```

401 invalid/expired/revoked credentials; 403 missing scope or browser-origin request; 404 unknown/foreign connection message; 409 idempotency conflict, changed credit authorization, insufficient account allowance or cancellation too late; 413 body too large; 415 non-JSON; 422 malformed fields/schedule/idempotency; 429 request/daily-app allowance; 503 disabled, missing schema or temporary backend failure. Rate-limited responses include `Retry-After`; for daily allowance, retries before the next business day will still fail. Responses use `Cache-Control: no-store` and do not expose provider errors, tokens or credential hashes.

## Deployment prerequisites and rollback

Keep `SMS_INTEGRATION_API_ENABLED=false` until migration **027**, existing transactional wallet/idempotency/account-quota prerequisites and SMS-local sign-in-security schema are verified. The dedicated `SmsIntegrationSetup` inspector fails closed on missing prerequisites or partial setup and allows only migration 027 with a backup confirmation and pinned hash. It does not apply migration 018, 019, 023 or other migrations, enroll accounts, create keys, send messages, move money or activate the API. If prerequisites are absent, review them separately instead of running the entire migration directory.

Publish only reviewed API/schema/UI deltas from fresh live-compatible bytes. The local checkout contains unrelated pending work. Activation is separate and no credentials are created automatically. On rollback disable the API flag first; keep integration tables, attribution, idempotency and audit records. API-origin campaigns already queued remain ordinary portal campaigns and continue unless explicitly cancelled under the normal rules. Restore the prior core/controller/route/UI bytes without deleting financial history or resending anything.
