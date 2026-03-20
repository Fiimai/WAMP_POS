<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\RateLimiter;
use App\Repositories\AuditLogRepository;
use App\Repositories\UserRepository;
use App\Services\UserManagementService;

$currentUser = Auth::requirePageAuth(['admin']);
Auth::requireCapability('users.manage');
$service = new UserManagementService(new UserRepository());
$auditRepo = new AuditLogRepository();

$errors = [];
$success = null;

$form = [
    'full_name' => '',
    'username' => '',
    'email' => '',
    'role' => 'cashier',
    'is_active' => '1',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $limit = RateLimiter::hit('admin:add_user:' . (int) $currentUser['id'] . ':' . Auth::clientIp(), 60, 60);
    if (!$limit['allowed']) {
        $errors[] = 'Too many requests. Try again in ' . $limit['retry_after'] . ' seconds.';
    }

    $csrf = (string) ($_POST['csrf_token'] ?? '');
    $sessionCsrf = (string) ($_SESSION['csrf_token'] ?? '');
    if ($csrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf)) {
        $errors[] = 'Session expired. Refresh and try again.';
    }

    $form = [
        'full_name' => trim((string) ($_POST['full_name'] ?? '')),
        'username' => trim((string) ($_POST['username'] ?? '')),
        'email' => trim((string) ($_POST['email'] ?? '')),
        'role' => trim((string) ($_POST['role'] ?? 'cashier')),
        'is_active' => ((string) ($_POST['is_active'] ?? '1')) === '1' ? '1' : '0',
    ];

    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if ($errors === []) {
        try {
            $newId = $service->createUser(
                $form['full_name'],
                $form['username'],
                $form['email'],
                $password,
                $confirmPassword,
                $form['role'],
                $form['is_active'] === '1'
            );
            $auditRepo->record(
              (int) $currentUser['id'],
              'user.created',
              'user',
              $newId,
              [
                'role' => $form['role'],
                'is_active' => $form['is_active'] === '1',
              ],
              Auth::clientIp(),
              (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
            );
            $success = 'User created successfully (ID ' . $newId . ').';
            $form = [
                'full_name' => '',
                'username' => '',
                'email' => '',
                'role' => 'cashier',
                'is_active' => '1',
            ];
        } catch (Throwable $throwable) {
            error_log('add user failure: ' . $throwable->getMessage());
            $errors[] = $throwable->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Add User</title>
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
  <main class="mx-auto max-w-3xl px-4 py-6 sm:px-6">
    <header class="mb-6 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold">Add User</h1>
        <p class="text-sm text-slate-300">Signed in as <?= e((string) $currentUser['full_name']) ?> (admin)</p>
      </div>
      <div class="flex gap-2">
        <a href="manage_users.php" class="rounded-lg border border-white/20 px-3 py-2 text-sm hover:bg-white/10">Manage Users</a>
        <a href="index.php" class="rounded-lg border border-white/20 px-3 py-2 text-sm hover:bg-white/10">Checkout</a>
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

    <form method="post" class="space-y-4 rounded-3xl border border-white/10 bg-slate-900/60 p-5 shadow-2xl backdrop-blur-sm">
      <input type="hidden" name="csrf_token" value="<?= e((string) $_SESSION['csrf_token']) ?>" />

      <label class="block text-sm">
        <span class="mb-1 block text-slate-300">Full Name</span>
        <input name="full_name" value="<?= e($form['full_name']) ?>" required class="w-full rounded-xl border border-white/15 bg-slate-950/60 px-3 py-2 outline-none focus:border-cyan-300" />
      </label>

      <div class="grid gap-3 sm:grid-cols-2">
        <label class="block text-sm">
          <span class="mb-1 block text-slate-300">Username</span>
          <input name="username" value="<?= e($form['username']) ?>" required class="w-full rounded-xl border border-white/15 bg-slate-950/60 px-3 py-2 outline-none focus:border-cyan-300" />
        </label>
        <label class="block text-sm">
          <span class="mb-1 block text-slate-300">Email (optional)</span>
          <input type="email" name="email" value="<?= e($form['email']) ?>" class="w-full rounded-xl border border-white/15 bg-slate-950/60 px-3 py-2 outline-none focus:border-cyan-300" />
        </label>
      </div>

      <div class="grid gap-3 sm:grid-cols-2">
        <label class="block text-sm">
          <span class="mb-1 block text-slate-300">Role</span>
          <select name="role" class="w-full rounded-xl border border-white/15 bg-slate-950/60 px-3 py-2 outline-none focus:border-cyan-300">
            <?php foreach (['admin', 'manager', 'cashier'] as $role): ?>
              <option value="<?= e($role) ?>" <?= $form['role'] === $role ? 'selected' : '' ?>><?= e(ucfirst($role)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="block text-sm">
          <span class="mb-1 block text-slate-300">Status</span>
          <select name="is_active" class="w-full rounded-xl border border-white/15 bg-slate-950/60 px-3 py-2 outline-none focus:border-cyan-300">
            <option value="1" <?= $form['is_active'] === '1' ? 'selected' : '' ?>>Active</option>
            <option value="0" <?= $form['is_active'] === '0' ? 'selected' : '' ?>>Inactive</option>
          </select>
        </label>
      </div>

      <div class="grid gap-3 sm:grid-cols-2">
        <label class="block text-sm">
          <span class="mb-1 block text-slate-300">Password</span>
          <input type="password" name="password" required minlength="8" class="w-full rounded-xl border border-white/15 bg-slate-950/60 px-3 py-2 outline-none focus:border-cyan-300" />
        </label>
        <label class="block text-sm">
          <span class="mb-1 block text-slate-300">Confirm Password</span>
          <input type="password" name="confirm_password" required minlength="8" class="w-full rounded-xl border border-white/15 bg-slate-950/60 px-3 py-2 outline-none focus:border-cyan-300" />
        </label>
      </div>

      <div class="pt-2">
        <button class="rounded-xl bg-gradient-to-r from-cyan-400 to-emerald-400 px-5 py-2 font-semibold text-slate-900">Create User</button>
      </div>
    </form>
  </main>
</body>
</html>
