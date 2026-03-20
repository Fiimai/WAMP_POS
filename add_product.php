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

    $price = (float) $form['price'];
    $stockQuantity = (int) $form['stock_quantity'];
    $categoryId = (int) $form['category_id'];

    if ($errors === []) {
        try {
            $service = new ProductService(new ProductRepository());
            $newId = $service->addProduct($form['product_name'], $form['sku'], $price, $stockQuantity, $categoryId);
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
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    body {
      font-family: 'Space Grotesk', sans-serif;
      background:
        radial-gradient(circle at 15% 10%, rgba(34, 211, 238, 0.2), transparent 28%),
        radial-gradient(circle at 80% 90%, rgba(251, 113, 133, 0.14), transparent 25%),
        #070b14;
    }
  </style>
</head>
<body class="min-h-screen text-slate-100 antialiased">
  <main class="mx-auto max-w-3xl px-4 py-6 sm:px-6">
    <header class="mb-6 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold">Add Product</h1>
        <p class="text-sm text-slate-300">Signed in as <?= e((string) $currentUser['full_name']) ?> (admin)</p>
      </div>
      <div class="flex gap-2">
        <a href="manage_products.php" class="rounded-lg border border-white/20 px-3 py-2 text-sm hover:bg-white/10">Manage Products</a>
        <a href="index.php" class="rounded-lg border border-white/20 px-3 py-2 text-sm hover:bg-white/10">Checkout</a>
        <a href="settings.php" class="rounded-lg border border-white/20 px-3 py-2 text-sm hover:bg-white/10">Settings</a>
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

    <form method="post" class="space-y-4 rounded-3xl border border-white/10 bg-slate-900/60 p-5 shadow-2xl backdrop-blur-sm">
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

      <div class="pt-2">
        <button class="rounded-xl bg-gradient-to-r from-cyan-400 to-emerald-400 px-5 py-2 font-semibold text-slate-900">Create Product</button>
      </div>
    </form>
  </main>
</body>
</html>
