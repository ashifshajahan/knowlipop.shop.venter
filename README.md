# Vendor App Backend (Pure PHP & Node.js Dual Engine)

A high-performance REST API and Admin Dashboard designed specifically for **Hostinger Production** (`https://knowlipop.shop`).
Configured exclusively for the main production host https://knowlipop.shop without any external framework dependencies.

---

## Architecture & Technology Stack

1. **Dual Engine Execution**:
   - **Node.js (Express)**: Provides ultra-fast JSON REST API endpoints, JWT token verification, multi-vendor order routing, and automated admin provisioning.
   - **Pure Native PHP**: Provides fallback routing via `.htaccess` and `index.php` with native PDO MySQL connections.
2. **Database Engine**:
   - MySQL running directly on Hostinger internal socket (`127.0.0.1:3306`).
   - Production Database: `u999617803_knowlipop1`
3. **Authentication & Authorization**:
   - JSON Web Tokens (JWT) for sellers (`/api/login`, `/api/register`, `/api/me`, `/api/seller/orders`, `/api/seller/products`).
   - Password reset via 6-digit OTP codes stored securely in `password_reset_otps`.
   - Strict role-based & seller ownership authorization on all catalog and order endpoints.
4. **Admin Dashboard**:
   - Accessible at `https://knowlipop.shop/admin`
   - Default credentials: `admin@vendor.app` / `Admin123!`

---

## Multi-Vendor Order Routing & Seller Isolation

The platform features built-in multi-vendor marketplace routing and privacy isolation:

1. **Order Item Storage (`order_items`)**:
   - Every order line item explicitly stores `product_id` and `seller_id`.
   - Indexes: `idx_order_items_product` and `idx_order_items_seller`.
2. **Server-Side Auto-Routing**:
   - When an order is placed (`POST /api/orders`), the backend automatically resolves and assigns each item's `seller_id` directly from `products.seller_id` in the database, preventing client-side spoofing.
3. **Multi-Vendor Isolation**:
   - Orders containing products from multiple sellers are automatically partitioned.
   - When Seller A queries order details, the response strips out products belonging to Seller B.
   - Seller-specific financials (`seller_subtotal`, `subtotal`, `items_count`, `total_quantity`) are calculated strictly for Seller A's items.
4. **Strict Authorization & Access Control**:
   - **401 Unauthorized**: Missing or invalid Bearer token.
   - **403 Forbidden**: If a seller accesses an order where they own 0 items (`"Access denied. You do not own any products in this order."`).
   - **403 Forbidden**: If a seller attempts to query another seller's orders (`/api/sellers/{other_id}/orders`).
   - **403 Forbidden**: If a seller attempts to edit or delete another seller's product (`"Access denied. You do not own this product."`).
   - Admin users retain global cross-seller visibility.

---

## Available Endpoints

### System & Health
* `GET /` — Backend status card
* `GET /api/health` — Hostinger MySQL connectivity check

### Authentication
* `POST /api/register` — Seller registration (requires `seller_name`, `address`, `password`, and either `email` or `phone` — both or single `identifier`/`email_or_phone` field also accepted; returns JWT Bearer token)
* `POST /api/login` — Seller login (requires `email` or `phone` — both or single `identifier`/`login`/`email_or_phone` field also accepted — plus `password`; returns JWT Bearer token)
* `GET /api/me` — Authenticated seller profile (`Authorization: Bearer <token>`)
* `PUT /api/me` — Edit authenticated seller profile
* `POST /api/logout` — Invalidate session

### Seller Profile & Directory
* `GET /api/seller/profile` — Seller profile details based on Seller ID (`?seller_id={id}` or `?id={id}`) or Bearer token (contains: `id`, `seller_name`, `email`, `phone`, `address`, `landmark`, `state`, `country`)
* `PUT /api/seller/profile` — Edit authenticated seller profile (shop name, email, phone, address, landmark, state, country; `id` cannot be changed)
* `POST /api/seller/profile/update` — Convenient POST alias for editing profile
* `POST /api/seller/change-password` — Separate password update endpoint requiring `current_password`, `new_password`, and `confirm_password`
* `GET /api/sellers/{id}` — Seller profile based on Seller ID (e.g. `/api/sellers/1` or `/api/seller/1` or `/api/seller/profile/1`) — returns seller details only
* `PUT /api/sellers/{id}` — Edit seller profile by Seller ID (authorized for the seller or admin; `id` cannot be changed)
* `GET /api/sellers` — Paginated directory of marketplace sellers with search filter (`?page=1&limit=20&search=keyword`)

