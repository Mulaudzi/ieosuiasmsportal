#!/usr/bin/env bash
set -euo pipefail

PROJECT_DIR="${PROJECT_DIR:-/var/www/sms.ieosuia.com}"
npm ci
npm run build

rsync -az --delete --exclude '.env' --exclude 'uploads/' dist/ "${PROJECT_DIR}/dist/"
rsync -az --delete --exclude '.env' --exclude 'uploads/' api/ "${PROJECT_DIR}/api/"

php "${PROJECT_DIR}/api/bin/migrate.php"
install -d -o www-data -g www-data -m 0750 "${PROJECT_DIR}/api/uploads"

echo "Deploy complete. Restart ieosuia-sms-worker using the configured process manager."
