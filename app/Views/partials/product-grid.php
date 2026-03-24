<?php
/** @var array<int, array<string, mixed>> $products */
/** @var string $currencySymbol */

$imageFallbacks = [
    'assets/images/product-placeholder-1.svg',
    'assets/images/product-placeholder-2.svg',
    'assets/images/product-placeholder-3.svg',
    'assets/images/product-placeholder-4.svg',
    'assets/images/product-placeholder-5.svg',
    'assets/images/product-placeholder-6.svg',
];
?>

<div id="productGrid" class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4" aria-live="polite">
  <?php if (empty($products)): ?>
    <div class="col-span-full rounded-2xl border border-dashed border-white/20 bg-slate-900/30 p-8 text-center text-slate-300" role="status">
      <p data-i18n="noProductsFound" class="text-sm font-medium">No products found. Try a different search term.</p>
      <p class="mt-1 text-xs text-slate-400">Try searching by barcode, SKU, or category.</p>
    </div>
  <?php endif; ?>

  <?php foreach ($products as $index => $product): ?>
    <?php
    $image = $product['image_path'] ?? $imageFallbacks[$index % count($imageFallbacks)];
    $name = (string) $product['name'];
    $category = (string) $product['category_name'];
    $price = (float) $product['unit_price'];
    $stock = (int) $product['stock_qty'];
    ?>
    <article class="group rounded-2xl border border-white/10 bg-slate-950/45 p-2 shadow-card transition hover:-translate-y-0.5 hover:border-cyan-300/35 hover:bg-slate-900/65 focus-within:ring-2 focus-within:ring-cyan-300/45">
      <img src="<?= e($image) ?>" alt="<?= e($name) ?>" class="h-28 w-full rounded-xl object-cover sm:h-32" />
      <div class="p-2">
        <h3 class="truncate text-sm font-semibold text-white"><?= e($name) ?></h3>
        <p class="mt-1 text-xs text-slate-400"><?= e($category) ?></p>
        <div class="mt-3 flex items-center justify-between gap-2">
          <div>
            <p class="text-sm font-semibold text-mint"><?= e($currencySymbol) ?><?= number_format($price, 2) ?></p>
            <p class="text-[11px] text-slate-400"><span data-i18n="stock">Stock</span>: <?= $stock ?></p>
            <?php if ($stock > 0 && $stock < 5): ?>
              <span data-i18n="lowStock" class="mt-1 inline-flex rounded-full border border-rose-300/40 bg-rose-500/20 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-rose-100">
                Low stock
              </span>
            <?php endif; ?>
          </div>
          <button
            class="add-to-cart rounded-lg bg-cyan-500/20 px-2 py-1 text-xs font-semibold text-cyan-100 transition hover:bg-cyan-500/30 focus:outline-none focus:ring-2 focus:ring-cyan-300/60 disabled:cursor-not-allowed disabled:opacity-40"
            data-product-id="<?= (int) $product['id'] ?>"
            aria-label="<?= e(($stock < 1 ? 'Out of stock' : 'Add to cart') . ': ' . $name) ?>"
            <?= $stock < 1 ? 'disabled' : '' ?>
          >
            <span data-i18n="<?= $stock < 1 ? 'out' : 'add' ?>"><?= $stock < 1 ? 'Out' : 'Add' ?></span>
          </button>
        </div>
      </div>
    </article>
  <?php endforeach; ?>
</div>


