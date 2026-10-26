/**
 * Configurable options on an order form (mod_product_options_form.html.twig, Product/Options.php).
 * Reads the choice, prices it for a billing cycle as Options::resolve() does (the value's price for
 * the cycle, times the quantity; "once" prices for a one-time or free product) and shows each
 * choice's price. The one-page checkout (checkout.js) adds optionLines() to its summary; on
 * FOSSBilling's order steps the prices follow the billing cycle select. A service's "Change
 * options" form (mod_order_manage.html.twig) is priced by the server (initOptionsChange()).
 */
import { api, errorText, fill, moneyFormatter, readJson } from './store-utils.js';

const KEYS = { '1W': 'w', '1M': 'm', '3M': 'q', '6M': 'b', '1Y': 'a', '12M': 'a', '2Y': 'bia', '3Y': 'tria' };

/** The price column of a billing cycle ("once" for none). */
export const optionKey = (cycle) => KEYS[cycle] || 'once';

/** The options listed in root (an empty list without any). */
export function readOptions(root) {
  const box = root.querySelector('[data-cf-options]');
  const options = box ? readJson(box, '[data-cf-options-data]') : [];
  return Array.isArray(options) ? options : [];
}

/** The choice on the form: option id => value id, 0/1 or a quantity. */
export function readChoice(root, options) {
  const choice = {};
  options.forEach((option) => {
    const fields = [...root.querySelectorAll(`[name="options[${option.id}]"]`)];
    if (!fields.length) {
      return;
    }
    if (option.type === 'radio') {
      const checked = fields.find((field) => field.checked);
      if (checked) {
        choice[option.id] = Number(checked.value);
      }
    } else if (option.type === 'yesno') {
      choice[option.id] = fields[0].checked ? 1 : 0;
    } else if (option.type === 'quantity') {
      const max = option.qty_max > 0 ? option.qty_max : Infinity;
      choice[option.id] = Math.min(max, Math.max(option.qty_min, parseInt(fields[0].value, 10) || 0));
    } else {
      choice[option.id] = Number(fields[0].value);
    }
  });
  return choice;
}

const chosenValue = (option, choice) => {
  if (option.type === 'select' || option.type === 'radio') {
    return { value: option.values.find((v) => v.id === Number(choice[option.id])), qty: 1 };
  }
  const qty = option.type === 'yesno' ? (choice[option.id] ? 1 : 0) : Number(choice[option.id] || 0);
  return { value: option.values[0], qty };
};

/** The priced lines of a choice for a cycle: { title, price, setup } (per product unit). */
export function optionLines(options, choice, cycle) {
  const key = optionKey(cycle);
  const lines = [];
  options.forEach((option) => {
    const { value, qty } = chosenValue(option, choice);
    if (!value || qty <= 0) {
      return;
    }
    const prices = value.prices?.[key] ?? { price: 0, setup_price: 0 };
    const price = Number(prices.price) * qty;
    const setup = Number(prices.setup_price) * qty;
    if (price <= 0 && setup <= 0) {
      return;
    }
    let title = option.title;
    if (option.type === 'select' || option.type === 'radio') {
      title = `${option.title}: ${value.title}`;
    } else if (option.type === 'quantity') {
      title = `${option.title}: ${qty}${option.unit ? ` ${option.unit}` : ''}`;
    }
    lines.push({ title, price, setup });
  });
  return lines;
}

