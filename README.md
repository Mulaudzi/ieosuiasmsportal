# IEOSUIA SMS Portal

IEOSUIA Messaging is a React/Vite customer portal with a custom PHP API and a MySQL-backed messaging ledger. The PHP application uses its own router, authentication helpers and database workers. It does not use Laravel, Artisan or Sanctum.

## Application structure

```text
api/
  index.php             Main HTTP entry point and route registrations
  core/                 Router, request/response, JWT and authentication helpers
  config/database.php   Private environment loading and PDO connection
  controllers/          Customer and administrator request handlers
  domain/               Campaign, wallet, reconciliation and policy services
  providers/            Messaging provider adapters
  services/             Transport, read models and operational services
  routes/               Additional route registration, including SMS integration
  migrations/           Numbered PHP schema migrations
  bin/                  CLI workers and maintenance tools
  cron/                 Scheduled campaign processing
  tests/                Isolated PHP fixtures and guarded integration suites
src/                    React pages, components, hooks and client API
ops/                    Worker and cron configuration examples
docs/operations/        Setup, security and operational contracts
docs/verification/      Dated, bounded verification and release evidence
dist/                   Generated frontend output
```

Apache-compatible hosting uses `api/.htaccess` to rewrite API paths to `api/index.php`. `api/core/Router.php` removes the `/api` prefix, matches the registered method/path, runs middleware and loads the selected controller. Some integrations have separate PHP entry points; inspect their source and operational contract before changing their deployment.

## Capabilities and acceptance boundaries

- SMS campaigns, scheduling, contacts, templates, reports and credit wallets use canonical campaign/message records. Submission acceptance and handset delivery are separate results; a missing delivery report does not prove non-delivery.
- The AWS email campaign service has sender review, reply-inbox verification, content/file inspection, feedback and separate billing/dispatch gates. Available source or a successful direct delivery test does not establish general customer launch readiness.
- WhatsApp uses Facebook Login for Business / Embedded Signup to authorize business assets. Customer portal sign-in is separate. Meta review, permission approval and publication remain external states.
- Administration is separated from customer access. Financial changes, real provider tests and production deployments retain their specific authorization and verification requirements.

Start with [production readiness](docs/operations/production-readiness.md), [email operations and pilot gates](docs/operations/email-feedback-alerts-and-pilot.md) and [Meta setup](docs/operations/whatsapp-meta-setup.md). Dated verification records describe the scope actually checked; they are not a current full-product acceptance certificate.

## Local development

The documented backend baseline is PHP 8.4 with PDO MySQL, cURL, sodium and mbstring, a dedicated MySQL-compatible development database, and an HTTP server configured for the PHP routes. Frontend development uses Node 20+ and the committed npm lockfile. Some isolated PHP fixtures also require PDO SQLite.

1. Read [environment configuration](docs/operations/environment.md) and [database setup](docs/operations/database.md).
2. Create private local environment files from the examples only when needed. Configure a dedicated local/test database and mock transports. Do not copy production credentials into a development or fixture environment.
3. Point `VITE_API_BASE_URL` in the frontend's private local environment to that local API. Its fallback is the production API; starting Vite alone does not create an isolated backend.
4. Install the locked frontend dependencies and start Vite:

```sh
npm ci
npm run dev
```

The Vite configuration requests port 8080; use the URL it prints if the port is occupied. Central sign-in also needs a deliberately configured development integration. There are no documented universal demo accounts or default production passwords.

The API loads `api/.env` through `api/config/database.php`. Its CLI migration runner is `api/bin/migrate.php`, not an Artisan command. It applies all pending numbered migrations, so review the intended schema scope and test restoration on a disposable database before invoking it. This README is not approval to migrate production or unrelated schemas.

## Checks

```sh
npm run typecheck
npm test
npm run test:php
```

`npm run test:php` runs only the pure domain and injected-provider suites named in `package.json`; it is not the complete PHP acceptance matrix. See [test strategy](api/tests/README.md). Run other suites only after checking their database/transport guards. MySQL integration suites must use the required dedicated test database; hermetic SQLite suites do not establish real MySQL concurrency or provider acceptance.

## Authentication and route discovery

Customer sign-in starts through the IEOSUIA central authentication integration. Local registration/password-login routes are disabled in the main router. Protected route groups use `Auth::check()` and bearer JWT validation; account state, session/revocation checks and central administrator validation are server-side. Public health, callback and preference routes have their own contracts and are not ordinary authenticated customer endpoints.

Use `api/index.php` and additional entry-point/route files as the route authority. Current main-router examples:

| Area | Route example |
| --- | --- |
| Central sign-in | `GET /api/auth/ieosuia/start` |
| SMS campaigns | `GET /api/sms/campaigns-v2` |
| Campaign queue / cancellation | `POST /api/sms/campaigns-v2/{id}/queue`, `POST /api/sms/campaigns-v2/{id}/cancel` |
| Contacts | `GET /api/contacts` |
| Templates | `GET /api/templates` |
| Wallet | `GET /api/wallet` |
| Liveness / readiness | `GET /api/up`, `GET /api/ready` |

These examples describe routing only; they do not authorize requests or prove the caller has access.

## Deployment and operations

Follow the [deployment contract](docs/operations/deployment.md) together with the exact approved release scope. The SMS dispatcher is `api/bin/sms-worker.php`; the scheduler and DLR poller have separate cron entries in `ops/cron.example`. They are database-backed PHP processes, not Laravel queue or scheduler commands. Email and WhatsApp have additional independent service and launch gates.

For a scoped update, preserve current live bytes and protected configuration, publish a coherent frontend asset graph, retain rollback evidence and verify readback. Keep `.env`, uploads, audit evidence and credentials private. Do not upload the whole dirty workspace or assume a frontend build authorizes workers, sending or financial changes.

## Ownership and support

Proprietary software owned by IEOSUIA (Pty) Ltd. Support: `sms@ieosuia.com`. Public website: [IEOSUIA](https://ieosuia.com).

## Public homepage rendering

`npm run build` builds the SPA, then runs `scripts/prerender-home.mjs` in a clean,
guest-only browser context. The generated `dist/index.html` contains the real
homepage content. `dist/app.html` stays an empty SPA entry for deep links.
Deploy both files and `dist/.htaccess` together, alongside the generated assets,
robots.txt, sitemap.xml, favicon PNG and social-preview.png. Do not substitute
the prerendered homepage for the SPA fallback.

The build requires Chromium: run `npx playwright install chromium` on build
machines, or set `PRERENDER_BROWSER` to an installed Chrome/Chromium executable.
On Windows the build also detects the standard installed Chrome location.
Prerendering rejects external requests and API requests and never uses a real
account. No production credentials are needed.

Run `node scripts/test-public-deployment.mjs https://sms.ieosuia.com` for raw HTML,
JavaScript-disabled content, metadata, responsive navigation and crawler checks.
Set `TEST_DIST` to a build directory to also scan its JavaScript/CSS and asset
names. Production source maps are disabled and Apache rejects `.map` requests.
Keep any separately generated debug maps private rather than uploading them.
