<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\RateLimiter;
use App\Repositories\CategoryRepository;
use App\Repositories\ProductRepository;
use App\Services\ProductService;

$currentUser = Auth::requirePageAuth(['admin']);
$service = new ProductService(new ProductRepository());

$query = trim((string) ($_GET['q'] ?? ''));
$errors = [];
$success = null;
$importSummary = null;

$downloadTemplate = ((string) ($_GET['template'] ?? '')) === 'products_csv';
if ($downloadTemplate) {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="products-import-template.csv"');
  $out = fopen('php://output', 'wb');
  if (is_resource($out)) {
    fputcsv($out, ['name', 'sku', 'category', 'unit_price', 'stock_qty', 'reorder_level', 'barcode', 'description', 'cost_price', 'is_active']);
    fclose($out);
  }
  exit;
}

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
          } elseif ($action === 'bulk_import') {
            $file = $_FILES['products_csv'] ?? null;
            if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
              throw new RuntimeException('Please select a valid CSV file.');
            }

            $tmpName = (string) ($file['tmp_name'] ?? '');
            if ($tmpName === '' || !is_uploaded_file($tmpName)) {
              throw new RuntimeException('Uploaded file could not be verified.');
            }

            $categoryRepo = new CategoryRepository();
            $categoryMap = [];
            foreach ($categoryRepo->allActive() as $category) {
              $id = (int) ($category['id'] ?? 0);
              $name = strtolower(trim((string) ($category['name'] ?? '')));
              if ($id > 0 && $name !== '') {
                $categoryMap[$name] = $id;
              }
            }

            $handle = fopen($tmpName, 'rb');
            if ($handle === false) {
              throw new RuntimeException('Unable to read uploaded CSV file.');
            }

            $header = fgetcsv($handle);
            if (!is_array($header)) {
              fclose($handle);
              throw new RuntimeException('CSV file is empty.');
            }

            $normalizedHeader = array_map(
              static fn($column): string => strtolower(trim((string) $column)),
              $header
            );

            $required = ['name', 'sku', 'category', 'unit_price', 'stock_qty'];
            foreach ($required as $column) {
              if (!in_array($column, $normalizedHeader, true)) {
                fclose($handle);
                throw new RuntimeException('CSV header must include: ' . implode(', ', $required));
              }
            }

            $pdo = Database::connection();
            $statement = $pdo->prepare(
              'INSERT INTO products (
                category_id, sku, barcode, name, description, unit_price, cost_price, stock_qty, reorder_level, is_active
              ) VALUES (
                :category_id, :sku, :barcode, :name, :description, :unit_price, :cost_price, :stock_qty, :reorder_level, :is_active
              )
              ON DUPLICATE KEY UPDATE
                category_id = VALUES(category_id),
                barcode = VALUES(barcode),
                name = VALUES(name),
                description = VALUES(description),
                unit_price = VALUES(unit_price),
                cost_price = VALUES(cost_price),
                stock_qty = VALUES(stock_qty),
                reorder_level = VALUES(reorder_level),
                is_active = VALUES(is_active)'
            );

            $createdOrUpdated = 0;
            $skipped = 0;
            $rowNumber = 1;

            while (($row = fgetcsv($handle)) !== false) {
              $rowNumber++;
              if ($row === [null] || $row === []) {
                continue;
              }

              $rowData = [];
              foreach ($normalizedHeader as $index => $column) {
                $rowData[$column] = trim((string) ($row[$index] ?? ''));
              }

              $name = (string) ($rowData['name'] ?? '');
              $sku = (string) ($rowData['sku'] ?? '');
              $categoryRaw = strtolower((string) ($rowData['category'] ?? ''));
              $unitPrice = (float) ($rowData['unit_price'] ?? 0);
              $stockQty = (int) ($rowData['stock_qty'] ?? 0);
              $reorderLevel = (int) ($rowData['reorder_level'] ?? 0);
              $barcode = (string) ($rowData['barcode'] ?? '');
              $description = (string) ($rowData['description'] ?? '');
              $costPrice = trim((string) ($rowData['cost_price'] ?? ''));
              $isActiveRaw = strtolower((string) ($rowData['is_active'] ?? '1'));
              $isActive = in_array($isActiveRaw, ['1', 'true', 'yes', 'y', 'active'], true) ? 1 : 0;

              if ($name === '' || $sku === '') {
                $skipped++;
                if (count($errors) < 5) {
                  $errors[] = 'Row ' . $rowNumber . ' skipped: name and sku are required.';
                }
                continue;
              }

              $categoryId = 0;
              if ($categoryRaw !== '') {
                if (ctype_digit($categoryRaw)) {
                  $categoryId = (int) $categoryRaw;
                } else {
                  $categoryId = (int) ($categoryMap[$categoryRaw] ?? 0);
                }
              }

              if ($categoryId < 1 || $unitPrice < 0 || $stockQty < 0 || $reorderLevel < 0) {
                $skipped++;
                if (count($errors) < 5) {
                  $errors[] = 'Row ' . $rowNumber . ' skipped: invalid category/price/stock values.';
                }
                continue;
              }

              try {
                $statement->execute([
                  ':category_id' => $categoryId,
                  ':sku' => $sku,
                  ':barcode' => $barcode !== '' ? $barcode : null,
                  ':name' => $name,
                  ':description' => $description !== '' ? $description : null,
                  ':unit_price' => $unitPrice,
                  ':cost_price' => $costPrice !== '' ? (float) $costPrice : null,
                  ':stock_qty' => $stockQty,
                  ':reorder_level' => $reorderLevel,
                  ':is_active' => $isActive,
                ]);
                $createdOrUpdated++;
              } catch (Throwable $throwable) {
                $skipped++;
                if (count($errors) < 5) {
                  $errors[] = 'Row ' . $rowNumber . ' skipped: ' . $throwable->getMessage();
                }
              }
            }

            fclose($handle);

            $importSummary = ['created_or_updated' => $createdOrUpdated, 'skipped' => $skipped];
            $success = 'Bulk product import finished.';
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

    body[data-theme='light'] .border-white\/10 {
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
<body class="ambient-medium min-h-screen text-slate-100 antialiased">
  <a href="#mainContent" class="skip-link">Skip to manage products content</a>
  <div class="matrix-grid" aria-hidden="true"></div>
  <div class="scanner-line" aria-hidden="true"></div>
  <div class="retro-orbs" aria-hidden="true">
    <span class="orb orb-a"></span>
    <span class="orb orb-b"></span>
  </div>
  <main id="mainContent" class="relative z-10 mx-auto max-w-6xl px-4 py-6 sm:px-6">
    <header class="mb-6 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold">Manage Products</h1>
        <p class="text-sm text-slate-300">Signed in as <?= e((string) $currentUser['full_name']) ?> (admin)</p>
      </div>
      <div class="flex flex-wrap gap-2" aria-label="Manage products navigation">
        <a href="add_product.php" class="utility-link">Add Product</a>
        <a href="settings.php" class="utility-link">Settings</a>
        <a href="dashboard.php" class="utility-link">Dashboard</a>
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

    <?php if ($importSummary !== null): ?>
      <div class="mb-4 rounded-xl border border-cyan-300/30 bg-cyan-500/10 px-4 py-3 text-sm text-cyan-100">
        Imported/Updated: <?= (int) $importSummary['created_or_updated'] ?> products, Skipped: <?= (int) $importSummary['skipped'] ?> rows.
      </div>
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

    <form method="post" enctype="multipart/form-data" class="mb-4 space-y-3 rounded-2xl border border-white/10 bg-slate-900/50 p-4">
      <input type="hidden" name="csrf_token" value="<?= e((string) $_SESSION['csrf_token']) ?>" />
      <input type="hidden" name="action" value="bulk_import" />
      <div class="flex flex-wrap items-end gap-3">
        <label class="block text-sm">Bulk Import Products (CSV)
          <input type="file" name="products_csv" accept=".csv,text/csv" required class="mt-1 block w-full rounded-lg border border-white/15 bg-slate-950/60 px-3 py-2 text-sm" />
        </label>
        <button class="min-h-[42px] rounded-xl border border-cyan-300/35 px-4 py-2 text-sm text-cyan-100 hover:bg-cyan-500/15">Import CSV</button>
        <a href="manage_products.php?template=products_csv" class="min-h-[42px] rounded-xl border border-emerald-300/35 px-4 py-2 text-sm text-emerald-100 hover:bg-emerald-500/15">Download CSV Template</a>
      </div>
      <p class="text-xs text-slate-400">CSV headers: name, sku, category, unit_price, stock_qty, reorder_level, barcode, description, cost_price, is_active</p>
    </form>

    <form method="get" class="mb-4 flex gap-2">
      <input
        type="search"
        name="q"
        value="<?= e($query) ?>"
        placeholder="Search by name, SKU or barcode..."
        class="w-full rounded-xl border border-white/15 bg-slate-950/60 px-3 py-2 outline-none focus:border-cyan-300"
      />
      <button class="min-h-[42px] rounded-xl border border-white/20 px-4 py-2 text-sm hover:bg-white/10">Search</button>
    </form>

    <section class="overflow-x-auto rounded-2xl border border-white/10 bg-slate-900/60">
      <table class="min-w-full text-sm">
        <thead class="bg-white/5 text-slate-300">
          <tr>
            <th class="px-3 py-2.5 text-left">Name</th>
            <th class="px-3 py-2.5 text-left">SKU</th>
            <th class="px-3 py-2.5 text-left">Category</th>
            <th class="px-3 py-2.5 text-right">Price</th>
            <th class="px-3 py-2.5 text-right">Stock</th>
            <th class="px-3 py-2.5 text-center">Status</th>
            <th class="px-3 py-2.5 text-right">Actions</th>
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
                <td class="px-3 py-2.5 text-white"><?= e((string) $product['name']) ?></td>
                <td class="px-3 py-2.5 text-slate-300"><?= e((string) $product['sku']) ?></td>
                <td class="px-3 py-2.5 text-slate-300"><?= e((string) $product['category_name']) ?></td>
                <td class="px-3 py-2.5 text-right text-slate-200"><?= number_format((float) $product['unit_price'], 2) ?></td>
                <td class="px-3 py-2.5 text-right text-slate-200"><?= (int) $product['stock_qty'] ?></td>
                <td class="px-3 py-2.5 text-center">
                  <span class="rounded-full px-2 py-1 text-xs <?= $active ? 'bg-emerald-500/20 text-emerald-200' : 'bg-slate-500/25 text-slate-300' ?>">
                    <?= $active ? 'Active' : 'Inactive' ?>
                  </span>
                </td>
                <td class="px-3 py-2.5">
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
  <script src="assets/js/ambient-layer.js"></script>
  <script>
    window.NovaAmbient.init({ pauseAfterMs: 7000 });

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


