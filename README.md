# POS System Architecture Blueprint (WAMP, Vanilla PHP, MVC)

This blueprint defines a scalable, WAMP-friendly Point of Sale (POS) web application using:

- **Windows** (host OS)
- **Apache** (web server)
- **MySQL** (database)
- **PHP** (vanilla, no heavy framework)

The structure below keeps compatibility high while enabling clean separation of concerns and long-term maintainability.

## Ghana NLP Translation Setup

This POS now supports Ghana NLP-backed runtime translations for supported Ghanaian language codes.

Required environment variable:

- `GHANANLP_API_KEY` = your Ghana NLP subscription key

API bridge endpoint used by the UI:

- `api/translate_text.php`

Notes:

- UI language codes are aligned to Ghana NLP codes: `tw`, `ee`, `gaa`, `fat`, `dag`, `gur`, `kus`.
- Legacy saved language values are auto-mapped: `twi -> tw`, `ewe -> ee`, `ga -> gaa`, `sehwi -> tw`.
- If `GHANANLP_API_KEY` is not set, the POS falls back to built-in local translations.

## Quick Setup Guide

Use this section for a real first run of the current project.

### Prerequisites

- PHP 8.1+
- MySQL 8+ or MariaDB
- Apache or Nginx
- cURL enabled in PHP

### Option A: Windows (WAMP)

1. Copy this project to your WAMP web root, for example:

`C:\wamp64\www\pos-app`

1. Start WAMP and ensure Apache + MySQL are running.

1. Create/import database in phpMyAdmin.

## Production Deployment

For production deployments and feature updates, see [DEPLOYMENT.md](DEPLOYMENT.md) for comprehensive deployment strategies including:

- Safe database migrations
- Zero-downtime deployment options
- Feature flag management
- Rollback procedures
- Gradual rollout strategies

### Quick Deployment (Development)

```bash
# 1. Run database migrations
php deploy.php

# 2. Copy files to web root
robocopy . C:\wamp64\www\pos-app /MIR /XD .git

# 3. Access the application
# http://localhost/pos-app/login.php
```

Import `schema.sql` first, then import `seed.sql`.

1. Open the app:

`http://localhost/pos-app/login.php`

### Option B: Linux (LAMP/LEMP)

1. Place project in your web directory, for example:

`/var/www/pos-app`

1. Ensure web user can read project files and write cache/session folders if needed.

1. Create/import database:

```bash
mysql -u root -p < schema.sql
mysql -u root -p < seed.sql
```

1. Serve through Apache/Nginx and open `/login.php`.

### Database Configuration

The app reads DB values from environment variables and falls back to local defaults in `config.php`.

Local fallback defaults:

- DB host: `127.0.0.1`
- DB port: `3306`
- DB name: `pos_db`
- DB user: `root`
- DB pass: empty

Optional explicit environment variables:

- `DB_HOST`
- `DB_PORT`
- `DB_NAME`
- `DB_USER`
- `DB_PASS`
- `DB_CHARSET`

### First Login

Seed users are created by `seed.sql`:

- Username: `admin` (admin role)
- Username: `cashier1` (cashier role)

If you cannot log in with seeded credentials, reset an account password directly in MySQL to `password` using this known bcrypt hash from the seed data:

```sql
UPDATE users
SET password_hash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'
WHERE username = 'admin';
```

Then sign in with:

- Username: `admin`
- Password: `password`

### Ghana NLP Translation Runtime

To enable live Ghana NLP translation calls from the POS UI, set:

- `GHANANLP_API_KEY`

Endpoints used:

- `https://translation-api.ghananlp.org/v1/languages`
- `https://translation-api.ghananlp.org/v1/translate`

If the key is missing or API is unavailable, the UI keeps working with built-in translation fallbacks.

### Troubleshooting

1. Blank/500 page on startup:

Confirm `schema.sql` and `seed.sql` were imported into `pos_db`, and confirm MySQL is running with matching DB credentials.

1. Login always fails:

Reset password using the SQL snippet above.

1. Ghana NLP translation not changing labels:

Ensure `GHANANLP_API_KEY` is set in your server environment and PHP cURL extension is enabled.

1. API throttling errors:

Wait for the rate limiter window to reset (translation endpoint is rate limited).

### Production Hardening Checklist

1. Enforce HTTPS in front of the app.

