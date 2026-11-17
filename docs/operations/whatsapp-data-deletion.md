# IEOSUIA Messaging data-deletion requests

The public instructions URL is `https://sms.ieosuia.com/data-deletion`.
The original `/data-deletion.html` URL redirects permanently to this page.
Select **Data deletion instructions URL** in Meta's app settings. This is a
manual request process, not an automated Meta deletion callback. The app's
OAuth return and signed message webhook are separate endpoints.

The page is maintained as `src/pages/DataDeletion.tsx`, using the site's shared
public header, account header, footer, button and theme. It is registered in
the React router and public SEO allowlist, with links in the shared footer and
privacy policy. The normal public-page prerender workflow renders the same React
content for visitors and crawlers without requiring JavaScript or a portal
login. Apache serves the public route and redirects the original `.html` URL.
The standalone hand-written HTML source has been removed and preserved in the
release evidence; future builds use the React source.

## Request intake

Requests go to the existing app contact, `sms@ieosuia.com`, with the subject
`Data deletion request - IEOSUIA Messaging`. A requester provides their portal
email, relevant business/number and deletion scope. A recipient without a portal
account can provide their receiving number and sender details instead. The
page's email link opens the requester's email application; it does not submit
an email or acknowledge a request automatically.

Keep the existing general contact form and its recipients unchanged. Do not
send deletion requests through a new form category or automatically CC another
mailbox. Do not request passwords, tokens, app secrets, verification codes or
identity documents in the initial message.

## Operator procedure

1. Record the request, receipt date, requester, requested scope and responsible
   operator in a restricted request log. Keep verification material minimal.
2. Verify control of the registered account or authority over the business or
   personal records before disclosing or deleting anything. Use the registered
   email and appropriate existing account verification. For lost-email access,
   resolve account recovery before granting account-level deletion authority.
   For recipient requests, verify the requester without exposing another
   customer's data. Never treat a number typed in an email as ownership proof.
3. Reply in the same thread with any verification needs, the agreed scope and
   an achievable expected completion time. This page establishes no invented
   fixed turnaround or automated deletion service.
4. Locate only the verified account/person's records. Review the connection
   tables, pending grants, the owner-scoped `whatsapp_workspace_operations`
   journal (including recipient numbers, template parameters and opt-in notes),
   stored webhook payloads/status events and any
   explicitly requested portal data. Record dependencies and justified retention
   before preparing changes. The existing owner-filtered webhook store is not
   a general cross-customer deletion interface.
5. Prepare exact account/record-scoped changes with a protected recovery plan.
   Validate ownership, affected rows, legal/security retention and shared-record
   dependencies before execution. No broad DELETE or cascade is authorized by
   this runbook alone. Obtain the authority needed for the specific request;
   requests concerning another company need verified delegated authority.
6. Execute the authorized changes once, verify the result, and retain a minimal
   completion record. An uncertain apply needs readback before any retry.
7. Tell the requester what was removed and explain any retained items, reason
   and applicable backup-removal limits. Confirmed requests must be considered
   before restoring older backups so deleted data is not silently reintroduced.

Disconnect is narrower than deletion: `MetaWhatsAppConnections::disconnect`
clears saved connection credentials and pending grants but retains the
connection/account reservation and metadata. Do not report a disconnect as full
data deletion. It does not remove the WhatsApp Business app account or revoke
the app's Meta permission. A requester can separately remove authorization in
Meta; data independently held by Meta or message recipients is outside the
portal's deletion action.

## Release boundary

Publishing these instructions changes no customer data, credentials, mail
routing, migrations, webhook filters, sending flags or account permissions.
The owner/operator must actually carry out and track verified requests; this
document does not establish prior operating history or automatically satisfy
Meta's public-authority process questionnaire.

Release receipts are stored under
`.sms-audit.local/whatsapp-data-deletion-20261104/`, with the React correction
under `react-release/`. Mailbox receipt and execution
of a real deletion request are separate acceptance steps and are not proven by
publishing or checking this page.
