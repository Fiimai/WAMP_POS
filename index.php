<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Controllers\ProductController;
use App\Models\ShopSettings;

$currentUser = Auth::requirePageAuth(['admin', 'manager', 'cashier']);
$shopSettings = ShopSettings::get();
$shopName = (string) ($shopSettings['shop_name'] ?? 'My Shop');
$currencySymbol = (string) ($shopSettings['currency_symbol'] ?? '$');
$taxRatePercent = (float) ($shopSettings['tax_rate_percent'] ?? 8.0);
$themePrimary = (string) ($shopSettings['theme_accent_primary'] ?? '#06B6D4');
$themeSecondary = (string) ($shopSettings['theme_accent_secondary'] ?? '#22D3AA');

$search = trim((string) ($_GET['q'] ?? ''));
$products = [];
$smartPicks = [];
$bestSellers = [];
$recentProducts = [];
$databaseError = null;

try {
  $productController = new ProductController();
  $products = $productController->index($search === '' ? null : $search);

  $pdo = Database::connection();
  $recommendationStmt = $pdo->prepare(
    'SELECT p.id, p.name, p.unit_price, p.stock_qty, p.created_at, c.name AS category_name,
            COALESCE(SUM(si.qty), 0) AS total_units,
            COALESCE(SUM(CASE WHEN s.sold_at >= (NOW() - INTERVAL 30 DAY) THEN si.qty ELSE 0 END), 0) AS units_30,
            COALESCE(SUM(CASE WHEN s.sold_at >= (NOW() - INTERVAL 7 DAY) THEN si.qty ELSE 0 END), 0) AS units_7,
            MAX(s.sold_at) AS last_sold_at
     FROM products p
     INNER JOIN categories c ON c.id = p.category_id
     LEFT JOIN sale_items si ON si.product_id = p.id
     LEFT JOIN sales s ON s.id = si.sale_id
     WHERE p.is_active = 1
       AND p.stock_qty > 0
     GROUP BY p.id, p.name, p.unit_price, p.stock_qty, p.created_at, c.name
     LIMIT 220'
  );
  $recommendationStmt->execute();
  $candidateRows = $recommendationStmt->fetchAll(\PDO::FETCH_ASSOC);

  $scoredRows = [];
  foreach ($candidateRows as $row) {
    $unitsTotal = (float) ($row['total_units'] ?? 0);
    $units30 = (float) ($row['units_30'] ?? 0);
    $units7 = (float) ($row['units_7'] ?? 0);
    $stockQty = (int) ($row['stock_qty'] ?? 0);

    $createdAt = (string) ($row['created_at'] ?? '');
    $createdTs = $createdAt !== '' ? strtotime($createdAt) : false;
    $daysSinceCreated = $createdTs !== false ? (int) floor((time() - $createdTs) / 86400) : 365;

    $recencyBoost = 0.0;
    if ($daysSinceCreated <= 7) {
      $recencyBoost = 9.0;
    } elseif ($daysSinceCreated <= 21) {
      $recencyBoost = 5.0;
    } elseif ($daysSinceCreated <= 45) {
      $recencyBoost = 2.0;
    }

    $stockBoost = $stockQty >= 5 ? 1.3 : 0.7;
    $score = ($units7 * 3.1) + ($units30 * 1.7) + (min($unitsTotal, 180) * 0.2) + $recencyBoost + $stockBoost;

    $row['smart_score'] = $score;
    $scoredRows[] = $row;
  }

  $smartRanked = $scoredRows;
  usort(
    $smartRanked,
    static fn(array $a, array $b): int => (float) ($b['smart_score'] ?? 0) <=> (float) ($a['smart_score'] ?? 0)
  );

  $bestSellerRanked = $scoredRows;
  usort(
    $bestSellerRanked,
    static function (array $a, array $b): int {
      $totalCompare = (float) ($b['total_units'] ?? 0) <=> (float) ($a['total_units'] ?? 0);
      if ($totalCompare !== 0) {
        return $totalCompare;
      }

      return (float) ($b['units_30'] ?? 0) <=> (float) ($a['units_30'] ?? 0);
    }
  );

  $recentRanked = $scoredRows;
  usort(
    $recentRanked,
    static function (array $a, array $b): int {
      $aTs = strtotime((string) ($a['created_at'] ?? '')) ?: 0;
      $bTs = strtotime((string) ($b['created_at'] ?? '')) ?: 0;
      return $bTs <=> $aTs;
    }
  );

  $toCard = static function (array $row): array {
    return [
      'id' => (int) ($row['id'] ?? 0),
      'name' => (string) ($row['name'] ?? ''),
      'category_name' => (string) ($row['category_name'] ?? 'General'),
      'unit_price' => (float) ($row['unit_price'] ?? 0),
      'stock_qty' => (int) ($row['stock_qty'] ?? 0),
      'total_units' => (int) round((float) ($row['total_units'] ?? 0)),
      'units_30' => (int) round((float) ($row['units_30'] ?? 0)),
      'is_new' => ((strtotime((string) ($row['created_at'] ?? '')) ?: 0) >= (time() - 14 * 86400)),
    ];
  };

  $smartPicks = array_map($toCard, array_slice($smartRanked, 0, 10));
  $bestSellers = array_map($toCard, array_slice($bestSellerRanked, 0, 10));
  $recentProducts = array_map($toCard, array_slice($recentRanked, 0, 10));

  if ($smartPicks === [] && $products !== []) {
    $fallback = array_slice($products, 0, 10);
    foreach ($fallback as $product) {
      $smartPicks[] = [
        'id' => (int) ($product['id'] ?? 0),
        'name' => (string) ($product['name'] ?? ''),
        'category_name' => (string) ($product['category_name'] ?? 'General'),
        'unit_price' => (float) ($product['unit_price'] ?? 0),
        'stock_qty' => (int) ($product['stock_qty'] ?? 0),
        'total_units' => 0,
        'units_30' => 0,
        'is_new' => false,
      ];
    }
    $bestSellers = $smartPicks;
    $recentProducts = $smartPicks;
  }
} catch (Throwable $exception) {
  error_log('checkout page DB failure: ' . $exception->getMessage());
  $databaseError = 'Database unavailable';
}

