# SMS credit transfers

Local implementation; not deployed or enabled by this change. No live migration, wallet mutation, verification email, SMS or payment was performed.

## Account permissions

- Customers: Billing & Credits → Transfer credits. The source is always the authenticated customer. Enter the recipient's exact verified SMS Portal account email, enter whole credits, review their name/email and the credit count, request a code to the sender's own verified email, and confirm. No customer directory or recipient wallet balance is exposed.
- Admins: Operations → Finance → Transfer SMS credits. Search active, verified customer accounts; choose both accounts, document the source owner's authorization, review, then enter the existing finance master password. Admin credentials cannot impersonate a customer code flow. Disabled/unverified accounts and admin recipient/source wallets are excluded.
- Own history is visible to both sides without exposing another customer's account ID, email, balance, contacts or the admin's authorization reason. Admins have full transfer audit history.

No transfer fee. Transfers move existing credits; they are not purchases, refunds or newly issued credits. Completed transfers are not automatically reversible. Any corrective transfer needs fresh authorization and is recorded separately.

## Financial invariants

Existing wallets store ZAR value at the canonical `SMS_PRICE_PER_SEGMENT` rate. A transfer pins that rate and moves exactly `credits × rate` on both sides. Discounted purchase history is unchanged. Integer ten-thousandth-rand arithmetic is used; rates must be representable in wallet cents. Only whole counts of 1–1,000,000 credits are supported, subject to actual unreserved funds and the supported wallet ceiling. No FX, rounding away credit value, or cash-ledger movement.

The operation locks the verification guard, owned quote, accounts in numeric order, and both wallets in a deterministic order. It rechecks account authorization using the locking-read rows, identities, pricing, quote expiry, wallet ownership, exact balances and reservations. A matched debit/credit `wallet_ledger` pair, both wallet updates, the transfer record and success audit commit together. Any error rolls the full financial operation back. Reservation, purchase, payment, customer cash and SMS data are not changed. Missing, duplicate or inconsistent wallets fail closed; opening an account's normal wallet page initializes a missing wallet through the existing flow.

The public reference is `TRF-` plus the complete 32-character transfer identifier. History uses pinned credit counts even if the credit rate changes later. Finance totals exclude transfer-in from credits issued and transfer-out from SMS value consumed; actual cash received is unaffected.

## Verification and retry rules

- Quote review: 10 minutes, no funds reserved or moved. Stable UUID request identity, unique per actor; changed intent with the same UUID is rejected.
- Customer code: random six-digit code, stored as a password hash, bound to the quote, recipient and amount. Only the verified source email receives it. One code request per review; at most three requests per account per 15 minutes (database-backed, including uncertain/failed email submissions). A code expires no later than its review. Five wrong codes in 15 minutes block customer confirmation for 15 minutes across reviews. Successful confirmation removes the code hash.
- The code email is transactional through existing system SMTP, **not** the pending bulk-email feature, SES campaign configuration or migration 019. SMTP debug output is suppressed for this email. If SMTP fails, no funds move. A delivery-uncertain code email is not automatically regenerated; inspect the inbox, then use a fresh review after expiry if necessary.
- Admin password checks retain the existing persisted attempt/lockout and password-rotation protections. Passwords and codes are not stored in transfer metadata or returned by transfer APIs.
- API search/quote/commit/challenge throttles add request-level protection; database-backed code limits remain authoritative for customer verification.
- Before confirmation, the UI saves only the owned quote ID in session storage. If storage is unavailable it refuses to submit. Unknown/5xx submission outcomes retain that ID and block a new review. Reopening checks status with GET only; retry uses the **same** quote ID. Completed retries return the original receipt, never another debit. A new review after an uncertain request is allowed only when its locked status check establishes it expired without completion. Losing browser storage or switching devices requires checking transfer history/support before attempting another transfer; do not infer failure from a timeout.

## API

All routes require existing bearer authentication and fresh account checks. Controllers set `Cache-Control: private, no-store`. Standard portal success/error envelopes are preserved. Validation/authorization errors use 422/403, private missing reviews 404, stale/insufficient states 409, throttles 429, disabled/unavailable services 503. Unknown errors expose no exception details or credentials.

| Route | Permission / request |
| --- | --- |
| GET `/admin/operations/credit-transfers?page=1` | Verified active admin; paginated audit history plus finance-security configuration |
| GET `/admin/operations/credit-transfers/accounts?search=...` | Admin only, at least two characters, at most 15 eligible-account results |
| POST `/admin/operations/credit-transfers/quote` | `source_user_id`, `destination_user_id`, `credits`, authorization `reason` (10–500 UTF-8 bytes, no controls), `authorization_confirmed: true`, UUID `request_id` |
| POST `/admin/operations/credit-transfers/commit` | Owned `quote_id`, `master_password`, `confirmed: true`; no financial overrides |
| GET `/admin/operations/credit-transfers/{quote_id}` | Admin may inspect only their own review and receipt; no sending |
| GET `/wallet/credit-transfers?page=1` | Authenticated user's own sent/received history; 20 per page |
| POST `/wallet/credit-transfers/quote` | Verified customer; `recipient_email`, `credits`, `authorization_confirmed: true`, UUID `request_id`; optional reason. Supplied source IDs, wallet IDs, prices, actor IDs and recipient IDs are rejected |
| POST `/wallet/credit-transfers/challenge` | Owned customer `quote_id`; request transactional verification email only |
| POST `/wallet/credit-transfers/commit` | Owned customer `quote_id`, `verification_code`, `confirmed: true` |
| GET `/wallet/credit-transfers/{quote_id}` | Source customer may inspect only their own review/receipt |

