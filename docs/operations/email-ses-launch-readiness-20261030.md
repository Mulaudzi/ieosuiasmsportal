# Email launch preparation — 30 October 2026

## Updated hosting direction

The owner selected AWS-hosted email processing on 30 October. See
[the selected AWS boundary and local implementation plan](email-aws-worker-boundary-20261030.md).
The original cPanel worker/hosting-permission sequence below is retained as
historical preparation, not the selected deployment topology. The existing
database-bound worker is not AWS-ready; no remote database access or resources
have been authorized. SES itself does not require Axxess approval. Confirm the
rules for functions remaining on Axxess and keep all bulk dispatch on AWS.
Everything remains local and sending disabled pending implementation and launch
acceptance for the new boundary.

Status: **local preparation completed; not approved for production sending**.
The SMS Portal release, customer wallets, transactional SMTP, DNS, live database
and existing workers were not changed by this work. Do not upload the broad
working tree or run all pending migrations.

## What is prepared

- A dedicated SES transport using the approved business name via IEOSUIA and a
  verified-account Reply-To. It adds the pinned SES configuration set and opaque
  recipient/campaign tags, one recipient per envelope; it never falls back to
  cPanel/system SMTP credentials.
- A public, session-independent SNS receiver, `api/email-ses-feedback.php`.
  `EMAIL_SES_FEEDBACK_ENABLED` defaults false. It accepts only the configured
  account/region/topic and SNS RSA/SHA256 signatures. Certificate retrieval pins
  the regional AWS hostname and public IPv4 address, verifies TLS, disables
  proxies/redirects and bounds time/body size. The signed SNS timestamp permits
  at most 23 days of age for controlled dead-letter replay, not a promise that
  HTTPS delivery is retried for that period. Database event IDs provide replay
  protection; a changed payload cannot reuse an event ID.
- Subscription confirmation is disabled by default. An operator can temporarily
  enable `EMAIL_SES_AUTO_CONFIRM_SUBSCRIPTIONS=true` during explicitly approved
  setup; only the verified, exact-topic/token AWS confirmation URL can be
  fetched. Disable that switch after subscription verification. Unsubscribe
  confirmation is never automatically reversed.
- SES Send, Delivery, Bounce, Complaint and Reject events require the original
  Message-ID from the original `mail.headers`, exact recipient and campaign
  tags, correct sending account/identity and configured event stream. The SES
  replacement `mail.messageId` is stored separately, namespaced by account and
  region, with a unique constraint. It cannot overwrite another reference.
  `commonHeaders.messageId` is deliberately not used as the original ID.
- Verified acceptance can resolve an uncertain submission through accounting
  only, without sending again. SMTP acceptance is the charging basis; later
  mailbox rejection/complaint does not create a second charge or automatic
  refund. Delivery feedback means recipient-server acceptance, not inbox
  placement, opening or reading. A permanent bounce suppresses that sender's
  address. A final transient bounce records failure without permanent suppression.
  Complaints and SES content rejection pause the sender for independent review.
- Durable per-second pacing and conservative rolling-24-hour controls. The
  global MySQL worker mutex and a transactional rate row protect each durable
  submitting checkpoint. The worker may wait briefly for its next slot within
  the existing bounded run, rather than unintentionally sending only once per
  cron invocation. It never requeues an uncertain SMTP result. The current batch
  stops and reports attention after uncertainty or SES transport rejection,
  leaving the other queued recipients unchanged.
- Signed quota/health snapshots from a **separate read-only AWS identity**. A
  snapshot binds account, region, identity, configuration set and feedback topic.
  Stale/invalid snapshots, sandbox status, paused sending, unhealthy enforcement
  or missing feedback stop new claims. Accepted checkpoints can still settle.
  AWS usage plus overlapping local attempts and a safety reserve are counted
  conservatively; these are not a guarantee against unrelated account traffic.
