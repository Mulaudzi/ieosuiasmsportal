# Bounded customer email acceptance plan — 16 November 2026

This is a reviewable plan, not authorization to send, reserve funds, debit a wallet, change AWS resources or widen customer access. It supplements [the existing pilot contract](email-feedback-alerts-and-pilot.md). The earlier direct delivery test's authorization was consumed; it does not cover this pilot.

## Preconditions and decisions

Engineering is assigned to prosper@ieosuia.com, independent review to jabulani@ieosuia.com and release disposition to lufuno@ieosuia.com. Assignment is not evidence of review, approval or credential access. The implementing chat records its own work under its authenticated identity.

Before execution, record all of the following in one scoped acceptance request:

| Gate | Required evidence / decision |
| --- | --- |
| AWS isolation | Fresh read-only quota/request status and the actual dedicated allocations: API 2, worker 1, feedback 2. Reuse the existing concurrency request; do not submit a duplicate. |
| Current runtime | Fresh sending, pause, feedback and schedule settings, plus monitored queue/DLQ state. Keep the general campaign schedule disabled. Do not infer current values from the 13 November snapshot. |
| Sender | Actual customer business identity and independent sender approval, verified From domain, and an account-bound verified Reply-To inbox. Customer preparation access is not sender approval. |
| Recipient and content | Exactly one explicitly consenting recipient; recorded address, approved subject/body/template, attachment choice and evidence of consent. Recommend no attachment for this first billing test. |
| Financial scope | Owner authorizes one unissued cancellation reservation and one one-recipient billed delivery using the explicitly named customer's wallet. Obtain a fresh quote before either reservation. |
| Cost proposal | The prior estimate was R0.07 per recipient. Proposed portal cap: R0.07 reserved for cancellation and fully released before a separate R0.07 delivery reservation; at most one R0.07 delivery debit. Stop for a new decision if the current quote exceeds that estimate. AWS/provider charges are separate and must be acknowledged in the request. |
| Queue isolation | Inspect all ready/active jobs immediately before invocation; no unrelated campaign may be processed. If a single scoped invocation cannot be guaranteed, stop and isolate it before requesting execution. |
| Review and release | Reviewer accepts the plan and evidence; owner explicitly approves the exact actions, recipient, cost and invocation bounds. No general enablement follows automatically. |

The preferred decision is this one-recipient, no-attachment pilot after capacity isolation and review. Alternatives are to keep preparation-only access while those inputs are missing, or revise the plan with a new reviewed budget and scope. There is no need to repeat the earlier direct email delivery test or WhatsApp test.

## Acceptance sequence after the gates pass

1. Capture account, wallet, current quote and queue baselines privately. Assign unique request/campaign references. Ensure feedback can settle already accepted work while general dispatch is paused.
2. Queue only the separately authorized cancellation case while the worker schedule is off. Verify its exact reservation, cancel before any worker invocation, and verify no debit, no provider attempt and complete release. Repeating cancellation must not release twice or change unrelated funds.
3. Queue only the authorized one-recipient delivery case. Review sender, recipient, opt-in, Reply-To and quote. Perform one scoped worker invocation only after confirming the complete runnable queue contains no unrelated work.
4. Correlate the durable attempt, SES acceptance reference, wallet settlement and delivery feedback. Require one debit, zero remaining reservation and no duplicate send/debit after a repeated read or idempotent request. Acceptance by SES is separate from receipt in the human inbox.
5. Obtain the consenting recipient's receipt confirmation and verify reply routing to the approved Reply-To inbox. Retain suppression, preference, content and sender-review controls throughout.
6. Pause again. Capture final queue, gate, wallet and audit evidence. The reviewer and release owner decide whether the exact pilot passed and whether a later widening is warranted. Throughput at 10,000/day remains unmeasured.

If acceptance, accounting or transport is uncertain, hold the attempt and reconcile its original reference. Never resend to repair an uncertain HTTP response or debit. Keep feedback processing available; do not purge queues, clear suppressions or label unknown delivery as success.

## Completion boundary

Preparation of this plan is complete. Execution, real customer billing acceptance, independent review and broader release are separate, still gated outcomes. No financial operation, provider message or AWS setting change was performed to prepare this document.