### Products Catalog & CRUD
* `GET /api/products` — Public catalog with pagination, rich details, and seller information
* `GET /api/seller/products` or `GET /api/sellers/{seller_id}/products` — Dedicated authenticated seller catalog (filters to only the authenticated seller's products)
* `POST /api/products` — Create product (`Authorization: Bearer <token>`) — supports `multipart/form-data` image file upload (`image`), Base64 image data, or image URL (uploaded & Base64 product images of any format — JPG, PNG, GIF, BMP, TIFF, AVIF, HEIC/HEIF, SVG, ICO, WEBP — are automatically converted and stored in `.webp` format)
* `POST /api/upload` — Dedicated product image upload endpoint (`Authorization: Bearer <token>`, converts uploaded & Base64 images to `.webp`)
* `GET /api/products/{id}` — Single product details including seller details
* `PUT /api/products/{id}` — Update product (`Authorization: Bearer <token>`) — protected by seller ownership check (403 if unauthorized)
* `DELETE /api/products/{id}` — Delete product (`Authorization: Bearer <token>`) — protected by seller ownership check (403 if unauthorized)

### Password Reset Flow & Firebase Email / Phone Gateway
* `GET /test-firebase` or `GET /firebase-otp` — Interactive Firebase Email & Phone Password Reset Gateway tester UI
* `POST /api/forgot-password` (or `/api/auth/forgot-password`) — Request password reset OTP / Firebase link using either `email` or `phone` (or single `identifier`/`email_or_phone` field) via Firebase Gateway / Fast2SMS
* `POST /api/verify-otp` (or `/api/auth/verify-otp`) — Verify reset using `code`/`otp`, Firebase `id_token`, or Firebase email `oob_code` for either `email` or `phone` and receive `reset_token`
* `POST /api/reset-password` (or `/api/auth/reset-password`) — Set new password using `email` or `phone` with verified `reset_token` (or Firebase `oob_code` / `id_token`)

### Orders, Multi-Vendor Routing & Invoices
* `GET /test-buyer` or `GET /buyer` — Interactive Buyer Checkout, Quantity Increments, Order Details & Order Editing Hub
* `POST /api/orders` — Place order with items array, buyer info, delivery address. Automatically routes each item's `seller_id` based on `products.seller_id`
* `GET /api/seller/orders` — Dedicated authenticated seller orders list (`Authorization: Bearer <token>`). Returns only orders containing the seller's items and recalculates totals
* `GET /api/sellers/{seller_id}/orders` — Seller orders by `seller_id` parameter (authorized for that seller or admin)
* `GET /api/seller/orders/{order_id}` — Single order details for authenticated seller (`Authorization: Bearer <token>`). Isolates items to the seller's products (403 if seller owns no items)
* `GET /api/sellers/{seller_id}/orders/{order_id}` — Route alias for seller order details
* `GET /api/orders` — List buyer orders (supports pagination, search by phone/name/order_id, filter by status). Automatically redirects authenticated sellers to their isolated orders
* `GET /api/orders/{id}` — Order details by ID (automatically enforces seller isolation if accessed by a seller)
* `PUT /api/orders/{id}` — Edit order (update items list, quantities, delivery address & status; ownership verified)
* `DELETE /api/orders/{id}` — Cancel / remove order (ownership verified)
* `GET /invoice/{id}` or `GET /api/orders/{id}/invoice` — Downloadable Provider Tax Invoice (supports print-to-PDF, `?download=1` attachment download, and `?format=json`). Multi-vendor isolated when accessed with seller token

### Seller Bank & Commission
* `POST /api/seller/bank-account/onboarding` — Store seller payment account reference
* `GET /api/seller/bank-account/status` — Payment account status
* `POST /api/marketplace/commission-preview` — Calculate platform fee and net payout
* `GET /api/seller/payouts` — Payout history

### Admin Panel
* `GET /admin/login` — Administrator login page
* `POST /admin/login` — Sign in with email and password (mints `admin_token` JWT cookie)
* `GET /admin` — Administrator dashboard
* `GET /admin/seller-deletions` — **Seller Management → Seller Deletion Requests** workspace (Preflight metrics, 6-gate checklist, Rules A–E verification, Razorpay refund runner, financial settlement, Approve/Hold/Reject, 2-step Final Delete, Activity History)

---

## Seller Account Deletion, Refund & Settlement System

A complete, multi-stage deletion workflow implemented identically across both **Node.js (Express)** (`helpers/seller_deletion.js`, `routes/seller_deletion_routes.js`) and **Pure PHP** (`helpers/seller_deletion.php`, `api/seller_deletion.php`) with MySQL transactional row locking (`FOR UPDATE`) and strict preservation of order and financial history.

### Deletion Stages & Status Badges
* **Stages**: `DELETION_REQUESTED` → `ACCOUNT_LOCKED` → `ORDER_VERIFICATION` → `REFUND_PROCESSING` → `FINANCIAL_SETTLEMENT` → `ADMIN_REVIEW` → `APPROVED_FOR_DELETION` → `DELETED`
* **Additional Statuses**: `DELETION_REJECTED`, `DELETION_ON_HOLD`, `REFUND_FAILED`, `SETTLEMENT_PENDING`
* **Color Mapping**: **PENDING** (Yellow), **PROCESSING** (Blue), **SUCCESS** (Green), **FAILED** (Red), **ON HOLD** (Orange)

### Order Verification Rules (A–E)
* **Rule A (`pending`)**: Blocks further seller processing (`403`), auto-cancels during order verification, restores stock, queues Razorpay refund if paid, and notifies buyer.
* **Rule B (`accepted` / `processing` / `packed`)**: Requires explicit Admin cancellation approval (`approve_accepted_cancellations` or `approve_cancellation_order_ids`), restores stock, and queues eligible buyer refund.
* **Rule C (`shipped`)**: Never auto-cancelled/refunded; blocks deletion until delivery/return or dispute resolution.
* **Rule D (`delivered`)**: Holds deletion until `RETURN_WINDOW_DAYS` (default `7`) elapses and open complaints/disputes reach `0`.
* **Rule E (`cancelled` / `rejected`)**: Audits refund delivery and queues any outstanding eligible refund.

### Automatic Razorpay Refund & Financial Settlement
* Uses `RAZORPAY_KEY_ID`, `RAZORPAY_KEY_SECRET`, `RAZORPAY_API_BASE_URL`, `MARKETPLACE_COMMISSION_RATE` (`0.05`), and `RETURN_WINDOW_DAYS` (`7`).
* Enforces unique `idempotency_key` (`X-Razorpay-Idempotency`), proportional multi-vendor refund calculation capped at `captured_amount - refunded_amount`, and authentic Razorpay confirmation (`status === 'processed'`) before marking `REFUND_SUCCESS`.
* Missing gateway payment ID routes to `REFUND_REQUIRES_REVIEW`; gateway errors set `REFUND_FAILED` and block deletion.
* Prevents double payouts and blocks payout release while unresolved orders, pending/failed refunds, unsettled gateway captures, or open disputes remain.

### Admin Deletion API Endpoints (`Authorization: Bearer <admin_token>`)
* `GET /api/admin/seller-deletions/preflight/:seller_id` — Live seller details + 13+ metrics (products, A–E order breakdown, successful payments, pending refunds, wallet balance, pending payouts, commissions, disputes)
* `POST /api/admin/seller-deletions` — Create deletion request, set `users.account_status = 'DELETION_PENDING'`, block new buyer orders, notify seller
* `GET /api/admin/seller-deletions` & `GET /api/admin/seller-deletions/:id` — Filtered request list & full review workspace payload
* `POST /api/admin/seller-deletions/:id/lock` — Lock seller account (`LOCKED`) and revoke seller mutations
* `POST /api/admin/seller-deletions/:id/verify-orders` — Execute Rules A–E verification & queue eligible refunds
* `GET /api/admin/seller-deletions/:id/refunds` & `POST /api/admin/seller-deletions/:id/process-refunds` — Track & execute idempotent Razorpay refunds
* `POST /api/admin/seller-deletions/:id/verify-settlement` & `POST /api/admin/seller-deletions/:id/settle-payout` — Audit financial obligations & safely release payouts
* `POST /api/admin/seller-deletions/:id/resolve-dispute` — Resolve order disputes/complaints & close return window
* `POST /api/admin/seller-deletions/:id/approve` — Validate all 6 checks `SUCCESS` & issue single-use `confirmation_token`
* `POST /api/admin/seller-deletions/:id/hold` & `POST /api/admin/seller-deletions/:id/reject` — Put on hold or reject & restore `ACTIVE`
* `POST /api/admin/seller-deletions/:id/final-delete` — Validate `confirmation_token` + exact phrase `"DELETE SELLER #<seller_id>"`, soft-delete seller (`DELETED`), bump `token_version` (revoke JWTs), clear tokens, hide products (`is_active = 0`), preserve all historical records
* `POST /api/orders/:id/payment-verify` — Record/verify Razorpay payment capture & settlement state
* `POST /api/orders/:id/disputes` — Record buyer complaints & disputes on an order (viewed in Deletion Workspace `GET /api/admin/seller-deletions/:id`)

---

## Automated Verification & Test Suites

Both backend engines include comprehensive automated test suites:

```bash
# 1. Node.js Express End-to-End Multi-Vendor Suite
node tests/test_e2e_node.js

# 2. Pure PHP End-to-End Multi-Vendor Suite
php tests/test_php_multi_vendor.php

# 3. Node.js Seller Deletion & Refund 11-Scenario Suite
node tests/test_seller_deletion_node.js

# 4. Pure PHP Seller Deletion & Refund 11-Scenario Suite
php tests/test_seller_deletion_php.php

# 5. API Cleanliness & Postman JSON Validation
node tests/test_response_cleanliness.js
node tests/validate_postman_json.js
```

All 38 integration assertions verify:
1. Product ID and Seller ID persistence on every line item in `order_items`.
2. Automatic routing of items based on product ownership.
3. Multi-vendor order isolation (sellers only see their own items and financials).
4. Dedicated seller-specific order list & detail retrieval endpoints.
5. Strict cross-seller 403 Forbidden enforcement for orders and catalog items.
6. Unauthenticated 401 Unauthorized enforcement.

---

## Postman Testing
The updated collections with full multi-vendor and seller isolation requests are available in the repository root:
* `Vendor_App_Postman_Collection.json`
* `Vendor_App_API.postman_collection.json`
* `Vendor_App_Environment.postman_environment.json`
