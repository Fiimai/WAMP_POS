<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Core\Auth;
use App\Models\ShopSettings;

$currentUser = Auth::requirePageAuth(['admin', 'manager']);
$shopSettings = ShopSettings::get();
$shopName = (string) ($shopSettings['shop_name'] ?? 'My Shop');
$enableReturns = (bool) ($shopSettings['enable_returns'] ?? false);
$enableTimeClock = (bool) ($shopSettings['enable_time_clock'] ?? false);
$enableMultiStore = (bool) ($shopSettings['enable_multi_store'] ?? false);

if (!(bool) ($shopSettings['enable_time_clock'] ?? false)) {
    header('Location: index.php');
    exit;
}

// Get all time clock records for today
$pdo = \App\Core\Database::connection();
$stmt = $pdo->prepare('
    SELECT tc.*, u.username, u.full_name
    FROM time_clock tc
    JOIN users u ON u.id = tc.user_id
    WHERE DATE(tc.clock_in) = CURDATE()
    ORDER BY tc.clock_in DESC
');
$stmt->execute();
$todayRecords = $stmt->fetchAll();

// Get current clock status for all users
$stmt = $pdo->prepare('
    SELECT u.id, u.username, u.full_name,
           MAX(tc.clock_in) as last_clock_in,
           MAX(tc.clock_out) as last_clock_out
    FROM users u
    LEFT JOIN time_clock tc ON tc.user_id = u.id AND DATE(tc.clock_in) = CURDATE()
    WHERE u.is_active = 1
    GROUP BY u.id, u.username, u.full_name
    ORDER BY u.username
');
$stmt->execute();
$userStatuses = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Time Clock Management - <?= e($shopName) ?> POS</title>
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
        body[data-theme='light'] .bg-slate-900\/40,
        body[data-theme='light'] .bg-slate-700\/50 {
            background-color: rgba(248, 250, 252, 0.92) !important;
        }

        body[data-theme='light'] .text-white,
        body[data-theme='light'] .text-slate-300,
        body[data-theme='light'] .text-slate-400,
        body[data-theme='light'] .text-cyan-400,
        body[data-theme='light'] .text-yellow-400,
        body[data-theme='light'] .text-green-400,
        body[data-theme='light'] .text-red-400 {
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
                    <?php if ($enableMultiStore): ?>
                        <a href="multi_store.php" class="utility-link icon-link"><svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 2 2 6v2h16V6l-8-4zm-7 8h2v6H3v-6zm4 0h2v6H7v-6zm4 0h2v6h-2v-6zm4 0h2v6h-2v-6z"/></svg><span>Stores</span></a>
                    <?php endif; ?>
                    <?php if ($enableReturns): ?>
                        <a href="returns.php" class="utility-link icon-link"><svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 12l-6-6h4V2h4v4h4l-6 6z"/></svg><span>Returns</span></a>
                    <?php endif; ?>
                    <?php if ($enableTimeClock): ?>
                        <a href="time_clock.php" class="utility-link icon-link"><svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 2a8 8 0 100 16 8 8 0 000-16zm0 14a6 6 0 110-12 6 6 0 010 12zm-1-9h2v4H9V7z"/></svg><span>Time Clock</span></a>
                        <span class="utility-link utility-link-active icon-link"><svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg><span>Time Clock Mgmt</span></span>
                    <?php endif; ?>
                    <button type="button" id="themeToggle" class="utility-link icon-link" aria-label="Toggle theme">
                        <span id="themeToggleIcon" class="inline-block w-4 text-center" aria-hidden="true">&#9790;</span>
                        <span id="themeToggleText">Dark</span>
                    </button>
                </div>
            </nav>

            <div class="mb-5">
                <h1 class="font-display text-2xl font-semibold text-white">Time Clock Management</h1>
                <p class="mt-1 text-sm text-slate-300">Monitor employee clock-ins, active shifts, and daily durations.</p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <!-- Current Status -->
                <div class="glass rounded-2xl p-6">
                    <h2 class="font-display text-xl font-semibold mb-4">Current Status (Today)</h2>
                    <div class="space-y-3">
                        <?php foreach ($userStatuses as $user): ?>
                            <div class="flex items-center justify-between p-3 bg-slate-700/50 rounded-lg">
                                <div>
                                    <div class="font-medium"><?= e($user['full_name']) ?> (<?= e($user['username']) ?>)</div>
                                    <?php if ($user['last_clock_in'] && !$user['last_clock_out']): ?>
                                        <div class="text-green-400 text-sm">Clocked in at: <?= e($user['last_clock_in']) ?></div>
                                    <?php elseif ($user['last_clock_out']): ?>
                                        <div class="text-slate-400 text-sm">Last clocked out at: <?= e($user['last_clock_out']) ?></div>
                                    <?php else: ?>
                                        <div class="text-slate-400 text-sm">Not clocked in today</div>
                                    <?php endif; ?>
                                </div>
                                <div class="text-right">
                                    <?php if ($user['last_clock_in'] && !$user['last_clock_out']): ?>
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-500/20 text-green-400">
                                            Active
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-slate-500/20 text-slate-400">
                                            Inactive
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Today's Records -->
                <div class="glass rounded-2xl p-6">
                    <h2 class="font-display text-xl font-semibold mb-4">Today's Time Records</h2>
                    <?php if (empty($todayRecords)): ?>
                        <p class="text-slate-400">No time records for today yet.</p>
                    <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($todayRecords as $record): ?>
                                <div class="p-3 bg-slate-700/50 rounded-lg">
                                    <div class="flex items-center justify-between mb-2">
                                        <div class="font-medium"><?= e($record['full_name']) ?> (<?= e($record['username']) ?>)</div>
                                        <div class="text-sm text-slate-400">
                                            <?= e(date('M j, Y', strtotime($record['clock_in']))) ?>
                                        </div>
                                    </div>
                                    <div class="grid grid-cols-2 gap-4 text-sm">
                                        <div>
                                            <span class="text-slate-400">Clock In:</span>
                                            <span class="text-green-400"><?= e(date('g:i A', strtotime($record['clock_in']))) ?></span>
                                        </div>
                                        <div>
                                            <span class="text-slate-400">Clock Out:</span>
                                            <?php if ($record['clock_out']): ?>
                                                <span class="text-red-400"><?= e(date('g:i A', strtotime($record['clock_out']))) ?></span>
                                            <?php else: ?>
                                                <span class="text-yellow-400">Active</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php if ($record['clock_in'] && $record['clock_out']): ?>
                                        <div class="mt-2 text-sm">
                                            <span class="text-slate-400">Duration:</span>
                                            <span class="text-cyan-400">
                                                <?php
                                                $duration = strtotime($record['clock_out']) - strtotime($record['clock_in']);
                                                $hours = floor($duration / 3600);
                                                $minutes = floor(($duration % 3600) / 60);
                                                echo "{$hours}h {$minutes}m";
                                                ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
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