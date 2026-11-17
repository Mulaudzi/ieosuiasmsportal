# AWS bulk-email service

The owner selected a target of **10,000 recipient emails per day** on
12 November 2026 and authorized continuing implementation and setup. This is
an account-wide capacity target, not a prepaid purchase or permission to send
a particular campaign. Start with a 100-attempt/day pilot after acceptance.

## Deployment boundary

The earlier Fargate proposal was a candidate. The implemented package uses
pay-per-use Lambda, DynamoDB, SNS and SQS to avoid a continuously running server,
load balancer, NAT gateway or database instance. Shared secrets use dedicated
Standard SSM SecureString parameters and narrowly scoped IAM roles. No paid
SES plan, dedicated IP or provisioned Lambda concurrency is needed.

- `services/email_aws/`: AWS-owned sender/campaign/recipient state, independent
  review, immutable content/audience snapshots, suppression, unsigned public
  preference capabilities, durable pre-send checkpoint and SES API submission.
- `api/email-aws.php`: authenticated customer/admin gateway. It derives the
  account, verified Reply-To, owned contacts/groups and current price itself.
  Browser-selected actor, reservation, price and balance values are rejected.
- `api/email-aws-accounting.php`: signed service-only attempt, settlement,
  unattempted release and status operations. It cannot create reservations.
- `EmailAwsAccounting`: the authenticated portal reserves funds only after
  checking the AWS-approved immutable campaign. The wallet and existing SMS
  ledger stay authoritative on the portal. AWS never receives SQL credentials.
- `029_email_aws_bridge.php`: three additive accounting/replay tables. The old
  campaign migration 019 and cPanel bulk-mail worker are not part of this release.

Only the two gateway files, their four narrow dependencies and migration 029
belong in the portal backend release. Existing transactional SMTP, SMS workers,
WhatsApp credentials and customer roles are unrelated and must be preserved.

## Duplicate, cancellation and accounting behavior

The signed protocol binds audience, method, exact path, timestamp, random nonce
and raw-body SHA-256. Signature verification and durable nonce insertion precede
new effects. Customer roles are checked by the portal on each request. Keys for
portal delegation, AWS accounting and public preferences are separate.

DynamoDB transactions condition every affected recipient/campaign/profile/
suppression version. The rolling budget and next allowed slot are committed
with the claim. A recipient becomes `preparing` before requesting a portal
permit and `submitting` before SES. The SES SDK is configured for exactly one
submission attempt. Neither restart, lease expiry, retry nor queue redelivery
turns an uncertain recipient back into sendable work.

Portal MySQL row locks serialize competing SMS/email wallet operations. Each
campaign reservation binds its exact recipient IDs/hashes, content fingerprint,
verified Reply-To hash and server-derived per-recipient price. Cancellation can
release only recipients without an issued permit. An accepted email charges
0.2 SMS credits exactly once; accounting retries do not invoke SES. Inactive
accounts cannot obtain new permits, but prior accepted work can still settle.

Hourly buckets conservatively include the full oldest hour when enforcing the
rolling daily limit, avoiding a large counter document and undercounting across
boundaries. SES health/quota observations add conservative overlap headroom.
The global target never exceeds 10,000; a lower provider quota or pilot limit
can restrict throughput further. These are ceilings, not measured throughput.

## Feedback and preferences

SES publishes the dedicated configuration set's SEND, DELIVERY, BOUNCE,
COMPLAINT and REJECT events to its pinned SNS topic. Only that SNS topic can
publish to the service-owned feedback queue. Lambda verifies the actual SQS
source and enclosed SNS topic before mapping an event. Message ID, account,
configuration, exact destination, original Message-ID and opaque tags must all
match the saved attempt. Delivery does not mean inbox placement or reading.

An email's public preference URL carries a 256-bit HMAC capability. GET only
shows confirmation; POST suppresses future campaigns from that account. An
abuse report also suspends the sender. Duplicate complaints/reports cannot
repeatedly suspend an independently reapproved sender. Bounce/complaint states
take precedence over delayed delivery. Suppression and the final submission
checkpoint share a DynamoDB transaction boundary.

SNS/SQS retries may retry feedback/accounting, never sending. Invalid or
unresolvable events are retained in the feedback DLQ after five attempts.
Do not delete or blindly acknowledge uncertain financial evidence. Real SES
event and mail-client tests are still required; fixtures are not provider proof.

## Build and setup

1. Install the pinned `requirements.txt` in an isolated tools directory
   `.sms-audit.local/email-aws-tools/vendor`, leaving global SDKs unchanged.
