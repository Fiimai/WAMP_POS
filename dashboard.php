<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Models\ShopSettings;

$currentUser = Auth::requirePageAuth(['admin', 'manager']);
$shopSettings = ShopSettings::get();
$shopName = (string) ($shopSettings['shop_name'] ?? 'My Shop');
$currencySymbol = (string) ($shopSettings['currency_symbol'] ?? '$');

$todaySales = 0.0;
$todayTransactions = 0;
$topProducts = [];
$lowStockProducts = [];
$trendLabels = [];
$trendSales = [];
$databaseError = null;

try {
    $pdo = Database::connection();

    $totalsStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(total_amount), 0) AS total_sales, COUNT(*) AS tx_count
         FROM sales
         WHERE DATE(sold_at) = CURDATE()'
    );
    $totalsStmt->execute();
    $totals = $totalsStmt->fetch(PDO::FETCH_ASSOC) ?: ['total_sales' => 0, 'tx_count' => 0];

    $todaySales = (float) $totals['total_sales'];
    $todayTransactions = (int) $totals['tx_count'];

    $topStmt = $pdo->prepare(
        'SELECT p.name,
                SUM(si.qty) AS units_sold,
                SUM(si.line_total) AS revenue
         FROM sale_items si
         INNER JOIN sales s ON s.id = si.sale_id
         INNER JOIN products p ON p.id = si.product_id
         WHERE DATE(s.sold_at) = CURDATE()
         GROUP BY p.id, p.name
         ORDER BY units_sold DESC, revenue DESC
         LIMIT 5'
    );
    $topStmt->execute();
    $topProducts = $topStmt->fetchAll(PDO::FETCH_ASSOC);

    $lowStockStmt = $pdo->prepare(
        'SELECT id, name, stock_qty, reorder_level
         FROM products
         WHERE is_active = 1
           AND stock_qty <= reorder_level
         ORDER BY stock_qty ASC, name ASC
         LIMIT 8'
    );
    $lowStockStmt->execute();
    $lowStockProducts = $lowStockStmt->fetchAll(PDO::FETCH_ASSOC);

    $trendStmt = $pdo->prepare(
        'SELECT DATE(sold_at) AS day, COALESCE(SUM(total_amount), 0) AS total
         FROM sales
         WHERE sold_at >= (CURDATE() - INTERVAL 6 DAY)
         GROUP BY DATE(sold_at)
         ORDER BY day ASC'
    );
    $trendStmt->execute();
    $trendRows = $trendStmt->fetchAll(PDO::FETCH_ASSOC);

    $trendMap = [];
    foreach ($trendRows as $row) {
        $trendMap[(string) $row['day']] = (float) $row['total'];
    }

    for ($i = 6; $i >= 0; $i--) {
        $date = new DateTimeImmutable("-{$i} days");
        $key = $date->format('Y-m-d');
        $trendLabels[] = $date->format('D');
        $trendSales[] = $trendMap[$key] ?? 0.0;
    }
} catch (Throwable $exception) {
  error_log('dashboard DB failure: ' . $exception->getMessage());
  $databaseError = 'Database unavailable';
}

$trendLabelsJson = json_encode($trendLabels, JSON_UNESCAPED_SLASHES);
$trendSalesJson = json_encode($trendSales, JSON_UNESCAPED_SLASHES);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= e($shopName) ?> Insights Dashboard</title>

  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Sora:wght@500;600;700&display=swap" rel="stylesheet" />
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>

  <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: {
            sans: ['Space Grotesk', 'ui-sans-serif', 'system-ui'],
            display: ['Sora', 'ui-sans-serif', 'system-ui']
          },
          colors: {
            panel: '#0D1523',
            aqua: '#22D3EE',
            mint: '#34D399',
            coral: '#FB7185'
          },
          boxShadow: {
            soft: '0 20px 50px rgba(2, 6, 23, 0.4)'
          }
        }
      }
    };
  </script>

  <style>
    body {
      background:
        radial-gradient(circle at 8% 10%, rgba(34, 211, 238, 0.2), transparent 32%),
        radial-gradient(circle at 85% 90%, rgba(251, 113, 133, 0.16), transparent 30%),
        #050912;
      min-height: 100vh;
    }

    .glass {
      background: linear-gradient(140deg, rgba(255, 255, 255, 0.11), rgba(255, 255, 255, 0.03));
      border: 1px solid rgba(255, 255, 255, 0.14);
      backdrop-filter: blur(14px);
      -webkit-backdrop-filter: blur(14px);
    }
  </style>
