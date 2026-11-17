# Continue IEOSUIA Meta App Review uploads

The owner has authorized uploading both prepared videos and finishing the Meta
App Review submission. The preceding chat could not access Chrome: plugin
switches were enabled locally, but no browser-control tools were attached to
that conversation. Start by verifying the actual Chrome connection in this
fresh desktop chat. The owner will sign into Facebook themselves if necessary.

## Target

- Meta app: **IEOSUIA Messaging**, ID **2108901959995210**.
- Open `https://developers.facebook.com/apps/2108901959995210/`.
- Navigate to the current **App Review** draft and **Allowed usage**.
- Inspect current state before any action. Nothing was uploaded or submitted by
  the preceding chat; the owner may have made changes separately.

## Verified upload files

1. Permission **whatsapp_business_messaging**:
   `C:\Users\venda\Downloads\IEOSUIA Meta Review 2026-11-12\whatsapp_business_messaging.mp4`
   SHA-256 `751d5578fd608cb32a7f5124f3205468fbe3c813e57fa853b79f85f2d020fc44`.
2. Permission **whatsapp_business_management**:
   `C:\Users\venda\Downloads\IEOSUIA Meta Review 2026-11-12\whatsapp_business_management.mp4`
   SHA-256 `8d21b99538054c6b58f58e50fac5d12479d54ffff6a6dc78f2626212275d5005`.

Both MP4s passed full decode checks and visual inspection. Save each permission
form after its upload finishes and verify the saved attachment. Do not attach
either video to the separate **business_management** permission by assuming it
is the same as **whatsapp_business_management**.

## Actual product and reviewer access

- Operator: **IEOSUIA (Pty) Ltd**, South Africa.
- Customer app: `https://sms.ieosuia.com/`.
- Customer login: `https://sms.ieosuia.com/login`.
- Reviewer account: `reviewer@ieosuia.com`, normal customer role, no admin access.
- Customer WhatsApp workspace: `https://sms.ieosuia.com/settings?tab=whatsapp`.
- Reviewer instructions: `docs/operations/whatsapp-meta-reviewer-instructions.md`.
- Privacy: `https://sms.ieosuia.com/privacy-policy`.
- Terms: `https://sms.ieosuia.com/terms-of-service`.
- Deletion instructions: `https://sms.ieosuia.com/data-deletion`.

Keep internal **Guymhan** URLs out of reviewer instructions. Customers connect
their own business numbers; the reviewer pilot uses the authorized IEOSUIA
number ending 2493. South African sender/recipient support is enforced in this
pilot. General customer access remains gated.

The owner supplied the reviewer password for the Meta reviewer-access field.
An encrypted copy is at
`.sms-audit.local/whatsapp-review-20261111/reviewer-credentials.dpapi`.
Existing audit `release.py` exposes `h.protected_bytes(raw, True)` for decrypting
that JSON in memory if needed. Never print the password, store a plaintext
copy, include it in source, or use it to sign into Facebook. It belongs only to
the dedicated IEOSUIA portal customer account. Facebook sign-in belongs to the
owner's already authenticated Chrome profile.

## Evidence already completed

- The customer reviewer signed in through normal IEOSUIA OIDC authentication.
- The owner WABA/phone association was verified through Meta and its token is
  encrypted in the customer connection record.
- Real template creation succeeded; Meta approved
  `ieosuia_setup_confirmation_v1`, language `en_ZA`, category `UTILITY`.
- The owner authorized exactly one test to their personal number ending 2775.
  The customer UI submitted it once; signed Meta events confirmed sent,
  delivered and read. **Do not send another message for this upload task.**
- The messaging video combines the real portal send with the actual received
  message in the phone's recipient WhatsApp chat. Its later confirmation and
  0.5x portal playback are clearly labeled. Original recordings are preserved.
- The template video shows real customer creation and distinguishes initial
  PENDING submission from later approval.
- Source clips and full receipts are under
  `.sms-audit.local/whatsapp-review-20261111/`.

## Finish the current draft accurately

Preserve existing accurate answers. Inspect remaining sections and complete
those supported by the product, recordings, owner-provided details and current
Meta requirements. The owner authorized submission; verify its actual receipt
and status afterward, and distinguish submitted/in review from approved/live.

Do not invent business-portfolio API functionality for `business_management`.
Check whether the hosted signup configuration requires that broader permission.
Do not invent processor countries, public-authority disclosure history, a DPO
appointment or operating procedures; unresolved factual declarations need the
responsible owner's answer. Do not open general customer onboarding, change
other accounts, or treat a successful upload as app approval or publication.

If the browser connection is still missing, report that specific capability
failure. Do not claim to have viewed Meta, uploaded files or submitted the draft.
