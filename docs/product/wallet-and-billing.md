# Wallet and billing

Wallet mutations use row locks and an idempotent `wallet_ledger`. Campaign funds follow `reserve -> settle accepted message -> release rejected/skipped remainder`. Available balance is `balance - reserved`.

`SMS_PRICE_PER_SEGMENT=0.35` is the single authoritative SMS price. Wallet balances and campaign charges are ZAR amounts inclusive of VAT. One segment costs R0.35, so a two-segment message costs R0.70 per recipient. The legacy SMS per-credit setting is not used.

A GSM-7 message uses one segment through 160 characters and concatenated segments of 153 characters. Unicode uses one segment through 70 UTF-16 units and concatenated segments of 67 units. Campaign preview shows the detected encoding, per-message segments, total segments, and total ZAR charge before funds are reserved.

Final-DLR refunds are not enabled because the commercial/provider policy is unconfirmed. Do not promise delivery-failure refunds until that policy is approved and implemented.
