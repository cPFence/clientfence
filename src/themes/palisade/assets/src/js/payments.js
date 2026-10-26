/**
 * Invoice and wallet pages (mod_invoice_invoice.html.twig, mod_client_balance.html.twig): the
 * payment method cards, the invoice's payment panels and receipt upload, and the wallet top-up.
 */
import { api, errorText, fill, moneyFormatter, quoteText, readJson } from './store-utils.js';

const RECEIPT_MAX_BYTES = 5 * 1024 * 1024;

function initInvoice(root) {
  const t = readJson(root, '[data-bf-invoice-texts]');
  const choose = root.querySelector('[data-bf-pay-choose]');
  if (!choose) {
    return;
  }
  const sent = root.querySelector('[data-bf-pay-sent]');
  const egpLine = root.querySelector('[data-bf-egp-line]');

  const selected = () => choose.querySelector('input[name="gateway_id"]:checked');
  const render = () => {
    const method = selected();
    root.querySelectorAll('[data-bf-panel]').forEach((panel) => {
      panel.hidden = panel.dataset.bfPanel !== method?.value;
    });
    if (egpLine) {
      const amount = method?.closest('.bf-pay')?.querySelector('[data-pay-amount]')?.textContent ?? '';
      egpLine.hidden = !method?.dataset.rate;
      egpLine.textContent = method?.dataset.rate ? fill(t.egpLine, { amount, method: method.dataset.title }) : '';
    }
  };
  choose.addEventListener('change', render);

  root.querySelectorAll('[data-bf-receipt]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      const input = form.querySelector('input[type="file"]');
      const file = input.files?.[0];
      if (!file) {
        ClientFence.message(t.chooseFile, 'error');
        input.focus();
        return;
      }
      if (file.size > RECEIPT_MAX_BYTES) {
        ClientFence.message(t.tooBig, 'error');
        return;
      }
      const body = new FormData();
      body.append('hash', root.dataset.hash);
      body.append('gateway_id', form.dataset.bfReceipt);
      body.append('receipt', file);
      const button = form.querySelector('button[type="submit"]');
      const label = button.textContent;
      button.disabled = true;
      button.textContent = t.sending;
      api('client', 'invoice/receipt_send', body)
        .then(() => {
          const method = selected();
          const line = sent.querySelector('[data-bf-sent-line]');
          line.textContent = fill(line.dataset.template, { method: method?.dataset.title ?? '' });
          choose.hidden = true;
          sent.hidden = false;
          sent.focus();
          input.value = '';
        })
        .catch((error) => ClientFence.message(errorText(error), 'error'))
        .finally(() => {
          button.disabled = false;
          button.textContent = label;
        });
    });
  });

  sent?.querySelector('[data-bf-pay-again]')?.addEventListener('click', () => {
    sent.hidden = true;
    choose.hidden = false;
  });

  render();
}

function initWallet(root) {
  const form = root.querySelector('[data-bf-wallet-form]');
  if (!form) {
    return;
  }
  const t = readJson(root, '[data-bf-wallet-texts]');
  const currency = root.dataset.currency || 'USD';
  const money = moneyFormatter(currency);
  const min = Number(root.dataset.min) || 0;
  const max = Number(root.dataset.max) || Infinity;
  const input = form.querySelector('[data-bf-amount]');
  const hint = form.querySelector('[data-bf-amount-hint]');
  const go = form.querySelector('[data-bf-wallet-go]');
  const sentBox = form.querySelector('[data-bf-wallet-sent]');
  let busy = false;

  // Arabic keyboards type Arabic-Indic digits and the Arabic decimal separator.
  const amountOf = () => Number(String(input.value).trim()
    .replace(/[٠-٩]/g, (d) => String(d.charCodeAt(0) - 0x0660))
    .replace(/[۰-۹]/g, (d) => String(d.charCodeAt(0) - 0x06F0))
    .replace(/٫/g, '.')
    .replace(/[,٬\s]/g, ''));
  const selected = () => form.querySelector('input[name="gateway_id"]:checked');

  const render = () => {
    const amount = amountOf();
    const valid = Number.isFinite(amount) && amount >= min && amount <= max && String(input.value).trim() !== '';
    const range = { min: money(min), max: money(max) };
    hint.textContent = fill(valid ? t.range : t.invalid, range);
    hint.classList.toggle('bf-error', !valid);
    form.querySelectorAll('[data-amount]').forEach((button) => {
      const on = valid && Number(button.dataset.amount) === amount;
      button.classList.toggle('is-on', on);
      button.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    form.querySelectorAll('input[name="gateway_id"]').forEach((radio) => {
      const out = radio.closest('.bf-pay').querySelector('[data-pay-amount]');
      if (out) {
        out.textContent = !valid ? '—' : (radio.dataset.rate ? quoteText(amount, radio.dataset.rate, radio.dataset.quoteCurrency) : money(amount));
      }
    });
    const method = selected();
    go.disabled = !valid || !method || busy;
    go.textContent = !valid || !method ? t.enter : fill(method.dataset.manual === '1' ? t.manual : t.online, { amount: money(amount), method: method.dataset.title });
  };

  form.addEventListener('click', (event) => {
    const preset = event.target instanceof Element ? event.target.closest('[data-amount]') : null;
    if (preset) {
      input.value = preset.dataset.amount;
      sentBox.hidden = true;
      render();
    }
  });
  form.addEventListener('input', () => {
    sentBox.hidden = true;
    render();
  });
  form.addEventListener('change', render);

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    const method = selected();
    const amount = amountOf();
    if (busy || !method || go.disabled) {
      return;
    }
    busy = true;
    render();
    api('client', 'invoice/funds_invoice', { amount })
      .then((hash) => {
        if (method.dataset.manual !== '1') {
          window.location.assign(`${root.dataset.banklink}/${hash}/${method.value}`);
          return;
        }
        const pay = method.dataset.rate ? quoteText(amount, method.dataset.rate, method.dataset.quoteCurrency) : money(amount);
        sentBox.querySelector('[data-bf-wallet-sent-line]').textContent = fill(t.sent, { amount: pay, method: method.dataset.title });
        const open = sentBox.querySelector('[data-bf-wallet-open]');
        open.textContent = t.open;
        open.href = `${root.dataset.invoice}/${hash}?gateway=${encodeURIComponent(method.value)}`;
        sentBox.hidden = false;
      })
      .catch((error) => ClientFence.message(fill(t.failed, { error: errorText(error) }), 'error'))
      .finally(() => {
        busy = false;
        render();
      });
  });

  render();
}

export default function initPayments() {
  document.querySelectorAll('[data-bf-invoice]').forEach(initInvoice);
  document.querySelectorAll('[data-bf-wallet]').forEach(initWallet);
}
