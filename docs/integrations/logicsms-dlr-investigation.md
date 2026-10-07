# LogicSMS delivery lookup investigation

Live inspection on 2026-09-09 found 11 campaigns, 28 messages, and 31 charged
segments for the reported customer. Only campaign 11 is named TEST MESSAGE LENGTH;
it contains three message records, each with two segments, charged R2.10 in total.
No duplicate record was found or deleted. No messages were resent.

After deployment, read-only verification confirmed:

| Period | Messages | Charged credits | Export rows | Daily chart total |
| --- | ---: | ---: | ---: | ---: |
| 24 hours | 11 | 14 | 11 | 11 |
| 7 days | 28 | 31 | 28 | 28 |
| 30 days | 28 | 31 | 28 | 28 |
| 90 days | 28 | 31 | 28 | 28 |

Rolling periods change over time. The campaign aggregation also correctly ignored
deliberately stale in-memory input counters (999); the database was not altered by this test.

The three persisted provider IDs are 1534759293, 1534759295, and 1534759296.
Their portal references are portal-message-26, portal-message-27, and portal-message-28.
Both HTTPS hosts returned Cannot Find Message. Tests included POST/GET lookups,
provider IDs, portal references, and a minimal GET using only Username, Password,
and Unique for the first message.

Official API documentation: https://www.logicsms.net/knNew.aspx?i=1
It describes querying the returned unique ID and recommends www.logicsms.net for HTTPS.

Provider support needs to confirm:

- Which account owns those returned IDs and which Unique value resolves them.
- Whether a separate delivery-report entitlement or endpoint is required.
- How Id and IdList map to multipart delivery reports for this account.
- The webhook delivery configuration and an example actual delivery receipt.

Do not infer Delivered or Failed from an unsuccessful lookup. The records remain sent,
with delivery pending. DLR lookup failures must never trigger an SMS resend or a second charge.
