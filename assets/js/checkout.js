(function () {
  'use strict';

  async function processCheckout(config) {
    const options = config || {};
    const endpoint = String(options.endpoint || 'api/checkout.php');
    const csrfToken = String(options.csrfToken || '');
    const paymentMethod = String(options.paymentMethod || 'cash');
    const discountAmount = Number(options.discountAmount || 0);
    const confirmMessage = String(options.confirmMessage || 'Confirm checkout and process payment?');

    const onStart = typeof options.onStart === 'function' ? options.onStart : function () {};
    const onSuccess = typeof options.onSuccess === 'function' ? options.onSuccess : function () {};
    const onError = typeof options.onError === 'function' ? options.onError : function () {};
    const onFinish = typeof options.onFinish === 'function' ? options.onFinish : function () {};

    if (!window.confirm(confirmMessage)) {
      return { success: false, skipped: true };
    }

    onStart();

    try {
      const response = await fetch(endpoint, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrfToken
        },
        body: JSON.stringify({ payment_method: paymentMethod, discount_amount: discountAmount })
      });

      const result = await response.json();

      if (!response.ok || result.success !== true) {
        const message = String(result.message || 'Checkout failed');
        onError(message, result);
        return { success: false, message, result };
      }

      onSuccess(result);
      return { success: true, result };
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Checkout request failed';
      onError(message, null);
      return { success: false, message };
    } finally {
      onFinish();
    }
  }

  window.POSCheckout = {
    processCheckout
  };
})();
