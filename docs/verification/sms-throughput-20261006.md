# SMS throughput investigation, 6 October 2026

## Production evidence (read-only)

Campaign 21, 403 recipients, created/queued 3 October 18:46:53, started 18:47:01. First submission 18:47:02, last submission 19:03:06: 16m04s. Eight 50-message worker batches took 70-71 seconds each and started about two minutes apart, followed by a three-message batch. Provider-call plus database processing averaged about 1.42 seconds per recipient, so the configured 10-per-second ceiling was not the actual throughput. Approximately 49 seconds between batches was idle wait for the next available cron invocation.

Delivery timestamps first appeared 6 October 00:00:49 through 00:12:22. These are portal confirmation-recording timestamps, not proof of when handsets received the messages. At inspection the campaign had 275 Delivered, 127 Not Delivered and one awaiting confirmation. Across the inspected period the poller logged 48,269 Cannot Find Message errors and 795 unrecognised `blocked 2026-10-03 18:57:24` responses. These aggregate error counts are system-wide, not exclusively campaign 21. Polling was running throughout; faster cron alone does not solve missing provider records.

## Local patch, not deployed

- Cron `sms-worker --once` drains multiple batches of at most 10 until a 55-second budget is reached. A batch already claimed is completed before exiting. This is a soft budget, not a forced process kill. The single-worker lock, provider rate ceiling, unique references and credit settlement remain unchanged.
- DLR selection polls first-hour messages frequently, then backs off to five minutes for older same-day records and 30 minutes for older records. First lookups are prioritised. A 50-second soft budget stops starting more queries and permits the next cron invocation to proceed; a slow in-flight query may exceed that budget.
- Successful nonterminal status lookups clear stale lookup-error labels.
- Campaign completion is recorded once, not overwritten each time delivery results arrive. Existing historical timestamps are not rewritten by this patch.

At Saturday's measured speed, removing idle gaps suggests roughly 10 minutes for 403 messages, not a proven production guarantee. Faster results beyond this need measured bounded concurrency or a provider-supported bulk interface with confirmed rate limits and per-recipient references. Do not simply start competing workers or remove the lock.

## Remaining provider work and release gates

Confirm with LogicSMS why valid `portal-message-ID` lookups returned Cannot Find Message for days, the final/nonfinal meaning of BLOCKED, account throughput limits, and delivery postback availability. Do not classify unsupported responses as delivered or failed. A minutes-level confirmation target is conditional on LogicSMS exposing the status within minutes.

Before deployment: review live worker bytes/cron configuration for drift, confirm cron runs every minute, retain rollback copies, and run controlled acceptance on consented test recipients. Measure queue-to-first-submit, first-to-last-submit, first-check age, provider latency and confirmation ingestion separately. Check for duplicate sends/charges and preserved opt-outs. No live SMS or provider status query was triggered in this investigation; the temporary token-protected SQL probes self-deleted and returned 404.

Local validation: PHP syntax passed; existing provider suite 31/31 and core suite 26/26 passed. The isolated completion-timestamp test verifies later delivered/failed outcomes do not alter dispatch completion. No production speed benchmark has been performed on the patch.
