<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Core\Auth;
use App\Services\EmailService;

$currentUser = Auth::requirePageAuth(['admin']);

$errors = [];
$success = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'test_email') {
        $testEmail = trim((string) ($_POST['test_email'] ?? ''));

        if (!filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        } else {
            $emailService = new EmailService();
            $result = $emailService->sendEmail(
                $testEmail,
                'Email Test from ' . ($_SESSION['settings']['shop_name'] ?? 'POS System'),
                '<h1>Email Test</h1><p>This is a test email to verify your SMTP configuration.</p><p>Sent at: ' . date('Y-m-d H:i:s') . '</p>'
            );

            if ($result) {
                $success = 'Test email sent successfully to ' . $testEmail;
            } else {
              $detail = $emailService->getLastError();
              if ($detail !== null && $detail !== '') {
                $errors[] = 'Failed to send test email: ' . $detail;
              } else {
                $errors[] = 'Failed to send test email. Please check your SMTP settings and server mail transport.';
              }
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Email Test</title>
  <script src="assets/vendor/tailwindcss/tailwindcss.js"></script>
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
  </style>
</head>
<body class="min-h-screen text-slate-100 antialiased">
  <main class="mx-auto max-w-2xl px-4 py-6 sm:px-6">
    <header class="mb-6 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold">Email Configuration Test</h1>
        <p class="text-sm text-slate-300">Test your email settings</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <a href="settings.php" class="utility-link">Settings</a>
        <a href="dashboard.php" class="utility-link">Dashboard</a>
      </div>
    </header>

    <?php if ($success !== null): ?>
      <div class="mb-4 rounded-xl border border-emerald-300/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-100">
        <?= e($success) ?>
      </div>
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

    <div class="rounded-3xl border border-white/10 bg-slate-900/60 p-5 sm:p-6 shadow-2xl backdrop-blur-sm">
      <h2 class="mb-4 text-lg font-semibold">Send Test Email</h2>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e((string) ($_SESSION['csrf_token'] ?? '')) ?>" />
        <input type="hidden" name="action" value="test_email" />
        <label class="block text-sm mb-3">
          <span class="mb-1 block text-slate-300">Test Email Address</span>
          <input
            type="email"
            name="test_email"
            required
            placeholder="your-email@example.com"
            class="w-full rounded-xl border border-white/15 bg-slate-950/60 px-3 py-2 outline-none focus:border-cyan-300"
          />
        </label>
        <button class="rounded-xl bg-gradient-to-r from-cyan-400 to-emerald-400 px-4 py-2 text-sm font-semibold text-slate-900">
          Send Test Email
        </button>
      </form>
    </div>
  </main>
  <script src="assets/js/y2k-global.js"></script>
  <script>
    window.NovaY2K.init();
  </script></body>
</html>