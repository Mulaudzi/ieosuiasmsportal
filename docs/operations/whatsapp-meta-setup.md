# Direct Meta WhatsApp integration: first local stage

The owner selected WhatsApp inside this portal. On 29 October 2026 the owner
created the IEOSUIA Messaging Meta app and confirmed receipt of its dashboard
test message. That proves the dashboard test, not a portal-originated send or a
working public portal callback. The callback was subsequently deployed and
server-checked on 29 October. The owner's subsequent screenshots show Meta
callback verification complete and a successful dashboard `messages` test.
Real sender receipt and campaign sending remain unproven.

## Set up Meta first

1. Open [Meta for Developers](https://developers.facebook.com/apps/) and create
   or select the IEOSUIA business app. Choose the WhatsApp use case/product
   offered in your dashboard and connect the correct business portfolio.
2. Open the WhatsApp API Setup/Getting Started panel. Use the test sender offered
   there, add your own receiving number to the allowed test recipients, and
   complete its verification.
3. Record the **Phone Number ID**, **WhatsApp Business Account ID**, and the
   **Graph API version shown in the sample request**. These IDs are different
   from the displayed telephone number. Keep the access token and app secret
   in the private server environment, never frontend code, Git or chat.
4. Use the dashboard's available test template for the first test. Confirm
   WhatsApp receipt on your own phone before moving on to a production number.
5. Before production campaigns, confirm the sender's registration, permissions,
   current template/consent rules, billing and access token lifecycle in Meta.
   Customer-owned senders will need their own onboarding and tenant binding;
   this foundation is not a shared sender authorization model.

Meta's [official Cloud API collection](https://www.postman.com/meta/whatsapp-business-platform/collection/wlk6lh4/whatsapp-cloud-api)
describes the required business portfolio, WhatsApp Business Account and business
phone number, as well as `whatsapp_business_messaging` and
`whatsapp_business_management` permissions. Its
[template request](https://www.postman.com/meta/whatsapp-business-platform/request/o65u5m5/send-message-template-text)
uses `POST https://graph.facebook.com/{version}/{phone-number-id}/messages`.

## Local configuration and checks

Copy the **keys** from `api/.env.whatsapp.example` into the private API environment
when the app exists. The example is not automatically loaded. Keep
`WHATSAPP_SEND_ENABLED=false` while onboarding. Choose a separate random verify
token; do not reuse the Meta access token as the webhook verification token.

```powershell
php api/bin/whatsapp-check.php
php api/tests/run-meta-whatsapp.php
```

The check prints only missing/invalid key names and flags. It never connects to
Meta, prints secrets, queries the database, or sends messages. Exit code 1 means
configuration is incomplete. It loads the existing API environment loader.

## Implemented locally

- `MetaWhatsAppProvider`: one direct HTTPS template request, with optional body
  text parameters. It validates configuration and international recipient digits,
  keeps the bearer token in a header, verifies TLS and does not follow redirects.
  Successful acknowledgements are `accepted`, not `delivered`.
- `MetaWhatsAppWebhook`: challenge verification, exact-byte HMAC-SHA256
  authentication with the Meta app secret, WABA/phone binding, and extraction of
  `sent`, `delivered`, `read` and `failed` status events. Event keys support
  later database deduplication. This class does not persist anything.
- Ambiguous submissions return `uncertain=true`. No POST is automatically
  retried; callers must preserve uncertain attempts to avoid duplicate messages.

## Callback preparation, 29 October 2026

A dedicated entry point is deployed at `api/whatsapp-meta-webhook.php`:
`https://sms.ieosuia.com/api/whatsapp-meta-webhook.php`. The shared API router,
SMS engine and wallet code were preserved. The incoming callback was enabled
after the isolated inbox migration. **The owner has now shown Meta's green
callback verification and successful dashboard test; a stored event from the
configured sender has not yet been independently confirmed.**

The receiver verifies Meta's GET challenge, authenticates POSTs before database
access, bounds the body to 1 MiB and filters to the configured WABA/phone ID.
Migration `022_whatsapp_webhook_inbox.php` adds only two new tables. Matching
incoming-message payloads and lifecycle status evidence are retained durably
before HTTP 200 is returned; exact receipts and status events are deduplicated.
Out-of-order status evidence is appended, not used to downgrade message state.
No inbox-reading UI or message-status projection has been added.

Stored callback payloads can contain personal information and message content.
They are held in the private database, not logged or returned by the endpoint.
Set access and retention rules before any production customer rollout. The
initial deployment served only the configured platform test sender. The
production sender connection is recorded below; customer-owned sender onboarding
and external-app authorization are still absent.

Run `scripts/Start-WhatsAppWebhookSetup.cmd` to enter the **Meta App Secret** in a
masked Windows prompt. It generates a separate random verify token and stores
both with Windows-user DPAPI encryption and restricted directory access under
`%LOCALAPPDATA%\IEOSUIA Messaging\WhatsApp\meta-webhook.private.json`, outside
OneDrive and Git. It does not upload, migrate, publish or send. It refuses to
overwrite an existing configuration. Run the PowerShell helper with
`-ShowVerifyToken` to display an existing verification token locally.

The collector now requires the App Secret's 32 hexadecimal characters. A saved
value with access-token format was detected during the first owner setup; it
was not deployed. To correct this specific undeployed configuration, use
`scripts/Repair-WhatsAppWebhookSecret.cmd`. It rejects invalid input before
writing, atomically replaces only the App Secret, preserves the original
encrypted settings as a timestamped backup, and keeps the verify token intact.
It refuses corrections after a configuration is marked deployed. No real
settings are altered by the synthetic collector tests.

The initial test account IDs were pinned from the owner's setup screen:
`997917760024995` (WABA) and `1434779843044463` (phone ID), Graph `v25.0`.
An access token is not needed to receive signed callbacks. Keep outgoing
`WHATSAPP_SEND_ENABLED=false` independently of webhook enablement.

Deployment completed on 29 October 2026 at 20:38 UTC (22:38 SAST). Exact-byte
readback verified the five callback/migration files and the private-directory
HTTP guard. Only the two inbox tables, one migration-version row and the five
incoming WhatsApp environment keys were added. The rest of the environment and
protected application files were preserved. The private config directory
returned a masked 404 while a paired public control returned its exact body;
this confirms private files were not served. No site-wide protections were
disabled.

The public challenge returned the exact requested value, a wrong verify token
was rejected, and unsigned POSTs were rejected. An explicitly synthetic,
owner-authorized signed fixture was committed, replayed without duplication,
read back, then removed by exact receipt/event keys. No actual WhatsApp message
was sent and the Meta app was not published. The expiring installer and its
remote receipt files were removed and absence verified. The two earlier
preflight stops and their receipts remain preserved; they did not change the
production environment or database.

Receipts: `.sms-audit.local/whatsapp-webhook-setup-20261029/` with final release
`release-20261029T203743Z/`. The pre-release production environment backup is
DPAPI-encrypted in that release folder; no plaintext secret backup was saved.
The local private settings are marked deployed. The owner has completed Meta's
Verify and save step and subscribed the `messages` field. On 30 October 2026,
the owner supplied Meta's success notification for a v26.0 Incoming Message
dashboard test. Its displayed sample uses dummy phone ID `123456123`, which
does not match the configured phone ID. The receiver acknowledges authenticated
unmatched payloads with HTTP 200 and `matched=false`, without writing an inbox
receipt. The notification is therefore connectivity evidence, not proof of
durable message storage. The shown sample and current filter explain this
expected outcome; no production database readback was performed for that test.

The dashboard also warns that unpublished apps receive dashboard tests only.
Next inspect the app's Publish requirements with the owner, then complete the
app's applicable requirements before an actual sender event and independent
receipt readback. Inspecting requirements does not authorize publishing the app
or adding payment. Keep the pinned WABA/phone binding in place; do not accept
dummy sender IDs to make a dashboard test appear as a production receipt.

Local callback checks: `php api/tests/run-meta-whatsapp-webhook.php`. These use
synthetic SQLite fixtures and do not establish production MySQL concurrency or
live Meta reachability.

## Production sender preparation, 30 October 2026

The owner showed the Ieosuia business number ending 2493 as Connected in
WhatsApp Manager, then supplied Phone Number ID `599942253205002`. The intended
production WABA is `1199128038269252` (Ieosuia). These are owner-provided
identifiers at the start of preparation. At that point the deployed callback
and encrypted private settings still bound the test IDs. Subsequent API
verification and the incoming-only cutover are recorded below. App publication
and payment confirmation are not inferred.

After the owner saved the candidate, live read-only Meta checks on 30 October
2026 confirmed a valid SYSTEM_USER token for app `2108901959995210`, with
`whatsapp_business_management` and `whatsapp_business_messaging`. Meta reports
zero expiry timestamps; this does not guarantee the token cannot be revoked.
Phone ID `599942253205002` resolves to Ieosuia's number ending 2493 and returns
`platform_type=CLOUD_API`, `is_on_biz_app=true`, and
`code_verification_status=NOT_VERIFIED`. The latter was recorded as evidence,
not treated as permission to re-register or remove the Business app account.

The initial observed boundary was account access: GETs for intended WABA
`1199128038269252` and its phone/subscription edges returned Graph error 100,
subcode 33. A comparison GET on v26.0 returned the same error. The business
portfolio `792909870582893` was readable, but its owned-WABA listing exposed
only the test account and its shared-WABA list was empty for this token.
`GET /me/assigned_whatsapp_business_accounts` returned an empty complete list.
The token principal is SYSTEM_USER `122099871897530210`, named Employee.
These observations establish a missing/unavailable account association for
this token, not that the business number is absent or the token is expired.

The app-level webhook subscription is active, its callback matches the portal,
and it includes `messages`. Initially the production WABA subscription was unknown
because its lookup failed. The owner was guided to inspect the Employee system
user's assigned assets and the Ieosuia messaging account in Meta Business
Settings, preserving the existing phone app and chats.

Sanitized receipts: `.sms-audit.local/whatsapp-production-setup-20261030/`,
`inspection-20261030T205702387711Z.json` and
`inspection-20261030T205746238938Z.json`. Diagnostics used GETs only; no Meta
assignments, subscriptions, registration, messages, billing or live portal
configuration were changed by those diagnostics. At that point the private
candidate remained undeployed.

The owner then assigned Full access / Everything on the Ieosuia messaging
account to the existing Employee system user. Readback at 21:07 UTC confirmed
that the same token can now read WABA `1199128038269252` and that its complete
phone list contains `599942253205002`. This resolved the observed account-access
gap without regenerating the token. No app was subscribed to that production
account at that stage. The earlier failure receipts above remain historical.

At 21:11 UTC (23:11 SAST) on 30 October, the incoming callback was connected to
the verified production WABA/phone. The only server environment changes were
the two sender IDs and explicit `WHATSAPP_SEND_ENABLED=false`. The App Secret,
verify token, unrelated environment lines and twelve protected source/guard
files were preserved. No code or migration was deployed for this change. An
exact current environment baseline was saved with DPAPI encryption, verified
by decryption in memory, and retained for rollback.

The production callback returned the exact verification challenge, rejected an
incorrect token and an unsigned request, stored a signed synthetic event and
deduplicated its replay. An expiring, header-keyed verifier independently read
the exact synthetic receipt/status and removed only those two fixture rows.
The verifier and private staging files were then removed, with absence checked.
No real WhatsApp message was sent. A POST subscribed IEOSUIA Messaging to the
production WABA; subsequent GETs confirmed the exact app ID in its subscriptions.
The active local encrypted metadata now records production IDs; prior active
and candidate encrypted files were preserved outside the workspace. The access
token remains local and has not been uploaded to the server.

Cutover receipts:
`.sms-audit.local/whatsapp-production-setup-20261030/cutover-20261030T211043Z-e42c8f/`.
Final independent API readback:
`inspection-20261030T211228270801Z.json`. App-level callback and `messages`
subscription are active. A read-only attempt to query `is_published` returned
Graph error 100, so publication is still unverified; the last owner screenshot
said unpublished apps receive dashboard tests only. Payment remains unconfirmed,
and phone code status remains `NOT_VERIFIED`. Do not alter the existing Business
app registration to repair that status without establishing the correct flow.
Real Meta-origin event persistence and an owner-selected sending pilot remain
pending. Sending stays disabled independently of the incoming connection.

The owner then showed the refreshed dashboard's From dropdown open with an
empty list. Do not keep treating that UI as an asset-access failure: the API
association and subscription above now pass. A targeted read-only health check
at 21:18 UTC returned WABA status ACTIVE, account review APPROVED, business
verification verified, and phone status CONNECTED. Account currency was absent
and timezone_id was `0`. The overall messaging health was BLOCKED, with WABA
errors `141006` (payment method missing/problem) and `141007` (timezone must be
set). The phone entity was LIMITED pending display-name approval. Calling/SIP
warnings were also present, but the app entity was AVAILABLE; do not configure
calling to resolve this text-messaging setup.

Receipt: `inspection-20261030T211849238098Z.json` in the production setup audit
directory. These are confirmed API sending blockers, not proof of the specific
reason the dashboard dropdown is empty. Next complete billing on Ieosuia and
set its account timezone to South Africa / Johannesburg (UTC+02:00), then rerun
the read-only health check. No card details, billing changes, timezone changes,
registration operations or real sends were performed by that health check.

On 2 November 2026 the owner reported adding a payment method. A fresh GET-only
health check at 08:54 UTC (10:54 SAST) confirmed that errors `141006` and
`141007` are absent. WABA status is ACTIVE, its messaging health is AVAILABLE,
and its review/verification remain approved/verified. The phone remains
CONNECTED. Overall health is now LIMITED, with Meta's explanation that the
display name has not yet been approved. The account now reports currency USD
and timezone_id `141`; this check did not establish the timezone's display
name or change either setting. Calling/SIP warnings remain outside this
text-messaging task. Receipt: `inspection-20261102T085444977460Z.json` in the
production setup audit directory. No settings were changed and no message was
sent. The refreshed dashboard sender list, app publication and real-message
receipt still need confirmation before declaring end-to-end sending ready.

The owner confirmed the dashboard sender list was still empty after billing
was corrected. A further GET-only check at 09:00 UTC returned phone status
CONNECTED, platform_type CLOUD_API and is_on_biz_app true. Its name_status was
`NON_EXISTS`, not `PENDING_REVIEW`; the earlier statement that approval was
pending was too specific. Do not infer that a name review is underway from
the generic health explanation. The production template list was empty and
complete. Receipt: `inspection-20261102T090049180008Z.json`.

The owner explicitly approved one direct API test reply with exact text
`IEOSUIA WhatsApp API test.`, supplied an owner-controlled recipient ending
2775, and confirmed sending `API test` from that phone to the business number.
This established the owner-confirmed customer-initiated reply window. At
09:06 UTC (11:06 SAST) the one-off local pilot sent exactly one free-text
reply through the production phone's `/messages` endpoint. Its exclusive
attempt receipt prevents rerunning the same send; timeouts and server errors
are held as uncertain, with no automatic retry. Synthetic transport checks
verified those guards before the live attempt. No campaigns, bulk send
capability, sender registration, display-name changes or app publication were
performed as part of this test. The access token remained in local process
memory and was not uploaded to the portal.

Meta returned HTTP 200 with a provider message ID. A temporary, expiring,
header-keyed server probe then queried only that exact message's status rows
inside a READ ONLY transaction. It independently confirmed stored `sent`,
`delivered` and `read` callbacks: provider sent/delivered timestamp 09:06:30
UTC, read timestamp 09:06:37 UTC, and portal receipt timestamps 09:06:32 /
09:06:39 UTC. The probe and private staging file were removed and absence
verified. It wrote no database rows. This verifies actual Meta-origin status
persistence and a successful owner-controlled reply, beyond synthetic checks.
It does not establish customer campaign readiness or resolve the dashboard
dropdown. Sending in the portal remains disabled. New business-initiated
campaigns still need appropriate approved templates and the remaining portal
workflow, consent, billing and sender-isolation controls.

Pilot evidence in the production setup audit directory:
`owner-single-reply-attempt.json`,
`owner-status-readback-20261102T090823Z.json`, and
`owner-pilot-acceptance-20261102.json`. Earlier cutover receipts reporting no
real messages and unverified actual callbacks remain unchanged historical
snapshots; this owner-approved pilot is the subsequent verification.

The workspace is now `C:\IEOSUIA\GitHub\ieosuiasmsportal`; the former OneDrive
GitHub path no longer exists. The owner used the new launcher from this location:

```powershell
& "C:\IEOSUIA\GitHub\ieosuiasmsportal\scripts\Start-WhatsAppProductionSetup.cmd"
```

This prompts for an access token in a masked local console and creates a
separate `meta-production.private.json` in the existing restricted Windows
private directory. It preserves the active test configuration and reuses only
its DPAPI-protected App Secret/verify token. The new access token is also
DPAPI-protected. The script refuses overwrites and performs no network calls,
deployment, billing, app publication or sending. Its token format check does
not prove authorization. Do not paste the token in chat or put it in Git.

The token/app match, required permissions, exact production WABA/phone
association, WABA app subscription and bounded incoming callback change are
now verified as described above. Next inspect the refreshed app setup UI and
its Publish requirements, then verify an actual Meta-origin receipt. Keep
sending disabled until the pilot's billing, template and consent requirements
are satisfied. The local preparation launcher intentionally refuses another
collection over existing private settings; it is not a token-rotation tool.

## Later campaign integration

### Customer connection implementation, 3 November 2026

Latest configuration follow-up: the owner provided config
`29417773921150379` for app `2108901959995210`, with Meta-hosted v4,
sessionInfoVersion 3 and WhatsApp Business app onboarding. Those options are
configured on the server. Signup is enabled only for authenticated portal
administrators (`WHATSAPP_CONNECT_ADMIN_PILOT_ONLY=true`) for the first real
Meta round-trip. General customer signup remains closed and message sending
remains false. Live configuration/URL/state-output/pilot-gate/encryption and
HTTP/readback checks passed, with 80 offline authorization and 29 HTTP checks.
No real Meta return or customer token grants have yet been observed. Current
receipt: `.sms-audit.local/whatsapp-customer-connect-20261103/config-29417773921150379/final.json`.

Deployment follow-up: the owner subsequently requested **deploy**. The callback,
Settings UI, migration 026, private credential encryption configuration and one
cleanup cron job have been released. The harmless callback landing page is live
at `https://sms.ieosuia.com/api/whatsapp-meta-oauth.php`. Customer connection
activation and sending remain false pending the generated Meta configuration ID
and a real signup pilot. See the deployment follow-up in the verification report
and its final remote-byte/browser/cleanup receipt. No message was sent by this
deployment; no owner access token was deployed.

The initial local implementation phase provided the customer-owned connection flow, with a
Settings panel, authenticated start/confirm/disconnect API, separate OAuth return
handler, encrypted per-owner credentials and additive migration 026. Sending is
still disabled. At that initial phase no deployment or live schema/configuration changes were made.
The exact implementation, offline test results, release prerequisites and
remaining product work are recorded in
`docs/verification/whatsapp-customer-connect-20261103.md`.

The planned redirect is `https://sms.ieosuia.com/api/whatsapp-meta-oauth.php`;
it must be deployed and verified before being described as live. Meta-hosted
signup state forwarding and actual customer token grants still require a real
pilot. Do not substitute the incoming-message webhook for the OAuth handler.

### Customer-owned senders confirmed, 3 November 2026

The owner clarified that the intended product lets other customers send to
their own contacts, and explicitly chose **each customer connects their own
WhatsApp Business number**. An IEOSUIA-only admin pilot was a verification stage,
not the final product model. Do not implement a shared IEOSUIA sender for all
customers or treat the owner's test token as customer authorization.

The intended customer workflow is: sign in to the portal, connect WhatsApp
through Meta, import contacts with recorded opt-in, create/select approved
templates, preview/send a campaign, and see its delivery/read results and costs.
The template page shown during this discussion belongs to the IEOSUIA messaging
account. Its templates do not automatically become templates for other
customers' WhatsApp accounts. Template category must match the actual message;
there is no universal template that authorizes arbitrary outbound campaign text.

The next Meta setup work is Tech Provider onboarding and the applicable App
Review/access requirements for customer accounts, followed by Embedded Signup
in the portal. Meta's official partner overview describes onboarding clients
through Embedded Signup and campaign tools on their behalf:
https://whatsappbusiness.com/partners/become-a-partner/ .
The onboarding reference is:
https://developers.facebook.com/documentation/business-messaging/whatsapp/embedded-signup/onboarding-customers-as-a-tech-provider .
Exact available requirements must be checked in the owner's app dashboard;
this note does not claim provider approval or completed App Review.

Implementation must bind each verified WABA/phone connection to its portal
owner, protect per-customer credentials, and keep contacts, templates,
submission attempts, status projections, costs and reports isolated. The
current single-sender webhook filter must become a lookup of verified customer
connections while retaining signature verification, bounded payloads, durable
acknowledgement and replay protection. Sender IDs provided by a browser are not
authorization. Add disconnect/revocation handling, duplicate-safe submission,
consent/opt-out enforcement and WhatsApp-specific accounting before enabling
customer sending. Existing WhatsApp Business app numbers need the appropriate
Meta onboarding flow without removing the customer's existing app account.

Business-initiated messaging uses approved templates; free-text replies are
limited to the applicable customer-service window. Opt-in and opt-out handling
must follow Meta's current policy:
https://whatsappbusiness.com/policy/ . Neither creating the owner's template
nor the successful owner reply grants cross-customer sending access. No customer
onboarding endpoint, campaign worker or customer sending switch was enabled
by this clarification.

No WhatsApp campaign screen, outgoing worker or external-app API has been
enabled. Connect authenticated account-scoped sender configuration, consent and
template selection, durable submission records and timestamp-aware status
handling before a sending pilot. Keep WhatsApp usage and pricing distinct from
SMS segment accounting. Free-text/media messages and reply-window enforcement
are outside this first template adapter.

Before release, verify the public callback accepts Meta's challenge and signed
POSTs without a browser verification page. Earlier temporary portal diagnostics
encountered hosting verification; a browser success alone does not prove Meta
can reach a callback. Test actual delivery events and replay/out-of-order
events before enabling customer campaigns.

### Guymhan administrator setup

The administrator's platform setup and first Meta connection belong in the SMS
portal's Guymhan console: `https://sms.ieosuia.com/guymhan/whatsapp`.
Sign in through `https://sms.ieosuia.com/guymhan/login`, then choose **WhatsApp
setup** under Configuration. The former administrator Settings deep link
redirects to this page. Successful OAuth returns use the server-verified owner
role: administrators return to Guymhan, customers to their own Settings.

Use **Connect WhatsApp Business** from inside the portal to create the
session-bound signup attempt. The copied reusable Meta onboarding link alone
cannot establish a portal connection. Complete Meta's flow in the same browser,
then choose the authorized business number and explicitly confirm it.

This is the administrator pilot. Customer signup remains closed and message
sending remains disabled. Each customer will connect their own number when
customer onboarding opens; the IEOSUIA connection is not their shared sender.
The real Meta return and token grants still need the administrator's browser
pilot. Deployment and validation evidence for the placement correction is
recorded in `docs/verification/whatsapp-customer-connect-20261103.md`.
