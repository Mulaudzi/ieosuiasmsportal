# Temporary contact-tag database setup

This owner-requested setup applies only `020_contact_tags.php` and its missing
private-draft prerequisite `016_work_drafts.php`. It never invokes
`api/bin/migrate.php`, which would apply every pending migration and could include
email migration 019. It deploys no portal UI, email controllers, workers or flags.

## Preparation and proof

- Source: `scripts/contact-tags-setup/runner.php.in` and `client.js`.
- Prepare: `powershell.exe -NoProfile -ExecutionPolicy Bypass -File
  scripts/prepare-contact-tags-setup.ps1`.
- Immutable preparation artifacts, the private capability URL, manifests and
  publication/cleanup receipts are ignored under
  `.sms-audit.local/contact-tags-setup-20261029-v2/`. Do not commit or publish this
  folder. Do not put the raw capability into query strings, logs or screenshots.
- The generated PHP is linted. The approved migration SHA-256 is pinned inside
  the runner; changed bytes cannot run.
- `scripts/test-contact-tags-setup.mjs`: 25 local access/expiry/origin checks and
  mocked browser checks, including initial read-only inspection, confirmation,
  one POST, cancellation, reload, CSP compatibility and mobile layout.
- `api/tests/run-contact-tags-mysql.php`: 18 checks passed on a dedicated,
  disposable loopback MariaDB 10.4.32 fixture (port 33160), including applying
  both allowlisted migrations twice, metadata verification, UTF-8 labels, bulk assignment, account isolation,
  filtered private drafts, foreign-key cascade and historical contact preservation.
  The exact generated test database was removed by the test; no production config
  was loaded. This was a synthetic compatible schema, not a restored live snapshot.
- Existing SQLite contact-tags fixture: 47 checks passed.

## Publication and browser application

`scripts/publish-contact-tags-setup.ps1 -Mode Publish` uses certificate-verified
FTPS, reads credentials privately from the existing local deployment access file,
checks path allowlists/collisions, stages under the existing denied config folder,
verifies hashes, then renames and verifies final bytes. It publishes only:

1. The canonical migrations `api/migrations/016_work_drafts.php` and
   `api/migrations/020_contact_tags.php`.
2. One randomly named temporary PHP endpoint.
3. Its randomly named, same-origin JavaScript file (no inline scripts).

Existing API/router/controller/worker/database-config and frontend entry bytes,
plus any existing email migration/worker, are guarded against changes. The
publication receipt explicitly records that no database migration was executed.

Give the owner the generated private URL. The token is random 256-bit capability
material; only its SHA-256 is in the public PHP. JavaScript reads it from the URL
fragment into per-tab session storage, removes the fragment from the address bar,
and sends the token in a header. Responses are no-store/noindex with a strict CSP
and no-referrer policy. The capability expires 24 hours after preparation.

Opening the link performs only an authenticated GET schema inspection. Missing
prerequisites, incompatible existing tables, wrong identifiers, missing keys or
bad collation block application. The owner must explicitly click Apply and
confirm. POST additionally requires the exact portal origin and JSON migration
confirmation. A private, exclusively created receipt claims the single apply
permission **before** any DDL. No unauthenticated GET or page refresh can apply.

The apply operation takes a database advisory lock, bounds metadata-lock waits,
applies only the two pinned, allowlisted migrations, verifies tables/indexes/foreign
keys, and adds only their migration-history records. Existing saved drafts are
never rewritten. It leaves email history unchanged. DDL
cannot be rolled back atomically on MariaDB; interrupted attempts are held for
inspection, not automatically retried. A completed receipt makes later refreshes
return the same result without reapplying. The UI does not claim setup succeeded
until verification and receipt persistence finish.

## Inspect and cleanup

`-Mode Inspect` reads the private server receipt over FTPS, if present; otherwise
it sends only a read-only GET. Hosting bot protection can require the owner's
browser to open the private link. Do not disable the protection or substitute a
query-string key to get around it.

After the owner supplies successful JSON, run:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/publish-contact-tags-setup.ps1 -Mode Inspect
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/publish-contact-tags-setup.ps1 -Mode Cleanup
```

Cleanup requires a completed, valid receipt. It archives that receipt locally,
deletes only the exact hash-matched temporary PHP, JavaScript and private state
files, verifies their absence, and records protected-file hashes. Keep the
canonical migration, recorded schema version and all customer classifications.
Do not delete tag tables as cleanup or rollback. An incomplete attempt or changed
file must be investigated before deletion rather than forcing this command.

The first inspection-only link found the live `work_drafts` table missing. Its
two unused temporary web files were hash-verified and removed; no apply permission
had been consumed and no migration ran. Its original artifacts and cancellation
receipt remain in `.sms-audit.local/contact-tags-setup-20261029/`. The replacement
explicitly declares the prerequisite in the page, confirmation and JSON allowlist.
If the history says an absent table was previously migrated, preflight blocks
recreation and requires investigation instead of assuming it contains no data.

This setup does not activate the customer-facing tag UI. A later SMS/contact-only
release still needs to be curated from the dirty working tree, excluding pending
email changes, and deployed only with appropriate release authorization.

## Publication status — 29 October 2026

The replacement tool and its JavaScript, plus the two canonical migration files,
were published and exact-byte verified. Nine protected live files remained
unchanged. Read-only production inspection at **15:08:32 UTC / 17:08:32 SAST**
returned `ready_to_apply: true`, no problems, both migration-history records
absent, all four new tables absent, and email migration absent. No migration has
been executed. The capability expires **30 October 2026 at 15:05:08 UTC /
17:05:08 SAST**. Application and cleanup are awaiting the owner's browser action
and successful JSON; the protected publication and inspection receipts remain in
the v2 private audit directory.

## Completion and cleanup — 29 October 2026

The owner applied the setup in their browser at **15:09:56 UTC / 17:09:56 SAST**.
The server's saved receipt was independently read over FTPS and matched the
owner's `CONTACT_TAGS_READY` result: migrations 016 and 020 recorded; all four
tables verified; no other migration, email, SMS, payment or customer-record
changes made by the tool. The missing private-draft table was created.

At **15:11:08 UTC / 17:11:08 SAST**, cleanup removed the exact hash-matched
temporary PHP endpoint, its JavaScript and the private server receipt. Their
remote absence was verified; nine protected live files remained unchanged.
The completion receipt was archived locally as `runtime-receipt.json` and the
deletion proof as `cleanup.json` in the v2 private audit directory. Local source
and candidates remain available for recovery; the old capability no longer has
an endpoint. Both canonical migrations, their history records and the new
database tables are preserved. No portal UI or email release was deployed.
