# Final release matrix

| Gate | Status | Publication requirement |
|---|---|---|
| Credits/insufficient-recipient UX | PASS WITH EXTERNAL VERIFICATION REQUIRED | browser confirmation |
| Email Coming Soon enforcement | PASS WITH EXTERNAL VERIFICATION REQUIRED | deployed HTTP 410 smoke test |
| Auth and role isolation | FAIL | live user/admin/cross-tenant negative suite |
| Contacts/templates | FAIL | API and browser CRUD/import/export suite |
| SMS internal pipeline | FAIL | DB-backed create/reserve/queue/dispatch tests |
| LogicSMS delivery lifecycle | PASS WITH EXTERNAL VERIFICATION REQUIRED | controlled live send + signed DLR evidence |
| PayOS payment lifecycle | FAIL | successful callback credits exactly once and UI refreshes |
| SMTP transactional email | PASS WITH EXTERNAL VERIFICATION REQUIRED | controlled verification/payment receipt tests |
| Email campaigns | EXCLUDED FROM CURRENT SCOPE | Coming Soon enforced in UI/API |
| Reports | PASS WITH EXTERNAL VERIFICATION REQUIRED | production data reconciliation |
| Background jobs | PASS WITH EXTERNAL VERIFICATION REQUIRED | healthy production heartbeats; overlap/retry proof remains |
| Security/dependencies | PASS WITH EXTERNAL VERIFICATION REQUIRED | no critical/high findings; 2 moderate SPA-mitigated React Router findings accepted pending v7 migration |
| Build/deployment | PASS | patched build deployed; `/`, `/api/up`, `/api/ready`, and SPA route return HTTP 200 |

Interim verdict: **NOT READY**. The open P1 gates are real release blockers, not documentation-only issues.
