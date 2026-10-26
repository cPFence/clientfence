/**
 * Helpers shared by the store, checkout and payment page scripts.
 */

/** Months in each FOSSBilling period code. */
export const MONTHS = { '1W': 0.25, '1M': 1, '3M': 3, '6M': 6, '1Y': 12, '2Y': 24, '3Y': 36 };

/**
 * Formats amounts as the templates do (store_macros money(): en_US, ".00" dropped), so
 * "$13.50", "$5", "$1,200".
 */
/** The page's language for number formats, as the server formats money (German "259,05 EGP"). */
function pageLocale() {
  const lang = document.documentElement.lang || 'en-US';
  // Arabic money as the server formats it: "$3.69" (Currency\Service::moneyLocale()).
  return /^ar\b/i.test(lang) ? 'en-US' : lang;
}

export function moneyFormatter(currency) {
  let format = null;
  try {
    format = new Intl.NumberFormat(pageLocale(), { style: 'currency', currency, minimumFractionDigits: 2, maximumFractionDigits: 2 });
  } catch {
    format = null;
  }
  return (value) => {
    const amount = Math.round((Number(value) || 0) * 100) / 100;
    const text = format ? format.format(amount) : `${amount.toFixed(2)} ${currency}`;
    return text.replace(/[.,]00(?!\d)/, '');
  };
}

/** An amount converted at an EGP method's rate, as CustomEgp shows it: rounded up to a whole pound. */
export function quoteText(amount, rate, currency) {
  const total = Math.ceil((Number(amount) || 0) * Number(rate));
  return `${currency} ${total.toLocaleString(pageLocale())}`;
}

/** Reads a JSON <script> inside root. */
export function readJson(root, selector) {
  const el = root.querySelector(selector);
  if (!el) {
    return {};
  }
  try {
    return JSON.parse(el.textContent);
  } catch {
    return {};
  }
}

/** Fills :name placeholders. */
export function fill(text, values) {
  return Object.entries(values).reduce((out, [key, value]) => out.split(`:${key}`).join(String(value)), String(text ?? ''));
}

/** A FOSSBilling API call as a promise. role: guest or client. */
export function api(role, endpoint, data = {}) {
  return new Promise((resolve, reject) => {
    ClientFence.api[role].post(endpoint, data, resolve, (error) => reject(error), false);
  });
}

/** The error text of a failed API call. */
export function errorText(error) {
  if (error && typeof error === 'object') {
    return error.message || error.code || 'Error';
  }
  return String(error ?? 'Error');
}

/** The cycle texts the templates pass (a JSON string inside the JSON). */
export function cycleTexts(value) {
  if (typeof value === 'string') {
    try {
      return JSON.parse(value);
    } catch {
      return {};
    }
  }
  return value ?? {};
}
