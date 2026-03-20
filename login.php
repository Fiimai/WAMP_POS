<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\RateLimiter;
use App\Models\ShopSettings;
use App\Repositories\AuditLogRepository;
use App\Services\UserAuthService;

if (Auth::check()) {
    header('Location: index.php');
    exit;
}

$error = null;
$username = trim((string) ($_POST['username'] ?? ''));
$shopSettings = ShopSettings::get();
$shopName = (string) ($shopSettings['shop_name'] ?? 'Khanun');
$shopBrand = strtoupper($shopName) . ' POS';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $ip = Auth::clientIp();
    $auditRepo = new AuditLogRepository();
    $normalizedUsername = strtolower($username !== '' ? $username : '*');
    $loginBucket = 'login:' . $ip . ':' . $normalizedUsername;
    $limit = RateLimiter::hit($loginBucket, 8, 900);

    if (!$limit['allowed']) {
        $error = 'Too many login attempts. Try again in ' . $limit['retry_after'] . ' seconds.';

        try {
            $auditRepo->record(
                null,
                'login.rate_limited',
                'user',
                null,
                ['identity' => $username, 'retry_after' => (int) $limit['retry_after']],
                $ip,
                (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
            );
        } catch (Throwable $throwable) {
            error_log('audit log failure login.rate_limited: ' . $throwable->getMessage());
        }
    }

    $csrf = (string) ($_POST['csrf_token'] ?? '');
    $sessionCsrf = (string) ($_SESSION['csrf_token'] ?? '');

    if ($error === null && ($csrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf))) {
        $error = 'Session expired. Refresh and try again.';
    } elseif ($error === null) {
        $password = (string) ($_POST['password'] ?? '');
        $authService = new UserAuthService();
        $user = $authService->login($username, $password);

        if ($user !== false) {
            try {
                $auditRepo->record(
                    (int) $user['id'],
                    'login.success',
                    'user',
                    (int) $user['id'],
                    ['username' => (string) $user['username']],
                    $ip,
                    (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
                );
            } catch (Throwable $throwable) {
                error_log('audit log failure login.success: ' . $throwable->getMessage());
            }

            RateLimiter::clear($loginBucket);
            RateLimiter::clear('login:' . $ip . ':*');
            Auth::login($user);
            header('Location: index.php');
            exit;
        }

        try {
            $auditRepo->record(
                null,
                'login.failed',
                'user',
                null,
                ['identity' => $username],
                $ip,
                (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
            );
        } catch (Throwable $throwable) {
            error_log('audit log failure login.failed: ' . $throwable->getMessage());
        }

        $error = 'Invalid username or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= e($shopBrand) ?> Login</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Sora:wght@500;600;700&display=swap" rel="stylesheet" />
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    :root {
      --ink: #14213d;
      --paper: #f6f5f1;
      --salmon: #ff9b71;
      --mint: #8ad7c1;
      --blue: #4f7cac;
      --line: rgba(20, 33, 61, 0.14);
    }

    body {
      font-family: 'Manrope', sans-serif;
      background:
        radial-gradient(circle at 8% 15%, rgba(138, 215, 193, 0.6), transparent 28%),
        radial-gradient(circle at 85% 82%, rgba(255, 155, 113, 0.5), transparent 32%),
        linear-gradient(140deg, #eef6f3 0%, #f9efe9 58%, #f5f1ea 100%);
      color: var(--ink);
    }

    .headline-font {
      font-family: 'Sora', sans-serif;
    }

    .hero-stripes {
      background-image:
        linear-gradient(30deg, rgba(79, 124, 172, 0.12) 12%, transparent 12.5%, transparent 87%, rgba(79, 124, 172, 0.12) 87.5%),
        linear-gradient(150deg, rgba(255, 155, 113, 0.12) 12%, transparent 12.5%, transparent 87%, rgba(255, 155, 113, 0.12) 87.5%);
      background-size: 20px 35px;
    }

    .float-in {
      animation: floatIn 0.75s cubic-bezier(0.2, 0.7, 0.25, 1) both;
    }

    .stagger-1 {
      animation-delay: 0.05s;
    }

    .stagger-2 {
      animation-delay: 0.13s;
    }

    .stagger-3 {
      animation-delay: 0.2s;
    }

    .stagger-4 {
      animation-delay: 0.28s;
    }

    @keyframes floatIn {
      from {
        opacity: 0;
        transform: translateY(16px) scale(0.98);
      }

      to {
        opacity: 1;
        transform: translateY(0) scale(1);
      }
    }
  </style>
</head>
<body class="min-h-screen antialiased">
  <main class="mx-auto flex min-h-screen w-full max-w-6xl items-center px-4 py-8 sm:px-6 lg:px-8">
    <section class="float-in grid w-full overflow-hidden rounded-[2rem] border border-[color:var(--line)] bg-[color:var(--paper)] shadow-[0_28px_70px_-35px_rgba(20,33,61,0.42)] lg:grid-cols-2">
      <div class="hero-stripes relative hidden overflow-hidden p-10 lg:flex lg:flex-col">
        <div class="absolute -left-12 -top-12 h-40 w-40 rounded-full bg-[color:var(--mint)]/45 blur-2xl"></div>
        <div class="absolute -bottom-14 right-4 h-44 w-44 rounded-full bg-[color:var(--salmon)]/35 blur-2xl"></div>

        <div class="relative z-10">
          <p class="float-in stagger-1 inline-flex items-center rounded-full border border-[color:var(--line)] bg-white/65 px-3 py-1 text-xs font-semibold uppercase tracking-[0.22em] text-[color:var(--blue)]"><?= e($shopBrand) ?></p>
          <h1 class="headline-font float-in stagger-2 mt-6 text-4xl font-semibold leading-tight text-[color:var(--ink)]">Welcome Back,<br />Cashier</h1>
          <p class="float-in stagger-3 mt-4 max-w-sm text-sm leading-relaxed text-[color:var(--ink)]/80">Sign in to open your terminal, process sales faster, and keep your inventory perfectly synced.</p>
        </div>

        <div class="relative z-10 mt-auto space-y-3 rounded-2xl border border-[color:var(--line)] bg-white/60 p-4 backdrop-blur-sm">
          <div class="float-in stagger-2 flex items-center gap-3 text-sm text-[color:var(--ink)]/80">
            <span class="inline-block h-2.5 w-2.5 rounded-full bg-emerald-500"></span>
            Inventory visibility in real time
          </div>
          <div class="float-in stagger-3 flex items-center gap-3 text-sm text-[color:var(--ink)]/80">
            <span class="inline-block h-2.5 w-2.5 rounded-full bg-amber-500"></span>
            Secure role-based access control
          </div>
          <div class="float-in stagger-4 flex items-center gap-3 text-sm text-[color:var(--ink)]/80">
            <span class="inline-block h-2.5 w-2.5 rounded-full bg-sky-500"></span>
            Smooth checkout flow for busy hours
          </div>
        </div>
      </div>

      <div class="p-6 sm:p-10">
        <div class="mx-auto max-w-md">
          <div class="float-in stagger-1 mb-8 lg:mb-10">
            <p class="text-xs font-semibold uppercase tracking-[0.22em] text-[color:var(--blue)] lg:hidden"><?= e($shopBrand) ?></p>
            <h2 class="headline-font mt-2 text-3xl font-semibold text-[color:var(--ink)]">Sign In</h2>
            <p class="mt-2 text-sm text-[color:var(--ink)]/70">Enter your credentials to continue to your POS dashboard.</p>
          </div>

          <?php if ($error !== null): ?>
            <div class="float-in stagger-2 mb-5 rounded-2xl border border-rose-300/70 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-700"><?= e($error) ?></div>
          <?php endif; ?>

          <form method="post" class="space-y-5">
            <input type="hidden" name="csrf_token" value="<?= e((string) $_SESSION['csrf_token']) ?>" />

            <label class="float-in stagger-2 block text-sm">
              <span class="mb-2 block font-semibold text-[color:var(--ink)]/90">Username</span>
              <input
                name="username"
                value="<?= e($username) ?>"
                required
                autofocus
                autocomplete="username"
                class="w-full rounded-2xl border border-[color:var(--line)] bg-white px-4 py-3 text-[color:var(--ink)] outline-none transition focus:border-[color:var(--blue)] focus:ring-4 focus:ring-sky-200/45"
              />
            </label>

            <label class="float-in stagger-3 block text-sm">
              <span class="mb-2 block font-semibold text-[color:var(--ink)]/90">Password</span>
              <div class="relative">
                <input
                  id="passwordInput"
                  type="password"
                  name="password"
                  required
                  autocomplete="current-password"
                  class="w-full rounded-2xl border border-[color:var(--line)] bg-white px-4 py-3 pr-14 text-[color:var(--ink)] outline-none transition focus:border-[color:var(--blue)] focus:ring-4 focus:ring-sky-200/45"
                />
                <button
                  type="button"
                  id="togglePassword"
                  class="absolute right-2 top-1/2 -translate-y-1/2 rounded-xl px-3 py-1.5 text-xs font-semibold uppercase tracking-[0.12em] text-[color:var(--blue)] transition hover:bg-slate-100"
                >Show</button>
              </div>
            </label>

            <button class="float-in stagger-4 w-full rounded-2xl bg-[color:var(--ink)] px-4 py-3 text-sm font-bold uppercase tracking-[0.12em] text-[color:var(--paper)] transition hover:bg-slate-700">Enter Terminal</button>
          </form>
        </div>
      </div>
    </section>
  </main>

  <script>
    (function () {
      const passwordInput = document.getElementById('passwordInput');
      const toggle = document.getElementById('togglePassword');

      if (!passwordInput || !toggle) {
        return;
      }

      toggle.addEventListener('click', function () {
        const showing = passwordInput.type === 'text';
        passwordInput.type = showing ? 'password' : 'text';
        toggle.textContent = showing ? 'Show' : 'Hide';
      });
    })();
  </script>
</body>
</html>
