/**
 * The cart page (mod_cart_index.html.twig): remove an item, apply or remove the promo code (the
 * page reloads with the cart's new totals) and place the order, then the payment page (online
 * methods) or the invoice (manual methods); without an invoice, the new service.
 */
import { api, errorText, fill, readJson } from './store-utils.js';

export default function initCart(root) {
  const data = readJson(root, '[data-bf-cart-data]');
  const t = data.texts ?? {};
  const fail = (error) => ClientFence.message(errorText(error), 'error');

  root.querySelectorAll('[data-bf-cart-remove]').forEach((button) => button.addEventListener('click', () => {
    if (window.confirm(fill(t.remove, { item: button.dataset.title }))) {
      api('guest', 'cart/remove_item', { id: button.dataset.bfCartRemove }).then(() => window.location.reload(), fail);
    }
  }));

  root.querySelector('[data-bf-cart-promo]')?.addEventListener('submit', (event) => {
    event.preventDefault();
    const input = event.target.elements.promocode;
    if (input.reportValidity()) {
      api('guest', 'cart/apply_promo', { promocode: input.value.trim() }).then(() => window.location.reload(), fail);
    }
  });
  root.querySelector('[data-bf-cart-promo-remove]')?.addEventListener('click', () => {
    api('guest', 'cart/remove_promo').then(() => window.location.reload(), fail);
  });

  const checkout = root.querySelector('[data-bf-cart-checkout]');
  checkout?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const pay = checkout.querySelector('[data-bf-cart-pay]');
    const invalid = [...checkout.querySelectorAll('input')].find((input) => !input.checkValidity());
    if (invalid) {
      invalid.reportValidity();
      return;
    }
    const method = root.querySelector('[data-bf-pay-list] input:checked');
    pay.disabled = true;
    try {
      const result = await api('client', 'cart/checkout', method ? { gateway_id: method.value } : {});
      if (result?.invoice_hash && method && method.dataset.manual !== '1') {
        window.location.assign(`${data.urls.banklink}/${result.invoice_hash}/${method.value}`);
      } else if (result?.invoice_hash) {
        window.location.assign(`${data.urls.invoice}/${result.invoice_hash}`);
      } else {
        window.location.assign(`${data.urls.service}/${result?.order_id ?? ''}`);
      }
    } catch (error) {
      pay.disabled = false;
      ClientFence.message(fill(t.failed, { error: errorText(error) }), 'error');
    }
  });
}
