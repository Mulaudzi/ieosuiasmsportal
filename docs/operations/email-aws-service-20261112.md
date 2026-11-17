# AWS campaign service: 10,000 recipient emails per day

This is the current implementation path. The older PDO/SMTP campaign worker remains a local historical implementation and must not be deployed to Axxess. Campaign state, review, pacing, MIME construction, dispatch, feedback and public preferences run on AWS. The portal authenticates customers, resolves their owned contacts and retains wallet authority.

Subsequent activation state: feedback acceptance and operational alerts are complete, and the chosen customer has preparation-only pilot access. Dispatch and capacity/isolation gates remain off. See [email-feedback-alerts-and-pilot.md](email-feedback-alerts-and-pilot.md) for the current monitoring and launch procedure; the initially disabled defaults described below remain the deployment defaults.

## Capacity and launch gates

The portal target is 10,000 recipient emails per rolling day. A campaign contains at most 1,000 eligible recipients. The initial deployment caps all attempts at 100/day and leaves dispatch, the minute schedule, real-feedback acceptance and concurrency isolation disabled. SES production access is a separate provider approval. Unused quota does not purchase messages or provision Lambda capacity.

The worker also checks sender allowance, SES account usage and 90 percent of the provider quota, using conservative overlapping hourly buckets. A provider quota above 11,112/day is needed to permit the full portal target with that headroom. Throughput at 10,000/day is a design target, not a measured production result.

Do not enable dispatch until production access, reserved concurrency, real feedback and preference verification, a separately authorized opt-in pilot and financial reconciliation all pass. Quota alone is insufficient. The first implementation has no automatic sender approval, campaign approval, provider rejection refund or resend after uncertain submission.

## Components

- `services/email_aws/engine.py`: immutable approved content and audience, distinct administrator review, durable submission checkpoints, overlapping quota budgets, suppression and accounting-only reconciliation.
- `store.py`: conditional DynamoDB transactions; SQLite implements the same document contract for isolated fixtures.
- `runtime.py`: single-attempt SES submission and signed, fixed-host portal accounting calls.
- `handler.py`: separate authenticated service API, bounded scheduled worker and dedicated SNS-to-SQS feedback consumer.
- `infrastructure.py`: PAY_PER_REQUEST DynamoDB with encryption/PITR, Python Lambda, SNS, encrypted SQS/DLQ, retained logs, a disabled schedule and an alarm. No provisioned concurrency, dedicated IPs or monthly SES plan.
- `api/email-aws.php`: authenticated, same-origin customer/admin gateway; current database ownership and verified email apply. Explicit pilot allowlist; no customer-selected actor or price.
- `api/email-aws-accounting.php`: HMAC, audience/path/body binding, timestamp window and persistent nonce replay protection. New attempts require sending enabled; acceptance settlement can continue while sending is paused.
- Migration `029_email_aws_bridge.php`: three additive tables only; no automatic execution of unrelated migrations.

Secrets are separate Standard SecureString SSM parameters. AWS never receives portal database credentials. The worker is the only runtime with permission to send from `messages@campaigns.ieosuia.com`. Portal and accounting keys are distinct. Secrets, bodies, tokens and recipient lists must not be printed to operational logs.

## Wallet contract

Five recipient emails consume one existing SMS credit, charged exactly per recipient. At R0.35/credit this is R0.07/email. The server derives the price; it refuses a price which cannot be represented in whole cents. Review reserves nothing. Explicit queueing reserves the exact approved audience once.

Every recipient needs a portal-issued attempt permit. Cancellation releases only unissued recipients, preserving unrelated SMS reserves. A durable checkpoint precedes the one SES network attempt. SES acceptance is persisted before a separate idempotent debit. Missing acknowledgement leaves the recipient held and funds reserved; accounting retries never send. Late hard bounces remain charged under acceptance-based billing. Acceptance does not promise inbox delivery.

## Verification and deployment

Use the checksum-verified portable MariaDB tools in the ignored `.sms-audit.local/email-aws-tools` directory; no shared database service is used. `scripts/test-email-aws-mysql.ps1` starts a disposable loopback fixture and tests genuine parallel reservation, cancellation, settlement and SMS/email competition. Previous XAMPP 10.4 native crash evidence is preserved in its owned temporary directories.

`manage_service.py test` requires a current-source MariaDB receipt, then runs Python contract/adversarial tests, PHP accounting fixtures and TypeScript. `plan` validates the CloudFormation template with AWS and creates a deterministic allowlisted runtime archive. `deploy` refuses stale acceptance, unknown resources and repeated deployment attempts. It creates only a disabled service. Inspect and resume the exact existing state after any cloud failure.

Install the pinned Python dependencies only into `.sms-audit.local/email-aws-tools/vendor` using `requirements.txt`. The portable fixture archive is the official `https://archive.mariadb.org/mariadb-11.4.13/winx64-packages/mariadb-11.4.13-winx64.zip`, SHA-256 `d62986d433eeebfde218560b276103831604a61e929e87f1a17f5aebd80257e2`; extract under the same ignored tools directory without installing a Windows service. Local commands assume the documented Windows PHP/Python tools and repository dependencies.

Authenticated accounting requests remove at most 25 expired nonce records at a time through the expiry index. Unexpired replay protection is retained; invalid signatures cannot trigger cleanup. DynamoDB service nonces have their own TTL. Do not remove either replay store to retry a financial operation.

Deployment and verification receipts live in `.sms-audit.local/email-aws-release-20261112`. That ignored directory contains encrypted backups and must not be published. Frontend publication must use a coherent captured live graph, with the new email pages and exact privacy component, and preserve all unrelated released logic. Do not build or deploy the entire dirty checkout or an entry file without its matching chunks.

## Operational limitations

Sender identity, recipient consent, content and links require human review. Syntax checks are not malware scanning. New sender details invalidate prior approval. Abuse reports and authenticated complaints suspend future claims. Unsubscribe GET is read-only; POST saves the choice. Suppression information persists to honor opt-outs.

Campaign records currently have no automatic retention purge. The public privacy disclosure identifies AWS and the configured Stockholm region and provides the existing deletion-request route. Deletion handling must cover AWS state, retained queues/logs and backup limitations. Do not promise automatic 90-day campaign deletion.

Inspect the DLQ and held/accounting-pending recipients before increasing the pilot allowance. Do not replay message submission to repair a balance or feedback failure. An alarm without an assigned operator or notification subscription is not an attended monitoring process.
