/**
 * The domain order form's register search (mod_servicedomain_order_form.html.twig, CF-139): one name across
 * the ticked TLDs (up to 20). servicedomain/check_bulk answers the first TLDs and a search id; each call with
 * the id answers the next ones, so the rows fill in as they arrive. The chosen domains go to the cart one
 * cart/add_item each (the period of their row, the nameservers and their TLD's domain fields), then the cart opens.
 */
import { api, errorText, moneyFormatter, readJson } from './store-utils.js';

export default function initDomainSearch(root) {
  const form = root.closest('form');
  const data = readJson(root, '[data-cf-domain-search-data]');
  const texts = data.texts || {};
  const money = moneyFormatter(data.currency);
  const input = root.querySelector('input[name="register_sld"]');
  const button = root.querySelector('[data-cf-ds-check]');
  const list = root.querySelector('[data-cf-ds-results]');
  const after = root.querySelector('.onAfterDomainCheck');
  const boxes = [...root.querySelectorAll('[data-cf-ds-tld]')];
  const configNext = document.getElementById('config-next');
  let sld = '';

  const ticked = () => boxes.filter((box) => box.checked).map((box) => box.value);
  // At the limit the unticked boxes rest; the server refuses more in any case.
  const limit = () => {
    const full = ticked().length >= data.max;
    boxes.forEach((box) => { box.disabled = full && !box.checked; });
  };
  const chosen = () => [...list.querySelectorAll('[data-cf-ds-choose]:checked')].map((box) => box.closest('[data-tld]'));
  const fieldsOf = (tld) => form.querySelector(`[data-tld-select="register_tld"][data-domain-fields="${CSS.escape(tld)}"]`);

  // The chosen TLDs' domain fields show (and are sent); Add to cart waits for a chosen domain.
  const refresh = () => {
    const tlds = chosen().map((row) => row.dataset.tld);
    form.querySelectorAll('[data-tld-select="register_tld"]').forEach((block) => {
      const on = tlds.includes(block.dataset.domainFields);
      block.hidden = !on;
      block.querySelectorAll('input, select').forEach((field) => { field.disabled = !on; });
    });
    if (configNext) {
      configNext.disabled = tlds.length === 0;
    }
  };

  // The periods of a TLD with their totals, as the single check offered them.
  const periodSelect = (result) => {
    const select = document.createElement('select');
    select.className = 'form-select form-select-sm';
    select.setAttribute('aria-label', result.domain);
    const reg = Number(result.price_registration);
    const renew = Number.isNaN(Number(result.price_renew)) || result.price_renew === null ? reg : Number(result.price_renew);
    let years = Array.isArray(result.periods) && result.periods.length ? result.periods : null;
    if (!years) {
      const min = Number(result.min_years) > 0 ? Number(result.min_years) : 1;
      years = [0, 1, 2, 3, 4].map((i) => min + i);
    }
    years.forEach((i) => {
      const total = (reg + (i - 1) * renew) * Number(data.rate);
      select.append(new Option(`${i}${i === 1 ? texts.year : texts.years}${money(total)}`, i));
    });
    return select;
  };

  const addRow = (result) => {
    const row = document.createElement('li');
    row.className = 'cf-ds-row';
    row.dataset.tld = result.tld;
    const name = document.createElement('label');
    name.className = 'cf-ds-name';
    const choose = document.createElement('input');
    choose.type = 'checkbox';
    choose.className = 'form-check-input';
    choose.dataset.cfDsChoose = '';
    choose.disabled = result.available !== true;
    // The first free domain is chosen, as one check offered it; from the embedded checker all its chosen ones.
    choose.checked = result.available === true && (data.auto || !list.querySelector('[data-cf-ds-choose]:checked'));
    const domain = document.createElement('span');
    domain.dir = 'ltr';
    domain.textContent = result.domain;
    name.append(choose, ' ', domain);
    const status = document.createElement('span');
    status.className = `badge ${result.available === true ? 'text-bg-success' : (result.available === false ? 'text-bg-secondary' : 'text-bg-warning')}`;
    status.textContent = result.available === true ? texts.available : (result.available === false ? texts.taken : texts.unknown);
    row.append(name, status);
    if (result.available === true) {
      row.append(periodSelect(result));
    }
    list.querySelector('.cf-ds-wait')?.before(row);
  };

  const search = async () => {
    if (button.disabled) {
      return; // a search is running (Enter while it loads)
    }
    const typed = input.value.trim().toLowerCase();
    const dot = typed.indexOf('.');
    sld = dot === -1 ? typed : typed.slice(0, dot);
    const tlds = ticked();
    // "example.com" also checks .com, as WHMCS does.
    if (dot !== -1 && !tlds.includes(typed.slice(dot))) {
      tlds.unshift(typed.slice(dot));
    }
    if (!sld || !tlds.length) {
      input.focus();
      return;
    }
    document.getElementById('domain-action').value = 'register';
    if (configNext) {
      configNext.style.display = 'none';
    }
    button.disabled = true;
    list.replaceChildren();
    const wait = document.createElement('li');
    wait.className = 'cf-ds-wait';
    wait.setAttribute('aria-hidden', 'true');
    list.append(wait);
    list.hidden = false;
    list.setAttribute('aria-busy', 'true');
    refresh();

    const captcha = window.ClientFenceDomainCaptcha;
    try {
      // After the counted search the CAPTCHA hides once passed, or a fresh one shows (each answer works once).
      let answer = await api('guest', 'servicedomain/check_bulk', { sld, tlds, ...(captcha ? await captcha.fields() : {}) })
        .finally(() => captcha?.after());
      answer.results.forEach(addRow);
      while (answer.search) {
        answer = await api('guest', 'servicedomain/check_bulk', { search: answer.search });
        answer.results.forEach(addRow);
      }
    } catch (error) {
      ClientFence.message(errorText(error), 'error');
    }
    wait.remove();
    list.removeAttribute('aria-busy');
    list.hidden = !list.querySelector('.cf-ds-row');
    button.disabled = false;
    if (list.querySelector('[data-cf-ds-choose]:not(:disabled)')) {
      after.style.display = '';
      if (configNext) {
        configNext.style.display = '';
      }
    }
    refresh();
  };

  // Add to cart with a register search: each chosen domain is its own cart item, as one check added it.
  const addToCart = async () => {
    const rows = chosen();
    if (!rows.length) {
      return;
    }
    configNext.disabled = true;
    try {
      for (const row of rows) {
        const body = new FormData(form);
        [...body.keys()].filter((key) => key.startsWith('domain_fields[')).forEach((key) => body.delete(key));
        fieldsOf(row.dataset.tld)?.querySelectorAll('input, select').forEach((field) => body.append(field.name, field.value));
        body.set('action', 'register');
        body.set('register_sld', sld);
        body.set('register_tld', row.dataset.tld);
        body.set('register_years', row.querySelector('select').value);
        await api('guest', 'cart/add_item', body);
        // Added: not sent again if a later domain fails and the visitor tries once more.
        row.querySelector('[data-cf-ds-choose]').checked = false;
        row.querySelector('[data-cf-ds-choose]').disabled = true;
      }
      // The cart page, where the form's own submit goes (order_configure.html.twig).
      window.location.href = ClientFence.tools.parseDataAttr(form.dataset.fbApi || '{}').redirect || window.location.href;
    } catch (error) {
      ClientFence.message(errorText(error), 'error');
      refresh();
    }
  };

  boxes.forEach((box) => box.addEventListener('change', limit));
  limit();
  list.addEventListener('change', refresh);
  button.addEventListener('click', search);
  input.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
      event.preventDefault();
      search();
    }
  });
  const nameservers = document.getElementById('nameserversCb');
  nameservers?.addEventListener('change', () => { document.getElementById('nameservers').hidden = !nameservers.checked; });
  // Captured on the document, before the form's API handler: a register order goes through addToCart().
  document.addEventListener('submit', (event) => {
    if (event.target === form && document.getElementById('domain-action').value === 'register') {
      event.preventDefault();
      event.stopPropagation();
      addToCart();
    }
  }, true);
  // From the embedded domain checker: its domains are ticked, so search at once unless a CAPTCHA waits.
  if (data.auto && input.value.trim() && !document.querySelector('[data-domain-captcha]:not([hidden])')) {
    search();
  }
}
