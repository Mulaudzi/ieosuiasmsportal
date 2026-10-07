# Completed PayOS callback timestamp repair

Duplicate successful callbacks no longer assign `processed_at`. The payment upsert
continues recording `webhook_received_at` and `updated_at`; financial crediting is unchanged.

Payment 3 / PAYOS-IEOSUIAS-20260906-24602 had original processed_at
`2026-09-06 13:10:04`, verified before replay. The September 10 replays changed it
to `2026-09-10 14:01:59`. The scoped repair restores the original value only if
the ID, both references, completed status, amount and known timestamp match.
No wallet or credit ledger fields are repaired or modified.

Regression verification: replay the existing successful event twice through PayOS.
After each callback, processed_at must remain `2026-09-06 13:10:04`, while
webhook_received_at advances. Payment 3 must still have one R10 credit ledger entry,
one corresponding wallet transaction, and no extra balance increase.
