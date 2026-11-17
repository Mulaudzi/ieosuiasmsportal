# Manual credit approval security

Direct FNB transfer orders are reviewed in **Credit payments** in the admin sidebar, or **Platform Operations > Finance** (`/guymhan?tab=finance`). Search by customer, email or reference and select **View proof** to read an uploaded PDF, JPG or PNG inside the portal. Downloading is optional through **Download copy**. Check the actual cleared bank transaction, then select **Review payment**. Enter the exact amount received, the bank's unique transaction ID and a review note, confirm cleared funds, enter the master password, and select **Approve and add credits**. Proof alone is not clearance.

Every manual approval requires the separate master password, including approvals by the owner. Rejection requires a reason but no master password because it issues no credits. Online payments remain automatic through the existing verified provider callbacks; this feature cannot manually override those payments.

## Owner setup and rotation

Only the authenticated `users` record with admin role, active status, verified email and exact email `lufuno@ieosuia.com` can manage the password. The browser cannot grant itself owner status. No new role is automatically assigned to this account.

In Finance, open **Credit approval security > Set master password**. Choose a unique passphrase of at least 14 characters and at most 72 UTF-8 bytes. There is no default password and manual approval stays blocked until setup is complete. Never put the password in source, deployment scripts, tickets or chat. Only its password hash is stored.

Changing it requires the current master password and matching new values. The owner cannot retrieve the current password. There is intentionally no passwordless reset or ordinary-admin recovery endpoint; a lost master password needs a separately authorized owner recovery procedure.

Five incorrect attempts lock that administrator out of password verification for 15 minutes. Attempts persist in the database and serialize under a row lock. Rotation increments the security version. A payment transaction must recheck and hold that version lock, so an old verification cannot approve after rotation.

## Payments and audit

Finance includes paginated, searchable payment history across all customers, gateways and payment statuses. It distinguishes money paid, credits ordered and credits actually added. Direct transfers can be opened in the review section. Credited/rejected transfer history shows reviewer, review time, note and the bank transaction reference. Private bank references are not added to customer responses.

Financial changes and approval audit events commit together. Transaction locks and unique ledger/bank references prevent duplicate credits. Security events are retained in `credit_approval_events` without passwords or hashes. A repeated successful approval does not add credits or an approval event again.

## Release requirements

This change adds migration `015_credit_approval_security.php`: three security tables and a singleton unconfigured settings row. It does not rewrite customers, payments or wallet balances. Apply only this migration in the established scoped deployment workflow; do not blindly rerun historical migrations. Its repeat execution preserves an existing password.

Publish the new domain and security controller before the modified review controller and API routes, then the frontend. Confirm authentication boundaries, migration state and owner access. Initial setup must be performed by the owner in the UI, not by a release runner. Remove any temporary deployment/migration endpoint after verification. Preserve versioned migration definitions and audit/rollback evidence.

Before setup or when the migration is missing, manual approval fails closed; online callbacks and customer purchases continue using their existing flow. Do not roll back to the old unguarded review endpoint as a way of recovering a lost password.

Local verification commands:

- `php api/tests/run-credit-approval-security.php`
- `php api/tests/run-credit-purchases.php`
- `node scripts/test-credit-purchase-http.mjs`
- `php api/tests/run-credit-approval-concurrency.php` against the deliberately isolated localhost MySQL fixture on port 33149 only
- `npm run test -- --maxWorkers=1 --testTimeout=30000 src/components/wallet/BuyCreditsModal.test.tsx`
- `npm run typecheck` and `npm --ignore-scripts run build`

These checks use synthetic local records. They do not establish production migration, owner-account configuration, bank clearance or real payment acceptance.