- Email usage in the wallet is labelled separately and shows **0.2 credits per
  accepted recipient**, including historical price changes. No rounding down
  to zero and no inflation of purchased credits/payments.
- Fresh, additive migration 019 includes the recipient provider references and
  dispatch-budget row. It has **not** run in production. An older or partially
  applied local 019 schema must be inspected/rebuilt in a disposable fixture;
  `CREATE TABLE IF NOT EXISTS` is not a repair for missing columns. Never delete
  real outbox or financial records to repair a schema.

Unsupported tracking/subscription/template event types are acknowledged without
financial or delivery changes. Open/click tracking and SES subscription-manager
features are not enabled. No attachments, arbitrary From addresses or user HTML
uploads are allowed by this first release. Human identity/content/consent review
remains required; these controls are not a legal-compliance certification or a
guarantee that abuse can never occur.

## Current AWS evidence

Read-only inspection of the owner-confirmed account ending **0326**, Stockholm:
identity/DKIM/custom MAIL FROM verified; account healthy; production access
false; quota **200 recipient emails/24 hours, 1/second**, zero recent sends.
The existing campaign configuration set remains paused. The dedicated feedback
topic/subscription and event destination do not exist yet. No AWS resources,
subscriptions, credentials or production-access request were created/submitted.

`python scripts/prepare-email-ses.py inspect` refreshes this evidence and writes
review-only operator artifacts beneath the ignored directory
`.sms-audit.local/email-ses-readiness-20261030/`:

- `smtp-sender-policy.json`: only `ses:SendRawEmail` from the dedicated identity
  and exact platform From address; no administrator or operator credentials.
- `quota-reader-policy.json`: only the SES/SNS read actions needed for readiness.
  Non-sending SES reads use `Resource: *`; no send, IAM, payment or mutation
  permission is granted. Prefer an infrastructure role over long-lived keys.
- `feedback-infrastructure-REVIEW-ONLY.json`: SHA256 SNS topic, exact SES
  configuration/account publishing policy, HTTPS subscription and encrypted
  retained SQS dead-letter queue. This is **not deployed or cloud-validated**.
  Creating it requires owner approval and may incur SNS/SQS usage charges. It
  does not turn the configuration set on, change DNS or create IAM credentials.

## Quota refresh on approved infrastructure

1. Use a separate random ASCII secret of at least 32 bytes for quota signing.
   Do not reuse AWS access keys, SMTP passwords or the generic feedback key.
   Store it outside Git and public content; make secret files readable only by
   the intended operator/service account.
2. With only the read-only AWS permissions above, run:

   ```text
   python scripts/prepare-email-ses.py snapshot --secret-file <private-key-file> --output <private-quota-file.php>
   ```

   This reads AWS and atomically writes a PHP-exit-guarded local snapshot. It
   creates no AWS resource and does not upload anything. A sandbox snapshot is
   valid evidence but **does not authorize sending**.
3. Supply that file through an authenticated, approved deployment channel or
   run the collector on the approved worker infrastructure using its read role.
   Set `EMAIL_SES_QUOTA_FILE` and the same `EMAIL_SES_QUOTA_HMAC_KEY` privately.
   Never install the existing operator AWS profile into the portal.
4. Refresh once per minute, monitor failures and keep maximum age at 300 seconds
   (hard maximum 600). Use atomic replacement and filesystem permissions/HTTP
   denial. Collector failure deliberately pauses new submissions. No scheduler
   was installed by this preparation.

Use `EMAIL_CAMPAIGN_PROVIDER=ses`, the exact regional SMTP host, dedicated sender
credentials and `messages@campaigns.ieosuia.com`; pin the configuration/topic
from the reviewed plan. Start `EMAIL_SES_SEND_RATE=1`, or lower, and retain daily,
hourly and per-sender limits. Provider quota is a ceiling, not a warm-up target.
The generic HMAC feedback secret is required only for the generic adapter;
direct SES feedback uses AWS RSA signatures. Neither path trusts unsigned input.

