# Test matrix

| Test area | Command/evidence | Result |
|---|---|---|
| PHP syntax (changed credit/campaign files) | `php -l` | PASS |
| Phone normalization and SMS segmentation/pricing | `npm run test:php` | PASS: 14/14 |
| LogicSMS provider contract/security cases | `npm run test:php` | PASS: 19/19 |
| TypeScript | `npm run typecheck` using hydrated config | PASS before final notification-copy-only change; rerun blocked by OneDrive dependency hydration |
| Vitest | `npm test` in hydrated mirror | PASS: 30/30 |
| ESLint | `npm run lint` | pending clean hydrated run |
| Production build | Vite production build in hydrated mirror | PASS: bundle generated; packaging then failed because mirror omitted `api/` |
| PHP full-tree lint | scripted lint | PASS: all readable PHP files |
| Browser user/admin journeys | controlled browser session | pending |
| Live LogicSMS/PayOS/SMTP | approved recipients/provider callbacks | EXTERNAL VERIFICATION REQUIRED |
| Production dependency audit | `npm audit --omit=dev --audit-level=high` | FAIL: 18 findings (1 critical, 12 high, 4 moderate, 1 low); non-breaking remediation attempt hit npm/OneDrive dependency-tree failure |
