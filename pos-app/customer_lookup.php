<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Models\ShopSettings;
use PDO;
use Throwable;

/**
 * @return string
 */
function normalizedDateInput(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
}

/**
 * @return array<string, bool>
 */
function salesColumnMap(PDO $pdo): array
{
    static $map = null;
    if (is_array($map)) {
        return $map;
    }

    $map = [];
    try {
        $statement = $pdo->query('SHOW COLUMNS FROM sales');
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $name = (string) ($row['Field'] ?? '');
            if ($name !== '') {
                $map[$name] = true;
            }
        }
    } catch (Throwable $throwable) {
        $map = [];
    }

    return $map;
}

$currentUser = Auth::requirePageAuth(['admin', 'manager', 'cashier']);
$shopSettings = ShopSettings::get();
$shopName = (string) ($shopSettings['shop_name'] ?? 'My Shop');
$currencySymbol = (string) ($shopSettings['currency_symbol'] ?? '$');

$q = trim((string) ($_GET['q'] ?? ''));
$fromDate = normalizedDateInput((string) ($_GET['from'] ?? ''));
$toDate = normalizedDateInput((string) ($_GET['to'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$rows = [];
$error = null;
$schemaReady = false;
$totalRows = 0;
$totalPages = 1;

try {
    $pdo = Database::connection();
    $columnMap = salesColumnMap($pdo);

    $required = ['customer_name', 'customer_contact', 'delivery_note', 'customer_consent_at'];
    $schemaReady = true;
    foreach ($required as $col) {
        if (!($columnMap[$col] ?? false)) {
            $schemaReady = false;
            break;
        }
    }

    if ($schemaReady) {
        $sqlFrom = ' FROM sales s
                     INNER JOIN users u ON u.id = s.cashier_user_id
                     WHERE 1=1
                       AND (
                            COALESCE(s.customer_name, "") <> ""
                         OR COALESCE(s.customer_contact, "") <> ""
                         OR COALESCE(s.delivery_note, "") <> ""
                       )';

        $params = [];

        if ($q !== '') {
            $sqlFrom .= ' AND (
                            s.customer_name LIKE :q
                         OR s.customer_contact LIKE :q
                         OR s.delivery_note LIKE :q
                         OR s.receipt_no LIKE :q
                         )';
            $params[':q'] = '%' . $q . '%';
        }

        if ($fromDate !== '') {
            $sqlFrom .= ' AND DATE(s.sold_at) >= :from_date';
            $params[':from_date'] = $fromDate;
        }

        if ($toDate !== '') {
            $sqlFrom .= ' AND DATE(s.sold_at) <= :to_date';
            $params[':to_date'] = $toDate;
        }

        $countStmt = $pdo->prepare('SELECT COUNT(*)' . $sqlFrom);
        foreach ($params as $k => $v) {
            $countStmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $countStmt->execute();
        $totalRows = (int) $countStmt->fetchColumn();
        $totalPages = max(1, (int) ceil($totalRows / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
            $offset = ($page - 1) * $perPage;
        }

        $stmt = $pdo->prepare(
            'SELECT s.id, s.receipt_no, s.sold_at, s.total_amount, s.customer_name, s.customer_contact, s.delivery_note, s.customer_consent_at,
                    u.full_name AS cashier_name'
            . $sqlFrom .
            ' ORDER BY s.sold_at DESC, s.id DESC
              LIMIT :limit_rows OFFSET :offset_rows'
        );

        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit_rows', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset_rows', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $throwable) {
    error_log('customer lookup failure: ' . $throwable->getMessage());
    $error = 'Could not load customer lookup data.';
}

$prevPage = max(1, $page - 1);
$nextPage = min($totalPages, $page + 1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= e($shopName) ?> Customer Lookup</title>
  <script src="assets/vendor/tailwindcss/tailwindcss.js"></script>
  <link rel="stylesheet" href="assets/css/ambient-layer.css" />
  <link rel="stylesheet" href="assets/css/y2k-global.css" />
</head>
<body class="ambient-soft min-h-screen bg-slate-950 text-slate-100">
  <main class="relative z-10 mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:px-8">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
      <div>
        <h1 class="text-2xl font-semibold">Customer Lookup</h1>
        <p class="text-sm text-slate-300">Minimal details for delivery follow-up with consent.</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <a href="index.php" class="utility-link">Checkout</a>
        <a href="receipt_history.php" class="utility-link">Receipt History</a>
      </div>
    </div>

    <?php if ($error !== null): ?>
      <div class="mb-3 rounded-xl border border-rose-300/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-100"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if (!$schemaReady): ?>
      <div class="mb-3 rounded-xl border border-amber-300/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-100">
        Customer trace fields are not available yet. Run migrations using <strong>php deploy.php</strong>, then refresh this page.
      </div>
    <?php else: ?>
      <form method="get" class="mb-4 grid gap-3 rounded-2xl border border-white/10 bg-slate-900/50 p-4 sm:grid-cols-4">
        <label class="text-sm sm:col-span-2">
          <span class="mb-1 block text-slate-300">Search</span>
          <input name="q" value="<?= e($q) ?>" placeholder="name, contact, note, or receipt" class="w-full rounded-lg border border-white/15 bg-slate-950/60 px-3 py-2" />
        </label>
        <label class="text-sm">
          <span class="mb-1 block text-slate-300">From</span>
          <input type="date" name="from" value="<?= e($fromDate) ?>" class="w-full rounded-lg border border-white/15 bg-slate-950/60 px-3 py-2" />
        </label>
        <label class="text-sm">
          <span class="mb-1 block text-slate-300">To</span>
          <input type="date" name="to" value="<?= e($toDate) ?>" class="w-full rounded-lg border border-white/15 bg-slate-950/60 px-3 py-2" />
        </label>
        <div class="sm:col-span-4 flex flex-wrap gap-2">
          <button class="rounded-lg bg-cyan-400 px-4 py-2 text-sm font-semibold text-slate-900">Apply</button>
          <a href="customer_lookup.php" class="rounded-lg border border-white/20 px-4 py-2 text-sm">Reset</a>
        </div>
      </form>

      <div class="overflow-x-auto rounded-2xl border border-white/10 bg-slate-900/45">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-950/60 text-slate-300">
            <tr>
              <th class="px-3 py-2 text-left">Date</th>
              <th class="px-3 py-2 text-left">Receipt</th>
              <th class="px-3 py-2 text-left">Customer</th>
              <th class="px-3 py-2 text-left">Contact</th>
              <th class="px-3 py-2 text-left">Delivery Note</th>
              <th class="px-3 py-2 text-left">Consent</th>
              <th class="px-3 py-2 text-right">Total</th>
              <th class="px-3 py-2 text-left">Cashier</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($rows === []): ?>
              <tr><td colspan="8" class="px-3 py-6 text-center text-slate-400">No matching customer records found.</td></tr>
            <?php else: ?>
              <?php foreach ($rows as $row): ?>
                <tr class="border-t border-white/5">
                  <td class="px-3 py-2 text-slate-300"><?= e((string) ($row['sold_at'] ?? '')) ?></td>
                  <td class="px-3 py-2"><a class="text-cyan-300 hover:text-cyan-200" href="receipt.php?sale_id=<?= (int) ($row['id'] ?? 0) ?>"><?= e((string) ($row['receipt_no'] ?? '')) ?></a></td>
                  <td class="px-3 py-2"><?= e((string) ($row['customer_name'] ?? '')) ?></td>
                  <td class="px-3 py-2"><?= e((string) ($row['customer_contact'] ?? '')) ?></td>
                  <td class="px-3 py-2"><?= e((string) ($row['delivery_note'] ?? '')) ?></td>
                  <td class="px-3 py-2"><?= ((string) ($row['customer_consent_at'] ?? '')) !== '' ? 'Yes' : 'No' ?></td>
                  <td class="px-3 py-2 text-right"><?= e($currencySymbol) ?><?= number_format((float) ($row['total_amount'] ?? 0), 2) ?></td>
                  <td class="px-3 py-2 text-slate-300"><?= e((string) ($row['cashier_name'] ?? '')) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div class="mt-4 flex items-center justify-between text-sm text-slate-300">
        <span>Page <?= $page ?> of <?= $totalPages ?> (<?= $totalRows ?> records)</span>
        <div class="flex gap-2">
          <a class="rounded border border-white/20 px-3 py-1.5 <?= $page <= 1 ? 'pointer-events-none opacity-40' : '' ?>" href="?q=<?= urlencode($q) ?>&from=<?= urlencode($fromDate) ?>&to=<?= urlencode($toDate) ?>&page=<?= $prevPage ?>">Prev</a>
          <a class="rounded border border-white/20 px-3 py-1.5 <?= $page >= $totalPages ? 'pointer-events-none opacity-40' : '' ?>" href="?q=<?= urlencode($q) ?>&from=<?= urlencode($fromDate) ?>&to=<?= urlencode($toDate) ?>&page=<?= $nextPage ?>">Next</a>
        </div>
      </div>
    <?php endif; ?>
  </main>
  <script src="assets/js/ambient-layer.js"></script>
  <script src="assets/js/y2k-global.js"></script>
  <script>
    window.AmbientLayer?.init();
    window.NovaY2K?.init();
  </script>
</body>
</html>