/** Shows each choice's price for a cycle next to it ("+$5 / month", "+$2 / month each"). */
export function showPrices(root, options, cycle, money, rate, per = '') {
  const key = optionKey(cycle);
  const box = root.querySelector('[data-cf-options]');
  const setupWord = box?.dataset.setupText || 'setup fee';
  const eachWord = box?.dataset.eachText || 'each';
  // A service's "Change options" form: a change has no setup fee.
  const withSetup = !(box && 'noSetup' in box.dataset);
  const text = (prices, each = false) => {
    const price = Number(prices?.price || 0) * rate;
    const setup = Number(prices?.setup_price || 0) * rate;
    const parts = [];
    if (price > 0) {
      parts.push(`+${money(price)}${per ? ` ${per}` : ''}${each ? ` ${eachWord}` : ''}`);
    }
    if (setup > 0 && withSetup) {
      parts.push(`+${money(setup)} ${setupWord}`);
    }
    return parts.join(', ');
  };
  options.forEach((option) => {
    const box = root.querySelector(`[data-cf-option="${option.id}"]`);
    if (!box) {
      return;
    }
    if (option.type === 'select') {
      box.querySelectorAll('option[data-cf-label]').forEach((el) => {
        const value = option.values.find((v) => v.id === Number(el.value));
        const extra = value ? text(value.prices?.[key]) : '';
        el.textContent = extra ? `${el.dataset.cfLabel} (${extra})` : el.dataset.cfLabel;
      });
    } else if (option.type === 'radio') {
      box.querySelectorAll('[data-cf-option-price]').forEach((el) => {
        const value = option.values.find((v) => v.id === Number(el.dataset.cfOptionPrice));
        const extra = value ? text(value.prices?.[key]) : '';
        el.textContent = extra ? `(${extra})` : '';
      });
    } else {
      const hint = box.querySelector('[data-cf-option-hint]');
      if (hint) {
        hint.textContent = text(option.values[0]?.prices?.[key], option.type === 'quantity');
      }
    }
  });
}

/**
 * A service's "Change options" form: each new choice is priced with the server's proration
 * (order/upgrade_options_quote, Order\Upgrades::optionsQuote()) and sent as an upgrade
 * (order/upgrade with `options`): an amount to pay leads to its invoice. Texts come from the form's
 * data-text-* attributes.
 */
export function initOptionsChange(form) {
  const options = readOptions(form);
  const out = form.querySelector('[data-cf-options-quote]');
  const submit = form.querySelector('[type="submit"]');
  if (!options.length || !out || !submit) {
    return;
  }
  const text = form.dataset;
  const money = moneyFormatter(text.currency || 'USD');
  const request = () => ({
    id: form.elements.id.value,
    product_id: form.elements.product_id.value,
    period: form.elements.period.value,
    options: readChoice(form, options),
  });
  let asked = 0;
  let timer = null;
  const quote = () => {
    asked += 1;
    const ask = asked;
    submit.disabled = true;
    out.textContent = text.textWorking;
    api('client', 'order/upgrade_options_quote', request()).then((result) => {
      if (ask !== asked) {
        return;
      }
      let due = text.textFree;
      if (result.amount > 0) {
        due = fill(text.textPay, { amount: money(result.total) });
      } else if (result.amount < 0) {
        due = fill(text.textCredit, { amount: money(-result.total) });
      }
      if (result.tax_rate > 0 && result.amount !== 0) {
        due += ` ${text.textTax}`;
      }
      out.textContent = fill(text.textQuote, {
        kind: result.kind === 'downgrade' ? text.textDowngrade : text.textUpgrade,
        price: money(result.price),
        due,
      });
      submit.disabled = false;
    }, (error) => {
      if (ask === asked) {
        out.textContent = errorText(error);
      }
    });
  };
  const changed = () => {
    window.clearTimeout(timer);
    timer = window.setTimeout(quote, 300);
  };
  form.addEventListener('change', changed);
  form.addEventListener('input', changed);
  form.addEventListener('submit', (event) => {
    event.preventDefault();
    submit.disabled = true;
    api('client', 'order/upgrade', request()).then((result) => {
      if (result.invoice_hash) {
        window.location = `${text.invoiceUrl}/${result.invoice_hash}`;
        return;
      }
      const done = result.status === 'applied';
      ClientFence.message(done ? text.textDone : text.textLater, done ? 'success' : 'error');
      window.setTimeout(() => window.location.reload(), 1500);
    }, (error) => {
      submit.disabled = false;
      ClientFence.message(errorText(error), 'error');
    });
  });
  submit.disabled = true;
  out.textContent = text.textPick;
}

/** FOSSBilling's order steps: the prices follow the form's billing cycle (a select, or fixed). */
export default function initProductOptions(box) {
  const form = box.closest('form') || document;
  const options = readOptions(form);
  if (!options.length) {
    return;
  }
  const money = moneyFormatter(box.dataset.currency || 'USD');
  const rate = Number(box.dataset.rate) || 1;
  const period = form.querySelector('[name="period"]');
  const update = () => showPrices(form, options, period ? period.value : 'once', money, rate, box.dataset.perText || '');
  period?.addEventListener('change', update);
  update();
}