Use TLS certificates and redirect all HTTP traffic to HTTPS.

1. Set all required production DB environment variables.

Set `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, and keep secrets out of source control.

1. Use a least-privilege MySQL user.

Grant only required permissions on the POS database, avoid root for production runtime.

1. Configure secure PHP session behavior.

Keep secure cookies enabled, use `HttpOnly`, and `SameSite=Lax` or stricter.

1. Disable verbose error display in production.

Use server logs for diagnostics, do not expose stack traces to users.

1. Restrict file and directory permissions.

Allow write access only where necessary (for example cache/session directories).

1. Protect access to admin-only routes.

Create strong admin credentials, disable unused accounts, and review role assignments regularly.

1. Configure translation service secret.

Set `GHANANLP_API_KEY` only in environment variables, never commit it to repository files.

1. Add backup and restore routine.

Automate daily database backups and test a restore procedure at least once per month.

1. Monitor audit and security signals.

Review login failures, rate-limit events, and audit logs for unusual activity.

## 1) Recommended Directory Structure

```text
pos-app/
├── app/                          # Application source (not directly public)
│   ├── Controllers/              # HTTP request handlers (MVC Controller layer)
│   │   ├── AuthController.php
│   │   ├── ProductController.php
│   │   ├── SaleController.php
│   │   ├── CustomerController.php
│   │   └── ReportController.php
│   │
│   ├── Models/                   # Domain + DB interaction (MVC Model layer)
│   │   ├── User.php
│   │   ├── Product.php
│   │   ├── Sale.php
│   │   ├── SaleItem.php
│   │   ├── Customer.php
│   │   ├── InventoryMovement.php
│   │   └── Payment.php
│   │
│   ├── Views/                    # Server-rendered UI templates (MVC View layer)
│   │   ├── layouts/
│   │   │   ├── main.php
│   │   │   └── auth.php
│   │   ├── auth/
│   │   ├── dashboard/
│   │   ├── products/
│   │   ├── sales/
│   │   ├── customers/
│   │   └── reports/
│   │
│   ├── Services/                 # Business logic orchestration
│   │   ├── CartService.php
│   │   ├── PricingService.php
│   │   ├── InventoryService.php
│   │   ├── PaymentService.php
│   │   └── ReceiptService.php
│   │
│   ├── Repositories/             # Data access abstraction (PDO queries)
│   │   ├── ProductRepository.php
│   │   ├── SaleRepository.php
│   │   ├── CustomerRepository.php
│   │   └── UserRepository.php
│   │
│   ├── Middleware/               # Request guards and cross-cutting rules
│   │   ├── AuthMiddleware.php
│   │   ├── RoleMiddleware.php
│   │   └── CsrfMiddleware.php
│   │
│   ├── Validators/               # Input validation and sanitization
│   │   ├── SaleValidator.php
│   │   └── ProductValidator.php
│   │
│   ├── Core/                     # Framework-like lightweight kernel
│   │   ├── Application.php       # Bootstrap app + run lifecycle
│   │   ├── Router.php            # Route registration + dispatch
│   │   ├── Controller.php        # Base controller
│   │   ├── Model.php             # Base model
│   │   ├── View.php              # View renderer helper
│   │   ├── Request.php           # HTTP request wrapper
│   │   ├── Response.php          # HTTP response helper
│   │   ├── Session.php           # Session abstraction
│   │   ├── Database.php          # PDO connection manager
│   │   ├── Auth.php              # Authentication helper
│   │   ├── Validator.php         # Shared validation utility
│   │   └── Exceptions/
│   │       ├── HttpException.php
│   │       └── ValidationException.php
│   │
│   ├── Helpers/                  # Stateless utility functions
│   │   ├── money.php
│   │   ├── date.php
│   │   └── security.php
│   │
│   └── Config/                   # App config maps (resolved from env)
│       ├── app.php
│       ├── database.php
│       ├── session.php
│       └── features.php
│
├── bootstrap/                    # Startup and bootstrapping scripts
│   ├── app.php                   # Creates Application instance
│   ├── autoload.php              # PSR-4 autoload (Composer recommended)
│   └── env.php                   # Loads .env values safely
│
├── public/                       # Apache document root (ONLY public folder)
│   ├── index.php                 # Front controller entry point
│   ├── .htaccess                 # Rewrite to index.php
│   ├── assets/
│   │   ├── css/
│   │   │   ├── app.css
│   │   │   └── pos-theme.css
│   │   ├── js/
│   │   │   ├── app.js
│   │   │   ├── api-client.js
│   │   │   ├── cart.js
│   │   │   └── ui-interactions.js
│   │   ├── img/
│   │   └── fonts/
│   └── uploads/                  # Runtime uploads (secure write permissions)
│
├── routes/                       # Route registration by context
│   ├── web.php                   # Browser pages
│   ├── api.php                   # AJAX endpoints for async UX
│   └── cli.php                   # Optional CLI tasks
│
├── database/
│   ├── migrations/               # Versioned schema migration files
│   │   ├── 20260317_000001_create_users_table.php
│   │   ├── 20260317_000002_create_products_table.php
│   │   ├── 20260317_000003_create_sales_tables.php
│   │   └── 20260317_000004_create_inventory_tables.php
│   ├── seeders/                  # Initial data for dev/staging
│   │   ├── UserSeeder.php
│   │   ├── ProductSeeder.php
│   │   └── PaymentMethodSeeder.php
│   └── schema/                   # Optional SQL snapshots and diagrams
│       ├── baseline.sql
│       └── erd.md
│
├── storage/                      # App runtime writable files
│   ├── logs/
│   │   └── app.log
│   ├── cache/
│   ├── sessions/
│   └── receipts/                 # Generated receipt artifacts
│
├── tests/                        # Basic test harness (unit/integration)
│   ├── Unit/
│   ├── Integration/
│   └── bootstrap.php
│
├── scripts/                      # DevOps and utility scripts
│   ├── migrate.php
│   ├── seed.php
│   └── backup_db.ps1
│
├── vendor/                       # Composer dependencies (generated)
├── .env                          # Environment variables (never commit secrets)
├── .env.example                  # Safe template for teammates
├── .gitignore
├── composer.json
└── README.md
```

## 2) Why This Structure Works for WAMP

- **Vanilla PHP compatibility:** No framework lock-in; works on common shared/WAMP deployments.
- **Single public entry point:** Apache serves `public/` only, reducing accidental exposure of app internals.
- **MVC boundaries:** Keeps controllers thin, business rules in services, and persistence in repositories/models.
- **Scalable growth path:** You can later add queue workers, microservices, or an SPA frontend without rewriting core domains.

## 3) Request Lifecycle (High-Level)

1. Apache routes requests to `public/index.php`.
2. `bootstrap/app.php` initializes config, env, session, DB, and router.
3. Router matches route from `routes/web.php` or `routes/api.php`.
4. Middleware runs (auth, role, CSRF).
5. Controller delegates business logic to Services.
6. Services call Repositories/Models.
7. Response is rendered via Views (HTML) or JSON (API for async UI).

## 4) WAMP Configuration Notes

- Set Apache `DocumentRoot` to `.../pos-app/public`.
- Enable `mod_rewrite` and allow overrides for `.htaccess`.
- Use PDO with prepared statements for MySQL.
- Store runtime writable directories in `storage/` and lock permissions.
- Keep secrets in `.env`; commit only `.env.example`.

Example `.htaccess` in `public/.htaccess`:

```apacheconf
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [QSA,L]
```

## 5) POS Module Boundaries

- **Auth & Roles:** cashier, supervisor, admin.
- **Catalog:** products, categories, pricing tiers, barcode support.
- **Sales:** cart, tax, discounts, split payments, receipt generation.
- **Inventory:** stock in/out, adjustments, low-stock alerts.
- **Customers:** profiles, purchase history, loyalty.
- **Reports:** daily sales, top products, payment breakdowns.

## 6) UI/UX-Ready Backend Design

To support a modern frontend experience while staying server-rendered:

- Expose AJAX-friendly endpoints under `routes/api.php`.
- Return consistent JSON envelopes (`success`, `message`, `data`, `errors`).
- Use optimistic UI updates for cart operations where appropriate.
- Keep key interactions async: product search, cart updates, stock checks, receipt preview.

## 7) Naming and Code Conventions

- Class names: `PascalCase`; methods/properties: `camelCase`.
- One class per file.
- Keep controllers thin; avoid embedding SQL in controllers.
- Prefer explicit service methods (`completeSale`, `reserveStock`) over generic CRUD in business-critical flows.

## 8) Suggested Next Build Steps

1. Implement `Core/Router.php`, `Core/Application.php`, and `public/index.php` first.
2. Create migration runner in `scripts/migrate.php` and initial migration files.
3. Build authentication flow (`AuthController`, `AuthMiddleware`).
4. Implement sales pipeline end-to-end (`CartService` -> `SaleService` -> receipt).
5. Add async API endpoints for cart and product search.

## 9) Current Starter Implementation

The workspace now includes a working starter of the next module:

- `index.php` renders products dynamically from MySQL using `app/Models/Product.php`.
- `app/Views/partials/` contains reusable view components for header, product grid, and cart sidebar.
- `api/cart.php` provides session-backed async cart actions (`get`, `add`, `remove`, `clear`).
- `seed.sql` provides sample users, categories, and products for a demo-ready checkout screen.

## 10) Quick Run Instructions (WAMP)

- Import `schema.sql` into MySQL.
- Import `seed.sql` into MySQL.
- Set environment variables (or system env): `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`.
- Point Apache site root to this project directory.
- Open `http://localhost/index.php`.

