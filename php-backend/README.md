# IEOSUIA SMS Portal - PHP Backend

A production-ready PHP/Laravel backend for the IEOSUIA SMS Portal.

## Requirements

- PHP 8.4+
- Composer 2.x
- MySQL 8.0+
- Redis (optional, for queues)
- Apache/Nginx

## Installation

### 1. Clone and Install Dependencies

```bash
cd php-backend
composer install
```

### 2. Environment Setup

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` with your database credentials and API keys.

### 3. Database Setup

```bash
# Create database
mysql -u root -p -e "CREATE DATABASE smsportal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Run migrations
php artisan migrate

# Seed initial data
php artisan db:seed
```

Or import the schema directly:

```bash
mysql -u root -p smsportal < database/schema.sql
```

### 4. Configure Web Server

**Apache (.htaccess is included)**

Point document root to `/public` folder.

**Nginx**

```nginx
server {
    listen 80;
    server_name api.smsportal.ieosuia.com;
    root /var/www/smsportal/public;

    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

### 5. Queue Worker (for background processing)

```bash
# Development
php artisan queue:work

# Production (use Supervisor)
# See supervisor configuration below
```

### 6. Scheduled Tasks

Add to crontab:

```bash
* * * * * cd /var/www/smsportal && php artisan schedule:run >> /dev/null 2>&1
```

## API Endpoints

### Authentication

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/auth/register` | Register new user |
| POST | `/api/auth/login` | Login and get token |
| POST | `/api/auth/logout` | Logout (revoke token) |
| GET | `/api/auth/me` | Get current user |
| GET | `/api/auth/check` | Check auth status |

### Dashboard

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/dashboard/stats` | Get dashboard statistics |

### SMS Campaigns

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/sms/campaigns` | List SMS campaigns |
| POST | `/api/sms/campaigns/create` | Create SMS campaign |
| GET | `/api/sms/campaigns/{id}` | Get campaign details |

### Email Campaigns

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/email/campaigns` | List email campaigns |
| POST | `/api/email/campaigns/create` | Create email campaign |
| GET | `/api/email/campaigns/{id}` | Get campaign details |

### Contacts

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/contacts` | List contacts |
| POST | `/api/contacts` | Create contact |
| POST | `/api/contacts/import` | Import from CSV |
| GET | `/api/contacts/export` | Export to CSV |
| DELETE | `/api/contacts` | Delete contacts |
| GET | `/api/contacts/groups` | List groups |
| POST | `/api/contacts/groups` | Create group |

### Wallet

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/wallet` | Get balance |
| GET | `/api/wallet/history` | Transaction history |
| POST | `/api/wallet/buy` | Purchase credits |

### Webhooks (No Auth)

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/dlr/webhook` | DLR from SMS gateway |
| POST | `/api/payments/payfast/itn` | PayFast ITN |
| POST | `/api/payments/ozow/notify` | Ozow notification |

## Payment Integration

### PayFast Setup

1. Create PayFast merchant account
2. Add credentials to `.env`
3. Configure ITN URL: `https://api.smsportal.ieosuia.com/api/payments/payfast/itn`
4. Enable in PayFast dashboard

### Ozow Setup

1. Create Ozow merchant account
2. Add credentials to `.env`
3. Configure notify URL: `https://api.smsportal.ieosuia.com/api/payments/ozow/notify`

### EFT (Manual)

Bank details are configured in `.env`. Admin manually confirms EFT payments via:

```
POST /api/admin/wallet/confirm-eft
{
  "reference": "SMS-ABC12345",
  "amount": 500.00
}
```

## SMS Gateway Integration

### LogicSMS

Configure in `.env`:

```
LOGICSMS_API_URL=https://www.logicsms.co.za/api/send
LOGICSMS_USERNAME=your_username
LOGICSMS_PASSWORD=your_password
```

### DLR Webhook

Configure your gateway to send DLR to:

```
POST https://api.smsportal.ieosuia.com/api/dlr/webhook
```

## Supervisor Configuration

Create `/etc/supervisor/conf.d/smsportal-worker.conf`:

```ini
[program:smsportal-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/smsportal/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=4
redirect_stderr=true
stdout_logfile=/var/www/smsportal/storage/logs/worker.log
stopwaitsecs=3600
```

Then:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start smsportal-worker:*
```

## Frontend Integration

Update your React frontend `.env`:

```
VITE_API_BASE_URL=https://api.smsportal.ieosuia.com/api
```

Update API client to use Bearer token authentication:

```typescript
const apiClient = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL,
});

apiClient.interceptors.request.use((config) => {
  const token = localStorage.getItem('auth_token');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});
```

## Security Checklist

- [ ] Set `APP_DEBUG=false` in production
- [ ] Use HTTPS only
- [ ] Configure CORS properly
- [ ] Set strong `APP_KEY`
- [ ] Use environment variables for all secrets
- [ ] Enable rate limiting
- [ ] Implement CSRF protection
- [ ] Regular security audits

## License

Proprietary - IEOSUIA (Pty) Ltd
