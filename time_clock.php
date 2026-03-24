<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Core\Auth;
use App\Models\ShopSettings;

$currentUser = Auth::requirePageAuth(['admin', 'manager', 'cashier']);
$shopSettings = ShopSettings::get();
$shopName = (string) ($shopSettings['shop_name'] ?? 'My Shop');

if (!(bool) ($shopSettings['enable_time_clock'] ?? false)) {
    header('Location: index.php');
    exit;
}

$message = null;

// Assume a simple time_clock table: id, user_id, clock_in, clock_out, date

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $pdo = \App\Core\Database::connection();

    if ($action === 'clock_in') {
        // Check if already clocked in today
        $stmt = $pdo->prepare('SELECT id FROM time_clock WHERE user_id = :user_id AND DATE(clock_in) = CURDATE() AND clock_out IS NULL');
        $stmt->execute([':user_id' => $currentUser['id']]);
        if ($stmt->fetch()) {
            $message = 'Already clocked in today.';
        } else {
            $stmt = $pdo->prepare('INSERT INTO time_clock (user_id, clock_in) VALUES (:user_id, NOW())');
            $stmt->execute([':user_id' => $currentUser['id']]);
            $message = 'Clocked in successfully.';
        }
    } elseif ($action === 'clock_out') {
        $stmt = $pdo->prepare('UPDATE time_clock SET clock_out = NOW() WHERE user_id = :user_id AND DATE(clock_in) = CURDATE() AND clock_out IS NULL');
        $stmt->execute([':user_id' => $currentUser['id']]);
        if ($stmt->rowCount() > 0) {
            $message = 'Clocked out successfully.';
        } else {
            $message = 'Not clocked in today.';
        }
    }
}

// Get today's entry
$pdo = \App\Core\Database::connection();
$stmt = $pdo->prepare('SELECT * FROM time_clock WHERE user_id = :user_id AND DATE(clock_in) = CURDATE() ORDER BY id DESC LIMIT 1');
$stmt->execute([':user_id' => $currentUser['id']]);
$todayEntry = $stmt->fetch();

$clockedIn = $todayEntry && !$todayEntry['clock_out'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Time Clock - <?= e($shopName) ?> POS</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="min-h-screen bg-slate-900 text-white">
        <header class="border-b border-white/10">
            <div class="max-w-7xl mx-auto px-4 py-4">
                <div class="flex items-center justify-between">
                    <h1 class="text-2xl font-bold">Time Clock</h1>
                    <a href="index.php" class="text-cyan-400 hover:text-cyan-300">Back to POS</a>
                </div>
            </div>
        </header>

        <main class="max-w-7xl mx-auto px-4 py-8">
            <div class="max-w-md mx-auto">
                <?php if ($message): ?>
                    <div class="bg-green-500/20 border border-green-500/30 px-4 py-3 rounded-lg mb-4">
                        <?= e($message) ?>
                    </div>
                <?php endif; ?>

                <div class="bg-slate-800 border border-white/10 rounded-lg p-6">
                    <h2 class="text-lg font-semibold mb-4">Today's Status</h2>
                    <?php if ($clockedIn): ?>
                        <p class="text-green-400 mb-4">Clocked in at: <?= e($todayEntry['clock_in']) ?></p>
                        <form method="post">
                            <input type="hidden" name="action" value="clock_out">
                            <button type="submit" class="w-full bg-red-600 hover:bg-red-700 px-4 py-2 rounded-lg">Clock Out</button>
                        </form>
                    <?php else: ?>
                        <p class="text-slate-400 mb-4">Not clocked in yet.</p>
                        <form method="post">
                            <input type="hidden" name="action" value="clock_in">
                            <button type="submit" class="w-full bg-green-600 hover:bg-green-700 px-4 py-2 rounded-lg">Clock In</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</body>
</html>