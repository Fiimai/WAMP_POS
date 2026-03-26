<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\RateLimiter;
use App\Repositories\CategoryRepository;
use App\Services\ProductService;
use App\Repositories\ProductRepository;

$currentUser = Auth::requirePageAuth(['admin']);

$errors = [];
$success = null;

$categoryRepo = new CategoryRepository();
$categories = $categoryRepo->allActive();

$form = [
    'product_name' => '',
    'sku' => '',
    'price' => '0.00',
    'stock_quantity' => '0',
    'category_id' => '',
];

function handleImageUpload(array $file): string
{
    // Validate file type
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($file['type'], $allowedTypes)) {
        throw new InvalidArgumentException('Invalid image type. Only JPG, PNG, GIF, and WebP are allowed.');
    }

    // Validate file size (2MB max)
    $maxSize = 2 * 1024 * 1024; // 2MB
    if ($file['size'] > $maxSize) {
        throw new InvalidArgumentException('Image file is too large. Maximum size is 2MB.');
    }

    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid('product_', true) . '.' . $extension;
    $uploadPath = __DIR__ . '/assets/images/products/' . $filename;

    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
        throw new RuntimeException('Failed to save uploaded image.');
    }

    return 'assets/images/products/' . $filename;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $ip = Auth::clientIp();
    $limit = RateLimiter::hit('admin:add_product:' . (int) $currentUser['id'] . ':' . $ip, 60, 60);

    if (!$limit['allowed']) {
        $errors[] = 'Too many requests. Try again in ' . $limit['retry_after'] . ' seconds.';
    }

    $csrf = (string) ($_POST['csrf_token'] ?? '');
    $sessionCsrf = (string) ($_SESSION['csrf_token'] ?? '');

    if ($csrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf)) {
        $errors[] = 'Session expired. Refresh and try again.';
    }

    $form = [
        'product_name' => trim((string) ($_POST['product_name'] ?? '')),
        'sku' => trim((string) ($_POST['sku'] ?? '')),
        'price' => trim((string) ($_POST['price'] ?? '0.00')),
        'stock_quantity' => trim((string) ($_POST['stock_quantity'] ?? '0')),
        'category_id' => trim((string) ($_POST['category_id'] ?? '')),
    ];

    $imagePath = null;
    if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] === UPLOAD_ERR_OK) {
        try {
            $imagePath = handleImageUpload($_FILES['product_image']);
        } catch (Throwable $throwable) {
            $errors[] = 'Failed to upload image: ' . $throwable->getMessage();
        }
    }

    $price = (float) $form['price'];
    $stockQuantity = (int) $form['stock_quantity'];
    $categoryId = (int) $form['category_id'];

    if ($errors === []) {
        try {
            $service = new ProductService(new ProductRepository());
            $newId = $service->addProduct($form['product_name'], $form['sku'], $price, $stockQuantity, $categoryId, $imagePath);
            $success = 'Product created successfully (ID ' . $newId . ').';
            $form = [
                'product_name' => '',
                'sku' => '',
                'price' => '0.00',
                'stock_quantity' => '0',
                'category_id' => '',
            ];
        } catch (Throwable $throwable) {
            error_log('add product failure: ' . $throwable->getMessage());
            $errors[] = 'Could not create product. Ensure SKU is unique and values are valid.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Add Product</title>
  <script src="assets/vendor/tailwindcss/tailwindcss.js"></script>
  <link rel="stylesheet" href="assets/css/ambient-layer.css" />
  <link rel="stylesheet" href="assets/css/y2k-global.css" />
  <style>
    body {
      font-family: 'Space Grotesk', sans-serif;
      --bg-base: #070b14;
      --bg-glow-1: rgba(34, 211, 238, 0.2);
      --bg-glow-2: rgba(251, 113, 133, 0.14);
      background:
        radial-gradient(circle at 15% 10%, var(--bg-glow-1), transparent 28%),
        radial-gradient(circle at 80% 90%, var(--bg-glow-2), transparent 25%),
        var(--bg-base);
      min-height: 100vh;
    }

    body[data-theme='light'] {
      --bg-base: #dbeafe;
      --bg-glow-1: rgba(59, 130, 246, 0.2);
      --bg-glow-2: rgba(255, 107, 53, 0.18);
      color: #1e40af;
    }

    body[data-theme='light'] .text-white,
    body[data-theme='light'] .text-slate-100,
    body[data-theme='light'] .text-slate-200 {
      color: #0f172a !important;
    }

    body[data-theme='light'] .text-slate-300,
    body[data-theme='light'] .text-slate-400 {
      color: #334155 !important;
    }

    body[data-theme='light'] .bg-slate-900\/60,
    body[data-theme='light'] .bg-slate-900\/50,
    body[data-theme='light'] .bg-slate-900\/35,
    body[data-theme='light'] .bg-slate-900\/30,
    body[data-theme='light'] .bg-slate-900\/45,
    body[data-theme='light'] .bg-slate-900\/65 {
      background-color: rgba(255, 255, 255, 0.82) !important;
    }

    body[data-theme='light'] .border-white\/10,
    body[data-theme='light'] .border-white\/15 {
      border-color: rgba(15, 23, 42, 0.16) !important;
    }

    body[data-theme='light'] .utility-link {
      border-color: rgba(51, 65, 85, 0.24);
      background: rgba(241, 245, 249, 0.95);
      color: #0f172a;
    }

    body[data-theme='light'] .utility-link:hover {
      border-color: rgba(59, 130, 246, 0.45);
      background: rgba(255, 255, 255, 0.95);
    }

    body[data-theme='light'] .text-rose-100 {
      color: #b91c1c !important;
    }

    body[data-theme='light'] .bg-rose-500\/10 {
      background-color: rgba(254, 226, 226, 0.68) !important;
    }

    body[data-theme='light'] .text-emerald-100,
    body[data-theme='light'] .text-emerald-200 {
      color: #047857 !important;
    }

    body[data-theme='light'] .bg-emerald-500\/10 {
      background-color: rgba(209, 250, 229, 0.68) !important;
    }

    .skip-link {
      position: fixed;
      left: 0.75rem;
      top: 0.75rem;
      z-index: 80;
      border-radius: 0.75rem;
      border: 1px solid rgba(125, 211, 252, 0.45);
      background: rgba(15, 23, 42, 0.92);
      color: #e2e8f0;
      padding: 0.55rem 0.8rem;
      font-size: 0.75rem;
      font-weight: 600;
      transform: translateY(-140%);
      transition: transform 180ms ease;
    }

    .skip-link:focus {
      transform: translateY(0);
      outline: 2px solid rgba(125, 211, 252, 0.8);
      outline-offset: 1px;
    }

    body[data-theme='light'] .skip-link {
      border-color: rgba(15, 23, 42, 0.25);
      background: rgba(255, 255, 255, 0.95);
      color: #0f172a;
    }

    .utility-link {
      border-radius: 0.6rem;
      border: 1px solid rgba(148, 163, 184, 0.35);
      background: rgba(15, 23, 42, 0.5);
      color: #dbeafe;
      padding: 0.45rem 0.75rem;
      font-size: 0.84rem;
      font-weight: 600;
      transition: background-color 170ms ease, border-color 170ms ease;
    }

    .utility-link:hover {
      border-color: rgba(125, 211, 252, 0.45);
      background: rgba(15, 23, 42, 0.75);
    }

    .utility-link:focus-visible,
    a:focus-visible,
    button:focus-visible,
    input:focus-visible,
    select:focus-visible {
      outline: 2px solid rgba(125, 211, 252, 0.8);
      outline-offset: 2px;
    }
  </style>
</head>
<body class="ambient-soft min-h-screen text-slate-100 antialiased">
  <a href="#mainContent" class="skip-link">Skip to add product content</a>
  <div class="matrix-grid" aria-hidden="true"></div>
  <div class="scanner-line" aria-hidden="true"></div>
  <div class="retro-orbs" aria-hidden="true">
    <span class="orb orb-a"></span>
    <span class="orb orb-b"></span>
  </div>
  <main id="mainContent" class="relative z-10 mx-auto max-w-3xl px-4 py-6 sm:px-6">
    <header class="mb-6 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold">Add Product</h1>
        <p class="text-sm text-slate-300">Signed in as <?= e((string) $currentUser['full_name']) ?> (admin)</p>
      </div>
      <div class="flex flex-wrap gap-2" aria-label="Add product navigation">
        <a href="manage_products.php" class="utility-link">Manage Products</a>
        <a href="dashboard.php" class="utility-link">Dashboard</a>
        <a href="settings.php" class="utility-link">Settings</a>
        <a href="index.php" class="utility-link">Checkout</a>
        <button type="button" id="themeToggle" class="utility-link inline-flex items-center gap-1.5" aria-label="Toggle theme">
          <span id="themeToggleIcon" class="inline-block w-4 text-center" aria-hidden="true">&#9790;</span>
          <span id="themeToggleText">Dark</span>
        </button>
      </div>
    </header>

    <?php if ($success !== null): ?>
      <div class="mb-4 rounded-xl border border-emerald-300/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-100"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
      <div class="mb-4 rounded-xl border border-rose-300/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-100">
        <ul class="list-disc pl-5">
          <?php foreach ($errors as $error): ?>
            <li><?= e((string) $error) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="space-y-4 rounded-3xl border border-white/10 bg-slate-900/60 p-5 sm:p-6 shadow-2xl backdrop-blur-sm">
      <input type="hidden" name="csrf_token" value="<?= e((string) $_SESSION['csrf_token']) ?>" />

      <label class="block text-sm">
        <span class="mb-1 block text-slate-300">Product Name</span>
        <input
          name="product_name"
          value="<?= e($form['product_name']) ?>"
          required
          class="w-full rounded-xl border border-white/15 bg-slate-950/60 px-3 py-2 outline-none focus:border-cyan-300"
        />
      </label>

      <div class="grid gap-3 sm:grid-cols-2">
        <label class="block text-sm">
          <span class="mb-1 block text-slate-300">SKU</span>
          <input
            name="sku"
            value="<?= e($form['sku']) ?>"
            required
            class="w-full rounded-xl border border-white/15 bg-slate-950/60 px-3 py-2 outline-none focus:border-cyan-300"
          />
        </label>

        <label class="block text-sm">
          <span class="mb-1 block text-slate-300">Category</span>
          <select name="category_id" required class="w-full rounded-xl border border-white/15 bg-slate-950/60 px-3 py-2 outline-none focus:border-cyan-300">
            <option value="">Select category</option>
            <?php foreach ($categories as $category): ?>
              <?php $cid = (string) (int) $category['id']; ?>
              <option value="<?= e($cid) ?>" <?= $form['category_id'] === $cid ? 'selected' : '' ?>>
                <?= e((string) $category['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>

      <div class="grid gap-3 sm:grid-cols-2">
        <label class="block text-sm">
          <span class="mb-1 block text-slate-300">Price</span>
          <input
            type="number"
            name="price"
            min="0"
            step="0.01"
            value="<?= e($form['price']) ?>"
            required
            class="w-full rounded-xl border border-white/15 bg-slate-950/60 px-3 py-2 outline-none focus:border-cyan-300"
          />
        </label>

        <label class="block text-sm">
          <span class="mb-1 block text-slate-300">Stock Quantity</span>
          <input
            type="number"
            name="stock_quantity"
            min="0"
            step="1"
            value="<?= e($form['stock_quantity']) ?>"
            required
            class="w-full rounded-xl border border-white/15 bg-slate-950/60 px-3 py-2 outline-none focus:border-cyan-300"
          />
        </label>
      </div>

      <label class="block text-sm">
        <span class="mb-1 block text-slate-300">Product Image (optional)</span>
        <input
          type="file"
          name="product_image"
          accept="image/*"
          class="w-full rounded-xl border border-white/15 bg-slate-950/60 px-3 py-2 outline-none focus:border-cyan-300 file:mr-4 file:py-2 file:px-4 file:rounded-l-lg file:border-0 file:bg-cyan-500/20 file:text-cyan-300 file:font-semibold hover:file:bg-cyan-500/30"
        />
        <span class="text-xs text-slate-400 mt-1 block">Accepted formats: JPG, PNG, GIF, WebP. Max size: 2MB</span>
      </label>

      <div class="pt-2">
        <button class="min-h-[42px] rounded-xl bg-gradient-to-r from-cyan-400 to-emerald-400 px-4 py-2 text-sm font-semibold text-slate-900">Create Product</button>
      </div>
    </form>
  </main>
  <script src="assets/js/ambient-layer.js"></script>
  <script>
    window.NovaAmbient.init({ pauseAfterMs: 5000 });

    (function () {
      const THEME_PREF_KEY = 'novapos_theme';
      const themeToggle = document.getElementById('themeToggle');
      const themeToggleIcon = document.getElementById('themeToggleIcon');
      const themeToggleText = document.getElementById('themeToggleText');

      function syncThemeToggle(theme) {
        if (!themeToggle || !themeToggleIcon || !themeToggleText) {
          return;
        }
        const isLight = theme === 'light';
        themeToggleIcon.innerHTML = isLight ? '&#9728;' : '&#9790;';
        themeToggleText.textContent = isLight ? 'Light' : 'Dark';
      }

      function applyTheme(themeName, persist) {
        const theme = themeName === 'light' ? 'light' : 'dark';
        document.body.setAttribute('data-theme', theme);
        syncThemeToggle(theme);
        if (persist) {
          try {
            localStorage.setItem(THEME_PREF_KEY, theme);
          } catch (error) {
          }
        }
      }

      let savedTheme = 'dark';
      try {
        savedTheme = localStorage.getItem(THEME_PREF_KEY) || 'dark';
      } catch (error) {
      }
      applyTheme(savedTheme, false);

      if (themeToggle) {
        themeToggle.addEventListener('click', function () {
          const currentTheme = document.body.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
          applyTheme(currentTheme === 'light' ? 'dark' : 'light', true);
        });
      }

      window.addEventListener('storage', function (event) {
        if (event.key !== THEME_PREF_KEY || event.newValue === null) {
          return;
        }
        applyTheme(event.newValue, false);
      });
    })();
  </script>
  <script src="assets/js/y2k-global.js"></script>
  <script>
    window.NovaY2K.init();
  </script></body>
</html>