Authentication:

- Login page: `http://localhost/login.php`
- Local demo users from `seed.sql`:
  - username: `admin` / password: `admin`
  - username: `cashier1` / password: `password`

Security hardening currently active:

- Login lockout: max 8 failed attempts per 15 minutes per username+IP.
- API throttling:
  - search: 120 requests/minute per user+IP
  - cart: 240 requests/minute per user+IP
  - checkout: 20 requests/5 minutes per user+IP
- API rate limit responses return HTTP `429` and `Retry-After` header.

Shop customization:

- Admin-only settings page: `http://localhost/settings.php`
- Configurable fields:
  - store identity (name, address, phone, tax ID)
  - business tagline
  - store logo URL (for login/receipt branding)
  - category setup (add/activate/deactivate categories for each business type)
  - category bulk CSV onboarding in settings
  - receipt header/footer
  - currency code/symbol
  - tax rate (%)
  - theme accent colors
- Tax setting is used by live cart totals and transactional checkout.

Bulk onboarding tools:

- Categories bulk import: `settings.php`
  - Upload CSV with headers: `name, slug, is_active` (required: `name`)
  - Existing slug rows are updated (status), new rows are created.
- Users bulk import: `manage_users.php`
  - Upload CSV with headers: `full_name, username, email, password, role, is_active`
