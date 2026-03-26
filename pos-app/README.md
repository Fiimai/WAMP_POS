# WAMP POS Application

A lightweight point-of-sale web application built with vanilla PHP and MySQL, designed for WAMP and similar Apache/PHP stacks.

## What This Project Includes

- POS checkout flow with cart and receipt generation
- Product and category management
- Inventory adjustments and movement history
- User and role management (admin, manager, cashier)
- Returns support and receipt history
- Time clock features
- Feature toggles in shop settings
- Audit logging
- Optional minimal customer trace fields at checkout (name/contact/note with consent)
- Optional Ghana NLP text translation endpoint

## Tech Stack

- PHP 8.1+
- MySQL 8+ or MariaDB 10.5+
- Apache (WAMP/LAMP compatible)
- Tailwind (bundled browser build in assets)

## Project Structure

```text
pos-app/
+-- app/                 # Core app classes (models, services, repositories, core)
+-- api/                 # AJAX/API endpoints
+-- assets/              # CSS, JS, vendor assets, images
+-- migrations/          # PHP migration definitions
+-- schema.sql           # Base schema
+-- seed.sql             # Starter data
+-- deploy.php           # Migration/deployment helper
+-- login.php            # Login page entry
+-- index.php            # Main application entry after auth
```

## Local Setup (WAMP)

1. Place this folder in your web root (example: C:\\wamp64\\www\\pos-app).
2. Start Apache and MySQL.
3. Create a database named pos_db.
4. Import database files in this order:

```bash
mysql -u root -p pos_db < schema.sql
mysql -u root -p pos_db < seed.sql
```

1. Open the app in browser:

- <http://localhost/pos-app/login.php>

## Database Configuration

Database settings are resolved in config.php. The app reads environment variables first and falls back to local defaults.

Supported variables:

- DB_HOST
- DB_PORT
- DB_NAME
- DB_USER
- DB_PASS
- DB_CHARSET

SMTP variables (optional, recommended for production):

- SMTP_HOST
- SMTP_PORT
- SMTP_USERNAME
- SMTP_PASSWORD
- SMTP_ENCRYPTION
- SMTP_FROM_ADDRESS
- SMTP_FROM_NAME

Example defaults are provided in .env.example.

If SMTP environment variables are set, they take precedence over values saved in Settings.

## Optional Translation Setup

To enable Ghana NLP runtime translation via api/translate_text.php, set:

- GHANANLP_API_KEY

If this key is not configured, the app still runs using built-in fallback translations.

## Seeded Users

The seed data creates the following users:

- admin
- cashier1

Known starter password from seed data:

- cashier1 / password

If needed, you can reset any user password to password with:

```sql
UPDATE users
SET password_hash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'
WHERE username = 'admin';
```

## Migrations and Deployment

To apply migration files in migrations/:

```bash
php deploy.php
```

For broader deployment guidance, see DEPLOYMENT.md.

## Security Notes

- Do not commit real secrets (use environment variables).
- Keep DB credentials out of source files in production.
- Restrict access to utility scripts in production environments.
- Enforce HTTPS on public deployments.

## Troubleshooting

- Login fails: verify schema.sql and seed.sql were imported into pos_db.
- DB connection errors: verify DB_* values and MySQL service status.
- Translation endpoint issues: verify GHANANLP_API_KEY and PHP cURL.

## License

This project currently has no explicit license file. Add one before public redistribution.
