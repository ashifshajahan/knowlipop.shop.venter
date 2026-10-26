# Venter Admin Panel (Laravel 12)

## Included
- Browser admin sign-in at `/admin/login`
- Admin-only dashboard at `/admin`
- Create seller accounts with seller name, international phone number, address, landmark, state, country and password confirmation
- Seller list and total count
- CSRF protection, validation, hashed passwords, session regeneration, login throttling

## Setup (Windows / XAMPP)
1. Open a terminal in the Laravel project folder (the folder containing `artisan`).
2. Make sure `.env` exists and has your local MySQL settings. Never share `.env` or passwords.
3. Run:
   ```powershell
   php artisan migrate
   php artisan optimize:clear
   ```
4. Create the first admin account. Start Tinker:
   ```powershell
   php artisan tinker
   ```
   Paste the following PHP statements one by one, changing the email and password to your own private values:
   ```php
   $admin = new \App\Models\User();
   $admin->name = 'Venter Admin';
   $admin->email = 'admin@example.com';
   $admin->password = \Illuminate\Support\Facades\Hash::make('CHANGE-THIS-TO-A-STRONG-PASSWORD');
   $admin->role = 'admin';
   $admin->save();
   exit
   ```
   Use a real email format and a strong unique password. Do not use the example password in production.
5. Start Laravel:
   ```powershell
   php artisan serve
   ```
6. Open `http://127.0.0.1:8000/admin/login`, sign in, and create sellers from the dashboard.

## Important
- Run `php artisan migrate` before first use so the `role` column exists.
- Existing accounts default to `seller`; promote an existing trusted account only through Tinker if necessary.
- The admin dashboard is a server-rendered Blade page, separate from the seller/mobile API. Seller accounts created here can use the existing phone/password API login.
- Use HTTPS, a secure admin password, backups and production environment settings before deployment. Do not expose debug mode or use local development server for production.
