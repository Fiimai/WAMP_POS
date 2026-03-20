<?php
/** @var array<int, array<string, mixed>> $products */
/** @var string $currencySymbol */

$imageFallbacks = [
    'https://images.unsplash.com/photo-1583394838336-acd977736f90?auto=format&fit=crop&w=600&q=80',
    'https://images.unsplash.com/photo-1542838132-92c53300491e?auto=format&fit=crop&w=600&q=80',
    'https://images.unsplash.com/photo-1585060544812-6b45742d762f?auto=format&fit=crop&w=600&q=80',
    'https://images.unsplash.com/photo-1523293182086-7651a899d37f?auto=format&fit=crop&w=600&q=80',
    'https://images.unsplash.com/photo-1572635196237-14b3f281503f?auto=format&fit=crop&w=600&q=80',
    'https://images.unsplash.com/photo-1611930022073-b7a4ba5fcccd?auto=format&fit=crop&w=600&q=80',
];
?>

<div id="productGrid" class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4">
  <?php if (empty($products)): ?>
    <div class="col-span-full rounded-2xl border border-dashed border-white/20 bg-slate-900/30 p-8 text-center text-slate-300">
      No products found. Try a different search term.
    </div>
  <?php endif; ?>

  <?php foreach ($products as $index => $product): ?>
    <?php
    $image = $imageFallbacks[$index % count($imageFallbacks)];
    $name = (string) $product['name'];
    $category = (string) $product['category_name'];
    $price = (float) $product['unit_price'];
    $stock = (int) $product['stock_qty'];
    ?>
    <article class="group rounded-2xl border border-white/10 bg-slate-950/45 p-2 shadow-card transition hover:-translate-y-0.5 hover:border-cyan-300/35 hover:bg-slate-900/65">
      <img src="<?= e($image) ?>" alt="<?= e($name) ?>" class="h-28 w-full rounded-xl object-cover sm:h-32" />
      <div class="p-2">
        <h3 class="truncate text-sm font-semibold text-white"><?= e($name) ?></h3>
        <p class="mt-1 text-xs text-slate-400"><?= e($category) ?></p>
        <div class="mt-3 flex items-center justify-between gap-2">
          <div>
            <p class="text-sm font-semibold text-mint\"><?= e($currencySymbol) ?><?= number_format($price, 2) ?></p>
            <p class="text-[11px] text-slate-400">Stock: <?= $stock ?></p>
            <?php if ($stock > 0 && $stock < 5): ?>
              <span class="mt-1 inline-flex rounded-full border border-rose-300/40 bg-rose-500/20 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-rose-100">
                Low stock
              </span>
            <?php endif; ?>
          </div>
          <button
            class="add-to-cart rounded-lg bg-cyan-500/20 px-2 py-1 text-xs font-semibold text-cyan-100 hover:bg-cyan-500/30 disabled:cursor-not-allowed disabled:opacity-40"
            data-product-id="<?= (int) $product['id'] ?>"
            <?= $stock < 1 ? 'disabled' : '' ?>
          >
            <?= $stock < 1 ? 'Out' : 'Add' ?>
          </button>
        </div>
      </div>
    </article>
  <?php endforeach; ?>
</div>