## Required launch order

1. **Hosting permission:** obtain Axxess's written approval for the campaign
   application/dispatcher with external SES, or choose infrastructure where it
   is allowed. Its published rules prohibit mass-mail scripts and bulk mailing
   on administered hosting; external SMTP alone does not resolve that restriction.
2. **Approve AWS setup:** review the policies/template, likely costs and resource
   scope. Install restricted identities without sharing keys in chat. Keep the
   portal send switches and SES configuration set off.
3. **Database and callback release:** take a recoverable backup, rehearse against
   a restored production schema, then apply only the reviewed email migration.
   cPanel has no terminal: prepare a narrow, expiring, one-apply migration link
   when that deployment is approved; no new link is active now. Deploy only a
   curated package on permitted infrastructure, preserving current SMS/tags/
   transfer/WhatsApp features. Confirm valid signed AWS feedback reaches the real
   callback without login or browser challenges; keep raw request bodies/tokens
   out of access logs. Reject bad signatures and verify DLQ handling/redrive.
4. **Public preference acceptance:** verify real anonymous GET/POST unsubscribe
   and abuse links with email switches off. No GET mutation, no broad WAF/auth
   bypass. Confirm a monitored abuse mailbox and sender-review operating owner.
5. **Production-access request:** complete and approve the separate draft below
   only when claims about deployed consent and working feedback are truthful.
   AWS domain verification is not production approval. Do not submit a misleading
   claim that local fixtures prove live feedback or inbox delivery.
6. **Quota/worker acceptance:** enable the reviewed SES configuration only for
   the explicitly approved pilot; install and verify quota refresh first, then
   bounded email worker scheduling. Health must stop sending when snapshots,
   feedback subscriptions or account approval are invalid. The email worker is
   separate from all existing SMS workers and transactional mail.
7. **Small opt-in pilot:** approve exact test recipients, test credit amount and
   content first. Check Gmail/Outlook/mobile-client From/Reply-To, SPF/DKIM/DMARC,
   signed RFC 8058 headers, delivered server feedback, unsubscribing, a simulated
   bounce/complaint, wallet conservation and complaint suspension. Inbox
   placement and DKIM coverage of unsubscribe headers remain unverified today.
8. **Gradual release:** approve initial daily volumes, review staffing, retention,
   monitoring/alerts and recovery ownership. Validate subscriber approval and
   consent evidence, and define stop thresholds. No blanket release is granted
   by passing local tests.

Rollback stops new claims first but preserves accepted checkpoints, wallet
reservations/ledger, preference links, suppressions and feedback reconciliation.
Do not reset held recipients to queued, restore stale wallet backups, or remove
real email tables as a code rollback.

## Verification

`python scripts/verify-email-readiness.py --mysql` runs only local test suites;
the MySQL switch starts a fresh loopback fixture and deletes only its generated
database. See the dated verification note for actual results and evidence paths.

Primary references: [SNS signature verification](https://docs.aws.amazon.com/sns/latest/dg/sns-verify-signature-of-message-verify-message-signature.html),
[SES original and provider message identifiers](https://docs.aws.amazon.com/ses/latest/dg/event-publishing-retrieving-sns-contents.html),
[SES event headers](https://docs.aws.amazon.com/ses/latest/dg/event-publishing-send-email.html),
[SES SNS publishing permissions](https://docs.aws.amazon.com/ses/latest/dg/event-publishing-add-event-destination-sns.html),
[SES quotas](https://docs.aws.amazon.com/ses/latest/dg/quotas.html),
[production access](https://docs.aws.amazon.com/ses/latest/dg/request-production-access.html),
[SNS retry and dead-letter behavior](https://docs.aws.amazon.com/sns/latest/dg/sns-message-delivery-retries.html),
[Axxess hosting rules](https://www.axxess.co.za/docs?tab=terms).