- Products bulk import: `manage_products.php`
  - Upload CSV with headers: `name, sku, category, unit_price, stock_qty, reorder_level, barcode, description, cost_price, is_active`
  - Import performs upsert by SKU so repeated imports can update records safely.

Admin product management:

- Admin-only product creation page: `http://localhost/add_product.php`
- Admin-only product maintenance page: `http://localhost/manage_products.php`
- Admin-only product edit page: `http://localhost/edit_product.php?id=PRODUCT_ID`
- Uses `ProductService` + `ProductRepository` with prepared statements.
- Includes product status toggles (activate/deactivate) and searchable catalog listing.

Instant cart UX (no page reload):

- Frontend module: `assets/js/cart.js`
- Compatibility endpoint: `api/add_to_cart.php` (form-encoded Fetch payload support)
- Checkout page now includes:
  - add-to-cart toast notifications (top-right)
  - 200ms cart indicator pop animation on successful add
  - polished empty-cart illustration/state message
  - API search by product name, barcode, SKU, and category

Atomic checkout flow:

- Checkout API: `api/checkout.php`
- Checkout transaction logic (single-unit DB commit/rollback + stock row locks): `app/Controllers/CartController.php`
- Frontend trigger module: `assets/js/checkout.js`
- Keyboard shortcuts on checkout screen:
  - `F9`: trigger checkout confirmation + processing
  - `F2`: focus/select product search input
- Product cards now show a red "Low stock" badge when stock is below 5.

Thermal receipt printing:

- Printable receipt page: `receipt.php?sale_id=SALE_ID`
- Auto-print mode: `receipt.php?sale_id=SALE_ID&print=1`
- Checkout success now attempts to open the receipt in a new window for immediate cashier printing.
- Receipt footer branding now follows your configured store name (`<SHOP_NAME> POS`) and supports optional logo/tagline display.

