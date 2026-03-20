<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Core\Auth;
use App\Models\ShopSettings;
use Throwable;

$currentUser = Auth::requirePageAuth(['admin']);

$errors = [];
$success = null;
$settings = ShopSettings::get();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    $sessionCsrf = (string) ($_SESSION['csrf_token'] ?? '');

    if ($csrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf)) {
        $errors[] = 'Session expired. Refresh and try again.';
    }

    $payload = [
        'shop_name' => trim((string) ($_POST['shop_name'] ?? '')),
        'shop_address' => trim((string) ($_POST['shop_address'] ?? '')),
        'shop_phone' => trim((string) ($_POST['shop_phone'] ?? '')),
        'shop_tax_id' => trim((string) ($_POST['shop_tax_id'] ?? '')),
        'currency_code' => strtoupper(trim((string) ($_POST['currency_code'] ?? 'USD'))),
        'currency_symbol' => trim((string) ($_POST['currency_symbol'] ?? '$')),
        'tax_rate_percent' => (float) ($_POST['tax_rate_percent'] ?? 8),
        'receipt_header' => trim((string) ($_POST['receipt_header'] ?? '')),
        'receipt_footer' => trim((string) ($_POST['receipt_footer'] ?? '')),
        'theme_accent_primary' => strtoupper(trim((string) ($_POST['theme_accent_primary'] ?? '#06B6D4'))),
        'theme_accent_secondary' => strtoupper(trim((string) ($_POST['theme_accent_secondary'] ?? '#22D3AA'))),
    ];

    if ($payload['shop_name'] === '') {
        $errors[] = 'Shop name is required.';
    }

    if (!preg_match('/^[A-Z]{3,10}$/', $payload['currency_code'])) {
        $errors[] = 'Currency code must be 3-10 uppercase letters.';
    }

    if ($payload['currency_symbol'] === '' || strlen($payload['currency_symbol']) > 10) {
        $errors[] = 'Currency symbol is required and must be short.';
    }

    if ($payload['tax_rate_percent'] < 0 || $payload['tax_rate_percent'] > 100) {
        $errors[] = 'Tax rate must be between 0 and 100.';
    }

    if (!preg_match('/^#[0-9A-F]{6}$/', $payload['theme_accent_primary'])) {
        $errors[] = 'Primary accent must be a valid HEX color like #06B6D4.';
    }

    if (!preg_match('/^#[0-9A-F]{6}$/', $payload['theme_accent_secondary'])) {
        $errors[] = 'Secondary accent must be a valid HEX color like #22D3AA.';
    }

    if ($errors === []) {
        try {
            ShopSettings::update($payload);
            $success = 'Settings saved successfully.';
            $settings = ShopSettings::get();
        } catch (Throwable $throwable) {
            error_log('settings save failure: ' . $throwable->getMessage());
            $errors[] = 'Failed to save settings. Please try again.';
            $settings = $payload;
        }
    } else {
        $settings = $payload;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Shop Settings</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    body {
      font-family: 'Space Grotesk', sans-serif;
      background:
        radial-gradient(circle at 15% 10%, rgba(34, 211, 238, 0.2), transparent 28%),
        radial-gradient(circle at 80% 90%, rgba(251, 113, 133, 0.14), transparent 25%),
        #070b14;
    }
  </style>
</head>
<body class="min-h-screen text-slate-100 antialiased">
  <main class="mx-auto max-w-4xl px-4 py-6 sm:px-6">
    <header class="mb-6 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold">Shop Settings</h1>
        <p class="text-sm text-slate-300">Signed in as <?= e((string) $currentUser['full_name']) ?> (admin)</p>
      </div>
      <div class="flex gap-2">
        <a href="index.php" class="rounded-lg border border-white/20 px-3 py-2 text-sm hover:bg-white/10">Checkout</a>
        <a href="manage_products.php" class="rounded-lg border border-white/20 px-3 py-2 text-sm hover:bg-white/10">Manage Products</a>
        <a href="manage_users.php" class="rounded-lg border border-white/20 px-3 py-2 text-sm hover:bg-white/10">Users</a>
        <a href="audit_logs.php" class="rounded-lg border border-white/20 px-3 py-2 text-sm hover:bg-white/10">Audit</a>
        <a href="inventory_adjustments.php" class="rounded-lg border border-white/20 px-3 py-2 text-sm hover:bg-white/10">Inventory</a>
        <a href="add_product.php" class="rounded-lg border border-white/20 px-3 py-2 text-sm hover:bg-white/10">Add Product</a>
        <a href="dashboard.php" class="rounded-lg border border-white/20 px-3 py-2 text-sm hover:bg-white/10">Dashboard</a>
      </div>
    </header>

    <?php if ($success !== null): ?>
      <div class="mb-4 rounded-xl border border-emerald-300/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-100"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
      <div class="mb-4 rounded-xl border border-rose-300/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-100">
        <ul class="list-disc pl-5">
          <?php foreach ($errors as $error): ?>
            <li><?= e((string) $error) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="post" class="space-y-5 rounded-3xl border border-white/10 bg-slate-900/60 p-5 shadow-2xl backdrop-blur-sm">
      <input type="hidden" name="csrf_token" value="<?= e((string) $_SESSION['csrf_token']) ?>" />

      <section>
        <h2 class="mb-3 text-lg font-semibold">Store Identity</h2>
        <div class="grid gap-3 sm:grid-cols-2">
          <label class="text-sm">Shop Name
            <input name="shop_name" value="<?= e((string) ($settings['shop_name'] ?? '')) ?>" required class="mt-1 w-full rounded-lg border border-white/15 bg-slate-950/60 px-3 py-2" />
          </label>
          <label class="text-sm">Tax ID
            <input name="shop_tax_id" value="<?= e((string) ($settings['shop_tax_id'] ?? '')) ?>" class="mt-1 w-full rounded-lg border border-white/15 bg-slate-950/60 px-3 py-2" />
          </label>
          <label class="text-sm sm:col-span-2">Address
            <input name="shop_address" value="<?= e((string) ($settings['shop_address'] ?? '')) ?>" class="mt-1 w-full rounded-lg border border-white/15 bg-slate-950/60 px-3 py-2" />
          </label>
          <label class="text-sm">Phone
            <input name="shop_phone" value="<?= e((string) ($settings['shop_phone'] ?? '')) ?>" class="mt-1 w-full rounded-lg border border-white/15 bg-slate-950/60 px-3 py-2" />
          </label>
        </div>
      </section>

      <section>
        <h2 class="mb-3 text-lg font-semibold">Pricing & Tax</h2>
        <div class="grid gap-3 sm:grid-cols-3">
          <label class="text-sm">Currency Code
            <input name="currency_code" value="<?= e((string) ($settings['currency_code'] ?? 'USD')) ?>" required class="mt-1 w-full rounded-lg border border-white/15 bg-slate-950/60 px-3 py-2 uppercase" />
          </label>
          <label class="text-sm">Currency Symbol
            <input name="currency_symbol" value="<?= e((string) ($settings['currency_symbol'] ?? '$')) ?>" required class="mt-1 w-full rounded-lg border border-white/15 bg-slate-950/60 px-3 py-2" />
          </label>
          <label class="text-sm">Tax Rate (%)
            <input type="number" step="0.01" min="0" max="100" name="tax_rate_percent" value="<?= e((string) ($settings['tax_rate_percent'] ?? '8.00')) ?>" required class="mt-1 w-full rounded-lg border border-white/15 bg-slate-950/60 px-3 py-2" />
          </label>
        </div>
      </section>

      <section>
        <h2 class="mb-3 text-lg font-semibold">Receipt Text</h2>
        <div class="grid gap-3 sm:grid-cols-2">
          <label class="text-sm">Receipt Header
            <input name="receipt_header" value="<?= e((string) ($settings['receipt_header'] ?? '')) ?>" class="mt-1 w-full rounded-lg border border-white/15 bg-slate-950/60 px-3 py-2" />
          </label>
          <label class="text-sm">Receipt Footer
            <input name="receipt_footer" value="<?= e((string) ($settings['receipt_footer'] ?? '')) ?>" class="mt-1 w-full rounded-lg border border-white/15 bg-slate-950/60 px-3 py-2" />
          </label>
        </div>
      </section>

      <section>
        <h2 class="mb-3 text-lg font-semibold">Theme Accents</h2>
        <div class="grid gap-3 sm:grid-cols-2">
          <label class="text-sm">Primary Accent
            <input type="color" name="theme_accent_primary" value="<?= e((string) ($settings['theme_accent_primary'] ?? '#06B6D4')) ?>" class="mt-1 h-10 w-full rounded-lg border border-white/15 bg-slate-950/60 px-2 py-1" />
          </label>
          <label class="text-sm">Secondary Accent
            <input type="color" name="theme_accent_secondary" value="<?= e((string) ($settings['theme_accent_secondary'] ?? '#22D3AA')) ?>" class="mt-1 h-10 w-full rounded-lg border border-white/15 bg-slate-950/60 px-2 py-1" />
          </label>
        </div>
      </section>

      <div class="pt-2">
        <button class="rounded-xl bg-gradient-to-r from-cyan-400 to-emerald-400 px-5 py-2 font-semibold text-slate-900">Save Settings</button>
      </div>
    </form>
  </main>
</body>
</html>
