(function () {
  'use strict';

  function escapeHtml(value) {
    return String(value)
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#39;');
  }

  function showToast(message, type) {
    const root = getToastRoot();
    const toast = document.createElement('div');
    const ok = type !== 'error';

    toast.className = [
      'pointer-events-auto min-w-[220px] max-w-xs rounded-xl border px-3 py-2 text-sm shadow-xl backdrop-blur-sm',
      ok
        ? 'border-emerald-300/35 bg-emerald-500/20 text-emerald-100'
        : 'border-rose-300/35 bg-rose-500/20 text-rose-100',
      'opacity-0 translate-y-[-6px] transition duration-200 ease-out'
    ].join(' ');

    toast.textContent = message;
    root.appendChild(toast);

    requestAnimationFrame(() => {
      toast.classList.remove('opacity-0', 'translate-y-[-6px]');
    });

    setTimeout(() => {
      toast.classList.add('opacity-0', 'translate-y-[-6px]');
      setTimeout(() => {
        toast.remove();
      }, 220);
    }, 2100);
  }

  function getToastRoot() {
    let root = document.getElementById('toastRoot');
    if (root) {
      return root;
    }

    root = document.createElement('div');
    root.id = 'toastRoot';
    root.className = 'pointer-events-none fixed right-4 top-20 z-[70] flex flex-col gap-2 sm:right-6 sm:top-24';
    document.body.appendChild(root);

    return root;
  }

  function bounceCartIndicator(element) {
    if (!element) {
      return;
    }

    element.classList.remove('cart-pop');
    void element.offsetWidth;
    element.classList.add('cart-pop');
  }

  function emptyStateMarkup() {
    return [
      '<div class="rounded-xl border border-dashed border-white/20 bg-slate-900/35 p-4 text-center">',
      '  <div class="mx-auto mb-2 flex h-12 w-12 items-center justify-center rounded-full bg-cyan-500/15 text-cyan-100">',
      '    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" aria-hidden="true">',
      '      <path d="M3 4h2l2.2 10.2a1 1 0 0 0 1 .8h8.8a1 1 0 0 0 1-.8L20 7H7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"></path>',
      '      <circle cx="10" cy="19" r="1.4" fill="currentColor"></circle>',
      '      <circle cx="17" cy="19" r="1.4" fill="currentColor"></circle>',
      '    </svg>',
      '  </div>',
      '  <p class="text-sm font-medium text-slate-200">Your cart is empty</p>',
      '  <p class="mt-1 text-xs text-slate-400">Scan a barcode or tap Add to start checkout.</p>',
      '</div>'
    ].join('');
  }

  function renderSimpleCart(container, cartItems, currencySymbol) {
    if (!container) {
      return;
    }

    if (!Array.isArray(cartItems) || cartItems.length === 0) {
      container.innerHTML = emptyStateMarkup();
      return;
    }

    container.innerHTML = cartItems.map((item) => {
      const lineTotal = Number(item.price || 0) * Number(item.qty || 0);
      return [
        '<div class="flex items-center justify-between border-b border-white/10 p-2">',
        `  <span class="text-sm text-slate-200">${escapeHtml(item.name)} x ${Number(item.qty || 0)}</span>`,
        `  <span class="text-sm font-bold text-mint">${escapeHtml(currencySymbol)}${lineTotal.toFixed(2)}</span>`,
        '</div>'
      ].join('');
    }).join('');
  }

  async function addToCart(productId, quantity, options) {
    const qty = Number(quantity || 1);
    const config = options || {};
    const endpoint = String(config.endpoint || 'api/add_to_cart.php');
    const csrfToken = String(config.csrfToken || '');

    const payload = new URLSearchParams();
    payload.set('product_id', String(productId));
    payload.set('quantity', String(qty));

    const response = await fetch(endpoint, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-CSRF-Token': csrfToken
      },
      body: payload.toString()
    });

    const data = await response.json();

    if (!response.ok || data.success !== true) {
      throw new Error(data.message || 'Could not add to cart');
    }

    return data;
  }

  window.POSCartUX = {
    addToCart,
    renderSimpleCart,
    emptyStateMarkup,
    bounceCartIndicator,
    showToast
  };
})();