</head>
<body class="text-slate-100 antialiased">
  <main class="mx-auto max-w-[1500px] px-4 py-6 sm:px-6 lg:px-8">
    <header class="mb-6 rounded-2xl border border-white/10 bg-panel/70 p-5 shadow-soft">
      <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
          <p class="font-display text-xs uppercase tracking-[0.3em] text-cyan-300">NovaPOS Intelligence</p>
          <h1 class="mt-1 font-display text-2xl font-semibold text-white sm:text-3xl"><?= e($shopName) ?> Dashboard</h1>
        </div>
        <div class="flex gap-2">
          <?php if ((string) $currentUser['role'] === 'admin'): ?>
            <a href="add_product.php" class="rounded-xl bg-white/10 px-4 py-2 text-sm font-medium text-white hover:bg-white/20">Add Product</a>
            <a href="manage_products.php" class="rounded-xl bg-white/10 px-4 py-2 text-sm font-medium text-white hover:bg-white/20">Manage Products</a>
            <a href="manage_users.php" class="rounded-xl bg-white/10 px-4 py-2 text-sm font-medium text-white hover:bg-white/20">Users</a>
            <a href="audit_logs.php" class="rounded-xl bg-white/10 px-4 py-2 text-sm font-medium text-white hover:bg-white/20">Audit</a>
            <a href="settings.php" class="rounded-xl bg-white/10 px-4 py-2 text-sm font-medium text-white hover:bg-white/20">Settings</a>
          <?php endif; ?>
          <a href="inventory_adjustments.php" class="rounded-xl bg-white/10 px-4 py-2 text-sm font-medium text-white hover:bg-white/20">Inventory</a>
          <a href="receipt_history.php" class="rounded-xl bg-white/10 px-4 py-2 text-sm font-medium text-white hover:bg-white/20">Receipts</a>
          <a href="index.php" class="rounded-xl bg-white/10 px-4 py-2 text-sm font-medium text-white hover:bg-white/20">Back to Checkout</a>
        </div>
      </div>
    </header>

    <?php if ($databaseError !== null): ?>
      <div class="mb-6 rounded-2xl border border-rose-400/30 bg-rose-500/10 p-4 text-sm text-rose-100">
        Dashboard is temporarily unavailable.
      </div>
    <?php endif; ?>

    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <article class="glass rounded-2xl p-4">
        <p class="text-xs uppercase tracking-[0.2em] text-cyan-200/90">Today's Sales</p>
        <p class="mt-2 font-display text-3xl font-semibold text-white"><?= e($currencySymbol) ?><?= number_format($todaySales, 2) ?></p>
      </article>
      <article class="glass rounded-2xl p-4">
        <p class="text-xs uppercase tracking-[0.2em] text-cyan-200/90">Transactions Today</p>
        <p class="mt-2 font-display text-3xl font-semibold text-white"><?= $todayTransactions ?></p>
      </article>
      <article class="glass rounded-2xl p-4">
        <p class="text-xs uppercase tracking-[0.2em] text-cyan-200/90">Top Product Count</p>
        <p class="mt-2 font-display text-3xl font-semibold text-white"><?= count($topProducts) ?></p>
      </article>
      <article class="glass rounded-2xl p-4">
        <p class="text-xs uppercase tracking-[0.2em] text-cyan-200/90">Low Stock Alerts</p>
        <p class="mt-2 font-display text-3xl font-semibold text-coral"><?= count($lowStockProducts) ?></p>
      </article>
    </section>

    <section class="mt-5 grid gap-5 xl:grid-cols-[1.8fr_1fr]">
      <article class="glass rounded-3xl p-4 sm:p-6">
        <div class="mb-4 flex items-center justify-between">
          <h2 class="font-display text-xl font-semibold text-white">Sales Trend (Last 7 Days)</h2>
          <span class="rounded-full bg-cyan-500/15 px-3 py-1 text-xs text-cyan-100">Revenue</span>
        </div>
        <div class="h-80">
          <canvas id="salesTrendChart"></canvas>
        </div>
      </article>

      <div class="space-y-5">
        <article class="glass rounded-3xl p-4 sm:p-5">
          <h3 class="mb-3 font-display text-lg font-semibold text-white">Top-Selling Products (Today)</h3>
          <?php if (empty($topProducts)): ?>
            <p class="rounded-xl border border-dashed border-white/20 bg-slate-900/35 p-4 text-sm text-slate-300">No sales yet today.</p>
          <?php else: ?>
            <ul class="space-y-2">
              <?php foreach ($topProducts as $product): ?>
                <li class="flex items-center justify-between rounded-xl border border-white/10 bg-slate-900/35 px-3 py-2 text-sm">
                  <div>
                    <p class="font-medium text-white"><?= e((string) $product['name']) ?></p>
                    <p class="text-xs text-slate-400"><?= (int) $product['units_sold'] ?> units</p>
                  </div>
                  <p class="font-semibold text-mint"><?= e($currencySymbol) ?><?= number_format((float) $product['revenue'], 2) ?></p>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </article>

        <article class="glass rounded-3xl p-4 sm:p-5">
          <h3 class="mb-3 font-display text-lg font-semibold text-white">Low-Stock Alerts</h3>
          <?php if (empty($lowStockProducts)): ?>
            <p class="rounded-xl border border-dashed border-white/20 bg-slate-900/35 p-4 text-sm text-slate-300">All products are above reorder thresholds.</p>
          <?php else: ?>
            <ul class="space-y-2">
              <?php foreach ($lowStockProducts as $product): ?>
                <li class="flex items-center justify-between rounded-xl border border-rose-300/20 bg-rose-500/10 px-3 py-2 text-sm">
                  <div>
                    <p class="font-medium text-white"><?= e((string) $product['name']) ?></p>
                    <p class="text-xs text-rose-200/80">Reorder level: <?= (int) $product['reorder_level'] ?></p>
                  </div>
                  <span class="rounded-full bg-rose-500/20 px-2 py-1 text-xs font-semibold text-rose-100">Stock <?= (int) $product['stock_qty'] ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </article>
      </div>
    </section>
  </main>

  <script>
    const labels = <?= $trendLabelsJson ?: '[]' ?>;
    const values = <?= $trendSalesJson ?: '[]' ?>;

    const canvas = document.getElementById('salesTrendChart');
    const ctx = canvas.getContext('2d');

    const gradientFill = ctx.createLinearGradient(0, 0, 0, canvas.height || 320);
    gradientFill.addColorStop(0, 'rgba(34, 211, 238, 0.4)');
    gradientFill.addColorStop(0.6, 'rgba(52, 211, 153, 0.16)');
    gradientFill.addColorStop(1, 'rgba(52, 211, 153, 0)');

    const gradientStroke = ctx.createLinearGradient(0, 0, canvas.width || 720, 0);
    gradientStroke.addColorStop(0, '#22D3EE');
    gradientStroke.addColorStop(1, '#34D399');

    new Chart(ctx, {
      type: 'line',
      data: {
        labels,
        datasets: [
          {
            label: 'Sales',
            data: values,
            fill: true,
            borderWidth: 3,
            borderColor: gradientStroke,
            backgroundColor: gradientFill,
            pointRadius: 4,
            pointHoverRadius: 6,
            pointBorderWidth: 0,
            pointBackgroundColor: '#22D3EE',
            tension: 0.38,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            display: false,
          },
          tooltip: {
            backgroundColor: 'rgba(8, 13, 23, 0.95)',
            borderColor: 'rgba(34, 211, 238, 0.25)',
            borderWidth: 1,
            titleColor: '#E2E8F0',
            bodyColor: '#E2E8F0',
            callbacks: {
              label: function (context) {
                return `Sales: <?= e($currencySymbol) ?>${Number(context.parsed.y || 0).toFixed(2)}`;
              },
            },
          },
        },
        scales: {
          x: {
            grid: {
              display: false,
              drawBorder: false,
            },
            ticks: {
              color: '#94A3B8',
            },
            border: {
              display: false,
            },
          },
          y: {
            beginAtZero: true,
            grid: {
              display: false,
              drawBorder: false,
            },
            ticks: {
              color: '#94A3B8',
              callback: function (value) {
                return `<?= e($currencySymbol) ?>${Number(value).toFixed(0)}`;
              },
            },
            border: {
              display: false,
            },
          },
        },
      },
    });
  </script>
</body>
</html>