User management:

- Admin user directory: `manage_users.php`
- Add user: `add_user.php`
- Edit user + reset password: `edit_user.php?id=USER_ID`
- Supports role assignment (`admin`, `manager`, `cashier`) and account activation/deactivation.
- Uses capability checks for sensitive actions (`users.manage`).

Inventory controls:

- Manual stock adjustment page: `inventory_adjustments.php` (admin/manager)
- Writes movement history with qty delta, before/after stock, actor, and notes.
- Checkout now records inventory movement entries (`movement_type = sale`) within the same DB transaction as sale + stock update.
- Schema includes `inventory_movements` table for auditable stock history.
- Uses capability checks for sensitive actions (`inventory.adjust`).

Audit logging:

- Schema includes `audit_logs` table for immutable security and admin operation history.
- Logged events include login success/failure/rate-limit, logout, user lifecycle changes, inventory adjustments, and checkout completion.
- Audit writes are integrated in key flows via `app/Repositories/AuditLogRepository.php`.
- Admin audit viewer page: `audit_logs.php` with filters, pagination, and CSV export.

Permission model:

- Capability map is defined in `app/Core/Permissions.php`.
- Capability checks are enforced through `Auth::requireCapability()` and `Auth::hasCapability()`.

Critical action approval:

- User role/status changes and password resets require current admin password confirmation in `edit_user.php`.
- High-risk stock-out adjustments (`qty >= 20`) require typing `APPROVE` in `inventory_adjustments.php`.

Receipt history & reprint:

- Receipt History page: `receipt_history.php`
- Supports quick filters by receipt/sale/cashier text and date range.
- Includes quick date presets: Today, Last 7 Days, This Month.
- Supports pagination with configurable page size (20/50/100 rows).
- Supports CSV export for current filters (up to 5000 rows per export) for admin/manager roles.
- Each row provides `View` and `Print` actions that open the thermal receipt layout in a new tab/window.

If the database is not reachable, the UI shows a non-fatal warning banner instead of crashing.

## 11) Business Intelligence Dashboard

- Open `dashboard.php` to view:
  - today's total sales
  - average basket value
  - projected end-of-day sales
  - momentum vs yesterday (%)
  - today's top-selling products
  - low-stock alerts based on `reorder_level`
  - 7-day sales trend chart
  - smart operational insight cards (trend + inventory risk + pacing)
- Chart rendering uses Chart.js with custom gradient stroke/fill and hidden grid lines for a premium presentation.

### Existing Database Upgrade (for older installs)

If your database was created before branding upgrades, run this SQL once:

```sql
USE pos_db;

ALTER TABLE shop_settings
  ADD COLUMN IF NOT EXISTS shop_logo_url VARCHAR(255) NULL AFTER shop_name,
  ADD COLUMN IF NOT EXISTS business_tagline VARCHAR(160) NULL AFTER shop_logo_url;
```

## 12) Clean URLs and Auto Config

- `.htaccess` now routes non-file/non-folder requests through `index.php` for clean URL patterns.
- `.htaccess` also applies security headers (CSP, X-Frame-Options, nosniff, referrer policy) with a reduced CDN allowlist and disabled inline event-handler attributes.
- `config.php` auto-detects localhost/WAMP and sets suitable default DB credentials.
- Environment variables (`DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`) still override defaults.

## 13) Database Singleton Pattern

- Database access uses a Singleton PDO wrapper in `app/Core/Database.php`.
- Preferred usage:
  - `Database::getInstance()->getConnection()`
  - or compatibility helper `Database::connection()`
- `config.php` also defines `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, and `DB_CHARSET` constants from the resolved environment.

## 14) Example Modules

- Add Product module using prepared statements:
  - `app/Repositories/ProductRepository.php` (`create` method)
  - `app/Services/ProductService.php` (`addProduct` method)
- Catalog maintenance module using prepared statements:
  - `app/Repositories/ProductRepository.php` (`listAll`, `findById`, `update`, `setActive` methods)
  - `app/Services/ProductService.php` (`listProducts`, `getProduct`, `editProduct`, `deactivateProduct`, `activateProduct` methods)
- User login module using singleton DB + `password_verify()`:
  - `app/Services/UserAuthService.php` (`login` method)
  - `login.php` now delegates credential verification to `UserAuthService`.
