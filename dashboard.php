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
$enableMultiStore = (bool) ($shopSettings['enable_multi_store'] ?? false);

$todaySales = 0.0;
$todayTransactions = 0;
$yesterdaySales = 0.0;
$averageTicket = 0.0;
$projectedSalesToday = 0.0;
$momentumPercent = 0.0;
$topProducts = [];
$lowStockProducts = [];
$trendLabels = [];
$trendSales = [];
$insights = [];
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
    $averageTicket = $todayTransactions > 0 ? $todaySales / $todayTransactions : 0.0;

    $yesterdayStmt = $pdo->prepare(
      'SELECT COALESCE(SUM(total_amount), 0) AS total_sales
       FROM sales
       WHERE DATE(sold_at) = (CURDATE() - INTERVAL 1 DAY)'
    );
    $yesterdayStmt->execute();
    $yesterdaySales = (float) ($yesterdayStmt->fetch(PDO::FETCH_ASSOC)['total_sales'] ?? 0);

    if ($yesterdaySales > 0) {
      $momentumPercent = (($todaySales - $yesterdaySales) / $yesterdaySales) * 100;
    }

    $hoursElapsed = max(1.0, (time() - strtotime('today')) / 3600);
    $projectedSalesToday = $todaySales > 0 ? ($todaySales / $hoursElapsed) * 24 : 0.0;

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

    if ($momentumPercent >= 10) {
      $insights[] = 'Sales momentum is strong at +' . number_format($momentumPercent, 1) . '% versus yesterday.';
    } elseif ($momentumPercent <= -10) {
      $insights[] = 'Sales are trending down ' . number_format(abs($momentumPercent), 1) . '% versus yesterday.';
    } else {
      $insights[] = 'Sales momentum is stable compared to yesterday.';
    }

    if (count($lowStockProducts) >= 5) {
      $insights[] = 'Urgent stock risk detected: ' . count($lowStockProducts) . ' products are at or below reorder level.';
    } else {
      $insights[] = 'Inventory health is manageable with only ' . count($lowStockProducts) . ' low-stock alerts.';
    }

    if ($averageTicket > 0) {
      $insights[] = 'Average basket value today is ' . $currencySymbol . number_format($averageTicket, 2) . '.';
    }

    if ($projectedSalesToday > 0) {
      $insights[] = 'Current pace projects around ' . $currencySymbol . number_format($projectedSalesToday, 2) . ' by end of day.';
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
  <title>Dashboard</title>
  <script src="assets/vendor/tailwindcss/tailwindcss.js"></script>
  <script src="assets/vendor/chartjs/chart.umd.min.js"></script>

  <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: {
            sans: ['Montserrat', 'Lato', 'Segoe UI', 'Tahoma', 'Arial', 'sans-serif'],
            display: ['Merriweather', 'Georgia', 'Times New Roman', 'serif']
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
      --bg-base: #050912;
      --bg-glow-1: rgba(34, 211, 238, 0.2);
      --bg-glow-2: rgba(251, 113, 133, 0.16);
      --glass-top: rgba(255, 255, 255, 0.11);
      --glass-bottom: rgba(255, 255, 255, 0.03);
      --glass-border: rgba(255, 255, 255, 0.14);
      background:
        radial-gradient(circle at 8% 10%, var(--bg-glow-1), transparent 32%),
        radial-gradient(circle at 85% 90%, var(--bg-glow-2), transparent 30%),
        var(--bg-base);
      min-height: 100vh;
    }

    .matrix-grid {
      position: fixed;
      inset: 0;
      pointer-events: none;
      z-index: 0;
      background-image: radial-gradient(circle, rgba(125, 211, 252, 0.24) 1px, transparent 1.2px);
      background-size: 24px 24px;
      opacity: 0.24;
      animation: matrixDrift 18s linear infinite;
    }

    .scanner-line {
      position: fixed;
      left: -20%;
      width: 140%;
      height: 1px;
      pointer-events: none;
      z-index: 0;
      opacity: 0.3;
      background: linear-gradient(90deg, transparent, rgba(34, 211, 238, 0.9), transparent);
      box-shadow: 0 0 12px rgba(34, 211, 238, 0.4);
      animation: scannerSweep 12s linear infinite;
    }

    .retro-orbs {
      position: fixed;
      inset: 0;
      pointer-events: none;
      z-index: 0;
      overflow: hidden;
    }

    .orb {
      position: absolute;
      border-radius: 999px;
      filter: blur(1px);
      opacity: 0.2;
      background: radial-gradient(circle at 30% 30%, rgba(255, 255, 255, 0.75), rgba(56, 189, 248, 0.2) 45%, transparent 72%);
      animation: orbFloat 20s ease-in-out infinite;
    }

    .orb.orb-a {
      width: 180px;
      height: 180px;
      left: -60px;
      top: 18%;
    }

    .orb.orb-b {
      width: 150px;
      height: 150px;
      right: -35px;
      top: 26%;
      animation-duration: 24s;
      animation-delay: -6s;
    }

    body[data-theme='light'] {
      --bg-base: #dbeafe;
      --bg-glow-1: rgba(59, 130, 246, 0.2);
      --bg-glow-2: rgba(255, 107, 53, 0.18);
      --glass-top: rgba(255, 255, 255, 0.95);
      --glass-bottom: rgba(255, 255, 255, 0.82);
      --glass-border: rgba(15, 23, 42, 0.14);
      color: #1e40af;
    }

    body[data-theme='light'] .text-white,
    body[data-theme='light'] .text-slate-100,
    body[data-theme='light'] .text-slate-200 {
      color: #0f172a !important;
    }

    body[data-theme='light'] .text-slate-300,
    body[data-theme='light'] .text-slate-400,
    body[data-theme='light'] .text-cyan-100,
    body[data-theme='light'] .text-cyan-200,
    body[data-theme='light'] .text-cyan-300 {
      color: #334155 !important;
    }

    body[data-theme='light'] .bg-panel\/70,
    body[data-theme='light'] .bg-slate-900\/35,
    body[data-theme='light'] .bg-white\/10,
    body[data-theme='light'] .bg-cyan-500\/15 {
      background-color: rgba(255, 255, 255, 0.82) !important;
    }

    body[data-theme='light'] .border-white\/10,
    body[data-theme='light'] .border-white\/20,
    body[data-theme='light'] .border-dashed {
      border-color: rgba(15, 23, 42, 0.24) !important;
    }

    body[data-theme='light'] .utility-link {
      border-color: rgba(15, 23, 42, 0.22);
      background: rgba(255, 255, 255, 0.96);
      color: #0f172a;
    }

    body[data-theme='light'] .utility-link:hover {
      background: rgba(241, 245, 249, 0.98);
      border-color: rgba(14, 116, 144, 0.35);
    }

    body[data-theme='light'] .switcher-chip {
      border-color: rgba(15, 23, 42, 0.22);
      background: rgba(255, 255, 255, 0.96);
      color: #0f172a;
    }

    body[data-theme='light'] .switcher-select {
      color: #0f172a;
    }

    body[data-theme='light'] .theme-toggle {
      color: #0f172a;
      border-color: rgba(15, 23, 42, 0.22);
      background: rgba(255, 255, 255, 0.96);
    }

    body[data-theme='light'] .text-mint {
      color: #047857 !important;
    }

    body[data-theme='light'] .text-coral,
    body[data-theme='light'] .text-rose-100,
    body[data-theme='light'] .text-rose-200 {
      color: #b91c1c !important;
    }

    body[data-theme='light'] .bg-rose-500\/10,
    body[data-theme='light'] .bg-rose-500\/20 {
      background-color: rgba(254, 226, 226, 0.68) !important;
    }

    body[data-theme='light'] .switcher-select,
    body[data-theme='light'] .utility-link,
    body[data-theme='light'] .skip-link {
      color: #0f172a !important;
    }

    .glass {
      background: linear-gradient(140deg, var(--glass-top), var(--glass-bottom));
      border: 1px solid var(--glass-border);
      backdrop-filter: blur(14px);
      -webkit-backdrop-filter: blur(14px);
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
      outline: 2px solid rgba(125, 211, 252, 0.7);
      outline-offset: 1px;
    }

    body[data-theme='light'] .skip-link {
      border-color: rgba(15, 23, 42, 0.25);
      background: rgba(255, 255, 255, 0.95);
      color: #0f172a;
    }

    .utility-link {
      border-radius: 0.7rem;
      border: 1px solid rgba(148, 163, 184, 0.35);
      background: rgba(15, 23, 42, 0.45);
      color: #dbeafe;
      padding: 0.5rem 0.8rem;
      font-size: 0.84rem;
      font-weight: 600;
      transition: background-color 170ms ease, border-color 170ms ease;
    }

    .utility-link:hover {
      border-color: rgba(125, 211, 252, 0.45);
      background: rgba(15, 23, 42, 0.72);
    }

    .utility-link:focus-visible {
      outline: 2px solid rgba(125, 211, 252, 0.8);
      outline-offset: 2px;
    }

    .icon-link {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
    }

    .nav-icon {
      width: 0.9rem;
      height: 0.9rem;
      opacity: 0.92;
      flex-shrink: 0;
    }

    .switcher-chip {
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
      border-radius: 0.7rem;
      border: 1px solid rgba(148, 163, 184, 0.35);
      background: rgba(15, 23, 42, 0.62);
      padding: 0.35rem 0.55rem;
      color: #f8fafc;
    }

    .switcher-icon {
      width: 0.95rem;
      height: 0.95rem;
      opacity: 0.9;
      flex-shrink: 0;
    }

    .switcher-select {
      min-width: 3.8rem;
      border-radius: 0.45rem;
      background: transparent;
      font-size: 0.75rem;
      font-weight: 700;
      text-transform: uppercase;
      outline: none;
      color: #f8fafc;
    }

    .theme-toggle {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 2rem;
      height: 2rem;
      border-radius: 0.6rem;
      border: 1px solid rgba(148, 163, 184, 0.35);
      background: rgba(15, 23, 42, 0.45);
      color: #f8fafc;
      transition: transform 150ms ease, border-color 150ms ease, background-color 150ms ease;
    }

    .theme-toggle:hover {
      transform: translateY(-1px);
      border-color: rgba(125, 211, 252, 0.45);
      background: rgba(15, 23, 42, 0.7);
    }

    .theme-toggle:focus-visible {
      outline: 2px solid rgba(125, 211, 252, 0.8);
      outline-offset: 2px;
    }

    .theme-toggle svg {
      width: 1rem;
      height: 1rem;
    }

    .switcher-label {
      font-size: 0.68rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      opacity: 0.88;
    }

    .sr-only {
      position: absolute;
      width: 1px;
      height: 1px;
      padding: 0;
      margin: -1px;
      overflow: hidden;
      clip: rect(0, 0, 0, 0);
      white-space: nowrap;
      border: 0;
    }

    @media (max-width: 768px) {
      .switcher-label {
        display: none;
      }

      .switcher-chip {
        gap: 0.25rem;
        padding: 0.3rem 0.42rem;
      }

      .switcher-select {
        min-width: 3.2rem;
        font-size: 0.68rem;
      }
    }

    .metric-card {
      position: relative;
      overflow: hidden;
      transition: transform 220ms ease, border-color 220ms ease;
    }

    .metric-card::after {
      content: '';
      position: absolute;
      inset: -35% -120%;
      background: linear-gradient(120deg, rgba(255, 255, 255, 0) 38%, rgba(255, 255, 255, 0.2) 50%, rgba(255, 255, 255, 0) 62%);
      transform: translateX(-55%) rotate(6deg);
      opacity: 0;
      pointer-events: none;
    }

    .metric-card:hover {
      transform: translateY(-2px);
      border-color: rgba(125, 211, 252, 0.34);
    }

    .metric-card:hover::after {
      opacity: 1;
      animation: metric-card-shimmer 1.8s ease-in-out;
    }

    .ambient-paused .matrix-grid,
    .ambient-paused .scanner-line,
    .ambient-paused .orb {
      animation: none !important;
    }

    @keyframes metric-card-shimmer {
      0% {
        transform: translateX(-65%) rotate(6deg);
      }

      100% {
        transform: translateX(65%) rotate(6deg);
      }
    }

    @keyframes matrixDrift {
      from { transform: translate3d(0, 0, 0); }
      to { transform: translate3d(-24px, -24px, 0); }
    }

    @keyframes scannerSweep {
      0% { top: -8%; }
      100% { top: 108%; }
    }

    @keyframes orbFloat {
      0%, 100% { transform: translate3d(0, 0, 0) scale(1); }
      50% { transform: translate3d(0, -18px, 0) scale(1.04); }
    }

    body[data-theme='light'] .metric-card:hover {
      border-color: rgba(14, 116, 144, 0.32);
    }
  </style>
</head>
<body class="text-slate-100 antialiased">
  <div class="matrix-grid" aria-hidden="true"></div>
  <div class="scanner-line" aria-hidden="true"></div>
  <div class="retro-orbs" aria-hidden="true">
    <span class="orb orb-a"></span>
    <span class="orb orb-b"></span>
  </div>
  <a href="#mainContent" class="skip-link">Skip to dashboard content</a>
  <main id="mainContent" class="relative z-10 mx-auto max-w-[1500px] px-4 py-6 sm:px-6 lg:px-8">
    <header class="mb-6 rounded-2xl border border-white/10 bg-panel/70 p-5 shadow-soft">
      <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
          <p class="font-display text-xs uppercase tracking-[0.3em] text-cyan-300"><?= e(strtoupper($shopName) . ' POS INTELLIGENCE') ?></p>
          <h1 id="dashboardTitle" class="mt-1 font-display text-2xl font-semibold text-white sm:text-3xl">Dashboard</h1>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <label class="switcher-chip focus-within:ring-2 focus-within:ring-cyan-300/45" title="Toggle theme">
            <span data-i18n="theme" class="sr-only">Theme</span>
            <button id="themeSwitch" type="button" class="theme-toggle" aria-label="Switch to light theme" aria-pressed="true">
              <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 2a8 8 0 1 0 8 8 7 7 0 0 1-8-8z"/></svg>
            </button>
          </label>
          <label class="switcher-chip focus-within:ring-2 focus-within:ring-cyan-300/45" title="Language">
            <svg class="switcher-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 2a8 8 0 1 0 8 8 8 8 0 0 0-8-8zm4.9 7h-2.1a12.5 12.5 0 0 0-.6-3 6 6 0 0 1 2.7 3zM10 4.2c.6.9 1.1 2.8 1.3 4.8H8.7c.2-2 .7-3.9 1.3-4.8zM6.8 6a12.5 12.5 0 0 0-.6 3H4.1a6 6 0 0 1 2.7-3zM4.1 11h2.1c.1 1.1.3 2.1.6 3a6 6 0 0 1-2.7-3zm3.6 0h2.6c-.2 2-.7 3.9-1.3 4.8-.6-.9-1.1-2.8-1.3-4.8zm4.5 3c.3-.9.5-1.9.6-3h2.1a6 6 0 0 1-2.7 3z"/></svg>
            <span data-i18n="language" class="switcher-label">Language</span>
            <select id="languageSwitch" aria-label="Language" class="switcher-select">
              <option value="en">EN</option>
              <option value="fr">FR</option>
              <option value="tw">TWI</option>
              <option value="ee">EWE</option>
              <option value="gaa">GA</option>
              <option value="fat">FANTE</option>
              <option value="dag">DAGBANI</option>
              <option value="gur">GURUNE</option>
              <option value="kus">KUSAAL</option>
            </select>
          </label>
          <?php if ((string) $currentUser['role'] === 'admin'): ?>
            <a href="add_product.php" class="utility-link icon-link"><svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 3a1 1 0 0 1 1 1v5h5a1 1 0 1 1 0 2h-5v5a1 1 0 1 1-2 0v-5H4a1 1 0 1 1 0-2h5V4a1 1 0 0 1 1-1z"/></svg><span data-i18n="addProduct">Add Product</span></a>
            <a href="manage_products.php" class="utility-link icon-link"><svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M3 4h14v3H3zm0 5h14v3H3zm0 5h14v2H3z"/></svg><span data-i18n="manageProducts">Manage Products</span></a>
            <a href="manage_users.php" class="utility-link icon-link"><svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 10a4 4 0 1 0-4-4 4 4 0 0 0 4 4zm0 2c-3.31 0-6 1.79-6 4v1h12v-1c0-2.21-2.69-4-6-4z"/></svg><span data-i18n="users">Users</span></a>
            <a href="audit_logs.php" class="utility-link icon-link"><svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M5 3h10a1 1 0 0 1 1 1v12l-3-2-3 2-3-2-3 2V4a1 1 0 0 1 1-1z"/></svg><span data-i18n="audit">Audit</span></a>
            <a href="settings.php" class="utility-link icon-link"><svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M11.98 2.5a1 1 0 0 0-1.96 0l-.2 1.2a6.9 6.9 0 0 0-1.53.63l-1.03-.65a1 1 0 0 0-1.34.29l-.98 1.7a1 1 0 0 0 .23 1.29l.95.76a6.8 6.8 0 0 0 0 1.76l-.95.76a1 1 0 0 0-.23 1.29l.98 1.7a1 1 0 0 0 1.34.29l1.03-.65c.48.27.99.48 1.53.63l.2 1.2a1 1 0 0 0 1.96 0l.2-1.2c.54-.15 1.05-.36 1.53-.63l1.03.65a1 1 0 0 0 1.34-.29l.98-1.7a1 1 0 0 0-.23-1.29l-.95-.76a6.8 6.8 0 0 0 0-1.76l.95-.76a1 1 0 0 0 .23-1.29l-.98-1.7a1 1 0 0 0-1.34-.29l-1.03.65a6.9 6.9 0 0 0-1.53-.63zM10 7a3 3 0 1 1 0 6 3 3 0 0 1 0-6z"/></svg><span data-i18n="settings">Settings</span></a>
          <?php endif; ?>
          <a href="inventory_adjustments.php" class="utility-link icon-link"><svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M3 4h14v3H3zm0 5h14v3H3zm0 5h14v2H3z"/></svg><span data-i18n="inventory">Inventory</span></a>
          <?php if ($enableMultiStore): ?>
            <a href="multi_store.php" class="utility-link icon-link"><svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 2 2 6v2h16V6l-8-4zm-7 8h2v6H3v-6zm4 0h2v6H7v-6zm4 0h2v6h-2v-6zm4 0h2v6h-2v-6z"/></svg><span>Stores</span></a>
          <?php endif; ?>
          <a href="receipt_history.php" class="utility-link icon-link"><svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M5 2h10a1 1 0 0 1 1 1v14l-2-1-2 1-2-1-2 1-2-1-2 1V3a1 1 0 0 1 1-1zm2 4v2h6V6zm0 4v2h6v-2z"/></svg><span data-i18n="receipts">Receipts</span></a>
          <a href="index.php" class="utility-link icon-link"><svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M9 4 3 10l6 6 1.4-1.4L7.8 12H17v-2H7.8l2.6-2.6z"/></svg><span data-i18n="backToCheckout">Back to Checkout</span></a>
        </div>
      </div>
    </header>

    <?php if ($databaseError !== null): ?>
      <div class="mb-6 rounded-2xl border border-rose-400/30 bg-rose-500/10 p-4 text-sm text-rose-100">
        <span data-i18n="dashboardUnavailable">Dashboard is temporarily unavailable.</span>
      </div>
    <?php endif; ?>

    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="KPI cards">
      <article class="glass metric-card rounded-2xl p-4">
        <p data-i18n="todaysSales" class="text-xs uppercase tracking-[0.2em] text-cyan-200/90">Today's Sales</p>
        <p class="mt-2 font-display text-3xl font-semibold text-white"><?= e($currencySymbol) ?><?= number_format($todaySales, 2) ?></p>
      </article>
      <article class="glass metric-card rounded-2xl p-4">
        <p data-i18n="transactionsToday" class="text-xs uppercase tracking-[0.2em] text-cyan-200/90">Transactions Today</p>
        <p class="mt-2 font-display text-3xl font-semibold text-white"><?= $todayTransactions ?></p>
      </article>
      <article class="glass metric-card rounded-2xl p-4">
        <p data-i18n="topProductCount" class="text-xs uppercase tracking-[0.2em] text-cyan-200/90">Top Product Count</p>
        <p class="mt-2 font-display text-3xl font-semibold text-white"><?= count($topProducts) ?></p>
      </article>
      <article class="glass metric-card rounded-2xl p-4">
        <p data-i18n="lowStockAlerts" class="text-xs uppercase tracking-[0.2em] text-cyan-200/90">Low Stock Alerts</p>
        <p class="mt-2 font-display text-3xl font-semibold text-coral"><?= count($lowStockProducts) ?></p>
      </article>
      <article class="glass metric-card rounded-2xl p-4">
        <p data-i18n="averageBasket" class="text-xs uppercase tracking-[0.2em] text-cyan-200/90">Average Basket</p>
        <p class="mt-2 font-display text-3xl font-semibold text-white"><?= e($currencySymbol) ?><?= number_format($averageTicket, 2) ?></p>
      </article>
      <article class="glass metric-card rounded-2xl p-4">
        <p data-i18n="projectedEodSales" class="text-xs uppercase tracking-[0.2em] text-cyan-200/90">Projected EOD Sales</p>
        <p class="mt-2 font-display text-3xl font-semibold text-white"><?= e($currencySymbol) ?><?= number_format($projectedSalesToday, 2) ?></p>
      </article>
      <article class="glass metric-card rounded-2xl p-4">
        <p data-i18n="momentumVsYesterday" class="text-xs uppercase tracking-[0.2em] text-cyan-200/90">Momentum vs Yesterday</p>
        <p class="mt-2 font-display text-3xl font-semibold <?= $momentumPercent >= 0 ? 'text-mint' : 'text-coral' ?>">
          <?= ($momentumPercent >= 0 ? '+' : '-') . number_format(abs($momentumPercent), 1) ?>%
        </p>
      </article>
    </section>

    <section class="mt-5">
      <article class="glass rounded-3xl p-4 sm:p-5">
        <h2 data-i18n="smartOperationalInsights" class="font-display text-xl font-semibold text-white">Smart Operational Insights</h2>
        <ul class="mt-3 grid gap-3 lg:grid-cols-2">
          <?php foreach ($insights as $insight): ?>
            <li class="rounded-xl border border-white/10 bg-slate-900/35 px-3 py-3 text-sm text-slate-200"><?= e($insight) ?></li>
          <?php endforeach; ?>
        </ul>
      </article>
    </section>

    <section class="mt-5 grid gap-5 xl:grid-cols-[1.8fr_1fr]">
      <article class="glass rounded-3xl p-4 sm:p-6">
        <div class="mb-4 flex items-center justify-between">
          <h2 data-i18n="salesTrend7Days" class="font-display text-xl font-semibold text-white">Sales Trend (Last 7 Days)</h2>
          <span data-i18n="revenue" class="rounded-full bg-cyan-500/15 px-3 py-1 text-xs text-cyan-100">Revenue</span>
        </div>
        <div class="h-80">
          <canvas id="salesTrendChart" role="img" aria-label="Sales trend chart for the last 7 days"></canvas>
        </div>
      </article>

      <div class="space-y-5">
        <article class="glass rounded-3xl p-4 sm:p-5">
          <h3 data-i18n="topSellingProductsToday" class="mb-3 font-display text-lg font-semibold text-white">Top-Selling Products (Today)</h3>
          <?php if (empty($topProducts)): ?>
            <p data-i18n="noSalesToday" class="rounded-xl border border-dashed border-white/20 bg-slate-900/35 p-4 text-sm text-slate-300">No sales yet today.</p>
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
          <h3 data-i18n="lowStockAlerts" class="mb-3 font-display text-lg font-semibold text-white">Low-Stock Alerts</h3>
          <?php if (empty($lowStockProducts)): ?>
            <p data-i18n="allProductsAboveReorder" class="rounded-xl border border-dashed border-white/20 bg-slate-900/35 p-4 text-sm text-slate-300">All products are above reorder thresholds.</p>
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
    const themeSwitch = document.getElementById('themeSwitch');
    const languageSwitch = document.getElementById('languageSwitch');
    const dashboardTitle = document.getElementById('dashboardTitle');
    const THEME_PREF_KEY = 'novapos_theme';
    const LANG_PREF_KEY = 'novapos_lang';
    const GHANA_TRANSLATE_API = 'api/translate_text.php';
    const GHANA_SUPPORTED_LANGS = new Set(['tw', 'ee', 'gaa', 'fat', 'dag', 'gur', 'kus']);
    const hydratedRemoteLanguages = new Set();

    const translations = {
      en: {
        theme: 'Theme',
        themeDark: 'Dark',
        themeLight: 'Light',
        language: 'Language',
        dashboard: 'Dashboard',
        addProduct: 'Add Product',
        manageProducts: 'Manage Products',
        users: 'Users',
        audit: 'Audit',
        settings: 'Settings',
        inventory: 'Inventory',
        receipts: 'Receipts',
        backToCheckout: 'Back to Checkout',
        dashboardUnavailable: 'Dashboard is temporarily unavailable.',
        todaysSales: "Today's Sales",
        transactionsToday: 'Transactions Today',
        topProductCount: 'Top Product Count',
        lowStockAlerts: 'Low Stock Alerts',
        averageBasket: 'Average Basket',
        projectedEodSales: 'Projected EOD Sales',
        momentumVsYesterday: 'Momentum vs Yesterday',
        smartOperationalInsights: 'Smart Operational Insights',
        salesTrend7Days: 'Sales Trend (Last 7 Days)',
        revenue: 'Revenue',
        topSellingProductsToday: 'Top-Selling Products (Today)',
        noSalesToday: 'No sales yet today.',
        allProductsAboveReorder: 'All products are above reorder thresholds.',
        sales: 'Sales',
      },
      fr: {
        theme: 'Theme',
        themeDark: 'Sombre',
        themeLight: 'Clair',
        language: 'Langue',
        dashboard: 'Tableau de bord',
        addProduct: 'Ajouter produit',
        manageProducts: 'Gestion produits',
        users: 'Utilisateurs',
        audit: 'Audit',
        settings: 'Parametres',
        inventory: 'Stock',
        receipts: 'Recus',
        backToCheckout: 'Retour a la caisse',
        dashboardUnavailable: 'Le tableau de bord est temporairement indisponible.',
        todaysSales: 'Ventes du jour',
        transactionsToday: 'Transactions du jour',
        topProductCount: 'Nombre de meilleurs produits',
        lowStockAlerts: 'Alertes stock faible',
        averageBasket: 'Panier moyen',
        projectedEodSales: 'Projection fin de journee',
        momentumVsYesterday: 'Tendance vs hier',
        smartOperationalInsights: 'Insights operationnels intelligents',
        salesTrend7Days: 'Tendance des ventes (7 derniers jours)',
        revenue: 'Revenu',
        topSellingProductsToday: 'Produits les plus vendus (aujourd\'hui)',
        noSalesToday: 'Aucune vente pour le moment aujourd\'hui.',
        allProductsAboveReorder: 'Tous les produits sont au-dessus du seuil de reapprovisionnement.',
        sales: 'Ventes',
      },
      tw: {
        language: 'Kasa',
        dashboard: 'Dashboard',
        backToCheckout: 'San kɔ Checkout',
        sales: 'Ntotɔn',
      },
      gaa: {
        language: 'Mli',
        dashboard: 'Dashboard',
        backToCheckout: 'Yaa Checkout',
        sales: 'Sales',
      },
      ee: {
        language: 'Gbe',
        dashboard: 'Dashboard',
        backToCheckout: 'Trɔ yi Checkout',
        sales: 'Nudada',
      },
      fat: {
        language: 'Mfantse',
      },
      dag: {
        language: 'Dagbanli',
      },
      gur: {
        language: 'Gurune',
      },
      kus: {
        language: 'Kusaal',
      }
    };

    let currentLanguage = 'en';

    function normalizeLanguageCode(languageCode) {
      const map = {
        twi: 'tw',
        ewe: 'ee',
        ga: 'gaa',
        sehwi: 'tw'
      };

      return map[languageCode] || languageCode;
    }

    function getRemoteLanguageCacheKey(langCode) {
      return `novapos_remote_i18n_dashboard_${langCode}`;
    }

    function loadRemoteLanguageCache(langCode) {
      try {
        const raw = localStorage.getItem(getRemoteLanguageCacheKey(langCode));
        if (!raw) {
          return;
        }

        const decoded = JSON.parse(raw);
        if (!decoded || typeof decoded !== 'object') {
          return;
        }

        translations[langCode] = {
          ...(translations[langCode] || {}),
          ...decoded
        };
      } catch (error) {
      }
    }

    function saveRemoteLanguageCache(langCode, values) {
      try {
        localStorage.setItem(getRemoteLanguageCacheKey(langCode), JSON.stringify(values));
      } catch (error) {
      }
    }

    function renderLanguageUI() {
      document.documentElement.lang = currentLanguage;

      document.querySelectorAll('[data-i18n]').forEach((element) => {
        const key = element.getAttribute('data-i18n');
        if (key) {
          element.textContent = t(key);
        }
      });

      if (dashboardTitle) {
        dashboardTitle.textContent = t('dashboard');
      }
    }

    async function hydrateRemoteTranslations(langCode) {
      if (!GHANA_SUPPORTED_LANGS.has(langCode) || hydratedRemoteLanguages.has(langCode)) {
        return;
      }

      loadRemoteLanguageCache(langCode);

      const sourcePack = translations.en || {};
      const targetPack = translations[langCode] || {};
      const payload = {};

      Object.keys(sourcePack).forEach((key) => {
        const targetValue = String(targetPack[key] || '').trim();
        const sourceValue = String(sourcePack[key] || '').trim();

        if (sourceValue !== '' && (targetValue === '' || targetValue === sourceValue)) {
          payload[key] = sourceValue;
        }
      });

      if (Object.keys(payload).length === 0) {
        hydratedRemoteLanguages.add(langCode);
        return;
      }

      try {
        const response = await fetch(GHANA_TRANSLATE_API, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({
            source: 'en',
            target: langCode,
            texts: payload
          })
        });

        const result = await response.json();
        if (!response.ok || !result || result.success !== true || typeof result.translations !== 'object') {
          return;
        }

        translations[langCode] = {
          ...(translations[langCode] || {}),
          ...result.translations
        };

        saveRemoteLanguageCache(langCode, translations[langCode]);
        hydratedRemoteLanguages.add(langCode);
      } catch (error) {
      }
    }

    function t(key) {
      const languagePack = translations[currentLanguage] || translations.en;
      return languagePack[key] || translations.en[key] || key;
    }

    function themeIconMarkup(theme) {
      if (theme === 'light') {
        return '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 4a1 1 0 0 1 1 1v1a1 1 0 1 1-2 0V5a1 1 0 0 1 1-1zm0 8a2 2 0 1 0 0-4 2 2 0 0 0 0 4zm5-2a1 1 0 0 1 1-1h1a1 1 0 1 1 0 2h-1a1 1 0 0 1-1-1zM3 10a1 1 0 0 1 1-1h1a1 1 0 1 1 0 2H4a1 1 0 0 1-1-1zm9.66-3.66a1 1 0 0 1 0-1.41l.71-.71a1 1 0 1 1 1.41 1.41l-.7.71a1 1 0 0 1-1.42 0zm-6.32 6.32a1 1 0 0 1 0-1.41l.71-.71a1 1 0 0 1 1.41 1.41l-.7.71a1 1 0 0 1-1.42 0zm7.03 0-.71-.71a1 1 0 1 1 1.41-1.41l.71.7a1 1 0 1 1-1.41 1.42zm-6.32-6.32-.71-.71A1 1 0 1 0 4.93 4.93l.7.71a1 1 0 1 0 1.42-1.41z"/></svg>';
      }

      return '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 2a8 8 0 1 0 8 8 7 7 0 0 1-8-8z"/></svg>';
    }

    function syncThemeToggle(theme) {
      if (!(themeSwitch instanceof HTMLElement)) {
        return;
      }

      const nextTheme = theme === 'light' ? 'dark' : 'light';
      themeSwitch.setAttribute('aria-label', `Switch to ${nextTheme} theme`);
      themeSwitch.setAttribute('title', `Switch to ${nextTheme} theme`);
      themeSwitch.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
      themeSwitch.innerHTML = themeIconMarkup(theme);
    }

    function applyLanguage(languageCode) {
      const allowedLanguages = ['en', 'fr', 'tw', 'ee', 'gaa', 'fat', 'dag', 'gur', 'kus'];
      const normalizedCode = normalizeLanguageCode(languageCode);
      currentLanguage = allowedLanguages.includes(normalizedCode) ? normalizedCode : 'en';

      if (languageSwitch) {
        languageSwitch.value = currentLanguage;
      }

      try {
        localStorage.setItem(LANG_PREF_KEY, currentLanguage);
      } catch (error) {
      }

      const requestedLanguage = currentLanguage;
      renderLanguageUI();

      hydrateRemoteTranslations(requestedLanguage).then(() => {
        if (currentLanguage === requestedLanguage) {
          renderLanguageUI();
        }
      });
    }

    function applyTheme(themeName) {
      let theme = themeName;
      if (themeName === 'ocean') {
        theme = 'dark';
      } else if (themeName === 'aurora') {
        theme = 'light';
      }

      if (theme !== 'light' && theme !== 'dark') {
        theme = 'dark';
      }

      document.body.setAttribute('data-theme', theme);
      syncThemeToggle(theme);

      try {
        localStorage.setItem(THEME_PREF_KEY, theme);
      } catch (error) {
      }
    }

    function loadThemePreference() {
      let saved = 'dark';
      try {
        saved = localStorage.getItem(THEME_PREF_KEY) || 'dark';
      } catch (error) {
      }
      applyTheme(saved);
    }

    function loadLanguagePreference() {
      let saved = 'en';
      try {
        saved = localStorage.getItem(LANG_PREF_KEY) || 'en';
      } catch (error) {
      }
      applyLanguage(saved);
    }

    if (languageSwitch) {
      languageSwitch.addEventListener('change', function () {
        applyLanguage(languageSwitch.value);
      });
    }

    if (themeSwitch) {
      themeSwitch.addEventListener('click', function () {
        const currentTheme = document.body.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
        applyTheme(currentTheme === 'light' ? 'dark' : 'light');
      });
    }

    window.addEventListener('storage', function (event) {
      if (event.key !== THEME_PREF_KEY || event.newValue === null) {
        return;
      }

      applyTheme(event.newValue);
    });

    loadThemePreference();
    loadLanguagePreference();

    setTimeout(function () {
      document.body.classList.add('ambient-paused');
    }, 16000);

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
            label: t('sales'),
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
                return `${t('sales')}: <?= e($currencySymbol) ?>${Number(context.parsed.y || 0).toFixed(2)}`;
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

