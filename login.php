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
$shopName = (string) ($shopSettings['shop_name'] ?? 'My Shop');
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
  <script src="assets/vendor/tailwindcss/tailwindcss.js"></script>
  <style>
    :root {
      --ink: #3d4468;
      --paper: #e0e5ec;
      --salmon: #ff6b35;
      --mint: #00c896;
      --blue: #6c7293;
      --line: rgba(61, 68, 104, 0.2);
      --neu-dark: #bec3cf;
      --neu-light: #ffffff;
      --hero-card: rgba(255, 255, 255, 0.62);
      --hero-pill: rgba(255, 255, 255, 0.68);
    }

    body {
      font-family: 'Montserrat', 'Lato', 'Segoe UI', Tahoma, Arial, sans-serif;
      background: #e0e5ec;
      color: var(--ink);
      overflow-x: hidden;
    }

    body[data-theme='dark'] {
      --ink: #e2e8f0;
      --paper: #0f172a;
      --line: rgba(226, 232, 240, 0.2);
      --blue: #7dd3fc;
      --neu-dark: #0a1222;
      --neu-light: #172742;
      --hero-card: rgba(8, 20, 38, 0.74);
      --hero-pill: rgba(11, 27, 50, 0.72);
      background:
        radial-gradient(circle at 8% 15%, rgba(56, 189, 248, 0.24), transparent 28%),
        radial-gradient(circle at 85% 82%, rgba(129, 140, 248, 0.18), transparent 34%),
        linear-gradient(140deg, #020617 0%, #0b1326 58%, #111827 100%);
    }

    .matrix-grid {
      position: fixed;
      inset: 0;
      pointer-events: none;
      z-index: 0;
      background-image: radial-gradient(circle, rgba(108, 114, 147, 0.26) 1px, transparent 1.2px);
      background-size: 24px 24px;
      opacity: 0.4;
      animation: matrixDrift 14s linear infinite;
    }

    body[data-theme='dark'] .matrix-grid {
      background-image: radial-gradient(circle, rgba(125, 211, 252, 0.24) 1px, transparent 1.2px);
      opacity: 0.36;
    }

    .scanner-line {
      position: fixed;
      left: -20%;
      width: 140%;
      height: 2px;
      pointer-events: none;
      z-index: 1;
      opacity: 0.55;
      background: linear-gradient(90deg, transparent, rgba(34, 211, 238, 0.9), transparent);
      box-shadow: 0 0 16px rgba(34, 211, 238, 0.65);
      animation: scannerSweep 9s linear infinite;
    }

    .scanner-line.scanner-b {
      height: 1px;
      opacity: 0.35;
      animation-duration: 12s;
      animation-delay: -4.3s;
      background: linear-gradient(90deg, transparent, rgba(255, 107, 53, 0.8), transparent);
      box-shadow: 0 0 14px rgba(255, 107, 53, 0.45);
    }

    .retro-orbs {
      position: fixed;
      inset: 0;
      pointer-events: none;
      z-index: 1;
      overflow: hidden;
    }

    .orb {
      position: absolute;
      border-radius: 999px;
      filter: blur(1px);
      opacity: 0.46;
      background: radial-gradient(circle at 30% 30%, rgba(255, 255, 255, 0.9), rgba(108, 114, 147, 0.24) 45%, transparent 72%);
      animation: orbFloat 18s ease-in-out infinite;
    }

    .orb.orb-a {
      width: 220px;
      height: 220px;
      left: -60px;
      top: 12%;
      animation-delay: -2s;
    }

    .orb.orb-b {
      width: 170px;
      height: 170px;
      right: 8%;
      top: 18%;
      animation-duration: 21s;
      animation-delay: -7s;
      background: radial-gradient(circle at 35% 35%, rgba(255, 255, 255, 0.82), rgba(255, 107, 53, 0.28) 48%, transparent 72%);
    }

    .orb.orb-c {
      width: 260px;
      height: 260px;
      right: -90px;
      bottom: 8%;
      animation-duration: 24s;
      background: radial-gradient(circle at 35% 35%, rgba(255, 255, 255, 0.85), rgba(0, 200, 150, 0.28) 45%, transparent 75%);
    }

    body[data-theme='dark'] .orb {
      opacity: 0.38;
      background: radial-gradient(circle at 30% 30%, rgba(224, 231, 255, 0.75), rgba(125, 211, 252, 0.24) 42%, transparent 75%);
    }

    body[data-theme='dark'] .hero-stripes {
      background-image:
        linear-gradient(30deg, rgba(125, 211, 252, 0.12) 12%, transparent 12.5%, transparent 87%, rgba(125, 211, 252, 0.12) 87.5%),
        linear-gradient(150deg, rgba(129, 140, 248, 0.14) 12%, transparent 12.5%, transparent 87%, rgba(129, 140, 248, 0.14) 87.5%);
    }

    .headline-font {
      font-family: 'Merriweather', Georgia, 'Times New Roman', serif;
    }

    .neon-pulse {
      animation: neonPulse 2s ease-in-out infinite;
    }

    .hero-stripes {
      background-image:
        linear-gradient(30deg, rgba(59, 130, 246, 0.18) 12%, transparent 12.5%, transparent 87%, rgba(59, 130, 246, 0.18) 87.5%),
        linear-gradient(150deg, rgba(255, 107, 53, 0.16) 12%, transparent 12.5%, transparent 87%, rgba(255, 107, 53, 0.16) 87.5%);
      background-size: 20px 35px;
    }

    .login-form-panel {
      background: var(--paper);
      box-shadow:
        inset 10px 10px 22px var(--neu-dark),
        inset -10px -10px 22px var(--neu-light);
    }

    body[data-theme='dark'] .login-form-panel {
      background: var(--paper);
      box-shadow:
        inset 8px 8px 20px rgba(3, 8, 18, 0.65),
        inset -8px -8px 20px rgba(32, 58, 98, 0.25);
    }

    .login-shell {
      background: var(--paper);
      border: none;
      box-shadow:
        20px 20px 45px var(--neu-dark),
        -20px -20px 45px var(--neu-light);
      transition: box-shadow 220ms ease, transform 220ms ease, background-color 220ms ease;
    }

    body[data-theme='dark'] .login-shell {
      box-shadow:
        18px 18px 40px rgba(2, 6, 23, 0.7),
        -16px -16px 35px rgba(30, 58, 138, 0.18);
    }

    .neu-input {
      border: none !important;
      background: var(--paper) !important;
      box-shadow:
        inset 8px 8px 16px var(--neu-dark),
        inset -8px -8px 16px var(--neu-light);
    }

    .neu-input:focus {
      box-shadow:
        inset 10px 10px 18px var(--neu-dark),
        inset -10px -10px 18px var(--neu-light),
        0 0 0 2px rgba(108, 114, 147, 0.24) !important;
    }

    .neu-toggle {
      background: var(--paper) !important;
      box-shadow:
        5px 5px 12px var(--neu-dark),
        -5px -5px 12px var(--neu-light);
    }

    .quantum-toggle {
      position: relative;
      overflow: hidden;
      border: 1px solid color-mix(in srgb, var(--line) 70%, rgba(56, 189, 248, 0.42));
      background: linear-gradient(120deg, color-mix(in srgb, var(--paper) 85%, #ffffff 15%), color-mix(in srgb, var(--paper) 86%, #bae6fd 14%)) !important;
    }

    .quantum-toggle .ripple {
      position: absolute;
      width: 12px;
      height: 12px;
      border-radius: 999px;
      background: radial-gradient(circle, rgba(56, 189, 248, 0.6), transparent 70%);
      transform: translate(-50%, -50%) scale(1);
      animation: rippleExpand 550ms ease-out forwards;
      pointer-events: none;
    }

    .neu-toggle:hover {
      background: color-mix(in srgb, var(--paper) 84%, #f0f4f8 16%) !important;
    }

    .neu-button {
      border: none !important;
      background: var(--paper) !important;
      color: var(--ink) !important;
      box-shadow:
        10px 10px 22px var(--neu-dark),
        -10px -10px 22px var(--neu-light);
    }

    .portal-button {
      position: relative;
      overflow: hidden;
      color: #edf6ff !important;
      background: linear-gradient(120deg, #ff6b35 0%, #ffd166 24%, #00c896 49%, #38bdf8 74%, #a78bfa 100%) !important;
      background-size: 240% 240%;
      animation: chromeShift 6s linear infinite;
      text-shadow: 0 0 12px rgba(255, 255, 255, 0.42);
      box-shadow:
        0 0 0 1px rgba(255, 255, 255, 0.36),
        0 14px 28px rgba(16, 24, 40, 0.24);
    }

    .portal-button::before {
      content: '';
      position: absolute;
      inset: -120% -40%;
      background: linear-gradient(112deg, transparent 42%, rgba(255, 255, 255, 0.45) 50%, transparent 58%);
      transform: translateX(-45%);
      pointer-events: none;
      animation: portalSheen 2.8s ease-in-out infinite;
    }

    .portal-label {
      position: relative;
      z-index: 1;
      animation: neonPulse 2s ease-in-out infinite;
    }

    .neu-button:hover {
      transform: translateY(-1px);
      box-shadow:
        12px 12px 24px var(--neu-dark),
        -12px -12px 24px var(--neu-light);
    }

    .neu-button:active {
      box-shadow:
        inset 6px 6px 14px var(--neu-dark),
        inset -6px -6px 14px var(--neu-light);
    }

    .neu-badge {
      position: relative;
      width: 74px;
      height: 74px;
      border-radius: 22px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      background: var(--paper);
      color: var(--blue);
      box-shadow:
        10px 10px 22px var(--neu-dark),
        -10px -10px 22px var(--neu-light);
    }

    .chrome-logo {
      isolation: isolate;
      overflow: hidden;
      animation: logoRotate 8s linear infinite;
    }

    .logo-ring {
      position: absolute;
      inset: 5px;
      border-radius: 18px;
      padding: 1px;
      background: linear-gradient(120deg, #ff6b35, #ffd166, #00c896, #38bdf8, #a78bfa, #ff6b35);
      background-size: 250% 250%;
      animation: borderShift 4s linear infinite;
      -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
      -webkit-mask-composite: xor;
      mask-composite: exclude;
      opacity: 0.86;
      z-index: 0;
    }

    .logo-ring.inner {
      inset: 13px;
      border-radius: 10px;
      animation-direction: reverse;
      opacity: 0.55;
    }

    .logo-core {
      position: relative;
      z-index: 1;
      animation: logoCounterRotate 8s linear infinite;
      filter: drop-shadow(0 0 8px rgba(56, 189, 248, 0.55));
    }

    .neu-badge svg {
      width: 34px;
      height: 34px;
    }

    .neu-form-shell {
      position: relative;
      padding: 0.35rem;
      border-radius: 1.2rem;
      background: var(--paper);
      box-shadow:
        inset 6px 6px 14px var(--neu-dark),
        inset -6px -6px 14px var(--neu-light);
    }

    .neu-form-shell::after {
      content: '';
      position: absolute;
      inset: 0;
      border-radius: inherit;
      padding: 1px;
      background: linear-gradient(115deg, transparent 10%, rgba(255, 107, 53, 0.65), rgba(56, 189, 248, 0.78), rgba(0, 200, 150, 0.68), transparent 90%);
      background-size: 220% 220%;
      opacity: 0;
      pointer-events: none;
      -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
      -webkit-mask-composite: xor;
      mask-composite: exclude;
      transition: opacity 200ms ease;
    }

    .neu-form-shell:focus-within::after {
      opacity: 1;
      animation: borderShift 4s linear infinite;
    }

    .neu-error {
      border: none !important;
      background: color-mix(in srgb, #ff3b5c 16%, var(--paper) 84%);
      color: #8b1028 !important;
      box-shadow:
        inset 4px 4px 10px rgba(190, 195, 207, 0.8),
        inset -4px -4px 10px rgba(255, 255, 255, 0.95);
      animation: gentleShake 0.45s ease;
    }

    body[data-theme='dark'] .neu-error {
      color: #fecdd3 !important;
      background: color-mix(in srgb, #7f1d1d 40%, var(--paper) 60%);
      box-shadow:
        inset 4px 4px 10px rgba(2, 6, 23, 0.8),
        inset -4px -4px 10px rgba(30, 58, 138, 0.2);
    }

    @keyframes gentleShake {
      0%, 100% { transform: translateX(0); }
      25% { transform: translateX(-3px); }
      75% { transform: translateX(3px); }
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

    .switcher-chip {
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
      border-radius: 0.7rem;
      border: 1px solid var(--line);
      background: color-mix(in srgb, var(--paper) 76%, #ffffff 24%);
      padding: 0.28rem 0.45rem;
      color: var(--ink);
    }

    body[data-theme='dark'] .switcher-chip {
      background: color-mix(in srgb, var(--paper) 82%, #0b1221 18%);
    }

    .hero-pill {
      background: var(--hero-pill);
      border-color: var(--line);
    }

    .hero-card {
      background: var(--hero-card);
      border-color: var(--line);
      backdrop-filter: blur(8px);
      -webkit-backdrop-filter: blur(8px);
    }

    body,
    .login-form-panel,
    .hero-stripes,
    .hero-pill,
    .hero-card,
    .neu-input,
    .neu-button,
    .neu-toggle {
      transition: background-color 220ms ease, color 220ms ease, border-color 220ms ease, box-shadow 220ms ease;
    }

    .switcher-icon {
      width: 0.92rem;
      height: 0.92rem;
      opacity: 0.9;
      flex-shrink: 0;
    }

    .switcher-select {
      min-width: 3.7rem;
      border-radius: 0.45rem;
      background: transparent;
      font-size: 0.72rem;
      font-weight: 700;
      text-transform: uppercase;
      outline: none;
      color: var(--ink);
    }

    .theme-toggle {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 2rem;
      height: 2rem;
      border-radius: 0.6rem;
      border: 1px solid var(--line);
      background: color-mix(in srgb, var(--paper) 82%, #ffffff 18%);
      color: var(--ink);
      transition: transform 180ms ease, background-color 180ms ease, border-color 180ms ease;
    }

    .theme-toggle:hover {
      transform: translateY(-1px);
      border-color: color-mix(in srgb, var(--line) 65%, rgba(56, 189, 248, 0.55));
    }

    .theme-toggle:focus-visible {
      outline: 2px solid rgba(56, 189, 248, 0.75);
      outline-offset: 2px;
    }

    .theme-toggle svg {
      width: 1rem;
      height: 1rem;
    }

    .switcher-label {
      font-size: 0.68rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      opacity: 0.86;
    }

    .sr-only {
      position: absolute;
      width: 1px;
      height: 1px;
      padding: 0;
      margin: -1px;
      overflow: hidden;
      clip: rect(0, 0, 0, 0);
      white-space: nowrap;
      border: 0;
    }

    .success-portal {
      position: fixed;
      inset: 0;
      display: none;
      align-items: center;
      justify-content: center;
      z-index: 70;
      background: radial-gradient(circle at center, rgba(15, 23, 42, 0.2), rgba(2, 6, 23, 0.72));
      backdrop-filter: blur(2px);
      -webkit-backdrop-filter: blur(2px);
      pointer-events: none;
    }

    .success-portal.active {
      display: flex;
    }

    .portal-core {
      position: relative;
      width: 220px;
      height: 220px;
      display: grid;
      place-items: center;
    }

    .portal-ring {
      position: absolute;
      inset: 0;
      border-radius: 999px;
      border: 2px solid transparent;
      border-top-color: rgba(56, 189, 248, 0.95);
      border-right-color: rgba(0, 200, 150, 0.8);
      border-bottom-color: rgba(255, 107, 53, 0.78);
      animation: portalExpand 1.5s ease-out infinite;
      filter: drop-shadow(0 0 12px rgba(56, 189, 248, 0.45));
    }

    .portal-ring.ring-2 {
      inset: 18px;
      animation-delay: 0.25s;
      animation-duration: 1.6s;
    }

    .portal-ring.ring-3 {
      inset: 36px;
      animation-delay: 0.5s;
      animation-duration: 1.7s;
    }

    .portal-loader {
      position: relative;
      width: 58px;
      height: 58px;
      border-radius: 999px;
      border: 2px solid rgba(226, 232, 240, 0.24);
      border-top-color: rgba(255, 107, 53, 0.95);
      border-right-color: rgba(56, 189, 248, 0.95);
      animation: spin 850ms linear infinite;
    }

    .portal-loader::before,
    .portal-loader::after {
      content: '';
      position: absolute;
      inset: 8px;
      border-radius: 999px;
      border: 2px solid transparent;
      border-top-color: rgba(0, 200, 150, 0.9);
      animation: spin 1.05s linear infinite reverse;
    }

    .portal-loader::after {
      inset: 18px;
      border-top-color: rgba(167, 139, 250, 0.95);
      animation-duration: 1.25s;
    }

    .portal-status {
      position: absolute;
      bottom: -26px;
      width: 100%;
      text-align: center;
      font-size: 0.72rem;
      letter-spacing: 0.16em;
      text-transform: uppercase;
      color: rgba(224, 242, 254, 0.9);
      text-shadow: 0 0 12px rgba(56, 189, 248, 0.8);
      animation: neonPulse 2s ease-in-out infinite;
    }

    .glitching {
      animation: matrixGlitch 180ms steps(2, end) 1;
    }

    .ambient-paused .matrix-grid,
    .ambient-paused .scanner-line,
    .ambient-paused .orb,
    .ambient-paused .chrome-logo,
    .ambient-paused .logo-core,
    .ambient-paused .logo-ring,
    .ambient-paused .portal-button,
    .ambient-paused .portal-button::before,
    .ambient-paused .portal-label,
    .ambient-paused .neon-pulse {
      animation: none !important;
    }

    @media (max-width: 768px) {
      .switcher-label {
        display: none;
      }

      .switcher-chip {
        gap: 0.25rem;
        padding: 0.24rem 0.4rem;
      }

      .switcher-select {
        min-width: 3.2rem;
        font-size: 0.68rem;
      }

      .theme-toggle {
        width: 1.8rem;
        height: 1.8rem;
      }
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

    @keyframes matrixDrift {
      from {
        transform: translate3d(0, 0, 0);
      }
      to {
        transform: translate3d(-24px, -24px, 0);
      }
    }

    @keyframes scannerSweep {
      0% {
        top: -8%;
      }
      100% {
        top: 108%;
      }
    }

    @keyframes orbFloat {
      0%, 100% {
        transform: translate3d(0, 0, 0) scale(1);
      }
      50% {
        transform: translate3d(0, -26px, 0) scale(1.06);
      }
    }

    @keyframes logoRotate {
      from {
        transform: rotate(0deg);
      }
      to {
        transform: rotate(360deg);
      }
    }

    @keyframes logoCounterRotate {
      from {
        transform: rotate(0deg);
      }
      to {
        transform: rotate(-360deg);
      }
    }

    @keyframes borderShift {
      0% {
        background-position: 0% 50%;
      }
      100% {
        background-position: 100% 50%;
      }
    }

    @keyframes chromeShift {
      0% {
        background-position: 0% 50%;
      }
      50% {
        background-position: 100% 50%;
      }
      100% {
        background-position: 0% 50%;
      }
    }

    @keyframes neonPulse {
      0%, 100% {
        filter: brightness(0.95);
      }
      50% {
        filter: brightness(1.18);
      }
    }

    @keyframes rippleExpand {
      to {
        opacity: 0;
        transform: translate(-50%, -50%) scale(9);
      }
    }

    @keyframes portalExpand {
      0% {
        opacity: 0.9;
        transform: scale(0.72) rotate(0deg);
      }
      100% {
        opacity: 0;
        transform: scale(1.35) rotate(200deg);
      }
    }

    @keyframes portalSheen {
      0% {
        transform: translateX(-45%);
      }
      100% {
        transform: translateX(45%);
      }
    }

    @keyframes spin {
      to {
        transform: rotate(360deg);
      }
    }

    @keyframes matrixGlitch {
      0% {
        transform: translateX(0);
        filter: hue-rotate(0deg);
      }
      25% {
        transform: translateX(-2px);
      }
      50% {
        transform: translateX(2px);
        filter: hue-rotate(14deg);
      }
      75% {
        transform: translateX(-1px);
      }
      100% {
        transform: translateX(0);
        filter: hue-rotate(0deg);
      }
    }

    @media (prefers-reduced-motion: reduce) {
      .matrix-grid,
      .scanner-line,
      .orb,
      .chrome-logo,
      .logo-core,
      .logo-ring,
      .portal-button,
      .portal-label,
      .portal-ring,
      .portal-loader,
      .portal-loader::before,
      .portal-loader::after {
        animation: none !important;
      }
    }
  </style>
</head>
<body class="min-h-screen antialiased">
  <div class="matrix-grid" aria-hidden="true"></div>
  <div class="scanner-line scanner-a" aria-hidden="true"></div>
  <div class="scanner-line scanner-b" aria-hidden="true"></div>
  <div class="retro-orbs" aria-hidden="true">
    <span class="orb orb-a"></span>
    <span class="orb orb-b"></span>
    <span class="orb orb-c"></span>
  </div>
  <main class="relative z-10 mx-auto flex min-h-[92vh] w-full max-w-5xl items-center px-4 py-5 sm:px-6 lg:px-8">
    <section id="loginCard" class="login-shell float-in mx-auto grid w-full max-w-[980px] overflow-hidden rounded-[1.75rem] border border-[color:var(--line)] bg-[color:var(--paper)] shadow-[0_24px_60px_-32px_rgba(20,33,61,0.42)] lg:grid-cols-[1.05fr_0.95fr]">
      <div class="hero-stripes relative hidden overflow-hidden p-10 lg:flex lg:flex-col">
        <div class="absolute -left-12 -top-12 h-40 w-40 rounded-full bg-[color:var(--mint)]/45 blur-2xl"></div>
        <div class="absolute -bottom-14 right-4 h-44 w-44 rounded-full bg-[color:var(--salmon)]/35 blur-2xl"></div>

        <div class="relative z-10">
          <p class="hero-pill float-in stagger-1 inline-flex items-center rounded-full border border-[color:var(--line)] bg-white/65 px-3 py-1 text-xs font-semibold uppercase tracking-[0.22em] text-[color:var(--blue)]"><?= e($shopBrand) ?></p>
          <h1 data-i18n="welcomeBackCashier" class="headline-font float-in stagger-2 mt-6 text-4xl font-semibold leading-tight text-[color:var(--ink)]">Welcome Back,<br />Cashier</h1>
          <p data-i18n="heroSub" class="float-in stagger-3 mt-4 max-w-sm text-sm leading-relaxed text-[color:var(--ink)]/80">Sign in to open your terminal, process sales faster, and keep your inventory perfectly synced.</p>
        </div>

        <div class="hero-card relative z-10 mt-auto space-y-3 rounded-2xl border border-[color:var(--line)] bg-white/60 p-4 backdrop-blur-sm">
          <div class="float-in stagger-2 flex items-center gap-3 text-sm text-[color:var(--ink)]/80">
            <span class="inline-block h-2.5 w-2.5 rounded-full bg-emerald-500"></span>
            <span data-i18n="heroPoint1">Inventory visibility in real time</span>
          </div>
          <div class="float-in stagger-3 flex items-center gap-3 text-sm text-[color:var(--ink)]/80">
            <span class="inline-block h-2.5 w-2.5 rounded-full bg-amber-500"></span>
            <span data-i18n="heroPoint2">Secure role-based access control</span>
          </div>
          <div class="float-in stagger-4 flex items-center gap-3 text-sm text-[color:var(--ink)]/80">
            <span class="inline-block h-2.5 w-2.5 rounded-full bg-sky-500"></span>
            <span data-i18n="heroPoint3">Smooth checkout flow for busy hours</span>
          </div>
        </div>
      </div>

      <div class="login-form-panel p-5 sm:p-8">
        <div class="mx-auto max-w-md">
          <div class="float-in stagger-1 mb-4 flex justify-end gap-2">
            <label class="switcher-chip" title="Toggle theme">
              <span data-i18n="theme" class="sr-only">Theme</span>
              <button id="themeSwitch" type="button" class="theme-toggle" aria-label="Switch to light theme" aria-pressed="true">
                <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 2a8 8 0 1 0 8 8 7 7 0 0 1-8-8z"/></svg>
              </button>
            </label>
            <label class="switcher-chip" title="Language">
              <svg class="switcher-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 2a8 8 0 1 0 8 8 8 8 0 0 0-8-8zm4.9 7h-2.1a12.5 12.5 0 0 0-.6-3 6 6 0 0 1 2.7 3zM10 4.2c.6.9 1.1 2.8 1.3 4.8H8.7c.2-2 .7-3.9 1.3-4.8zM6.8 6a12.5 12.5 0 0 0-.6 3H4.1a6 6 0 0 1 2.7-3zM4.1 11h2.1c.1 1.1.3 2.1.6 3a6 6 0 0 1-2.7-3zm3.6 0h2.6c-.2 2-.7 3.9-1.3 4.8-.6-.9-1.1-2.8-1.3-4.8zm4.5 3c.3-.9.5-1.9.6-3h2.1a6 6 0 0 1-2.7 3z"/></svg>
              <span data-i18n="language" class="switcher-label">Language</span>
              <select id="languageSwitch" aria-label="Language" class="switcher-select">
                <option value="en">EN</option>
                <option value="fr">FR</option>
                <option value="tw">TWI</option>
                <option value="ee">EWE</option>
                <option value="gaa">GA</option>
                <option value="fat">FANTE</option>
                <option value="dag">DAGBANI</option>
                <option value="gur">GURUNE</option>
                <option value="kus">KUSAAL</option>
              </select>
            </label>
          </div>

          <div class="float-in stagger-1 mb-8 lg:mb-10">
            <div class="neu-badge chrome-logo mb-4" aria-hidden="true">
              <span class="logo-ring"></span>
              <svg class="logo-core" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
              <span class="logo-ring inner"></span>
            </div>
            <p class="text-xs font-semibold uppercase tracking-[0.22em] text-[color:var(--blue)] lg:hidden"><?= e($shopBrand) ?></p>
            <h2 data-i18n="signIn" class="headline-font neon-pulse mt-2 text-3xl font-semibold text-[color:var(--ink)]">Sign In</h2>
            <p data-i18n="signInSub" class="mt-2 text-sm text-[color:var(--ink)]/70">Enter your credentials to continue to your POS dashboard.</p>
          </div>

          <?php if ($error !== null): ?>
            <div id="loginError" class="neu-error float-in stagger-2 mb-5 rounded-2xl border border-rose-300/70 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-700"><?= e($error) ?></div>
          <?php endif; ?>

          <form id="loginForm" method="post" class="space-y-5">
            <input type="hidden" name="csrf_token" value="<?= e((string) $_SESSION['csrf_token']) ?>" />

            <label class="float-in stagger-2 block text-sm">
              <span data-i18n="username" class="mb-2 block font-semibold text-[color:var(--ink)]/90">Username</span>
              <div class="neu-form-shell">
                <input
                  name="username"
                  value="<?= e($username) ?>"
                  required
                  autofocus
                  autocomplete="username"
                  data-i18n-placeholder="usernamePlaceholder"
                  placeholder="Enter username"
                  class="neu-input w-full rounded-2xl border border-[color:var(--line)] bg-white px-4 py-3 text-[color:var(--ink)] outline-none transition focus:border-[color:var(--blue)] focus:ring-4 focus:ring-sky-200/45"
                />
              </div>
            </label>

            <label class="float-in stagger-3 block text-sm">
              <span data-i18n="password" class="mb-2 block font-semibold text-[color:var(--ink)]/90">Password</span>
              <div class="neu-form-shell relative">
                <input
                  id="passwordInput"
                  type="password"
                  name="password"
                  required
                  autocomplete="current-password"
                  data-i18n-placeholder="passwordPlaceholder"
                  placeholder="Enter password"
                  class="neu-input w-full rounded-2xl border border-[color:var(--line)] bg-white px-4 py-3 pr-14 text-[color:var(--ink)] outline-none transition focus:border-[color:var(--blue)] focus:ring-4 focus:ring-sky-200/45"
                />
                <button
                  type="button"
                  id="togglePassword"
                  class="neu-toggle quantum-toggle absolute right-2 top-1/2 -translate-y-1/2 rounded-xl px-3 py-1.5 text-xs font-semibold uppercase tracking-[0.12em] text-[color:var(--blue)] transition hover:bg-slate-100"
                >Show</button>
              </div>
            </label>

            <button class="neu-button portal-button float-in stagger-4 w-full rounded-2xl px-4 py-3 text-sm font-bold uppercase tracking-[0.12em] transition hover:bg-slate-700"><span data-i18n="enterTerminal" class="portal-label">Enter Terminal</span></button>
          </form>
        </div>
      </div>
    </section>
  </main>

  <div id="successPortal" class="success-portal" aria-hidden="true">
    <div class="portal-core" role="status" aria-live="polite">
      <span class="portal-ring ring-1"></span>
      <span class="portal-ring ring-2"></span>
      <span class="portal-ring ring-3"></span>
      <span class="portal-loader"></span>
      <span class="portal-status">Warping In</span>
    </div>
  </div>

  <script>
    (function () {
      const passwordInput = document.getElementById('passwordInput');
      const toggle = document.getElementById('togglePassword');
      const themeSwitch = document.getElementById('themeSwitch');
      const languageSwitch = document.getElementById('languageSwitch');
      const LANG_PREF_KEY = 'novapos_lang';
      const THEME_PREF_KEY = 'novapos_theme';
      const GHANA_TRANSLATE_API = 'api/translate_text.php';
      const GHANA_SUPPORTED_LANGS = new Set(['tw', 'ee', 'gaa', 'fat', 'dag', 'gur', 'kus']);
      const hydratedRemoteLanguages = new Set();

      const translations = {
        en: {
          theme: 'Theme',
          themeDark: 'Dark',
          themeLight: 'Light',
          language: 'Language',
          welcomeBackCashier: 'Welcome Back, Cashier',
          heroSub: 'Sign in to open your terminal, process sales faster, and keep your inventory perfectly synced.',
          heroPoint1: 'Inventory visibility in real time',
          heroPoint2: 'Secure role-based access control',
          heroPoint3: 'Smooth checkout flow for busy hours',
          signIn: 'Sign In',
          signInSub: 'Enter your credentials to continue to your POS dashboard.',
          username: 'Username',
          usernamePlaceholder: 'Enter username',
          password: 'Password',
          passwordPlaceholder: 'Enter password',
          show: 'Show',
          hide: 'Hide',
          enterTerminal: 'Enter Terminal',
        },
        fr: {
          theme: 'Theme',
          themeDark: 'Sombre',
          themeLight: 'Clair',
          language: 'Langue',
          welcomeBackCashier: 'Bon retour, caissier',
          heroSub: 'Connectez-vous pour ouvrir votre terminal, accelerer les ventes et garder votre stock synchronise.',
          heroPoint1: 'Visibilite des stocks en temps reel',
          heroPoint2: 'Controle d acces securise par role',
          heroPoint3: 'Flux de caisse fluide pendant les heures de pointe',
          signIn: 'Connexion',
          signInSub: 'Saisissez vos identifiants pour continuer vers le tableau de bord POS.',
          username: "Nom d'utilisateur",
          usernamePlaceholder: "Entrez le nom d'utilisateur",
          password: 'Mot de passe',
          passwordPlaceholder: 'Entrez le mot de passe',
          show: 'Voir',
          hide: 'Masquer',
          enterTerminal: 'Entrer au terminal',
        },
        tw: {
          language: 'Kasa',
          signIn: 'Hyɛ mu',
          username: 'Username',
          password: 'Password',
          enterTerminal: 'Kɔ Terminal mu',
        },
        gaa: {
          language: 'Mli',
          signIn: 'Nɔɔ mli',
          username: 'Username',
          password: 'Password',
          enterTerminal: 'Ke Terminal mli',
        },
        ee: {
          language: 'Gbe',
          signIn: 'Ge ɖe eme',
          username: 'Username',
          password: 'Password',
          enterTerminal: 'Yi ɖe Terminal me',
        },
        fat: {
          language: 'Mfantse',
        },
        dag: {
          language: 'Dagbanli',
        },
        gur: {
          language: 'Gurune',
        },
        kus: {
          language: 'Kusaal',
        }
      };

      let currentLanguage = 'en';

      function normalizeLanguageCode(languageCode) {
        const map = {
          twi: 'tw',
          ewe: 'ee',
          ga: 'gaa',
          sehwi: 'tw'
        };

        return map[languageCode] || languageCode;
      }

      function getRemoteLanguageCacheKey(langCode) {
        return `novapos_remote_i18n_login_${langCode}`;
      }

      function loadRemoteLanguageCache(langCode) {
        try {
          const raw = localStorage.getItem(getRemoteLanguageCacheKey(langCode));
          if (!raw) {
            return;
          }

          const decoded = JSON.parse(raw);
          if (!decoded || typeof decoded !== 'object') {
            return;
          }

          translations[langCode] = {
            ...(translations[langCode] || {}),
            ...decoded
          };
        } catch (error) {
        }
      }

      function saveRemoteLanguageCache(langCode, values) {
        try {
          localStorage.setItem(getRemoteLanguageCacheKey(langCode), JSON.stringify(values));
        } catch (error) {
        }
      }

      function renderLanguageUI() {
        document.documentElement.lang = currentLanguage;

        document.querySelectorAll('[data-i18n]').forEach((element) => {
          const key = element.getAttribute('data-i18n');
          if (key) {
            element.textContent = t(key);
          }
        });

        document.querySelectorAll('[data-i18n-placeholder]').forEach((element) => {
          const key = element.getAttribute('data-i18n-placeholder');
          if (key && 'placeholder' in element) {
            element.placeholder = t(key);
          }
        });

        if (toggle && passwordInput) {
          const showing = passwordInput.type === 'text';
          toggle.textContent = showing ? t('hide') : t('show');
        }
      }

      async function hydrateRemoteTranslations(langCode) {
        if (!GHANA_SUPPORTED_LANGS.has(langCode) || hydratedRemoteLanguages.has(langCode)) {
          return;
        }

        loadRemoteLanguageCache(langCode);

        const sourcePack = translations.en || {};
        const targetPack = translations[langCode] || {};
        const payload = {};

        Object.keys(sourcePack).forEach((key) => {
          const targetValue = String(targetPack[key] || '').trim();
          const sourceValue = String(sourcePack[key] || '').trim();

          if (sourceValue !== '' && (targetValue === '' || targetValue === sourceValue)) {
            payload[key] = sourceValue;
          }
        });

        if (Object.keys(payload).length === 0) {
          hydratedRemoteLanguages.add(langCode);
          return;
        }

        try {
          const response = await fetch(GHANA_TRANSLATE_API, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json'
            },
            body: JSON.stringify({
              source: 'en',
              target: langCode,
              texts: payload
            })
          });

          const result = await response.json();
          if (!response.ok || !result || result.success !== true || typeof result.translations !== 'object') {
            return;
          }

          translations[langCode] = {
            ...(translations[langCode] || {}),
            ...result.translations
          };

          saveRemoteLanguageCache(langCode, translations[langCode]);
          hydratedRemoteLanguages.add(langCode);
        } catch (error) {
        }
      }

      function t(key) {
        const languagePack = translations[currentLanguage] || translations.en;
        return languagePack[key] || translations.en[key] || key;
      }

      function themeIconMarkup(theme) {
        if (theme === 'light') {
          return '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 4a1 1 0 0 1 1 1v1a1 1 0 1 1-2 0V5a1 1 0 0 1 1-1zm0 8a2 2 0 1 0 0-4 2 2 0 0 0 0 4zm5-2a1 1 0 0 1 1-1h1a1 1 0 1 1 0 2h-1a1 1 0 0 1-1-1zM3 10a1 1 0 0 1 1-1h1a1 1 0 1 1 0 2H4a1 1 0 0 1-1-1zm9.66-3.66a1 1 0 0 1 0-1.41l.71-.71a1 1 0 1 1 1.41 1.41l-.7.71a1 1 0 0 1-1.42 0zm-6.32 6.32a1 1 0 0 1 0-1.41l.71-.71a1 1 0 0 1 1.41 1.41l-.7.71a1 1 0 0 1-1.42 0zm7.03 0-.71-.71a1 1 0 1 1 1.41-1.41l.71.7a1 1 0 1 1-1.41 1.42zm-6.32-6.32-.71-.71A1 1 0 1 0 4.93 4.93l.7.71a1 1 0 1 0 1.42-1.41z"/></svg>';
        }

        return '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 2a8 8 0 1 0 8 8 7 7 0 0 1-8-8z"/></svg>';
      }

      function syncThemeToggle(theme) {
        if (!(themeSwitch instanceof HTMLElement)) {
          return;
        }

        const nextTheme = theme === 'light' ? 'dark' : 'light';
        themeSwitch.setAttribute('aria-label', `Switch to ${nextTheme} theme`);
        themeSwitch.setAttribute('title', `Switch to ${nextTheme} theme`);
        themeSwitch.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
        themeSwitch.innerHTML = themeIconMarkup(theme);
      }

      function applyLanguage(languageCode) {
        const allowedLanguages = ['en', 'fr', 'tw', 'ee', 'gaa', 'fat', 'dag', 'gur', 'kus'];
        const normalizedCode = normalizeLanguageCode(languageCode);
        currentLanguage = allowedLanguages.includes(normalizedCode) ? normalizedCode : 'en';

        if (languageSwitch) {
          languageSwitch.value = currentLanguage;
        }

        try {
          localStorage.setItem(LANG_PREF_KEY, currentLanguage);
        } catch (error) {
        }

        const requestedLanguage = currentLanguage;
        renderLanguageUI();

        hydrateRemoteTranslations(requestedLanguage).then(() => {
          if (currentLanguage === requestedLanguage) {
            renderLanguageUI();
          }
        });
      }

      function applyTheme(themeName) {
        let theme = themeName;
        if (themeName === 'ocean') {
          theme = 'dark';
        } else if (themeName === 'aurora') {
          theme = 'light';
        }

        if (theme !== 'light' && theme !== 'dark') {
          theme = 'dark';
        }

        document.body.setAttribute('data-theme', theme);
        syncThemeToggle(theme);

        try {
          localStorage.setItem(THEME_PREF_KEY, theme);
        } catch (error) {
        }
      }

      function loadThemePreference() {
        let saved = 'dark';
        try {
          saved = localStorage.getItem(THEME_PREF_KEY) || 'dark';
        } catch (error) {
        }
        applyTheme(saved);
      }

      function loadLanguagePreference() {
        let saved = 'en';
        try {
          saved = localStorage.getItem(LANG_PREF_KEY) || 'en';
        } catch (error) {
        }
        applyLanguage(saved);
      }

      if (!passwordInput || !toggle) {
        loadLanguagePreference();
        return;
      }

      const loginCard = document.getElementById('loginCard');
      const loginError = document.getElementById('loginError');
      const loginForm = document.getElementById('loginForm');
      const successPortal = document.getElementById('successPortal');

      function updateAmbientShadow(clientX, clientY) {
        if (!loginCard) {
          return;
        }

        const rect = loginCard.getBoundingClientRect();
        const relX = (clientX - rect.left) / rect.width;
        const relY = (clientY - rect.top) / rect.height;

        const x = (relX - 0.5) * 18;
        const y = (relY - 0.5) * 18;
        const lift = Math.max(34, 48 - Math.abs(x) - Math.abs(y));

        loginCard.style.boxShadow = `${x}px ${y}px ${lift}px var(--neu-dark), ${-x}px ${-y}px ${lift}px var(--neu-light)`;
      }

      if (loginCard) {
        loginCard.addEventListener('mousemove', function (event) {
          updateAmbientShadow(event.clientX, event.clientY);
        });

        loginCard.addEventListener('mouseleave', function () {
          loginCard.style.boxShadow = '';
        });
      }

      if (loginForm) {
        loginForm.addEventListener('submit', function () {
          const submitButton = loginForm.querySelector('.neu-button');
          if (submitButton instanceof HTMLElement) {
            submitButton.style.boxShadow = 'inset 6px 6px 14px var(--neu-dark), inset -6px -6px 14px var(--neu-light)';
          }

          if (successPortal instanceof HTMLElement) {
            successPortal.classList.add('active');
            successPortal.setAttribute('aria-hidden', 'false');
          }
        });
      }

      if (loginError) {
        loginError.addEventListener('animationend', function () {
          loginError.style.animation = 'none';
          requestAnimationFrame(function () {
            loginError.style.animation = '';
          });
        });
      }

      toggle.addEventListener('click', function () {
        const showing = passwordInput.type === 'text';
        passwordInput.type = showing ? 'password' : 'text';
        toggle.textContent = showing ? t('show') : t('hide');

        const ripple = document.createElement('span');
        ripple.className = 'ripple';
        const rect = toggle.getBoundingClientRect();
        ripple.style.left = `${rect.width / 2}px`;
        ripple.style.top = `${rect.height / 2}px`;
        toggle.appendChild(ripple);
        setTimeout(function () {
          ripple.remove();
        }, 560);
      });

      let glitchPulseCount = 0;

      function runGlitchPulse() {
        if (!loginCard || document.hidden || glitchPulseCount >= 4) {
          return;
        }

        glitchPulseCount += 1;
        loginCard.classList.add('glitching');
        setTimeout(function () {
          loginCard.classList.remove('glitching');
        }, 190);

        const nextDelay = 2600 + Math.floor(Math.random() * 2200);
        setTimeout(runGlitchPulse, nextDelay);
      }

      setTimeout(runGlitchPulse, 1800);
      setTimeout(function () {
        document.body.classList.add('ambient-paused');
      }, 16000);

      if (languageSwitch) {
        languageSwitch.addEventListener('change', function () {
          applyLanguage(languageSwitch.value);
        });
      }

      if (themeSwitch) {
        themeSwitch.addEventListener('click', function () {
          const currentTheme = document.body.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
          applyTheme(currentTheme === 'light' ? 'dark' : 'light');
        });
      }

      window.addEventListener('storage', function (event) {
        if (event.key !== THEME_PREF_KEY || event.newValue === null) {
          return;
        }

        applyTheme(event.newValue);
      });

      loadThemePreference();
      loadLanguagePreference();
    })();
  </script>
</body>
</html>

