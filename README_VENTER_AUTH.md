# Venter App — Seller Auth Backend (Laravel + MySQL + SMS OTP)

Backend files only. Includes seller registration, phone/password login, token logout, profile endpoint, and SMS OTP password reset using Twilio Verify API. No frontend pages are included.

## Requirements
- Existing Laravel project (recommended Laravel 11/12)
- PHP + Composer
- MySQL (XAMPP is fine)
- Twilio account with Verify service configured and SMS enabled for your target country

## 1. Install API auth
From the Laravel project root:
```bash
php artisan install:api
```
If already installed, skip. This sets up Laravel Sanctum for bearer-token API auth.

## 2. Create database
In phpMyAdmin, create `venter_app`. Update `.env`:
```env
APP_NAME="Venter App API"
APP_URL=http://127.0.0.1:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=venter_app
DB_USERNAME=root
DB_PASSWORD=

TWILIO_ACCOUNT_SID=ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
TWILIO_AUTH_TOKEN=replace_with_your_twilio_auth_token
TWILIO_VERIFY_SERVICE_SID=VAxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```
Use actual credentials. Never commit or share `.env`. Twilio Verify service SID starts with `VA`; account SID starts with `AC`.

## 3. Copy/merge files
Copy the files in this ZIP into your Laravel project at matching paths:
- `app/Models/User.php`
- `app/Http/Controllers/Api/AuthController.php`
- `app/Services/TwilioVerifyService.php`
- `routes/api.php`
- `database/migrations/2026_10_19_000001_add_seller_fields_to_users_table.php`
- `database/migrations/2026_10_19_000002_create_password_reset_otps_table.php`

If your project already has a customized User model, merge the `HasApiTokens` trait, fillable fields and casts instead of replacing it. If you already have seller fields, edit the migration to avoid duplicate columns. Laravel's default users migration should run before the `add_seller_fields...` migration.

## 4. Run
```bash
php artisan migrate
php artisan route:list --path=api
php artisan serve
```
Base URL: `http://127.0.0.1:8000/api`

## 5. API endpoints

### Register seller
`POST /api/register`
```json
{
  "seller_name": "Example Seller",
  "phone": "+919876543210",
  "address": "Shop address",
  "landmark": "Near bus stop",
  "state": "Kerala",
  "country": "India",
  "password": "StrongPass123!",
  "password_confirmation": "StrongPass123!"
}
```
Phone must be in international E.164 format (for India, e.g. +919876543210). Response includes token.

### Login
`POST /api/login`
```json
{
  "phone": "+919876543210",
  "password": "StrongPass123!"
}
```

### Request password reset SMS
`POST /api/forgot-password`
```json
{ "phone": "+919876543210" }
```
This sends an SMS using Twilio Verify. For privacy, the API responds with a generic message whether or not that phone exists.

### Verify OTP
`POST /api/verify-otp`
```json
{
  "phone": "+919876543210",
  "code": "123456"
}
```
On successful verification it returns a short-lived reset token.

### Reset password
`POST /api/reset-password`
```json
{
  "phone": "+919876543210",
  "reset_token": "TOKEN_FROM_VERIFY_RESPONSE",
  "password": "NewStrongPass123!",
  "password_confirmation": "NewStrongPass123!"
}
```
The reset token expires in 10 minutes and can only be used once.

### Current profile
`GET /api/me` with `Authorization: Bearer TOKEN`

### Logout
`POST /api/logout` with `Authorization: Bearer TOKEN`

## 6. SMS configuration
This implementation uses Twilio Verify (SMS OTP). Create a Verify Service in Twilio Console and set the service SID in `.env`. Ensure your Twilio account is allowed to send SMS to your target country. Trial accounts may only send to verified recipient numbers. Twilio SMS charges apply.

## Security notes
- Passwords are hashed.
- OTP generation, sending and verification are handled by Twilio Verify; your application does not store the OTP itself.
- Reset token is stored hashed and expires after 10 minutes.
- Rate limits are applied to auth/OTP endpoints.
- Configure production HTTPS, CORS, monitoring and tests before launch.


## Compatibility note
This package keeps Laravel’s existing `password_reset_tokens` table for email-based resets and uses a separate `password_reset_otps` table for phone-based reset tokens. Since the default `users.email` column is required and unique, phone-only sellers receive a unique internal placeholder email ending in `@phone.venter.local`; they should continue to log in with their phone number.
