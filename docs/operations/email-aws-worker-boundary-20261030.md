# AWS-hosted email processing: selected direction

Owner decision, 30 October 2026: use the second option, an AWS-hosted email
worker, rather than a bulk-mail dispatcher on Axxess. Preparation remains
local. No AWS resource creation, deployment, credentials, migration or sending
is authorized by this document. Existing SMS, payments, transfers and
transactional email remain unchanged.

This decision supersedes the assumption in the earlier launch checklist that
the campaign worker would run on cPanel. Axxess permission is not a requirement
imposed by SES. The hosting contract still applies to whatever application
functions remain on Axxess; moving only SMTP does not establish compliance.
Move the campaign-processing backend, feedback and preference handling to AWS,
leaving the existing portal UI and its financial authority separate. Confirm
any residual hosting-policy question before release; do not claim moving the
worker automatically approves all campaign-related code on the existing host.

## Existing versus proposed

Existing local implementation (not deployed): `api/bin/email-campaign-worker.php`
uses a direct PDO connection, MySQL advisory locks, transactional outbox claims,
reservation/ledger updates and `EmailCampaignTransport` SMTP submission. It is
**not** a remote worker. Copying it to AWS would require database credentials
and network access that we have not approved. Do not open cPanel MySQL publicly,
copy the portal database/configuration to AWS, or reuse administrator accounts.

Proposed AWS service: a containerized PHP campaign API/worker on ECS Fargate,
with private service-owned outbox/state storage, SES HTTPS submission using a
restricted task role, and SNS/SQS feedback with a dead-letter queue. Fargate is
a candidate, not a provisioned or cost-approved commitment. Review expected
volume and the complete compute/storage/network/logging bill before creation.
SES sending charges alone are not the full service cost.

Fargate task roles supply temporary SDK credentials; use a separate execution
role for image/logging operations. The existing SMTP adapter does not use task
roles: add and test a distinct SES API adapter using the supported AWS SDK,
rather than treating IAM credentials as an SMTP password. Disable automatic
SDK retries for submission: an unverified timeout must not silently send again.
Read-only quota calls may retry with bounds. Preserve rendered MIME, original
message IDs, opaque campaign/recipient tags and exact SES reference matching.

## Ownership and flow

| Component | Authority / responsibility |
| --- | --- |
| Existing portal | Authenticate customers, account ownership, credit quote/reservation/settlement/release, existing wallet/ledger |
| AWS campaign API | Sender/content review, immutable approved audience/content, consent evidence and suppression checks |
| AWS dispatcher | Durable send checkpoint, quota pacing, provider submission, accepted/uncertain outcomes |
| AWS feedback/preferences | SNS verification and durable events, bounce/complaint suppression, anonymous unsubscribe/abuse links |
| Cross-service bridge | Narrow authenticated reservation and accounting commands, never arbitrary wallet updates or SQL |

1. Portal authentication authorizes an account-scoped campaign request. The AWS
   API must validate a server-issued, short-lived audience-bound delegation;
   never accept a browser-supplied payer/user ID as authority. Account role,
   organization/payer policy and independent approval stay explicit.
2. AWS freezes the reviewed recipient/content snapshot and pricing version.
   The portal derives the exact amount itself and atomically reserves it with
   an immutable operation ID. No accepted reservation means no send permission.
   A lost response is reconciled with that same ID, not a new reservation.
3. A work notification contains an opaque job ID, not a recipient list, email
   body or unsubscribe token. AWS loads the immutable data from private storage.
   Durable per-recipient state is the authority, not queue delivery counts.
4. Before network I/O, the worker obtains and commits a one-attempt permit
   bound to account, campaign, recipient, content hash, reserved cost and expiry.
   Checks include current suppression/review state, rate/quota, budget and
   cancellation. Permit issuance and cancellation have a single atomic ordering
   authority; the detailed protocol must be implemented and concurrency-tested
   before release. Cancellation of an in-flight permit never refunds an email
   whose submission may have started.
5. Worker persists `submitting` before SES. A successful provider response is
   saved durably and emits an idempotent accounting command. Charge exactly once
   against the original reservation (0.2 credits per accepted recipient under
   the current five-emails-per-credit rule). The portal derives the charge;
   the AWS caller cannot specify a replacement price or balance.
6. Ambiguous SES/network/persistence outcomes remain held. Queue redelivery,
   expired leases, worker restart, cancellation and SDK middleware must not
   resubmit a `submitting` recipient. Reconcile original SES evidence instead.
   If SES accepted but settlement is unavailable, retry accounting only.
