# Deployment

Requirements: Node 20+, PHP 8.4 with PDO MySQL, cURL, sodium and mbstring, MySQL 8+, Nginx, and systemd or Supervisor.

1. Copy `api/.env.example` to `api/.env` outside source control and set all launch-blocking values.
2. Run `npm ci && npm run build`.
3. Deploy `dist/` and `api/` without overwriting `.env` or uploads.
4. Back up MySQL and run `php api/bin/migrate.php`.
5. Install `ops/ieosuia-sms-worker.service`, enable and start it.
6. Install the scheduler and DLR-poller lines from `ops/cron.example`. Run the DLR poller every minute and retain `flock -n` so a slow provider response cannot overlap the next invocation. Shared-hosting cron entries omit the `www-data` column and must use the account's absolute document-root and writable log paths.
7. Configure Nginx/PHP-FPM. Confirm `/api/up` returns 200 for web/database health and `/api/ready` returns 200 after the worker, scheduler, and DLR poller have produced fresh heartbeats.

Never run the removed Laravel/Artisan deployment steps. Test migrations and rollback procedures on staging before production.
