<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Models\ShopSettings;

$currentUser = Auth::requirePageAuth(['admin']);
Auth::requireCapability('audit.view');

$shop = ShopSettings::get();
$shopName = (string) ($shop['shop_name'] ?? 'My Shop');

$action = trim((string) ($_GET['action'] ?? ''));
$entityType = trim((string) ($_GET['entity_type'] ?? ''));
$fromDate = trim((string) ($_GET['from'] ?? ''));
$toDate = trim((string) ($_GET['to'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;
$exportCsv = ((string) ($_GET['export'] ?? '')) === 'csv';

$rows = [];
$totalRows = 0;
$totalPages = 1;
$error = null;

try {
    $pdo = Database::connection();

    $baseSql = ' FROM audit_logs a
                 LEFT JOIN users u ON u.id = a.actor_user_id
                 WHERE 1=1';
    $params = [];

    if ($action !== '') {
        $baseSql .= ' AND a.action = :action';
        $params[':action'] = $action;
    }

    if ($entityType !== '') {
        $baseSql .= ' AND a.entity_type = :entity_type';
        $params[':entity_type'] = $entityType;
    }

    if ($fromDate !== '') {
        $baseSql .= ' AND DATE(a.created_at) >= :from_date';
        $params[':from_date'] = $fromDate;
    }

    if ($toDate !== '') {
        $baseSql .= ' AND DATE(a.created_at) <= :to_date';
        $params[':to_date'] = $toDate;
    }

    $countStmt = $pdo->prepare('SELECT COUNT(*)' . $baseSql);
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

    $selectSql = 'SELECT a.id, a.created_at, a.action, a.entity_type, a.entity_id, a.details_json, a.ip_address,
                         u.full_name AS actor_name'
        . $baseSql
        . ' ORDER BY a.created_at DESC, a.id DESC';

    if ($exportCsv) {
        $csvStmt = $pdo->prepare($selectSql . ' LIMIT 5000');
        foreach ($params as $key => $value) {
            $csvStmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $csvStmt->execute();
        $csvRows = $csvStmt->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="audit-logs-' . date('Ymd-His') . '.csv"');

        $out = fopen('php://output', 'wb');
        if ($out !== false) {
            fputcsv($out, ['id', 'created_at', 'actor_name', 'action', 'entity_type', 'entity_id', 'ip_address', 'details_json']);
            foreach ($csvRows as $row) {
                fputcsv($out, [
                    (int) ($row['id'] ?? 0),
                    (string) ($row['created_at'] ?? ''),
                    (string) ($row['actor_name'] ?? ''),
                    (string) ($row['action'] ?? ''),
                    (string) ($row['entity_type'] ?? ''),
                    (string) ($row['entity_id'] ?? ''),
                    (string) ($row['ip_address'] ?? ''),
                    (string) ($row['details_json'] ?? ''),
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
} catch (Throwable $throwable) {
    error_log('audit logs page failure: ' . $throwable->getMessage());
    $error = 'Could not load audit logs.';
}

$baseQuery = [
    'action' => $action,
    'entity_type' => $entityType,
    'from' => $fromDate,
    'to' => $toDate,
];
$prevQuery = $baseQuery;
$prevQuery['page'] = (string) max(1, $page - 1);
$nextQuery = $baseQuery;
$nextQuery['page'] = (string) min($totalPages, $page + 1);
$csvQuery = $baseQuery;
$csvQuery['export'] = 'csv';

/**
 * @param mixed $value
 */
function formatAuditDetailValue(string $key, $value, string $currencySymbol, ?string $action = null): string
{
  $normalizedAction = strtolower(trim((string) $action));

  if (is_bool($value)) {
    return $value ? 'yes' : 'no';
  }

  if (is_array($value) || is_object($value)) {
    $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
    return $encoded === false ? '[unserializable]' : $encoded;
  }

  if ($value === null) {
    return '-';
  }

  $normalizedKey = strtolower($key);

  if ($normalizedKey === 'payment_method' && is_string($value)) {
    return ucwords(str_replace(['_', '-'], ' ', $value));
  }

  if ($normalizedKey === 'identity' && trim((string) $value) === '') {
    return '-';
  }

  if ($normalizedAction === 'login.rate_limited' && $normalizedKey === 'retry_after' && is_numeric($value)) {
    $seconds = max(0, (int) round((float) $value));
    if ($seconds === 1) {
      return '1 second';
    }
    return number_format((float) $seconds, 0, '.', ',') . ' seconds';
  }

  if ($normalizedAction === 'inventory.adjusted' && $normalizedKey === 'qty_change' && is_numeric($value)) {
    $delta = (int) $value;
    if ($delta > 0) {
      return '+' . (string) $delta;
    }
    return (string) $delta;
  }

  $isCurrencyField = preg_match('/(total|amount|price|cost|subtotal|tax|discount|balance|change|cash)/', $normalizedKey) === 1;
  if ($isCurrencyField && is_numeric($value)) {
    return $currencySymbol . number_format((float) $value, 2);
  }

  if (is_numeric($value)) {
    $numeric = (float) $value;
    if (fmod($numeric, 1.0) === 0.0) {
      return number_format($numeric, 0, '.', ',');
    }
  }

  $isDateField = preg_match('/(date|time|_at|clock|created|updated|sold)/', $normalizedKey) === 1;
  if ($isDateField && is_string($value)) {
    $timestamp = strtotime($value);
    if ($timestamp !== false) {
      return date('M j, Y g:i A', $timestamp);
    }
  }

  return (string) $value;
}

function formatAuditDetailLabel(string $key, ?string $action = null): string
{
  static $labelMap = [
    'receipt_no' => 'Receipt #',
    'payment_method' => 'Payment Method',
    'identity' => 'Login Identity',
    'retry_after' => 'Retry After',
    'cashier_user_id' => 'Cashier User ID',
    'cashier_name' => 'Cashier',
    'sale_id' => 'Sale ID',
    'user_id' => 'User ID',
    'username' => 'Username',
    'full_name' => 'Full Name',
    'email' => 'Email',
    'role' => 'Role',
    'is_active' => 'Active',
    'created' => 'Created',
    'skipped' => 'Skipped',
    'qty_change' => 'Quantity Change',
    'stock_before' => 'Stock Before',
    'stock_after' => 'Stock After',
    'product_id' => 'Product ID',
    'product_name' => 'Product Name',
    'sku' => 'SKU',
    'entity_type' => 'Entity Type',
    'entity_id' => 'Entity ID',
    'ip_address' => 'IP Address',
    'user_agent' => 'User Agent',
    'clock_in' => 'Clock In',
    'clock_out' => 'Clock Out',
    'created_at' => 'Created At',
    'updated_at' => 'Updated At',
    'total' => 'Total',
    'subtotal' => 'Subtotal',
    'tax' => 'Tax',
    'tax_amount' => 'Tax Amount',
    'discount' => 'Discount',
    'discount_amount' => 'Discount Amount',
    'amount' => 'Amount',
  ];

  static $actionLabelMap = [
    'checkout.completed' => [
      'total' => 'Sale Total',
      'payment_method' => 'Tender Type',
    ],
    'inventory.adjusted' => [
      'qty_change' => 'Adjusted Quantity',
      'stock_before' => 'Previous Stock',
      'stock_after' => 'New Stock',
    ],
    'login.failed' => [
      'identity' => 'Attempted Username',
    ],
    'login.rate_limited' => [
      'identity' => 'Attempted Username',
      'retry_after' => 'Retry In',
    ],
    'user.bulk_imported' => [
      'created' => 'Users Created',
      'skipped' => 'Rows Skipped',
    ],
  ];

  $normalized = strtolower($key);
  $normalizedAction = strtolower(trim((string) $action));

  if ($normalizedAction !== '' && isset($actionLabelMap[$normalizedAction][$normalized])) {
    return $actionLabelMap[$normalizedAction][$normalized];
  }

  if (isset($labelMap[$normalized])) {
    return $labelMap[$normalized];
  }

  return ucfirst(str_replace('_', ' ', $key));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= e($shopName) ?> Audit Logs</title>
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
<body class="ambient-medium min-h-screen text-slate-100 antialiased">
  <a href="#mainContent" class="skip-link">Skip to audit logs content</a>
  <div class="matrix-grid" aria-hidden="true"></div>
  <div class="scanner-line" aria-hidden="true"></div>
  <div class="retro-orbs" aria-hidden="true">
    <span class="orb orb-a"></span>
    <span class="orb orb-b"></span>
  </div>
  <main id="mainContent" class="relative z-10 mx-auto max-w-7xl px-4 py-6 sm:px-6">
    <header class="mb-6 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold">Audit Logs</h1>
        <p class="text-sm text-slate-300">Signed in as <?= e((string) $currentUser['full_name']) ?> (admin)</p>
      </div>
      <div class="flex flex-wrap gap-2" aria-label="Audit navigation">
        <a href="dashboard.php" class="utility-link">Dashboard</a>
        <a href="settings.php" class="utility-link">Settings</a>
        <a href="index.php" class="utility-link">Checkout</a>
        <button type="button" id="themeToggle" class="utility-link inline-flex items-center gap-1.5" aria-label="Toggle theme">
          <span id="themeToggleIcon" class="inline-block w-4 text-center" aria-hidden="true">&#9790;</span>
          <span id="themeToggleText">Dark</span>
        </button>
      </div>
    </header>

    <section class="mb-4 rounded-2xl border border-white/10 bg-slate-900/60 p-4">
      <form method="get" class="grid gap-3 sm:grid-cols-4">
        <label class="text-sm">
          <span class="mb-1 block text-slate-300">Action</span>
          <input name="action" value="<?= e($action) ?>" placeholder="e.g. user.updated" class="w-full rounded-xl border border-white/15 bg-slate-950/60 px-3 py-2 outline-none focus:border-cyan-300" />
        </label>
        <label class="text-sm">
          <span class="mb-1 block text-slate-300">Entity</span>
          <input name="entity_type" value="<?= e($entityType) ?>" placeholder="user, product, sale" class="w-full rounded-xl border border-white/15 bg-slate-950/60 px-3 py-2 outline-none focus:border-cyan-300" />
        </label>
        <label class="text-sm">
          <span class="mb-1 block text-slate-300">From</span>
          <input type="date" name="from" value="<?= e($fromDate) ?>" class="w-full rounded-xl border border-white/15 bg-slate-950/60 px-3 py-2 outline-none focus:border-cyan-300" />
        </label>
        <label class="text-sm">
          <span class="mb-1 block text-slate-300">To</span>
          <input type="date" name="to" value="<?= e($toDate) ?>" class="w-full rounded-xl border border-white/15 bg-slate-950/60 px-3 py-2 outline-none focus:border-cyan-300" />
        </label>
        <div class="sm:col-span-4 flex flex-wrap gap-2">
          <button class="min-h-[42px] rounded-xl bg-gradient-to-r from-cyan-400 to-emerald-400 px-4 py-2 text-sm font-semibold text-slate-900">Apply Filters</button>
          <a href="audit_logs.php" class="rounded-xl border border-white/20 px-4 py-2 text-sm hover:bg-white/10">Reset</a>
          <a href="audit_logs.php?<?= e(http_build_query($csvQuery)) ?>" class="rounded-xl border border-cyan-300/35 bg-cyan-500/10 px-4 py-2 text-sm text-cyan-100 hover:bg-cyan-500/20">Export CSV</a>
        </div>
      </form>
    </section>

    <?php if ($error !== null): ?>
      <div class="mb-4 rounded-xl border border-rose-300/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-100"><?= e($error) ?></div>
    <?php endif; ?>

    <section class="overflow-x-auto rounded-2xl border border-white/10 bg-slate-900/60">
      <table class="min-w-full text-sm">
        <thead class="bg-white/5 text-slate-300">
          <tr>
            <th class="px-3 py-2.5 text-left">When</th>
            <th class="px-3 py-2.5 text-left">Actor</th>
            <th class="px-3 py-2.5 text-left">Action</th>
            <th class="px-3 py-2.5 text-left">Entity</th>
            <th class="px-3 py-2.5 text-left">IP</th>
            <th class="px-3 py-2.5 text-left">Details</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($rows === []): ?>
            <tr>
              <td colspan="6" class="px-3 py-4 text-slate-300">No audit events found.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($rows as $row): ?>
              <?php
                $entityLabel = trim((string) ($row['entity_type'] ?? ''));
                if ($entityLabel !== '' && ($row['entity_id'] ?? null) !== null) {
                    $entityLabel .= '#' . (string) $row['entity_id'];
                }

                $ipAddress = trim((string) ($row['ip_address'] ?? ''));

                $detailsRaw = trim((string) ($row['details_json'] ?? ''));
                $detailsList = [];
                if ($detailsRaw !== '') {
                    $decoded = json_decode($detailsRaw, true);
                    if (is_array($decoded)) {
                        foreach ($decoded as $key => $value) {
                        $valueText = formatAuditDetailValue(
                          (string) $key,
                          $value,
                          (string) ($shop['currency_symbol'] ?? '$'),
                          (string) ($row['action'] ?? '')
                        );

                      $label = formatAuditDetailLabel((string) $key, (string) ($row['action'] ?? ''));
                            $detailsList[] = ['label' => $label, 'value' => $valueText];
                        }
                    } else {
                        $detailsList[] = ['label' => 'Details', 'value' => $detailsRaw];
                    }
                }
              ?>
              <tr class="border-t border-white/10 align-top">
                <td class="px-3 py-2.5 text-slate-300"><?= e((string) $row['created_at']) ?></td>
                <td class="px-3 py-2.5 text-slate-200"><?= e((string) ($row['actor_name'] ?? 'System')) ?></td>
                <td class="px-3 py-2.5 text-cyan-200"><?= e((string) $row['action']) ?></td>
                <td class="px-3 py-2.5 text-slate-300"><?= e($entityLabel) ?></td>
                <td class="px-3 py-2.5 text-slate-300"><?= $ipAddress !== '' ? e($ipAddress) : '-' ?></td>
                <td class="px-3 py-2 text-xs text-slate-300">
                  <?php if ($detailsList === []): ?>
                    <span class="text-slate-500">-</span>
                  <?php else: ?>
                    <div class="space-y-1">
                      <?php foreach ($detailsList as $detail): ?>
                        <div class="flex flex-wrap items-start gap-1">
                          <span class="rounded bg-white/10 px-1.5 py-0.5 text-[10px] text-slate-200"><?= e((string) $detail['label']) ?></span>
                          <span class="break-all text-slate-300"><?= e((string) $detail['value']) ?></span>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </section>

    <section class="mt-4 flex items-center justify-between text-sm text-slate-300">
      <span>Total events: <?= $totalRows ?></span>
      <div class="flex items-center gap-2">
        <a href="audit_logs.php?<?= e(http_build_query($prevQuery)) ?>" class="rounded-lg border border-white/20 px-3 py-1.5 <?= $page <= 1 ? 'pointer-events-none opacity-40' : 'hover:bg-white/10' ?>">Prev</a>
        <span class="rounded-lg border border-white/10 bg-white/5 px-3 py-1.5">Page <?= $page ?> / <?= $totalPages ?></span>
        <a href="audit_logs.php?<?= e(http_build_query($nextQuery)) ?>" class="rounded-lg border border-white/20 px-3 py-1.5 <?= $page >= $totalPages ? 'pointer-events-none opacity-40' : 'hover:bg-white/10' ?>">Next</a>
      </div>
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


