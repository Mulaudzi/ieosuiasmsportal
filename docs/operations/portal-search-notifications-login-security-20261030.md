# Portal credits, search, alerts and optional sign-in verification

Deployment follow-up: the portal release and the separately approved Central Auth
publisher are now deployed. See the [portal release](../verification/portal-production-release-20261030.md)
and [password-event release](../verification/password-events-release-20261030.md).
Statements below about local-only status and the disconnected publisher describe
the original implementation stage; the owner-only verification pilot remains.

Local implementation, 30 October 2026. Nothing has been deployed, migrated,
enabled or sent. All previously deferred SES work remains deferred. This release
does not create AWS resources or alter shared Central Auth security settings.

## Customer features

- Dashboard balance is the server's available SMS-credit count, excluding
  reservations. Loading/errors do not become false zeroes. Buy and transfer
  actions use the existing wallet and customer transfer flow. Transfers retain
  review, recipient confirmation, transactional email code, idempotency and
  server-side available-balance checks. Completing a transfer invalidates the
  dashboard balance cache and refreshes notifications.
- Global search is available from the desktop and mobile header and Ctrl/Cmd K.
  It offers shortcuts, then debounced campaign/contact/SMS-template suggestions
  after two characters. Arrow keys/Enter/Escape and explicit error/retry are
  supported. Contact/template links apply a real destination-page search filter.
  `GET /search?q=...` is authenticated, account-scoped and read-only, limits
  queries to 80 characters, five results per kind, literal SQL wildcard escaping,
  and 90 requests/account/minute. No customer directory, message bodies, other
  accounts, email templates or arbitrary navigation URLs are returned.
- Existing purchase, sending-completion and first/final delivery notifications
  are preserved. Committed transfers create one notice for each affected account;
  no counterpart email, bank detail, body, password or code is included. Security
  events have fixed safe copy. The existing event deduplication ledger keeps
  dismissed alerts from reappearing. Missing optional transfer/security tables
  do not break old notifications. Alerts are polled, not instant push guarantees.
- Notifications link to owned campaign details, Wallet or the Security tab.
  Pending network confirmation is not described as a failed submission.

## App-local email verification decision

Recommendation: an optional extra sign-in step in the **SMS Portal only**.
It follows successful Central Auth authentication; other IEOSUIA apps are not
changed. Shared passwords remain owned by Central Auth. A future organization-
wide MFA policy should be designed centrally, rather than silently extending an
app's opt-in choice to unrelated products.

