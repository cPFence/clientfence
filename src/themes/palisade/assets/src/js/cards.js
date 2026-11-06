/**
 * Saved cards page (ClientFence, mod_invoice_payment_methods.html.twig): adds a card with Stripe's card
 * form (a setup from invoice/card_setup, kept with invoice/card_added), and makes a card the default
 * or removes it.
 */
import { api, errorText } from './store-utils.js';

function loadStripe() {
  if (window.Stripe) {
    return Promise.resolve(window.Stripe);
  }
  return new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = 'https://js.stripe.com/v3/';
    script.onload = () => resolve(window.Stripe);
    script.onerror = reject;
    document.head.appendChild(script);
  });
}

export default function initCards(root) {
  // A card that needed a bank check comes back here from the bank's page with its setup.
  const back = new URLSearchParams(window.location.search);
  if (back.get('setup_intent') && back.get('redirect_status') === 'succeeded') {
    window.history.replaceState(null, '', root.dataset.returnUrl);
    api('client', 'invoice/card_added', { setup_intent: back.get('setup_intent') })
      .then(() => window.location.reload())
      .catch((error) => ClientFence.message(errorText(error), 'error'));
  }

  root.querySelectorAll('[data-clientfence-card-call]').forEach((button) => {
    button.addEventListener('click', () => {
      if (button.dataset.confirm && !window.confirm(button.dataset.confirm)) {
        return;
      }
      button.disabled = true;
      api('client', button.dataset.clientfenceCardCall, { id: button.dataset.id })
        .then(() => window.location.reload())
        .catch((error) => {
          button.disabled = false;
          ClientFence.message(errorText(error), 'error');
        });
    });
  });

  const add = root.querySelector('[data-clientfence-card-add]');
  const form = root.querySelector('[data-clientfence-card-form]');
  add?.addEventListener('click', () => {
    add.disabled = true;
    Promise.all([api('client', 'invoice/card_setup'), loadStripe()])
      .then(([setup, Stripe]) => {
        const stripe = Stripe(setup.pub_key);
        const dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
        const elements = stripe.elements({ clientSecret: setup.client_secret, appearance: { theme: dark ? 'night' : 'stripe' } });
        elements.create('payment').mount(root.querySelector('[data-clientfence-card-element]'));
        add.hidden = true;
        form.hidden = false;
        form.addEventListener('submit', (event) => {
          event.preventDefault();
          const button = form.querySelector('button[type="submit"]');
          button.disabled = true;
          stripe.confirmSetup({ elements, redirect: 'if_required', confirmParams: { return_url: root.dataset.returnUrl } })
            .then(({ error, setupIntent }) => {
              if (error) {
                throw error;
              }
              return api('client', 'invoice/card_added', { setup_intent: setupIntent.id });
            })
            .then(() => window.location.reload())
            .catch((error) => {
              button.disabled = false;
              ClientFence.message(errorText(error), 'error');
            });
        });
      })
      .catch((error) => {
        add.disabled = false;
        ClientFence.message(errorText(error), 'error');
      });
  });
}
