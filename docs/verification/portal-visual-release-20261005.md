# SMS portal visual and usability release

## Scope

SMS only. Optional account-scoped browser preferences for dark mode and desktop sidebar collapse, mobile More navigation, manual page/step tutorials, SMS-only templates, five delivery labels, compact campaign delivery breakdowns, clearer campaign step validation/review, and wallet purchase-card presentation.

Sending, scheduling, payment, opt-out processing and credit calculation were not changed by this release. Skipped reporting is additive, displayed separately and excluded from the existing sent totals and delivery-rate denominator. Export rows now include skipped records with their not-sent explanation. Existing report totals and credit calculations remain unchanged.

## Verification

- TypeScript typecheck and production build passed.
- Nine delivery-label/detail cases passed.
- Eighteen frontend/backend SMS segment and credit parity cases passed.
- Isolated reporting fixture passed for 24 hours, 7 days, 30 days and 90 days, including account isolation, export parity, skipped separation and unchanged totals/credits/rate.
- Responsive browser tests passed locally and on production assets at 320, 375, 768 and 1440 pixels. Tests covered light default, remembered dark/collapsed choices, unchanged sidebar/logo, five-outcome panel, hover/click, manual tutorials, SMS-only templates, report periods, step validation and draft recovery.
- Private browser API requests were mocked. Tests did not send real SMS messages or create payments.
- Read-only production reporting probe passed eight account/period combinations. It checked summaries against actual message rows and exercised export/breakdown queries. No database writes were made; the temporary protected probe was removed and returned 404.
- Sixteen public pages and entry assets matched the release hashes. The private composer shell remained noindex. Unauthenticated /api/auth/user returned 401.

## Release

Final entry: `index-BqHRf9g3.js`.

Deployed immutable assets first, reviewed API presentation files next, public/private entry pages last. Live drift checks and upload readback verification were applied. Rollback copies remain in the local audit directory, with the pre-release baseline at `visual-backup-20261005-005816` and the final wording-pass backup at `visual-backup-20261005-012313`.

## Remaining verification limits

Vitest did not complete: worker startup stalled, and an attempted isolated dependency copy encountered OneDrive cloud-file timeouts. This is not recorded as a passing unit suite. Browser regression tests and PHP fixture tests passed separately. No fresh end-to-end provider delivery, payment or email transaction was performed for this presentation-only release.
