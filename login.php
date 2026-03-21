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
  <script src="assets/vendor/tailwindcss/tailwindcss.js"></script>
  <style>
    :root {
      --ink: #14213d;
      --paper: #f6f5f1;
      --salmon: #6fbfd8;
      --mint: #8ad7c1;
      --blue: #4f7cac;
      --line: rgba(20, 33, 61, 0.14);
    }

    body {
      font-family: 'Montserrat', 'Lato', 'Segoe UI', Tahoma, Arial, sans-serif;
      background:
        radial-gradient(circle at 8% 15%, rgba(138, 215, 193, 0.56), transparent 28%),
        radial-gradient(circle at 85% 82%, rgba(111, 191, 216, 0.46), transparent 34%),
        linear-gradient(140deg, #edf6fb 0%, #e9f2fb 58%, #edf4fa 100%);
      color: var(--ink);
    }

    body[data-theme='dark'] {
      --ink: #e2e8f0;
      --paper: #0f172a;
      --line: rgba(226, 232, 240, 0.2);
      --blue: #7dd3fc;
      background:
        radial-gradient(circle at 8% 15%, rgba(56, 189, 248, 0.24), transparent 28%),
        radial-gradient(circle at 85% 82%, rgba(129, 140, 248, 0.18), transparent 34%),
        linear-gradient(140deg, #020617 0%, #0b1326 58%, #111827 100%);
    }

    body[data-theme='dark'] .hero-stripes {
      background-image:
        linear-gradient(30deg, rgba(125, 211, 252, 0.12) 12%, transparent 12.5%, transparent 87%, rgba(125, 211, 252, 0.12) 87.5%),
        linear-gradient(150deg, rgba(129, 140, 248, 0.14) 12%, transparent 12.5%, transparent 87%, rgba(129, 140, 248, 0.14) 87.5%);
    }

    .headline-font {
      font-family: 'Merriweather', Georgia, 'Times New Roman', serif;
    }

    .hero-stripes {
      background-image:
        linear-gradient(30deg, rgba(79, 124, 172, 0.12) 12%, transparent 12.5%, transparent 87%, rgba(79, 124, 172, 0.12) 87.5%),
        linear-gradient(150deg, rgba(111, 191, 216, 0.13) 12%, transparent 12.5%, transparent 87%, rgba(111, 191, 216, 0.13) 87.5%);
      background-size: 20px 35px;
    }

    .login-form-panel {
      background:
        radial-gradient(circle at 12% 8%, rgba(79, 124, 172, 0.17), transparent 34%),
        radial-gradient(circle at 92% 94%, rgba(125, 211, 252, 0.2), transparent 38%),
        linear-gradient(160deg, rgba(250, 253, 255, 0.74) 0%, rgba(240, 247, 255, 0.9) 46%, rgba(235, 245, 252, 0.92) 100%);
    }

    body[data-theme='dark'] .login-form-panel {
      background:
        radial-gradient(circle at 12% 8%, rgba(125, 211, 252, 0.18), transparent 34%),
        radial-gradient(circle at 92% 94%, rgba(129, 140, 248, 0.14), transparent 38%),
        linear-gradient(165deg, rgba(10, 18, 34, 0.92) 0%, rgba(15, 23, 42, 0.94) 48%, rgba(2, 6, 23, 0.96) 100%);
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
      background: rgba(255, 255, 255, 0.7);
      padding: 0.28rem 0.45rem;
      color: var(--ink);
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
          <h1 data-i18n="welcomeBackCashier" class="headline-font float-in stagger-2 mt-6 text-4xl font-semibold leading-tight text-[color:var(--ink)]">Welcome Back,<br />Cashier</h1>
          <p data-i18n="heroSub" class="float-in stagger-3 mt-4 max-w-sm text-sm leading-relaxed text-[color:var(--ink)]/80">Sign in to open your terminal, process sales faster, and keep your inventory perfectly synced.</p>
        </div>

        <div class="relative z-10 mt-auto space-y-3 rounded-2xl border border-[color:var(--line)] bg-white/60 p-4 backdrop-blur-sm">
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

      <div class="login-form-panel p-6 sm:p-10">
        <div class="mx-auto max-w-md">
          <div class="float-in stagger-1 mb-4 flex justify-end gap-2">
            <label class="switcher-chip" title="Theme">
              <svg class="switcher-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 2a8 8 0 1 0 8 8 7 7 0 0 1-8-8z"/></svg>
              <span data-i18n="theme" class="switcher-label">Theme</span>
              <select id="themeSwitch" aria-label="Theme" class="switcher-select">
                <option value="dark" data-i18n="themeDark">Dark</option>
                <option value="light" data-i18n="themeLight">Light</option>
              </select>
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
            <p class="text-xs font-semibold uppercase tracking-[0.22em] text-[color:var(--blue)] lg:hidden"><?= e($shopBrand) ?></p>
            <h2 data-i18n="signIn" class="headline-font mt-2 text-3xl font-semibold text-[color:var(--ink)]">Sign In</h2>
            <p data-i18n="signInSub" class="mt-2 text-sm text-[color:var(--ink)]/70">Enter your credentials to continue to your POS dashboard.</p>
          </div>

          <?php if ($error !== null): ?>
            <div class="float-in stagger-2 mb-5 rounded-2xl border border-rose-300/70 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-700"><?= e($error) ?></div>
          <?php endif; ?>

          <form method="post" class="space-y-5">
            <input type="hidden" name="csrf_token" value="<?= e((string) $_SESSION['csrf_token']) ?>" />

            <label class="float-in stagger-2 block text-sm">
              <span data-i18n="username" class="mb-2 block font-semibold text-[color:var(--ink)]/90">Username</span>
              <input
                name="username"
                value="<?= e($username) ?>"
                required
                autofocus
                autocomplete="username"
                data-i18n-placeholder="usernamePlaceholder"
                placeholder="Enter username"
                class="w-full rounded-2xl border border-[color:var(--line)] bg-white px-4 py-3 text-[color:var(--ink)] outline-none transition focus:border-[color:var(--blue)] focus:ring-4 focus:ring-sky-200/45"
              />
            </label>

            <label class="float-in stagger-3 block text-sm">
              <span data-i18n="password" class="mb-2 block font-semibold text-[color:var(--ink)]/90">Password</span>
              <div class="relative">
                <input
                  id="passwordInput"
                  type="password"
                  name="password"
                  required
                  autocomplete="current-password"
                  data-i18n-placeholder="passwordPlaceholder"
                  placeholder="Enter password"
                  class="w-full rounded-2xl border border-[color:var(--line)] bg-white px-4 py-3 pr-14 text-[color:var(--ink)] outline-none transition focus:border-[color:var(--blue)] focus:ring-4 focus:ring-sky-200/45"
                />
                <button
                  type="button"
                  id="togglePassword"
                  class="absolute right-2 top-1/2 -translate-y-1/2 rounded-xl px-3 py-1.5 text-xs font-semibold uppercase tracking-[0.12em] text-[color:var(--blue)] transition hover:bg-slate-100"
                >Show</button>
              </div>
            </label>

            <button data-i18n="enterTerminal" class="float-in stagger-4 w-full rounded-2xl bg-[color:var(--ink)] px-4 py-3 text-sm font-bold uppercase tracking-[0.12em] text-[color:var(--paper)] transition hover:bg-slate-700">Enter Terminal</button>
          </form>
        </div>
      </div>
    </section>
  </main>

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
        if (themeSwitch) {
          themeSwitch.value = theme;
        }

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

      toggle.addEventListener('click', function () {
        const showing = passwordInput.type === 'text';
        passwordInput.type = showing ? 'password' : 'text';
        toggle.textContent = showing ? t('show') : t('hide');
      });

      if (languageSwitch) {
        languageSwitch.addEventListener('change', function () {
          applyLanguage(languageSwitch.value);
        });
      }

      if (themeSwitch) {
        themeSwitch.addEventListener('change', function () {
          applyTheme(themeSwitch.value);
        });
      }

      loadThemePreference();
      loadLanguagePreference();
    })();
  </script>
</body>
</html>