$productCount = count($products);
$userRole = (string) ($currentUser['role'] ?? 'cashier');
$smartPicksJson = json_encode($smartPicks, JSON_UNESCAPED_SLASHES);
$bestSellersJson = json_encode($bestSellers, JSON_UNESCAPED_SLASHES);
$recentProductsJson = json_encode($recentProducts, JSON_UNESCAPED_SLASHES);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="<?= e((string) $_SESSION['csrf_token']) ?>" />
  <meta name="currency-symbol" content="<?= e($currencySymbol) ?>" />
  <meta name="tax-rate-percent" content="<?= e((string) $taxRatePercent) ?>" />
  <title><?= e($shopName) ?> POS Checkout</title>

  <script src="assets/vendor/tailwindcss/tailwindcss.js"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: {
            sans: ['Montserrat', 'Lato', 'Segoe UI', 'Tahoma', 'Arial', 'sans-serif'],
            display: ['Merriweather', 'Georgia', 'Times New Roman', 'serif']
          },
          colors: {
            night: '#070B14',
            panel: '#0E1726',
            electric: '#06B6D4',
            mint: '#22D3AA',
            ember: '#FB7185'
          },
          boxShadow: {
            glow: '0 0 0 1px rgba(34, 211, 170, 0.28), 0 25px 50px -12px rgba(6, 182, 212, 0.32)',
            card: '0 10px 35px rgba(2, 6, 23, 0.45)'
          },
          keyframes: {
            rise: {
              '0%': { opacity: '0', transform: 'translateY(16px)' },
              '100%': { opacity: '1', transform: 'translateY(0)' }
            }
          },
          animation: {
            rise: 'rise 650ms ease-out both'
          }
        }
      }
    };
  </script>

  <style>
    body {
      --shop-accent-primary: <?= e($themePrimary) ?>;
      --shop-accent-secondary: <?= e($themeSecondary) ?>;
      --bg-base: #070b14;
      --bg-glow-1: rgba(6, 182, 212, 0.18);
      --bg-glow-2: rgba(34, 211, 170, 0.14);
      --bg-glow-3: rgba(251, 113, 133, 0.16);
      --glass-top: rgba(255, 255, 255, 0.1);
      --glass-bottom: rgba(255, 255, 255, 0.04);
      --glass-border: rgba(255, 255, 255, 0.14);
      background:
        radial-gradient(circle at 12% 15%, var(--bg-glow-1), transparent 30%),
        radial-gradient(circle at 80% 8%, var(--bg-glow-2), transparent 26%),
        radial-gradient(circle at 84% 88%, var(--bg-glow-3), transparent 26%),
        var(--bg-base);
      min-height: 100vh;
    }

    body[data-theme='light'] {
      --bg-base: #eef3fb;
      --bg-glow-1: rgba(14, 165, 233, 0.18);
      --bg-glow-2: rgba(16, 185, 129, 0.14);
      --bg-glow-3: rgba(244, 114, 182, 0.12);
      --glass-top: rgba(255, 255, 255, 0.88);
      --glass-bottom: rgba(255, 255, 255, 0.72);
      --glass-border: rgba(15, 23, 42, 0.12);
      color: #0f172a;
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

    body[data-theme='light'] .bg-slate-900\/45,
    body[data-theme='light'] .bg-slate-900\/40,
    body[data-theme='light'] .bg-slate-900\/35,
    body[data-theme='light'] .bg-slate-900\/50,
    body[data-theme='light'] .bg-slate-900\/30,
    body[data-theme='light'] .bg-slate-800\/70,
    body[data-theme='light'] .bg-slate-950\/35,
    body[data-theme='light'] .bg-slate-950\/45,
    body[data-theme='light'] .bg-slate-900\/65 {
      background-color: rgba(255, 255, 255, 0.74) !important;
    }

    body[data-theme='light'] .quicklink-badge {
      border-color: rgba(51, 65, 85, 0.24);
      background: rgba(241, 245, 249, 0.95);
      color: #0f172a;
    }

    body[data-theme='light'] .utility-link {
      border-color: rgba(15, 23, 42, 0.22);
      background: rgba(255, 255, 255, 0.95);
      color: #0f172a;
    }

    body[data-theme='light'] .utility-link-active {
      border-color: rgba(8, 145, 178, 0.45);
      background: rgba(103, 232, 249, 0.3);
      color: #0f172a;
    }

    body[data-theme='light'] .quicklink-tile {
      border-color: rgba(15, 23, 42, 0.18);
      background-color: rgba(255, 255, 255, 0.93);
    }

    body[data-theme='light'] .quicklink-tile:hover {
      background-color: rgba(241, 245, 249, 0.98);
      border-color: rgba(14, 116, 144, 0.35);
    }

    body[data-theme='light'] .switcher-chip {
      border-color: rgba(15, 23, 42, 0.22);
      background: rgba(255, 255, 255, 0.95);
      color: #0f172a;
    }

    body[data-theme='light'] .switcher-select {
      color: #0f172a;
    }

    body[data-theme='light'] .text-mint {
      color: #047857 !important;
    }

    body[data-theme='light'] .text-coral,
    body[data-theme='light'] .text-rose-300,
    body[data-theme='light'] .text-rose-200,
    body[data-theme='light'] .text-rose-100 {
      color: #b91c1c !important;
    }

    body[data-theme='light'] .border-white\/10,
    body[data-theme='light'] .border-white\/15,
    body[data-theme='light'] .border-white\/20 {
      border-color: rgba(15, 23, 42, 0.16) !important;
    }

    body[data-theme='light'] .shop-gradient-btn {
      color: #ffffff;
    }

    body[data-theme='light'] .scanner-toggle,
    body[data-theme='light'] .scanner-toggle-muted {
      color: #0f172a;
      background-color: rgba(255, 255, 255, 0.9);
      border-color: rgba(15, 23, 42, 0.2);
    }

    .glass {
      background: linear-gradient(145deg, var(--glass-top), var(--glass-bottom));
      backdrop-filter: blur(18px);
      -webkit-backdrop-filter: blur(18px);
      border: 1px solid var(--glass-border);
    }

    .bubble-layer {
      position: fixed;
      inset: 0;
      overflow: hidden;
      pointer-events: none;
      z-index: 0;
      opacity: 0.45;
    }

    .bubble {
      position: absolute;
      bottom: -120px;
      border-radius: 999px;
      border: 1px solid rgba(255, 255, 255, 0.12);
      background: radial-gradient(circle at 30% 30%, rgba(255, 255, 255, 0.18), rgba(6, 182, 212, 0.05));
      filter: blur(1.2px);
      animation: bubbleFloat linear infinite;
      opacity: 0.2;
    }

    .bubble:nth-child(1) { left: 8%; width: 50px; height: 50px; animation-duration: 24s; animation-delay: -5s; background: radial-gradient(circle at 30% 30%, rgba(56, 189, 248, 0.22), rgba(56, 189, 248, 0.04)); }
    .bubble:nth-child(2) { left: 20%; width: 26px; height: 26px; animation-duration: 18s; animation-delay: -10s; background: radial-gradient(circle at 30% 30%, rgba(52, 211, 153, 0.24), rgba(52, 211, 153, 0.05)); }
    .bubble:nth-child(3) { left: 35%; width: 40px; height: 40px; animation-duration: 22s; animation-delay: -2s; background: radial-gradient(circle at 30% 30%, rgba(244, 114, 182, 0.22), rgba(244, 114, 182, 0.04)); }
    .bubble:nth-child(4) { left: 52%; width: 62px; height: 62px; animation-duration: 30s; animation-delay: -8s; background: radial-gradient(circle at 30% 30%, rgba(125, 211, 252, 0.2), rgba(125, 211, 252, 0.04)); }
    .bubble:nth-child(5) { left: 67%; width: 34px; height: 34px; animation-duration: 20s; animation-delay: -3s; background: radial-gradient(circle at 30% 30%, rgba(250, 204, 21, 0.2), rgba(250, 204, 21, 0.04)); }
    .bubble:nth-child(6) { left: 79%; width: 56px; height: 56px; animation-duration: 28s; animation-delay: -14s; background: radial-gradient(circle at 30% 30%, rgba(129, 140, 248, 0.2), rgba(129, 140, 248, 0.04)); }
    .bubble:nth-child(7) { left: 90%; width: 24px; height: 24px; animation-duration: 17s; animation-delay: -6s; background: radial-gradient(circle at 30% 30%, rgba(20, 184, 166, 0.2), rgba(20, 184, 166, 0.04)); }

    @keyframes bubbleFloat {
      0% { transform: translateY(0) translateX(0) scale(0.95); opacity: 0; }
      10% { opacity: 0.38; }
      50% { transform: translateY(-55vh) translateX(12px) scale(1); }
      100% { transform: translateY(-108vh) translateX(-10px) scale(1.06); opacity: 0; }
    }

    @media (prefers-reduced-motion: reduce) {
      .bubble {
        animation: none;
        opacity: 0.08;
      }
    }

    .scrollbar-thin::-webkit-scrollbar {
      width: 8px;
    }

    .scrollbar-thin::-webkit-scrollbar-thumb {
      background: rgba(148, 163, 184, 0.45);
      border-radius: 999px;
    }

    #scanStatus {
      transition: opacity 180ms ease, transform 180ms ease;
    }

    #scanStatus.hidden {
      opacity: 0;
      transform: translateY(-6px);
      pointer-events: none;
    }

    .shop-gradient-btn {
      background: linear-gradient(90deg, var(--shop-accent-primary), var(--shop-accent-secondary));
      box-shadow: 0 10px 30px rgba(6, 182, 212, 0.25);
    }

    .scanner-toggle {
      border-color: color-mix(in srgb, var(--shop-accent-primary) 35%, white);
      background-color: color-mix(in srgb, var(--shop-accent-primary) 18%, transparent);
      color: #cffafe;
    }

    .scanner-toggle-muted {
      border-color: rgba(148, 163, 184, 0.35);
      background-color: rgba(100, 116, 139, 0.18);
      color: #e2e8f0;
    }

    @keyframes cart-pop {
      0% {
        transform: scale(1);
      }
      50% {
        transform: scale(1.18);
      }
      100% {
        transform: scale(1);
      }
    }

    .cart-pop {
      animation: cart-pop 200ms ease-out;
    }

    .quicklink-tile {
      position: relative;
      overflow: hidden;
      transition: transform 170ms ease, border-color 170ms ease, background-color 170ms ease;
    }

    .quicklink-tile:hover {
      transform: translateY(-2px);
      border-color: rgba(125, 211, 252, 0.45);
      background-color: rgba(15, 23, 42, 0.58);
    }

    .quicklink-badge {
      border: 1px solid rgba(148, 163, 184, 0.35);
      background: rgba(148, 163, 184, 0.18);
      color: #cbd5e1;
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

    .utility-link {
      border-radius: 0.6rem;
      border: 1px solid rgba(148, 163, 184, 0.35);
      background: rgba(15, 23, 42, 0.5);
      color: #dbeafe;
      padding: 0.35rem 0.65rem;
      transition: background-color 170ms ease, border-color 170ms ease;
    }

    .utility-link:hover {
      border-color: rgba(125, 211, 252, 0.45);
      background: rgba(15, 23, 42, 0.75);
    }

    .utility-link:focus-visible,
    .quicklink-badge:focus-visible {
      outline: 2px solid rgba(125, 211, 252, 0.8);
      outline-offset: 2px;
    }

    .utility-link-active {
      border-color: rgba(34, 211, 238, 0.45);
      background: rgba(34, 211, 238, 0.16);
      color: #cffafe;
      cursor: default;
    }

    .icon-link {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
    }

    .switcher-chip {
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
      border-radius: 0.7rem;
      border: 1px solid rgba(148, 163, 184, 0.35);
      background: rgba(15, 23, 42, 0.68);
      padding: 0.3rem 0.5rem;
    }

    .switcher-icon {
      width: 0.95rem;
      height: 0.95rem;
      opacity: 0.9;
      flex-shrink: 0;
    }

    .switcher-select {
      min-width: 3.9rem;
      border-radius: 0.45rem;
      background: transparent;
      font-size: 0.72rem;
      font-weight: 700;
      text-transform: uppercase;
      outline: none;
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

    .nav-icon {
      width: 0.88rem;
      height: 0.88rem;
      opacity: 0.92;
      flex-shrink: 0;
    }

    @media (max-width: 768px) {
      .bubble-layer {
        opacity: 0.28;
      }

      #scannerSoundToggle {
        top: auto;
        left: auto;
        right: 0.75rem;
        bottom: 5.2rem;
        padding: 0.45rem 0.6rem;
        font-size: 0.68rem;
      }

      #scanStatus {
        top: auto;
        left: auto;
        right: 0.75rem;
        bottom: 2.4rem;
      }

      #topUtilityBar {
        justify-content: flex-start;
        align-items: flex-start;
      }

      #quickControlsCluster {
        width: 100%;
      }

      #quickControlsBar {
        width: 100%;
        justify-content: space-between;
        gap: 0.4rem;
      }

      #quickControlsBar label {
        flex: 1 1 0;
        min-width: 0;
      }

      #quickControlsBar .switcher-label {
        display: none;
      }

      #quickControlsBar .switcher-chip {
        gap: 0.25rem;
        padding: 0.28rem 0.42rem;
      }

      #quickControlsBar .switcher-select {
        min-width: 3.2rem;
        font-size: 0.68rem;
      }

      #quickControlsBar select {
        width: 100%;
      }

      #quickActionButtons {
        width: 100%;
      }

      #quickActionButtons button {
        flex: 1 1 0;
      }

      .quicklink-badge,
      .add-to-cart,
      .remove-item,
      #clearCart,
      #checkoutBtn {
        min-height: 42px;
      }

      #smartModeHint {
        display: none;
      }

      #smartPicksGrid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }

      #cartItems {
        max-height: 36vh;
      }

      #cartActionRow {
        position: sticky;
        bottom: 0;
        padding-top: 0.6rem;
        padding-bottom: calc(0.45rem + env(safe-area-inset-bottom, 0px));
        background: linear-gradient(to top, rgba(7, 11, 20, 0.92), rgba(7, 11, 20, 0));
      }

      body[data-theme='light'] #cartActionRow {
        background: linear-gradient(to top, rgba(238, 243, 251, 0.96), rgba(238, 243, 251, 0));
      }
    }
  </style>
