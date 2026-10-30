# Vendor App Backend (Pure PHP & Node.js Dual Engine)

A high-performance REST API and Admin Dashboard designed specifically for **Hostinger Production** (`https://knowlipop.shop`).
Completely free of Laravel framework dependencies, XAMPP, or localhost setups.

---

## Architecture & Technology Stack

1. **Dual Engine Execution**:
   - **Node.js (Express)**: Provides ultra-fast JSON REST API endpoints, JWT token verification, and automated admin provisioning.
   - **Pure Native PHP**: Provides fallback routing via `.htaccess` and `index.php` with native PDO MySQL connections.
2. **Database Engine**:
   - MySQL running directly on Hostinger internal socket (`127.0.0.1:3306`).
   - Production Database: `u999617803_knowlipop1`
3. **Authentication**:
   - JSON Web Tokens (JWT) for sellers (`/api/login`, `/api/register`, `/api/me`, `/api/products`).
   - Password reset via 6-digit OTP codes stored securely in `password_reset_otps`.
4. **Admin Dashboard**:
   - Accessible at `https://knowlipop.shop/admin`
   - Default credentials: `admin@vendor.app` / `Admin123!`

---

## Available Endpoints

### System & Health
* `GET /` — Backend status card
* `GET /api/health` — Hostinger MySQL connectivity check

### Authentication
* `POST /api/register` — Seller registration (returns JWT Bearer token)
* `POST /api/login` — Seller login (returns JWT Bearer token)
* `GET /api/me` — Authenticated seller profile (`Authorization: Bearer <token>`)
* `PUT /api/me` — Edit authenticated seller profile
* `POST /api/logout` — Invalidate session

### Seller Profile & Directory
* `GET /api/seller/profile` — Authenticated seller profile with products count & payment account info (`Authorization: Bearer <token>`)
* `PUT /api/seller/profile` — Edit authenticated seller profile (shop name, address, landmark, state, country, phone, email, password, avatar image)
* `POST /api/seller/profile/update` — Convenient POST alias for editing profile (supports `multipart/form-data`, JSON, and Base64 avatar)
* `GET /api/sellers/{id}` — Seller profile & active products catalog by Seller ID (e.g. `/api/sellers/1` or `/api/seller/1`)
* `PUT /api/sellers/{id}` — Edit seller profile by Seller ID (authorized for the seller or admin)
* `GET /api/sellers` — Paginated directory of marketplace sellers with search filter (`?page=1&limit=20&search=keyword`)

### Products Catalog & CRUD
* `GET /api/products` — Public catalog with pagination, rich details, and seller information
* `POST /api/products` — Create product (`Authorization: Bearer <token>`) — supports `multipart/form-data` image file upload (`image`), Base64 image data, or image URL
* `POST /api/upload` — Dedicated image file upload endpoint (`Authorization: Bearer <token>`)
* `GET /api/products/{id}` — Single product details including seller details
* `PUT /api/products/{id}` — Update product (`Authorization: Bearer <token>`) — supports image file replacement
* `DELETE /api/products/{id}` — Delete product (`Authorization: Bearer <token>`)

### Password Reset Flow & Firebase Phone OTP Gateway
* `GET /test-firebase` or `GET /firebase-otp` — Interactive Firebase Phone Auth tester UI
* `POST /api/forgot-password` — Request 6-digit OTP via Firebase Gateway / Fast2SMS
* `POST /api/verify-otp` — Verify OTP (supports Firebase test code `000000`, client-side Firebase `id_token`, and local OTP)
* `POST /api/reset-password` — Set new password using reset token

### Seller Bank & Commission
* `POST /api/seller/bank-account/onboarding` — Store seller payment account reference
* `GET /api/seller/bank-account/status` — Payment account status
* `POST /api/marketplace/commission-preview` — Calculate platform fee and net payout
* `GET /api/seller/payouts` — Payout history

### Admin Panel
* `GET /admin/login` — Administrator login page
* `POST /admin/login` — Sign in with email and password
* `GET /admin` — Administrator dashboard

---

## Postman Testing
The complete collection with 40 automated test checks is available in the repository root:
* `Vendor_App_API.postman_collection.json`
* `Vendor_App_Environment.postman_environment.json`
