<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Models\ShopSettings;
use App\Repositories\CategoryRepository;

$currentUser = Auth::requirePageAuth(['admin']);

$errors = [];
$success = null;
$categoryImportSummary = null;
$checkoutAuditGuard = [
  'blocked' => false,
  'reason' => null,
  'summary' => '',
  'fix' => '',
];
$settings = ShopSettings::get();
$categoryRepo = new CategoryRepository();
$categories = $categoryRepo->all();

$currencyOptions = [
  'USD' => '$',
  'EUR' => '€',
  'GBP' => '£',
  'CHF' => 'CHF',
  'SEK' => 'kr',
  'NOK' => 'kr',
  'DKK' => 'kr',
  'PLN' => 'zł',
  'CZK' => 'Kč',
  'HUF' => 'Ft',
  'RON' => 'lei',
  'TRY' => '₺',
  'RUB' => '₽',
  'UAH' => '₴',
  'NGN' => '₦',
  'GHC' => '₵',
  'KES' => 'KSh',
  'ZAR' => 'R',
  'UGX' => 'USh',
  'TZS' => 'TSh',
  'RWF' => 'RF',
  'XOF' => 'CFA',
  'XAF' => 'FCFA',
  'EGP' => 'E£',
  'MAD' => 'MAD',
  'TND' => 'DT',
  'ETB' => 'Br',
  'BWP' => 'P',
  'ZMW' => 'ZK',
  'MWK' => 'MK',
  'INR' => '₹',
  'PKR' => '₨',
  'BDT' => '৳',
  'LKR' => 'Rs',
  'NPR' => 'Rs',
  'AED' => 'د.إ',
  'SAR' => '﷼',
  'QAR' => 'QR',
  'KWD' => 'KD',
  'BHD' => 'BD',
  'OMR' => 'OMR',
  'JOD' => 'JD',
  'ILS' => '₪',
  'JPY' => '¥',
  'CNY' => '¥',
  'HKD' => 'HK$',
  'SGD' => 'S$',
  'MYR' => 'RM',
  'THB' => '฿',
  'IDR' => 'Rp',
  'PHP' => '₱',
  'VND' => '₫',
  'KRW' => '₩',
  'TWD' => 'NT$',
  'CAD' => 'C$',
  'AUD' => 'A$',
  'NZD' => 'NZ$',
  'MXN' => 'MX$',
  'BRL' => 'R$',
  'ARS' => '$',
  'CLP' => '$',
  'COP' => '$',
  'PEN' => 'S/',
  'UYU' => '$U',
];

/**
 * @return string
 */
function categorySlug(string $value): string
{
  $value = strtolower(trim($value));
  $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
  $value = trim($value, '-');

  return $value;
}

/**
 * @return array{blocked: bool, reason: string|null, summary: string, fix: string}
 */
function checkoutAuditGuardStatus(): array
{
  try {
    $pdo = Database::connection();
    $statement = $pdo->query(
      'SELECT NOW() AS db_now, MAX(sold_at) AS latest_sale_at
       FROM sales'
    );
    $row = $statement->fetch(\PDO::FETCH_ASSOC);

    if ($row === false) {
      return [
        'blocked' => false,
        'reason' => null,
        'summary' => '',
        'fix' => '',
      ];
    }

    $dbNowRaw = (string) ($row['db_now'] ?? '');
    $latestSaleRaw = (string) ($row['latest_sale_at'] ?? '');
    if ($dbNowRaw === '' || $latestSaleRaw === '') {
      return [
        'blocked' => false,
        'reason' => null,
        'summary' => '',
        'fix' => '',
      ];
    }

    $dbNow = strtotime($dbNowRaw);
    $latestSale = strtotime($latestSaleRaw);
    if ($dbNow === false || $latestSale === false) {
      return [
        'blocked' => false,
        'reason' => null,
        'summary' => '',
        'fix' => '',
      ];
    }

    if ($latestSale > ($dbNow + 300)) {
      return [
        'blocked' => true,
        'reason' => 'clock_issue',
        'summary' => 'Checkout is currently blocked because sales timestamps are ahead of database time.',
        'fix' => 'Fix needed: sync server/PC date and time (including timezone), then recheck. Ensure POS host and MySQL server clocks match.',
      ];
    }

    $dbYearMonth = date('Y-m', $dbNow);
    $latestYearMonth = date('Y-m', $latestSale);
    if ($latestYearMonth > $dbYearMonth) {
      return [
        'blocked' => true,
        'reason' => 'period_mismatch',
        'summary' => 'Checkout is blocked due to future-month sales records relative to current database period.',
        'fix' => 'Fix needed: correct system/database period and review future-dated sales before resuming checkout to protect month-end audit accuracy.',
      ];
    }
  } catch (\Throwable $throwable) {
    error_log('settings audit guard status failure: ' . $throwable->getMessage());
  }

  return [
    'blocked' => false,
    'reason' => null,
    'summary' => '',
    'fix' => '',
  ];
}

