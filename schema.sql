-- MySQL schema for a retail POS system
-- Target: MySQL 8+

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE DATABASE IF NOT EXISTS pos_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE pos_db;

-- Drop in dependency-safe order for re-runs in development
DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS inventory_movements;
DROP TABLE IF EXISTS sale_items;
DROP TABLE IF EXISTS sales;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS shop_settings;
DROP TABLE IF EXISTS users;

CREATE TABLE users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  full_name VARCHAR(120) NOT NULL,
  username VARCHAR(60) NOT NULL,
  email VARCHAR(190) NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin', 'manager', 'cashier') NOT NULL DEFAULT 'cashier',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role_active (role, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE categories (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  slug VARCHAR(120) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categories_name (name),
  UNIQUE KEY uq_categories_slug (slug),
  KEY idx_categories_active_name (is_active, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE shop_settings (
  id TINYINT UNSIGNED NOT NULL DEFAULT 1,
  shop_name VARCHAR(140) NOT NULL DEFAULT 'My Shop',
  shop_address VARCHAR(255) NULL,
  shop_phone VARCHAR(50) NULL,
  shop_tax_id VARCHAR(80) NULL,
  currency_code VARCHAR(10) NOT NULL DEFAULT 'USD',
  currency_symbol VARCHAR(10) NOT NULL DEFAULT '$',
  tax_rate_percent DECIMAL(5,2) NOT NULL DEFAULT 8.00,
  receipt_header VARCHAR(255) NULL,
  receipt_footer VARCHAR(255) NULL,
  theme_accent_primary VARCHAR(7) NOT NULL DEFAULT '#06B6D4',
  theme_accent_secondary VARCHAR(7) NOT NULL DEFAULT '#22D3AA',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT chk_shop_tax_rate_nonnegative CHECK (tax_rate_percent >= 0),
  CONSTRAINT chk_shop_primary_hex CHECK (theme_accent_primary REGEXP '^#[0-9A-Fa-f]{6}$'),
  CONSTRAINT chk_shop_secondary_hex CHECK (theme_accent_secondary REGEXP '^#[0-9A-Fa-f]{6}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE products (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  category_id BIGINT UNSIGNED NOT NULL,
  sku VARCHAR(64) NOT NULL,
  barcode VARCHAR(64) NULL,
  name VARCHAR(180) NOT NULL,
  description TEXT NULL,
  unit_price DECIMAL(12,2) NOT NULL,
  cost_price DECIMAL(12,2) NULL,
  stock_qty INT NOT NULL DEFAULT 0,
  reorder_level INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_products_sku (sku),
  UNIQUE KEY uq_products_barcode (barcode),
  KEY idx_products_name (name),
  KEY idx_products_category_active (category_id, is_active),
  KEY idx_products_stock_qty (stock_qty),
  KEY idx_products_barcode_name (barcode, name),
  CONSTRAINT fk_products_category
    FOREIGN KEY (category_id) REFERENCES categories(id)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,
  CONSTRAINT chk_products_unit_price_nonnegative CHECK (unit_price >= 0),
  CONSTRAINT chk_products_cost_price_nonnegative CHECK (cost_price IS NULL OR cost_price >= 0),
  CONSTRAINT chk_products_stock_nonnegative CHECK (stock_qty >= 0),
  CONSTRAINT chk_products_reorder_nonnegative CHECK (reorder_level >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sales (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  receipt_no VARCHAR(40) NOT NULL,
  cashier_user_id BIGINT UNSIGNED NOT NULL,
  sold_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  subtotal DECIMAL(12,2) NOT NULL,
  tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total_amount DECIMAL(12,2) NOT NULL,
  payment_method ENUM('cash', 'card', 'mobile', 'mixed') NOT NULL DEFAULT 'cash',
  notes VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sales_receipt_no (receipt_no),
  KEY idx_sales_sold_at (sold_at),
  KEY idx_sales_cashier_date (cashier_user_id, sold_at),
  CONSTRAINT fk_sales_cashier
    FOREIGN KEY (cashier_user_id) REFERENCES users(id)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,
  CONSTRAINT chk_sales_subtotal_nonnegative CHECK (subtotal >= 0),
  CONSTRAINT chk_sales_tax_nonnegative CHECK (tax_amount >= 0),
  CONSTRAINT chk_sales_discount_nonnegative CHECK (discount_amount >= 0),
  CONSTRAINT chk_sales_total_nonnegative CHECK (total_amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sale_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sale_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  qty INT NOT NULL,
  unit_price DECIMAL(12,2) NOT NULL,
  discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  line_total DECIMAL(12,2) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sale_items_sale_id (sale_id),
  KEY idx_sale_items_product_id (product_id),
  KEY idx_sale_items_sale_product (sale_id, product_id),
  CONSTRAINT fk_sale_items_sale
    FOREIGN KEY (sale_id) REFERENCES sales(id)
    ON UPDATE CASCADE
    ON DELETE CASCADE,
  CONSTRAINT fk_sale_items_product
    FOREIGN KEY (product_id) REFERENCES products(id)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,
  CONSTRAINT chk_sale_items_qty_positive CHECK (qty > 0),
  CONSTRAINT chk_sale_items_unit_price_nonnegative CHECK (unit_price >= 0),
  CONSTRAINT chk_sale_items_discount_nonnegative CHECK (discount_amount >= 0),
  CONSTRAINT chk_sale_items_line_total_nonnegative CHECK (line_total >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inventory_movements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id BIGINT UNSIGNED NOT NULL,
  changed_by_user_id BIGINT UNSIGNED NOT NULL,
  movement_type ENUM('sale', 'adjustment_in', 'adjustment_out', 'restock', 'return') NOT NULL,
  qty_change INT NOT NULL,
  stock_before INT NOT NULL,
  stock_after INT NOT NULL,
  reference_type VARCHAR(40) NULL,
  reference_id BIGINT UNSIGNED NULL,
  notes VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_movements_product_date (product_id, created_at),
  KEY idx_movements_user_date (changed_by_user_id, created_at),
  KEY idx_movements_type_date (movement_type, created_at),
  CONSTRAINT fk_movements_product
    FOREIGN KEY (product_id) REFERENCES products(id)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,
  CONSTRAINT fk_movements_user
    FOREIGN KEY (changed_by_user_id) REFERENCES users(id)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,
  CONSTRAINT chk_movements_qty_nonzero CHECK (qty_change <> 0),
  CONSTRAINT chk_movements_stock_before_nonnegative CHECK (stock_before >= 0),
  CONSTRAINT chk_movements_stock_after_nonnegative CHECK (stock_after >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_user_id BIGINT UNSIGNED NULL,
  action VARCHAR(100) NOT NULL,
  entity_type VARCHAR(60) NULL,
  entity_id BIGINT UNSIGNED NULL,
  details_json JSON NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_actor_date (actor_user_id, created_at),
  KEY idx_audit_action_date (action, created_at),
  KEY idx_audit_entity (entity_type, entity_id, created_at),
  CONSTRAINT fk_audit_actor
    FOREIGN KEY (actor_user_id) REFERENCES users(id)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional helper view for fast reporting and receipt detail queries
CREATE OR REPLACE VIEW v_sale_details AS
SELECT
  s.id AS sale_id,
  s.receipt_no,
  s.sold_at,
  u.full_name AS cashier_name,
  si.id AS sale_item_id,
  p.sku,
  p.barcode,
  p.name AS product_name,
  si.qty,
  si.unit_price,
  si.discount_amount,
  si.line_total
FROM sales s
JOIN users u ON u.id = s.cashier_user_id
JOIN sale_items si ON si.sale_id = s.id
JOIN products p ON p.id = si.product_id;