2. Run `scripts/test-email-aws-mysql.ps1` directly. It creates an owned loopback
   MariaDB on port 33169, refuses an occupied port, uses independent PHP
   processes, verifies real InnoDB races, shuts down only its own server and
   preserves logs. It writes a receipt bound to the tested bridge sources.
   Its per-connection thread mode avoids a native crash seen in the installed
   Windows MariaDB thread-pool implementation; production settings are unchanged.
3. Run `python services/email_aws/manage_service.py test`. This checks the receipt,
   Python service/adapters, PHP accounting fixtures and TypeScript. No live mail
   or customer data is involved.
4. Run `python services/email_aws/manage_service.py plan`. This validates the
   CloudFormation template with AWS and creates a deterministic, allowlisted
   code archive. It creates no AWS resource and records source/package hashes.
5. `deploy` requires matching successful acceptance and plan hashes. It creates
   only the dedicated code bucket, SecureString parameters and named stack.
   It preserves unrelated resources and refuses unknown existing ownership or
   an uninspected repeated deployment attempt. Both dispatch and its schedule
   are initially disabled. No IAM access key or SMTP user is created.
6. Complete disabled live-service tests and a scoped portal migration/release.
   Do not deploy the entire dirty checkout or run every pending migration.
7. Verify actual SES/SNS/SQS feedback, unsigned callback access, DKIM coverage
   of one-click unsubscribe headers, real client rendering, account ownership,
   wallet conservation, operator monitoring and processor/privacy disclosures.
8. Submit the accurate SES production-access request and conduct the separately
   authorized small opt-in pilot. Raise limits gradually within AWS approval.

The operator corrects only its own request-signing time against an authenticated
AWS HTTPS Date header when the PC clock is skewed; it never changes OS time or
disables TLS. Credentials and raw authorization headers are not printed.

## AWS account quota

The existing eu-north-1 Lambda account limit was 10. AWS requires at least 100
unreserved executions before allowing positive reserved concurrency. A request
for 128 was rejected because the quota API requires a value above its default
1,000; a request for **1,001** was submitted and is pending. This does not buy
provisioned capacity. Only five executions are intended to be reserved across
API, worker and feedback once approved. The initial disabled setup can use
unreserved functions, with the feedback source limited to two consumers.
`ISOLATION_VERIFIED=false` remains an independent dispatch blocker until the
approved concurrency settings are applied and inspected.

## Costs and retention

SES remains on a la carte pricing: USD 0.10 per 1,000 recipient emails, so
300,000 actual emails in a 30-day month have a USD 30 base sending charge.
This excludes data, tax, database operations/storage/PITR, Lambda, SNS/SQS,
logs, alarms and code storage. Requesting a larger quota does not prepay usage.
Standard SSM parameters avoid a recurring Secrets Manager per-secret charge;
KMS/API pricing still follows AWS terms. No total monthly bill is guaranteed.

Read-only regional AWS Price List rates retrieved during preparation included
DynamoDB Standard writes USD 0.67/million units, reads USD 0.1345/million units,
and storage USD 0.269/GB-month. Lambda tier-1 x86 compute was
USD 0.0000166667/GB-second and USD 0.20/million requests. Actual units depend on
document sizes and retries; a transaction consumes more than a normal write.
Immutable content is stored separately from small accounting/status documents
to avoid rewriting the complete audience on each recipient settlement.

Financial state, suppressions, event references and customer content are retained
on stack deletion rather than silently discarded. Nonce TTL is independent of
replay timestamp validation. Logs retain 14 days and never intentionally contain
message bodies, recipient lists, credentials or preference tokens. Define and
operate the content/consent deletion policy before general customer rollout.

## Rollback

Stop new dispatch/attempt permits first. Preserve feedback, preferences and
accounting for previously accepted work. Never reset held/submitting recipients
to queued or release issued permits without matching evidence. Preserve DynamoDB,
portal reservations/ledger, suppressions, SSM key continuity and retained DLQ
records. Do not restore stale whole-wallet backups over unrelated activity.

Primary documentation checked during implementation:

- https://docs.aws.amazon.com/ses/latest/APIReference-V2/API_SendEmail.html
- https://docs.aws.amazon.com/amazondynamodb/latest/developerguide/transaction-apis.html
- https://docs.aws.amazon.com/lambda/latest/dg/services-sqs-errorhandling.html
- https://docs.aws.amazon.com/lambda/latest/dg/configuration-concurrency.html
- https://docs.aws.amazon.com/lambda/latest/dg/urls-auth.html
- https://docs.aws.amazon.com/ses/latest/dg/event-publishing-retrieving-sns-contents.html
- https://aws.amazon.com/ses/pricing/
- https://aws.amazon.com/systems-manager/pricing/
