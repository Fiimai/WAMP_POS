USE pos_db;

INSERT INTO shop_settings (
  id,
  shop_name,
  shop_logo_url,
  business_tagline,
  shop_address,
  shop_phone,
  shop_tax_id,
  currency_code,
  currency_symbol,
  tax_rate_percent,
  receipt_header,
  receipt_footer,
  theme_accent_primary,
  theme_accent_secondary
)
SELECT
  1,
  'Khanun',
  '',
  'Retail made gracious and fast',
  '123 Market Street',
  '+1-555-0100',
  'TAX-0001',
  'USD',
  '$',
  8.00,
  'Thank you for shopping with us',
  'No refunds without receipt',
  '#06B6D4',
  '#22D3AA'
WHERE NOT EXISTS (
  SELECT 1 FROM shop_settings WHERE id = 1
);

INSERT INTO users (full_name, username, email, password_hash, role, is_active)
SELECT 'System Admin', 'admin', 'admin@novapos.local', '$2y$10$NJivItNLrznv209Jy9FTWuhTQJKsM9Z2clZUrsF53WJ3N6J9idqxm', 'admin', 1
WHERE NOT EXISTS (
  SELECT 1 FROM users WHERE username = 'admin'
);

INSERT INTO users (full_name, username, email, password_hash, role, is_active)
SELECT 'Store Cashier', 'cashier1', 'cashier1@novapos.local', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'cashier', 1
WHERE NOT EXISTS (
  SELECT 1 FROM users WHERE username = 'cashier1'
);

INSERT INTO categories (name, slug, is_active)
SELECT 'Peripherals', 'peripherals', 1
WHERE NOT EXISTS (
  SELECT 1 FROM categories WHERE slug = 'peripherals'
);

INSERT INTO categories (name, slug, is_active)
SELECT 'Groceries', 'groceries', 1
WHERE NOT EXISTS (
  SELECT 1 FROM categories WHERE slug = 'groceries'
);

INSERT INTO categories (name, slug, is_active)
SELECT 'Electronics', 'electronics', 1
WHERE NOT EXISTS (
  SELECT 1 FROM categories WHERE slug = 'electronics'
);

INSERT INTO categories (name, slug, is_active)
SELECT 'Dairy', 'dairy', 1
WHERE NOT EXISTS (
  SELECT 1 FROM categories WHERE slug = 'dairy'
);

INSERT INTO categories (name, slug, is_active)
SELECT 'Produce', 'produce', 1
WHERE NOT EXISTS (
  SELECT 1 FROM categories WHERE slug = 'produce'
);

INSERT INTO categories (name, slug, is_active)
SELECT 'Accessories', 'accessories', 1
WHERE NOT EXISTS (
  SELECT 1 FROM categories WHERE slug = 'accessories'
);

INSERT INTO categories (name, slug, is_active)
SELECT 'Office', 'office', 1
WHERE NOT EXISTS (
  SELECT 1 FROM categories WHERE slug = 'office'
);

INSERT INTO categories (name, slug, is_active)
SELECT 'Snacks', 'snacks', 1
WHERE NOT EXISTS (
  SELECT 1 FROM categories WHERE slug = 'snacks'
);

INSERT INTO products (category_id, sku, barcode, name, description, unit_price, cost_price, stock_qty, reorder_level, is_active)
SELECT c.id, 'PER-001', '890100000001', 'Wireless Barcode Scanner', '2.4GHz scanner for fast checkout', 59.00, 41.00, 24, 5, 1
FROM categories c
WHERE c.slug = 'peripherals'
  AND NOT EXISTS (SELECT 1 FROM products WHERE sku = 'PER-001');

INSERT INTO products (category_id, sku, barcode, name, description, unit_price, cost_price, stock_qty, reorder_level, is_active)
SELECT c.id, 'GRO-001', '890100000002', 'Organic Coffee Beans', 'Arabica blend 500g', 12.90, 8.10, 50, 10, 1
FROM categories c
WHERE c.slug = 'groceries'
  AND NOT EXISTS (SELECT 1 FROM products WHERE sku = 'GRO-001');

INSERT INTO products (category_id, sku, barcode, name, description, unit_price, cost_price, stock_qty, reorder_level, is_active)
SELECT c.id, 'ELE-001', '890100000003', 'Noise Canceling Headphones', 'Wireless ANC headset', 139.00, 98.00, 17, 4, 1
FROM categories c
WHERE c.slug = 'electronics'
  AND NOT EXISTS (SELECT 1 FROM products WHERE sku = 'ELE-001');

INSERT INTO products (category_id, sku, barcode, name, description, unit_price, cost_price, stock_qty, reorder_level, is_active)
SELECT c.id, 'DAI-001', '890100000004', 'Whole Milk 1L', 'Pasteurized full cream milk', 2.80, 1.90, 72, 15, 1
FROM categories c
WHERE c.slug = 'dairy'
  AND NOT EXISTS (SELECT 1 FROM products WHERE sku = 'DAI-001');

INSERT INTO products (category_id, sku, barcode, name, description, unit_price, cost_price, stock_qty, reorder_level, is_active)
SELECT c.id, 'PRO-001', '890100000005', 'Fresh Veggie Basket', 'Mixed seasonal vegetables', 8.25, 5.80, 31, 8, 1
FROM categories c
WHERE c.slug = 'produce'
  AND NOT EXISTS (SELECT 1 FROM products WHERE sku = 'PRO-001');

INSERT INTO products (category_id, sku, barcode, name, description, unit_price, cost_price, stock_qty, reorder_level, is_active)
SELECT c.id, 'ACC-001', '890100000006', 'Aviator Sunglasses', 'Polarized UV protection', 35.40, 22.00, 20, 5, 1
FROM categories c
WHERE c.slug = 'accessories'
  AND NOT EXISTS (SELECT 1 FROM products WHERE sku = 'ACC-001');

INSERT INTO products (category_id, sku, barcode, name, description, unit_price, cost_price, stock_qty, reorder_level, is_active)
SELECT c.id, 'OFF-001', '890100000007', 'Aluminum Laptop Stand', 'Ergonomic cooling stand', 28.00, 18.20, 36, 7, 1
FROM categories c
WHERE c.slug = 'office'
  AND NOT EXISTS (SELECT 1 FROM products WHERE sku = 'OFF-001');

INSERT INTO products (category_id, sku, barcode, name, description, unit_price, cost_price, stock_qty, reorder_level, is_active)
SELECT c.id, 'SNK-001', '890100000008', 'Dark Chocolate Bar', '70 percent cocoa', 3.50, 2.20, 90, 20, 1
FROM categories c
WHERE c.slug = 'snacks'
  AND NOT EXISTS (SELECT 1 FROM products WHERE sku = 'SNK-001');