$checkoutAuditGuard = checkoutAuditGuardStatus();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    $sessionCsrf = (string) ($_SESSION['csrf_token'] ?? '');

    if ($csrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf)) {
        $errors[] = 'Session expired. Refresh and try again.';
    }

    $action = (string) ($_POST['action'] ?? 'save_settings');

    if ($action === 'save_settings') {
      $payload = [
        'shop_name' => trim((string) ($_POST['shop_name'] ?? '')),
        'shop_logo_url' => trim((string) ($_POST['shop_logo_url'] ?? '')),
        'business_tagline' => trim((string) ($_POST['business_tagline'] ?? '')),
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
        'enable_discounts' => ((string) ($_POST['enable_discounts'] ?? '')) === '1',
        'enable_returns' => ((string) ($_POST['enable_returns'] ?? '')) === '1',
        'enable_multi_store' => ((string) ($_POST['enable_multi_store'] ?? '')) === '1',
        'enable_time_clock' => ((string) ($_POST['enable_time_clock'] ?? '')) === '1',
        'enable_email_notifications' => ((string) ($_POST['enable_email_notifications'] ?? '')) === '1',
        'smtp_host' => trim((string) ($_POST['smtp_host'] ?? '')),
        'smtp_port' => (int) ($_POST['smtp_port'] ?? 587),
        'smtp_username' => trim((string) ($_POST['smtp_username'] ?? '')),
        'smtp_password' => trim((string) ($_POST['smtp_password'] ?? '')),
        'smtp_encryption' => trim((string) ($_POST['smtp_encryption'] ?? 'tls')),
        'email_from_address' => trim((string) ($_POST['email_from_address'] ?? '')),
        'email_from_name' => trim((string) ($_POST['email_from_name'] ?? '')),
      ];

      if ($payload['currency_code'] === 'GHS') {
        $payload['currency_code'] = 'GHC';
      }

      if ($payload['shop_name'] === '') {
        $errors[] = 'Shop name is required.';
      }

      if ($payload['shop_logo_url'] !== '' && filter_var($payload['shop_logo_url'], FILTER_VALIDATE_URL) === false) {
        $errors[] = 'Logo URL must be a valid URL (or leave empty).';
      }

      if (!array_key_exists($payload['currency_code'], $currencyOptions)) {
        $errors[] = 'Please select a valid preset currency.';
      } else {
        $payload['currency_symbol'] = (string) $currencyOptions[$payload['currency_code']];
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
        } catch (\Throwable $throwable) {
          error_log('settings save failure: ' . $throwable->getMessage());
          $errors[] = 'Failed to save settings. Please try again.';
          $settings = $payload;
        }
      } else {
        $settings = $payload;
      }
    } elseif ($action === 'add_category') {
      $categoryName = trim((string) ($_POST['category_name'] ?? ''));
      if ($categoryName === '' || strlen($categoryName) > 100) {
        $errors[] = 'Category name is required and must be <= 100 chars.';
      }

      if ($errors === []) {
        $slugBase = categorySlug($categoryName);
        if ($slugBase === '') {
          $errors[] = 'Category name must include letters or numbers.';
        } else {
          $slug = $slugBase;
          $suffix = 2;
          while ($categoryRepo->slugExists($slug)) {
            $slug = $slugBase . '-' . $suffix;
            $suffix++;
          }

          $categoryRepo->create($categoryName, $slug);
          $success = 'Category created.';
        }
      }
    } elseif ($action === 'activate_category' || $action === 'deactivate_category') {
      $categoryId = (int) ($_POST['category_id'] ?? 0);
      if ($categoryId < 1) {
        $errors[] = 'Invalid category selected.';
      } elseif ($action === 'activate_category') {
        $categoryRepo->setActive($categoryId, true);
        $success = 'Category activated.';
      } else {
        $categoryRepo->setActive($categoryId, false);
        $success = 'Category deactivated.';
      }
    } elseif ($action === 'bulk_import_categories') {
      $file = $_FILES['categories_csv'] ?? null;
      if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $errors[] = 'Please select a valid CSV file for category import.';
      } else {
        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
          $errors[] = 'Uploaded file could not be verified.';
        } else {
          $handle = fopen($tmpName, 'rb');
          if ($handle === false) {
            $errors[] = 'Unable to read uploaded category CSV file.';
          } else {
            $header = fgetcsv($handle);
            if (!is_array($header)) {
              $errors[] = 'Category CSV file is empty.';
            } else {
              $normalizedHeader = array_map(
                static fn($column): string => strtolower(trim((string) $column)),
                $header
              );

              if (!in_array('name', $normalizedHeader, true)) {
                $errors[] = 'Category CSV header must include at least: name';
              }

              if ($errors === []) {
                $categoryMap = [];
                foreach ($categoryRepo->all() as $category) {
                  $slug = (string) ($category['slug'] ?? '');
                  if ($slug !== '') {
                    $categoryMap[$slug] = [
                      'id' => (int) ($category['id'] ?? 0),
                      'is_active' => (int) ($category['is_active'] ?? 0),
                    ];
                  }
                }

                $created = 0;
                $updated = 0;
                $skipped = 0;
                $rowNumber = 1;

                while (($row = fgetcsv($handle)) !== false) {
                  $rowNumber++;
                  if ($row === [null] || $row === []) {
                    continue;
                  }

                  $rowData = [];
                  foreach ($normalizedHeader as $index => $column) {
                    $rowData[$column] = trim((string) ($row[$index] ?? ''));
                  }

                  $name = (string) ($rowData['name'] ?? '');
                  if ($name === '' || strlen($name) > 100) {
                    $skipped++;
                    if (count($errors) < 5) {
                      $errors[] = 'Row ' . $rowNumber . ' skipped: name is required and must be <= 100 chars.';
                    }
                    continue;
                  }

                  $providedSlug = categorySlug((string) ($rowData['slug'] ?? ''));
                  $slugBase = $providedSlug !== '' ? $providedSlug : categorySlug($name);

                  if ($slugBase === '') {
                    $skipped++;
                    if (count($errors) < 5) {
                      $errors[] = 'Row ' . $rowNumber . ' skipped: invalid name/slug.';
                    }
                    continue;
                  }

                  $isActiveRaw = strtolower((string) ($rowData['is_active'] ?? '1'));
                  $isActive = in_array($isActiveRaw, ['1', 'true', 'yes', 'y', 'active'], true);

                  if (isset($categoryMap[$slugBase])) {
                    $categoryId = (int) ($categoryMap[$slugBase]['id'] ?? 0);
                    if ($categoryId > 0) {
                      $categoryRepo->setActive($categoryId, $isActive);
                      $updated++;
                      continue;
                    }
                  }

                  $slug = $slugBase;
                  $suffix = 2;
                  while (isset($categoryMap[$slug])) {
                    $slug = $slugBase . '-' . $suffix;
                    $suffix++;
                  }

                  $newId = $categoryRepo->create($name, $slug);
                  if ($newId > 0 && !$isActive) {
                    $categoryRepo->setActive($newId, false);
                  }

                  $categoryMap[$slug] = [
                    'id' => $newId,
                    'is_active' => $isActive ? 1 : 0,
                  ];
                  $created++;
                }

                $categoryImportSummary = [
                  'created' => $created,
                  'updated' => $updated,
                  'skipped' => $skipped,
                ];
                $success = 'Category CSV import finished.';
              }
            }

            fclose($handle);
          }
        }
      }
    }

    $categories = $categoryRepo->all();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Shop Settings</title>
  <script src="assets/vendor/tailwindcss/tailwindcss.js"></script>
  <style>
    body {
      font-family: 'Montserrat', 'Lato', 'Segoe UI', Tahoma, Arial, sans-serif;
      background:
        radial-gradient(circle at 15% 10%, rgba(34, 211, 238, 0.2), transparent 28%),
        radial-gradient(circle at 80% 90%, rgba(251, 113, 133, 0.14), transparent 25%),
        #070b14;
    }

    body[data-theme='light'] {
      background:
        radial-gradient(circle at 15% 10%, rgba(59, 130, 246, 0.2), transparent 28%),
        radial-gradient(circle at 80% 90%, rgba(255, 107, 53, 0.18), transparent 25%),
        #dbeafe;
      color: #1e40af;
    }

    body[data-theme='light'] .text-slate-100,
    body[data-theme='light'] .text-slate-200,
    body[data-theme='light'] .text-white {
      color: #0f172a !important;
    }

    body[data-theme='light'] .text-slate-300,
    body[data-theme='light'] .text-slate-400 {
      color: #1e293b !important;
    }

    body[data-theme='light'] .bg-slate-900\/60,
    body[data-theme='light'] .bg-slate-900\/50,
    body[data-theme='light'] .bg-slate-950\/60,
    body[data-theme='light'] .bg-slate-950\/40,
    body[data-theme='light'] .bg-white\/5 {
      background-color: rgba(255, 255, 255, 0.78) !important;
    }

    body[data-theme='light'] .border-white\/10,
    body[data-theme='light'] .border-white\/15,
    body[data-theme='light'] .border-white\/20 {
      border-color: rgba(15, 23, 42, 0.24) !important;
    }

    body[data-theme='light'] .rounded-3xl,
    body[data-theme='light'] .rounded-2xl,
    body[data-theme='light'] .rounded-xl {
      box-shadow: 0 10px 28px rgba(15, 23, 42, 0.08);
    }

    body[data-theme='light'] a.rounded-lg {
      color: #0f172a !important;
      background: rgba(255, 255, 255, 0.95);
      border-color: rgba(15, 23, 42, 0.22) !important;
    }

    body[data-theme='light'] a.rounded-lg:hover {
      background: rgba(241, 245, 249, 0.98) !important;
    }

    body[data-theme='light'] .bg-emerald-500\/10,
    body[data-theme='light'] .bg-cyan-500\/10,
    body[data-theme='light'] .bg-amber-500\/12,
    body[data-theme='light'] .bg-rose-500\/10 {
      background-color: rgba(248, 250, 252, 0.9) !important;
    }

    body[data-theme='light'] .text-emerald-100,
    body[data-theme='light'] .text-cyan-100,
    body[data-theme='light'] .text-amber-100,
    body[data-theme='light'] .text-amber-200,
    body[data-theme='light'] .text-rose-100,
    body[data-theme='light'] .text-cyan-200,
    body[data-theme='light'] .text-emerald-200,
    body[data-theme='light'] .text-rose-200 {
      color: #0f172a !important;
    }

    body[data-theme='light'] .bg-white\/5 {
      background-color: rgba(148, 163, 184, 0.18) !important;
    }

    body[data-theme='light'] select,
    body[data-theme='light'] input,
    body[data-theme='light'] button {
      color: #0f172a;
    }

    .icon-link {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
    }

    .nav-icon {
      width: 0.9rem;
      height: 0.9rem;
      opacity: 0.92;
      flex-shrink: 0;
    }

    .switcher-chip {
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
      border-radius: 0.6rem;
      border: 1px solid rgba(148, 163, 184, 0.35);
      background: rgba(15, 23, 42, 0.55);
      padding: 0.32rem 0.5rem;
      color: #f8fafc;
    }

    .switcher-icon {
      width: 0.95rem;
      height: 0.95rem;
      opacity: 0.9;
      flex-shrink: 0;
    }

    .switcher-select {
      min-width: 3.8rem;
      border-radius: 0.45rem;
      background: transparent;
      font-size: 0.72rem;
      font-weight: 700;
      text-transform: uppercase;
      outline: none;
      color: #f8fafc;
    }

    .switcher-label {
      font-size: 0.68rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      opacity: 0.88;
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

    body[data-theme='light'] .switcher-chip {
      border-color: rgba(15, 23, 42, 0.22);
      background: rgba(255, 255, 255, 0.96);
      color: #0f172a;
    }

    body[data-theme='light'] .switcher-select {
      color: #0f172a;
    }

    @media (max-width: 768px) {
      .switcher-label {
        display: none;
      }

      .switcher-chip {
        gap: 0.25rem;
        padding: 0.28rem 0.42rem;
      }

      .switcher-select {
        min-width: 3.2rem;
        font-size: 0.68rem;
      }
    }
  </style>
</head>
<body class="min-h-screen text-slate-100 antialiased">
  <main class="mx-auto max-w-4xl px-4 py-6 sm:px-6">
    <header class="mb-6 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 data-i18n="shopSettings" class="text-2xl font-semibold">Shop Settings</h1>
        <p id="signedInText" data-name="<?= e((string) $currentUser['full_name']) ?>" data-role="admin" class="text-sm text-slate-300">Signed in as <?= e((string) $currentUser['full_name']) ?> (admin)</p>
      </div>
      <div class="flex gap-2">
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
        <a href="index.php" class="rounded-lg border border-white/20 px-3 py-2 text-sm hover:bg-white/10 icon-link"><svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M3 10 10 4l7 6v7H3z"/></svg><span data-i18n="checkout">Checkout</span></a>
        <a href="manage_products.php" class="rounded-lg border border-white/20 px-3 py-2 text-sm hover:bg-white/10 icon-link"><svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M3 4h14v3H3zm0 5h14v3H3zm0 5h14v2H3z"/></svg><span data-i18n="manageProducts">Manage Products</span></a>
        <a href="manage_users.php" class="rounded-lg border border-white/20 px-3 py-2 text-sm hover:bg-white/10 icon-link"><svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 10a4 4 0 1 0-4-4 4 4 0 0 0 4 4zm0 2c-3.31 0-6 1.79-6 4v1h12v-1c0-2.21-2.69-4-6-4z"/></svg><span data-i18n="users">Users</span></a>
        <a href="audit_logs.php" class="rounded-lg border border-white/20 px-3 py-2 text-sm hover:bg-white/10 icon-link"><svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M5 3h10a1 1 0 0 1 1 1v12l-3-2-3 2-3-2-3 2V4a1 1 0 0 1 1-1z"/></svg><span data-i18n="audit">Audit</span></a>
        <a href="inventory_adjustments.php" class="rounded-lg border border-white/20 px-3 py-2 text-sm hover:bg-white/10 icon-link"><svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M3 4h14v3H3zm0 5h14v3H3zm0 5h14v2H3z"/></svg><span data-i18n="inventory">Inventory</span></a>
        <a href="add_product.php" class="rounded-lg border border-white/20 px-3 py-2 text-sm hover:bg-white/10 icon-link"><svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 3a1 1 0 0 1 1 1v5h5a1 1 0 1 1 0 2h-5v5a1 1 0 1 1-2 0v-5H4a1 1 0 1 1 0-2h5V4a1 1 0 0 1 1-1z"/></svg><span data-i18n="addProduct">Add Product</span></a>
        <a href="dashboard.php" class="rounded-lg border border-white/20 px-3 py-2 text-sm hover:bg-white/10 icon-link"><svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M3 3h6v6H3zm8 0h6v10h-6zM3 11h6v6H3zm8 4h6v2h-6z"/></svg><span data-i18n="dashboard">Dashboard</span></a>
      </div>
    </header>

    <?php if ($success !== null): ?>
      <div class="mb-4 rounded-xl border border-emerald-300/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-100"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if (($checkoutAuditGuard['blocked'] ?? false) === true): ?>
      <div class="mb-4 rounded-xl border border-amber-300/40 bg-amber-500/12 px-4 py-3 text-sm text-amber-100">
        <p class="font-semibold">Audit Checkout Guard: Active</p>
        <p class="mt-1"><?= e((string) ($checkoutAuditGuard['summary'] ?? '')) ?></p>
        <p class="mt-1 text-amber-200"><?= e((string) ($checkoutAuditGuard['fix'] ?? '')) ?></p>
      </div>
    <?php endif; ?>

    <?php if ($categoryImportSummary !== null): ?>
      <div class="mb-4 rounded-xl border border-cyan-300/30 bg-cyan-500/10 px-4 py-3 text-sm text-cyan-100">
        <span data-i18n="categoryImportSummary">Category import summary</span>: <span data-i18n="created">Created</span> <?= (int) $categoryImportSummary['created'] ?>, <span data-i18n="updated">Updated</span> <?= (int) $categoryImportSummary['updated'] ?>, <span data-i18n="skipped">Skipped</span> <?= (int) $categoryImportSummary['skipped'] ?>.
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

    <form method="post" class="space-y-5 rounded-3xl border border-white/10 bg-slate-900/60 p-5 shadow-2xl backdrop-blur-sm">
      <input type="hidden" name="action" value="save_settings" />
      <input type="hidden" name="csrf_token" value="<?= e((string) $_SESSION['csrf_token']) ?>" />

      <section>
        <h2 data-i18n="storeIdentity" class="mb-3 text-lg font-semibold">Store Identity</h2>
        <div class="grid gap-3 sm:grid-cols-2">
          <label class="text-sm"><span data-i18n="shopName">Shop Name</span>
            <input name="shop_name" value="<?= e((string) ($settings['shop_name'] ?? '')) ?>" required class="mt-1 w-full rounded-lg border border-white/15 bg-slate-950/60 px-3 py-2" />
          </label>
          <label class="text-sm"><span data-i18n="businessTagline">Business Tagline</span>
            <input name="business_tagline" value="<?= e((string) ($settings['business_tagline'] ?? '')) ?>" data-i18n-placeholder="optionalShortTagline" placeholder="Optional short tagline" class="mt-1 w-full rounded-lg border border-white/15 bg-slate-950/60 px-3 py-2" />
          </label>
          <label class="text-sm"><span data-i18n="taxId">Tax ID</span>
            <input name="shop_tax_id" value="<?= e((string) ($settings['shop_tax_id'] ?? '')) ?>" class="mt-1 w-full rounded-lg border border-white/15 bg-slate-950/60 px-3 py-2" />
          </label>
          <label class="text-sm sm:col-span-2"><span data-i18n="address">Address</span>
            <input name="shop_address" value="<?= e((string) ($settings['shop_address'] ?? '')) ?>" class="mt-1 w-full rounded-lg border border-white/15 bg-slate-950/60 px-3 py-2" />
          </label>
          <label class="text-sm sm:col-span-2"><span data-i18n="logoUrl">Logo URL</span>
            <input name="shop_logo_url" value="<?= e((string) ($settings['shop_logo_url'] ?? '')) ?>" data-i18n-placeholder="logoPlaceholder" placeholder="https://example.com/your-logo.png" class="mt-1 w-full rounded-lg border border-white/15 bg-slate-950/60 px-3 py-2" />
          </label>
          <label class="text-sm"><span data-i18n="phone">Phone</span>
            <input name="shop_phone" value="<?= e((string) ($settings['shop_phone'] ?? '')) ?>" class="mt-1 w-full rounded-lg border border-white/15 bg-slate-950/60 px-3 py-2" />
          </label>
          <?php if ((string) ($settings['shop_logo_url'] ?? '') !== ''): ?>
            <div class="text-sm">
              <span data-i18n="logoPreview" class="mb-1 block text-slate-300">Logo Preview</span>
              <img src="<?= e((string) $settings['shop_logo_url']) ?>" alt="Shop logo preview" class="h-16 w-auto rounded-lg border border-white/15 bg-white p-2" />
            </div>
          <?php endif; ?>
        </div>
      </section>

      <section>
        <h2 data-i18n="pricingTax" class="mb-3 text-lg font-semibold">Pricing & Tax</h2>
        <div class="grid gap-3 sm:grid-cols-3">
          <label class="text-sm"><span data-i18n="currencyCode">Currency Code</span>
            <?php
              $selectedCurrencyCode = strtoupper((string) ($settings['currency_code'] ?? 'USD'));
              if ($selectedCurrencyCode === 'GHS') {
                $selectedCurrencyCode = 'GHC';
              }
            ?>
            <select id="currencyCode" name="currency_code" required class="mt-1 w-full rounded-lg border border-white/15 bg-slate-950/60 px-3 py-2 uppercase">
              <?php foreach ($currencyOptions as $code => $symbol): ?>
                <option value="<?= e($code) ?>" data-symbol="<?= e($symbol) ?>" <?= $selectedCurrencyCode === $code ? 'selected' : '' ?>><?= e($code) ?> (<?= e($symbol) ?>)</option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="text-sm"><span data-i18n="currencySymbol">Currency Symbol</span>
            <input id="currencySymbol" name="currency_symbol" value="<?= e((string) ($settings['currency_symbol'] ?? '$')) ?>" readonly required class="mt-1 w-full rounded-lg border border-white/15 bg-slate-950/60 px-3 py-2" />
          </label>
          <label class="text-sm"><span data-i18n="taxRate">Tax Rate (%)</span>
            <input type="number" step="0.01" min="0" max="100" name="tax_rate_percent" value="<?= e((string) ($settings['tax_rate_percent'] ?? '8.00')) ?>" required class="mt-1 w-full rounded-lg border border-white/15 bg-slate-950/60 px-3 py-2" />
          </label>
        </div>
      </section>

      <section>
        <h2 data-i18n="receiptText" class="mb-3 text-lg font-semibold">Receipt Text</h2>
        <div class="grid gap-3 sm:grid-cols-2">
          <label class="text-sm"><span data-i18n="receiptHeader">Receipt Header</span>
            <input name="receipt_header" value="<?= e((string) ($settings['receipt_header'] ?? '')) ?>" class="mt-1 w-full rounded-lg border border-white/15 bg-slate-950/60 px-3 py-2" />
          </label>
          <label class="text-sm"><span data-i18n="receiptFooter">Receipt Footer</span>
            <input name="receipt_footer" value="<?= e((string) ($settings['receipt_footer'] ?? '')) ?>" class="mt-1 w-full rounded-lg border border-white/15 bg-slate-950/60 px-3 py-2" />
          </label>
        </div>
      </section>

      <section>
        <h2 data-i18n="themeAccents" class="mb-3 text-lg font-semibold">Theme Accents</h2>
        <div class="grid gap-3 sm:grid-cols-2">
          <label class="text-sm"><span data-i18n="primaryAccent">Primary Accent</span>
            <input type="color" name="theme_accent_primary" value="<?= e((string) ($settings['theme_accent_primary'] ?? '#06B6D4')) ?>" class="mt-1 h-10 w-full rounded-lg border border-white/15 bg-slate-950/60 px-2 py-1" />
          </label>
          <label class="text-sm"><span data-i18n="secondaryAccent">Secondary Accent</span>
            <input type="color" name="theme_accent_secondary" value="<?= e((string) ($settings['theme_accent_secondary'] ?? '#22D3AA')) ?>" class="mt-1 h-10 w-full rounded-lg border border-white/15 bg-slate-950/60 px-2 py-1" />
          </label>
        </div>
      </section>

      <section>
        <h2 class="mb-3 text-lg font-semibold">Email Configuration</h2>
        <div class="grid gap-3 sm:grid-cols-2">
          <label class="text-sm"><span>SMTP Host</span>
            <input name="smtp_host" value="<?= e((string) ($settings['smtp_host'] ?? '')) ?>" placeholder="smtp.gmail.com" class="mt-1 w-full rounded-lg border border-white/15 bg-slate-950/60 px-3 py-2" />
          </label>
          <label class="text-sm"><span>SMTP Port</span>
            <input type="number" name="smtp_port" value="<?= e((string) ($settings['smtp_port'] ?? '587')) ?>" class="mt-1 w-full rounded-lg border border-white/15 bg-slate-950/60 px-3 py-2" />
          </label>
          <label class="text-sm"><span>SMTP Username</span>
            <input name="smtp_username" value="<?= e((string) ($settings['smtp_username'] ?? '')) ?>" class="mt-1 w-full rounded-lg border border-white/15 bg-slate-950/60 px-3 py-2" />
          </label>
          <label class="text-sm"><span>SMTP Password</span>
            <input type="password" name="smtp_password" value="<?= e((string) ($settings['smtp_password'] ?? '')) ?>" class="mt-1 w-full rounded-lg border border-white/15 bg-slate-950/60 px-3 py-2" />
          </label>
          <label class="text-sm"><span>Encryption</span>
            <select name="smtp_encryption" class="mt-1 w-full rounded-lg border border-white/15 bg-slate-950/60 px-3 py-2">
              <option value="tls" <?= ((string) ($settings['smtp_encryption'] ?? 'tls')) === 'tls' ? 'selected' : '' ?>>TLS</option>
              <option value="ssl" <?= ((string) ($settings['smtp_encryption'] ?? 'tls')) === 'ssl' ? 'selected' : '' ?>>SSL</option>
              <option value="none" <?= ((string) ($settings['smtp_encryption'] ?? 'tls')) === 'none' ? 'selected' : '' ?>>None</option>
            </select>
          </label>
          <label class="text-sm"><span>From Email Address</span>
            <input type="email" name="email_from_address" value="<?= e((string) ($settings['email_from_address'] ?? '')) ?>" class="mt-1 w-full rounded-lg border border-white/15 bg-slate-950/60 px-3 py-2" />
          </label>
          <label class="text-sm sm:col-span-2"><span>From Name</span>
            <input name="email_from_name" value="<?= e((string) ($settings['email_from_name'] ?? '')) ?>" placeholder="Your Shop Name" class="mt-1 w-full rounded-lg border border-white/15 bg-slate-950/60 px-3 py-2" />
          </label>
        </div>
        <div class="mt-3">
          <a href="test_email.php" class="text-cyan-400 hover:text-cyan-300 text-sm">Test Email Configuration →</a>
        </div>
      </section>
        <h2 class="mb-3 text-lg font-semibold">Feature Toggles</h2>
        <div class="space-y-4">
          <div class="flex items-center justify-between rounded-lg border border-white/10 bg-slate-950/40 p-4">
            <span class="text-sm font-medium">Enable Discounts & Promotions</span>
            <label class="relative inline-flex items-center cursor-pointer">
              <input type="checkbox" name="enable_discounts" value="1" <?= ((bool) ($settings['enable_discounts'] ?? false)) ? 'checked' : '' ?> class="sr-only peer">
              <div class="w-11 h-6 bg-slate-600 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-cyan-300/20 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-gradient-to-r peer-checked:from-cyan-400 peer-checked:to-emerald-400"></div>
            </label>
          </div>
          <div class="flex items-center justify-between rounded-lg border border-white/10 bg-slate-950/40 p-4">
            <span class="text-sm font-medium">Enable Returns & Refunds System</span>
            <label class="relative inline-flex items-center cursor-pointer">
              <input type="checkbox" name="enable_returns" value="1" <?= ((bool) ($settings['enable_returns'] ?? false)) ? 'checked' : '' ?> class="sr-only peer">
              <div class="w-11 h-6 bg-slate-600 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-cyan-300/20 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-gradient-to-r peer-checked:from-cyan-400 peer-checked:to-emerald-400"></div>
            </label>
          </div>
          <div class="flex items-center justify-between rounded-lg border border-white/10 bg-slate-950/40 p-4">
            <span class="text-sm font-medium">Enable Multi-Store Support</span>
            <label class="relative inline-flex items-center cursor-pointer">
              <input type="checkbox" name="enable_multi_store" value="1" <?= ((bool) ($settings['enable_multi_store'] ?? false)) ? 'checked' : '' ?> class="sr-only peer">
              <div class="w-11 h-6 bg-slate-600 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-cyan-300/20 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-gradient-to-r peer-checked:from-cyan-400 peer-checked:to-emerald-400"></div>
            </label>
          </div>
          <div class="flex items-center justify-between rounded-lg border border-white/10 bg-slate-950/40 p-4">
            <span class="text-sm font-medium">Enable Time Clock & Employee Management</span>
            <label class="relative inline-flex items-center cursor-pointer">
              <input type="checkbox" name="enable_time_clock" value="1" <?= ((bool) ($settings['enable_time_clock'] ?? false)) ? 'checked' : '' ?> class="sr-only peer">
              <div class="w-11 h-6 bg-slate-600 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-cyan-300/20 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-gradient-to-r peer-checked:from-cyan-400 peer-checked:to-emerald-400"></div>
            </label>
          </div>
          <div class="flex items-center justify-between rounded-lg border border-white/10 bg-slate-950/40 p-4">
            <span class="text-sm font-medium">Enable Email Notifications</span>
            <label class="relative inline-flex items-center cursor-pointer">
              <input type="checkbox" name="enable_email_notifications" value="1" <?= ((bool) ($settings['enable_email_notifications'] ?? false)) ? 'checked' : '' ?> class="sr-only peer">
              <div class="w-11 h-6 bg-slate-600 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-cyan-300/20 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-gradient-to-r peer-checked:from-cyan-400 peer-checked:to-emerald-400"></div>
            </label>
          </div>
        </div>
      </section>

      <div class="pt-2">
        <button data-i18n="saveSettings" class="rounded-xl bg-gradient-to-r from-cyan-400 to-emerald-400 px-5 py-2 font-semibold text-slate-900">Save Settings</button>
      </div>
    </form>

    <section class="mt-5 space-y-4 rounded-3xl border border-white/10 bg-slate-900/60 p-5 shadow-2xl backdrop-blur-sm">
      <div>
        <h2 data-i18n="categorySetup" class="text-lg font-semibold">Category Setup</h2>
        <p data-i18n="categorySetupSub" class="text-sm text-slate-300">Add categories to match each store type and activate/deactivate as needed.</p>
      </div>

      <form method="post" class="flex flex-wrap items-end gap-3">
        <input type="hidden" name="csrf_token" value="<?= e((string) $_SESSION['csrf_token']) ?>" />
        <input type="hidden" name="action" value="add_category" />
        <label class="text-sm"><span data-i18n="newCategory">New Category</span>
          <input name="category_name" required maxlength="100" data-i18n-placeholder="newCategoryPlaceholder" placeholder="e.g. Pharmacy" class="mt-1 w-full rounded-lg border border-white/15 bg-slate-950/60 px-3 py-2" />
        </label>
        <button data-i18n="addCategory" class="rounded-xl border border-cyan-300/35 px-4 py-2 text-sm text-cyan-100 hover:bg-cyan-500/15">Add Category</button>
      </form>

      <form method="post" enctype="multipart/form-data" class="space-y-2 rounded-2xl border border-white/10 bg-slate-950/40 p-4">
        <input type="hidden" name="csrf_token" value="<?= e((string) $_SESSION['csrf_token']) ?>" />
        <input type="hidden" name="action" value="bulk_import_categories" />
        <div class="flex flex-wrap items-end gap-3">
          <label class="text-sm"><span data-i18n="bulkImportCategories">Bulk Import Categories (CSV)</span>
            <input type="file" name="categories_csv" accept=".csv,text/csv" required class="mt-1 block w-full rounded-lg border border-white/15 bg-slate-950/60 px-3 py-2 text-sm" />
          </label>
          <button data-i18n="importCsv" class="rounded-xl border border-cyan-300/35 px-4 py-2 text-sm text-cyan-100 hover:bg-cyan-500/15">Import CSV</button>
        </div>
        <p data-i18n="csvHelp" class="text-xs text-slate-400">CSV headers: name, slug, is_active (required: name). Existing slug rows are updated; new rows are created.</p>
      </form>

      <div class="overflow-x-auto rounded-2xl border border-white/10">
        <table class="min-w-full text-sm">
          <thead class="bg-white/5 text-slate-300">
            <tr>
              <th data-i18n="name" class="px-3 py-2 text-left">Name</th>
              <th data-i18n="slug" class="px-3 py-2 text-left">Slug</th>
              <th data-i18n="status" class="px-3 py-2 text-center">Status</th>
              <th data-i18n="action" class="px-3 py-2 text-right">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($categories === []): ?>
              <tr><td colspan="4" data-i18n="noCategoriesYet" class="px-3 py-3 text-slate-300">No categories yet.</td></tr>
            <?php else: ?>
              <?php foreach ($categories as $category): ?>
                <?php $active = (int) ($category['is_active'] ?? 0) === 1; ?>
                <tr class="border-t border-white/10">
                  <td class="px-3 py-2 text-white"><?= e((string) ($category['name'] ?? '')) ?></td>
                  <td class="px-3 py-2 text-slate-300"><?= e((string) ($category['slug'] ?? '')) ?></td>
                  <td class="px-3 py-2 text-center">
                    <span class="rounded-full px-2 py-1 text-xs <?= $active ? 'bg-emerald-500/20 text-emerald-200' : 'bg-slate-500/25 text-slate-300' ?>">
                      <span data-i18n="<?= $active ? 'active' : 'inactive' ?>"><?= $active ? 'Active' : 'Inactive' ?></span>
                    </span>
                  </td>
                  <td class="px-3 py-2 text-right">
                    <form method="post" class="inline">
                      <input type="hidden" name="csrf_token" value="<?= e((string) $_SESSION['csrf_token']) ?>" />
                      <input type="hidden" name="category_id" value="<?= (int) ($category['id'] ?? 0) ?>" />
                      <?php if ($active): ?>
                        <input type="hidden" name="action" value="deactivate_category" />
                        <button data-i18n="deactivate" class="rounded-lg border border-rose-300/35 px-2 py-1 text-xs text-rose-200 hover:bg-rose-500/15">Deactivate</button>
                      <?php else: ?>
                        <input type="hidden" name="action" value="activate_category" />
                        <button data-i18n="activate" class="rounded-lg border border-emerald-300/35 px-2 py-1 text-xs text-emerald-200 hover:bg-emerald-500/15">Activate</button>
                      <?php endif; ?>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </main>

  <script>
    (function () {
      const currencyCode = document.getElementById('currencyCode');
      const currencySymbol = document.getElementById('currencySymbol');
      const themeSwitch = document.getElementById('themeSwitch');
      const languageSwitch = document.getElementById('languageSwitch');
      const signedInText = document.getElementById('signedInText');
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
          signedInAs: 'Signed in as',
          shopSettings: 'Shop Settings',
          checkout: 'Checkout',
          manageProducts: 'Manage Products',
          users: 'Users',
          audit: 'Audit',
          inventory: 'Inventory',
          addProduct: 'Add Product',
          dashboard: 'Dashboard',
          categoryImportSummary: 'Category import summary',
          created: 'Created',
          updated: 'Updated',
          skipped: 'Skipped',
          storeIdentity: 'Store Identity',
          shopName: 'Shop Name',
          businessTagline: 'Business Tagline',
          optionalShortTagline: 'Optional short tagline',
          taxId: 'Tax ID',
          address: 'Address',
          logoUrl: 'Logo URL',
          logoPlaceholder: 'https://example.com/your-logo.png',
          phone: 'Phone',
          logoPreview: 'Logo Preview',
          pricingTax: 'Pricing & Tax',
          currencyCode: 'Currency Code',
          currencySymbol: 'Currency Symbol',
          taxRate: 'Tax Rate (%)',
          receiptText: 'Receipt Text',
          receiptHeader: 'Receipt Header',
          receiptFooter: 'Receipt Footer',
          themeAccents: 'Theme Accents',
          primaryAccent: 'Primary Accent',
          secondaryAccent: 'Secondary Accent',
          saveSettings: 'Save Settings',
          categorySetup: 'Category Setup',
          categorySetupSub: 'Add categories to match each store type and activate/deactivate as needed.',
          newCategory: 'New Category',
          newCategoryPlaceholder: 'e.g. Pharmacy',
          addCategory: 'Add Category',
          bulkImportCategories: 'Bulk Import Categories (CSV)',
          importCsv: 'Import CSV',
          csvHelp: 'CSV headers: name, slug, is_active (required: name). Existing slug rows are updated; new rows are created.',
          name: 'Name',
          slug: 'Slug',
          status: 'Status',
          action: 'Action',
          noCategoriesYet: 'No categories yet.',
          active: 'Active',
          inactive: 'Inactive',
          activate: 'Activate',
          deactivate: 'Deactivate',
        },
        fr: {
          theme: 'Theme',
          themeDark: 'Sombre',
          themeLight: 'Clair',
          language: 'Langue',
          signedInAs: 'Connecte en tant que',
          shopSettings: 'Parametres du magasin',
          checkout: 'Encaissement',
          manageProducts: 'Gestion produits',
          users: 'Utilisateurs',
          audit: 'Audit',
          inventory: 'Stock',
          addProduct: 'Ajouter produit',
          dashboard: 'Tableau de bord',
          categoryImportSummary: 'Resume import categories',
          created: 'Crees',
          updated: 'Mis a jour',
          skipped: 'Ignores',
          storeIdentity: 'Identite du magasin',
          shopName: 'Nom du magasin',
          businessTagline: 'Slogan',
          optionalShortTagline: 'Slogan court optionnel',
          taxId: 'ID fiscal',
          address: 'Adresse',
          logoUrl: 'URL du logo',
          logoPlaceholder: 'https://example.com/your-logo.png',
          phone: 'Telephone',
          logoPreview: 'Apercu du logo',
          pricingTax: 'Tarification et taxe',
          currencyCode: 'Code devise',
          currencySymbol: 'Symbole devise',
          taxRate: 'Taux de taxe (%)',
          receiptText: 'Texte du recu',
          receiptHeader: 'En-tete du recu',
          receiptFooter: 'Pied du recu',
          themeAccents: 'Accents de theme',
          primaryAccent: 'Accent principal',
          secondaryAccent: 'Accent secondaire',
          saveSettings: 'Enregistrer',
          categorySetup: 'Configuration categories',
          categorySetupSub: 'Ajoutez des categories pour chaque type de magasin et activez/desactivez selon vos besoins.',
          newCategory: 'Nouvelle categorie',
          newCategoryPlaceholder: 'ex. Pharmacie',
          addCategory: 'Ajouter categorie',
          bulkImportCategories: 'Import categories (CSV)',
          importCsv: 'Importer CSV',
          csvHelp: 'Entetes CSV: name, slug, is_active (name requis). Les slugs existants sont mis a jour; les nouveaux sont crees.',
          name: 'Nom',
          slug: 'Slug',
          status: 'Statut',
          action: 'Action',
          noCategoriesYet: 'Aucune categorie pour le moment.',
          active: 'Actif',
          inactive: 'Inactif',
          activate: 'Activer',
          deactivate: 'Desactiver',
        },
        tw: {
          language: 'Kasa',
          signedInAs: 'Wɔahyɛ mu sɛ',
          shopSettings: 'Adan Nhyɛso',
          saveSettings: 'Sie Nhyɛso',
        },
        gaa: {
          language: 'Mli',
          signedInAs: 'Oyi mli he',
          shopSettings: 'Shop Nitsumɔ',
          saveSettings: 'Save Nitsumɔ',
        },
        ee: {
          language: 'Gbe',
          signedInAs: 'Le geɖe eme abe',
          shopSettings: 'Shop ƒe ɖoɖowo',
          saveSettings: 'Dzra ɖoɖowo ɖe asi',
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
        return `novapos_remote_i18n_settings_${langCode}`;
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

        if (signedInText) {
          const name = signedInText.getAttribute('data-name') || '';
          const role = signedInText.getAttribute('data-role') || '';
          signedInText.textContent = `${t('signedInAs')} ${name} (${role})`;
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

      let currentLanguage = 'en';

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

      if (!currencyCode || !currencySymbol) {
        return;
      }

      function syncSymbol() {
        const selected = currencyCode.options[currencyCode.selectedIndex];
        const symbol = selected ? selected.getAttribute('data-symbol') : '';
        currencySymbol.value = symbol || '';
      }

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

      currencyCode.addEventListener('change', syncSymbol);
      syncSymbol();
      loadThemePreference();
      loadLanguagePreference();
    })();
  </script>
  <?php require __DIR__ . '/app/Views/partials/support-card.php'; ?>
</body>
</html>

