<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Models\ShopSettings;

/**
 * Returns YYYY-MM-DD if valid, otherwise empty string.
 */
function normalizedDateInput(string $value): string
{
  $value = trim($value);
  if ($value === '') {
    return '';
  }

  return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
}

$currentUser = Auth::requirePageAuth(['admin', 'manager', 'cashier']);
$shopSettings = ShopSettings::get();
$shopName = (string) ($shopSettings['shop_name'] ?? 'My Shop');
$currencySymbol = (string) ($shopSettings['currency_symbol'] ?? '$');
$canExport = Auth::hasCapability('receipts.export');

$search = trim((string) ($_GET['q'] ?? ''));
$fromDate = normalizedDateInput((string) ($_GET['from'] ?? ''));
$toDate = normalizedDateInput((string) ($_GET['to'] ?? ''));
$preset = trim((string) ($_GET['preset'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPageRequested = (int) ($_GET['per_page'] ?? 50);
$allowedPageSizes = [20, 50, 100];
$perPage = in_array($perPageRequested, $allowedPageSizes, true) ? $perPageRequested : 50;
$offset = ($page - 1) * $perPage;
$exportCsv = ((string) ($_GET['export'] ?? '')) === 'csv';

$today = date('Y-m-d');

if ($preset === 'today') {
  $fromDate = $today;
  $toDate = $today;
} elseif ($preset === 'last7') {
  $fromDate = date('Y-m-d', strtotime('-6 days'));
  $toDate = $today;
} elseif ($preset === 'month') {
  $fromDate = date('Y-m-01');
  $toDate = $today;
}

$totalRows = 0;
$totalPages = 1;
$fromRow = 0;
$toRow = 0;

$rows = [];
$error = null;

try {
    $pdo = Database::connection();

    $baseFromSql = ' FROM sales s
             INNER JOIN users u ON u.id = s.cashier_user_id
             WHERE 1=1';

    $params = [];

    if ($search !== '') {
      $baseFromSql .= ' AND (s.receipt_no LIKE :q OR CAST(s.id AS CHAR) LIKE :q OR u.full_name LIKE :q)';
        $params[':q'] = '%' . $search . '%';
    }

    if ($fromDate !== '') {
      $baseFromSql .= ' AND DATE(s.sold_at) >= :from_date';
        $params[':from_date'] = $fromDate;
    }

    if ($toDate !== '') {
      $baseFromSql .= ' AND DATE(s.sold_at) <= :to_date';
        $params[':to_date'] = $toDate;
    }

    $countStmt = $pdo->prepare('SELECT COUNT(*)' . $baseFromSql);
    foreach ($params as $key => $value) {
      $countStmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $countStmt->execute();
    $totalRows = (int) $countStmt->fetchColumn();

    $totalPages = max(1, (int) ceil($totalRows / $perPage));
    if ($page > $totalPages) {
      $page = $totalPages;
      $offset = ($page - 1) * $perPage;
    }

    $selectSql = 'SELECT s.id, s.receipt_no, s.sold_at, s.total_amount, s.payment_method, u.full_name AS cashier_name'
      . $baseFromSql
      . ' ORDER BY s.sold_at DESC, s.id DESC';

    if ($exportCsv) {
      if (!$canExport) {
        http_response_code(403);
        exit('Forbidden');
      }

      $csvStmt = $pdo->prepare($selectSql . ' LIMIT 5000');
      foreach ($params as $key => $value) {
        $csvStmt->bindValue($key, $value, PDO::PARAM_STR);
      }
      $csvStmt->execute();
      $csvRows = $csvStmt->fetchAll(PDO::FETCH_ASSOC);

      $filename = 'receipt-history-' . date('Ymd-His') . '.csv';
      header('Content-Type: text/csv; charset=utf-8');
      header('Content-Disposition: attachment; filename="' . $filename . '"');

      $out = fopen('php://output', 'wb');
      if ($out !== false) {
        fputcsv($out, ['sale_id', 'receipt_no', 'sold_at', 'cashier_name', 'payment_method', 'total_amount']);
        foreach ($csvRows as $csvRow) {
          fputcsv($out, [
            (int) ($csvRow['id'] ?? 0),
            (string) ($csvRow['receipt_no'] ?? ''),
            (string) ($csvRow['sold_at'] ?? ''),
            (string) ($csvRow['cashier_name'] ?? ''),
            (string) ($csvRow['payment_method'] ?? ''),
            (float) ($csvRow['total_amount'] ?? 0),
          ]);
        }
        fclose($out);
      }

      exit;
    }

    $stmt = $pdo->prepare($selectSql . ' LIMIT :per_page OFFSET :offset_rows');

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }

    $stmt->bindValue(':per_page', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset_rows', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($totalRows > 0) {
      $fromRow = $offset + 1;
      $toRow = $offset + count($rows);
    }
} catch (Throwable $throwable) {
    error_log('receipt history failure: ' . $throwable->getMessage());
    $error = 'Could not load receipt history.';
}

  $baseQuery = [
    'q' => $search,
    'from' => $fromDate,
    'to' => $toDate,
    'preset' => $preset,
    'per_page' => (string) $perPage,
  ];

  $csvQuery = $baseQuery;
  $csvQuery['export'] = 'csv';

  $prevQuery = $baseQuery;
  $prevQuery['page'] = (string) max(1, $page - 1);

  $nextQuery = $baseQuery;
  $nextQuery['page'] = (string) min($totalPages, $page + 1);

  $presetTodayQuery = [
    'preset' => 'today',
    'q' => $search,
    'per_page' => (string) $perPage,
  ];

  $presetLast7Query = [
    'preset' => 'last7',
    'q' => $search,
    'per_page' => (string) $perPage,
  ];

  $presetMonthQuery = [
    'preset' => 'month',
    'q' => $search,
    'per_page' => (string) $perPage,
  ];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= e($shopName) ?> Receipt History</title>
  <script src="assets/vendor/tailwindcss/tailwindcss.js"></script>
  <style>
    body {
      font-family: 'Space Grotesk', sans-serif;
      background:
        radial-gradient(circle at 12% 15%, rgba(6, 182, 212, 0.18), transparent 30%),
        radial-gradient(circle at 80% 8%, rgba(34, 211, 170, 0.14), transparent 26%),
        radial-gradient(circle at 84% 88%, rgba(251, 113, 133, 0.16), transparent 26%),
        #070b14;
      min-height: 100vh;
    }

    .glass {
      background: linear-gradient(145deg, rgba(255, 255, 255, 0.1), rgba(255, 255, 255, 0.04));
      backdrop-filter: blur(18px);
      -webkit-backdrop-filter: blur(18px);
      border: 1px solid rgba(255, 255, 255, 0.14);
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
<body class="text-slate-100 antialiased">
  <a href="#mainContent" class="skip-link">Skip to receipt history content</a>
  <main id="mainContent" class="mx-auto max-w-[1400px] px-4 py-6 sm:px-6 lg:px-8">
    <header class="mb-6 flex flex-wrap items-center justify-between gap-3">
      <div>
        <p class="font-display text-xs uppercase tracking-[0.28em] text-cyan-300"><?= e(strtoupper($shopName) . ' POS') ?></p>
        <h1 class="font-display text-2xl font-semibold text-white sm:text-3xl">Receipt History</h1>
        <p class="text-xs text-slate-300">Signed in as <?= e((string) $currentUser['full_name']) ?> (<?= e((string) $currentUser['role']) ?>)</p>
      </div>
      <div class="flex flex-wrap gap-2" aria-label="Receipt history navigation">
        <a href="index.php" class="utility-link">Back to Checkout</a>
        <?php if ((string) $currentUser['role'] !== 'cashier'): ?>
          <a href="dashboard.php" class="utility-link">Dashboard</a>
        <?php endif; ?>
      </div>
    </header>

    <section class="glass mb-4 rounded-2xl p-4 sm:p-5">
      <div class="mb-3 flex flex-wrap gap-2">
        <a href="receipt_history.php?<?= e(http_build_query($presetTodayQuery)) ?>" class="rounded-lg border border-white/20 px-3 py-1.5 text-xs hover:bg-white/10">Today</a>
        <a href="receipt_history.php?<?= e(http_build_query($presetLast7Query)) ?>" class="rounded-lg border border-white/20 px-3 py-1.5 text-xs hover:bg-white/10">Last 7 Days</a>
        <a href="receipt_history.php?<?= e(http_build_query($presetMonthQuery)) ?>" class="rounded-lg border border-white/20 px-3 py-1.5 text-xs hover:bg-white/10">This Month</a>
      </div>

      <form method="get" class="grid gap-3 sm:grid-cols-5">
        <input type="hidden" name="preset" value="" />
        <label class="text-sm sm:col-span-2">
          <span class="mb-1 block text-slate-300">Find Receipt / Sale / Cashier</span>
          <input
            type="search"
            name="q"
            value="<?= e($search) ?>"
            placeholder="e.g. RCP-20260317 or 1024"
            class="w-full rounded-xl border border-white/15 bg-slate-900/70 px-3 py-2 text-slate-100 placeholder:text-slate-400 outline-none focus:border-cyan-300"
          />
        </label>

        <label class="text-sm">
          <span class="mb-1 block text-slate-300">From</span>
          <input type="date" name="from" value="<?= e($fromDate) ?>" class="w-full rounded-xl border border-white/15 bg-slate-900/70 px-3 py-2 text-slate-100 outline-none focus:border-cyan-300" />
        </label>

        <label class="text-sm">
          <span class="mb-1 block text-slate-300">To</span>
          <input type="date" name="to" value="<?= e($toDate) ?>" class="w-full rounded-xl border border-white/15 bg-slate-900/70 px-3 py-2 text-slate-100 outline-none focus:border-cyan-300" />
        </label>

        <label class="text-sm">
          <span class="mb-1 block text-slate-300">Rows</span>
          <select name="per_page" class="w-full rounded-xl border border-white/15 bg-slate-900/70 px-3 py-2 text-slate-100 outline-none focus:border-cyan-300">
            <?php foreach ($allowedPageSizes as $size): ?>
              <option value="<?= $size ?>" <?= $perPage === $size ? 'selected' : '' ?>><?= $size ?></option>
            <?php endforeach; ?>
          </select>
        </label>

        <div class="sm:col-span-5 flex flex-wrap gap-2">
          <button class="min-h-[42px] rounded-xl bg-gradient-to-r from-cyan-400 to-emerald-400 px-4 py-2 text-sm font-semibold text-slate-900">Apply Filters</button>
          <a href="receipt_history.php" class="rounded-xl border border-white/20 px-4 py-2 text-sm hover:bg-white/10">Reset</a>
          <?php if ($canExport): ?>
            <a href="receipt_history.php?<?= e(http_build_query($csvQuery)) ?>" class="rounded-xl border border-cyan-300/35 bg-cyan-500/10 px-4 py-2 text-sm text-cyan-100 hover:bg-cyan-500/20">Export CSV</a>
          <?php endif; ?>
        </div>
      </form>
    </section>

    <?php if ($error !== null): ?>
      <div class="mb-4 rounded-2xl border border-rose-400/25 bg-rose-500/10 px-4 py-3 text-sm text-rose-100">
        <?= e($error) ?>
      </div>
    <?php endif; ?>

    <section class="glass overflow-x-auto rounded-2xl">
      <table class="min-w-full text-sm">
        <thead class="bg-white/5 text-slate-300">
          <tr>
            <th class="px-3 py-3 text-left">Sale ID</th>
            <th class="px-3 py-3 text-left">Receipt No</th>
            <th class="px-3 py-3 text-left">Sold At</th>
            <th class="px-3 py-3 text-left">Cashier</th>
            <th class="px-3 py-3 text-left">Payment</th>
            <th class="px-3 py-3 text-right">Total</th>
            <th class="px-3 py-3 text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($rows === []): ?>
            <tr>
              <td colspan="7" class="px-3 py-6 text-center text-slate-300">No receipts found for current filter.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($rows as $row): ?>
              <?php $saleId = (int) $row['id']; ?>
              <tr class="border-t border-white/10">
                <td class="px-3 py-2.5 text-slate-200">#<?= $saleId ?></td>
                <td class="px-3 py-2 font-medium text-white"><?= e((string) $row['receipt_no']) ?></td>
                <td class="px-3 py-2.5 text-slate-300"><?= e((string) $row['sold_at']) ?></td>
                <td class="px-3 py-2.5 text-slate-300"><?= e((string) $row['cashier_name']) ?></td>
                <td class="px-3 py-2.5 text-slate-300"><?= e((string) $row['payment_method']) ?></td>
                <td class="px-3 py-2.5 text-right text-slate-100"><?= e($currencySymbol) ?><?= number_format((float) $row['total_amount'], 2) ?></td>
                <td class="px-3 py-2.5">
                  <div class="flex justify-end gap-2">
                    <a href="receipt.php?sale_id=<?= $saleId ?>" target="_blank" rel="noopener" class="rounded-lg border border-white/20 px-2 py-1 text-xs hover:bg-white/10">View</a>
                    <a href="receipt.php?sale_id=<?= $saleId ?>&print=1" target="_blank" rel="noopener" class="rounded-lg border border-cyan-300/35 bg-cyan-500/10 px-2 py-1 text-xs text-cyan-100 hover:bg-cyan-500/20">Print</a>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </section>

    <section class="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm text-slate-300">
      <p>
        <?php if ($totalRows > 0): ?>
          Showing <?= $fromRow ?>-<?= $toRow ?> of <?= $totalRows ?> receipts
        <?php else: ?>
          Showing 0 receipts
        <?php endif; ?>
      </p>

      <div class="flex items-center gap-2">
        <a
          href="receipt_history.php?<?= e(http_build_query($prevQuery)) ?>"
          class="rounded-lg border border-white/20 px-3 py-1.5 <?= $page <= 1 ? 'pointer-events-none opacity-40' : 'hover:bg-white/10' ?>"
        >
          Prev
        </a>
        <span class="rounded-lg border border-white/10 bg-white/5 px-3 py-1.5">Page <?= $page ?> / <?= $totalPages ?></span>
        <a
          href="receipt_history.php?<?= e(http_build_query($nextQuery)) ?>"
          class="rounded-lg border border-white/20 px-3 py-1.5 <?= $page >= $totalPages ? 'pointer-events-none opacity-40' : 'hover:bg-white/10' ?>"
        >
          Next
        </a>
      </div>
    </section>
  </main>
</body>
</html>



