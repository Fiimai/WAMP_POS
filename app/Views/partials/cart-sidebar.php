<aside class="animate-rise [animation-delay:220ms] lg:sticky lg:top-8 lg:h-[calc(100vh-4.5rem)]">
  <div class="glass flex h-full flex-col rounded-3xl p-4 shadow-glow sm:p-5">
    <div class="mb-4 flex items-center justify-between">
      <h2 class="font-display text-xl font-semibold text-white">Current Cart</h2>
      <span id="cartIndicator" class="rounded-full bg-emerald-500/20 px-3 py-1 text-xs font-medium text-emerald-200">Session</span>
    </div>

    <div id="cartItems" class="scrollbar-thin flex-1 space-y-3 overflow-y-auto pr-1"></div>

    <div class="mt-4 space-y-2 rounded-2xl border border-white/10 bg-slate-900/45 p-4 text-sm">
      <div class="flex items-center justify-between text-slate-300">
        <span>Subtotal</span>
        <span id="subtotal">$0.00</span>
      </div>
      <div class="flex items-center justify-between text-slate-300">
        <span id="taxLabel">Tax</span>
        <span id="tax">$0.00</span>
      </div>
      <div class="mt-2 flex items-center justify-between border-t border-white/10 pt-2 text-base font-semibold text-white">
        <span>Total</span>
        <span id="total">$0.00</span>
      </div>
    </div>

    <div class="mt-4 grid grid-cols-2 gap-2">
      <button id="clearCart" class="rounded-xl border border-white/20 bg-transparent px-3 py-3 text-sm font-medium text-slate-200 transition hover:bg-white/10">
        Clear
      </button>
      <button id="checkoutBtn" class="shop-gradient-btn w-full rounded-xl px-3 py-3 font-display text-sm font-semibold text-slate-950 shadow-lg transition hover:brightness-110 focus:outline-none focus:ring-4 focus:ring-cyan-300/40">
        Checkout
      </button>
    </div>
  </div>
</aside>
