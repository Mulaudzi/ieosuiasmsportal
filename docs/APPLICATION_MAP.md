# IEOSUIA SMS Portal - Complete Application Map

> **Generated**: 2026-01-13  
> **Purpose**: Full architecture documentation for AI and developer navigation

---

## 📋 Table of Contents

1. [Application Overview](#application-overview)
2. [Tech Stack](#tech-stack)
3. [Frontend Routes & Pages](#frontend-routes--pages)
4. [Backend API Endpoints](#backend-api-endpoints)
5. [Database Schema](#database-schema)
6. [File Dependency Map](#file-dependency-map)
7. [CRUD Operations Matrix](#crud-operations-matrix)
8. [Authentication Flow](#authentication-flow)
9. [Data Flow Diagrams](#data-flow-diagrams)
10. [Services & Integrations](#services--integrations)

---

## 🏗️ Application Overview

**IEOSUIA SMS Portal** is a full-stack SMS and Email marketing platform built with:
- React/TypeScript frontend (Vite + TailwindCSS)
- Raw PHP backend API
- MySQL database
- Multi-gateway SMS delivery (Telnyx primary, LogicSMS fallback)
- Multiple payment gateways (PayFast, Paystack, Ozow)

---

## 🔧 Tech Stack

### Frontend
| Technology | Purpose |
|------------|---------|
| React 18 | UI Framework |
| TypeScript | Type Safety |
| Vite | Build Tool |
| TailwindCSS | Styling |
| Tanstack Query | Data Fetching |
| React Router DOM | Routing |
| Shadcn/UI | Component Library |
| Recharts | Charts/Analytics |

### Backend
| Technology | Purpose |
|------------|---------|
| PHP 8+ | Server Language |
| MySQL | Database |
| Custom Router | API Routing |
| QueryBuilder | Database ORM |
| JWT | Authentication |

### External Services
| Service | Purpose |
|---------|---------|
| Telnyx | Primary SMS Gateway |
| LogicSMS | Fallback SMS Gateway |
| PayFast | Payment (ZA) |
| Paystack | Payment (Africa) |
| Ozow | Payment (Instant EFT) |
| Google OAuth | Social Login |
| SMTP (PHPMailer) | Email Delivery |

---

## 🗺️ Frontend Routes & Pages

### Public Routes (No Auth Required)

| Route | Page Component | Purpose |
|-------|----------------|---------|
| `/` | `Landing.tsx` | Marketing homepage |
| `/pricing` | `Pricing.tsx` | Pricing plans display |
| `/login` | `Login.tsx` | User login form |
| `/register` | `Register.tsx` | User registration |
| `/forgot-password` | `ForgotPassword.tsx` | Password reset request |
| `/verify-email` | `VerifyEmail.tsx` | Email verification handler |
| `/auth/google/callback` | `GoogleCallback.tsx` | Google OAuth callback |
| `/terms-of-service` | `TermsOfService.tsx` | Legal terms |
| `/privacy-policy` | `PrivacyPolicy.tsx` | Privacy policy |
| `/cookie-policy` | `CookiePolicy.tsx` | Cookie policy |
| `/popia-compliance` | `PopiaCompliance.tsx` | POPIA compliance (SA) |
| `/support` | `Support.tsx` | Help/support page |
| `/documentation` | `Documentation.tsx` | API docs |
| `/careers` | `Careers.tsx` | Job listings |
| `/contact` | `Contact.tsx` | Contact form |

### Protected Routes (Auth Required)

| Route | Page Component | Requires Verified | Purpose |
|-------|----------------|-------------------|---------|
| `/dashboard` | `Dashboard.tsx` | No | Main user dashboard |
| `/profile` | `Profile.tsx` | No | User profile management |
| `/verify-email-reminder` | `VerifyEmailReminder.tsx` | No | Email verification prompt |
| `/sms-campaigns` | `SmsCampaigns.tsx` | Yes | SMS campaign list |
| `/sms-campaigns/new` | `CreateSmsCampaign.tsx` | Yes | Create SMS campaign |
| `/sms-campaigns/:id` | `CampaignDetails.tsx` | Yes | View campaign details |
| `/email-campaigns` | `EmailCampaigns.tsx` | Yes | Email campaign list |
| `/email-campaigns/new` | `CreateEmailCampaign.tsx` | Yes | Create email campaign |
| `/email-campaigns/:id` | `CampaignDetails.tsx` | Yes | View campaign details |
| `/contacts` | `Contacts.tsx` | Yes | Contact management |
| `/contacts/import` | `Contacts.tsx` | Yes | Import contacts |
| `/templates` | `Templates.tsx` | Yes | Message templates |
| `/wallet` | `Wallet.tsx` | No | Wallet/credits view |
| `/wallet/payments` | `PaymentHistory.tsx` | No | Payment history |
| `/payment/success` | `PaymentSuccess.tsx` | No | Payment success handler |
| `/payment/failed` | `PaymentFailed.tsx` | No | Payment failure handler |
| `/reports` | `Reports.tsx` | Yes | Analytics & reports |
| `/reports/compare` | `CampaignComparison.tsx` | Yes | Compare campaigns |
| `/settings` | `Settings.tsx` | No | User settings |
| `/e2e-tests` | `E2ETestConsole.tsx` | No | E2E testing console |

### Admin Routes (Admin Auth Required)

| Route | Page Component | Purpose |
|-------|----------------|---------|
| `/admin/login` | `AdminLogin.tsx` | Admin login (3-password) |
| `/admin` | `AdminDashboard.tsx` | Admin dashboard |
| `/admin/users` | `AdminManagement.tsx` | User management |

---

## 🔌 Backend API Endpoints

### Authentication (`AuthController.php`)

| Method | Endpoint | Auth | Purpose |
|--------|----------|------|---------|
| POST | `/auth/register` | No | User registration |
| POST | `/auth/login` | No | User login |
| POST | `/auth/logout` | Yes | Logout |
| GET | `/auth/user` | Yes | Get current user |
| PUT | `/auth/user` | Yes | Update user profile |
| POST | `/auth/avatar` | Yes | Upload avatar |
| POST | `/auth/forgot-password` | No | Request password reset |
| POST | `/auth/reset-password` | No | Reset password with OTP |
| POST | `/auth/verify-email` | No | Verify email token |
| POST | `/auth/resend-verification` | Yes | Resend verification email |
| POST | `/auth/refresh` | Yes | Refresh JWT token |

### Google OAuth (`GoogleAuthController.php`)

| Method | Endpoint | Auth | Purpose |
|--------|----------|------|---------|
| GET | `/auth/google/status` | No | Check Google OAuth status |
| GET | `/auth/google/url` | No | Get OAuth URL |
| POST | `/auth/google/callback` | No | Handle OAuth callback |
| POST | `/auth/google/credential` | No | Sign in with credential |

### Dashboard (`DashboardController.php`)

| Method | Endpoint | Auth | Purpose |
|--------|----------|------|---------|
| GET | `/dashboard/stats` | Yes | Dashboard statistics |
| GET | `/dashboard/chart` | Yes | Chart data (messages/day) |
| GET | `/dashboard/recent-campaigns` | Yes | Recent campaigns list |
| GET | `/dashboard/schedule-recommendations` | Yes | Best send time recommendations |

### Contacts (`ContactController.php`)

| Method | Endpoint | Auth | Purpose |
|--------|----------|------|---------|
| GET | `/contacts` | Yes | List contacts (paginated) |
| POST | `/contacts` | Yes | Create contact |
| GET | `/contacts/{id}` | Yes | Get single contact |
| PUT | `/contacts/{id}` | Yes | Update contact |
| DELETE | `/contacts/{id}` | Yes | Delete contact |
| POST | `/contacts/import` | Yes | Import from CSV/Excel |
| GET | `/contacts/export` | Yes | Export to CSV |

### Contact Groups (`ContactController.php`)

| Method | Endpoint | Auth | Purpose |
|--------|----------|------|---------|
| GET | `/contact-groups` | Yes | List groups |
| POST | `/contact-groups` | Yes | Create group |
| PUT | `/contact-groups/{id}` | Yes | Update group |
| DELETE | `/contact-groups/{id}` | Yes | Delete group |

### Templates (`TemplateController.php`)

| Method | Endpoint | Auth | Purpose |
|--------|----------|------|---------|
| GET | `/templates` | Yes | List templates |
| POST | `/templates` | Yes | Create template |
| GET | `/templates/{id}` | Yes | Get template |
| PUT | `/templates/{id}` | Yes | Update template |
| DELETE | `/templates/{id}` | Yes | Delete template |

### SMS Campaigns (`CampaignController.php`)

| Method | Endpoint | Auth | Purpose |
|--------|----------|------|---------|
| GET | `/sms/campaigns` | Yes | List SMS campaigns |
| POST | `/sms/campaigns` | Yes | Create SMS campaign |
| GET | `/sms/campaigns/{id}` | Yes | Get campaign details |
| POST | `/sms/campaigns/{id}/send` | Yes | Send campaign |
| POST | `/sms/campaigns/{id}/cancel` | Yes | Cancel scheduled |
| POST | `/sms/campaigns/{id}/duplicate` | Yes | Duplicate campaign |
| GET | `/sms/campaigns/{id}/export` | Yes | Export messages CSV |
| DELETE | `/sms/campaigns/{id}` | Yes | Delete campaign |

### Email Campaigns (`CampaignController.php`)

| Method | Endpoint | Auth | Purpose |
|--------|----------|------|---------|
| GET | `/email/campaigns` | Yes | List email campaigns |
| POST | `/email/campaigns` | Yes | Create email campaign |
| GET | `/email/campaigns/{id}` | Yes | Get campaign details |
| POST | `/email/campaigns/{id}/send` | Yes | Send campaign |
| POST | `/email/campaigns/{id}/duplicate` | Yes | Duplicate campaign |
| GET | `/email/campaigns/{id}/export` | Yes | Export messages CSV |
| DELETE | `/email/campaigns/{id}` | Yes | Delete campaign |
| GET | `/email/limits` | Yes | Check daily email limits |

### Wallet (`WalletController.php`)

| Method | Endpoint | Auth | Purpose |
|--------|----------|------|---------|
| GET | `/wallet` | Yes | Get wallet balance |
| GET | `/wallet/stats` | Yes | Wallet statistics |
| GET | `/wallet/transactions` | Yes | Transaction history |
| GET | `/wallet/payments` | Yes | Payment history |
| GET | `/wallet/receipt` | Yes | Generate PDF receipt |
| GET | `/wallet/packages` | Yes | Credit packages |
| POST | `/wallet/buy` | Yes | Initiate purchase |

### Reports (`ReportController.php`)

| Method | Endpoint | Auth | Purpose |
|--------|----------|------|---------|
| GET | `/reports/stats` | Yes | Report statistics |
| GET | `/reports/chart` | Yes | Chart data |
| GET | `/reports/delivery` | Yes | Delivery breakdown |
| GET | `/reports/campaigns` | Yes | Campaign list |
| GET | `/reports/messages` | Yes | Message list |
| GET | `/reports/export` | Yes | Export report |
| GET | `/reports/compare` | Yes | Compare campaigns |
| GET | `/reports/ab-test-results` | Yes | A/B test results |
| POST | `/reports/ab-test-winner` | Yes | Select A/B winner |

### Admin (`AdminController.php`)

| Method | Endpoint | Auth | Purpose |
|--------|----------|------|---------|
| GET | `/admin/stats` | Yes | Admin dashboard stats |
| GET | `/admin/users` | Yes | List all users |
| GET | `/admin/users/{id}` | Yes | Get user details |
| POST | `/admin/users/{id}/activate` | Yes | Activate user |
| POST | `/admin/users/{id}/deactivate` | Yes | Deactivate user |
| PUT | `/admin/users/{id}/role` | Yes | Change user role |
| GET | `/admin/audit-logs` | Yes | Audit log list |
| GET | `/admin/system-health` | Yes | System health check |

### Webhooks (Public with signature validation)

| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/webhooks/telnyx/dlr` | Telnyx DLR webhook |
| POST | `/webhooks/telnyx/inbound` | Telnyx inbound SMS |
| POST | `/payments/payfast/itn` | PayFast ITN |
| POST | `/payments/paystack/webhook` | Paystack webhook |
| POST | `/payments/ozow/notify` | Ozow notification |

---

## 🗄️ Database Schema

### Core Tables

| Table | Purpose | Key Fields |
|-------|---------|------------|
| `users` | User accounts | id, name, email, password, account_type, email_verified_at |
| `wallets` | User wallets | id, user_id, balance, reserved, currency |
| `wallet_transactions` | Transaction history | id, wallet_id, amount, type, status |
| `payments` | Payment records | id, user_id, gateway, amount, status |

### Contacts

| Table | Purpose | Key Fields |
|-------|---------|------------|
| `contacts` | Contact records | id, user_id, name, phone, email, subscription_status |
| `contact_groups` | Contact groups | id, user_id, name, description |
| `group_contacts` | Group membership | group_id, contact_id |

### Campaigns

| Table | Purpose | Key Fields |
|-------|---------|------------|
| `campaigns` | Campaign records | id, user_id, type, name, status, scheduled_at |
| `campaign_variants` | A/B test variants | id, campaign_id, variant_name, message_content |
| `messages` | Individual messages | id, campaign_id, recipient, status, external_id |

### Templates & Settings

| Table | Purpose | Key Fields |
|-------|---------|------------|
| `templates` | Message templates | id, user_id, name, type, content |
| `opt_outs` | Unsubscribed contacts | id, user_id, recipient |
| `notifications` | User notifications | id, user_id, type, data, read_at |

### Admin

| Table | Purpose | Key Fields |
|-------|---------|------------|
| `admin_users` | Admin accounts | id, email, password_1, password_2, password_3 |
| `audit_logs` | Audit trail | id, action, entity_type, user_id |

---

## 🔗 File Dependency Map

### Frontend Core

```
src/App.tsx
├── src/hooks/useAuth.tsx (AuthProvider, ProtectedRoute)
├── src/components/auth/AdminRoute.tsx
├── src/pages/*.tsx (All page components)
└── src/components/layout/
    ├── DashboardLayout.tsx
    ├── Sidebar.tsx
    ├── AdminLayout.tsx
    └── AdminSidebar.tsx
```

### API Client

```
src/lib/api.ts
├── Provides: api.get(), api.post(), api.put(), api.delete(), api.upload()
├── Handles: JWT token, 401 redirects, error formatting
└── Exports: All API functions (login, createContact, etc.)
```

### Backend Core

```
api/index.php (Entry Point)
├── api/core/Router.php (Route dispatching)
├── api/core/Response.php (JSON responses)
├── api/core/Request.php (Input validation)
├── api/core/Auth.php (JWT authentication)
├── api/core/JWT.php (Token generation)
├── api/core/QueryBuilder.php (Database ORM)
└── api/config/database.php (PDO connection)
```

### Controllers → Services

```
CampaignController.php
├── SmsService.php
│   └── TelnyxService.php
├── EmailService.php
├── BatchEmailService.php
├── AuditLogService.php
└── AdminNotificationService.php

WalletController.php
└── PdfReceiptService.php

AuthController.php
├── EmailService.php
├── AdminNotificationService.php
└── AuditLogService.php
```

---

## ✅ CRUD Operations Matrix

### Contacts Module

| Operation | Frontend | API Endpoint | Controller Method | DB Tables |
|-----------|----------|--------------|-------------------|-----------|
| **Create** | AddContactModal.tsx | POST /contacts | ContactController@store | contacts, group_contacts |
| **Read** | Contacts.tsx | GET /contacts | ContactController@index | contacts |
| **Update** | AddContactModal.tsx | PUT /contacts/{id} | ContactController@update | contacts |
| **Delete** | Contacts.tsx | DELETE /contacts/{id} | ContactController@destroy | contacts, group_contacts |
| **Import** | ContactImportModal.tsx | POST /contacts/import | ContactController@import | contacts, group_contacts |
| **Export** | Contacts.tsx | GET /contacts/export | ContactController@export | contacts |

### Templates Module

| Operation | Frontend | API Endpoint | Controller Method | DB Tables |
|-----------|----------|--------------|-------------------|-----------|
| **Create** | TemplateModal.tsx | POST /templates | TemplateController@store | templates |
| **Read** | Templates.tsx | GET /templates | TemplateController@index | templates |
| **Update** | TemplateModal.tsx | PUT /templates/{id} | TemplateController@update | templates |
| **Delete** | Templates.tsx | DELETE /templates/{id} | TemplateController@destroy | templates |

### SMS Campaign Module

| Operation | Frontend | API Endpoint | Controller Method | DB Tables |
|-----------|----------|--------------|-------------------|-----------|
| **Create** | CreateSmsCampaign.tsx | POST /sms/campaigns | CampaignController@smsStore | campaigns, messages, campaign_variants |
| **Read** | SmsCampaigns.tsx | GET /sms/campaigns | CampaignController@smsIndex | campaigns |
| **Read One** | CampaignDetails.tsx | GET /sms/campaigns/{id} | CampaignController@smsShow | campaigns, messages |
| **Send** | CampaignDetails.tsx | POST /sms/campaigns/{id}/send | CampaignController@smsSend | campaigns, messages, wallets, wallet_transactions |
| **Delete** | SmsCampaigns.tsx | DELETE /sms/campaigns/{id} | CampaignController@destroy | campaigns, messages |
| **Duplicate** | CampaignDetails.tsx | POST /sms/campaigns/{id}/duplicate | CampaignController@duplicate | campaigns, messages |

### Wallet Module

| Operation | Frontend | API Endpoint | Controller Method | DB Tables |
|-----------|----------|--------------|-------------------|-----------|
| **Read Balance** | Wallet.tsx | GET /wallet/stats | WalletController@stats | wallets |
| **Read Transactions** | Wallet.tsx | GET /wallet/transactions | WalletController@transactions | wallet_transactions |
| **Buy Credits** | BuyCreditsModal.tsx | POST /wallet/buy | WalletController@buy | wallets, wallet_transactions |

---

## 🔐 Authentication Flow

### Regular User Login

```
1. User submits email/password on /login
2. Frontend calls POST /auth/login
3. AuthController validates credentials against `users` table
4. JWT token generated with 24hr expiry
5. Token + user data returned to frontend
6. Frontend stores in localStorage, schedules refresh
7. Subsequent requests include Authorization: Bearer {token}
```

### Admin Login (3-Password System)

```
1. User submits email on /admin/login
2. Frontend calls POST /admin/check-email
3. If admin email detected, 3 password fields shown
4. Frontend calls POST /auth/login with password_2, password_3
5. AdminUserController verifies all 3 passwords
6. On success, admin token issued
7. Admin routes protected by AdminRoute component
```

### Email Verification

```
1. Registration triggers verification email
2. Email contains link with token
3. User clicks link → /verify-email?token=xxx
4. POST /auth/verify-email validates token
5. User's email_verified_at updated
6. Protected routes requiring verification now accessible
```

---

## 📊 Data Flow Diagrams

### SMS Campaign Send Flow

```
User creates campaign (CreateSmsCampaign.tsx)
    ↓
POST /sms/campaigns → CampaignController@smsStore
    ↓
Create campaign record + message records
Reserve funds in wallet
    ↓
User clicks "Send Now" (CampaignDetails.tsx)
    ↓
POST /sms/campaigns/{id}/send → CampaignController@smsSend
    ↓
For each message:
  - Check opt-out list
  - SmsService→TelnyxService sends SMS
  - Update message status + external_id
    ↓
Debit wallet, create transaction record
Update campaign status to "Sent"
    ↓
Telnyx sends DLR webhook → /webhooks/telnyx/dlr
    ↓
TelnyxWebhookController updates message status (Delivered/Failed)
```

### Payment Flow

```
User selects credit package (Wallet.tsx)
    ↓
POST /wallet/buy → WalletController@buy
    ↓
Create pending transaction
Generate payment URL (PayFast/Paystack/Ozow)
    ↓
Redirect user to payment gateway
    ↓
User completes payment
    ↓
Gateway sends webhook → /payments/{gateway}/itn
    ↓
PaymentWebhookController:
  - Validates signature
  - Creates payment record
  - Credits wallet balance
  - Updates transaction status
    ↓
User redirected to /payment/success
```

---

## ⚙️ Services & Integrations

### SMS Service (`SmsService.php`)

- **Primary Gateway**: Telnyx (via TelnyxService.php)
- **Fallback Gateway**: LogicSMS
- **Features**: Single send, bulk send, status check
- **Phone Format**: Auto-converts to E.164 (ZA default +27)

### Email Service (`EmailService.php`)

- **Method**: SMTP via PHPMailer
- **Features**: Verification emails, password reset, campaign emails
- **Templates**: HTML with placeholder replacement

### Payment Gateways

| Gateway | Countries | Webhook |
|---------|-----------|---------|
| PayFast | South Africa | `/payments/payfast/itn` |
| Paystack | Africa (NG, GH, ZA) | `/payments/paystack/webhook` |
| Ozow | South Africa (EFT) | `/payments/ozow/notify` |

### Audit Logging (`AuditLogService.php`)

Tracks: user_registered, admin_login, campaign_created, campaign_sent, payment_completed

---

## 🐛 Debug Guide

### Common Error Points

| Issue | Check | Solution |
|-------|-------|----------|
| 401 Unauthorized | Token expired | Frontend should redirect to /login |
| SMS not delivered | Telnyx API key | Check SMS_GATEWAY, TELNYX_API_KEY |
| Payment not credited | Webhook not received | Check ITN URL, server logs |
| Campaign stuck "Sending" | Cron not running | Run process_scheduled_campaigns.php |

### Log Locations

- PHP errors: Server error log
- API responses: Network tab
- Frontend state: React DevTools
- SMS delivery: Telnyx dashboard

---

## 📁 Quick Reference

### Key Files by Feature

| Feature | Frontend | Backend | Service |
|---------|----------|---------|---------|
| Auth | useAuth.tsx | AuthController.php | - |
| SMS | CreateSmsCampaign.tsx | CampaignController.php | SmsService.php |
| Email | CreateEmailCampaign.tsx | CampaignController.php | EmailService.php |
| Contacts | Contacts.tsx | ContactController.php | - |
| Payments | Wallet.tsx | WalletController.php | - |
| Admin | AdminDashboard.tsx | AdminController.php | AdminNotificationService.php |

---

*This document should be updated when major features are added or modified.*
