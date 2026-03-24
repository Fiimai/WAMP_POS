<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Core\Auth;
use App\Models\ShopSettings;

$currentUser = Auth::requirePageAuth(['admin', 'manager', 'cashier']);
$shopSettings = ShopSettings::get();
$shopName = (string) ($shopSettings['shop_name'] ?? 'My Shop');

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
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="min-h-screen bg-slate-900 text-white">
        <header class="border-b border-white/10">
            <div class="max-w-7xl mx-auto px-4 py-4">
                <div class="flex items-center justify-between">
                    <h1 class="text-2xl font-bold">Returns</h1>
                    <a href="index.php" class="text-cyan-400 hover:text-cyan-300">Back to POS</a>
                </div>
            </div>
        </header>

        <main class="max-w-7xl mx-auto px-4 py-8">
            <div class="max-w-md mx-auto">
                <form method="post" class="mb-8">
                    <label for="receipt_no" class="block text-sm font-medium mb-2">Receipt Number</label>
                    <input type="text" id="receipt_no" name="receipt_no" required class="w-full px-3 py-2 bg-slate-800 border border-white/20 rounded-lg">
                    <button type="submit" class="mt-4 w-full bg-cyan-600 hover:bg-cyan-700 px-4 py-2 rounded-lg">Search Receipt</button>
                </form>

                <?php if ($error): ?>
                    <div class="bg-red-500/20 border border-red-500/30 px-4 py-3 rounded-lg mb-4">
                        <?= e($error) ?>
                    </div>
                <?php endif; ?>

                <?php if ($receipt): ?>
                    <div class="bg-slate-800 border border-white/10 rounded-lg p-6">
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
</body>
</html>