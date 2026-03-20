<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\RateLimiter;
use App\Repositories\ProductRepository;
use App\Services\ProductService;

$currentUser = Auth::requirePageAuth(['admin']);
$service = new ProductService(new ProductRepository());

$query = trim((string) ($_GET['q'] ?? ''));
$errors = [];
$success = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $ip = Auth::clientIp();
    $limit = RateLimiter::hit('admin:manage_products:' . (int) $currentUser['id'] . ':' . $ip, 100, 60);

    if (!$limit['allowed']) {
        $errors[] = 'Too many requests. Try again in ' . $limit['retry_after'] . ' seconds.';
    }

    $csrf = (string) ($_POST['csrf_token'] ?? '');
    $sessionCsrf = (string) ($_SESSION['csrf_token'] ?? '');
    if ($csrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf)) {
        $errors[] = 'Session expired. Refresh and try again.';
    }

    $action = (string) ($_POST['action'] ?? '');
    $productId = (int) ($_POST['product_id'] ?? 0);

    if ($errors === []) {
        try {
            if ($action === 'deactivate') {
                $service->deactivateProduct($productId);
                $success = 'Product deactivated.';
            } elseif ($action === 'activate') {
                $service->activateProduct($productId);
                $success = 'Product reactivated.';
            }
        } catch (Throwable $throwable) {
            error_log('manage products action failure: ' . $throwable->getMessage());
            $errors[] = 'Could not complete product action.';
        }
    }
}

$products = $service->listProducts($query === '' ? null : $query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Manage Products</title>
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
  <main class="mx-auto max-w-6xl px-4 py-6 sm:px-6">
    <header class="mb-6 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold">Manage Products</h1>
        <p class="text-sm text-slate-300">Signed in as <?= e((string) $currentUser['full_name']) ?> (admin)</p>
      </div>
      <div class="flex gap-2">
        <a href="add_product.php" class="rounded-lg border border-white/20 px-3 py-2 text-sm hover:bg-white/10">Add Product</a>
        <a href="settings.php" class="rounded-lg border border-white/20 px-3 py-2 text-sm hover:bg-white/10">Settings</a>
        <a href="index.php" class="rounded-lg border border-white/20 px-3 py-2 text-sm hover:bg-white/10">Checkout</a>
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

    <form method="get" class="mb-4 flex gap-2">
      <input
        type="search"
        name="q"
        value="<?= e($query) ?>"
        placeholder="Search by name, SKU or barcode..."
        class="w-full rounded-xl border border-white/15 bg-slate-950/60 px-3 py-2 outline-none focus:border-cyan-300"
      />
      <button class="rounded-xl border border-white/20 px-4 py-2 text-sm hover:bg-white/10">Search</button>
    </form>

    <section class="overflow-x-auto rounded-2xl border border-white/10 bg-slate-900/60">
      <table class="min-w-full text-sm">
        <thead class="bg-white/5 text-slate-300">
          <tr>
            <th class="px-3 py-2 text-left">Name</th>
            <th class="px-3 py-2 text-left">SKU</th>
            <th class="px-3 py-2 text-left">Category</th>
            <th class="px-3 py-2 text-right">Price</th>
            <th class="px-3 py-2 text-right">Stock</th>
            <th class="px-3 py-2 text-center">Status</th>
            <th class="px-3 py-2 text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($products === []): ?>
            <tr>
              <td class="px-3 py-3 text-slate-300" colspan="7">No products found.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($products as $product): ?>
              <?php $active = (int) $product['is_active'] === 1; ?>
              <tr class="border-t border-white/10">
                <td class="px-3 py-2 text-white"><?= e((string) $product['name']) ?></td>
                <td class="px-3 py-2 text-slate-300"><?= e((string) $product['sku']) ?></td>
                <td class="px-3 py-2 text-slate-300"><?= e((string) $product['category_name']) ?></td>
                <td class="px-3 py-2 text-right text-slate-200"><?= number_format((float) $product['unit_price'], 2) ?></td>
                <td class="px-3 py-2 text-right text-slate-200"><?= (int) $product['stock_qty'] ?></td>
                <td class="px-3 py-2 text-center">
                  <span class="rounded-full px-2 py-1 text-xs <?= $active ? 'bg-emerald-500/20 text-emerald-200' : 'bg-slate-500/25 text-slate-300' ?>">
                    <?= $active ? 'Active' : 'Inactive' ?>
                  </span>
                </td>
                <td class="px-3 py-2">
                  <div class="flex justify-end gap-2">
                    <a href="edit_product.php?id=<?= (int) $product['id'] ?>" class="rounded-lg border border-white/20 px-2 py-1 text-xs hover:bg-white/10">Edit</a>
                    <form method="post" class="inline">
                      <input type="hidden" name="csrf_token" value="<?= e((string) $_SESSION['csrf_token']) ?>" />
                      <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>" />
                      <?php if ($active): ?>
                        <input type="hidden" name="action" value="deactivate" />
                        <button class="rounded-lg border border-rose-300/35 px-2 py-1 text-xs text-rose-200 hover:bg-rose-500/15">Deactivate</button>
                      <?php else: ?>
                        <input type="hidden" name="action" value="activate" />
                        <button class="rounded-lg border border-emerald-300/35 px-2 py-1 text-xs text-emerald-200 hover:bg-emerald-500/15">Activate</button>
                      <?php endif; ?>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </section>
  </main>
</body>
</html>
