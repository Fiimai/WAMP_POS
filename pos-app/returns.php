<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Core\Auth;
use App\Models\ShopSettings;

$currentUser = Auth::requirePageAuth(['admin', 'manager', 'cashier']);
$shopSettings = ShopSettings::get();
$shopName = (string) ($shopSettings['shop_name'] ?? 'My Shop');
$enableReturns = (bool) ($shopSettings['enable_returns'] ?? false);
$enableTimeClock = (bool) ($shopSettings['enable_time_clock'] ?? false);
$enableMultiStore = (bool) ($shopSettings['enable_multi_store'] ?? false);

if (!(bool) ($shopSettings['enable_returns'] ?? false)) {
    header('Location: index.php');
    exit;
}

$error = null;
$success = null;
$receipt = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $receiptNo = trim((string) ($_POST['receipt_no'] ?? ''));

    if ($receiptNo === '') {
        $error = 'Receipt number is required.';
    } else {
        // Search for sale
        $pdo = \App\Core\Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM sales WHERE receipt_no = :receipt_no');
        $stmt->execute([':receipt_no' => $receiptNo]);
        $sale = $stmt->fetch();

        if ($sale) {
            $receipt = $sale;
            // Get items
            $stmt = $pdo->prepare('SELECT si.*, p.name FROM sale_items si JOIN products p ON p.id = si.product_id WHERE si.sale_id = :sale_id');
            $stmt->execute([':sale_id' => $sale['id']]);
            $receipt['items'] = $stmt->fetchAll();
        } else {
            $error = 'Receipt not found.';
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Returns - <?= e($shopName) ?> POS</title>
    <script src="assets/vendor/tailwindcss/tailwindcss.js"></script>
    <link rel="stylesheet" href="assets/css/ambient-layer.css" />
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Montserrat', 'Lato', 'Segoe UI', 'Tahoma', 'Arial', 'sans-serif'],
                        display: ['Merriweather', 'Georgia', 'Times New Roman', 'serif']
                    }
                }
            }
        };
    </script>
    <link rel="stylesheet" href="assets/css/y2k-global.css" />
  <style>
        body {
            background:
                radial-gradient(circle at 12% 15%, rgba(6, 182, 212, 0.18), transparent 30%),
                radial-gradient(circle at 80% 8%, rgba(34, 211, 170, 0.14), transparent 26%),
                radial-gradient(circle at 84% 88%, rgba(251, 113, 133, 0.16), transparent 26%),
                #070b14;
            min-height: 100vh;
        }

        .glass {
            background: linear-gradient(145deg, rgba(255, 255, 255, 0.1), rgba(255, 255, 255, 0.04));
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            border: 1px solid rgba(255, 255, 255, 0.14);
            box-shadow: 0 10px 35px rgba(2, 6, 23, 0.45);
        }

        .utility-link {
            border-radius: 0.6rem;
            border: 1px solid rgba(148, 163, 184, 0.35);
            background: rgba(15, 23, 42, 0.5);
            color: #dbeafe;
            padding: 0.35rem 0.65rem;
            transition: background-color 170ms ease, border-color 170ms ease;
            text-decoration: none;
        }

        .utility-link:hover {
            border-color: rgba(125, 211, 252, 0.45);
            background: rgba(15, 23, 42, 0.75);
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

        .nav-icon {
            width: 0.88rem;
            height: 0.88rem;
            opacity: 0.92;
            flex-shrink: 0;
        }

        body[data-theme='light'] {
            background:
                radial-gradient(circle at 12% 15%, rgba(37, 99, 235, 0.17), transparent 30%),
                radial-gradient(circle at 80% 8%, rgba(20, 184, 166, 0.12), transparent 26%),
                radial-gradient(circle at 84% 88%, rgba(249, 115, 22, 0.14), transparent 26%),
                #e2e8f0;
            color: #0f172a;
        }

        body[data-theme='light'] .bg-slate-900,
        body[data-theme='light'] .bg-slate-900\/40 {
            background-color: rgba(248, 250, 252, 0.92) !important;
        }

        body[data-theme='light'] .text-white,
        body[data-theme='light'] .text-slate-300,
        body[data-theme='light'] .text-slate-400 {
            color: #1e293b !important;
        }

        body[data-theme='light'] .border-white\/10,
        body[data-theme='light'] .border-white\/20 {
            border-color: rgba(15, 23, 42, 0.18) !important;
        }

        body[data-theme='light'] .utility-link {
            border-color: rgba(51, 65, 85, 0.24);
            background: rgba(241, 245, 249, 0.95);
            color: #0f172a;
        }

        body[data-theme='light'] .utility-link:hover {
            border-color: rgba(59, 130, 246, 0.42);
            background: rgba(255, 255, 255, 0.95);
        }

        body[data-theme='light'] .utility-link-active {
            border-color: rgba(37, 99, 235, 0.35);
            background: rgba(59, 130, 246, 0.14);
            color: #1e3a8a;
        }

        body[data-theme='light'] .glass {
            background: linear-gradient(145deg, rgba(255, 255, 255, 0.92), rgba(248, 250, 252, 0.8));
            border-color: rgba(148, 163, 184, 0.32);
            box-shadow: 0 10px 30px rgba(148, 163, 184, 0.35);
        }

        body[data-theme='light'] .bg-slate-800 {
            background-color: rgba(248, 250, 252, 0.95) !important;
        }

        body[data-theme='light'] #themeToggle {
            color: #0f172a;
        }
    </style>
