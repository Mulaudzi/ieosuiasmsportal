# External configuration required

| Variable | Purpose/source | Launch blocking | Validation |
|---|---|---:|---|
| `DB_*` | Production MySQL administrator | Yes | Migration and `/api/up` DB check |
| `JWT_SECRET` | Generated secret manager value | Yes | At least 32 random characters; login test |
| `SMS_PRICE_PER_SEGMENT` | Approved commercial price | Yes | Preview and reservation tests |
| `PAYOS_*` | PayOS merchant portal | Yes for purchases | Signed sandbox callback |
| `SMTP_*` | Mail provider | Yes for verification | Verification email smoke test |
| `GOOGLE_CLIENT_*` | Google Cloud console | No if OAuth hidden | OAuth test account |
| `LOGICSMS_*` | LogicSMS portal, callback configuration and host CA store | Yes for live SMS | Controlled send, callback authentication/replay, DLR and balance reconciliation |
| `EFT_*` | Finance team | Only if EFT displayed | Finance review |
