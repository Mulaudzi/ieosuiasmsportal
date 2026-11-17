# Meta customer reviewer instructions — 11 November 2026

App: IEOSUIA Messaging, operated by IEOSUIA (Pty) Ltd.
App ID: `2108901959995210`.

## App location

`https://sms.ieosuia.com/`

Customer sign-in: `https://sms.ieosuia.com/login`

WhatsApp workspace after sign-in:
`https://sms.ieosuia.com/settings?tab=whatsapp`

## Testing instructions

1. Open the customer sign-in URL and use the dedicated customer account
   `reviewer@ieosuia.com`. Supply its password separately in Meta's credential
   field; do not include a personal Facebook password, token or app secret.
2. From the customer portal, open **Settings → Connected apps → WhatsApp**,
   or use the workspace URL above. The test account has no platform
   administrator access.
3. The review account is preconnected to IEOSUIA's own South African business
   number ending **2493**. This is an authorized review asset, not a claim that
   general customer Embedded Signup has passed review. Avoid disconnecting it
   during the demonstration because reconnecting through Meta may require the
   Advanced Access approvals being requested.
4. Under **Messages & templates → Templates**, use **Refresh** to retrieve the
   actual templates and review statuses from Meta. To demonstrate creation,
   enter a unique lowercase template name, choose the appropriate category and
   English language, enter plain text without variables, and choose **Submit
   template to Meta**. A successful submission is not template approval: Meta
   determines its status.
5. Under **Send message**, choose an **APPROVED** supported text template. Enter
   a South African recipient in `+27` format that you control or that has
   explicitly consented to this test. Enter any required template parameters,
   confirm opt-in and record how permission was obtained. Choose **Review
   message**, inspect the sender, recipient and preview, then choose **Send
   WhatsApp message**. Meta messaging charges may apply; SMS credits are
   separate. Do not use an arbitrary contact or repeatedly resend a test.
6. Open **Recent activity**, then **Refresh activity**. **Accepted** records
   Meta's submission response; **sent**, **delivered** and **read** require
   signed webhook evidence. An unconfirmed submission is not automatically
   resent. Show receipt in the recipient's actual WhatsApp interface in the
   messaging screencast.

## Facebook Login and Meta integrations

Facebook Login for Business / WhatsApp Embedded Signup connects the customer's
own WhatsApp Business assets. Portal authentication uses IEOSUIA customer
sign-in separately. The integration uses `whatsapp_business_management` for
authorized business/number information and template management, and
`whatsapp_business_messaging` for requested WhatsApp messages. Business access
tokens are encrypted on the server and are not exposed to other customers.

For `business_management`, confirm whether the current hosted signup
configuration requires the broader permission. The deployed customer workspace
does not implement a business-portfolio management UI. Do not describe or film
portfolio operations that are not implemented. `public_profile` may be part of
the Meta authorization configuration; portal customer login is separate.

## Access and geography fields

The service currently supports South African business senders and `+27`
recipients. The reviewer uses a dedicated South African test account and
authorized assets. This workspace does not introduce an IP-based geographic
block. No app-store download or download payment applies. The reviewer account
has no administrator access and needs no SMS credit purchase for the WhatsApp
pilot; Meta's WhatsApp billing remains separate.

Customer pilot access is explicitly limited to this account and existing
administrators. General customer signup is not opened by this release.

## Public URLs

- Privacy: `https://sms.ieosuia.com/privacy-policy`
- Terms: `https://sms.ieosuia.com/terms-of-service`
- Data deletion instructions: `https://sms.ieosuia.com/data-deletion`

## Submission boundary

The owner test asset's `ieosuia_setup_confirmation_v1` template was approved by
Meta after the initial deployment. One explicitly authorized test through the
customer UI was accepted and confirmed delivered by signed webhook events.
The actual template-creation and message-sending source recordings are stored in
`.sms-audit.local/whatsapp-review-20261111/recordings/`. On 12 November 2026,
the owner supplied a phone recording showing the matching received message.
The final MP4 exports are under `recordings/review-export-20261112/`:

- `IEOSUIA-WhatsApp-Messaging-Review.mp4` includes the customer send and the
  recipient-side WhatsApp confirmation, explicitly labeled as recorded afterward.
- `IEOSUIA-WhatsApp-Template-Management-Review.mp4` shows the actual template
  creation. It distinguishes initial submission from subsequent Meta approval.

Both preserve the recorded screen content. Portal playback is labeled 0.5x;
the phone segment is at its original speed. Originals are unchanged. Do not
upload the local inspection frames or editing parts as review evidence.

Use the above as final testing instructions only when an approved template,
successful real send/receipt demonstration and matching recordings exist.
Keep `whatsapp_business_messaging` review pending until that evidence is ready.
The template-management recording must show actual API-backed creation; the
offline fixture browser tests are never App Review evidence. Meta review,
Advanced Access approval, Access Verification and app publication are external
states and are not established by a portal deployment.

Before submitting, confirm processor countries, any public-authority disclosures
and actual policies with the responsible operator; do not invent declarations.
