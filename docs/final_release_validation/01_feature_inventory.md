# Feature inventory

Status reflects validation on 2026-09-06. `EXTERNAL` means the internal path exists but requires a controlled production-provider test.

| ID | Feature/action | Role | UI | API/controller | Data/provider | Auth | Result |
|---|---|---|---|---|---|---|---|
| AUTH-01 | Central sign-in | User | `/login` redirect | `/auth/central/*`, `IeosuiaAuthController` | central identity service/users | public callback + signed state | EXTERNAL |
| AUTH-02 | Admin sign-in and PIN | Admin | `/guymhan/login` | `/auth/central/*`, admin session endpoints | users/admin tables | central identity + admin role + PIN | PARTIAL |
| CON-01 | List/search contacts | User | `/contacts` | `GET /contacts`, `ContactController@index` | contacts | JWT/ownership | PASS (static/API review) |
| CON-02 | Create/edit/delete contact | User | contacts dialogs | `/contacts[/{id}]` | contacts, audit log | JWT/ownership | PARTIAL |
| CON-03 | Group membership | User | contacts/groups | `/contact-groups*`, bulk group route | contact_groups, group_contacts | JWT/ownership | PARTIAL |
| IMP-01 | Contact CSV import | User | contacts import | `POST /contacts/import` | contacts/groups | JWT, validation | PARTIAL |
| TMP-01 | SMS templates CRUD | User | `/templates` | `/templates*` | templates | JWT/ownership | PARTIAL |
| SMS-01 | Authoritative preview | User | `/sms-campaigns/new` | `POST /sms/preview`, `SmsController@preview` | contacts, groups, opt-outs, wallet | JWT/ownership | PASS (unit/static) |
| SMS-02 | Remove recipient for one campaign | User | preview recipient list | `POST /sms/preview` then create | request exclusion only; no contact mutation | JWT/revalidated | PASS (static) |
| SMS-03 | Create/reserve/queue | User | campaign wizard | `/sms/campaigns-v2*` | sms_campaigns, sms_messages, wallet_ledger | JWT/transaction/idempotency | PARTIAL |
| SMS-04 | Dispatch and DLR | Worker | detail/status views | cron + LogicSMS webhook | SMS tables/LogicSMS | cron secret/webhook secret | EXTERNAL |
| BILL-01 | View SMS credits | User | sidebar, `/wallet` | `GET /wallet`, `WalletController` | wallets, price config | JWT | PASS (static) |
| BILL-02 | Buy credits via PayOS | User | wallet modal | `POST /wallet/buy` | payments/PayOS | JWT + signed callback | EXTERNAL |
| BILL-03 | Idempotent payment credit | System | return/status page | PayOS callback | payments, wallets, ledger | callback signature + transaction | PARTIAL |
| EMAIL-01 | Email campaigns | User | `/email-campaigns` | email endpoints return 410 | none while disabled | JWT | PASS (Coming Soon) |
| REP-01 | Reports/export | User | `/reports` | `/reports/*` | legacy and canonical campaign data | JWT/ownership | PARTIAL |
| ADM-01 | Admin dashboard/tools | Admin | `/guymhan/*` | `/admin/*` | platform tables/providers | admin middleware | PARTIAL |
| SUP-01 | Support/contact | Public/User | `/contact`, `/support` | `POST /contact` | contact email tables/SMTP | validation/rate limit | PARTIAL |

