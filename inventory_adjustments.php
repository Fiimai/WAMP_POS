<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\RateLimiter;
use App\Repositories\AuditLogRepository;
use App\Repositories\InventoryMovementRepository;

$currentUser = Auth::requirePageAuth(['admin', 'manager']);
Auth::requireCapability('inventory.adjust');
$movementRepo = new InventoryMovementRepository();
$auditRepo = new AuditLogRepository();

$errors = [];
$success = null;

$form = [
    'product_id' => '',
    'direction' => 'in',
    'qty' => '1',
    'notes' => '',
  'approval_code' => '',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $limit = RateLimiter::hit('inventory:adjustments:' . (int) $currentUser['id'] . ':' . Auth::clientIp(), 80, 60);
    if (!$limit['allowed']) {
        $errors[] = 'Too many requests. Try again in ' . $limit['retry_after'] . ' seconds.';
    }

    $csrf = (string) ($_POST['csrf_token'] ?? '');
    $sessionCsrf = (string) ($_SESSION['csrf_token'] ?? '');
    if ($csrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf)) {
        $errors[] = 'Session expired. Refresh and try again.';
    }

    $form = [
        'product_id' => trim((string) ($_POST['product_id'] ?? '')),
        'direction' => ((string) ($_POST['direction'] ?? 'in')) === 'out' ? 'out' : 'in',
        'qty' => trim((string) ($_POST['qty'] ?? '1')),
        'notes' => trim((string) ($_POST['notes'] ?? '')),
      'approval_code' => trim((string) ($_POST['approval_code'] ?? '')),
    ];

    $productId = (int) $form['product_id'];
    $qty = (int) $form['qty'];
    $qtyDelta = $form['direction'] === 'out' ? -$qty : $qty;

    if ($productId < 1) {
        $errors[] = 'Select a product.';
    }

    if ($qty < 1) {
        $errors[] = 'Quantity must be at least 1.';
    }

    if ($qtyDelta < 0 && abs($qtyDelta) >= 20 && strtoupper($form['approval_code']) !== 'APPROVE') {
      $errors[] = 'High-risk stock out detected. Type APPROVE in the confirmation field.';
    }

    if ($errors === []) {
        try {
            $pdo = Database::connection();
            $pdo->beginTransaction();

            $lockStmt = $pdo->prepare(
                'SELECT id, name, stock_qty
                 FROM products
                 WHERE id = :id
                 FOR UPDATE'
            );
            $lockStmt->execute([':id' => $productId]);
            $product = $lockStmt->fetch(PDO::FETCH_ASSOC);

            if ($product === false) {
                $pdo->rollBack();
                $errors[] = 'Product not found.';
            } else {
                $before = (int) $product['stock_qty'];
                $after = $before + $qtyDelta;

                if ($after < 0) {
                    $pdo->rollBack();
                    $errors[] = 'Adjustment would make stock negative.';
                } else {
                    $updateStmt = $pdo->prepare('UPDATE products SET stock_qty = :stock_qty WHERE id = :id');
                    $updateStmt->execute([
                        ':stock_qty' => $after,
                        ':id' => $productId,
                    ]);

                    $movementRepo->record(
                        $productId,
                        (int) $currentUser['id'],
                        $qtyDelta > 0 ? 'adjustment_in' : 'adjustment_out',
                        $qtyDelta,
                        $before,
                        $after,
                        'manual_adjustment',
                        null,
                        $form['notes'] !== '' ? $form['notes'] : 'Manual stock adjustment',
                        $pdo
                    );

                      $auditRepo->record(
                        (int) $currentUser['id'],
                        'inventory.adjusted',
                        'product',
                        $productId,
                        [
                          'qty_change' => $qtyDelta,
                          'stock_before' => $before,
                          'stock_after' => $after,
                        ],
                        Auth::clientIp(),
                        (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
                        $pdo
                      );

                    $pdo->commit();
                    $success = 'Inventory adjusted for ' . (string) $product['name'] . '.';
                    $form = [
                        'product_id' => '',
                        'direction' => 'in',
                        'qty' => '1',
                        'notes' => '',
                      'approval_code' => '',
                    ];
                }
            }
        } catch (Throwable $throwable) {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('inventory adjustment failure: ' . $throwable->getMessage());
            $errors[] = 'Could not apply inventory adjustment.';
        }
    }
}

$products = [];
$recentMovements = [];

