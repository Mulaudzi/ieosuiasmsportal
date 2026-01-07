#!/bin/bash
# Deploy script for IEOSUIA SMS Portal
# Usage: ./deploy.sh [environment]
# Example: ./deploy.sh production

set -e

ENVIRONMENT=${1:-production}
PROJECT_DIR="/var/www/sms.ieosuia.com"
REMOTE_USER="deploy"
REMOTE_HOST="sms.ieosuia.com"

echo "🚀 Deploying IEOSUIA SMS Portal to ${ENVIRONMENT}..."

# Build frontend
echo "📦 Building frontend..."
npm ci
npm run build

# Upload frontend
echo "📤 Uploading frontend files..."
rsync -avz --delete dist/ ${REMOTE_USER}@${REMOTE_HOST}:${PROJECT_DIR}/dist/

# Upload API
echo "📤 Uploading API files..."
rsync -avz --delete \
  --exclude 'vendor' \
  --exclude 'node_modules' \
  --exclude '.env' \
  --exclude 'storage/logs/*' \
  --exclude 'storage/framework/cache/*' \
  --exclude 'storage/framework/sessions/*' \
  --exclude 'storage/framework/views/*' \
  api/ ${REMOTE_USER}@${REMOTE_HOST}:${PROJECT_DIR}/api/

# Run remote commands
echo "🔧 Running remote setup..."
ssh ${REMOTE_USER}@${REMOTE_HOST} << 'ENDSSH'
  cd /var/www/sms.ieosuia.com/api

  # Install PHP dependencies
  composer install --no-dev --optimize-autoloader

  # Run migrations
  php artisan migrate --force

  # Clear caches
  php artisan config:cache
  php artisan route:cache
  php artisan view:cache

  # Restart queue workers
  php artisan queue:restart

  # Set permissions
  sudo chown -R www-data:www-data storage bootstrap/cache
  sudo chmod -R 775 storage bootstrap/cache

  echo "✅ Deployment complete!"
ENDSSH

echo "🎉 Deployment to ${ENVIRONMENT} successful!"
echo "   Frontend: https://sms.ieosuia.com"
echo "   API: https://sms.ieosuia.com/api"
