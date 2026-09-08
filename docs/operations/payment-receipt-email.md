# Payment receipt email

Migration `007_payment_receipt_outbox` adds one durable receipt job per completed payment. The PayOS callback queues the job in the same database transaction as wallet crediting, attempts it immediately, and records `sent` or a safely truncated failure. SMTP failure cannot roll back or duplicate the wallet credit.

Run the worker every five minutes:

```text
Minute: */5
Hour: *
Day: *
Month: *
Weekday: *
Command: /usr/local/bin/php /home/ejetffbz/public_html/sms/api/bin/payment-receipt-worker.php
```

Failed jobs retry after five minutes, stale processing claims recover after ten minutes, and a job stops automatically after eight attempts. An administrator can retry a completed payment through `POST /api/admin/operations/payments/{id}/retry-receipt`.

The active **system** SMTP setting supplies the sender address. Set its From Email to the approved IEOSUIA SMS billing sender (for example `billion@ieosuia.com`) and use the SMTP test action before processing a real payment.
