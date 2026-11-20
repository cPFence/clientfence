/**
 * A hosting plan's domain on the one-page checkout (mod_servicehosting_order_form.html.twig, CF-146): the choice
 * the plan allows (register, transfer, own domain, free subdomain), the availability or transfer check through
 * ClientFenceDomainCaptcha (the CAPTCHA and rate limit of every domain search), the TLD's prices from
 * servicedomain/pricing (default-currency equivalents, as the plan's prices), the years and the transfer code.
 * checkout.js reads it: fields() for the cart item, priced() for the summary, missing() for the Pay button.
 */
import { api, errorText } from './store-utils.js';

const SEARCHES = { register: 'servicedomain/check', transfer: 'servicedomain/can_be_transferred' };

/** A registration's price for some years, as Product\Service::getDomainRegistrationTotal(). */
export function registrationTotal(prices, years) {
  if (years <= 0) {
    return 0;
  }
  return (Number(prices.price_registration) || 0) + (years - 1) * (Number(prices.price_renew) || 0);
}

export default function initHostingDomain(root, money, rate, texts, onChange) {
  const form = root.closest('form');
  const action = root.querySelector('[name="domain[action]"]');
  const field = (name) => root.querySelector(`[name="domain[${name}]"]`);
  const pane = (name) => root.querySelector(`[data-domain-pane="${name}"]`);
  // A register or transfer search: the TLD's prices once checked; run drops answers to an older search.
  const checks = { register: { prices: null, run: 0 }, transfer: { prices: null, run: 0 } };
  const codeNeeded = () => field('transfer_tld')?.selectedOptions[0]?.dataset.requireTransferCode === '1';

  const paint = () => {
    root.querySelectorAll('[data-domain-action]').forEach((button) => {
      const on = button.dataset.domainAction === action.value;
      button.classList.toggle('is-on', on);
      button.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    root.querySelectorAll('[data-domain-pane]').forEach((box) => { box.hidden = box.dataset.domainPane !== action.value; });
    Object.entries(checks).forEach(([name, check]) => {
      const box = pane(name);
      if (box) {
        box.querySelector('[data-domain-check]').hidden = !!check.prices;
        box.querySelector('[data-domain-config]').hidden = !check.prices;
      }
    });
    const code = field('transfer_code');
    if (code) {
      code.required = action.value === 'transfer' && !!checks.transfer.prices && codeNeeded();
      root.querySelector('#transfer-code-hint').hidden = !code.required;
    }
  };
  const changed = () => {
    paint();
    onChange();
  };

  // A new name or TLD needs a new check.
  const reset = (name) => {
    checks[name].run++;
    if (checks[name].prices) {
      checks[name].prices = null;
      changed();
    }
  };

  // The TLD's allowed years (as the domain order form's search offers them), each with its total.
  const fillYears = (prices) => {
    let years = Array.isArray(prices.periods) && prices.periods.length ? prices.periods.map(Number) : null;
    if (!years) {
      const min = Number(prices.min_years) > 0 ? Number(prices.min_years) : 1;
      // Five years from the minimum unless the TLD lists longer terms (some registries cap them, .co: 5 years).
      years = Array.from({ length: 5 }, (_, i) => min + i);
    }
    // A registration holds at most 10 years (Servicedomain\Service::validateOrderData()).
    field('register_years').replaceChildren(...years.filter((y) => y >= 1 && y <= 10)
      .map((y) => new Option(`${y}${y === 1 ? texts.year : texts.years}${money(registrationTotal(prices, y) * rate)}`, y)));
  };

  const search = (name) => {
    const run = ++checks[name].run;
    const params = { sld: field(`${name}_sld`).value.toLowerCase(), tld: field(`${name}_tld`).value.toLowerCase() };
    window.ClientFenceDomainCaptcha.post(SEARCHES[name], params, () => {
      api('guest', 'servicedomain/pricing', { tld: params.tld }).then((prices) => {
        if (run !== checks[name].run) {
          return;
        }
        if (name === 'register') {
          fillYears(prices);
        } else {
          root.querySelector('#transfer-price').textContent = money((Number(prices.price_transfer) || 0) * rate);
        }
        checks[name].prices = prices;
        changed();
      }, (error) => ClientFence.message(errorText(error), 'error'));
    });
  };

  root.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    const choice = target?.closest('[data-domain-action]');
    const check = target?.closest('[data-domain-check]');
    if (choice) {
      action.value = choice.dataset.domainAction;
      changed();
    } else if (check) {
      search(check.closest('[data-domain-pane]').dataset.domainPane);
    }
  });
  root.addEventListener('input', (event) => {
    const name = /^domain\[(register|transfer)_sld\]$/.exec(event.target.name ?? '')?.[1];
    if (name) {
      reset(name);
    }
  });
  root.addEventListener('change', (event) => {
    const name = /^domain\[(register|transfer)_tld\]$/.exec(event.target.name ?? '')?.[1];
    if (name) {
      reset(name);
    } else if (event.target.name === 'domain[register_years]') {
      onChange();
    }
  });
  // Enter in a domain name checks it (the checkout form is not sent).
  root.addEventListener('keydown', (event) => {
    const name = /^domain\[(register|transfer)_sld\]$/.exec(event.target.name ?? '')?.[1];
    if (event.key === 'Enter' && name) {
      event.preventDefault();
      if (!checks[name].prices) {
        search(name);
      }
    }
  });
  paint();

  return {
    /** The domain[...] fields as the configure form posted them (disabled TLD fields are left out). */
    fields() {
      const out = {};
      for (const [key, value] of new FormData(form)) {
        const match = /^domain\[([^\]]+)\](?:\[([^\]]+)\])?$/.exec(key);
        if (match && match[2]) {
          (out[match[1]] ??= {})[match[2]] = value;
        } else if (match) {
          out[match[1]] = value;
        }
      }
      return out;
    },
    /** A checked registration or transfer with its TLD's prices, or null. */
    priced() {
      const name = action.value;
      const prices = checks[name]?.prices;
      if (!prices) {
        return null;
      }
      const tld = field(`${name}_tld`).value;
      return {
        action: name,
        tld,
        domain: field(`${name}_sld`).value.toLowerCase() + tld,
        years: name === 'register' ? Number(field('register_years').value) || 1 : 1,
        prices,
      };
    },
    /** What the order still needs: 'check', 'code' (transfer code), 'own' or 'subdomain'; null when complete. */
    missing() {
      switch (action.value) {
        case 'register':
          return checks.register.prices ? null : 'check';
        case 'transfer':
          if (!checks.transfer.prices) {
            return 'check';
          }
          return field('transfer_code').required && !field('transfer_code').value.trim() ? 'code' : null;
        case 'owndomain':
          return field('owndomain_sld').value.trim() && field('owndomain_tld').value.trim() ? null : 'own';
        case 'subdomain':
          return field('subdomain_sld').value.trim() ? null : 'subdomain';
        default:
          return null;
      }
    },
  };
}
