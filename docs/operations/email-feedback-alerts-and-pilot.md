# Email feedback, operational alerts and first customer pilot

The active operational contact is `sms@ieosuia.com`, using the confirmed `ieosuia-email-operations-v1` SNS subscription. Alert messages contain infrastructure names and bounded counts, not recipient lists, message content, codes, credentials or wallet details. A separate encrypted SQS receipt queue retains operational alerts for 14 days. This queue is an audit path; the operator must also monitor the email inbox.

## Active monitoring

The dedicated monitoring stack routes 23 alarms to the operational topic:

- Feedback and file-inspection dead letters; delayed feedback older than five minutes for two evaluations.
- Execution errors, throttles and application-reported operational failures for the API, worker, feedback processor, file scanner and read-only probe.
- Pilot bounce, complaint and provider-rejection events; blocked uploads.
- A separate setup-test canary used to verify the complete alarm route.

During the initial pilot, one bounce/complaint/rejection event is sufficient to notify the operator. These are conservative pilot thresholds, not unique-recipient billing counters or provider reputation-rate calculations. Duplicated provider events or metric extraction can duplicate event counts; financial effects remain idempotent.

Valid AWS mailbox-simulator events publish under the `feedback-simulator` component. They still exercise the real provider, queue, handler, suppression and suspension code, but do not appear as genuine customer-complaint counts. Invalid processing still increments the real failure metric. Customer destinations resembling the simulator domain are not exempted: the domain must match exactly.

Counters use CloudWatch embedded metrics with fixed component dimensions. No additional metric-write permissions were added to the campaign roles. Logs remain retained for 14 days. Standard monitoring, custom-metric, log, SNS and queue charges apply; there is no provisioned monitoring server or new always-on instance.

## Read-only probe

Every five minutes, the probe reads at most 51 active references and 50 campaign metadata records. It flags held campaigns, missing/legacy references, monitoring overflow and jobs older than 30 minutes that still need completion or have reserved funds. This age check is an attention signal: inspect quotas and cancellation state before deciding whether a job is broken.

The probe cannot send email, read portal secrets, call accounting or change campaign/wallet state. Its role permits only the required active/campaign reads and its own log writes. The monitoring schedule is distinct from the campaign worker schedule, which remains disabled during preparation.

## Responding to an alert

1. Distinguish the clearly marked setup canary or documented simulator check from a customer event.
2. Inspect the named function/queue and the protected administrator review workspace. Keep message bodies and recipient data out of notifications and chat.
3. For a bounce or complaint, verify correlation and the saved suppression/sender-suspension state. Do not override opt-outs or clear suppression to resend.
4. For blocked files, inspect private quarantine tags and owned file metadata. Never distribute blocked bytes or manufacture a clean scan result.
5. For held or accepted-pending recipients, reconcile the durable attempt and provider reference. Retrying accounting is separate from sending. Never resend because an HTTP acknowledgement or debit is uncertain.
6. Preserve unknown retained failures. Remove only an identified, resolved queue item after keeping appropriate evidence. Do not purge a queue to clear an alarm.
7. If customer sending must stop, disable the portal send flag, runtime dispatch and campaign schedule, and pause the dedicated SES configuration. Feedback and accounting settlement should remain able to reconcile work already accepted.

The original informational DLQ alarms remain preserved. The monitored alarms are the named resources of `ieosuia-email-monitoring-v1`, each with the dedicated operational SNS action.

## Feedback readiness and gates

`FeedbackAccepted=true` records acceptance of real delivery/bounce/complaint processing, suppression, suspension and public preference checks. It does not enable dispatch, reserved capacity, the campaign worker schedule, the portal send flag or SES sending. Reserved concurrency and the genuine customer billing pilot remain separate launch requirements.

## Preparing the chosen customer

Only the explicitly chosen customer is allowlisted for preparation; ordinary customers remain excluded. The account is active and email-verified. It can prepare sender details, verify another reply inbox, choose templates, upload bounded files and submit for review. Business identity must be entered and independently approved; preparation does not fabricate an identity or grant sender approval.

The first proposed delivery has exactly one consenting recipient and an initial sender allowance of one attempt/day. The separate cancellation check must run with the campaign worker schedule off and must release its unissued reservation without a debit or email. Before a manual worker invocation, inspect the complete pending queue and exclude unrelated jobs.

After AWS capacity/isolation passes, obtain explicit authorization for the bounded real send and wallet test, recheck the current quote, run the cancellation check, run one delivery, and verify exact acceptance, debit, released reserve, recipient receipt and reply routing. Pause again before deciding to widen the pilot. The 10,000/day target has not been load-tested.

Private account-specific draft, cost preview, test limits, receipts and encrypted environment rollback are in `.sms-audit.local/email-operations-pilot-20261113/`. The account password was not changed. No customer message, reservation or debit was created during preparation.
