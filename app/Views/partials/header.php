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

    <form id="searchForm" method="get" class="flex w-full items-center gap-3 sm:w-auto">
      <input
        id="searchInput"
        type="search"
        name="q"
        value="<?= e($search) ?>"
        placeholder="Search products, barcode, SKU..."
        class="w-full rounded-xl border border-white/15 bg-slate-900/70 px-4 py-3 text-sm text-slate-100 placeholder:text-slate-400 focus:border-electric focus:outline-none focus:ring-2 focus:ring-electric/40 sm:w-80"
        autocomplete="off"
      />
      <button type="submit" class="rounded-xl bg-white/10 px-4 py-3 text-sm font-medium text-white transition hover:bg-white/20">Search</button>
      <div id="searchSpinner" class="hidden h-5 w-5 animate-spin rounded-full border-2 border-cyan-300/30 border-t-cyan-300" aria-label="Searching"></div>
    </form>
  </div>
</header>

<div class="mb-4 flex items-center justify-between animate-rise [animation-delay:120ms]">
  <h2 class="font-display text-xl font-semibold text-white sm:text-2xl">Products</h2>
  <span id="productCount" class="rounded-full bg-cyan-500/15 px-3 py-1 text-xs font-medium text-cyan-200"><?= $productCount ?> Items</span>
</div>
