# Production acceptance test

Run these tests with one normal customer, one second customer, and one administrator. Use only the approved SMS test number and a controlled email inbox.

## 1. Authentication and isolation

1. Sign in as Customer A and confirm the dashboard loads without 401/403/500 responses.
2. Sign in as the administrator through `/guymhan/login`; confirm it remains on `/guymhan` routes.
3. Open `/guymhan` as a normal customer. Expected: access denied/admin login, never customer data.
4. Copy a Customer A contact/campaign ID, sign in as Customer B, and request that record. Expected: 404/403 and no data.
5. Leave the administrator inactive for more than five minutes. Expected: admin session expires and returns to `/guymhan/login`.

## 2. Credits and campaign reduction

1. Open SMS Credits. Expected: balance is shown as SMS credits, with `1 credit = 1 segment` and minimum purchase R10.
2. Create a group containing at least three controlled contacts.
3. Compose a one-segment SMS whose recipient requirement exceeds available credits.
4. Expected: preview shows credits required/available and blocks Continue.
5. Remove one number. Expected: requirement recalculates; the contact remains in Contacts and in its group.
6. Use **Fit campaign to available credits**. Expected: only this send list is reduced; saved contacts/groups remain unchanged.
7. Repeat using a Unicode or 161-character GSM message. Expected: credits per recipient reflect the server-calculated segment count.

## 3. PayOS—release-critical

1. Record the current credit count and make one R10 PayOS purchase.
2. On return, expected: page says Processing until the signed callback arrives; it must not claim success from the redirect alone.
3. Expected after callback: payment history is Completed and available credits increase by `floor(10 / 0.35) = 28`; the remaining R0.20 stays internally available toward future usage.
4. Expected: one confirmation email arrives at the registered address and states payment amount, SMS credits, and reference.
5. Ask PayOS to replay the identical successful callback. Expected: credits and ledger increase zero additional times.
6. Test pending, cancelled, failed, bad-signature, wrong-reference, wrong-currency, and wrong-amount fixtures. Expected: no credit for any of them.
7. Confirm a delayed pending/failed callback after success does not downgrade the Completed payment.

## 4. SMS lifecycle

1. Buy/retain enough credits and queue one controlled SMS to the approved number.
2. Expected: one reservation, one provider submission, and one LogicSMS message reference.
3. Expected: worker, scheduler, and DLR poller remain healthy at `/api/ready`.
4. Expected after receipt: recipient becomes Delivered or Failed automatically; campaign totals and reports match.
5. Rerun the worker while the first run overlaps. Expected: no duplicate SMS and no duplicate ledger debit.
6. Cancel a scheduled campaign before dispatch. Expected: no SMS and reserved credits released once.

## 5. Reports, email scope, and mobile

1. Open Reports for 24h, 7d, 30d, and 90d. Expected: canonical SMS totals, charts, statuses, and credits agree with campaign details.
2. Export the campaign report. Expected: CSV opens safely and contains SMS credits plus ZAR accounting amount.
3. Open Email Campaigns and its old new/detail URLs. Expected: Coming Soon; no creation or sending controls.
4. At 320px, 375px, 768px, and desktop widths, test dashboard, contacts, campaign preview, SMS Credits, reports, and `/guymhan` navigation. Expected: no clipped actions or horizontal page overflow.
5. During all tests, keep DevTools Console and Network open. Record every 4xx/5xx, uncaught exception, and failed request.

Publication acceptance requires every Expected result above, exactly one wallet credit for the PayOS payment, exactly one SMS provider submission, and no cross-tenant data exposure.

