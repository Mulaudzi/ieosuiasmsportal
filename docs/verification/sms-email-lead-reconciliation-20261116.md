# SMS / Email lead evidence reconciliation — 16 November 2026

Scope: local documentation corrections, retained-evidence mapping and isolated lifecycle checks for Project Manager's existing SMS / Email tasks. This does not certify the whole portal or reopen production, provider, financial or credential operations. The implementing lead is the authenticated chat `01a182a2-1eb9-7fb3-90c5-18c8d3f96fb5`; independent reviewer disposition is pending.

## Completed local outcomes

| Existing task | Outcome and evidence |
| --- | --- |
| `f2b87fc1-1e67-4e54-b8a6-60b7575279bd` | README now describes the actual custom PHP router, JWT/central authentication, database workers, Vite port and real route examples. Obsolete Laravel/Artisan/Sanctum setup and default demo credentials were removed. Its public homepage rendering section was preserved. |
| `cb063a96-faac-4a37-bd90-4db45e2d3ff1` | Mapped original reservation/charge journal IDs and message IDs to the three released retry holds and the separately labelled legacy correction. Retained the frozen approved report seed's hash/scope and the missing original workbook limitation. These September/legacy holds are not attributed to an unrelated October report. |
| `345a1592-16fa-426b-a247-fe028cc7e58c` | Fresh isolated fixtures passed for cancellation/replay, no-resend retry handling, later delivery without rebilling and email reservation accounting. These are SQLite proofs; no claim of new MySQL concurrency or real provider acceptance. |
| `6c674d2c-214e-4eee-b12c-89e8644dddc0` | All six unresolved provider cases mapped to original portal Unique references and provider message IDs in the already sent support request. The five INVALID query results and one SENT query result remain unresolved; their timestamps have unverified semantics/timezone. |
| `b34cb254-e178-41f1-b4a8-56dc5e65662e` | A [bounded customer pilot acceptance plan](../operations/customer-pilot-acceptance-plan-20261116.md) specifies sender/recipient, quote and proposed cost caps, isolated invocation, cancellation, delivery, reconciliation and pause conditions. Plan preparation is complete; execution is not. |

The [R6.16 reservation correction](reservation-release-20261116.md) was already completed and separately read back before this review. This work did not repeat it, modify old charges or replace original receipts. That repair's 45 MariaDB and 512 regression checks retain their own scope and evidence.

## Fresh checks

All four selected PHP suites passed, totaling 269 checks:

- 67 real integration/campaign/wallet checks, including cancellation and idempotent replay, in isolated SQLite.
- 16 no-resend/crash/timeout/schedule checks plus 13 small/bulk scheduling and exact-once claim checks.
- 130 reconciliation checks, including later delivery outcomes without rebilling and strict legacy-journal validation.
- 43 AWS email financial/signature fixtures in SQLite, with no live AWS or wallet access.

README local links and nine documented main-router paths were checked against repository files/source. Original README bytes, before/after hashes, selected source hashes, results and private case/journal maps are retained in `.sms-audit.local/lead-review-20261116/`. A one-line evidence-inspection command initially failed PowerShell quoting before running; it was replaced by a literal Python script. No acceptance failure was suppressed and no production operation occurred.

## Remaining gates and current evidence limits

| Outcome | Latest retained evidence and next owner |
| --- | --- |
| LogicSMS callback activation and final six outcomes | 16 November support request was SMTP accepted once. The monitoring receipt still requires provider clarification. Await the provider response through the monitored support inbox; do not resend messages or infer success. |
| SES / capacity / pilot | Last retained cloud snapshot is 13 November: SES production access healthy, 50,000/day provider quota, existing concurrency request CASE_OPENED, campaign sending off and schedule disabled. This is historical, not a fresh cloud read. Engineering needs current read-only quota/runtime evidence and actual isolation acceptance before the reviewed pilot can execute. |
| Meta approval/publication | Retained reviewer instructions and real owner-asset test evidence establish preparation and the bounded demonstration. They do not establish current App Review, Advanced Access or publication state. Obtain the current Meta decision/dashboard before changing customer access. |
| Controlled scheduled SMS acceptance | The earlier scheduling repair and isolated checks are retained. Any new real send requires the approved recipient, schedule and exact scope; completed campaign #65 must not be reused. |
| Independent acceptance / release | Jabulani's independent review and Lufuno's release disposition remain separate PM outcomes. No implementing-chat self-attribution as independent verifier. |

Existing portal fixes and the financial repair are implemented, tested and released only within their recorded scopes. New local documentation has not been deployed. No AWS/Meta settings, financial records, provider state, customer messages or shared authentication source were changed by this reconciliation.

## Coordination decisions

One SMS / Email lead owns these local changes. The legacy SMS chat contributed only the private scheduling acceptance card and evidence on existing blocked task `819f6a70-4858-4b39-8c0e-2dad05c34189`; file/task ownership was agreed before edits. The lead reviewed revision 2, SHA-256 `4b1864bd9784e28b2adaf7095676b3db170a112d5e710e172799965bf782df3d`, checked all six historical receipt fingerprints and accepted preparation only. The original draft remains retained. A hash-pinned review attempt correctly stopped while draft metadata was still changing; the accepted frozen revision has a separate review receipt. This is lead integration, not independent acceptance or permission to run the test.

The reviewed card explicitly identifies that the shared SMS worker's `--once` flag bounds a run rather than isolating a particular campaign or one transport attempt. It therefore requires a separately reviewed isolated route, current full runnable-queue inventory, consent, payer/quote/time approval, before/after wallet journals and a provider evidence route. Its proposed future time is unbooked and expires without approval. The canonical task stays blocked; no shared worker or scheduler was invoked.

Existing shared task IDs and versions are reused. A useful supervisor improvement is to separate evidence/plan completion from the dependent execution and independent acceptance, and to label provider snapshots with their observation date rather than reopening already completed repairs or treating old snapshots as current.
