# Venter App Backend — updated from project documentation

Laravel 12 / PHP 8.2 / MySQL API only. No frontend pages are included.

## Important implementation status
- Implemented in this source tree: seller registration/login/logout/profile, Twilio Verify OTP flow (requires credentials), public product list/detail, authenticated seller product CRUD with ownership checks, health endpoint, seller payment-account reference/status storage, payment/commission database schema, commission preview when a rate is configured.
- Not implemented/verified: actual Razorpay Route account creation, payment/order checkout, transfers, payout processing, refunds, signed webhook processing, KYC status synchronization, production hosting, SMS delivery, high-concurrency capacity.
- The bank-account endpoint intentionally stores only a provider-issued account reference. It does not collect raw bank account numbers or IFSC and does not prove the linked account is real or verified.
- Commission preview is not a real charge or settlement. Leave `MARKETPLACE_COMMISSION_RATE` blank until the business owner approves a rate and calculation basis.
- A schema is not a functioning payment integration. Use Razorpay test mode and confirm Route eligibility/approval before implementing live money movement.

## Windows / XAMPP setup
1. Open a terminal in the folder containing `artisan`, normally `...\venter_app_laravel_backend\venter-app`.
2. Copy `.env.example` to `.env` if `.env` does not exist.
3. Create a MySQL database in phpMyAdmin, for example `venter_app`.
4. Set local `.env` values: `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`, `DB_PORT=3306`, `DB_DATABASE=venter_app`, `DB_USERNAME=root`, and your local `DB_PASSWORD`.
5. Run:
   ```powershell
   php -v
   composer install
   php artisan key:generate
   php artisan config:clear
   php artisan migrate
   php artisan route:list --path=api
   php artisan serve
   ```
   If this is an existing database, back it up first. Review migration status before running migrations.
6. Test `GET http://127.0.0.1:8000/api/health`. A 404 at `/` does not prove the API is broken.

## API endpoints
- `GET /api/health`
- `POST /api/register`
- `POST /api/login`
- `POST /api/forgot-password`
- `POST /api/verify-otp`
- `POST /api/reset-password`
- `GET /api/me` (Bearer token)
- `POST /api/logout` (Bearer token)
- `GET /api/products`, `GET /api/products/{id}`
- `POST /api/products`, `PUT/PATCH /api/products/{id}`, `DELETE /api/products/{id}` (Bearer token; seller ownership enforced)
- `POST /api/seller/bank-account/onboarding` (Bearer token; saves provider-issued reference only)
- `GET /api/seller/bank-account/status` (Bearer token)
- `GET /api/seller/payouts` (returns 501 until payout integration is built)
- `POST /api/marketplace/commission-preview` (Bearer token; minor currency units, only after commission rate configured)

Use `Authorization: Bearer YOUR_TOKEN` for protected routes. Never send credentials, OTPs, or tokens in screenshots, logs, documentation, or chat.

## Seller registration JSON
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

## Frontend CORS
Set `VENTER_FRONTEND_ORIGINS` to exact comma-separated origins, for example `http://localhost:3000,http://localhost:3001`. Do not add a trailing slash. After changing `.env`, run `php artisan config:clear`. For production, use only the real HTTPS frontend origin.

## Security and release checklist
- `APP_DEBUG=false` in production; HTTPS required.
- Configure Twilio Verify privately and test with an allowed test number.
- Configure Razorpay credentials only in server environment settings; verify signatures and idempotency before handling payment webhooks.
- Review the commission policy and provider fees/taxes with the business owner.
- Add automated tests, backups, monitoring, and staging load tests.
- The documented 500,000 concurrent-user target is not proven by this code. Use a scalable cloud design and distributed load testing; no server can guarantee zero crashes.
