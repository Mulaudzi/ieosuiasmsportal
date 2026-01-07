# IEOSUIA SMS Portal

A comprehensive SMS and Email marketing platform built with Laravel (PHP 8.4) backend and React/Vite frontend.

## 🚀 Features

- **SMS Campaigns**: Send bulk SMS messages with delivery tracking
- **Email Campaigns**: Create and send HTML email campaigns
- **Contact Management**: Import, organize, and segment contacts
- **Template System**: Reusable message templates with variables
- **Wallet System**: Credit-based billing with transaction history
- **Real-time DLR**: Delivery report tracking and webhooks
- **Analytics**: Comprehensive reporting and exports
- **Multi-user**: Role-based access control

## 📁 Project Structure

```
sms.ieosuia.com/
├── api/                    # Laravel PHP Backend
│   ├── app/
│   │   ├── Http/Controllers/
│   │   ├── Models/
│   │   ├── Services/
│   │   └── Jobs/
│   ├── database/
│   │   ├── migrations/
│   │   ├── seeders/
│   │   └── factories/
│   ├── routes/api.php
│   └── public/             # API document root
├── src/                    # React Frontend Source
│   ├── components/
│   ├── pages/
│   ├── hooks/
│   └── lib/
├── dist/                   # Built frontend (after npm run build)
├── nginx.conf              # Nginx configuration
└── deploy.sh               # Deployment script
```

## 🛠️ Local Development Setup

### Prerequisites

- PHP 8.4+
- Composer 2.x
- Node.js 18+
- MySQL 8.0+

### Backend Setup

```bash
# Navigate to API directory
cd api

# Install PHP dependencies
composer install

# Copy environment file
cp .env.example .env

# Generate application key
php artisan key:generate

# Configure database in .env
# DB_CONNECTION=mysql
# DB_HOST=127.0.0.1
# DB_PORT=3306
# DB_DATABASE=ieosuia_sms
# DB_USERNAME=your_user
# DB_PASSWORD=your_password

# Run migrations
php artisan migrate

# Seed demo data
php artisan db:seed

# Start API server
php artisan serve --port=8000
```

### Frontend Setup

```bash
# From project root
npm install

# Start development server
npm run dev
```

Access the app at `http://localhost:5173`

### Demo Credentials

| Role | Email | Password |
|------|-------|----------|
| Admin | admin@ieosuia.com | password |
| User | test@example.com | password |

## 🧪 Running Tests

```bash
cd api

# Run all tests
php artisan test

# Run specific test suite
php artisan test --testsuite=Feature

# With coverage
php artisan test --coverage
```

## 📦 Production Deployment

### Server Requirements

- Ubuntu 22.04+ / CentOS 8+
- Nginx or Apache
- PHP 8.4 with extensions: BCMath, Ctype, cURL, DOM, Fileinfo, JSON, Mbstring, OpenSSL, PCRE, PDO, Tokenizer, XML
- MySQL 8.0+
- Redis (optional, for queues)
- Supervisor (for queue workers)

### Quick Deploy

```bash
# Make deploy script executable
chmod +x deploy.sh

# Run deployment
./deploy.sh
```

### Manual Deployment

#### 1. Build Frontend

```bash
npm run build
```

#### 2. Upload Files

Upload to your server:
- `dist/` → `/var/www/sms.ieosuia.com/dist/`
- `api/` → `/var/www/sms.ieosuia.com/api/`

#### 3. Configure Backend

```bash
cd /var/www/sms.ieosuia.com/api

composer install --optimize-autoloader --no-dev
cp .env.example .env
php artisan key:generate
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

#### 4. Set Permissions

```bash
chown -R www-data:www-data /var/www/sms.ieosuia.com
chmod -R 755 /var/www/sms.ieosuia.com
chmod -R 775 /var/www/sms.ieosuia.com/api/storage
chmod -R 775 /var/www/sms.ieosuia.com/api/bootstrap/cache
```

#### 5. Configure Nginx

```bash
sudo cp nginx.conf /etc/nginx/sites-available/sms.ieosuia.com
sudo ln -s /etc/nginx/sites-available/sms.ieosuia.com /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

#### 6. SSL Certificate

```bash
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d sms.ieosuia.com
```

#### 7. Queue Worker (Supervisor)

```bash
sudo cp api/supervisor.conf /etc/supervisor/conf.d/sms-queue.conf
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start sms-queue:*
```

#### 8. Cron Job

Add to crontab (`crontab -e`):

```
* * * * * cd /var/www/sms.ieosuia.com/api && php artisan schedule:run >> /dev/null 2>&1
```

## ⚙️ Configuration

### Environment Variables

Key settings in `api/.env`:

```env
# Application
APP_NAME="IEOSUIA SMS Portal"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://sms.ieosuia.com

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ieosuia_sms
DB_USERNAME=your_user
DB_PASSWORD=your_password

# SMS Gateway (LogicSMS)
SMS_GATEWAY_URL=https://www.logicsms.co.za/postmsg2.aspx
SMS_GATEWAY_USERNAME=your_username
SMS_GATEWAY_PASSWORD=your_password
SMS_DEFAULT_SENDER=IEOSUIA

# Email (SMTP)
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailgun.org
MAIL_PORT=587
MAIL_USERNAME=your_smtp_user
MAIL_PASSWORD=your_smtp_password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@ieosuia.com
MAIL_FROM_NAME="IEOSUIA SMS Portal"

# Queue
QUEUE_CONNECTION=database

# Frontend URL
FRONTEND_URL=https://sms.ieosuia.com
```

### SMS Gateway Integration

The system supports LogicSMS (South Africa). Configure in `api/config/sms.php`:

```php
'api_url' => env('SMS_GATEWAY_URL'),
'username' => env('SMS_GATEWAY_USERNAME'),
'password' => env('SMS_GATEWAY_PASSWORD'),
'default_sender' => env('SMS_DEFAULT_SENDER', 'IEOSUIA'),
```

## 🔒 Security

- All API routes require authentication (Laravel Sanctum)
- CORS configured for frontend domain only
- Rate limiting on authentication endpoints
- Input validation on all requests
- XSS protection headers

## 📊 API Endpoints

### Authentication
- `POST /api/auth/register` - Register new user
- `POST /api/auth/login` - Login
- `POST /api/auth/logout` - Logout
- `GET /api/auth/me` - Get current user

### Campaigns
- `GET /api/sms/campaigns` - List SMS campaigns
- `POST /api/sms/campaigns` - Create SMS campaign
- `GET /api/sms/campaigns/{id}` - Get campaign details
- `DELETE /api/sms/campaigns/{id}` - Delete campaign

### Contacts
- `GET /api/contacts` - List contacts
- `POST /api/contacts` - Create contact
- `POST /api/contacts/import` - Import CSV
- `GET /api/contacts/groups` - List groups

### Templates
- `GET /api/templates` - List templates
- `POST /api/templates` - Create template
- `PUT /api/templates/{id}` - Update template

### Wallet
- `GET /api/wallet/balance` - Get balance
- `GET /api/wallet/transactions` - Transaction history
- `POST /api/wallet/buy` - Buy credits

## 🤝 Contributing

1. Fork the repository
2. Create feature branch (`git checkout -b feature/amazing-feature`)
3. Commit changes (`git commit -m 'Add amazing feature'`)
4. Push to branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## 📄 License

This project is proprietary software owned by IEOSUIA (Pty) Ltd.

## 📞 Support

For support, email support@ieosuia.com or visit https://ieosuia.com
