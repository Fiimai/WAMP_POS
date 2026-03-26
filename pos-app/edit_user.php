<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\RateLimiter;
use App\Repositories\AuditLogRepository;
use App\Repositories\UserRepository;
use App\Services\UserAuthService;
use App\Services\UserManagementService;

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
  <script src="assets/vendor/tailwindcss/tailwindcss.js"></script>
  <link rel="stylesheet" href="assets/css/ambient-layer.css" />
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

    body[data-theme='light'] .border-white\/10,
    body[data-theme='light'] .border-white\/15 {
      border-color: rgba(15, 23, 42, 0.16) !important;
    }

    body[data-theme='light'] .utility-link {
      border-color: rgba(51, 65, 85, 0.24);
      background: rgba(241, 245, 249, 0.95);
      color: #0f172a;
    }

    body[data-theme='light'] .utility-link:hover {
      border-color: rgba(59, 130, 246, 0.45);
      background: rgba(255, 255, 255, 0.95);
    }

    body[data-theme='light'] .text-rose-100 {
      color: #b91c1c !important;
    }

    body[data-theme='light'] .bg-rose-500\/10 {
      background-color: rgba(254, 226, 226, 0.68) !important;
    }

    body[data-theme='light'] .text-emerald-100,
    body[data-theme='light'] .text-emerald-200 {
      color: #047857 !important;
    }

    body[data-theme='light'] .bg-emerald-500\/10 {
      background-color: rgba(209, 250, 229, 0.68) !important;
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
      outline: 2px solid rgba(125, 211, 252, 0.8);
      outline-offset: 1px;
    }

    body[data-theme='light'] .skip-link {
      border-color: rgba(15, 23, 42, 0.25);
      background: rgba(255, 255, 255, 0.95);
      color: #0f172a;
    }

    .utility-link {
      border-radius: 0.6rem;
      border: 1px solid rgba(148, 163, 184, 0.35);
      background: rgba(15, 23, 42, 0.5);
      color: #dbeafe;
      padding: 0.45rem 0.75rem;
      font-size: 0.84rem;
      font-weight: 600;
      transition: background-color 170ms ease, border-color 170ms ease;
    }

    .utility-link:hover {
      border-color: rgba(125, 211, 252, 0.45);
      background: rgba(15, 23, 42, 0.75);
    }

    .utility-link:focus-visible,
    a:focus-visible,
    button:focus-visible,
    input:focus-visible,
    select:focus-visible {
      outline: 2px solid rgba(125, 211, 252, 0.8);
      outline-offset: 2px;
    }
  </style>
</head>
<body class="ambient-soft min-h-screen text-slate-100 antialiased">
  <a href="#mainContent" class="skip-link">Skip to edit user content</a>
  <div class="matrix-grid" aria-hidden="true"></div>
  <div class="scanner-line" aria-hidden="true"></div>
  <div class="retro-orbs" aria-hidden="true">
    <span class="orb orb-a"></span>
    <span class="orb orb-b"></span>
  </div>
  <main id="mainContent" class="relative z-10 mx-auto max-w-4xl px-4 py-6 sm:px-6">
    <header class="mb-6 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold">Edit User</h1>
        <p class="text-sm text-slate-300">Signed in as <?= e((string) $currentUser['full_name']) ?> (admin)</p>
      </div>
      <div class="flex flex-wrap gap-2" aria-label="Edit user navigation">
        <a href="manage_users.php" class="utility-link">Manage Users</a>
        <a href="dashboard.php" class="utility-link">Dashboard</a>
        <a href="settings.php" class="utility-link">Settings</a>
        <a href="index.php" class="utility-link">Checkout</a>
        <button type="button" id="themeToggle" class="utility-link inline-flex items-center gap-1.5" aria-label="Toggle theme">
          <span id="themeToggleIcon" class="inline-block w-4 text-center" aria-hidden="true">&#9790;</span>
          <span id="themeToggleText">Dark</span>
        </button>
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
      <form method="post" class="space-y-4 rounded-3xl border border-white/10 bg-slate-900/60 p-5 sm:p-6 shadow-2xl backdrop-blur-sm">
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
          <button class="min-h-[42px] rounded-xl bg-gradient-to-r from-cyan-400 to-emerald-400 px-4 py-2 text-sm font-semibold text-slate-900">Save Profile</button>
        </div>

        <label class="block text-sm">
          <span class="mb-1 block text-slate-300">Admin Password (required for role/status changes)</span>
          <input type="password" name="admin_password" class="w-full rounded-xl border border-white/15 bg-slate-950/60 px-3 py-2 outline-none focus:border-cyan-300" />
        </label>
      </form>

      <form method="post" class="space-y-4 rounded-3xl border border-white/10 bg-slate-900/60 p-5 sm:p-6 shadow-2xl backdrop-blur-sm">
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
          <button class="min-h-[42px] rounded-xl border border-cyan-300/45 bg-cyan-500/10 px-4 py-2 text-sm font-semibold text-cyan-100 hover:bg-cyan-500/20">Reset Password</button>
        </div>
      </form>
    </section>
  </main>
  <script src="assets/js/ambient-layer.js"></script>
  <script>
    window.NovaAmbient.init({ pauseAfterMs: 5000 });

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


