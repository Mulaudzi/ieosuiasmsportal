# Central administrator management release

Batch: 20261020. Owner-authorised deployment following the source implementation. Blu-T excluded.

## Completed

- Backed up the affected Auth and Invoices schemas/data and all overwritten files to private ignored local storage.
- Applied Auth `007_admin_management.sql`: managed grant marker, invitation purpose, persistent throttles and encrypted mail outbox.
- Applied Invoices `20261020_central_admin_sessions.sql`: central version and management mode on administrator sessions.
- Deployed 22 scoped backend/runtime files and four Main IEOSUIA asset/entry files; 26 remote SHA-256 readbacks passed. Existing public HTML was preserved except entry bundle references.
- Created four confidential, app-bound inspection clients, without changing the existing six sign-in clients. Enabled the Main IEOSUIA, SMS, QR and Invoices server connectors.
- Verified successful existing-administrator inspection and stale-version denial for each enabled app.
- Passed 21 native MySQL/mysqli assertions using temporary tables only. No synthetic users or grants persisted, and no actual test emails were sent.
- Preserved master credentials, existing grants, tokens, applications, original OAuth clients and Invoices administrator/session records, compared with pre-release snapshots.
- Installed one every-minute Auth mail-worker cron entry through the verified hosting account's own crontab, preserving other jobs. The panel API credential was rejected and was not changed. Verified the actual CLI worker against an empty outbox: exit 0, sent 0, failed 0.
- Removed every used HTTP runner after execution and verified HTTP 404. Removed the temporary local release script sources and credential handoff; kept private rollback snapshots, manifests and results. One local compiled Python cache, `.sms-audit.local/admin-release-20261020/__pycache__/release.cpython-314.pyc`, remains because the execution policy rejected its removal. It was never deployed. Source migration files and the required mail-worker application script are intentionally retained.

## Still requires owner acceptance

This environment's automated anonymous browser reached the hosting provider's **One moment, please...** security challenge. The release did not bypass that challenge, weaken security or authenticate as the owner. Server-side runtime checks and file readback are complete; they do not prove inbox delivery or a full signed-in user journey.

1. Sign in at `https://auth.ieosuia.com/guymhan/login?central=1` with the existing master credentials and PIN. Existing app sessions may require signing in again.
2. Open **Administrators and app roles** directly, or use Main IEOSUIA's Central Directory link.
3. Invite a controlled test administrator with access to just one app. Confirm invitation receipt in inbox/spam and successful password/PIN activation.
4. Confirm that administrator can enter only the selected app, not the other apps or the master console.
5. Remove the app role, save with master password/PIN and confirm the old app session is denied on its next protected request.
6. Test administrator password recovery separately. The existing PIN and role assignments must stay unchanged.

Main IEOSUIA offers Administrator and Viewer. SMS, QR and Invoices offer Administrator. POS and PayOS are not offered as completed central assignments. Customer identities, credit settlement and owner-only manual credit approval remain separate.

Full operational guide: `apps/auth/docs/admin-management.md` in the main `ieosuia` repository. Private release evidence: `.sms-audit.local/admin-release-20261020/` in this SMS checkout. Do not publish backups or credentials.
