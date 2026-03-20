# POS System Architecture Blueprint (WAMP, Vanilla PHP, MVC)

This blueprint defines a scalable, WAMP-friendly Point of Sale (POS) web application using:

- **Windows** (host OS)
- **Apache** (web server)
- **MySQL** (database)
- **PHP** (vanilla, no heavy framework)

The structure below keeps compatibility high while enabling clean separation of concerns and long-term maintainability.

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
  - receipt header/footer
  - currency code/symbol
  - tax rate (%)
  - theme accent colors
- Tax setting is used by live cart totals and transactional checkout.

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
  - today's top-selling products
  - low-stock alerts based on `reorder_level`
  - 7-day sales trend chart
- Chart rendering uses Chart.js with custom gradient stroke/fill and hidden grid lines for a premium presentation.

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
