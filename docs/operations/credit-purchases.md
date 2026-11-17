# Volume credit purchases and direct FNB transfers

The minimum purchase is 30 credits. The entire purchase uses the rate for its quantity range. `api/domain/credit-purchase-pricing.json` is shared by PHP and React. All money calculations use integer cents. Customer prices have no VAT added. Supplier VAT remains an operating cost and is unrelated to wallet credit allocation.

Direct transfers save half the estimated normal Instant EFT processing fee: `floor(max(200 cents, round(subtotal_cents * 2%)) / 2)`. The standard payment total is the basis, not the discounted amount. The customer sees only a bank transfer saving. Examples:

| Credits | Standard | Transfer saving | Transfer due |
| ---: | ---: | ---: | ---: |
| 30 | R10.50 | R1.00 | R9.50 |
| 100 | R35.00 | R1.00 | R34.00 |
| 500 | R165.00 | R1.65 | R163.35 |
| 1,000 | R310.00 | R3.10 | R306.90 |

The 501 to 1,000 band is R0.31 per credit under pricing version
`2026-10-21-volume-3` and remains unchanged in `2026-10-29-volume-4`.
The 250,001 to 500,000 band is R0.22 per credit.
Online PayOS card/Instant EFT checkout is available through 500,000 credits,
with no added online service fee. Direct bank transfers retain their discount.
More than 500,000 credits cannot be purchased through self-service; direct bank
deposit is required and customers must contact sales at `sms@ieosuia.com` for a
quote before transferring funds. These limits are checked
by PHP as well as the frontend. New quotes, public price
tables, purchase forms and calculators read the shared rules. Existing orders,
receipts and payment amounts retain their stored original quote. No database
migration or wallet repricing is needed for this rate update.

Price breaks can reduce the total when quantity increases. Checkout offers the first eligible larger purchase that costs no more than the entered quantity using the selected payment method. The discount is applied only to direct FNB transfers. Online card/Instant EFT remains through PayOS for up to 500,000 credits inclusive, even when direct transfers are unavailable. Only the direct-deposit/sales notice above 500,000 is shown inside the purchase form; the 50,000-credit restriction no longer applies. Customers explicitly choose the payment method; an unavailable selected EFT option is never silently converted to an online order.

## Banking source

`api/config/bank-transfer.json` reuses the IEOSUIA account from the main site's banking-document configuration: IEOSUIA (PTY) LTD, First National Bank (FNB), GOLD BUSINESS ACCOUNT, account 63184542520, branch 255355, SWIFT FIRNZAJJ. Provenance: the adjacent Blu-T repository's `scripts/check-ieosuia-operations-production.py` and its successful `2026-10-01` postflight banking comparisons, which checked the isolated IEOSUIA settings database. The public `ieosuia.com` page returned a browser verification screen during this implementation. The account holder, account number and branch code were explicitly confirmed current by the owner on 19 October 2026 before live activation.

Legacy `EFT_*` values are not automatically used: the old local SMS configuration contains a placeholder account. `SMS_BANK_TRANSFER_ENABLED=false` hides direct transfers. To use replacement environment values, explicitly set `SMS_BANK_TRANSFER_USE_ENV=true` and fill all four `EFT_*` fields with verified FNB details. Bank details are snapshotted on each order, so later changes do not silently rewrite existing instructions. Proof-of-payment correspondence goes to `sms@ieosuia.com`.

The public `/banking-details` page is linked from Pricing, public footers, mobile
navigation, Support and Contact. Its unauthenticated, read-only
`GET /api/public/banking-details` endpoint exposes only the active bank fields and
payment-contact instructions, without orders or customer data. It uses the same
configuration and kill switch as checkout, with no-store responses. Static public
HTML includes reference instructions but not frozen bank account numbers; live
visitors fetch the current configuration. The preferred reference remains the
unique `SMS-...` order reference, with registered account email only as the bank
format fallback. Bulk enquiries above 500,000 credits go to `sms@ieosuia.com`.

## Purchase and verification workflow

1. Customer enters a whole credit quantity and sees the range price and total.
2. Before checkout, they can choose direct FNB transfer and see the exact saving, amount due and the same credit quantity.
3. A persisted order and pending payment are created together. Repeating the same request key returns the same purchase. Instructions are saved in Wallet and queued for email to the customer and SMS inbox.
4. Customer uses the 20-character `SMS-...` order reference. If the bank cannot accept it, they can use their account email and include the order reference in their proof. Email fallback is reconciled manually, not automatically matched to arbitrary orders.
5. Customer uploads a PDF/JPG/PNG up to 5 MB or emails the proof. Up to three distinct documents are retained per order. Duplicate uploads do not duplicate documents or email jobs. Uploaded documents are private database blobs, accessible only to the owner or an authenticated administrator, with attachment/no-store/nosniff headers.
6. In Platform Operations > Finance > Bank transfer verification, staff check cleared funds in the actual FNB account. They enter the exact amount received, the bank's unique transaction ID and a review note, then explicitly confirm clearance. Payment proof alone never releases credits. A bank transaction ID can fund only one order.
7. Approval locks the purchase/payment/wallet records, records the ledger entry once and completes the payment atomically. Receipts and the existing purchase alert use the purchased credit count and actual money received. Repeated approval cannot add credits twice. Rejection adds no credits and queues the reason for the customer.