</head>
<body class="text-slate-100 antialiased">
  <a href="#mainContent" class="skip-link">Skip to checkout content</a>
  <div class="bubble-layer" aria-hidden="true">
    <span class="bubble"></span>
    <span class="bubble"></span>
    <span class="bubble"></span>
    <span class="bubble"></span>
    <span class="bubble"></span>
    <span class="bubble"></span>
    <span class="bubble"></span>
  </div>

  <button
    id="scannerSoundToggle"
    type="button"
    class="scanner-toggle fixed left-4 top-4 z-50 rounded-xl border px-3 py-2 text-xs font-semibold shadow-lg transition hover:brightness-110 sm:left-6 sm:top-6"
    aria-pressed="true"
  >
    Scanner Sound: On
  </button>

  <div id="scanStatus" data-i18n="scanModeActive" role="status" aria-live="polite" class="hidden fixed left-4 top-16 z-50 rounded-xl border px-3 py-2 text-xs font-semibold shadow-lg sm:left-6 sm:top-20">
    Scanner mode active
  </div>

  <main id="mainContent" class="relative z-10 mx-auto max-w-[1600px] px-4 py-4 sm:px-6 sm:py-6 lg:px-8 lg:py-8">
    <nav id="topUtilityBar" class="mb-3 flex flex-wrap items-center justify-between gap-3 text-xs text-slate-300" aria-label="Primary navigation">
      <span id="signedInText" class="rounded-lg border border-white/10 bg-slate-900/40 px-2 py-1" data-name="<?= e((string) $currentUser['full_name']) ?>" data-role="<?= e((string) $currentUser['role']) ?>">Signed in as <?= e((string) $currentUser['full_name']) ?> (<?= e((string) $currentUser['role']) ?>)</span>
      <div class="flex flex-wrap items-center gap-2">
      <span class="utility-link utility-link-active">Checkout</span>
      <?php if ((string) $currentUser['role'] === 'admin'): ?>
        <a href="add_product.php" class="utility-link icon-link"><svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 3a1 1 0 0 1 1 1v5h5a1 1 0 1 1 0 2h-5v5a1 1 0 1 1-2 0v-5H4a1 1 0 1 1 0-2h5V4a1 1 0 0 1 1-1z"/></svg><span data-i18n="addProduct">Add Product</span></a>
        <a href="manage_users.php" class="utility-link icon-link"><svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 10a4 4 0 1 0-4-4 4 4 0 0 0 4 4zm0 2c-3.31 0-6 1.79-6 4v1h12v-1c0-2.21-2.69-4-6-4z"/></svg><span data-i18n="users">Users</span></a>
        <a href="audit_logs.php" class="utility-link icon-link"><svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M5 3h10a1 1 0 0 1 1 1v12l-3-2-3 2-3-2-3 2V4a1 1 0 0 1 1-1z"/></svg><span data-i18n="audit">Audit</span></a>
        <a href="settings.php" class="utility-link icon-link"><svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M11.98 2.5a1 1 0 0 0-1.96 0l-.2 1.2a6.9 6.9 0 0 0-1.53.63l-1.03-.65a1 1 0 0 0-1.34.29l-.98 1.7a1 1 0 0 0 .23 1.29l.95.76a6.8 6.8 0 0 0 0 1.76l-.95.76a1 1 0 0 0-.23 1.29l.98 1.7a1 1 0 0 0 1.34.29l1.03-.65c.48.27.99.48 1.53.63l.2 1.2a1 1 0 0 0 1.96 0l.2-1.2c.54-.15 1.05-.36 1.53-.63l1.03.65a1 1 0 0 0 1.34-.29l.98-1.7a1 1 0 0 0-.23-1.29l-.95-.76a6.8 6.8 0 0 0 0-1.76l.95-.76a1 1 0 0 0 .23-1.29l-.98-1.7a1 1 0 0 0-1.34-.29l-1.03.65a6.9 6.9 0 0 0-1.53-.63zM10 7a3 3 0 1 1 0 6 3 3 0 0 1 0-6z"/></svg><span data-i18n="settings">Settings</span></a>
      <?php endif; ?>
      <?php if (in_array((string) $currentUser['role'], ['admin', 'manager'], true)): ?>
        <a href="inventory_adjustments.php" class="utility-link icon-link"><svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M3 4h14v3H3zm0 5h14v3H3zm0 5h14v2H3z"/></svg><span data-i18n="inventory">Inventory</span></a>
      <?php endif; ?>
      <a href="receipt_history.php" class="utility-link icon-link"><svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M5 2h10a1 1 0 0 1 1 1v14l-2-1-2 1-2-1-2 1-2-1-2 1V3a1 1 0 0 1 1-1zm2 4v2h6V6zm0 4v2h6v-2z"/></svg><span data-i18n="receipts">Receipts</span></a>
      <a href="dashboard.php" class="utility-link icon-link"><svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M3 3h6v6H3zm8 0h6v10h-6zM3 11h6v6H3zm8 4h6v2h-6z"/></svg><span data-i18n="qlDashboard">Dashboard</span></a>
      </div>
    </nav>

    <section class="mb-4 animate-rise [animation-delay:80ms]">
      <div class="glass rounded-2xl p-4 sm:p-5">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
          <div>
            <h2 data-i18n="quicklinksTitle" class="font-display text-lg font-semibold text-white">Smart Quicklinks</h2>
            <p data-i18n="quicklinksSub" class="text-xs text-slate-300">Jump straight to the features you use most.</p>
          </div>
          <div id="quickControlsCluster" class="flex flex-wrap items-center gap-2">
            <div id="quickControlsBar" class="flex items-center gap-2 rounded-xl border border-white/15 bg-slate-900/45 px-2 py-1.5 focus-within:ring-2 focus-within:ring-cyan-300/45">
              <span data-i18n="quickControls" class="px-1 text-[11px] font-semibold uppercase tracking-wide text-slate-300">Quick Controls</span>
              <label class="switcher-chip" title="Theme">
                <svg class="switcher-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 2a8 8 0 1 0 8 8 7 7 0 0 1-8-8z"/></svg>
                <span data-i18n="theme" class="switcher-label">Theme</span>
                <select id="themeSwitch" aria-label="Theme" class="switcher-select text-slate-100">
                  <option value="dark" data-i18n="themeDark">Dark</option>
                  <option value="light" data-i18n="themeLight">Light</option>
                </select>
              </label>
              <label class="switcher-chip" title="Language">
                <svg class="switcher-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 2a8 8 0 1 0 8 8 8 8 0 0 0-8-8zm4.9 7h-2.1a12.5 12.5 0 0 0-.6-3 6 6 0 0 1 2.7 3zM10 4.2c.6.9 1.1 2.8 1.3 4.8H8.7c.2-2 .7-3.9 1.3-4.8zM6.8 6a12.5 12.5 0 0 0-.6 3H4.1a6 6 0 0 1 2.7-3zM4.1 11h2.1c.1 1.1.3 2.1.6 3a6 6 0 0 1-2.7-3zm3.6 0h2.6c-.2 2-.7 3.9-1.3 4.8-.6-.9-1.1-2.8-1.3-4.8zm4.5 3c.3-.9.5-1.9.6-3h2.1a6 6 0 0 1-2.7 3z"/></svg>
                <span data-i18n="language" class="switcher-label">Language</span>
                <select id="languageSwitch" aria-label="Language" class="switcher-select text-slate-100">
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
              <a href="logout.php" class="quicklink-badge icon-link rounded-lg px-3 py-1.5 text-xs font-semibold hover:brightness-110"><svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M7 3h6a1 1 0 0 1 1 1v3h-2V5H8v10h4v-2h2v3a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1zm7.29 4.29L16.41 9H10v2h6.41l-2.12 1.71 1.42 1.42L20 10l-4.29-4.13z"/></svg><span data-i18n="signOut">Sign out</span></a>
            </div>
            <div id="quickActionButtons" class="flex items-center gap-2">
              <button id="quickSearchBtn" type="button" data-i18n="quickSearch" class="quicklink-badge rounded-lg px-3 py-1.5 text-xs font-semibold hover:brightness-110">Quick Search (F2)</button>
              <button id="quickCheckoutBtn" type="button" data-i18n="quickCheckout" class="quicklink-badge rounded-lg px-3 py-1.5 text-xs font-semibold hover:brightness-110">Quick Checkout (F9)</button>
            </div>
          </div>
        </div>

        <div class="mb-2 flex flex-wrap items-center gap-2">
          <a href="dashboard.php" class="quicklink-badge rounded-lg px-3 py-1.5 text-xs font-semibold hover:brightness-110" data-i18n="qlDashboard">Dashboard</a>
          <a href="receipt_history.php" class="quicklink-badge rounded-lg px-3 py-1.5 text-xs font-semibold hover:brightness-110" data-i18n="qlReceipts">Receipt History</a>
          <button id="moreToolsToggle" type="button" class="quicklink-badge rounded-lg px-3 py-1.5 text-xs font-semibold hover:brightness-110" data-i18n="showMoreTools" aria-expanded="false" aria-controls="moreToolsPanel">Show more tools</button>
        </div>

        <div id="moreToolsPanel" class="hidden grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
          <?php if ($userRole === 'admin'): ?>
            <a href="manage_products.php" class="quicklink-tile rounded-xl border border-white/10 bg-slate-900/40 p-3">
              <p data-i18n="qlProducts" class="text-sm font-semibold text-white">Manage Products</p>
              <p data-i18n="qlProductsSub" class="mt-1 text-xs text-slate-300">Edit prices, SKUs, and availability</p>
            </a>

            <a href="manage_users.php" class="quicklink-tile rounded-xl border border-white/10 bg-slate-900/40 p-3">
              <p data-i18n="qlUsers" class="text-sm font-semibold text-white">Manage Users</p>
              <p data-i18n="qlUsersSub" class="mt-1 text-xs text-slate-300">Control access and staff accounts</p>
            </a>
          <?php elseif ($userRole === 'manager'): ?>
            <a href="inventory_adjustments.php" class="quicklink-tile rounded-xl border border-white/10 bg-slate-900/40 p-3">
              <p data-i18n="qlInventory" class="text-sm font-semibold text-white">Inventory Adjustments</p>
              <p data-i18n="qlInventorySub" class="mt-1 text-xs text-slate-300">Correct stock counts quickly</p>
            </a>

            <a href="dashboard.php" class="quicklink-tile rounded-xl border border-white/10 bg-slate-900/40 p-3">
              <p data-i18n="qlTeamPerformance" class="text-sm font-semibold text-white">Team Performance</p>
              <p data-i18n="qlTeamPerformanceSub" class="mt-1 text-xs text-slate-300">Track sales pace and alerts</p>
            </a>
          <?php else: ?>
            <a href="receipt_history.php" class="quicklink-tile rounded-xl border border-white/10 bg-slate-900/40 p-3">
              <p data-i18n="qlFindSale" class="text-sm font-semibold text-white">Find Past Sale</p>
              <p data-i18n="qlFindSaleSub" class="mt-1 text-xs text-slate-300">Locate receipts for returns</p>
            </a>

            <a href="dashboard.php" class="quicklink-tile rounded-xl border border-white/10 bg-slate-900/40 p-3">
              <p data-i18n="qlDailyView" class="text-sm font-semibold text-white">Daily View</p>
              <p data-i18n="qlDailyViewSub" class="mt-1 text-xs text-slate-300">Check progress before shift end</p>
            </a>
          <?php endif; ?>
        </div>

      </div>
    </section>

    <?php if ($databaseError !== null): ?>
      <div class="mb-4 rounded-2xl border border-amber-400/25 bg-amber-500/10 px-4 py-3 text-sm text-amber-100">
        Database connection is not ready. Configure DB credentials, import schema, and refresh.
      </div>
    <?php endif; ?>

    <?php require __DIR__ . '/app/Views/partials/header.php'; ?>

    <section class="grid gap-5 lg:grid-cols-[minmax(0,2fr)_minmax(340px,1fr)]">
      <div class="animate-rise [animation-delay:120ms]">
        <?php require __DIR__ . '/app/Views/partials/product-grid.php'; ?>
      </div>

      <?php require __DIR__ . '/app/Views/partials/cart-sidebar.php'; ?>
    </section>
  </main>

  <script src="assets/js/cart.js"></script>
  <script src="assets/js/checkout.js"></script>
  <script>
    const cartItemsContainer = document.getElementById('cartItems');
    const subtotalEl = document.getElementById('subtotal');
    const taxEl = document.getElementById('tax');
    const totalEl = document.getElementById('total');
    const clearCartBtn = document.getElementById('clearCart');
    const checkoutBtn = document.getElementById('checkoutBtn');
    const searchForm = document.getElementById('searchForm');
    const searchInput = document.getElementById('searchInput');
    const searchSpinner = document.getElementById('searchSpinner');
    const clearSearchBtn = document.getElementById('clearSearchBtn');
    const searchSubmitBtn = searchForm ? searchForm.querySelector('button[type="submit"]') : null;
    const productGrid = document.getElementById('productGrid');
    const productCountEl = document.getElementById('productCount');
    const scanStatusEl = document.getElementById('scanStatus');
    const scannerSoundToggle = document.getElementById('scannerSoundToggle');
    const themeSwitch = document.getElementById('themeSwitch');
    const languageSwitch = document.getElementById('languageSwitch');
    const signedInText = document.getElementById('signedInText');
    const quickSearchBtn = document.getElementById('quickSearchBtn');
    const quickCheckoutBtn = document.getElementById('quickCheckoutBtn');
    const moreToolsToggle = document.getElementById('moreToolsToggle');
    const moreToolsPanel = document.getElementById('moreToolsPanel');
    const cartIndicator = document.getElementById('cartIndicator');
    const taxLabelEl = document.getElementById('taxLabel');
    const csrfTokenMeta = document.querySelector('meta[name="csrf-token"]');
    const currencySymbolMeta = document.querySelector('meta[name="currency-symbol"]');
    const taxRatePercentMeta = document.querySelector('meta[name="tax-rate-percent"]');
    let latestSearchResults = [];
    let lastKeypressAt = 0;
    let fastKeyStreak = 0;
    let scannerBuffer = '';
    let scannerLastKeyAt = 0;
    let scannerClearTimer = null;
    let scanStatusTimer = null;
    let audioContext = null;
    let scannerSoundEnabled = true;
    let smartMode = 'best';

    const BARCODE_MAX_KEY_INTERVAL_MS = 45;
    const BARCODE_MIN_LENGTH = 6;
    const SCANNER_IDLE_RESET_MS = 120;
    const SCANNER_SOUND_PREF_KEY = 'novapos_scanner_sound_enabled';
    const THEME_PREF_KEY = 'novapos_theme';
    const LANG_PREF_KEY = 'novapos_lang';
    const GHANA_TRANSLATE_API = 'api/translate_text.php';
    const GHANA_SUPPORTED_LANGS = new Set(['tw', 'ee', 'gaa', 'fat', 'dag', 'gur', 'kus']);
    const hydratedRemoteLanguages = new Set();
    const CSRF_TOKEN = csrfTokenMeta ? csrfTokenMeta.getAttribute('content') : '';
    const CURRENCY_SYMBOL = currencySymbolMeta ? currencySymbolMeta.getAttribute('content') : '$';
    const DEFAULT_TAX_RATE_PERCENT = taxRatePercentMeta ? Number(taxRatePercentMeta.getAttribute('content')) : 8;
    const smartPicksData = <?= $smartPicksJson ?: '[]' ?>;
    const bestSellersData = <?= $bestSellersJson ?: '[]' ?>;
    const recentProductsData = <?= $recentProductsJson ?: '[]' ?>;

    const productImages = [
      'assets/images/product-placeholder-1.svg',
      'assets/images/product-placeholder-2.svg',
      'assets/images/product-placeholder-3.svg',
      'assets/images/product-placeholder-4.svg',
      'assets/images/product-placeholder-5.svg',
      'assets/images/product-placeholder-6.svg'
    ];

    const translations = {
      en: {
        theme: 'Theme',
        themeDark: 'Dark',
        themeLight: 'Light',
        language: 'Language',
        signedInAs: 'Signed in as',
        addProduct: 'Add Product',
        users: 'Users',
        audit: 'Audit',
        settings: 'Settings',
        inventory: 'Inventory',
        receipts: 'Receipts',
        signOut: 'Sign out',
        quickControls: 'Quick Controls',
        quicklinksTitle: 'Smart Quicklinks',
        quicklinksSub: 'Jump straight to the features you use most.',
        quickSearch: 'Quick Search (F2)',
        quickCheckout: 'Quick Checkout (F9)',
        showMoreTools: 'Show more tools',
        hideMoreTools: 'Hide more tools',
        qlDashboard: 'Dashboard',
        qlDashboardSub: 'Live sales and stock insights',
        qlReceipts: 'Receipt History',
        qlReceiptsSub: 'Find and reprint recent receipts',
        qlProducts: 'Manage Products',
        qlProductsSub: 'Edit prices, SKUs, and availability',
        qlUsers: 'Manage Users',
        qlUsersSub: 'Control access and staff accounts',
        qlInventory: 'Inventory Adjustments',
        qlInventorySub: 'Correct stock counts quickly',
        qlTeamPerformance: 'Team Performance',
        qlTeamPerformanceSub: 'Track sales pace and alerts',
        qlFindSale: 'Find Past Sale',
        qlFindSaleSub: 'Locate receipts for returns',
        qlDailyView: 'Daily View',
        qlDailyViewSub: 'Check progress before shift end',
        smartMode: 'Smart Picks',
        bestMode: 'Best Sellers',
        recentMode: 'Recently Added',
        smartModeHint: 'Balanced by popularity, sales velocity, and freshness.',
        bestModeHint: 'Top items based on total units sold.',
        recentModeHint: 'Newest active products added to the catalog.',
        smartNoItems: 'No smart recommendations right now. Use search to find products.',
        soldTag: 'Sold',
        newTag: 'New',
        searchPlaceholder: 'Search products, barcode, SKU...',
        searchButton: 'Search',
        products: 'Products',
        noProductsFound: 'No products found. Try a different search term.',
        noResultsFound: 'No results found. Try a different name, SKU, or barcode.',
        stock: 'Stock',
        lowStock: 'Low stock',
        out: 'Out',
        add: 'Add',
        items: 'Items',
        currentCart: 'Current Cart',
        session: 'Session',
        subtotal: 'Subtotal',
        tax: 'Tax',
        total: 'Total',
        clear: 'Clear',
        checkout: 'Checkout',
        scannerSoundOn: 'Scanner Sound: On',
        scannerSoundMuted: 'Scanner Sound: Muted',
        scanModeActive: 'Scanner mode active',
        cartEmpty: 'Your cart is empty. Add products to begin checkout.',
        qty: 'Qty',
        remove: 'Remove',
        confirmCheckout: 'Confirm checkout and process payment?',
        processing: 'Processing...',
        popupBlocked: 'Sale completed. Pop-up blocked; open receipt from dashboard.',
        receiptSuccess: 'Success! Receipt:',
        productAdded: 'Product added to cart',
        cartCleared: 'Cart cleared',
        authRequired: 'Authentication required',
        couldNotLoadProducts: 'Could not load product results. Please try again.',
        scannedAdded: 'Scanned and added',
        noMatchingInStock: 'No matching in-stock product for',
      },
      fr: {
        theme: 'Theme',
        themeDark: 'Sombre',
        themeLight: 'Clair',
        language: 'Langue',
        signedInAs: 'Connecte en tant que',
        addProduct: 'Ajouter produit',
        users: 'Utilisateurs',
        audit: 'Audit',
        settings: 'Parametres',
        inventory: 'Stock',
        receipts: 'Recus',
        signOut: 'Deconnexion',
        quickControls: 'Controle rapide',
        quicklinksTitle: 'Raccourcis intelligents',
        quicklinksSub: 'Accedez directement aux fonctions les plus utilisees.',
        quickSearch: 'Recherche rapide (F2)',
        quickCheckout: 'Encaissement rapide (F9)',
        showMoreTools: 'Afficher plus doutils',
        hideMoreTools: 'Masquer plus doutils',
        qlDashboard: 'Tableau de bord',
        qlDashboardSub: 'Ventes et stock en direct',
        qlReceipts: 'Historique des recus',
        qlReceiptsSub: 'Retrouver et reimprimer les recus',
        qlProducts: 'Gestion produits',
        qlProductsSub: 'Modifier prix, SKU et disponibilite',
        qlUsers: 'Gestion utilisateurs',
        qlUsersSub: 'Gerer les acces et comptes du personnel',
        qlInventory: 'Ajustements de stock',
        qlInventorySub: 'Corriger rapidement les quantites',
        qlTeamPerformance: 'Performance equipe',
        qlTeamPerformanceSub: 'Suivre le rythme et les alertes',
        qlFindSale: 'Trouver une vente',
        qlFindSaleSub: 'Retrouver des recus pour retours',
        qlDailyView: 'Vue journaliere',
        qlDailyViewSub: 'Verifier la progression avant la fin de service',
        smartMode: 'Selections intelligentes',
        bestMode: 'Meilleures ventes',
        recentMode: 'Ajouts recents',
        smartModeHint: 'Equilibre selon popularite, vitesse de vente et nouveaute.',
        bestModeHint: 'Produits en tete selon le volume vendu.',
        recentModeHint: 'Produits actifs ajoutes recemment au catalogue.',
        smartNoItems: 'Aucune recommandation pour le moment. Utilisez la recherche.',
        soldTag: 'Vendus',
        newTag: 'Nouveau',
        searchPlaceholder: 'Rechercher produits, code-barres, SKU...',
        searchButton: 'Rechercher',
        products: 'Produits',
        noProductsFound: 'Aucun produit trouve. Essayez une autre recherche.',
        noResultsFound: 'Aucun resultat. Essayez un autre nom, SKU ou code-barres.',
        stock: 'Stock',
        lowStock: 'Stock faible',
        out: 'Rupture',
        add: 'Ajouter',
        items: 'Articles',
        currentCart: 'Panier actuel',
        session: 'Session',
        subtotal: 'Sous-total',
        tax: 'Taxe',
        total: 'Total',
        clear: 'Vider',
        checkout: 'Encaisser',
        scannerSoundOn: 'Son scanner: Active',
        scannerSoundMuted: 'Son scanner: Muet',
        scanModeActive: 'Mode scanner actif',
        cartEmpty: 'Votre panier est vide. Ajoutez des produits.',
        qty: 'Qt',
        remove: 'Retirer',
        confirmCheckout: 'Confirmer l\'encaissement et traiter le paiement ?',
        processing: 'Traitement...',
        popupBlocked: 'Vente terminee. Popup bloquee; ouvrez le recu depuis le tableau de bord.',
        receiptSuccess: 'Succes! Recu:',
        productAdded: 'Produit ajoute au panier',
        cartCleared: 'Panier vide',
        authRequired: 'Authentification requise',
        couldNotLoadProducts: 'Impossible de charger les produits. Reessayez.',
        scannedAdded: 'Scanne et ajoute',
        noMatchingInStock: 'Aucun produit en stock correspondant pour',
      },
      tw: {
        language: 'Kasa',
        themeDark: 'Sum',
        themeLight: 'Hann',
        quickControls: 'Ntɛmntɛm Nhyɛso',
        quicklinksTitle: 'Smart Quicklinks',
        quicklinksSub: 'Kɔ ntɛm kɔ nneyɛe a wode di dwuma paa no so.',
        signOut: 'Fi mu',
        smartMode: 'Smart Picks',
        bestMode: 'Best Sellers',
        recentMode: 'Recently Added',
      },
      gaa: {
        language: 'Mli',
        themeDark: 'Bibi',
        themeLight: 'Kɛkɛ',
        quickControls: 'Kɛji Nshɔŋmɔ',
        quicklinksTitle: 'Smart Quicklinks',
        quicklinksSub: 'Lɛkɛ yi no, kɛ hew mli nitsumɔ ni oha nɔ.',
        signOut: 'Bɔ mli',
        smartMode: 'Smart Picks',
        bestMode: 'Best Sellers',
        recentMode: 'Recently Added',
      },
      ee: {
        language: 'Gbe',
        themeDark: 'Bibi',
        themeLight: 'Kekeli',
        quickControls: 'Dɔwɔwɔ Kpuiwo',
        quicklinksTitle: 'Smart Quicklinks',
        quicklinksSub: 'Yi mɔ kaba yi dɔwɔwɔ siwo nèzã geɖe o.',
        signOut: 'Do go',
        smartMode: 'Smart Picks',
        bestMode: 'Best Sellers',
        recentMode: 'Recently Added',
      },
      fat: {
        language: 'Mfantse',
        quickControls: 'Ntɛmntɛm Controls',
        quicklinksTitle: 'Smart Quicklinks',
        quicklinksSub: 'Kɔ ntɛmntɛm kɔ dwumadzi a idzi ho dwuma paa no so.',
        signOut: 'Pue',
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
      return `novapos_remote_i18n_index_${langCode}`;
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

      document.querySelectorAll('[data-i18n-placeholder]').forEach((element) => {
        const key = element.getAttribute('data-i18n-placeholder');
        if (key && 'placeholder' in element) {
          element.placeholder = t(key);
        }
      });

      if (signedInText) {
        const name = signedInText.getAttribute('data-name') || '';
        const role = signedInText.getAttribute('data-role') || '';
        signedInText.textContent = `${t('signedInAs')} ${name} (${role})`;
      }

      if (taxLabelEl) {
        const current = taxLabelEl.textContent || '';
        const match = current.match(/\(([^)]+)\)/);
        taxLabelEl.textContent = match ? `${t('tax')} (${match[1]})` : t('tax');
      }

      updateMoreToolsToggleLabel();
      updateScannerSoundToggleUI();
      applyProductMode(smartMode);
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
      if (themeSwitch) {
        themeSwitch.value = theme;
      }

      try {
        localStorage.setItem(THEME_PREF_KEY, theme);
      } catch (error) {
      }
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

    function updateMoreToolsToggleLabel() {
      if (!moreToolsToggle || !moreToolsPanel) {
        return;
      }

      const isExpanded = !moreToolsPanel.classList.contains('hidden');
      moreToolsToggle.textContent = isExpanded ? t('hideMoreTools') : t('showMoreTools');
      moreToolsToggle.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
    }

    function shouldAutoFocusSearch() {
      const mobileScreen = window.matchMedia ? window.matchMedia('(max-width: 768px)').matches : false;
      const touchDevice = ('ontouchstart' in window) || (navigator.maxTouchPoints > 0);
      return !(mobileScreen && touchDevice);
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

    function formatMoney(value) {
      return `${CURRENCY_SYMBOL}${Number(value).toFixed(2)}`;
    }

    function getSmartModeData(mode) {
      if (mode === 'recent') {
        return recentProductsData;
      }

      return bestSellersData;
    }

    function applyProductMode(mode = smartMode) {
      smartMode = mode === 'recent' ? 'recent' : 'best';

      document.querySelectorAll('[data-smart-mode]').forEach((button) => {
        const selected = button.getAttribute('data-smart-mode') === smartMode;
        button.classList.toggle('bg-cyan-500/25', selected);
        button.classList.toggle('text-cyan-100', selected);
      });

      const searchTerm = searchInput ? (searchInput.value || '').trim() : '';
      syncSearchClearButton();
      if (searchTerm !== '') {
        return;
      }

      const list = getSmartModeData(smartMode);
      latestSearchResults = Array.isArray(list) ? list : [];
      renderProducts(latestSearchResults);
    }

    function escapeHtml(value) {
      return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
    }

    function setSearchLoading(isLoading) {
      if (!searchSpinner) {
        return;
      }

      if (isLoading) {
        searchSpinner.classList.remove('hidden');
      } else {
        searchSpinner.classList.add('hidden');
      }

      if (searchSubmitBtn) {
        searchSubmitBtn.disabled = isLoading;
        searchSubmitBtn.setAttribute('aria-busy', isLoading ? 'true' : 'false');
      }
    }

    function syncSearchClearButton() {
      if (!clearSearchBtn || !searchInput) {
        return;
      }

      const hasTerm = (searchInput.value || '').trim() !== '';
      clearSearchBtn.classList.toggle('hidden', !hasTerm);
    }

    function getAudioContext() {
      const AudioCtx = window.AudioContext || window.webkitAudioContext;
      if (!AudioCtx) {
        return null;
      }

      if (!audioContext) {
        audioContext = new AudioCtx();
      }

      return audioContext;
    }

    function updateScannerSoundToggleUI() {
      if (!scannerSoundToggle) {
        return;
      }

      scannerSoundToggle.textContent = scannerSoundEnabled ? t('scannerSoundOn') : t('scannerSoundMuted');
      scannerSoundToggle.setAttribute('aria-pressed', scannerSoundEnabled ? 'true' : 'false');

      if (scannerSoundEnabled) {
        scannerSoundToggle.classList.remove('scanner-toggle-muted');
      } else {
        scannerSoundToggle.classList.add('scanner-toggle-muted');
      }
    }

    function loadScannerSoundPreference() {
      try {
        const saved = localStorage.getItem(SCANNER_SOUND_PREF_KEY);
        if (saved !== null) {
          scannerSoundEnabled = saved === '1';
        }
      } catch (error) {
        scannerSoundEnabled = true;
      }

      updateScannerSoundToggleUI();
    }

    function setScannerSoundEnabled(enabled) {
      scannerSoundEnabled = Boolean(enabled);

      try {
        localStorage.setItem(SCANNER_SOUND_PREF_KEY, scannerSoundEnabled ? '1' : '0');
      } catch (error) {
      }

      updateScannerSoundToggleUI();
    }

    function playScanTone(type) {
      if (!scannerSoundEnabled) {
        return;
      }

      const ctx = getAudioContext();
      if (!ctx) {
        return;
      }

      if (ctx.state === 'suspended') {
        ctx.resume().catch(() => {});
      }

      const now = ctx.currentTime;
      const oscillator = ctx.createOscillator();
      const gainNode = ctx.createGain();

      const isSuccess = type === 'success';
      oscillator.type = isSuccess ? 'sine' : 'triangle';
      oscillator.frequency.setValueAtTime(isSuccess ? 1046 : 220, now);

      gainNode.gain.setValueAtTime(0.0001, now);
      gainNode.gain.exponentialRampToValueAtTime(isSuccess ? 0.045 : 0.05, now + 0.015);
      gainNode.gain.exponentialRampToValueAtTime(0.0001, now + (isSuccess ? 0.09 : 0.14));

      oscillator.connect(gainNode);
      gainNode.connect(ctx.destination);

      oscillator.start(now);
      oscillator.stop(now + (isSuccess ? 0.1 : 0.15));
    }

    function showScanStatus(type, message) {
      if (!scanStatusEl) {
        return;
      }

      if (scanStatusTimer !== null) {
        clearTimeout(scanStatusTimer);
      }

      const successClasses = ['border-emerald-300/35', 'bg-emerald-500/20', 'text-emerald-100'];
      const errorClasses = ['border-rose-300/35', 'bg-rose-500/20', 'text-rose-100'];

      scanStatusEl.classList.remove(...successClasses, ...errorClasses, 'hidden');

      if (type === 'success') {
        scanStatusEl.classList.add(...successClasses);
      } else {
        scanStatusEl.classList.add(...errorClasses);
      }

      scanStatusEl.textContent = message;
      playScanTone(type);

      scanStatusTimer = setTimeout(() => {
        scanStatusEl.classList.add('hidden');
      }, 1400);
    }

    function renderProducts(products) {
      if (!productGrid) {
        return;
      }

      if (!Array.isArray(products) || products.length === 0) {
        productGrid.innerHTML = `
          <div class="col-span-full rounded-2xl border border-dashed border-white/20 bg-slate-900/30 p-8 text-center text-slate-300" role="status">
            ${t('noResultsFound')}
          </div>
        `;

        if (productCountEl) {
          productCountEl.textContent = `0 ${t('items')}`;
        }

        return;
      }

      productGrid.innerHTML = products.map((product, index) => {
        const stock = Number(product.stock_qty || 0);
        const disabledAttr = stock < 1 ? 'disabled' : '';
        const buttonLabel = stock < 1 ? t('out') : t('add');
        const image = productImages[index % productImages.length];

        return `
          <article class="group rounded-2xl border border-white/10 bg-slate-950/45 p-2 shadow-card transition hover:-translate-y-0.5 hover:border-cyan-300/35 hover:bg-slate-900/65">
            <img src="${image}" alt="${escapeHtml(product.name)}" class="h-28 w-full rounded-xl object-cover sm:h-32" />
            <div class="p-2">
              <h3 class="truncate text-sm font-semibold text-white">${escapeHtml(product.name)}</h3>
              <p class="mt-1 text-xs text-slate-400">${escapeHtml(product.category_name)}</p>
              <div class="mt-3 flex items-center justify-between gap-2">
                <div>
                  <p class="text-sm font-semibold text-mint">${formatMoney(product.unit_price)}</p>
                  <p class="text-[11px] text-slate-400">${t('stock')}: ${stock}</p>
                  ${stock > 0 && stock < 5 ? `<span class="mt-1 inline-flex rounded-full border border-rose-300/40 bg-rose-500/20 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-rose-100">${t('lowStock')}</span>` : ''}
                </div>
                <button class="add-to-cart rounded-lg bg-cyan-500/20 px-2 py-1 text-xs font-semibold text-cyan-100 transition hover:bg-cyan-500/30 focus:outline-none focus:ring-2 focus:ring-cyan-300/60 disabled:cursor-not-allowed disabled:opacity-40" data-product-id="${Number(product.id)}" aria-label="${escapeHtml(buttonLabel + ': ' + product.name)}" ${disabledAttr}>
                  ${buttonLabel}
                </button>
              </div>
            </div>
          </article>
        `;
      }).join('');

      if (productCountEl) {
        productCountEl.textContent = `${products.length} ${t('items')}`;
      }
    }

    function toast(message, type = 'success') {
      if (window.POSCartUX && typeof window.POSCartUX.showToast === 'function') {
        window.POSCartUX.showToast(message, type);
        return;
      }

      if (type === 'error') {
        alert(message);
      } else {
        console.info(message);
      }
    }

    function popCartIndicator() {
      if (window.POSCartUX && typeof window.POSCartUX.bounceCartIndicator === 'function') {
        window.POSCartUX.bounceCartIndicator(cartIndicator);
        return;
      }

      if (!cartIndicator) {
        return;
      }

      cartIndicator.classList.remove('cart-pop');
      void cartIndicator.offsetWidth;
      cartIndicator.classList.add('cart-pop');
    }

    function renderCart(cart) {
      const items = Array.isArray(cart.items) ? cart.items : [];

      if (!items.length) {
        if (window.POSCartUX && typeof window.POSCartUX.emptyStateMarkup === 'function') {
          cartItemsContainer.innerHTML = `
            <div class="rounded-xl border border-dashed border-white/20 bg-slate-900/30 p-4 text-center text-sm text-slate-400">
              ${t('cartEmpty')}
            </div>
          `;
        } else {
          cartItemsContainer.innerHTML = `
            <div class="rounded-xl border border-dashed border-white/20 bg-slate-900/30 p-4 text-center text-sm text-slate-400">
              ${t('cartEmpty')}
            </div>
          `;
        }
      } else {
        cartItemsContainer.innerHTML = items.map((item) => `
          <div class="rounded-xl border border-white/10 bg-slate-900/45 p-3">
            <div class="flex items-start justify-between gap-3">
              <div>
                <h4 class="text-sm font-medium text-white">${item.name}</h4>
                <p class="text-xs text-slate-400">${t('qty')} ${item.qty}</p>
              </div>
              <div class="text-right">
                <p class="text-sm font-semibold text-mint">${formatMoney(item.line_total)}</p>
                <button class="remove-item mt-1 text-[11px] text-rose-300 hover:text-rose-200" data-product-id="${item.product_id}">${t('remove')}</button>
              </div>
            </div>
          </div>
        `).join('');
      }

      subtotalEl.textContent = formatMoney(cart.subtotal || 0);
      taxEl.textContent = formatMoney(cart.tax || 0);
      totalEl.textContent = formatMoney(cart.total || 0);

      const taxRate = Number(cart.tax_rate_percent ?? DEFAULT_TAX_RATE_PERCENT);
      if (taxLabelEl) {
        taxLabelEl.textContent = `${t('tax')} (${taxRate.toFixed(2)}%)`;
      }

      document.querySelectorAll('.remove-item').forEach((button) => {
        button.addEventListener('click', async () => {
          await removeFromCart(Number(button.dataset.productId));
        });
      });
    }

    async function requestCart(url, payload = null) {
      const options = payload === null
        ? { method: 'GET' }
        : {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-Token': CSRF_TOKEN
            },
            body: JSON.stringify(payload)
          };

      const response = await fetch(url, options);
      const data = await response.json();

      if (response.status === 429) {
        const retryAfter = response.headers.get('Retry-After');
        throw new Error(retryAfter ? `Too many requests. Retry in ${retryAfter}s.` : 'Too many requests. Please wait and try again.');
      }

      if (response.status === 401 || response.status === 403) {
        window.location.href = 'login.php';
        throw new Error(t('authRequired'));
      }

      if (!response.ok || data.success !== true) {
        throw new Error(data.message || 'Request failed');
      }

      return data.data;
    }

    async function requestJson(url, payload) {
      const response = await fetch(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': CSRF_TOKEN
        },
        body: JSON.stringify(payload || {})
      });

      const data = await response.json();

      if (response.status === 429) {
        const retryAfter = response.headers.get('Retry-After');
        throw new Error(retryAfter ? `Too many requests. Retry in ${retryAfter}s.` : 'Too many requests. Please wait and try again.');
      }

      if (response.status === 401 || response.status === 403) {
        window.location.href = 'login.php';
        throw new Error(t('authRequired'));
      }

      if (!response.ok || data.success !== true) {
        throw new Error(data.message || 'Request failed');
      }

      return data;
    }

    async function loadCart() {
      try {
        const cart = await requestCart('./api/cart.php');
        renderCart(cart);
      } catch (error) {
        console.error(error);
      }
    }

    async function addToCart(productId) {
      try {
        let cart;

        if (window.POSCartUX && typeof window.POSCartUX.addToCart === 'function') {
          const response = await window.POSCartUX.addToCart(productId, 1, {
            endpoint: './api/add_to_cart.php',
            csrfToken: CSRF_TOKEN,
          });

          cart = {
            items: Array.isArray(response.cart) ? response.cart.map((item) => ({
              product_id: Number(item.product_id || 0),
              name: item.name || '',
              price: Number(item.price || 0),
              qty: Number(item.qty || 0),
              line_total: Number(item.price || 0) * Number(item.qty || 0),
            })) : [],
            subtotal: Number(response.summary?.subtotal || 0),
            tax: Number(response.summary?.tax || 0),
            total: Number(response.summary?.total || 0),
            tax_rate_percent: Number(response.summary?.tax_rate_percent || DEFAULT_TAX_RATE_PERCENT),
          };

          toast(response.message || 'Product added to cart');
        } else {
          cart = await requestCart('./api/cart.php?action=add', { product_id: productId, qty: 1 });
          toast(t('productAdded'));
        }

        renderCart(cart);
        popCartIndicator();
        return true;
      } catch (error) {
        toast(error.message, 'error');
        return false;
      }
    }

    async function removeFromCart(productId) {
      try {
        const cart = await requestCart('./api/cart.php?action=remove', { product_id: productId });
        renderCart(cart);
      } catch (error) {
        toast(error.message, 'error');
      }
    }

    async function clearCart() {
      try {
        const cart = await requestCart('./api/cart.php?action=clear', {});
        renderCart(cart);
        toast(t('cartCleared'));
      } catch (error) {
        toast(error.message, 'error');
      }
    }

    async function checkoutCart() {
      if (!window.POSCheckout || typeof window.POSCheckout.processCheckout !== 'function') {
        return;
      }

      await window.POSCheckout.processCheckout({
        endpoint: './api/checkout.php',
        csrfToken: CSRF_TOKEN,
        paymentMethod: 'cash',
        confirmMessage: t('confirmCheckout'),
        onStart: () => {
          if (checkoutBtn) {
            checkoutBtn.disabled = true;
            checkoutBtn.textContent = t('processing');
          }
        },
        onSuccess: async (response) => {
          await loadCart();
          await searchProducts((searchInput && searchInput.value ? searchInput.value : '').trim());
          const receiptNo = response?.data?.receipt_no || 'N/A';
          const saleId = Number(response?.data?.sale_id || 0);

          if (saleId > 0) {
            const receiptUrl = `receipt.php?sale_id=${saleId}&print=1`;
            const receiptWindow = window.open(receiptUrl, '_blank', 'noopener,noreferrer,width=420,height=760');

            if (!receiptWindow) {
              toast(t('popupBlocked'), 'error');
            }
          }

          toast(`${t('receiptSuccess')} ${receiptNo}`);
        },
        onError: (message) => {
          toast(message, 'error');
        },
        onFinish: () => {
          if (checkoutBtn) {
            checkoutBtn.disabled = false;
            checkoutBtn.textContent = t('checkout');
          }
        }
      });
    }

    async function searchProducts(term) {
      syncSearchClearButton();
      if (term === '') {
        setSearchLoading(false);
        applyProductMode(smartMode);
        return latestSearchResults;
      }

      setSearchLoading(true);

      try {
        const response = await fetch(`./api/search_product.php?query=${encodeURIComponent(term)}`);
        const payload = await response.json();

        if (response.status === 429) {
          const retryAfter = response.headers.get('Retry-After');
          throw new Error(retryAfter ? `Search rate limit reached. Retry in ${retryAfter}s.` : 'Search rate limit reached.');
        }

        if (response.status === 401 || response.status === 403) {
          window.location.href = 'login.php';
          throw new Error('Authentication required');
        }

        if (!response.ok || payload.success !== true) {
          throw new Error(payload.message || 'Search failed');
        }

        latestSearchResults = Array.isArray(payload.data) ? payload.data : [];
        renderProducts(latestSearchResults);
        return latestSearchResults;
      } catch (error) {
        latestSearchResults = [];

        if (productGrid) {
          productGrid.innerHTML = `
            <div class="col-span-full rounded-2xl border border-rose-400/20 bg-rose-500/10 p-8 text-center text-rose-100">
              ${t('couldNotLoadProducts')}
            </div>
          `;
        }

        if (productCountEl) {
          productCountEl.textContent = `0 ${t('items')}`;
        }

        console.error(error);
        return [];
      } finally {
        setSearchLoading(false);
      }
    }

    function getFirstInStockResult() {
      if (!Array.isArray(latestSearchResults)) {
        return null;
      }

      return latestSearchResults.find((product) => Number(product.stock_qty || 0) > 0) || null;
    }

    async function addFirstResultToCart() {
      const first = getFirstInStockResult();
      if (!first) {
        return false;
      }

      return addToCart(Number(first.id));
    }

    function updateBarcodeCadence(key) {
      const isPrintable = key.length === 1;
      const now = performance.now();

      if (!isPrintable) {
        return;
      }

      if (lastKeypressAt === 0) {
        fastKeyStreak = 1;
      } else {
        const delta = now - lastKeypressAt;
        fastKeyStreak = delta <= BARCODE_MAX_KEY_INTERVAL_MS ? fastKeyStreak + 1 : 1;
      }

      lastKeypressAt = now;
    }

    function isLikelyBarcode(term) {
      const sinceLastKey = performance.now() - lastKeypressAt;
      return term.length >= BARCODE_MIN_LENGTH && fastKeyStreak >= 4 && sinceLastKey <= 180;
    }

    function shouldIgnoreGlobalCapture(target) {
      if (!target) {
        return false;
      }

      if (target === searchInput) {
        return true;
      }

      if (target.isContentEditable) {
        return true;
      }

      const tagName = (target.tagName || '').toLowerCase();
      return tagName === 'input' || tagName === 'textarea' || tagName === 'select';
    }

    function scheduleScannerBufferReset() {
      if (scannerClearTimer !== null) {
        clearTimeout(scannerClearTimer);
      }

      scannerClearTimer = setTimeout(() => {
        scannerBuffer = '';
        scannerLastKeyAt = 0;
      }, SCANNER_IDLE_RESET_MS);
    }

    async function processGlobalScanBuffer(term) {
      if (!searchInput) {
        return;
      }

      searchInput.value = term;
      await searchProducts(term);

      let added = await addFirstResultToCart();
      if (!added) {
        await searchProducts(term);
        added = await addFirstResultToCart();
      }

      if (added) {
        showScanStatus('success', `${t('scannedAdded')}: ${term}`);
        searchInput.value = '';
        latestSearchResults = [];
        fastKeyStreak = 0;
        await searchProducts('');
      } else {
        showScanStatus('error', `${t('noMatchingInStock')}: ${term}`);
      }
    }

    function debounce(fn, delayMs) {
      let timeoutId;

      return (...args) => {
        clearTimeout(timeoutId);
        timeoutId = setTimeout(() => fn(...args), delayMs);
      };
    }

    const debouncedSearch = debounce((term) => {
      searchProducts(term);
    }, 250);

    if (productGrid) {
      productGrid.addEventListener('click', async (event) => {
        const button = event.target.closest('.add-to-cart');
        if (!button) {
          return;
        }

        if (button.hasAttribute('disabled')) {
          return;
        }

        await addToCart(Number(button.dataset.productId));
      });
    }

    document.querySelectorAll('[data-smart-mode]').forEach((button) => {
      button.addEventListener('click', () => {
        const mode = button.getAttribute('data-smart-mode') || 'best';
        applyProductMode(mode);
      });
    });

    if (searchInput) {
      searchInput.addEventListener('input', (event) => {
        const term = event.target.value || '';
        syncSearchClearButton();
        debouncedSearch(term.trim());
      });

      searchInput.addEventListener('keydown', async (event) => {
        updateBarcodeCadence(event.key);

        if (event.key === 'Backspace' || event.key === 'Delete') {
          fastKeyStreak = 0;
        }

        if (event.key === 'Escape') {
          searchInput.value = '';
          syncSearchClearButton();
          searchProducts('');
          return;
        }

        if (event.key !== 'Enter') {
          return;
        }

        event.preventDefault();

        const term = (searchInput.value || '').trim();
        if (term === '') {
          return;
        }

        const likelyBarcode = isLikelyBarcode(term);

        if (likelyBarcode) {
          await searchProducts(term);
        }

        let added = await addFirstResultToCart();

        if (!added) {
          await searchProducts(term);
          added = await addFirstResultToCart();
        }

        if (added && likelyBarcode) {
          showScanStatus('success', `${t('scannedAdded')}: ${term}`);
          searchInput.value = '';
          latestSearchResults = [];
          fastKeyStreak = 0;
          await searchProducts('');
        } else if (!added && likelyBarcode) {
          showScanStatus('error', `${t('noMatchingInStock')}: ${term}`);
        }
      });
    }

    document.addEventListener('keydown', async (event) => {
      if (event.key === 'F2') {
        event.preventDefault();
        if (searchInput) {
          searchInput.focus();
          searchInput.select();
        }
        return;
      }

      if (event.key === 'F9') {
        event.preventDefault();
        await checkoutCart();
        return;
      }

      if (event.defaultPrevented || event.ctrlKey || event.metaKey || event.altKey) {
        return;
      }

      if (shouldIgnoreGlobalCapture(event.target)) {
        return;
      }

      if (event.key.length === 1) {
        const now = performance.now();
        const delta = scannerLastKeyAt === 0 ? 0 : now - scannerLastKeyAt;

        if (scannerLastKeyAt !== 0 && delta > BARCODE_MAX_KEY_INTERVAL_MS) {
          scannerBuffer = '';
        }

        scannerBuffer += event.key;
        scannerLastKeyAt = now;
        scheduleScannerBufferReset();
        return;
      }

      if (event.key !== 'Enter') {
        return;
      }

      const term = scannerBuffer.trim();
      scannerBuffer = '';
      scannerLastKeyAt = 0;

      if (scannerClearTimer !== null) {
        clearTimeout(scannerClearTimer);
        scannerClearTimer = null;
      }

      if (term.length < BARCODE_MIN_LENGTH) {
        return;
      }

      event.preventDefault();
      await processGlobalScanBuffer(term);
    }, true);

    if (searchForm && searchInput) {
      searchForm.addEventListener('submit', (event) => {
        event.preventDefault();
        searchProducts((searchInput.value || '').trim());
      });
    }

    if (clearCartBtn) {
      clearCartBtn.addEventListener('click', async () => {
        await clearCart();
      });
    }

    if (clearSearchBtn && searchInput) {
      clearSearchBtn.addEventListener('click', async () => {
        searchInput.value = '';
        syncSearchClearButton();
        await searchProducts('');
        searchInput.focus();
      });
    }

    if (checkoutBtn) {
      checkoutBtn.addEventListener('click', async () => {
        await checkoutCart();
      });
    }

    if (scannerSoundToggle) {
      scannerSoundToggle.addEventListener('click', () => {
        setScannerSoundEnabled(!scannerSoundEnabled);
      });
    }

    if (themeSwitch) {
      themeSwitch.addEventListener('change', () => {
        applyTheme(themeSwitch.value);
      });
    }

    if (languageSwitch) {
      languageSwitch.addEventListener('change', () => {
        applyLanguage(languageSwitch.value);
      });
    }

    if (searchInput && shouldAutoFocusSearch()) {
      setTimeout(() => {
        searchInput.focus();
      }, 60);
    }

    if (quickSearchBtn) {
      quickSearchBtn.addEventListener('click', () => {
        if (searchInput) {
          searchInput.focus();
          searchInput.select();
        }
      });
    }

    if (quickCheckoutBtn) {
      quickCheckoutBtn.addEventListener('click', () => {
        if (checkoutBtn && !checkoutBtn.disabled) {
          checkoutBtn.focus();
          checkoutBtn.click();
        }
      });
    }

    if (moreToolsToggle && moreToolsPanel) {
      moreToolsToggle.addEventListener('click', () => {
        moreToolsPanel.classList.toggle('hidden');
        updateMoreToolsToggleLabel();
      });
    }

    loadThemePreference();
    loadLanguagePreference();
    updateMoreToolsToggleLabel();
    syncSearchClearButton();
    applyProductMode('best');
    loadScannerSoundPreference();
    loadCart();
  </script>
</body>
</html>