Email codes are deliberately described as an extra sign-in check, not strong or
phishing-resistant MFA. A compromised mailbox can defeat this check. Passkeys
or authenticator-based verification are stronger future options; financial
transfer approval remains independently required even with this check enabled.
See [OWASP MFA guidance](https://cheatsheetseries.owasp.org/cheatsheets/Multifactor_Authentication_Cheat_Sheet.html)
and [NIST authentication guidance](https://pages.nist.gov/800-63-4/sp800-63b.html).
No NIST/AAL compliance claim is made.

### Implementation

- Additive migration **023_portal_login_security.php** adds app-local security
  state, opaque login challenges and safe security events. It does not run email
  migration 019 or modify wallets. No migration was executed on production.
- Security tab lets a verified active customer request an enable/disable code
  through existing **transactional SMTP**, not SES campaign transport. Enabling
  is opt-in and requires correct confirmation, not merely toggling a switch.
- Codes use `random_int`, exactly six digits, expire after ten minutes, are
  one-use and stored only as challenge-bound HMAC digests with a dedicated secret.
  Challenge capabilities are random 256-bit values stored hashed.
- Challenge issuance is serialized per account, with a 60-second send cooldown,
  at most five requests per 15-minute window and five persisted wrong-code
  attempts before a 15-minute block. New challenge IDs do not reset failures.
  Endpoint-level rate limits add account/IP protection. Existing SMTP debug is
  explicitly disabled around code mail; no code is put in notification copy.
- Enrollment/disable requests bind the existing bearer session, purpose, account,
  verified email, auth version and security epoch. Settings changes increment
  `users.auth_version`, invalidating other app sessions and pending challenges.
  Confirmation returns a replacement token for the initiating session.
- Central SSO callback checks the app setting. If enabled, it sends a code and
  redirects with an opaque fragment challenge, **not a usable portal token**.
  An HttpOnly Secure SameSite=Strict cookie binds the challenge to that browser.
  The page removes its fragment and confirms only by POST. Refreshing that page
  requires restarting sign-in; no capability is persisted in local storage.
- Both real `Auth::check()` and lazy `Auth::user()` reject enrolled accounts
  without a matching signed `sms_two_step_version` JWT claim. Refresh preserves
  the validated proof; ordinary SSO cannot skip it. Database faults fail closed.
- Eight random high-entropy recovery codes are shown once after enrollment and
  stored as digests. They can replace the email code at a pending login, once
  each. Using one records a nonsensitive notice; disabling clears unused codes.
  No support/admin bypass or reveal-code endpoint exists. Recovery login still
  requires a valid Central Auth sign-in and browser-bound challenge; it does not
  recover the shared password. If SMTP is unavailable, login challenge issuance
  stops; support must repair SMTP rather than bypass verification.
- Sign-in, recovery and security-setting tests never use actual system SMTP.

### Routes

| Route | Access / purpose |
| --- | --- |
| `GET /settings/login-security` | Authenticated, own availability/status only |
| `POST /settings/login-security/request` | Authenticated, purpose `enable` or `disable`; explicit email |
| `POST /settings/login-security/confirm` | Same session, challenge/purpose/code; replacement JWT and one-time recovery codes |
| `POST /auth/two-step/verify` | Public but requires browser-bound challenge and code/recovery proof after SSO |
| `POST /auth/security-events` | Disabled signed Central Auth event receiver, not a customer API |

Codes, tokens and recovery strings must never be placed in URLs, analytics,
support screenshots, application logs or notification payloads. Configure edge
logging to exclude bodies/Authorization/cookies and protect auth pages with
no-store/no-referrer/frame protections. Verify real SMTP and same-origin cookie
behavior during a separately authorized pilot; local tests are not live proof.

## Password-change notification integration: partially complete

The portal cannot observe a shared password reset by itself, and must not infer
one from a new sign-in or general identity-version change. A narrowly scoped
signed event receiver is implemented and tested here. **Central Auth has not
been modified to publish it. Password-change notices are not end-to-end live.**

Proposed publisher contract (requires separate integration/release):

```json
{
  "id": "unique-stable-security-event-id",
  "type": "password_changed",
  "subject": "central-identity-uuid",
  "application": "sms",
  "occurred_at": "2026-10-30T12:00:00Z"
}
```

`X-IEOSUIA-Timestamp` is a ten-digit current Unix timestamp.
`X-IEOSUIA-Signature` is lower-case hex HMAC-SHA256 of
`sms-security-v1|<timestamp>|<exact JSON body>` using a dedicated service secret.
No passwords, password hashes, OTPs, recipient mailboxes, IPs or raw identity
profiles belong in this payload. Match the immutable identity UUID, never email.
Request signatures permit at most five minutes skew; events at most 30 days old.
The publisher retries the same immutable event ID/body with a fresh signed
request timestamp. Changed body/owner under the same ID conflicts. Each new valid
password event invalidates SMS Portal sessions once and becomes a safe notice.

The future Central Auth publisher must use a transactional event outbox on
committed password changes, signed delivery with bounded retries, privacy-safe
logging and owned-user/app routing. It must not delay or roll back password
changes while waiting for the portal, or leak events for unrelated identities.

## Deployment and rollback gates

1. Capture the current release, protect unrelated local work and retain rollback.
   Curate only this feature set; private builds include other pending email
   source and must not be uploaded wholesale.
2. Rehearse migration 023 against a restored disposable production schema. Run
   only the approved migration; no generic migration runner or new public
   migration link is authorized now. Migration 013 is the existing notification
   dedup prerequisite; 021 is the existing transfer prerequisite.
3. Configure a dedicated random `SMS_LOGIN_CODE_SECRET` of at least 32 bytes
   outside Git. Confirm transactional SMTP with an owner-approved recipient and
   ensure debug/body logs cannot disclose codes. Email campaign flags stay off.
4. After schema/code verification, set `SMS_LOGIN_SECURITY_REQUIRED=true` so
   missing security tables cannot silently remove enforcement. Then explicitly
   approve `SMS_LOGIN_ENROLLMENT_ENABLED=true` for a pilot. Turning enrollment
   off blocks new enrollment, **not enforcement for opted-in customers**.
5. Prove real SSO customer/admin callbacks, code delivery, cookie path/SameSite,
   replay/lockouts, recovery, session refresh and all existing SMS/finance paths
   before customer-wide release. Inspect WAF behavior without broad bypasses.
6. Configure `CENTRAL_SECURITY_EVENTS_SECRET` and enable its separate flag only
   after a separately reviewed Central Auth publisher is deployed and tested.
   Receiver readiness alone is not end-to-end password-change notification.
7. Define challenge expiry cleanup and a reviewed security-event retention policy
   before release. Never delete recovery state, dedup/audit records or active
   security settings simply to remove an error. Rotating the code secret
   invalidates outstanding codes/recovery digests; plan a controlled replacement
   and user recovery, not an unannounced live key swap.

Rollback may disable new enrollment and hide UI additions, but **must retain
security enforcement, verification routes and recovery for enrolled accounts**.
Do not roll Auth back to a pre-enforcement version or drop migration 023 tables
after anyone opts in. Preserve revocation epochs and notification dedup history.

## Local checks

See the matching dated verification note. All fixtures use synthetic records,
local HTTP/MySQL or intercepted browser APIs. No live mailbox, Central Auth,
AWS service or customer financial data was used by these checks.
