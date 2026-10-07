# Campaign report email and shared exports

## Release scope

SMS report downloads now use `SmsReportDocument` for CSV, Excel and PDF. CSV/Excel have exactly Campaign, Number, Status, Segments, Sent, Details, with one row per recipient. PDF uses Number, Status, Sent, Details and paginates. Campaign downloads apply the same state and recipient search filters as the on-screen list. Period exports use the selected reporting period. Existing dispatch states, delivery calculations and credit charging are unchanged.

Report Total counts recipient records including Skipped. This is intentionally different from Total Sent, which continues to exclude skipped messages. Unsent queue states retain truthful operational labels rather than being described as successfully sent.

Email samples mask numbers and contain at most five rows. PDF/CSV attachments contain full recipient data, so reports go only to the registered account email. Download links point to authenticated portal pages, never publicly accessible recipient files. If signed out, sign in and reopen the link.

The current PDF renderer uses Helvetica with Windows-1252 encoding. Campaign names outside that character set are transliterated; CSV and email retain UTF-8. Full multilingual PDF fidelity requires an embedded Unicode font and remains a limitation of this renderer.

## Activation required, not performed by local implementation

Deployment update, 5 October 2026: the report/export backend, email attachment support and matching portal changes are deployed. Entry asset: `index-CeE49J54.js`. FTPS readback and drift checks passed; rollback files are in `.sms-audit.local/report-backup-20261005-032310`. A temporary protected production probe passed 12 read-only checks across periods, status filters, recipient search, CSV/PDF generation and grouped counts. It confirmed automatic emailing is disabled and was removed (HTTP 404). Production-asset browser checks passed at 320/375/768/1440px with private APIs mocked. Public entry, private noindex shell and unauthenticated 401 checks passed. No SMS or email was sent. Migration 012 and cron activation remain outstanding, as do actual Gmail/Outlook receipt checks.

Local verification on 5 October 2026 passed TypeScript checking, the Vite production build, PHP syntax checks, report formatter tests, isolated SQLite/outbox tests with a fake SMTP transport, delivery-time formatting boundaries, and responsive Chrome checks at 320/375/768/1440px. Email browser previews passed at 320/375/800px. Portal browser tests mock private APIs. These results do not verify production MySQL, real SMTP delivery, Gmail/Outlook rendering or live activation.

1. Back up the production database and reviewed server files.
2. Apply `api/migrations/012_campaign_report_email.sql` once. Its stored activation time excludes campaigns completed before activation. Rerunning it does not reset that time.
3. Deploy the reviewed report classes/controllers, EmailService attachment extension, new CLI worker and matching frontend assets. The existing public `/branding/ieosuia-sms-portal-navy.png` must remain available.
4. Configure `CAMPAIGN_REPORT_EMAIL_ENABLED=1` in the server environment. It is off by default.
5. Schedule `php /absolute/path/to/sms/api/bin/campaign-report-worker.php` every minute using the hosting account's actual PHP binary/path. Capture stderr and monitor its exit status. Do not replace the existing SMS dispatch or payment workers.
6. Finish one controlled campaign, verify exactly one email, both attachments, masked sample, registered recipient and authenticated report links. Rerun the worker and verify no second email.
7. Confirm PDF pagination and the rendered email in actual Gmail and Outlook clients. Browser rendering is not independent email-client acceptance.

## Duplicate prevention and recovery

The outbox primary key is the campaign ID. Atomic claim/update predicates prevent competing workers from sending the same pending job. Finished or manually retried campaigns keep the same outbox record. Preparing jobs that expire before SMTP are recoverable. Sending jobs with an unknown SMTP result move to review, never automatically back to pending.

SMTP cannot provide transactional exactly-once delivery together with this database. If status is review, inspect mail-server logs first. Do not reset to pending merely because an email has not appeared in an inbox: the recipient's server may already have accepted it. No automated resend is provided for ambiguous delivery.

Read-only operational check:

```sql
SELECT campaign_id, status, created_at, locked_at, sent_at, last_error
FROM campaign_report_outbox
ORDER BY created_at DESC;
```

Setting the environment flag to 0 stops new report deliveries without changing SMS sending. Preserve outbox records on rollback to retain duplicate protection.

## Verification boundary

Local fixture tests use an in-memory database and fake mail transport. They do not send email, queue SMS messages or modify production data. MySQL migration execution, cron activation, real mail delivery and Gmail/Outlook acceptance remain deployment checks.