7. Verified SNS evidence updates the AWS history and sends deduplicated
   account-scoped status/accounting notifications to the portal. Complaint or
   abuse suspension wins over later ordinary delivery events. Reordered events
   cannot downgrade stronger results. Never equate server delivery with inbox
   placement or reading.
8. Public preferences run on an AWS-owned endpoint with existing GET-read /
   POST-confirm behavior. Unsubscribe/suppression and worker claim share a
   durable ordering boundary, so already opted-out recipients are not claimed.
   Keep preference and feedback services operational when dispatch is stopped.

These steps are a design, not new working routes. Distributed transactions do
not exist across the portal and AWS: implement a durable command/outbox-inbox
protocol, acknowledgements, reconciliation and conservation checks on both
sides. Do not add an HTTP loop around the current database-bound worker.

## Bridge contract to implement locally next

Use a separately versioned, server-only API. Proposed operations are reserve,
reservation lookup, issue-attempt-permit, cancel/release-unattempted, settle
accepted-recipient and event acknowledgement. Paths and schemas remain to be
implemented. No public route or credential was added by this preparation.

- Separate revocable identity and scopes for campaign authorization and
  accounting. Never use a customer's password, browser bearer token or portal
  admin session as a worker credential.
- TLS plus signed method, canonical path/query, raw-body SHA256, timestamp,
  nonce, key ID and audience; constant-time signature checks, persistent nonce
  replay prevention and bounded clock skew. Secrets live in Secrets Manager
  and protected portal configuration; rotation accepts a bounded overlap only.
  An AWS API edge can use IAM/SigV4 where supported; the cPanel bridge needs its
  own verified service-authentication implementation, not an assumed IAM hook.
- Every request binds the authorized account, immutable campaign/recipient,
  reservation and request hash. Stable idempotency IDs return the original
  result; changed payload with the same ID conflicts. Revocation, scope and
  account authorization are rechecked before a new effect.
- Bounded batch/body sizes and least-privilege per-client rate/spend limits;
  audited actor/product/account IDs without raw content, tokens or credentials.
- Fail closed on authentication failure, stale quota, missing feedback health,
  billing outage or review suspension. WAF challenges are deployment blockers,
  not a reason to expose a generic unauthenticated migration/send endpoint.

## Acceptance gates and rollout

1. Local protocol tests: two workers cannot get two permits; duplicate/reordered
   queue and feedback events settle once; replay or cross-account requests fail;
   simultaneous SMS/email reservations conserve wallet funds; cancellation versus
   permit issuance is deterministic; timeouts, dead-letter replay and crashes
   before/after SES acceptance never trigger automatic resending.
2. Rehearse additive schemas against a restored disposable production snapshot.
   Keep the existing SMS wallet authoritative and historical pricing unchanged.
   No broad migration runner and no production database export from this task.
3. Review costs, network/security boundaries, service credentials, backup and
   regional data retention. Obtain explicit AWS creation and release approval.
   Deployment authority is separate from this local architecture selection.
4. Provision dedicated resources with dispatch disabled. Prove real feedback,
   preferences, signed bridge calls and quota refresh without mail submission.
   Update SES production-access wording truthfully for the AWS-hosted service.
5. Approve exact recipients, content and credit amount for a small opt-in pilot,
   then validate delivery evidence and ledger conservation before gradual launch.
6. Rollback stops new permits/dispatch first, not settlement or preferences.
   Retain outbox, accepted references, suppressions, audit and ledger history.
   Never turn uncertain recipients back into automatically sendable work.

Current status: architecture selected; remote API/SDK adapter, distributed
protocol and AWS infrastructure are **not implemented**. Earlier local SMTP,
MySQL and synthetic SNS tests do not certify the new AWS boundary. The dashboard
credit display is an independent frontend change and needs no email migration.

Primary references checked 30 October 2026:

- [ECS task roles and temporary container credentials](https://docs.aws.amazon.com/AmazonECS/latest/developerguide/task-iam-roles.html)
- [SQS standard queues can redeliver messages](https://docs.aws.amazon.com/AWSSimpleQueueService/latest/SQSDeveloperGuide/standard-queues-at-least-once-delivery.html)
- [SES supports HTTPS API/SDK submission separately from SMTP](https://docs.aws.amazon.com/ses/latest/dg/send-email-api.html)
