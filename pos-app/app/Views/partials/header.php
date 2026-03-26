<?php
/** @var string $search */
/** @var int $productCount */
/** @var string $shopName */
?>
<header class="mb-5 animate-rise rounded-2xl border border-white/10 bg-panel/70 p-4 shadow-card sm:p-5">
  <div class="flex flex-wrap items-center justify-between gap-4">
    <div>
      <p class="font-display text-sm uppercase tracking-[0.25em] text-cyan-300/90"><?= e(strtoupper($shopName) . ' POS') ?></p>
      <h1 class="font-display text-2xl font-semibold text-white sm:text-3xl"><?= e($shopName) ?> Checkout</h1>
    </div>

    <form id="searchForm" method="get" class="flex w-full flex-wrap items-center gap-2 sm:w-auto sm:flex-nowrap sm:gap-3" role="search" aria-label="Product search form">
      <input
        id="searchInput"
        type="search"
        name="q"
        value="<?= e($search) ?>"
        placeholder="Search products, barcode, SKU..."
        data-i18n-placeholder="searchPlaceholder"
        class="w-full min-h-[44px] rounded-xl border border-white/15 bg-slate-900/70 px-4 py-3 text-sm text-slate-100 placeholder:text-slate-400 focus:border-electric focus:outline-none focus:ring-2 focus:ring-electric/40 sm:w-80"
        autocomplete="off"
        aria-label="Search products"
      />
      <button type="submit" data-i18n="searchButton" class="min-h-[44px] rounded-xl bg-white/10 px-4 py-3 text-sm font-medium text-white transition hover:bg-white/20">Search</button>
      <button id="clearSearchBtn" type="button" data-i18n="clear" class="hidden min-h-[44px] rounded-xl border border-white/15 bg-slate-900/45 px-4 py-3 text-sm font-medium text-slate-200 transition hover:bg-white/10 focus:outline-none focus:ring-2 focus:ring-cyan-300/50">Clear</button>
      <div id="searchSpinner" class="hidden h-5 w-5 animate-spin rounded-full border-2 border-cyan-300/30 border-t-cyan-300" aria-label="Searching"></div>
    </form>
  </div>
  <p class="mt-3 text-xs text-slate-300">Tip: Press F2 to focus search, then Enter to add the first in-stock match.</p>
</header>

<div class="mb-4 flex flex-wrap items-center justify-between gap-3 animate-rise [animation-delay:120ms]">
  <h2 data-i18n="products" class="font-display text-xl font-semibold text-white sm:text-2xl">Products</h2>
  <div class="flex flex-wrap items-center gap-2">
    <button id="bestModeBtn" type="button" data-smart-mode="best" data-i18n="bestMode" class="quicklink-badge rounded-lg px-3 py-1.5 text-xs font-semibold">Best Sellers</button>
    <button id="recentModeBtn" type="button" data-smart-mode="recent" data-i18n="recentMode" class="quicklink-badge rounded-lg px-3 py-1.5 text-xs font-semibold">Recently Added</button>
    <span id="productCount" role="status" aria-live="polite" class="rounded-full bg-cyan-500/15 px-3 py-1 text-xs font-medium text-cyan-200"><?= $productCount ?> Items</span>
  </div>
</div>