try {
    $pdo = Database::connection();
    $productsStmt = $pdo->query('SELECT id, name, sku, stock_qty FROM products WHERE is_active = 1 ORDER BY name ASC LIMIT 400');
    $products = $productsStmt->fetchAll(PDO::FETCH_ASSOC);
    $recentMovements = $movementRepo->latest(80);
} catch (Throwable $throwable) {
    error_log('inventory adjustments load failure: ' . $throwable->getMessage());
    $errors[] = 'Could not load inventory data.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Inventory Adjustments</title>
  <script src="assets/vendor/tailwindcss/tailwindcss.js"></script>
  <style>
    body {
      font-family: 'Space Grotesk', sans-serif;
      background:
        radial-gradient(circle at 15% 10%, rgba(34, 211, 238, 0.2), transparent 28%),
        radial-gradient(circle at 80% 90%, rgba(251, 113, 133, 0.14), transparent 25%),
        #070b14;
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
<body class="min-h-screen text-slate-100 antialiased">
  <a href="#mainContent" class="skip-link">Skip to inventory adjustments content</a>
  <main id="mainContent" class="mx-auto max-w-7xl px-4 py-6 sm:px-6">
    <header class="mb-6 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold">Inventory Adjustments</h1>
        <p class="text-sm text-slate-300">Signed in as <?= e((string) $currentUser['full_name']) ?> (<?= e((string) $currentUser['role']) ?>)</p>
      </div>
      <div class="flex flex-wrap gap-2" aria-label="Inventory navigation">
        <a href="index.php" class="utility-link">Checkout</a>
        <a href="dashboard.php" class="utility-link">Dashboard</a>
        <a href="settings.php" class="utility-link">Settings</a>
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

    <section class="mb-5 rounded-3xl border border-white/10 bg-slate-900/60 p-5 sm:p-6 shadow-2xl backdrop-blur-sm">
      <h2 class="mb-3 text-lg font-semibold">Manual Stock Adjustment</h2>
      <form method="post" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <input type="hidden" name="csrf_token" value="<?= e((string) $_SESSION['csrf_token']) ?>" />

        <label class="text-sm sm:col-span-2">
          <span class="mb-1 block text-slate-300">Product</span>
          <select name="product_id" required class="w-full rounded-xl border border-white/15 bg-slate-950/60 px-3 py-2 outline-none focus:border-cyan-300">
            <option value="">Select product</option>
            <?php foreach ($products as $product): ?>
              <?php $pid = (string) (int) $product['id']; ?>
              <option value="<?= e($pid) ?>" <?= $form['product_id'] === $pid ? 'selected' : '' ?>>
                <?= e((string) $product['name']) ?> (<?= e((string) $product['sku']) ?>) - Stock <?= (int) $product['stock_qty'] ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>

        <label class="text-sm">
          <span class="mb-1 block text-slate-300">Direction</span>
          <select name="direction" class="w-full rounded-xl border border-white/15 bg-slate-950/60 px-3 py-2 outline-none focus:border-cyan-300">
            <option value="in" <?= $form['direction'] === 'in' ? 'selected' : '' ?>>Stock In</option>
            <option value="out" <?= $form['direction'] === 'out' ? 'selected' : '' ?>>Stock Out</option>
          </select>
        </label>

        <label class="text-sm">
          <span class="mb-1 block text-slate-300">Quantity</span>
          <input type="number" min="1" step="1" name="qty" value="<?= e($form['qty']) ?>" required class="w-full rounded-xl border border-white/15 bg-slate-950/60 px-3 py-2 outline-none focus:border-cyan-300" />
        </label>

        <label class="text-sm sm:col-span-2 lg:col-span-3">
          <span class="mb-1 block text-slate-300">Reason / Notes</span>
          <input name="notes" value="<?= e($form['notes']) ?>" placeholder="e.g. Damaged goods, cycle count correction" class="w-full rounded-xl border border-white/15 bg-slate-950/60 px-3 py-2 outline-none focus:border-cyan-300" />
        </label>

        <label class="text-sm">
          <span class="mb-1 block text-slate-300">Approval Code (for stock out >= 20)</span>
          <input name="approval_code" value="<?= e($form['approval_code']) ?>" placeholder="Type APPROVE" class="w-full rounded-xl border border-white/15 bg-slate-950/60 px-3 py-2 outline-none focus:border-cyan-300" />
        </label>

        <div class="flex items-end">
          <button class="min-h-[42px] w-full rounded-xl bg-gradient-to-r from-cyan-400 to-emerald-400 px-4 py-2 text-sm font-semibold text-slate-900">Apply Adjustment</button>
        </div>
      </form>
    </section>

    <section class="overflow-x-auto rounded-2xl border border-white/10 bg-slate-900/60">
      <table class="min-w-full text-sm">
        <thead class="bg-white/5 text-slate-300">
          <tr>
            <th class="px-3 py-2.5 text-left">When</th>
            <th class="px-3 py-2.5 text-left">Product</th>
            <th class="px-3 py-2.5 text-left">Type</th>
            <th class="px-3 py-2.5 text-right">Qty Delta</th>
            <th class="px-3 py-2.5 text-right">Before</th>
            <th class="px-3 py-2.5 text-right">After</th>
            <th class="px-3 py-2.5 text-left">By</th>
            <th class="px-3 py-2.5 text-left">Notes</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($recentMovements === []): ?>
            <tr>
              <td colspan="8" class="px-3 py-4 text-slate-300">No inventory movements yet.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($recentMovements as $row): ?>
              <?php $delta = (int) $row['qty_change']; ?>
              <tr class="border-t border-white/10">
                <td class="px-3 py-2.5 text-slate-300"><?= e((string) $row['created_at']) ?></td>
                <td class="px-3 py-2.5 text-white"><?= e((string) $row['product_name']) ?> <span class="text-xs text-slate-400">(<?= e((string) $row['sku']) ?>)</span></td>
                <td class="px-3 py-2.5 text-slate-300"><?= e((string) $row['movement_type']) ?></td>
                <td class="px-3 py-2 text-right <?= $delta < 0 ? 'text-rose-200' : 'text-emerald-200' ?>"><?= $delta ?></td>
                <td class="px-3 py-2.5 text-right text-slate-200"><?= (int) $row['stock_before'] ?></td>
                <td class="px-3 py-2.5 text-right text-slate-200"><?= (int) $row['stock_after'] ?></td>
                <td class="px-3 py-2.5 text-slate-300"><?= e((string) $row['changed_by_name']) ?></td>
                <td class="px-3 py-2.5 text-slate-300"><?= e((string) ($row['notes'] ?? '')) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </section>
  </main>
</body>
</html>