</head>
<body class="ambient-medium">
    <div class="matrix-grid" aria-hidden="true"></div>
    <div class="scanner-line" aria-hidden="true"></div>
    <div class="retro-orbs" aria-hidden="true">
        <span class="orb orb-a"></span>
        <span class="orb orb-b"></span>
    </div>
    <div class="relative z-10 min-h-screen bg-slate-900 text-white">
        <main class="max-w-7xl mx-auto px-4 py-8">
            <nav class="mb-4 flex flex-wrap items-center justify-between gap-3 text-xs text-slate-300" aria-label="Primary navigation">
                <span class="rounded-lg border border-white/10 bg-slate-900/40 px-2 py-1">Signed in as <?= e((string) $currentUser['full_name']) ?> (<?= e((string) $currentUser['role']) ?>)</span>
                <div class="flex flex-wrap items-center gap-2">
                    <a href="index.php" class="utility-link icon-link"><svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M3 3h6v6H3zm8 0h6v10h-6zM3 11h6v6H3zm8 4h6v2h-6z"/></svg><span>Checkout</span></a>
                    <a href="receipt_history.php" class="utility-link icon-link"><svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M5 2h10a1 1 0 0 1 1 1v14l-2-1-2 1-2-1-2 1-2-1-2 1V3a1 1 0 0 1 1-1zm2 4v2h6V6zm0 4v2h6v-2z"/></svg><span>Receipts</span></a>
                    <a href="dashboard.php" class="utility-link icon-link"><svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M3 3h6v6H3zm8 0h6v10h-6zM3 11h6v6H3zm8 4h6v2h-6z"/></svg><span>Dashboard</span></a>
                    <?php if ($enableMultiStore && in_array((string) $currentUser['role'], ['admin', 'manager'], true)): ?>
                        <a href="multi_store.php" class="utility-link icon-link"><svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 2 2 6v2h16V6l-8-4zm-7 8h2v6H3v-6zm4 0h2v6H7v-6zm4 0h2v6h-2v-6zm4 0h2v6h-2v-6z"/></svg><span>Stores</span></a>
                    <?php endif; ?>
                    <?php if ($enableReturns): ?>
                        <span class="utility-link utility-link-active icon-link"><svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 12l-6-6h4V2h4v4h4l-6 6z"/></svg><span>Returns</span></span>
                    <?php endif; ?>
                    <?php if ($enableTimeClock): ?>
                        <a href="time_clock.php" class="utility-link icon-link"><svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 2a8 8 0 100 16 8 8 0 000-16zm0 14a6 6 0 110-12 6 6 0 010 12zm-1-9h2v4H9V7z"/></svg><span>Time Clock</span></a>
                        <?php if (in_array((string) $currentUser['role'], ['admin', 'manager'], true)): ?>
                            <a href="time_clock_management.php" class="utility-link icon-link"><svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg><span>Time Clock Mgmt</span></a>
                        <?php endif; ?>
                    <?php endif; ?>
                    <button type="button" id="themeToggle" class="utility-link icon-link" aria-label="Toggle theme">
                        <span id="themeToggleIcon" class="inline-block w-4 text-center" aria-hidden="true">&#9790;</span>
                        <span id="themeToggleText">Dark</span>
                    </button>
                </div>
            </nav>

            <div class="mb-5">
                <h1 class="font-display text-2xl font-semibold text-white">Returns</h1>
                <p class="mt-1 text-sm text-slate-300">Find receipts quickly and review sold line items for return processing.</p>
            </div>

            <div class="max-w-md mx-auto">
                <form method="post" class="glass mb-8 rounded-2xl p-5">
                    <label for="receipt_no" class="block text-sm font-medium mb-2">Receipt Number</label>
                    <input type="text" id="receipt_no" name="receipt_no" required class="w-full rounded-lg border border-white/20 bg-slate-800 px-3 py-2.5">
                    <button type="submit" class="mt-4 w-full rounded-xl bg-cyan-600 px-4 py-2.5 font-semibold text-white transition hover:bg-cyan-700">Search Receipt</button>
                </form>

                <?php if ($error): ?>
                    <div class="bg-red-500/20 border border-red-500/30 px-4 py-3 rounded-lg mb-4">
                        <?= e($error) ?>
                    </div>
                <?php endif; ?>

                <?php if ($receipt): ?>
                    <div class="glass rounded-2xl p-6">
                        <h2 class="text-lg font-semibold mb-4">Receipt: <?= e($receipt['receipt_no']) ?></h2>
                        <p class="text-sm text-slate-300 mb-4">Sold at: <?= e($receipt['sold_at']) ?></p>
                        <div class="space-y-2 mb-4">
                            <?php foreach ($receipt['items'] as $item): ?>
                                <div class="flex justify-between">
                                    <span><?= e($item['name']) ?> x <?= e($item['qty']) ?></span>
                                    <span>$<?= number_format($item['line_total'], 2) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="border-t border-white/10 pt-4">
                            <div class="flex justify-between font-semibold">
                                <span>Total</span>
                                <span>$<?= number_format($receipt['total_amount'], 2) ?></span>
                            </div>
                        </div>
                        <p class="text-sm text-slate-400 mt-4">Return functionality not yet implemented.</p>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
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