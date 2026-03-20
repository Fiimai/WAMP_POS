<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Core\Auth;
use App\Controllers\ProductController;
use App\Models\ShopSettings;

$currentUser = Auth::requirePageAuth(['admin', 'manager', 'cashier']);
$shopSettings = ShopSettings::get();
$shopName = (string) ($shopSettings['shop_name'] ?? 'My Shop');
$currencySymbol = (string) ($shopSettings['currency_symbol'] ?? '$');
$taxRatePercent = (float) ($shopSettings['tax_rate_percent'] ?? 8.0);
$themePrimary = (string) ($shopSettings['theme_accent_primary'] ?? '#06B6D4');
$themeSecondary = (string) ($shopSettings['theme_accent_secondary'] ?? '#22D3AA');

$search = trim((string) ($_GET['q'] ?? ''));
$products = [];
$databaseError = null;

try {
  $productController = new ProductController();
  $products = $productController->index($search === '' ? null : $search);
} catch (Throwable $exception) {
  error_log('checkout page DB failure: ' . $exception->getMessage());
  $databaseError = 'Database unavailable';
}

$productCount = count($products);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="<?= e((string) $_SESSION['csrf_token']) ?>" />
  <meta name="currency-symbol" content="<?= e($currencySymbol) ?>" />
  <meta name="tax-rate-percent" content="<?= e((string) $taxRatePercent) ?>" />
  <title><?= e($shopName) ?> POS Checkout</title>

  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Sora:wght@500;600;700&display=swap" rel="stylesheet" />

  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: {
            sans: ['Space Grotesk', 'ui-sans-serif', 'system-ui'],
            display: ['Sora', 'ui-sans-serif', 'system-ui']
          },
          colors: {
            night: '#070B14',
            panel: '#0E1726',
            electric: '#06B6D4',
            mint: '#22D3AA',
            ember: '#FB7185'
          },
          boxShadow: {
            glow: '0 0 0 1px rgba(34, 211, 170, 0.28), 0 25px 50px -12px rgba(6, 182, 212, 0.32)',
            card: '0 10px 35px rgba(2, 6, 23, 0.45)'
          },
          keyframes: {
            rise: {
              '0%': { opacity: '0', transform: 'translateY(16px)' },
              '100%': { opacity: '1', transform: 'translateY(0)' }
            }
          },
          animation: {
            rise: 'rise 650ms ease-out both'
          }
        }
      }
    };
  </script>

  <style>
    body {
      --shop-accent-primary: <?= e($themePrimary) ?>;
      --shop-accent-secondary: <?= e($themeSecondary) ?>;
      background:
        radial-gradient(circle at 12% 15%, rgba(6, 182, 212, 0.18), transparent 30%),
        radial-gradient(circle at 80% 8%, rgba(34, 211, 170, 0.14), transparent 26%),
        radial-gradient(circle at 84% 88%, rgba(251, 113, 133, 0.16), transparent 26%),
        #070b14;
      min-height: 100vh;
    }

    .glass {
      background: linear-gradient(145deg, rgba(255, 255, 255, 0.1), rgba(255, 255, 255, 0.04));
      backdrop-filter: blur(18px);
      -webkit-backdrop-filter: blur(18px);
      border: 1px solid rgba(255, 255, 255, 0.14);
    }

    .scrollbar-thin::-webkit-scrollbar {
      width: 8px;
    }

    .scrollbar-thin::-webkit-scrollbar-thumb {
      background: rgba(148, 163, 184, 0.45);
      border-radius: 999px;
    }

    #scanStatus {
      transition: opacity 180ms ease, transform 180ms ease;
    }

    #scanStatus.hidden {
      opacity: 0;
      transform: translateY(-6px);
      pointer-events: none;
    }

    .shop-gradient-btn {
      background: linear-gradient(90deg, var(--shop-accent-primary), var(--shop-accent-secondary));
      box-shadow: 0 10px 30px rgba(6, 182, 212, 0.25);
    }

    .scanner-toggle {
      border-color: color-mix(in srgb, var(--shop-accent-primary) 35%, white);
      background-color: color-mix(in srgb, var(--shop-accent-primary) 18%, transparent);
      color: #cffafe;
    }

    .scanner-toggle-muted {
      border-color: rgba(148, 163, 184, 0.35);
      background-color: rgba(100, 116, 139, 0.18);
      color: #e2e8f0;
    }

    @keyframes cart-pop {
      0% {
        transform: scale(1);
      }
      50% {
        transform: scale(1.18);
      }
      100% {
        transform: scale(1);
      }
    }

    .cart-pop {
      animation: cart-pop 200ms ease-out;
    }
  </style>
