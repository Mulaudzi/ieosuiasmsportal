# UI action inventory

| Surface | Action | Expected request/state | Current result |
|---|---|---|---|
| Login | Continue to IEOSUIA identity | signed central-auth redirect | EXTERNAL |
| Contacts | Search/filter/paginate | scoped list refresh | PARTIAL |
| Contacts | Create/edit/delete/import/export | matching contact endpoint and visible toast | PARTIAL |
| Templates | Create/edit/delete | matching template endpoint and refresh | PARTIAL |
| SMS wizard | Select manual/contact/group recipients | local selection, no mutation | PASS |
| SMS wizard | Calculate credits | `/sms/preview`; normalized recipients and segment count | PASS |
| SMS wizard | Remove recipient | preview with `recipients.excluded`; group unchanged | PASS |
| SMS wizard | Fit to available credits | removes only tail of this send | PASS |
| SMS wizard | Queue/schedule | create then queue, or create scheduled | PARTIAL |
| Wallet | Buy credits | R10 minimum, estimated credits, PayOS/EFT | EXTERNAL |
| Email campaigns | Open navigation item | visible Coming Soon page, no mutations | PASS |
| Admin | Navigate tools/settings/health | admin-only APIs and mobile layout | PARTIAL |

Keyboard, focus order, modal focus trapping, narrow mobile widths, and destructive confirmation remain browser-test items.

