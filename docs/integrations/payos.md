# sms.ieosuia.com PayOS Integration

## Overview

This document captures the PayOS-side setup for the sms.ieosuia.com tenant. The SMS platform remains a tenant client of PayOS and uses the hosted checkout flow:

sms.ieosuia.com -> PayOS -> PayFast -> PayOS -> sms.ieosuia.com callback

The tenant project is responsible for creating a local pending wallet transaction, calling PayOS to create a hosted checkout, receiving the signed PayOS callback, and crediting wallet balances exactly once.

## Tenant record

Create or update the tenant in PayOS with these values:

- Legal name: IEOSUIA SMS Portal
- Display name: IEOSUIA SMS Portal
- Slug: sms-ieosuia
- Allowed origin: https://sms.ieosuia.com
- Callback URL: https://sms.ieosuia.com/api/payments/payos/callback
- Callback status: Active
- Default currency: ZAR
- Website URL: https://sms.ieosuia.com
- Integration profile: wallet_topup
- Integration callback path: /api/payments/payos/callback
- Integration return path: /payment/success
- Integration cancel path: /payment/failed

Recommended branding values:

- Store name: IEOSUIA SMS Portal
- Support email: billing@ieosuia.com
- Support phone: Set the tenant support number used by the SMS team
- Brand color and accent color: Match the SMS portal brand palette

## Credentials to hand to the SMS project

After saving the tenant, copy these values out of the PayOS admin:

- API endpoint: https://payos.ieosuia.com/api/v1/payments/create
- Public key: generated per tenant
- API secret: generated per tenant
- Callback secret: generated per tenant

The SMS project should keep the API secret and callback secret server-side only.

## Payment create contract

Headers:

- Content-Type: application/json
- X-Payos-Key: tenant public key
- X-Payos-Timestamp: current Unix timestamp
- X-Payos-Nonce: a unique random value of at least 16 characters
- X-Payos-Signature: HMAC SHA-256 of `METHOD\nPATH\nTIMESTAMP\nNONCE\nRAW_BODY` using the tenant API secret

Recommended request body for SMS wallet top-ups:

```json
{
  "tenant_identifier": "sms-ieosuia",
  "external_order_id": "WALLET-SMS-20260518-001",
  "amount": 500.00,
  "currency": "ZAR",
  "customer_name": "Jane Doe",
  "customer_email": "jane@example.com",
  "customer_phone": "0821234567",
  "item_name": "SMS wallet top-up",
  "item_description": "Wallet top-up for prepaid SMS credits",
  "source_website": "https://sms.ieosuia.com",
  "return_url": "https://sms.ieosuia.com/payment/success",
  "cancel_url": "https://sms.ieosuia.com/payment/failed",
  "branding": {
    "store_name": "IEOSUIA SMS Portal",
    "logo_url": "https://sms.ieosuia.com/logo.png",
    "brand_color": "#0f425b"
  },
  "metadata": {
    "platform": "sms-ieosuia",
    "wallet_reference": "WALLET-SMS-20260518-001",
    "wallet_id": 42,
    "user_id": 7,
    "requested_credits": 5000,
    "checkout_source": "web"
  }
}
```

Recommended local mapping on the tenant side:

- external_order_id -> wallet_transactions.reference
- amount -> wallet_transactions.amount
- metadata.requested_credits -> wallet_transactions.requested_credits
- PayOS internal reference -> wallet_transactions.payos_reference and payments.gateway_reference

## Callback contract

PayOS posts signed JSON to:

- POST https://sms.ieosuia.com/api/payments/payos/callback

Headers:

- Content-Type: application/json
- X-Payos-Event: payment.updated
- X-Payos-Signature: HMAC SHA-256 of the raw callback body using the tenant callback secret

Minimum payload fields expected from PayOS:

- internal_reference
- external_order_id
- tenant_id
- tenant_slug
- store_name
- status
- amount
- currency
- payfast_payment_id
- paid_at
- event_timestamp
- metadata

Tenant callback receiver checklist:

1. Read the raw body.
2. Verify X-Payos-Signature with the tenant callback secret.
3. Look up the local wallet transaction by external_order_id.
4. Store the PayOS internal reference if it is new.
5. Upsert the tenant payment history record.
6. Credit the wallet only if the status becomes paid and the transaction has not already been finalized.
7. Persist the raw callback payload for audit or debugging.
8. Return a small JSON success response.

## Status mapping for wallet top-ups

- paid -> mark payments.status completed, mark wallet_transactions.status completed, add wallet credits exactly once
- pending -> keep payments.status pending and wallet_transactions.status pending
- failed -> mark payments.status failed and wallet_transactions.status failed
- cancelled -> mark payments.status cancelled and treat the wallet transaction as failed unless the tenant has a dedicated cancelled state
- refunded -> mark payments.status refunded, but do not reverse wallet credits automatically unless the tenant has a controlled reversal flow

## Recommended tenant-side schema changes

Apply only if those columns or indexes do not already exist.

```sql
ALTER TABLE wallet_transactions
  ADD COLUMN payos_reference VARCHAR(100) NULL AFTER reference,
  ADD COLUMN requested_credits INT NULL AFTER amount,
  ADD COLUMN checkout_initiated_at TIMESTAMP NULL DEFAULT NULL AFTER payos_reference,
  ADD INDEX idx_wallet_transactions_reference (reference),
  ADD INDEX idx_wallet_transactions_payos_reference (payos_reference),
  ADD INDEX idx_wallet_transactions_status (status);

ALTER TABLE payments
  ADD UNIQUE KEY uniq_payments_gateway_reference (gateway, gateway_reference),
  ADD INDEX idx_payments_transaction_id (transaction_id),
  ADD INDEX idx_payments_merchant_reference (merchant_reference),
  ADD INDEX idx_payments_user_status (user_id, status);
```

Reuse existing payments.metadata and payments.webhook_received_at fields if the tenant schema already has them.