## Release and rollback

1. Explicit deployment authorization is still required. Capture live baseline and scoped rollback package. Preserve the dirty worktree and exclude private audits, credentials and unrelated email work. Do not deploy the entire current root build.
2. Inspect canonical users/wallet/ledger and security prerequisites. Apply only additive migration `021_credit_transfers`, after prerequisite `015_credit_approval_security` if absent, using the controlled migration process. Do not blindly run all pending migrations: email 019 remains excluded. Migration 021 creates `credit_transfer_quotes`, `credit_transfers`, `credit_transfer_security` and their indexes; no historical balance changes or automatic schema setup occur in web requests. MySQL requires InnoDB prerequisites and exact decimal wallet columns; duplicates fail the preflight.
3. Deploy the scoped controller/service/router/read-model/finance/UI additions and transactional code-email method. Keep `CREDIT_TRANSFERS_ENABLED=false` until schema, backend and UI are verified together.
4. Verify the system SMTP path with explicit authority for a controlled transactional test; no actual verification delivery was tested locally. Confirm local Central Auth projections are active and email-verified. Configure the existing admin master password if necessary. Check monitored errors, then explicitly activate `CREDIT_TRANSFERS_ENABLED=true`.
5. Controlled acceptance: authorized sender/recipient, small approved credit count, verify both wallet values/reservations, both ledger rows, exactly one transfer/audit, both histories, unchanged payment/cash totals, and replay once against the same ID. No such production acceptance has been run.
6. Rollback starts by disabling the flag; preserve read-only transfer/status history and all completed records. Do not drop tables, restore old wallet snapshots or erase audit/ledger entries. Avoid restoring a pre-feature finance read model after real transfers, because that version would classify transfers as adjustments/issuance/consumption. Roll forward the presentation fix if necessary. Reversals require a separately authorized compensating transfer.

Verification commands and fixture limitations: `docs/verification/credit-transfers-20261029.md`.

## Dedicated migration command

Use `api/bin/setup-credit-transfers.php`, not the general `api/bin/migrate.php` (which would also run unrelated pending migrations). This is CLI-only; opening it as a web link returns 404.

Upload these exact files in binary mode, preserving their paths: `api/bin/setup-credit-transfers.php`, `api/domain/CreditTransferSetup.php`, `api/migrations/021_credit_transfers.php`, `api/migrations/015_credit_approval_security.php`. The maintenance class pins the two migration files' SHA-256 bytes; changed/missing files fail closed. It uses the server's existing `api/.env`, not credentials supplied as command arguments. Do not copy local environment files over the server configuration.

From the portal project root in cPanel Terminal or SSH, inspect first:

```sh
php api/bin/setup-credit-transfers.php
```

Review `problems`, `ready_to_apply`, prerequisite permission and any partial-setup indication. Verify a recoverable database backup before applying. Only then:

```sh
php api/bin/setup-credit-transfers.php --apply --confirm=021_credit_transfers --backup-confirmed
```

If `security_prerequisite_permission_required` is true, explicitly append `--include-security-prerequisite`. This permits only missing prerequisite 015, never any other migration. If inspection reports compatible but unrecorded partial tables, review why the previous attempt stopped before explicitly appending `--resume-partial`. Structural mismatches, missing recorded tables, duplicate wallets or missing idempotency uniqueness are blockers, not automatically repaired.

The runner acquires a scoped advisory lock, applies allowlisted DDL only, verifies column types/nullability/collations, primary/unique/lookup indexes and foreign keys, then appends missing migration-history markers. Existing finance password hashes and history markers are preserved. MariaDB DDL is not transactionally reversible; after any failure, inspect before a further attempt. No wallet balances, reservations, customer records, SMS, emails, payments, application files or activation flags are modified by this runner. Schema setup alone does not deploy or enable credit transfers.

## Browser-only cPanel setup

For hosting without Terminal, `scripts/prepare-credit-transfers-setup.ps1` creates a private 24-hour capability URL with an external JavaScript client (compatible with `script-src 'self'`). The generated PHP embeds the inspected maintenance class, so no public reusable maintenance endpoint or application router change is needed. Preparation writes the private link only inside the ignored audit folder.

`scripts/publish-credit-transfers-setup.ps1 -Mode Publish` uploads exactly two allowlisted canonical migrations and two randomly named temporary PHP/JS files using the existing verified FTPS transport. It refuses mismatching existing files, byte-verifies publication and preserves guarded application/config/worker/email hashes. It never calls Apply. Default `-Mode Inspect` checks completion receipts or makes an authenticated GET only.

Opening the full private URL inspects. Apply remains disabled until preflight succeeds and the owner confirms a recoverable backup; missing prerequisite 015 requires a separate checkbox. Partial setups are not auto-resumed by this link. POST requires the hashed private capability, same production Origin and exact JSON confirmation. Before DDL, an exclusive receipt claim consumes the one apply attempt. Uncertain/failed results require inspection, not automatic retries. The receipt is a PHP-protected file under the existing protected config directory.

After the owner returns successful JSON, use `-Mode Inspect`, then `-Mode Cleanup`. Cleanup archives the receipt and removes only the two exact temporary files and their verified receipt; canonical migrations/schema remain. `-Mode CancelUnused` can remove an unused link only if no apply receipt exists. Do not remove an uncertain attempt without investigating its schema first. Expiration denies all execution even if temporary files have not yet been removed.
