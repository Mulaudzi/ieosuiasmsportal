# Excel contact import

Supported: `.xlsx` and comma-delimited `.csv`; 5 MB/file, 10,000 contacts/selected sheet.
Older `.xls`, macro-enabled and password-protected workbooks must be saved as ordinary `.xlsx` first.

1. Row 1 contains unique headings: Name, Surname, Phone, Email. Only Phone is required.
2. Format phone cells as Text before entering/pasting numbers. Include the local leading zero or international country code. Previously lost zeros cannot be recovered by formatting alone.
3. Use one contact per row. Remove titles, merged cells, totals, formulas and unused columns.
4. Upload the file and select the contact worksheet. Only that sheet is processed.
5. Map Phone and any optional columns. Select a pre-existing group if required.
6. Review the first ten rows and total row count; leave duplicate skipping enabled.
7. Confirm import and read the imported, duplicate-skipped and failed totals.

Excel parsing runs in a bundled same-origin Web Worker with a 15-second timeout.
The selected worksheet is serialized to CSV and uses the existing authenticated
contact-import API. No workbook macros/formulas are executed. Existing group
ownership checks and server-side phone normalization/duplicate handling remain authoritative.

Checks: `node scripts/test-contact-import-file.mjs`,
`node scripts/test-contact-import-result.mjs`, TypeScript and production build.
The dependency audit still flags two moderate advisories in the existing React Router
dependency family; this change does not attempt a major-version router upgrade.