The transfer option does not initiate an FNB transfer or read bank transactions. Customers select immediate transfer in their bank app; the bank may charge a fee. Verification timing depends on actual clearance and staff review. Do not promise instant credits. Orders ask for payment within 72 hours; old orders are retained for reconciliation and show an instruction to contact the SMS inbox before paying. Already-paid orders can still accept proof and be verified against their stored quote. Online checkout creation expires after 72 hours.

### Voiding unpaid EFT requests

In Finance > Bank transfer verification, an administrator can select **Void unpaid
request** on an awaiting-transfer or awaiting-verification EFT order. A confirmation
dialog requires a reason and an explicit affirmation that the bank account was
checked and no funds were received. Do not void uncertain, partial or received
payments: reconcile those separately. This is not a refund or credit reversal.

The existing transaction/payment/order lock order serializes voiding with approval.
Only a pending, uncredited, consistently linked EFT payment and pending wallet
transaction with no payment-ledger entries or recorded bank reference may be voided.
The order becomes `voided`, the payment `cancelled`, and the pending wallet
transaction `failed`. Wallet balances, reserved balances and ledger entries are
untouched. The request leaves the pending queue but remains in the Voided/All
filters and customer Wallet, together with its reference, proof, reviewer, reason
and timestamp. A security audit event and customer closure notice are recorded;
repeated void requests do not duplicate either. The customer is told not to pay
against the closed reference and to contact sms@ieosuia.com if already paid.

Voided orders cannot receive new proof or be approved/settled later. Already
credited requests cannot be voided. There is no automatic expiry or deletion.
No schema migration is needed: order status is already VARCHAR(30), while the
existing payment/transaction status values remain supported. Existing approval
master-password controls are unchanged; voiding grants no credits.

## Accounting and old payments

The existing wallet/queue uses a configured internal accounting value per segment, usually R0.35. Leave `SMS_PRICE_PER_SEGMENT` unchanged. A purchase of 1,000 credits adds `1,000 * current internal segment value`, irrespective of whether the customer paid R310 online or R306.90 by transfer. Actual cash remains in `payments.amount`; stored quantity and conversion value remain on the order. Campaign reservations and charging continue to consume one credit per billable segment. This avoids repricing old balances or altering existing campaign reservations.

Payments initiated before this feature, which have no purchase-order row, retain their old conversion and residual balance. For example, a legacy R10 callback still adds R10 of wallet value and reports 28 credits at R0.35. New receipts use `payments.credits_added`; they must not divide discounted money by R0.35. Pending transaction displays read the ordered quantity without claiming it has already been added.

## Release and acceptance

- Back up the payment/wallet tables. Rehearse `014_credit_purchase_orders.php` against an isolated MySQL-compatible database, including concurrent duplicate approvals. It creates three purchase tables and ensures the payment receipt outbox exists; it does not rewrite customer balances or historical payments.
- Apply migration 014 before publishing the purchase UI. All three purchase tables and the receipt outbox must exist. Checkout returns a maintenance response if the purchase tables are absent. The receipt outbox payment foreign key must match the existing unsigned INT payment ID.
- Deploy the new controllers/domains/config JSON, callback/receipt/read-model changes and `CreditPurchaseMailer.php`. Include both JSON files; frontend builds alone are insufficient.
- Update `api/bin/payment-receipt-worker.php` and ensure a dedicated cron runs it at least once per minute. Preserve every existing cron entry and use an absolute PHP CLI path and a non-overlapping lock. Bank instructions, proof alerts and rejection notices are queued durably, retry up to eight times and do not hold checkout open waiting for SMTP. Check for unsent jobs after deployment. Customer approval receipts and the existing SMS purchase alerts use their existing outboxes.
- PHP needs upload limits above 5 MB (`upload_max_filesize=6M`, `post_max_size=8M`) and a database packet limit comfortably above the maximum document size. `api/.user.ini` supplies CGI/FPM values; `api/.htaccess` supplies equivalent LiteSpeed/LSAPI values because this host does not apply the former to web uploads. Confirm with an actual 5 MB request, not file readback alone. `PaymentProofFile` validates PDF signatures/end markers and actual PNG/JPEG image headers without requiring the optional fileinfo extension. Downloads are private attachments with nosniff headers. Proof documents contain financial information; include them in the existing controlled backup/access/retention process.
- Test a 30-credit online purchase and a bank transfer, a larger discounted purchase, an upload, an emailed proof, a rejection and repeated approval. Confirm that bank cash, payment total, actual purchased quantity, wallet balance, receipt and notification all agree.
- Replay both a legacy PayOS callback and a new quantity-based callback. Neither may issue credits again. No real payment/provider callbacks or external emails were executed by the local test suites.
- If reverting the new checkout, retain the new settlement code until all orders created under these prices have been reconciled. Old amount-only settlement would under-credit those orders. Preserve the three new tables and stored bank documents.

Local verification: `php api/tests/run-credit-purchases.php`, `node scripts/test-credit-purchase-http.mjs`, `npm run test -- src/lib/credit-purchases.test.ts src/components/wallet/BuyCreditsModal.test.tsx src/pages/Landing.test.tsx`, `npm run typecheck`, `npm run build`. SQLite fixtures exercise business logic and HTTP upload/download handling; they do not establish MariaDB locking, live SMTP, FNB clearance or PayOS acceptance.
