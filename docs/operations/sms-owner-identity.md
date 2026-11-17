# SMS administrator identity projection

Central Auth establishes identity and product entry. SMS still requires an active local `admin_users` entry. Its authenticated API token refers to the matching `users` row. The central Master flag, local administrator access and local API projection are distinct records; do not infer the status of one from another.

The historical `005_link_central_identities.sql` excludes internal addresses from customer linking and disables their `users` rows. It links the approved master in `admin_users` only. An administrator whose projection has not subsequently been synchronized can therefore have active central/local administrator access while its API user record remains inactive and unlinked. Do not rerun that historical migration as an activation tool.

`SmsAdminProjection::synchronize` accepts only the already verified back-channel profile from the SMS administrator OAuth flow. It requires a verified administrator identity for the SMS application and exactly one matching active local administrator. It resolves both UUID and email, rejects conflicting or ambiguous bindings, preserves the existing user ID and all business records, and links the administrator and API projection in one transaction. It never provisions a missing administrator or changes central access.

When binding or eligibility changes, `auth_version` increments so previously issued local tokens cannot revive after repair. Subsequent logins with unchanged authority retain that version. Password hashes and master-password settings are not changed. Customer login mapping is unchanged.

For owner recovery, compare the central active/verified identity and SMS grant with both local rows first. Any UUID conflict, inactive local administrator, missing projection or unexpected account change must stop automated reconciliation. A one-time repair must be bound to the reviewed account and central UUID, run after a fresh central authority check, record an audit event, and verify all financial/contact fingerprints remain unchanged. Remove temporary diagnostics and repair endpoints afterwards. Normal production login does not gain cross-database access.

After repair, sign in freshly through the SMS administrator login, then open Platform Operations > Finance > Credit approval security. The owner must set the initial master password in the UI. Ordinary administrators cannot set or rotate it; every manual bank-transfer approval still requires it. Confirmed online payments remain automatic.

Regression test: `php api/tests/run-sms-admin-projection.php`. This uses synthetic SQLite records and does not establish real owner sign-in or real payment clearance.
