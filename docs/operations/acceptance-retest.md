# Acceptance retest

The customer's payment and SMS tests confirm payment completion, credit allocation,
single-segment and multi-segment charging, recipient exclusions, and basic mobile use.

## Patched defects

- Campaign comparison now serializes numeric amounts/counts as numbers and safely formats monetary values.
- Campaign CSV uses the shared authenticated download helper, attaches its link to the document,
  and allows the browser time to consume the download before releasing its URL.
- Recipient recalculation clears the previous preview; failed recalculation returns to the message step.
- Receipt retries include stale processing jobs. The receipt worker records its runs and heartbeat.

## Remaining acceptance checks

1. Compare two campaigns and confirm the costs and recipient counts render.
2. Download campaign CSV on desktop and the phone used for the failed test.
3. Use a campaign costing more than the available credits. Confirm Continue is unavailable,
   reduce recipients, and confirm recalculated costs. Check excluded contacts remain saved.
4. Select 24 hours, 7 days, 30 days and 90 days in Dashboard and Reports and compare the exports.
5. Confirm the payment receipt worker runs in cPanel, review its operational results,
   then retry the missing receipt from administrator payment operations.

For cPanel, the receipt worker command is:

```text
/usr/local/bin/php /home/ejetffbz/public_html/sms/api/bin/payment-receipt-worker.php
```

Run every minute. An existing identical entry should not be duplicated.

## Unresolved external delivery evidence

The live poller checked 23 outstanding messages and received `Cannot Find Message`
for all 23 during this investigation. No delivered result was available to apply.
LogicSMS account/reference lookup and webhook configuration require resolution before
delivery reporting can pass acceptance. Do not mark these messages Delivered on the basis
of submission success or send the campaigns again to repair reporting.

The available production error log contains SMTP authentication failures dated September 3.
These historical errors do not establish the cause of the latest missing billing email.
Receipt-worker results and current SMTP verification are still required.
