/**
 * One-page checkout (mod_order_product.html.twig). Keeps the summary, the cycle totals, the
 * add-ons, the Pay button and the phone pay bar in step with the choices, and places the order:
 * Add to cart (the cart page) or, with Pay, sign-up for a new customer (signed in by FOSSBilling's "auto login after sign-up"), cart/add_item (which empties the cart first), the promo
 * code, cart/checkout, then the payment page (online methods) or the confirmation (manual
 * methods, balance, nothing to pay). Amounts shown before the promo code are the product
 * prices; with a promo code the cart's own totals are shown.
 */
import { MONTHS, api, cycleTexts, errorText, fill, moneyFormatter, readJson } from './store-utils.js';
import { optionLines, readChoice, readOptions, showPrices } from './product-options.js';

export default function initCheckout(root) {
  const data = readJson(root, '[data-bf-checkout-data]');
  const t = data.texts ?? {};
  const cycles = cycleTexts(t.cycles);
  const money = moneyFormatter(data.currency || 'USD');
  const rate = Number(data.rate) || 1;
  const product = data.product ?? {};
  const form = root.querySelector('[data-bf-checkout-form]');
  const $ = (selector) => root.querySelector(selector);
  const $$ = (selector) => [...root.querySelectorAll(selector)];
  // Configurable options (Product/Options.php), priced per product unit for the cycle.
  const options = readOptions(root);

  const state = {
    cycle: data.period || '',
    qty: 1,
    addons: Object.fromEntries((data.addons ?? []).map((addon) => [addon.id, { on: false, qty: 1 }])),
    promo: null, // { code, discount, total } in the default currency, once the cart accepted a code
    signedIn: !!data.signedIn,
    accountReady: false, // the account was made, but the sign-up did not sign in
    busy: false,
    planInCart: false, // Apply put this plan in the cart (and emptied it)
  };

  const recurrent = product.type === 'recurrent';
  // The tax the invoice adds for a signed-in client (rounded once, as the invoice does). With prices that include
  // tax, the invoice asks for the price, less the tax for an exempt client (deduct).
  const inclusive = !!data.tax?.inclusive;
  const taxRate = inclusive ? 0 : Number(data.tax?.rate) || 0;
  const deduct = inclusive ? Number(data.tax?.deduct) || 0 : 0;
  const withTax = (amount) => (deduct > 0 ? Math.round(amount * 10000 / (100 + deduct)) / 100 : amount + (taxRate > 0 ? Math.round(amount * taxRate) / 100 : 0));
  const addonPrice = (addon, cycle) => {
    if (addon.type === 'recurrent') {
      return addon.prices?.[cycle] ?? null;
    }
    if (addon.type === 'once') {
      return addon.once;
    }
    if (addon.type === 'free') {
      return { price: 0, setup: 0 };
    }
    return null;
  };

  /** Lines, today's total and the renewal amount for a cycle (without a promo code). */
  const compute = (cycle) => {
    const lines = [];
    let due = 0;
    let renew = 0;
    const qty = product.quantity ? state.qty : 1;
    const base = recurrent ? product.prices?.[cycle] : (product.type === 'once' ? product.once : { price: 0, setup: 0 });
    if (base) {
      lines.push([qty > 1 ? `${product.title} × ${qty}` : product.title, base.price * qty]);
      due += (Number(base.price) + Number(base.setup || 0)) * qty;
      renew += recurrent ? base.price * qty : 0;
      if (Number(base.setup) > 0) {
        lines.push([t.setup, base.setup * qty]);
      }
      optionLines(options, readChoice(root, options), recurrent ? cycle : 'once').forEach((line) => {
        lines.push([line.title, line.price * qty]);
        due += (line.price + line.setup) * qty;
        renew += recurrent ? line.price * qty : 0;
        if (line.setup > 0) {
          lines.push([`${line.title}: ${t.setup}`, line.setup * qty]);
        }
      });
    }
    (data.addons ?? []).forEach((addon) => {
      const choice = state.addons[addon.id];
      const price = addonPrice(addon, cycle);
      if (!choice?.on || !price) {
        return;
      }
      const q = addon.quantity ? choice.qty : 1;
      lines.push([q > 1 ? `${addon.title} × ${q}` : addon.title, price.price * q]);
      due += (Number(price.price) + Number(price.setup || 0)) * q;
      renew += addon.type === 'recurrent' ? price.price * q : 0;
      if (Number(price.setup) > 0) {
        lines.push([`${addon.title}: ${t.setup}`, price.setup * q]);
      }
    });
    return { lines, due: due * rate, renew: renew * rate };
  };

  const fieldsMissing = () => {
    for (const field of $$('[data-bf-field]')) {
      const inputs = [...field.querySelectorAll('input, textarea, select')];
      const required = inputs.some((input) => input.required);
      if (!required) {
        continue;
      }
      const filled = inputs.some((input) => ((input.type === 'radio' || input.type === 'checkbox') ? input.checked : input.value.trim() !== ''));
      if (!filled) {
        return field.dataset.label;
      }
    }
    return null;
  };

  const signupMissing = () => !state.signedIn && $$('[data-bf-signup] input, [data-bf-signup] select').some((input) => input.value.trim() === '');

  const selectedMethod = () => form.querySelector('input[name="gateway_id"]:checked');

  const render = () => {
    const now = compute(state.cycle);
    const due = state.promo ? state.promo.total * rate : now.due;

    // Cycle switch with each cycle's total.
    $$('[data-bf-cycles] [data-cycle]').forEach((button) => {
      const code = button.dataset.cycle;
      button.classList.toggle('is-on', code === state.cycle);
      button.setAttribute('aria-pressed', code === state.cycle ? 'true' : 'false');
      button.querySelector('[data-cycle-total]').textContent = money(compute(code).due);
    });
    showPrices(root, options, recurrent ? state.cycle : 'once', money, rate, recurrent ? (cycles[state.cycle]?.per ?? '') : '');
    const onceLine = $('[data-bf-once-line]');
    if (onceLine) {
      onceLine.textContent = product.type === 'free' ? t.free : `${t.once}: ${money(now.due)}`;
    }

    // Add-ons follow the plan's cycle.
    (data.addons ?? []).forEach((addon) => {
      const row = $(`[data-bf-addon="${addon.id}"]`);
      const price = addonPrice(addon, state.cycle);
      const choice = state.addons[addon.id];
      const toggle = row.querySelector('[data-addon-toggle]');
      const stepper = row.querySelector('[data-bf-stepper]');
      row.classList.toggle('is-unavailable', !price);
      toggle.disabled = !price;
      if (!price) {
        choice.on = false;
      }
      toggle.setAttribute('aria-checked', choice.on ? 'true' : 'false');
      if (stepper) {
        stepper.hidden = !choice.on;
        stepper.querySelector('input').value = String(choice.qty);
      }
      row.querySelector('[data-addon-each]').textContent = !price
        ? t.notOffered
        : (addon.type === 'recurrent'
          ? fill(t.each, { amount: money(price.price * rate), per: cycles[state.cycle]?.per ?? '' })
          : fill(t.eachOnce, { amount: money(price.price * rate) }));
    });

    // Lines per textarea.
    $$('[data-bf-field] textarea').forEach((box) => {
      const chip = box.closest('[data-bf-field]').querySelector('[data-lines]');
      const count = box.value.split(/\n/).map((line) => line.trim()).filter(Boolean).length;
      if (chip) {
        chip.hidden = count === 0;
        chip.textContent = count === 1 ? t.line : fill(t.lines, { count });
      }
    });

    // Summary.
    $('[data-bf-lines]').replaceChildren(...now.lines.map(([label, amount]) => {
      const line = document.createElement('div');
      line.className = 'bf-line';
      const name = document.createElement('span');
      name.textContent = label;
      const value = document.createElement('span');
      value.textContent = money(amount * rate);
      line.append(name, value);
      return line;
    }));
    const promoLine = $('[data-bf-promo-line]');
    promoLine.hidden = !state.promo;
    if (state.promo) {
      $('[data-bf-promo-label]').textContent = fill(t.promo, { code: state.promo.code });
      $('[data-bf-promo-amount]').textContent = `−${money(state.promo.discount * rate)}`;
    }
    const total = withTax(due);
    const taxLine = $('[data-bf-tax-line]');
    if (taxLine) {
      taxLine.hidden = !(taxRate > 0 && due > 0);
      $('[data-bf-tax-label]').textContent = `${data.tax?.name || t.tax} (${taxRate}%)`;
      $('[data-bf-tax-amount]').textContent = money(total - due);
    }
    $$('[data-bf-due]').forEach((el) => { el.textContent = money(total); });
    const then = $('[data-bf-then]');
    if (recurrent && now.renew > 0) {
      const renewAt = new Date();
      renewAt.setDate(renewAt.getDate() + Math.round(MONTHS[state.cycle] * 30.4375));
      then.textContent = fill(t.then, {
        amount: money(withTax(now.renew)),
        per: cycles[state.cycle]?.per ?? '',
        date: renewAt.toLocaleDateString(document.documentElement.lang || undefined, { month: 'short', day: 'numeric', year: 'numeric' }),
      });
      then.hidden = false;
    } else {
      then.hidden = true;
    }
    $('[data-bf-promo]').hidden = !!state.promo;
    $('[data-bf-promo-remove]').hidden = !state.promo;

    // Payment: nothing to pay, paid from the balance, or a payment method.
    const free = total <= 0;
    // The balance is already in the client's currency.
    const fromBalance = !free && state.signedIn && Number(data.balance) >= total;
    const methods = $('[data-bf-methods]');
    methods.hidden = free || fromBalance;
    const method = selectedMethod();
    const methodName = method?.dataset.title ?? '';
    const manual = method?.dataset.manual === '1';

    let label;
    let disabled = false;
    const missing = fieldsMissing();
    if (!state.signedIn && (!data.signup || state.accountReady)) {
      label = t.signIn;
      disabled = true;
    } else if (missing) {
      label = fill(t.fillIn, { field: missing });
      disabled = true;
    } else if (signupMissing()) {
      label = t.account;
      disabled = true;
    } else if (free) {
      label = t.placeFree;
    } else if (fromBalance) {
      label = fill(t.payBalance, { amount: money(total) });
    } else if (!method) {
      label = t.placeFree;
      disabled = true;
    } else if (manual) {
      label = fill(t.placeOrder, { amount: money(total) });
    } else {
      label = fill(t.pay, { amount: money(total), method: methodName });
    }
    $$('[data-bf-pay]').forEach((button) => {
      button.textContent = label;
      button.disabled = disabled || state.busy;
    });
    let hint = '';
    if (free) {
      hint = t.hintFree;
    } else if (fromBalance) {
      hint = t.hintBalance;
    } else if (method) {
      hint = fill(manual ? t.hintManual : t.hintOnline, { method: methodName });
    }
    $('[data-bf-hint]').textContent = hint;
  };

  const cartItem = () => {
    const item = { id: product.id };
    if (recurrent) {
      item.period = state.cycle;
    }
    if (product.quantity) {
      item.quantity = state.qty;
    }
    const addons = {};
    (data.addons ?? []).forEach((addon) => {
      const choice = state.addons[addon.id];
      const on = choice.on && !!addonPrice(addon, state.cycle);
      addons[addon.id] = { selected: on ? 1 : 0 };
      if (on && addon.type === 'recurrent') {
        addons[addon.id].period = state.cycle;
      }
      if (on && addon.quantity) {
        addons[addon.id].quantity = choice.qty;
      }
    });
    if (Object.keys(addons).length) {
      item.addons = addons;
    }
    if (options.length) {
      item.options = readChoice(root, options);
    }
    $$('[data-bf-field]').forEach((field) => {
      field.querySelectorAll('input, textarea, select').forEach((input) => {
        if (!input.name) {
          return;
        }
        if (input.type === 'checkbox') {
          const name = input.name.replace(/\[\]$/, '');
          item[name] ??= [];
          if (input.checked) {
            item[name].push(input.value);
          }
        } else if (input.type === 'radio') {
          if (input.checked) {
            item[input.name] = input.value;
          }
        } else {
          item[input.name] = input.value;
        }
      });
    });
    return item;
  };

  // A change of choice after a promo code was applied: the cart is filled again at Pay, so the
  // code has to be applied again to see its effect.
  const choiceChanged = () => {
    if (state.promo) {
      $('[data-bf-promo-input]').value = state.promo.code;
      state.promo = null;
      const msg = $('[data-bf-promo-msg]');
      msg.textContent = t.promoAgain;
      msg.hidden = false;
    }
    render();
  };

  root.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    if (!target || state.busy) {
      return;
    }
    const cycleButton = target.closest('[data-bf-cycles] [data-cycle]');
    const toggle = target.closest('[data-addon-toggle]');
    const step = target.closest('[data-bf-stepper] [data-step]');
    if (cycleButton) {
      state.cycle = cycleButton.dataset.cycle;
      choiceChanged();
    } else if (toggle && !toggle.disabled) {
      const id = toggle.closest('[data-bf-addon]').dataset.bfAddon;
      state.addons[id].on = !state.addons[id].on;
      choiceChanged();
    } else if (step) {
      const stepper = step.closest('[data-bf-stepper]');
      const input = stepper.querySelector('input');
      const value = Math.max(1, (parseInt(input.value, 10) || 1) + Number(step.dataset.step));
      setQty(stepper, value);
    } else if (target.closest('[data-bf-promo-apply]')) {
      applyPromo();
    } else if (target.closest('[data-bf-promo-remove]')) {
      state.promo = null;
      render();
    }
  });

  const setQty = (stepper, value) => {
    if (stepper.dataset.bfStepper === 'product') {
      state.qty = value;
    } else {
      state.addons[stepper.closest('[data-bf-addon]').dataset.bfAddon].qty = value;
    }
    choiceChanged();
  };

  root.addEventListener('change', (event) => {
    const input = event.target;
    if (input instanceof HTMLInputElement && input.closest('[data-bf-stepper]')) {
      setQty(input.closest('[data-bf-stepper]'), Math.max(1, parseInt(input.value, 10) || 1));
    } else if (input instanceof Element && input.closest('[data-cf-options]')) {
      choiceChanged();
    } else {
      render();
    }
  });
  root.addEventListener('input', () => render());

  const promoMessage = (text) => {
    const msg = $('[data-bf-promo-msg]');
    msg.textContent = text;
    msg.hidden = !text;
  };

  async function applyPromo() {
    const code = $('[data-bf-promo-input]').value.trim();
    if (!code) {
      return;
    }
    // The code is checked on this plan alone: cart/add_item without `multiple` empties the cart.
    if (Number(data.cartCount) > 0 && !window.confirm(fill(t.promoOthers, { count: data.cartCount }))) {
      return;
    }
    promoMessage('');
    setBusy(true);
    try {
      await api('guest', 'cart/add_item', cartItem());
      state.planInCart = true;
      data.cartCount = 0;
      await api('guest', 'cart/apply_promo', { promocode: code });
      const cart = await api('guest', 'cart/get');
      if (!(Number(cart.discount) > 0)) {
        throw new Error(t.promoInvalid);
      }
      // The cart's totals are in the default currency, like the product prices.
      state.promo = { code, discount: Number(cart.discount), total: Number(cart.total) };
    } catch (error) {
      state.promo = null;
      promoMessage(errorText(error));
    } finally {
      setBusy(false);
    }
  }

  const setBusy = (busy) => {
    state.busy = busy;
    root.toggleAttribute('aria-busy', busy);
    render();
  };

  // The account is made but not signed in ("auto login after sign-up" off, or the email had an
  // account, which FOSSBilling never says): sign in, then back to this order and its cycle.
  const showAccountReady = () => {
    const back = window.location.pathname + (state.cycle ? `?period=${encodeURIComponent(state.cycle)}` : '');
    const login = new URL(data.urls.login, window.location.href);
    login.searchParams.set('back', back);
    const ready = $('[data-bf-account-ready]');
    ready.querySelector('a').href = login.href;
    $$('[data-bf-signup-part]').forEach((part) => { part.hidden = true; });
    ready.hidden = false;
    state.accountReady = true;
    ready.focus();
  };

  const showDone = (line, primaryText, primaryHref, servicesLink) => {
    form.hidden = true;
    const done = $('[data-bf-done]');
    done.querySelector('[data-bf-done-line]').textContent = `${product.title}${recurrent ? `, ${(cycles[state.cycle]?.label ?? '').toLowerCase()}` : ''}. ${line}`;
    const primary = done.querySelector('[data-bf-done-primary]');
    primary.textContent = primaryText;
    primary.href = primaryHref;
    done.querySelector('[data-bf-done-services]').hidden = !servicesLink;
    done.hidden = false;
    document.querySelectorAll('[data-bf-steps] .bf-step').forEach((step) => {
      const n = Number(step.dataset.step);
      step.classList.toggle('is-done', n < 3);
      step.classList.toggle('is-on', n === 3);
      step.toggleAttribute('aria-current', n === 3);
      if (n === 3) {
        step.setAttribute('aria-current', 'step');
      }
    });
    const compact = document.querySelector('[data-bf-steps-compact]');
    if (compact) {
      compact.textContent = fill(t.stepCompact, { n: 3, label: t.stepDone });
    }
    window.scrollTo({ top: 0 });
    done.focus();
  };

  // Add to cart keeps what is in the cart and opens the cart page; only the order details are checked.
  $('[data-bf-add-cart]')?.addEventListener('click', async () => {
    if (state.busy) {
      return;
    }
    const invalid = $$('[data-bf-field] input, [data-bf-field] textarea, [data-bf-field] select').find((input) => !input.checkValidity());
    if (invalid) {
      invalid.reportValidity();
      return;
    }
    setBusy(true);
    try {
      // After Apply the cart holds this plan alone (perhaps with old choices): replace it, not add a second.
      await api('guest', 'cart/add_item', state.planInCart ? cartItem() : { ...cartItem(), multiple: 1 });
      if (state.promo) {
        await api('guest', 'cart/apply_promo', { promocode: state.promo.code });
      }
      window.location.assign(data.urls.cart);
    } catch (error) {
      setBusy(false);
      ClientFence.message(errorText(error), 'error');
    }
  });

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (state.busy) {
      return;
    }
    // Pay orders this plan alone: cart/add_item empties the cart first.
    if (Number(data.cartCount) > 0 && !window.confirm(fill(t.cartOthers, { count: data.cartCount }))) {
      return;
    }
    const invalid = [...form.querySelectorAll('input, textarea, select')].find((input) => !input.closest('[hidden]') && !input.checkValidity());
    if (invalid) {
      invalid.reportValidity();
      return;
    }
    setBusy(true);
    try {
      if (!state.signedIn) {
        const fields = Object.fromEntries(new FormData(form).entries());
        const account = {
          first_name: fields.first_name,
          last_name: fields.last_name,
          email: fields.email,
          password: fields.password,
          password_confirm: fields.password,
          // ClientFence: the country decides the order's tax.
          country: fields.country,
        };
        // ClientFence: each CAPTCHA provider posts its own answer field.
        for (const name of ['g-recaptcha-response', 'cf-turnstile-response', 'h-captcha-response']) {
          if (fields[name] !== undefined) {
            account[name] = fields[name];
          }
        }
        await api('guest', 'client/create', account);
        // With "auto login after sign-up" on (Settings → Clients), the sign-up signed in too, with
        // its own CAPTCHA. Otherwise the customer signs in on the sign-in page (a second sign-in
        // here would need a second CAPTCHA, which the login refuses).
        if (!(await api('client', 'profile/get').then(() => true, () => false))) {
          showAccountReady();
          setBusy(false);
          return;
        }
        state.signedIn = true;
      }
      const promo = state.promo;
      await api('guest', 'cart/add_item', cartItem());
      if (promo) {
        await api('guest', 'cart/apply_promo', { promocode: promo.code });
      }
      const now = compute(state.cycle);
      const due = withTax(promo ? promo.total * rate : now.due);
      const fromBalance = due > 0 && Number(data.balance) >= due;
      const method = selectedMethod();
      const checkout = {};
      if (due > 0 && !fromBalance && method) {
        checkout.gateway_id = method.value;
      }
      const result = await api('client', 'cart/checkout', checkout);
      if (result?.invoice_hash && method && method.dataset.manual !== '1' && checkout.gateway_id) {
        window.location.assign(`${data.urls.banklink}/${result.invoice_hash}/${checkout.gateway_id}`);
        return;
      }
      if (result?.invoice_hash) {
        showDone(fill(t.doneManual, { method: method?.dataset.title ?? '' }), t.doneInvoice, `${data.urls.invoice}/${result.invoice_hash}`, true);
      } else {
        showDone(fromBalance ? t.doneBalance : t.doneFree, t.doneService, `${data.urls.service}/${result?.order_id ?? ''}`, false);
      }
      setBusy(false);
    } catch (error) {
      setBusy(false);
      ClientFence.message(fill(t.failed, { error: errorText(error) }), 'error');
    }
  });

  render();
}
