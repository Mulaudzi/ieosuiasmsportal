# Amazon SES onboarding — 29 October 2026

The owner selected the lowest-cost verified email transport and explicitly
confirmed the existing AWS account ending **0326**, default profile, configured
for **Stockholm / eu-north-1**. This authorizes the scoped SES preparation below,
not sending customer campaigns or deploying the pending email implementation.

## Verified pricing and changes made

[AWS pricing](https://aws.amazon.com/ses/pricing/) lists outbound **à-la-carte**
sending at **USD 0.10 per 1,000 recipient emails**, before taxes, outgoing data,
hosting and optional features. This is USD 10 per 100,000 recipient emails in
base transport fees, not a complete operational-cost quotation. Optional native
SES tenants, event infrastructure and dedicated IPs have separate prices.

The installed AWS CLI / botocore predates pricing-plan fields. A read-only capture
of the authenticated GetAccount HTTPS response confirmed that the region was
defaulted to `ESSENTIALS`, not the requested cheapest option. The setup verified
zero SES identities and zero sends in the previous 24 hours before changing the
region's plan. It then used the documented
[PutAccountPricingAttributes API](https://docs.aws.amazon.com/ses/latest/APIReference-V2/API_PutAccountPricingAttributes.html)
to request `Plan=NONE`. Readback confirmed **CurrentPlan NONE; NextPlan null**.
No other AWS Region's plan was changed.

Prepared resources:

- Configuration set `ieosuia-portal-campaigns`, with **SendingEnabled=false**.
  Reputation metrics are disabled; bounce/complaint suppression is enabled.
- Dedicated domain identity `campaigns.ieosuia.com`, using 2048-bit Easy DKIM,
  defaulting to that paused configuration set. It does not replace a root-domain
  identity or existing sender. A future platform From address could be
  `messages@campaigns.ieosuia.com`; no mailbox was created or configured.
- Custom MAIL FROM `bounce.campaigns.ieosuia.com`, configured to **reject** on
  MX failure rather than silently falling back to a different domain.
- Three exact DKIM CNAMEs derived from SES's returned `SigningHostedZone`, plus
  the new subdomain's MAIL FROM MX/SPF and a separate DMARC monitoring record.

The setup created no IAM access keys, SMTP credentials, dedicated IPs, SNS
subscriptions, hosted zones, compute services or paid deliverability add-ons.
It sent no email, changed no portal environment, ran no email migration and made
no SMS, wallet, billing, contact or application-deployment changes.

## Current status and DNS action

AWS preparation was verified at **15:41:45 UTC / 17:41:45 SAST**. SES remains in
the sandbox: **200 recipient emails per rolling 24 hours, 1 recipient per second**;
campaign configuration-set sending remains paused. Domain DKIM and custom
MAIL FROM verification are pending DNS publication. The account-level sending
flag is enabled by AWS, but it is not permission to send or evidence of portal
feature activation.

The authoritative nameservers are the existing hosting cluster, not a Route 53
zone in this account. Read-only matching-zone lookup found no Route 53 zone.
The existing cPanel credential/API path returned **HTTP 403**; this does not prove
that the password is wrong or that the customer-facing website is unavailable.
No DNS change was attempted. Do not disable host protection or repeatedly try
credentials to work around it.

The exact six public DNS records are exported to the ignored local file:

`.sms-audit.local/amazon-ses-setup-20261029/ses-dns-records.csv`

An authorized DNS operator should add them under `ieosuia.com` in cPanel's Zone
Editor or ask the hosting provider to add them. Check for conflicts first. Do
not replace root MX, SPF, DMARC, existing DKIM selectors or nameservers. The
record names are fully qualified: avoid appending `ieosuia.com` twice. Keep CNAME
targets as returned by SES. The MX priority is 10. The DMARC record is initial
monitoring (`p=none`), not a claim of full anti-spoofing enforcement.

After publication, inspect public DNS and SES until DKIM and custom MAIL FROM
both show success. DNS propagation and provider verification are not immediate.
[AWS identity verification](https://docs.aws.amazon.com/ses/latest/dg/creating-identities.html)
describes the authentication requirements. Do not send merely because one of
these checks passes.

## Remaining launch gates

1. Obtain written hosting permission for email-campaign scripts or use permitted
   infrastructure. [Axxess hosting terms](https://www.axxess.co.za/docs?tab=terms)
   prohibit mass-mailing scripts and bulk mail. An external SMTP account does
   not itself remove that restriction.
2. Implement and test the actual SES feedback adapter before asserting it is
   connected: verify SNS signatures/topic/account/region; reject unsafe signing
   certificate and subscription URLs; handle duplicates/reordering; map exact
   recipient IDs and original Message-ID from verified headers, not SES's
   rewritten Message-ID. Store provider correlation without authorizing resends.
   [AWS notification contents](https://docs.aws.amazon.com/ses/latest/dg/notification-contents.html)
   distinguish these identifiers. SDK/SMTP success does not mean inbox delivery.
3. Verify public unsubscribe/abuse callbacks work without login or bot challenges,
   and that one-click unsubscribe headers are signed correctly. Configure a real
   monitored abuse mailbox and protect consent/complaint evidence.
4. Match the worker to actual per-second and rolling-24h SES quotas. The existing
   generic worker's hour/day limits alone do not establish SES per-second pacing.
   Use a least-privilege runtime role or dedicated SES SMTP user, never the
   operator's current AWS credentials inside the portal.
5. Request SES production access only when claims about consent and working
   bounce/complaint handling are truthful. Describe the service as a multi-customer,
   opt-in campaign platform and select marketing when it is the dominant traffic.
   No production-access request was submitted in this setup.
6. Complete the previously documented email financial/concurrency acceptance,
   scoped migration 019, provider integration and release approval. Keep both
   portal email switches false and the SES configuration paused until then.
7. Only then conduct a separately authorized small opt-in pilot. No pilot was
   authorized or performed by this onboarding.

## Tools, evidence and resumption

- `scripts/setup-amazon-ses.py inspect`: read-only masked account/pricing/domain
  readiness. Captures immutable timestamped evidence, no credentials.
- `scripts/setup-amazon-ses.py prepare`: narrow, ownership-checked setup only.
  Pins the full authenticated identity hash privately, preserves a baseline,
  blocks unexpected paid plans/existing workloads and changed configuration,
  requests only `NONE` pricing, and cannot send or create credentials. Existing
  root-domain and unrelated AWS resources are never reset or deleted.
- `scripts/inspect-ses-dns.ps1`: read-only cPanel DNS inspection, showing HTTP
  status rather than exposing authentication values.
- `python scripts/test-amazon-ses-setup.py`: seven offline tests pass, covering
  cheapest-plan no-op, existing-workload and paid-plan guards, fixed signed TLS
  request, active-vs-scheduled pricing, older-SDK pagination and paused preparation.
- Private timestamped baseline, plan before/after, creation, DNS and inspection
  receipts are under `.sms-audit.local/amazon-ses-setup-20261029/`. The folder is
  Git-ignored. Credential values and full Authorization headers are not recorded.

Do not run generic migration/deployment scripts to resume SES onboarding. Inspect
first. Keep the campaign configuration paused; preserve DNS/key ownership,
original preparation receipts and any future accepted-message evidence. A
rollback must not change other workloads' sending/pricing settings or delete
customer data. The old local SDK was not upgraded globally.

## Verified follow-up — 30 October 2026

The earlier pending-DNS status above is historical: all six expected public
DKIM/MX/SPF/DMARC records now match, and AWS reports domain/DKIM/custom MAIL FROM
success. DMARC is still monitoring-only (`p=none`), not spoofing enforcement or
proof of inbox placement. Public DNS inspection used no cPanel credential or
zone change; unrelated rows in the owner's CSV notes were left untouched.

Production access remains false, configuration-set sending is paused, and no
feedback topic/subscription/event destination exists. The SES adapter, pacing,
local financial/HTTP/browser tests and review-only operator artifacts have been
prepared without changing AWS resources or production. See
[current launch checklist](email-ses-launch-readiness-20261030.md) and
[verification evidence](../verification/email-ses-readiness-20261030.md).
