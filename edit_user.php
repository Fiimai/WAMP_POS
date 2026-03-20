<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\RateLimiter;
use App\Repositories\AuditLogRepository;
use App\Repositories\UserRepository;
use App\Services\UserAuthService;
use App\Services\UserManagementService;
use InvalidArgumentException;

$currentUser = Auth::requirePageAuth(['admin']);
Auth::requireCapability('users.manage');
$service = new UserManagementService(new UserRepository());
$auditRepo = new AuditLogRepository();

$userId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$user = $service->getUser($userId);
if ($user === null) {
    http_response_code(404);
    exit('User not found');
}

$errors = [];
$success = null;
$form = [
    'id' => (string) $userId,
    'full_name' => (string) $user['full_name'],
    'username' => (string) $user['username'],
    'email' => (string) ($user['email'] ?? ''),
    'role' => (string) $user['role'],
    'is_active' => (int) $user['is_active'] === 1 ? '1' : '0',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $limit = RateLimiter::hit('admin:edit_user:' . (int) $currentUser['id'] . ':' . Auth::clientIp(), 80, 60);
    if (!$limit['allowed']) {
        $errors[] = 'Too many requests. Try again in ' . $limit['retry_after'] . ' seconds.';
    }

    $csrf = (string) ($_POST['csrf_token'] ?? '');
    $sessionCsrf = (string) ($_SESSION['csrf_token'] ?? '');
    if ($csrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf)) {
        $errors[] = 'Session expired. Refresh and try again.';
    }

    $mode = (string) ($_POST['mode'] ?? 'profile');

    if ($mode === 'profile') {
        $form = [
            'id' => (string) $userId,
            'full_name' => trim((string) ($_POST['full_name'] ?? '')),
            'username' => trim((string) ($_POST['username'] ?? '')),
            'email' => trim((string) ($_POST['email'] ?? '')),
            'role' => trim((string) ($_POST['role'] ?? 'cashier')),
            'is_active' => ((string) ($_POST['is_active'] ?? '1')) === '1' ? '1' : '0',
        ];
    }

    if ($errors === []) {
        try {
            if ($mode === 'profile') {
                if ($userId === (int) $currentUser['id'] && $form['is_active'] !== '1') {
                    throw new InvalidArgumentException('You cannot deactivate your own account.');
                }

          $criticalChange = ($form['role'] !== (string) $user['role'])
            || ($form['is_active'] !== ((int) $user['is_active'] === 1 ? '1' : '0'));

          if ($criticalChange) {
            $approvalPassword = (string) ($_POST['admin_password'] ?? '');
            $approver = (new UserAuthService())->login((string) $currentUser['username'], $approvalPassword);
            if ($approver === false) {
              throw new InvalidArgumentException('Critical change approval failed. Enter your current admin password.');
            }
          }

                $service->updateUser(
                    $userId,
                    $form['full_name'],
                    $form['username'],
                    $form['email'],
                    $form['role'],
                    $form['is_active'] === '1'
                );
                $auditRepo->record(
                  (int) $currentUser['id'],
                  'user.updated',
                  'user',
                  $userId,
                  [
                    'role' => $form['role'],
                    'is_active' => $form['is_active'] === '1',
                  ],
                  Auth::clientIp(),
                  (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
                );
                $success = 'User profile updated.';
            } elseif ($mode === 'password') {
                $newPassword = (string) ($_POST['new_password'] ?? '');
                $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
              $approvalPassword = (string) ($_POST['admin_password'] ?? '');
              $approver = (new UserAuthService())->login((string) $currentUser['username'], $approvalPassword);
              if ($approver === false) {
                throw new InvalidArgumentException('Password reset approval failed. Enter your current admin password.');
              }
                $service->resetPassword($userId, $newPassword, $confirmPassword);
                $auditRepo->record(
                  (int) $currentUser['id'],
                  'user.password_reset',
                  'user',
                  $userId,
                  null,
                  Auth::clientIp(),
                  (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
                );
                $success = 'Password reset successfully.';
            }

            $user = $service->getUser($userId);
            if ($user !== null) {
                $form = [
                    'id' => (string) $userId,
                    'full_name' => (string) $user['full_name'],
                    'username' => (string) $user['username'],
                    'email' => (string) ($user['email'] ?? ''),
                    'role' => (string) $user['role'],
                    'is_active' => (int) $user['is_active'] === 1 ? '1' : '0',
                ];
            }
        } catch (Throwable $throwable) {
            error_log('edit user failure: ' . $throwable->getMessage());
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
  <title>Edit User</title>
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
        <h1 class="text-2xl font-semibold">Edit User</h1>
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

    <section class="grid gap-4 lg:grid-cols-2">
      <form method="post" class="space-y-4 rounded-3xl border border-white/10 bg-slate-900/60 p-5 shadow-2xl backdrop-blur-sm">
        <input type="hidden" name="csrf_token" value="<?= e((string) $_SESSION['csrf_token']) ?>" />
        <input type="hidden" name="id" value="<?= e($form['id']) ?>" />
        <input type="hidden" name="mode" value="profile" />

        <h2 class="text-lg font-semibold">Profile & Access</h2>

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

        <div class="pt-2">
          <button class="rounded-xl bg-gradient-to-r from-cyan-400 to-emerald-400 px-5 py-2 font-semibold text-slate-900">Save Profile</button>
        </div>

        <label class="block text-sm">
          <span class="mb-1 block text-slate-300">Admin Password (required for role/status changes)</span>
          <input type="password" name="admin_password" class="w-full rounded-xl border border-white/15 bg-slate-950/60 px-3 py-2 outline-none focus:border-cyan-300" />
        </label>
      </form>

      <form method="post" class="space-y-4 rounded-3xl border border-white/10 bg-slate-900/60 p-5 shadow-2xl backdrop-blur-sm">
        <input type="hidden" name="csrf_token" value="<?= e((string) $_SESSION['csrf_token']) ?>" />
        <input type="hidden" name="id" value="<?= e($form['id']) ?>" />
        <input type="hidden" name="mode" value="password" />

        <h2 class="text-lg font-semibold">Reset Password</h2>
        <p class="text-xs text-slate-300">Set a new password (minimum 8 characters).</p>

        <label class="block text-sm">
          <span class="mb-1 block text-slate-300">New Password</span>
          <input type="password" name="new_password" required minlength="8" class="w-full rounded-xl border border-white/15 bg-slate-950/60 px-3 py-2 outline-none focus:border-cyan-300" />
        </label>

        <label class="block text-sm">
          <span class="mb-1 block text-slate-300">Confirm Password</span>
          <input type="password" name="confirm_password" required minlength="8" class="w-full rounded-xl border border-white/15 bg-slate-950/60 px-3 py-2 outline-none focus:border-cyan-300" />
        </label>

        <label class="block text-sm">
          <span class="mb-1 block text-slate-300">Admin Password (required)</span>
          <input type="password" name="admin_password" required class="w-full rounded-xl border border-white/15 bg-slate-950/60 px-3 py-2 outline-none focus:border-cyan-300" />
        </label>

        <div class="pt-2">
          <button class="rounded-xl border border-cyan-300/45 bg-cyan-500/10 px-5 py-2 font-semibold text-cyan-100 hover:bg-cyan-500/20">Reset Password</button>
        </div>
      </form>
    </section>
  </main>
</body>
</html>
