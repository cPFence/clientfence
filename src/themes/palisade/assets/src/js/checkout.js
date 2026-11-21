/**
 * One-page checkout (mod_order_product.html.twig). Keeps the summary, the cycle totals, the
 * add-ons, the Pay button and the phone pay bar in step with the choices, and places the order:
 * Add to cart (the cart page) or, with Pay, sign-up for a new customer (signed in by FOSSBilling's "auto login after sign-up"), cart/add_item (which empties the cart first), the promo
 * code, cart/checkout, then the payment page (online methods) or the confirmation (manual
 * methods, balance, nothing to pay). Amounts shown before the promo code are the product
 * prices; with a promo code the cart's own totals are shown. A hosting plan's domain (CF-146) is its
 * own section (hosting-domain.js), priced here as the cart prices it.
 */
import { MONTHS, api, cycleTexts, errorText, fill, moneyFormatter, readJson } from './store-utils.js';
import { optionLines, readChoice, readOptions, showPrices } from './product-options.js';
import initHostingDomain, { registrationTotal } from './hosting-domain.js';

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
  // CF-146: a hosting plan's domain. A change of its price acts as a change of choice (and may apply a link's promo code).
  let afterDomain = () => {};
  const domainRoot = root.querySelector('[data-cf-hosting-domain]');
  const domain = domainRoot ? initHostingDomain(domainRoot, money, rate, t, () => { choiceChanged(); afterDomain(); }) : null;

  const state = {
    cycle: data.period || '',
    qty: 1,
    addons: Object.fromEntries((data.addons ?? []).map((addon) => [addon.id, { on: false, qty: 1 }])),
    promo: null, // { code, discount, total } in the default currency, once the cart accepted a code
    signedIn: !!data.signedIn,
    accountReady: false, // the account was made, but the sign-up did not sign in
    busy: false,
    planInCart: false, // Apply put this plan in the cart (and emptied it)
    choice: 0, // counts changes of choice, so an Apply that answers after one is dropped
  };

  const recurrent = product.type === 'recurrent';
  // The tax the invoice adds for a signed-in client (rounded once, as the invoice does). With prices that include
  // tax, the invoice asks for the price, less the tax for an exempt client (deduct).
  let inclusive = false;
  let taxRate = 0;
  let deduct = 0;
  const setTax = (tax) => {
    data.tax = tax;
    inclusive = !!tax?.inclusive;
    taxRate = inclusive ? 0 : Number(tax?.rate) || 0;
    deduct = inclusive ? Number(tax?.deduct) || 0 : 0;
  };
  setTax(data.tax);
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

  // The plan's free domain (Product\Service::getDomainRelatedDiscount()): a transfer with free_transfer; a registration
  // with free_domain, of a free TLD (any when none is set), on a billing cycle in free_domain_periods.
  const free = product.freeDomain ?? {};
  const freeTlds = Object.values(free.tlds ?? {});
  const freePeriods = Object.values(free.periods ?? {});
  /** The registration years a free domain covers for the plan's cycle, as Product\Service::getFreeDomainDiscountYears(). */
  const freeYears = (cycle, years) => {
    if (cycle === `${years}Y`) {
      return years;
    }
    if (!cycle.includes('Y')) {
      return 0;
    }
    if (Math.min(years, parseInt(cycle, 10)) === 1) {
      return 1;
    }
    const covered = freePeriods.map((period) => parseInt(period, 10)).filter((qty) => years - qty > 0);
    return covered.length ? Math.min(years, ...covered) : 0;
  };
  /** The domain line as the cart prices it (Product\Service::getDomainOrderLineConfig()), less the plan's free domain. */
  const domainLine = (cycle) => {
    const chosen = domain?.priced();
    if (!chosen) {
      return null;
    }
    const register = chosen.action === 'register';
    const price = register ? registrationTotal(chosen.prices, chosen.years) : Number(chosen.prices.price_transfer) || 0;
    let off = 0;
    if (!register && free.transfer) {
      off = price;
    } else if (register && free.domain && (!freeTlds.length || freeTlds.includes(chosen.tld)) && freePeriods.includes(cycle)) {
      off = registrationTotal(chosen.prices, freeYears(cycle, chosen.years));
    }
    const label = register ? `${chosen.domain} · ${chosen.years} ${chosen.years === 1 ? t.yearOne : t.yearMany}` : `${chosen.domain} · ${t.transfer}`;
    return { label, price, off };
  };

  /** Lines, today's total and the renewal amount for a cycle (without a promo code). */
  const compute = (cycle) => {
    const lines = [];
    let due = 0;
    let renew = 0;
    const qty = product.quantity ? state.qty : 1;
    const base = recurrent ? product.prices?.[cycle] : (product.type === 'once' ? product.once : { price: 0, setup: 0 });
    // CF-145: a prorata product first charges part of a period (with its add-ons), setup fees in full.
    const part = recurrent ? Number(product.prorata?.[cycle]?.factor ?? 1) : 1;
    if (base) {
      lines.push([qty > 1 ? `${product.title} × ${qty}` : product.title, base.price * qty * part]);
      due += (base.price * part + Number(base.setup || 0)) * qty;
      renew += recurrent ? base.price * qty : 0;
      if (Number(base.setup) > 0) {
        lines.push([t.setup, base.setup * qty]);
      }
      optionLines(options, readChoice(root, options), recurrent ? cycle : 'once').forEach((line) => {
        lines.push([line.title, line.price * qty * part]);
        due += (line.price * part + line.setup) * qty;
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
      const addonPart = addon.type === 'recurrent' ? part : 1;
      lines.push([q > 1 ? `${addon.title} × ${q}` : addon.title, price.price * q * addonPart]);
      due += (price.price * addonPart + Number(price.setup || 0)) * q;
      renew += addon.type === 'recurrent' ? price.price * q : 0;
      if (Number(price.setup) > 0) {
        lines.push([`${addon.title}: ${t.setup}`, price.setup * q]);
      }
    });
    // The domain, once for the order and never prorated; it renews yearly on its own (the renewal line is the plan's).
    const line = domainLine(cycle);
    if (line) {
      lines.push([line.label, line.price - line.off, line.price]);
      due += line.price - line.off;
    }
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

  // CF-239: a registered or transferred domain needs the registrant details (partials/registrant_fields): asked with the
  // sign-up, or the ones the profile lacks, while the plan's domain is a registration or transfer.
  const registrantBox = $('[data-cf-registrant]');
  const registrantAction = domainRoot?.querySelector('[name="domain[action]"]');
  const registrantShown = () => !!registrantBox && !registrantBox.hidden;
  const registrant = () => Object.fromEntries($$('[data-cf-registrant] [name]').map((input) => [input.name, input.value.trim()]));

  const signupMissing = () => [...(state.signedIn ? [] : $$('[data-bf-signup] input, [data-bf-signup] select')), ...(registrantShown() ? $$('[data-cf-registrant] [name]') : [])]
    .some((input) => input.required && input.value.trim() === '');

  const selectedMethod = () => form.querySelector('input[name="gateway_id"]:checked');

  /** Why the domain stops the order (the Pay button's text), or null. */
  const domainMissing = () => {
    const need = domain?.missing();
    if (!need) {
      return null;
    }
    return need === 'check' ? t.domainCheck : fill(t.fillIn, { field: { code: t.transferCode, own: t.domainName, subdomain: t.subdomain }[need] });
  };

  const render = () => {
    if (registrantBox) {
      registrantBox.hidden = state.accountReady || !['register', 'transfer'].includes(registrantAction?.value);
    }
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
    $('[data-bf-lines]').replaceChildren(...now.lines.map(([label, amount, gross]) => {
      const line = document.createElement('div');
      line.className = 'bf-line';
      const name = document.createElement('span');
      name.textContent = label;
      const value = document.createElement('span');
      // The domain line: a promo code that beats the free domain takes its place (Cart\Service::getProductDiscount()),
      // so the line shows its price and the promo line the cart's discount.
      const shown = state.promo && gross !== undefined && !state.promo.keepFree ? gross : amount;
      value.textContent = gross !== undefined && shown === 0 ? t.free : money(shown * rate);
      line.append(name, value);
      return line;
    }));
    const promoLine = $('[data-bf-promo-line]');
    // The cart's totals stay after an automatic promotion ended (state.promo with no discount): no "−0" line then.
    promoLine.hidden = !(state.promo?.discount > 0);
    if (!promoLine.hidden) {
      $('[data-bf-promo-label]').textContent = state.promo.code ? fill(t.promo, { code: state.promo.code }) : t.discount;
      $('[data-bf-promo-amount]').textContent = `−${money(state.promo.discount * rate)}`;
    }
    const total = withTax(due);
    const taxLine = $('[data-bf-tax-line]');
    if (taxLine) {
      taxLine.hidden = !(taxRate > 0 && due > 0);
      $('[data-bf-tax-label]').textContent = `${data.tax?.name || t.tax} (${taxRate}%)`;
      $('[data-bf-tax-amount]').textContent = money(total - due);
    }
    // Each method's gateway fee on this total, as the invoice will carry it (GatewayFees::fee()).
    const feeOf = (radio) => (total > 0 && radio ? Math.round(total * Number(radio.dataset.feePercent || 0) + Number(radio.dataset.feeFixed || 0) * 100) / 100 : 0);
    // What the fee adds to the invoice: a taxed fee adds its tax, rounded once with the order's taxed lines
    // (GatewayFees::taxOn(); before tax, so the price less its tax when prices include it).
    const realRate = Number(data.tax?.rate) || 0;
    const taxed = inclusive ? Math.round(due * 10000 / (100 + realRate)) / 100 : due;
    const taxOf = (amount) => (realRate > 0 && amount !== 0 ? Math.round(amount * realRate) / 100 : 0);
    const chargeOf = (radio) => {
      const fee = feeOf(radio);
      return fee > 0 && radio.dataset.feeTaxable === '1' ? Math.round((fee + taxOf(taxed + fee) - taxOf(taxed)) * 100) / 100 : fee;
    };
    $$('[data-pay-fee]').forEach((out) => {
      const fee = feeOf(out.closest('.bf-pay').querySelector('input[name="gateway_id"]'));
      out.hidden = !(fee > 0);
      out.textContent = fill(out.dataset.template, { fee: money(fee) });
    });
    const then = $('[data-bf-then]');
    const renew = now.renew - (state.promo ? state.promo.renewDiscount * rate : 0);
    if (recurrent && renew > 0) {
      const prorataDue = product.prorata?.[state.cycle]?.due;
      const renewAt = prorataDue ? new Date(`${prorataDue}T00:00:00`) : new Date();
      if (!prorataDue) {
        renewAt.setDate(renewAt.getDate() + Math.round(MONTHS[state.cycle] * 30.4375));
      }
      then.textContent = fill(t.then, {
        amount: money(withTax(renew)),
        per: cycles[state.cycle]?.per ?? '',
        date: renewAt.toLocaleDateString(document.documentElement.lang || undefined, { month: 'short', day: 'numeric', year: 'numeric' }),
      });
      then.hidden = false;
    } else {
      then.hidden = true;
    }
    // An automatic promotion cannot be removed; a typed code takes its place (Cart\Service::autoPromo()).
    $('[data-bf-promo]').hidden = !!state.promo && !state.promo.auto;
    $('[data-bf-promo-remove]').hidden = !state.promo || state.promo.auto;

    // Payment: nothing to pay, paid from the balance, or a payment method.
    const free = total <= 0;
    // The balance is already in the client's currency.
    const fromBalance = !free && state.signedIn && Number(data.balance) >= total;
    const methods = $('[data-bf-methods]');
    methods.hidden = free || fromBalance;
    const method = selectedMethod();
    // Due today as the Pay button: with the chosen method's fee (none when the balance pays).
    $$('[data-bf-due]').forEach((el) => { el.textContent = money(total + (free || fromBalance ? 0 : chargeOf(method))); });
    const methodName = method?.dataset.title ?? '';
    const manual = method?.dataset.manual === '1';

    let label;
    let disabled = false;
    const missing = fieldsMissing();
    const domainNeed = domainMissing();
    if (!state.signedIn && (!data.signup || state.accountReady)) {
      label = t.signIn;
      disabled = true;
    } else if (domainNeed) {
      label = domainNeed;
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
      label = fill(t.placeOrder, { amount: money(total + chargeOf(method)) });
    } else {
      label = fill(t.pay, { amount: money(total + chargeOf(method)), method: methodName });
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
    if (domain) {
      item.domain = domain.fields();
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
    state.choice += 1;
    if (state.promo?.auto) {
      state.promo = null;
    } else if (state.promo) {
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

  /** The summary from the cart's own totals (in the default currency): a typed code, or the cart's automatic promotion. */
  const promoFromCart = (cart, code, auto = false) => {
    // CF-146: a free domain the code does not beat stays the domain's own (promo_line false), not the code's discount.
    const kept = (cart.items ?? []).filter((item) => item.promo_line === false).reduce((sum, item) => sum + Number(item.discount ?? 0), 0);
    // A recurring promo also comes off each renewal: the order keeps the price part of the discount (the full
    // period's, not a prorated first charge's). CF-146: not a domain's discount (the domain renews on its own).
    const renewDiscount = cart.promo_recurring ? (cart.items ?? []).filter((item) => item.type !== 'domain').reduce((sum, item) => sum + Number(item.recurring_discount ?? item.discount_price ?? 0), 0) : 0;
    return { code, auto, discount: Number(cart.discount) - kept, total: Number(cart.total), renewDiscount, keepFree: kept > 0 };
  };

  async function applyPromo() {
    const code = $('[data-bf-promo-input]').value.trim();
    if (!code) {
      return;
    }
    // The cart takes a hosting plan only with its domain.
    if (domainMissing()) {
      promoMessage(domainMissing());
      return;
    }
    // The code is checked on this plan alone: cart/add_item without `multiple` empties the cart.
    if (Number(data.cartCount) > 0 && !data.linkRedo && !window.confirm(fill(t.promoOthers, { count: data.cartCount }))) {
      return;
    }
    promoMessage('');
    setBusy(true);
    const choice = state.choice;
    try {
      await api('guest', 'cart/add_item', cartItem());
      state.planInCart = true;
      data.cartCount = 0;
      await api('guest', 'cart/apply_promo', { promocode: code });
      const cart = await api('guest', 'cart/get');
      // CF-146: the domain section stays usable meanwhile; the cart priced the old choice.
      if (choice !== state.choice) {
        throw new Error(t.promoAgain);
      }
      const promo = promoFromCart(cart, code);
      if (!(promo.discount > 0)) {
        throw new Error(t.promoInvalid);
      }
      state.promo = promo;
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
    const query = new URLSearchParams();
    if (state.cycle) {
      query.set('period', state.cycle);
    }
    // An applied promo code comes back too, as from a store link's ?promo=.
    if (state.promo) {
      query.set('promo', state.promo.code);
    }
    const back = window.location.pathname + (query.toString() ? `?${query}` : '');
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
    if (domainMissing()) {
      ClientFence.message(domainMissing(), 'error');
      return;
    }
    setBusy(true);
    try {
      // After Apply the cart holds this plan alone (perhaps with old choices): replace it, not add a second.
      await api('guest', 'cart/add_item', state.planInCart ? cartItem() : { ...cartItem(), multiple: 1 });
      if (state.promo && !state.promo.auto) {
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
          // ClientFence: the EU VAT number (shown when EU VAT numbers are checked) can make the order VAT free.
          company_vat: fields.company_vat,
        };
        // ClientFence: the client custom fields shown on the order form (custom_1 to custom_20).
        for (const [name, value] of Object.entries(fields)) {
          if (/^custom_\d+$/.test(name)) {
            account[name] = value;
          }
        }
        // ClientFence: each CAPTCHA provider posts its own answer field.
        for (const name of ['g-recaptcha-response', 'cf-turnstile-response', 'h-captcha-response']) {
          if (fields[name] !== undefined) {
            account[name] = fields[name];
          }
        }
        // ClientFence (CF-239): the registrant details of a domain registration or transfer.
        if (registrantShown()) {
          Object.assign(account, registrant());
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
      } else if (registrantShown()) {
        // ClientFence (CF-239): the details the profile lacks, saved before the domain is ordered.
        const saved = registrant();
        await api('client', 'profile/update', saved);
        // A new country can change the invoice's tax: show the new total first, as for a changed cart total (CF-146).
        if ('country' in saved) {
          const before = [taxRate, deduct];
          setTax(await api('client', 'clientfence/tax'));
          if (before[0] !== taxRate || before[1] !== deduct) {
            setBusy(false);
            render();
            ClientFence.message(fill(t.totalChanged, { amount: money(withTax(state.promo ? state.promo.total * rate : compute(state.cycle).due)) }), 'info');
            return;
          }
        }
      }
      const promo = state.promo;
      await api('guest', 'cart/add_item', cartItem());
      state.planInCart = true;
      data.cartCount = 0;
      if (promo && !promo.auto) {
        await api('guest', 'cart/apply_promo', { promocode: promo.code });
      }
      // CF-146: the order is charged only at the total shown. The cart can differ (an automatic promotion, which the
      // page cannot price): show the cart's totals and let the client select the button again.
      const cart = await api('guest', 'cart/get');
      if (Math.abs(Number(cart.total) - (promo ? promo.total : compute(state.cycle).due / rate)) > 0.004) {
        state.promo = promoFromCart(cart, cart.promocode ?? cart.auto_promo ?? '', !cart.promocode);
        setBusy(false);
        ClientFence.message(fill(t.totalChanged, { amount: money(withTax(state.promo.total * rate)) }), 'info');
        return;
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
      } else if (result?.id_document) {
        // ClientFence: the order waits for the client's ID document (Client/IdDocuments.php).
        showDone(t.doneIdDocument, t.doneIdButton, data.urls.identity, true);
      } else if (result?.held) {
        // ClientFence: the order waits for staff review (Order/Holds.php); its invoice comes after approval.
        showDone(t.doneHeld, t.doneService, `${data.urls.service}/${result.order_id ?? ''}`, false);
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
  // A store link's ?promo= code goes through the promo box, with all its checks and messages. It waits in the
  // box while the cart holds other items (applying it here empties the cart; not this link's own plan and code,
  // data.linkRedo), and is applied once the required order questions are answered and a hosting plan's domain is
  // chosen (the cart refuses the plan without them).
  if (data.promo) {
    $('[data-bf-promo-input]').value = data.promo;
    const required = [...root.querySelectorAll('[data-bf-field] [required]')];
    let pending = !(Number(data.cartCount) > 0) || data.linkRedo;
    const applyLink = () => {
      if (pending && !state.busy && !domainMissing() && required.every((input) => input.checkValidity())) {
        pending = false;
        applyPromo();
      }
    };
    required.forEach((input) => input.addEventListener('change', applyLink));
    domainRoot?.addEventListener('change', applyLink);
    afterDomain = applyLink;
    applyLink();
  }
}
