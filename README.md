# Vendor App Backend (Pure Native PHP & MySQL)

A lightweight, high-performance REST API and Admin Dashboard with **zero dependencies**. 
Works 100% out of the box on **Hostinger**, **cPanel**, or any standard PHP host (PHP 7.4 - 8.3+).

---

## Features
- **Zero Dependencies**: No Composer, no `vendor/`, no Node.js, no build scripts required on server.
- **REST API**:
  - `GET /api/health` — Health check
  - `POST /api/register` — Seller registration
  - `POST /api/login` — Seller login (returns Bearer token)
  - `GET /api/me` — Authenticated seller profile
  - `POST /api/logout` — Revoke token
  - `POST /api/forgot-password`, `POST /api/verify-otp`, `POST /api/reset-password`
  - `GET /api/products` — Public catalog with pagination
  - `GET /api/products/{id}` — Public product detail
  - `POST /api/products` — Create product (seller only)
  - `PUT /api/products/{id}` — Update product (seller only)
  - `DELETE /api/products/{id}` — Delete product (seller only)
  - `POST /api/seller/bank-account/onboarding`
  - `GET /api/seller/bank-account/status`
- **Web Admin Dashboard**:
  - `GET /admin/login` — Sign-in page
  - `GET /admin` — Dashboard with seller counts, seller creation form, and seller table

---

## Hostinger / Shared Hosting Deployment

### 1. Upload files
Upload all project files directly to your **`public_html`** folder (via Hostinger Git or File Manager).

### 2. Configure Database
Open `config.php` and set your MySQL credentials:
```php
'db' => [
    'host' => 'localhost', // or 127.0.0.1
    'port' => '3306',
    'name' => 'your_database_name',
    'user' => 'your_database_user',
    'pass' => 'your_database_password',
]
```

### 3. Import Database
In Hostinger phpMyAdmin:
- Select your database &rarr; click **Import** &rarr; choose `database/schema.sql` &rarr; click **Go**.

### 4. Admin Access
- Open `https://yourdomain.com/login`
- Default admin email: `admin@vendor.app` (or `admin@venter.app`)
- Default password: `Admin123!`
