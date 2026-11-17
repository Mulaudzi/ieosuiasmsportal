# IEOSUIA administrator access standardisation

Implementation batch started 2026-10-20. Blu-T is explicitly excluded. This document records source changes and remaining work, not live production acceptance.

## First batch

| Product | Customer entry | Administrator entry | This batch |
| --- | --- | --- | --- |
| Main IEOSUIA | Public site | `/guymhan` through Central Auth | Existing active local admin/viewer checks retained; no source change needed |
| Central Auth | `/login` | `/guymhan` / `/guymhan/login` | Existing master-only console retained; no identity or app grant changes |
| SMS | `/login` through Central Auth | `/guymhan` | Existing admin guard, verified identity projection and owner-only credit security retained |
| Invoices | `/login` through Central Auth | `/guymhan` | Existing separate local administrator session retained |
| QR | `/login` through Central Auth | `/guymhan` / `/guymhan/login` | Added entry aliases to the existing verified admin flow; retained `/admin/*`, callback paths and tokens |
| PayOS | `/login` through Central Auth | `/guymhan` | Existing entry retained; detailed console role policy is still a separate unresolved stage |
| POS | `/signin` | `/guymhan` / `/guymhan/login` | Added separate entry plus server-persisted customer/platform session contexts; existing platform permissions retained |

Shared sign-in branding is not an access grant. Central identity, per-app eligibility, the local active role, the session's purpose and the requested operation are distinct checks. A business owner is not a platform administrator. A central master is not silently assigned a local app role.

## Changed repositories

- POS: dedicated administrator entry, session-context migration 007, backend enforcement, navigation and regression tests. See `docs/ADMIN_ENTRY_POINTS.md` in that repository for migration order and acceptance.
- QR: new Guymhan aliases, protected-route sign-in destination, return-target loop protection, private crawl headers and tests. Existing `/admin/*` pages and the registered callback URI remain intact, so no Central Auth redirect registration is changed.
- SMS: this implementation record only. No customer, credit, contact, delivery, payment or permission behaviour changed by this batch.
- Main IEOSUIA/Auth/PayOS and Invoices: inspected only. Their files, schemas, users and grants were not modified.
- Blu-T: no implementation, deployment, database or account operations.

Pre-existing ecosystem/FOS visual work in these worktrees is unrelated and must not be bundled into this access release.

## Local verification, 2026-10-20

- POS: 133 Vitest tests, 35 authentication-context service checks, 182 repository-isolation checks and 53 built-UI browser checks passed. Browser checks covered 320, 375, 768 and 1440 pixel widths with mocked anonymous sessions; the service checks used SQLite in memory.
- QR: all 45 Vitest tests passed, including the new administrator-route and return-target cases.
- SMS: the unchanged identity-projection suite passed 61 checks and the unchanged manual-credit security suite passed 40 checks, both with isolated SQLite fixtures.
- POS and QR TypeScript checks and Vite production builds passed. Changed PHP files passed syntax checks. Builds were written to ignored audit directories, not uploaded or treated as scoped release packages.
- POS Guymhan was visually inspected on mobile. Existing branding and page layout were retained.

These are local/source results. They do not establish MySQL migration acceptance, production cookie behaviour, Apache rewrite acceptance, Central Auth integration for POS, deployment or live account access.

## Not completed by this batch

1. **POS Central Auth migration:** requires a reviewed client registration, callback contract, explicit existing-identity binding and staged login/logout/recovery acceptance. Existing POS accounts continue using POS credentials. Do not provision or bind by email alone.
2. **PayOS console capabilities:** the source audit found that general authenticated web middleware is not a complete finance/support/tenant authorisation policy. Define and enforce operation-level and tenant-level access, including negative tests for tenant viewers, before declaring PayOS role isolation complete. Existing tenant API credentials do not establish web-console isolation.
3. **Cross-app capability mapping:** preserve current role strings and map operations explicitly. Do not equate two products' roles just because the names match, or translate a central app access label into local super-admin permission.
4. **Real MFA and step-up:** requires enrollment, recovery, emergency access and privileged-operation testing. A password and static PIN are both knowledge factors; a Guymhan route is not MFA. No MFA completion claim is made.
5. **Release acceptance:** POS MySQL migration and real browser-cookie checks, coordinated scoped deployment, exact production readback and signed-in acceptance remain pending. No production release or mutation was performed in this batch.

Continue one stage at a time, preserve existing identity UUID bindings and revocation rules, and test denial as well as successful access. Keep normal online payment settlement automatic and preserve SMS owner-only manual credit security.

## Follow-up: central administrator invitations and app roles

The subsequent source-only batch adds master-managed invitations, administrator password recovery and independent role selection for Main IEOSUIA, SMS, QR and Invoices. It does not offer POS or PayOS assignments as completed integrations, does not change master identities and does not touch Blu-T. The first-batch statements above describe that earlier snapshot, not the later implementation.

The operational guide is `apps/auth/docs/admin-management.md` in the main `ieosuia` repository. It includes migrations, mail-worker setup, confidential inspection clients, per-app connector configuration and signed-in acceptance gates. All new connectors default to disabled. No production migration, account creation, password reset, invitation email, role change or deployment has been performed by this follow-up.

SMS now has a guarded managed-admin adapter. With its connector enabled and verified central assignment, it can create a separate bound administrator projection. Existing customer email/UUID collisions, inactive administrators and mismatched bindings are rejected. It does not convert customers, create wallets, issue credits or bypass the existing owner-only manual credit approval security. Central role changes invalidate managed sessions on their next protected request; customer sessions remain separate.

The later owner-authorised release applied the Auth and Invoices migrations and deployed the scoped Central Auth, Main IEOSUIA, SMS, QR and Invoices changes. All four connectors are now enabled with app-bound confidential inspection clients. Native temporary-table tests, real back-channel access checks, stale-version denial and 26 remote file hashes passed. The mail worker is scheduled and its empty-queue CLI check passed. Temporary HTTP runners were removed and verified as 404; local release helpers were cleaned up, while private rollback evidence was retained. Real signed-in invitation/email and role-removal acceptance remains pending because this environment's automated browser encountered the host's security challenge. POS/PayOS completion and Blu-T remain outside this release.
