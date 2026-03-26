<aside class="animate-rise [animation-delay:220ms] lg:sticky lg:top-8 lg:h-[calc(100vh-4.5rem)]">
  <div class="glass flex h-full flex-col rounded-3xl p-4 shadow-glow sm:p-5">
    <div class="mb-4 flex items-center justify-between">
      <h2 data-i18n="currentCart" class="font-display text-xl font-semibold text-white">Current Cart</h2>
      <span id="cartIndicator" data-i18n="session" class="rounded-full bg-emerald-500/20 px-3 py-1 text-xs font-medium text-emerald-200">Session</span>
    </div>

    <div id="cartItems" class="scrollbar-thin flex-1 space-y-3 overflow-y-auto pr-1" aria-live="polite"></div>

    <div id="cartSummary" class="mt-4 space-y-2 rounded-2xl border border-white/10 bg-slate-900/45 p-4 text-sm">
      <div class="flex items-center justify-between text-slate-300">
        <span data-i18n="subtotal">Subtotal</span>
        <span id="subtotal">$0.00</span>
      </div>
      <?php if ($enableDiscounts ?? false): ?>
      <div class="flex items-center justify-between text-slate-300">
        <label for="discountAmount" data-i18n="discount">Discount</label>
        <input id="discountAmount" type="number" step="0.01" min="0" class="w-20 rounded border border-white/20 bg-slate-800 px-2 py-1 text-right text-sm text-white" value="0.00">
      </div>
      <?php endif; ?>
      <div class="flex items-center justify-between text-slate-300">
        <span id="taxLabel" data-i18n="tax">Tax</span>
        <span id="tax">$0.00</span>
      </div>
      <div class="mt-2 flex items-center justify-between border-t border-white/10 pt-2 text-base font-semibold text-white">
        <span data-i18n="total">Total</span>
        <span id="total">$0.00</span>
      </div>
    </div>

    <div id="cartActionRow" class="mt-4 grid grid-cols-2 gap-2" aria-label="Cart actions">
      <button id="clearCart" data-i18n="clear" class="rounded-xl border border-white/20 bg-transparent px-3 py-3 text-sm font-medium text-slate-200 transition hover:bg-white/10 focus:outline-none focus:ring-2 focus:ring-white/50">
        Clear
      </button>
      <button id="checkoutBtn" data-i18n="checkout" class="shop-gradient-btn w-full rounded-xl px-3 py-3 font-display text-sm font-semibold text-slate-950 shadow-lg transition hover:brightness-110 focus:outline-none focus:ring-4 focus:ring-cyan-300/40" aria-label="Process checkout">
        Checkout
      </button>
    </div>

    <details id="customerDetailsPanel" class="mt-3 rounded-xl border border-white/10 bg-slate-950/35 p-3 text-sm text-slate-300">
      <summary class="cursor-pointer select-none text-xs font-semibold text-slate-200">Customer Details (optional)</summary>
      <div class="mt-2 space-y-2">
        <label for="customerName" class="block text-xs font-medium">Name or Nickname</label>
        <input id="customerName" type="text" maxlength="80" placeholder="Ama / Mr. K" class="w-full rounded border border-white/20 bg-slate-800 px-2 py-1 text-sm text-white" />

        <label for="customerContact" class="block text-xs font-medium">Contact (phone or email)</label>
        <input id="customerContact" type="text" maxlength="120" placeholder="+233... or name@email.com" class="w-full rounded border border-white/20 bg-slate-800 px-2 py-1 text-sm text-white" />

        <label for="deliveryNote" class="block text-xs font-medium">Delivery Note (optional)</label>
        <textarea id="deliveryNote" rows="2" maxlength="255" placeholder="Landmark or short note" class="w-full rounded border border-white/20 bg-slate-800 px-2 py-1 text-sm text-white"></textarea>

        <label class="inline-flex items-start gap-2 text-xs">
          <input id="customerConsent" type="checkbox" class="mt-0.5 rounded border-white/30 bg-slate-800" />
          <span>Customer agreed to store these details for delivery follow-up.</span>
        </label>
      </div>
    </details>
  </div>
</aside>