</head>
<body class="text-slate-100 antialiased">
  <button
    id="scannerSoundToggle"
    type="button"
    class="scanner-toggle fixed right-4 top-4 z-50 rounded-xl border px-3 py-2 text-xs font-semibold shadow-lg transition hover:brightness-110 sm:right-6 sm:top-6"
    aria-pressed="true"
  >
    Scanner Sound: On
  </button>

  <div id="scanStatus" class="hidden fixed left-4 top-4 z-50 rounded-xl border px-3 py-2 text-xs font-semibold shadow-lg sm:left-6 sm:top-6">
    Scanner mode active
  </div>

  <main class="mx-auto max-w-[1600px] px-4 py-4 sm:px-6 sm:py-6 lg:px-8 lg:py-8">
    <div class="mb-3 flex items-center justify-end gap-3 text-xs text-slate-300">
      <span>Signed in as <?= e((string) $currentUser['full_name']) ?> (<?= e((string) $currentUser['role']) ?>)</span>
      <?php if ((string) $currentUser['role'] === 'admin'): ?>
        <a href="add_product.php" class="rounded-lg border border-white/20 px-2 py-1 hover:bg-white/10">Add Product</a>
        <a href="manage_users.php" class="rounded-lg border border-white/20 px-2 py-1 hover:bg-white/10">Users</a>
        <a href="audit_logs.php" class="rounded-lg border border-white/20 px-2 py-1 hover:bg-white/10">Audit</a>
        <a href="settings.php" class="rounded-lg border border-white/20 px-2 py-1 hover:bg-white/10">Settings</a>
      <?php endif; ?>
      <?php if (in_array((string) $currentUser['role'], ['admin', 'manager'], true)): ?>
        <a href="inventory_adjustments.php" class="rounded-lg border border-white/20 px-2 py-1 hover:bg-white/10">Inventory</a>
      <?php endif; ?>
      <a href="receipt_history.php" class="rounded-lg border border-white/20 px-2 py-1 hover:bg-white/10">Receipts</a>
      <a href="logout.php" class="rounded-lg border border-white/20 px-2 py-1 hover:bg-white/10">Sign out</a>
    </div>

    <?php if ($databaseError !== null): ?>
      <div class="mb-4 rounded-2xl border border-amber-400/25 bg-amber-500/10 px-4 py-3 text-sm text-amber-100">
        Database connection is not ready. Configure DB credentials, import schema, and refresh.
      </div>
    <?php endif; ?>

    <?php require __DIR__ . '/app/Views/partials/header.php'; ?>

    <section class="grid gap-5 lg:grid-cols-[minmax(0,2fr)_minmax(340px,1fr)]">
      <div class="animate-rise [animation-delay:120ms]">
        <?php require __DIR__ . '/app/Views/partials/product-grid.php'; ?>
      </div>

      <?php require __DIR__ . '/app/Views/partials/cart-sidebar.php'; ?>
    </section>
  </main>

  <script src="assets/js/cart.js"></script>
  <script src="assets/js/checkout.js"></script>
  <script>
    const cartItemsContainer = document.getElementById('cartItems');
    const subtotalEl = document.getElementById('subtotal');
    const taxEl = document.getElementById('tax');
    const totalEl = document.getElementById('total');
    const clearCartBtn = document.getElementById('clearCart');
    const checkoutBtn = document.getElementById('checkoutBtn');
    const searchForm = document.getElementById('searchForm');
    const searchInput = document.getElementById('searchInput');
    const searchSpinner = document.getElementById('searchSpinner');
    const productGrid = document.getElementById('productGrid');
    const productCountEl = document.getElementById('productCount');
    const scanStatusEl = document.getElementById('scanStatus');
    const scannerSoundToggle = document.getElementById('scannerSoundToggle');
    const cartIndicator = document.getElementById('cartIndicator');
    const taxLabelEl = document.getElementById('taxLabel');
    const csrfTokenMeta = document.querySelector('meta[name="csrf-token"]');
    const currencySymbolMeta = document.querySelector('meta[name="currency-symbol"]');
    const taxRatePercentMeta = document.querySelector('meta[name="tax-rate-percent"]');
    let latestSearchResults = [];
    let lastKeypressAt = 0;
    let fastKeyStreak = 0;
    let scannerBuffer = '';
    let scannerLastKeyAt = 0;
    let scannerClearTimer = null;
    let scanStatusTimer = null;
    let audioContext = null;
    let scannerSoundEnabled = true;

    const BARCODE_MAX_KEY_INTERVAL_MS = 45;
    const BARCODE_MIN_LENGTH = 6;
    const SCANNER_IDLE_RESET_MS = 120;
    const SCANNER_SOUND_PREF_KEY = 'novapos_scanner_sound_enabled';
    const CSRF_TOKEN = csrfTokenMeta ? csrfTokenMeta.getAttribute('content') : '';
    const CURRENCY_SYMBOL = currencySymbolMeta ? currencySymbolMeta.getAttribute('content') : '$';
    const DEFAULT_TAX_RATE_PERCENT = taxRatePercentMeta ? Number(taxRatePercentMeta.getAttribute('content')) : 8;

    const productImages = [
      'https://images.unsplash.com/photo-1583394838336-acd977736f90?auto=format&fit=crop&w=600&q=80',
      'https://images.unsplash.com/photo-1542838132-92c53300491e?auto=format&fit=crop&w=600&q=80',
      'https://images.unsplash.com/photo-1585060544812-6b45742d762f?auto=format&fit=crop&w=600&q=80',
      'https://images.unsplash.com/photo-1523293182086-7651a899d37f?auto=format&fit=crop&w=600&q=80',
      'https://images.unsplash.com/photo-1572635196237-14b3f281503f?auto=format&fit=crop&w=600&q=80',
      'https://images.unsplash.com/photo-1611930022073-b7a4ba5fcccd?auto=format&fit=crop&w=600&q=80'
    ];

    function formatMoney(value) {
      return `${CURRENCY_SYMBOL}${Number(value).toFixed(2)}`;
    }

    function escapeHtml(value) {
      return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
    }

    function setSearchLoading(isLoading) {
      if (!searchSpinner) {
        return;
      }

      if (isLoading) {
        searchSpinner.classList.remove('hidden');
      } else {
        searchSpinner.classList.add('hidden');
      }
    }

    function getAudioContext() {
      const AudioCtx = window.AudioContext || window.webkitAudioContext;
      if (!AudioCtx) {
        return null;
      }

      if (!audioContext) {
        audioContext = new AudioCtx();
      }

      return audioContext;
    }

    function updateScannerSoundToggleUI() {
      if (!scannerSoundToggle) {
        return;
      }

      scannerSoundToggle.textContent = scannerSoundEnabled ? 'Scanner Sound: On' : 'Scanner Sound: Muted';
      scannerSoundToggle.setAttribute('aria-pressed', scannerSoundEnabled ? 'true' : 'false');

      if (scannerSoundEnabled) {
        scannerSoundToggle.classList.remove('scanner-toggle-muted');
      } else {
        scannerSoundToggle.classList.add('scanner-toggle-muted');
      }
    }

    function loadScannerSoundPreference() {
      try {
        const saved = localStorage.getItem(SCANNER_SOUND_PREF_KEY);
        if (saved !== null) {
          scannerSoundEnabled = saved === '1';
        }
      } catch (error) {
        scannerSoundEnabled = true;
      }

      updateScannerSoundToggleUI();
    }

    function setScannerSoundEnabled(enabled) {
      scannerSoundEnabled = Boolean(enabled);

      try {
        localStorage.setItem(SCANNER_SOUND_PREF_KEY, scannerSoundEnabled ? '1' : '0');
      } catch (error) {
      }

      updateScannerSoundToggleUI();
    }

    function playScanTone(type) {
      if (!scannerSoundEnabled) {
        return;
      }

      const ctx = getAudioContext();
      if (!ctx) {
        return;
      }

      if (ctx.state === 'suspended') {
        ctx.resume().catch(() => {});
      }

      const now = ctx.currentTime;
      const oscillator = ctx.createOscillator();
      const gainNode = ctx.createGain();

      const isSuccess = type === 'success';
      oscillator.type = isSuccess ? 'sine' : 'triangle';
      oscillator.frequency.setValueAtTime(isSuccess ? 1046 : 220, now);

      gainNode.gain.setValueAtTime(0.0001, now);
      gainNode.gain.exponentialRampToValueAtTime(isSuccess ? 0.045 : 0.05, now + 0.015);
      gainNode.gain.exponentialRampToValueAtTime(0.0001, now + (isSuccess ? 0.09 : 0.14));

      oscillator.connect(gainNode);
      gainNode.connect(ctx.destination);

      oscillator.start(now);
      oscillator.stop(now + (isSuccess ? 0.1 : 0.15));
    }

    function showScanStatus(type, message) {
      if (!scanStatusEl) {
        return;
      }

      if (scanStatusTimer !== null) {
        clearTimeout(scanStatusTimer);
      }

      const successClasses = ['border-emerald-300/35', 'bg-emerald-500/20', 'text-emerald-100'];
      const errorClasses = ['border-rose-300/35', 'bg-rose-500/20', 'text-rose-100'];

      scanStatusEl.classList.remove(...successClasses, ...errorClasses, 'hidden');

      if (type === 'success') {
        scanStatusEl.classList.add(...successClasses);
      } else {
        scanStatusEl.classList.add(...errorClasses);
      }

      scanStatusEl.textContent = message;
      playScanTone(type);

      scanStatusTimer = setTimeout(() => {
        scanStatusEl.classList.add('hidden');
      }, 1400);
    }

    function renderProducts(products) {
      if (!productGrid) {
        return;
      }

      if (!Array.isArray(products) || products.length === 0) {
        productGrid.innerHTML = `
          <div class="col-span-full rounded-2xl border border-dashed border-white/20 bg-slate-900/30 p-8 text-center text-slate-300">
            No results found. Try a different name or barcode.
          </div>
        `;

        if (productCountEl) {
          productCountEl.textContent = '0 Items';
        }

        return;
      }

      productGrid.innerHTML = products.map((product, index) => {
        const stock = Number(product.stock_qty || 0);
        const disabledAttr = stock < 1 ? 'disabled' : '';
        const buttonLabel = stock < 1 ? 'Out' : 'Add';
        const image = productImages[index % productImages.length];

        return `
          <article class="group rounded-2xl border border-white/10 bg-slate-950/45 p-2 shadow-card transition hover:-translate-y-0.5 hover:border-cyan-300/35 hover:bg-slate-900/65">
            <img src="${image}" alt="${escapeHtml(product.name)}" class="h-28 w-full rounded-xl object-cover sm:h-32" />
            <div class="p-2">
              <h3 class="truncate text-sm font-semibold text-white">${escapeHtml(product.name)}</h3>
              <p class="mt-1 text-xs text-slate-400">${escapeHtml(product.category_name)}</p>
              <div class="mt-3 flex items-center justify-between gap-2">
                <div>
                  <p class="text-sm font-semibold text-mint">${formatMoney(product.unit_price)}</p>
                  <p class="text-[11px] text-slate-400">Stock: ${stock}</p>
                  ${stock > 0 && stock < 5 ? '<span class="mt-1 inline-flex rounded-full border border-rose-300/40 bg-rose-500/20 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-rose-100">Low stock</span>' : ''}
                </div>
                <button class="add-to-cart rounded-lg bg-cyan-500/20 px-2 py-1 text-xs font-semibold text-cyan-100 hover:bg-cyan-500/30 disabled:cursor-not-allowed disabled:opacity-40" data-product-id="${Number(product.id)}" ${disabledAttr}>
                  ${buttonLabel}
                </button>
              </div>
            </div>
          </article>
        `;
      }).join('');

      if (productCountEl) {
        productCountEl.textContent = `${products.length} Items`;
      }
    }

    function toast(message, type = 'success') {
      if (window.POSCartUX && typeof window.POSCartUX.showToast === 'function') {
        window.POSCartUX.showToast(message, type);
        return;
      }

      if (type === 'error') {
        alert(message);
      } else {
        console.info(message);
      }
    }

    function popCartIndicator() {
      if (window.POSCartUX && typeof window.POSCartUX.bounceCartIndicator === 'function') {
        window.POSCartUX.bounceCartIndicator(cartIndicator);
        return;
      }

      if (!cartIndicator) {
        return;
      }

      cartIndicator.classList.remove('cart-pop');
      void cartIndicator.offsetWidth;
      cartIndicator.classList.add('cart-pop');
    }

    function renderCart(cart) {
      const items = Array.isArray(cart.items) ? cart.items : [];

      if (!items.length) {
        if (window.POSCartUX && typeof window.POSCartUX.emptyStateMarkup === 'function') {
          cartItemsContainer.innerHTML = window.POSCartUX.emptyStateMarkup();
        } else {
          cartItemsContainer.innerHTML = `
            <div class="rounded-xl border border-dashed border-white/20 bg-slate-900/30 p-4 text-center text-sm text-slate-400">
              Your cart is empty. Add products to begin checkout.
            </div>
          `;
        }
      } else {
        cartItemsContainer.innerHTML = items.map((item) => `
          <div class="rounded-xl border border-white/10 bg-slate-900/45 p-3">
            <div class="flex items-start justify-between gap-3">
              <div>
                <h4 class="text-sm font-medium text-white">${item.name}</h4>
                <p class="text-xs text-slate-400">Qty ${item.qty}</p>
              </div>
              <div class="text-right">
                <p class="text-sm font-semibold text-mint">${formatMoney(item.line_total)}</p>
                <button class="remove-item mt-1 text-[11px] text-rose-300 hover:text-rose-200" data-product-id="${item.product_id}">Remove</button>
              </div>
            </div>
          </div>
        `).join('');
      }

      subtotalEl.textContent = formatMoney(cart.subtotal || 0);
      taxEl.textContent = formatMoney(cart.tax || 0);
      totalEl.textContent = formatMoney(cart.total || 0);

      const taxRate = Number(cart.tax_rate_percent ?? DEFAULT_TAX_RATE_PERCENT);
      if (taxLabelEl) {
        taxLabelEl.textContent = `Tax (${taxRate.toFixed(2)}%)`;
      }

      document.querySelectorAll('.remove-item').forEach((button) => {
        button.addEventListener('click', async () => {
          await removeFromCart(Number(button.dataset.productId));
        });
      });
    }

    async function requestCart(url, payload = null) {
      const options = payload === null
        ? { method: 'GET' }
        : {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-Token': CSRF_TOKEN
            },
            body: JSON.stringify(payload)
          };

      const response = await fetch(url, options);
      const data = await response.json();

      if (response.status === 429) {
        const retryAfter = response.headers.get('Retry-After');
        throw new Error(retryAfter ? `Too many requests. Retry in ${retryAfter}s.` : 'Too many requests. Please wait and try again.');
      }

      if (response.status === 401 || response.status === 403) {
        window.location.href = 'login.php';
        throw new Error('Authentication required');
      }

      if (!response.ok || data.success !== true) {
        throw new Error(data.message || 'Request failed');
      }

      return data.data;
    }

    async function requestJson(url, payload) {
      const response = await fetch(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': CSRF_TOKEN
        },
        body: JSON.stringify(payload || {})
      });

      const data = await response.json();

      if (response.status === 429) {
        const retryAfter = response.headers.get('Retry-After');
        throw new Error(retryAfter ? `Too many requests. Retry in ${retryAfter}s.` : 'Too many requests. Please wait and try again.');
      }

      if (response.status === 401 || response.status === 403) {
        window.location.href = 'login.php';
        throw new Error('Authentication required');
      }

      if (!response.ok || data.success !== true) {
        throw new Error(data.message || 'Request failed');
      }

      return data;
    }

    async function loadCart() {
      try {
        const cart = await requestCart('./api/cart.php');
        renderCart(cart);
      } catch (error) {
        console.error(error);
      }
    }

    async function addToCart(productId) {
      try {
        let cart;

        if (window.POSCartUX && typeof window.POSCartUX.addToCart === 'function') {
          const response = await window.POSCartUX.addToCart(productId, 1, {
            endpoint: './api/add_to_cart.php',
            csrfToken: CSRF_TOKEN,
          });

          cart = {
            items: Array.isArray(response.cart) ? response.cart.map((item) => ({
              product_id: Number(item.product_id || 0),
              name: item.name || '',
              price: Number(item.price || 0),
              qty: Number(item.qty || 0),
              line_total: Number(item.price || 0) * Number(item.qty || 0),
            })) : [],
            subtotal: Number(response.summary?.subtotal || 0),
            tax: Number(response.summary?.tax || 0),
            total: Number(response.summary?.total || 0),
            tax_rate_percent: Number(response.summary?.tax_rate_percent || DEFAULT_TAX_RATE_PERCENT),
          };

          toast(response.message || 'Product added to cart');
        } else {
          cart = await requestCart('./api/cart.php?action=add', { product_id: productId, qty: 1 });
          toast('Product added to cart');
        }

        renderCart(cart);
        popCartIndicator();
        return true;
      } catch (error) {
        toast(error.message, 'error');
        return false;
      }
    }

    async function removeFromCart(productId) {
      try {
        const cart = await requestCart('./api/cart.php?action=remove', { product_id: productId });
        renderCart(cart);
      } catch (error) {
        toast(error.message, 'error');
      }
    }

    async function clearCart() {
      try {
        const cart = await requestCart('./api/cart.php?action=clear', {});
        renderCart(cart);
        toast('Cart cleared');
      } catch (error) {
        toast(error.message, 'error');
      }
    }

    async function checkoutCart() {
      if (!window.POSCheckout || typeof window.POSCheckout.processCheckout !== 'function') {
        return;
      }

      await window.POSCheckout.processCheckout({
        endpoint: './api/checkout.php',
        csrfToken: CSRF_TOKEN,
        paymentMethod: 'cash',
        confirmMessage: 'Confirm checkout and process payment?',
        onStart: () => {
          if (checkoutBtn) {
            checkoutBtn.disabled = true;
            checkoutBtn.textContent = 'Processing...';
          }
        },
        onSuccess: async (response) => {
          await loadCart();
          await searchProducts((searchInput && searchInput.value ? searchInput.value : '').trim());
          const receiptNo = response?.data?.receipt_no || 'N/A';
          const saleId = Number(response?.data?.sale_id || 0);

          if (saleId > 0) {
            const receiptUrl = `receipt.php?sale_id=${saleId}&print=1`;
            const receiptWindow = window.open(receiptUrl, '_blank', 'noopener,noreferrer,width=420,height=760');

            if (!receiptWindow) {
              toast('Sale completed. Pop-up blocked; open receipt from dashboard.', 'error');
            }
          }

          toast(`Success! Receipt: ${receiptNo}`);
        },
        onError: (message) => {
          toast(message, 'error');
        },
        onFinish: () => {
          if (checkoutBtn) {
            checkoutBtn.disabled = false;
            checkoutBtn.textContent = 'Checkout';
          }
        }
      });
    }

    async function searchProducts(term) {
      setSearchLoading(true);

      try {
        const response = await fetch(`./api/search_product.php?query=${encodeURIComponent(term)}`);
        const payload = await response.json();

        if (response.status === 429) {
          const retryAfter = response.headers.get('Retry-After');
          throw new Error(retryAfter ? `Search rate limit reached. Retry in ${retryAfter}s.` : 'Search rate limit reached.');
        }

        if (response.status === 401 || response.status === 403) {
          window.location.href = 'login.php';
          throw new Error('Authentication required');
        }

        if (!response.ok || payload.success !== true) {
          throw new Error(payload.message || 'Search failed');
        }

        latestSearchResults = Array.isArray(payload.data) ? payload.data : [];
        renderProducts(latestSearchResults);
        return latestSearchResults;
      } catch (error) {
        latestSearchResults = [];

        if (productGrid) {
          productGrid.innerHTML = `
            <div class="col-span-full rounded-2xl border border-rose-400/20 bg-rose-500/10 p-8 text-center text-rose-100">
              Could not load product results. Please try again.
            </div>
          `;
        }

        if (productCountEl) {
          productCountEl.textContent = '0 Items';
        }

        console.error(error);
        return [];
      } finally {
        setSearchLoading(false);
      }
    }

    function getFirstInStockResult() {
      if (!Array.isArray(latestSearchResults)) {
        return null;
      }

      return latestSearchResults.find((product) => Number(product.stock_qty || 0) > 0) || null;
    }

    async function addFirstResultToCart() {
      const first = getFirstInStockResult();
      if (!first) {
        return false;
      }

      return addToCart(Number(first.id));
    }

    function updateBarcodeCadence(key) {
      const isPrintable = key.length === 1;
      const now = performance.now();

      if (!isPrintable) {
        return;
      }

      if (lastKeypressAt === 0) {
        fastKeyStreak = 1;
      } else {
        const delta = now - lastKeypressAt;
        fastKeyStreak = delta <= BARCODE_MAX_KEY_INTERVAL_MS ? fastKeyStreak + 1 : 1;
      }

      lastKeypressAt = now;
    }

    function isLikelyBarcode(term) {
      const sinceLastKey = performance.now() - lastKeypressAt;
      return term.length >= BARCODE_MIN_LENGTH && fastKeyStreak >= 4 && sinceLastKey <= 180;
    }

    function shouldIgnoreGlobalCapture(target) {
      if (!target) {
        return false;
      }

      if (target === searchInput) {
        return true;
      }

      if (target.isContentEditable) {
        return true;
      }

      const tagName = (target.tagName || '').toLowerCase();
      return tagName === 'input' || tagName === 'textarea' || tagName === 'select';
    }

    function scheduleScannerBufferReset() {
      if (scannerClearTimer !== null) {
        clearTimeout(scannerClearTimer);
      }

      scannerClearTimer = setTimeout(() => {
        scannerBuffer = '';
        scannerLastKeyAt = 0;
      }, SCANNER_IDLE_RESET_MS);
    }

    async function processGlobalScanBuffer(term) {
      if (!searchInput) {
        return;
      }

      searchInput.value = term;
      await searchProducts(term);

      let added = await addFirstResultToCart();
      if (!added) {
        await searchProducts(term);
        added = await addFirstResultToCart();
      }

      if (added) {
        showScanStatus('success', `Scanned and added: ${term}`);
        searchInput.value = '';
        latestSearchResults = [];
        fastKeyStreak = 0;
        await searchProducts('');
      } else {
        showScanStatus('error', `No matching in-stock product for: ${term}`);
      }
    }

    function debounce(fn, delayMs) {
      let timeoutId;

      return (...args) => {
        clearTimeout(timeoutId);
        timeoutId = setTimeout(() => fn(...args), delayMs);
      };
    }

    const debouncedSearch = debounce((term) => {
      searchProducts(term);
    }, 250);

    if (productGrid) {
      productGrid.addEventListener('click', async (event) => {
        const button = event.target.closest('.add-to-cart');
        if (!button) {
          return;
        }

        if (button.hasAttribute('disabled')) {
          return;
        }

        await addToCart(Number(button.dataset.productId));
      });
    }

    if (searchInput) {
      searchInput.addEventListener('input', (event) => {
        const term = event.target.value || '';
        debouncedSearch(term.trim());
      });

      searchInput.addEventListener('keydown', async (event) => {
        updateBarcodeCadence(event.key);

        if (event.key === 'Backspace' || event.key === 'Delete') {
          fastKeyStreak = 0;
        }

        if (event.key !== 'Enter') {
          return;
        }

        event.preventDefault();

        const term = (searchInput.value || '').trim();
        if (term === '') {
          return;
        }

        const likelyBarcode = isLikelyBarcode(term);

        if (likelyBarcode) {
          await searchProducts(term);
        }

        let added = await addFirstResultToCart();

        if (!added) {
          await searchProducts(term);
          added = await addFirstResultToCart();
        }

        if (added && likelyBarcode) {
          showScanStatus('success', `Scanned and added: ${term}`);
          searchInput.value = '';
          latestSearchResults = [];
          fastKeyStreak = 0;
          await searchProducts('');
        } else if (!added && likelyBarcode) {
          showScanStatus('error', `No matching in-stock product for: ${term}`);
        }
      });
    }

    document.addEventListener('keydown', async (event) => {
      if (event.key === 'F2') {
        event.preventDefault();
        if (searchInput) {
          searchInput.focus();
          searchInput.select();
        }
        return;
      }

      if (event.key === 'F9') {
        event.preventDefault();
        await checkoutCart();
        return;
      }

      if (event.defaultPrevented || event.ctrlKey || event.metaKey || event.altKey) {
        return;
      }

      if (shouldIgnoreGlobalCapture(event.target)) {
        return;
      }

      if (event.key.length === 1) {
        const now = performance.now();
        const delta = scannerLastKeyAt === 0 ? 0 : now - scannerLastKeyAt;

        if (scannerLastKeyAt !== 0 && delta > BARCODE_MAX_KEY_INTERVAL_MS) {
          scannerBuffer = '';
        }

        scannerBuffer += event.key;
        scannerLastKeyAt = now;
        scheduleScannerBufferReset();
        return;
      }

      if (event.key !== 'Enter') {
        return;
      }

      const term = scannerBuffer.trim();
      scannerBuffer = '';
      scannerLastKeyAt = 0;

      if (scannerClearTimer !== null) {
        clearTimeout(scannerClearTimer);
        scannerClearTimer = null;
      }

      if (term.length < BARCODE_MIN_LENGTH) {
        return;
      }

      event.preventDefault();
      await processGlobalScanBuffer(term);
    }, true);

    if (searchForm && searchInput) {
      searchForm.addEventListener('submit', (event) => {
        event.preventDefault();
        searchProducts((searchInput.value || '').trim());
      });
    }

    if (clearCartBtn) {
      clearCartBtn.addEventListener('click', async () => {
        await clearCart();
      });
    }

    if (checkoutBtn) {
      checkoutBtn.addEventListener('click', async () => {
        await checkoutCart();
      });
    }

    if (scannerSoundToggle) {
      scannerSoundToggle.addEventListener('click', () => {
        setScannerSoundEnabled(!scannerSoundEnabled);
      });
    }

    if (searchInput) {
      setTimeout(() => {
        searchInput.focus();
      }, 60);
    }

    loadScannerSoundPreference();
    loadCart();
  </script>
</body>
</html>
