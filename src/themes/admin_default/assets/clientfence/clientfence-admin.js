// ClientFence admin frame (themes/admin_default/assets/clientfence/clientfence-admin.js, a module after
// FOSSBilling's own admin script): the Ctrl+K search, the menu drawer and its collapsed mode, the
// one-click language switch, the "New ..." links (#bf-new=<modal id> opens that page's create
// form), the bell, the dashboard income chart and the dashboard card order. Text from the server goes in
// with textContent only.

const root = document.documentElement;
const MENU_KEY = 'clientfence_admin_menu';

function store(key, value) {
  try {
    if (value === null) localStorage.removeItem(key);
    else localStorage.setItem(key, value);
  } catch (e) { /* private mode: the choice lasts for this page only */ }
}

function readJson(el, name, fallback) {
  try {
    const value = JSON.parse(el.dataset[name] || '');
    return value ?? fallback;
  } catch (e) {
    return fallback;
  }
}

// FOSSBilling's admin API as a promise: the result, or null when the call fails.
function apiPost(endpoint, data) {
  return new Promise((resolve) => {
    const api = window.ClientFence?.api?.admin;
    if (!api) { resolve(null); return; }
    try {
      api.post(endpoint, data, (result) => resolve(result), () => resolve(null), false);
    } catch (e) {
      resolve(null);
    }
  });
}

// ---- Messages that survive a reload. FOSSBilling's API forms and links (data-fb-api) reload or
// leave the page before they show their success message, so a save that reloads said nothing.
// The message (or "Changes saved" for a form, "Done" for a link; message: '' for none) is kept
// for the next page, which shows it. Errors are shown by FOSSBilling before any reload.
const FLASH_KEY = 'clientfence_admin_flash';
const fbApi = window.ClientFence?.api;
if (fbApi && typeof fbApi._afterComplete === 'function') {
  const afterComplete = fbApi._afterComplete;
  fbApi._afterComplete = function (element, result) {
    let config = {};
    try {
      config = window.ClientFence.tools.parseDataAttr(element.dataset.fbApi || '{}');
    } catch (e) { /* FOSSBilling reports it below */ }
    const leaves = typeof window[config.callback] !== 'function' && ('redirect' in config || 'reload' in config);
    // Some stock links repeat their confirm question as the message ("Are you sure?"): "Done" then.
    const asked = config.modal && (config.message === config.modal.title || config.message === config.modal.content);
    const message = 'message' in config && !asked ? config.message
      : (element instanceof HTMLFormElement ? document.body.dataset.bfSaved : document.body.dataset.bfDone);
    if (leaves && message) {
      try {
        sessionStorage.setItem(FLASH_KEY, JSON.stringify({ message, at: Date.now() }));
      } catch (e) { /* private mode: no message */ }
    }
    return afterComplete.call(this, element, result);
  };
}
try {
  const flash = JSON.parse(sessionStorage.getItem(FLASH_KEY) || 'null');
  sessionStorage.removeItem(FLASH_KEY);
  if (flash && typeof flash.message === 'string' && Date.now() - flash.at < 30000
    && typeof window.ClientFence?.message === 'function') {
    window.ClientFence.message(flash.message, 'success');
  }
} catch (e) { /* nothing kept */ }

// ---- Menu drawer (small screens) and collapsed menu (large screens)
const backdrop = document.querySelector('.bf-backdrop');
const opener = document.querySelector('[data-bf-menu-open]');

function setMenu(open) {
  root.classList.toggle('bf-menu-shown', open);
  if (backdrop) backdrop.hidden = !open;
  if (opener) opener.setAttribute('aria-expanded', open ? 'true' : 'false');
  if (open) document.querySelector('#bf-sidebar .bf-item')?.focus();
  else if (opener && document.activeElement?.closest('#bf-sidebar')) opener.focus();
}

opener?.addEventListener('click', () => setMenu(true));
document.querySelectorAll('[data-bf-menu-close]').forEach((el) => el.addEventListener('click', () => setMenu(false)));

// Two buttons share the choice: one next to the logo, one at the bottom of the menu.
const collapses = [...document.querySelectorAll('[data-bf-collapse]')];
function showCollapsed() {
  const collapsed = root.classList.contains('bf-collapsed');
  collapses.forEach((button) => {
    const label = collapsed ? button.dataset.labelExpand : button.dataset.labelCollapse;
    button.setAttribute('aria-pressed', collapsed ? 'true' : 'false');
    button.title = label || '';
    if (button.hasAttribute('aria-label')) button.setAttribute('aria-label', label || '');
    const text = button.querySelector('[data-bf-collapse-label]');
    if (text) text.textContent = label || '';
  });
}
function setCollapsed(collapsed) {
  root.classList.toggle('bf-collapsed', collapsed);
  store(MENU_KEY, collapsed ? 'collapsed' : null);
  showCollapsed();
}
collapses.forEach((button) => button.addEventListener('click', () => {
  setCollapsed(!root.classList.contains('bf-collapsed'));
  // The top button hides once the menu is collapsed: keep the focus in the menu.
  if (button.offsetParent === null) collapses.find((b) => b.offsetParent !== null)?.focus();
}));
showCollapsed();

// A group clicked in the collapsed menu opens the menu with that group open.
document.querySelectorAll('[data-bf-group] > summary').forEach((summary) => {
  summary.addEventListener('click', (event) => {
    if (root.classList.contains('bf-collapsed') && window.matchMedia('(min-width: 992px)').matches) {
      event.preventDefault();
      setCollapsed(false);
      summary.parentElement.open = true;
    }
  });
});

// ---- Language: the header button (two languages) or the language menu's items switch the language
document.querySelectorAll('[data-bf-locale]').forEach((button) => {
  button.addEventListener('click', () => {
    const name = window.ClientFence?.cookieNames?.locale || 'clientfence_locale';
    if (typeof window.ClientFence?.cookieCreate === 'function') {
      window.ClientFence.cookieCreate(name, button.dataset.bfLocale, 365);
    } else {
      document.cookie = `${name}=${encodeURIComponent(button.dataset.bfLocale)}; path=/; max-age=31536000; SameSite=Lax`;
    }
    window.location.reload();
  });
});

// ---- "New ..." links: #bf-new=newClientModal opens the create form of the list page
function openNewForm() {
  const match = /^#bf-new=([A-Za-z][\w-]*)$/.exec(window.location.hash);
  if (!match) return;
  history.replaceState(null, '', window.location.pathname + window.location.search);
  const trigger = document.querySelector(`[data-bs-target="#${match[1]}"]`);
  if (trigger) trigger.click();
}
window.addEventListener('hashchange', openNewForm);
if (document.readyState === 'complete') openNewForm();
else window.addEventListener('load', openNewForm);

// ---- Ctrl+K search
// A setting picked in the search: its page opens on its tab, then the label is looked up, scrolled
// to and highlighted (findSetting). The label waits in sessionStorage across the page load.
const FIND_KEY = 'bf_find_setting';
const squash = (value) => String(value || '').replace(/\s+/g, ' ').trim();

function findSetting() {
  let label = '';
  try {
    label = sessionStorage.getItem(FIND_KEY) || '';
    sessionStorage.removeItem(FIND_KEY);
  } catch (e) { return; }
  if (!label) return;
  const labels = Array.from(document.querySelectorAll('.form-label, .form-check-label, .col-form-label, .card-title, label'));
  const exact = labels.filter((el) => squash(el.textContent) === label);
  const loose = labels.filter((el) => squash(el.textContent).startsWith(label));
  const shown = (el) => el.getClientRects().length > 0;
  const el = exact.find(shown) || exact[0] || loose.find(shown) || loose[0];
  if (!el) return;
  // On another tab of the page (the index can be wrong about the tab): open that tab first.
  const pane = el.closest('.tab-pane');
  if (pane && !shown(el) && pane.id) document.querySelector(`[data-bs-toggle="tab"][href="#${pane.id}"], [data-bs-toggle="tab"][data-bs-target="#${pane.id}"]`)?.click();
  let box = el.classList.contains('card-title') ? el.closest('.card') || el : el.closest('.form-check, .mb-3, [class*="col-"]') || el.parentElement;
  const still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  setTimeout(() => {
    // A field shown only once a switch is on: point at the last visible control before it.
    if (!shown(box)) {
      const scope = box.closest('.card, .tab-pane, form') || document.body;
      const before = Array.from(scope.querySelectorAll('.form-check, .mb-3, [class*="col-"]'))
        .filter((c) => shown(c) && !c.contains(box) && (c.compareDocumentPosition(box) & Node.DOCUMENT_POSITION_FOLLOWING));
      box = before.pop() || box;
    }
    box.scrollIntoView({ block: 'center', behavior: still ? 'auto' : 'smooth' });
    box.classList.add('bf-found');
    setTimeout(() => box.classList.remove('bf-found'), 2600);
    const field = (el.htmlFor && document.getElementById(el.htmlFor)) || box.querySelector('input:not([type="hidden"]), select, textarea');
    if (field && field.getClientRects().length) field.focus({ preventScroll: true });
  }, pane && !shown(el) ? 200 : 0);
}
window.addEventListener('load', () => setTimeout(findSetting, 50));
window.addEventListener('hashchange', () => setTimeout(findSetting, 50));

const palette = document.querySelector('[data-bf-palette]');
if (palette) {
  const input = palette.querySelector('.bf-palette-input');
  const list = palette.querySelector('.bf-palette-list');
  const pages = readJson(palette, 'pages', []);
  const records = readJson(palette, 'records', []);
  const text = readJson(palette, 'text', {});
  const base = (palette.dataset.base || '').replace(/\/+$/, '');
  const link = (path) => `${base}/${path}`;
  const name = (person) => [person?.first_name, person?.last_name].filter(Boolean).join(' ') || person?.company || '';
  const sources = {
    client: {
      endpoint: 'client/get_list',
      map: (c) => ({ label: name(c) || `#${c.id}`, meta: [c.company && name(c) !== c.company ? c.company : '', c.email].filter(Boolean).join(' · '), href: link(`client/manage/${c.id}`) }),
    },
    order: {
      endpoint: 'order/get_list',
      map: (o) => ({ label: `#${o.id} ${o.title || ''}`.trim(), meta: [name(o.client), o.status].filter(Boolean).join(' · '), href: link(`order/manage/${o.id}`) }),
    },
    invoice: {
      endpoint: 'invoice/get_list',
      map: (i) => ({ label: i.serie_nr || `#${i.id}`, meta: [name(i.buyer), i.status].filter(Boolean).join(' · '), href: link(`invoice/manage/${i.id}`) }),
    },
    support: {
      endpoint: 'support/ticket_get_list',
      map: (t) => ({ label: `#${t.id} ${t.subject || ''}`.trim(), meta: [name(t.client) || t.author_name, t.status].filter(Boolean).join(' · '), href: link(`support/ticket/${t.id}`) }),
    },
  };

  let returnFocus = null;
  let results = [];
  let selected = 0;
  let recordGroups = [];
  let recordState = '';
  let timer = 0;
  let sequence = 0;
  // The settings pages, tabs and options this staff member may open (clientfence/settings_index),
  // loaded the first time the search opens.
  let settings = null;


  function option(item, index) {
    const a = document.createElement('a');
    a.className = 'bf-result';
    a.href = item.href;
    a.id = `bf-result-${index}`;
    a.setAttribute('role', 'option');
    const main = document.createElement('span');
    main.className = 'bf-result-main';
    const label = document.createElement('span');
    label.className = 'bf-result-label';
    label.textContent = item.label;
    main.append(label);
    if (item.meta) {
      const meta = document.createElement('span');
      meta.className = 'bf-result-meta';
      meta.textContent = item.meta;
      main.append(meta);
    }
    a.append(main);
    a.addEventListener('mousemove', () => select(index));
    a.addEventListener('click', () => remember(item));
    return a;
  }

  function group(title) {
    const div = document.createElement('div');
    div.className = 'bf-palette-group';
    div.setAttribute('role', 'presentation');
    div.textContent = title;
    return div;
  }

  function note(message) {
    const div = document.createElement('div');
    div.className = 'bf-palette-note';
    div.textContent = message;
    return div;
  }

  // Every word typed must be in the option's name, page, tab or extra words; names that start
  // with the text come first, then names that contain it.
  function settingMatches(q) {
    if (q.length < 2 || !settings) return [];
    const words = q.split(/\s+/);
    return settings
      .map((e) => {
        const name = e.label.toLowerCase();
        const all = `${name} ${e.tab} ${e.page} ${e.words}`.toLowerCase();
        if (!words.every((w) => all.includes(w))) return null;
        const score = name.startsWith(q) ? 0 : name.includes(q) ? 1 : e.kind === 'field' ? 3 : 2;
        return { score, item: { label: e.label, meta: [e.page, e.tab].filter(Boolean).join(' → '), href: link(e.url), find: e.kind === 'tab' ? '' : e.label } };
      })
      .filter(Boolean)
      .sort((a, b) => a.score - b.score)
      .slice(0, 8)
      .map((m) => m.item);
  }

  function remember(item) {
    if (!item.find) return;
    try { sessionStorage.setItem(FIND_KEY, item.find); } catch (e) { /* private mode */ }
    // Another page looks for it once loaded, another tab of this page on hashchange; the very same
    // address loads nothing, so it is looked for now.
    const url = new URL(item.href, window.location.href);
    if (url.pathname !== window.location.pathname || url.search !== window.location.search) return;
    close();
    if (url.hash === window.location.hash) setTimeout(findSetting, 0);
  }

  function render() {
    const q = input.value.trim().toLowerCase();
    const matched = pages
      .filter((p) => !q || p.label.toLowerCase().includes(q) || (p.group || '').toLowerCase().includes(q))
      .slice(0, q ? 8 : 12)
      .map((p) => ({ label: p.label, meta: p.group, href: p.uri }));
    const nodes = [];
    results = [];
    if (matched.length) {
      nodes.push(group(text.pages || 'Pages'));
      matched.forEach((item) => { nodes.push(option(item, results.length)); results.push(item); });
    }
    const found = settingMatches(q);
    if (found.length) {
      nodes.push(group(text.settings || 'Settings'));
      found.forEach((item) => { nodes.push(option(item, results.length)); results.push(item); });
    }
    recordGroups.forEach(({ type, items }) => {
      if (!items.length) return;
      nodes.push(group(text[type] || type));
      items.forEach((item) => { nodes.push(option(item, results.length)); results.push(item); });
    });
    if (recordState === 'searching') nodes.push(note(text.searching || '…'));
    else if (recordState === 'failed') nodes.push(note(text.failed || 'Search failed'));
    else if (!results.length) nodes.push(note(text.empty || 'No results'));
    list.replaceChildren(...nodes);
    select(Math.min(selected, Math.max(results.length - 1, 0)));
  }

  function select(index) {
    selected = index;
    list.querySelectorAll('.bf-result').forEach((el, i) => {
      el.classList.toggle('is-sel', i === index);
      el.setAttribute('aria-selected', i === index ? 'true' : 'false');
    });
    const current = list.querySelector(`#bf-result-${index}`);
    if (current) {
      input.setAttribute('aria-activedescendant', current.id);
      current.scrollIntoView({ block: 'nearest' });
    } else {
      input.removeAttribute('aria-activedescendant');
    }
  }

  async function searchRecords(q) {
    const mine = ++sequence;
    if (q.length < 2 || !records.length) {
      recordGroups = [];
      recordState = '';
      render();
      return;
    }
    recordState = 'searching';
    render();
    const answers = await Promise.all(records.map(async (type) => {
      const source = sources[type];
      if (!source) return { type, items: [], ok: true };
      const result = await apiPost(source.endpoint, { search: q, per_page: 5, page: 1 });
      if (!result || !Array.isArray(result.list)) return { type, items: [], ok: result !== null };
      return { type, items: result.list.slice(0, 5).map(source.map), ok: true };
    }));
    if (mine !== sequence) return;
    recordGroups = answers;
    recordState = answers.every((a) => !a.ok) ? 'failed' : '';
    render();
  }

  function open() {
    if (!palette.hidden) return;
    returnFocus = document.activeElement;
    setMenu(false);
    palette.hidden = false;
    root.classList.add('bf-palette-shown');
    input.value = '';
    recordGroups = [];
    recordState = '';
    selected = 0;
    render();
    input.focus();
    if (settings === null) {
      settings = [];
      apiPost('clientfence/settings_index', {}).then((list) => {
        settings = Array.isArray(list) ? list : [];
        if (!palette.hidden) render();
      });
    }
  }

  function close() {
    if (palette.hidden) return;
    palette.hidden = true;
    root.classList.remove('bf-palette-shown');
    sequence++;
    clearTimeout(timer);
    if (returnFocus && typeof returnFocus.focus === 'function') returnFocus.focus();
  }

  document.querySelectorAll('[data-bf-palette-open]').forEach((el) => el.addEventListener('click', open));
  palette.querySelectorAll('[data-bf-palette-close]').forEach((el) => el.addEventListener('click', close));

  input.addEventListener('input', () => {
    selected = 0;
    render();
    clearTimeout(timer);
    const q = input.value.trim();
    timer = setTimeout(() => searchRecords(q), 250);
  });

  input.addEventListener('keydown', (event) => {
    if (event.key === 'ArrowDown' && results.length) {
      event.preventDefault();
      select((selected + 1) % results.length);
    } else if (event.key === 'ArrowUp' && results.length) {
      event.preventDefault();
      select((selected - 1 + results.length) % results.length);
    } else if (event.key === 'Enter') {
      event.preventDefault();
      const item = results[selected];
      if (item) {
        remember(item);
        window.location.href = item.href;
      }
    } else if (event.key === 'Tab') {
      event.preventDefault();
    }
  });

  document.addEventListener('keydown', (event) => {
    if ((event.ctrlKey || event.metaKey) && !event.altKey && !event.shiftKey && event.key.toLowerCase() === 'k') {
      event.preventDefault();
      if (palette.hidden) open();
      else close();
    } else if (event.key === 'Escape') {
      if (!palette.hidden) {
        event.preventDefault();
        close();
      } else if (root.classList.contains('bf-menu-shown')) {
        setMenu(false);
      }
    }
  });
}

// ---- Counter cards on the list pages (clients, orders, invoices, transactions, tickets): a click
// filters the table by that status in place, a second click (or Total) shows everything again.
// Only the table and the paging controls are replaced; the address follows, so reload and back
// keep the filter (core patch "a status filter alone keeps the counter cards").
const summary = document.querySelector('.filter-panel-summary');
const frameText = document.querySelector('[data-bf-palette]') ? readJson(document.querySelector('[data-bf-palette]'), 'text', {}) : {};
if (summary) {
  const cards = [...summary.querySelectorAll('a[href]')];
  const statusOf = (href) => new URL(href, window.location.href).searchParams.get('status') || '';
  const current = () => new URLSearchParams(window.location.search).get('status') || '';
  const tablesOf = (doc) => [...doc.querySelectorAll('.bf-body .table-responsive')];
  // The list is the first table after the cards, also while another tab (Clients: Groups) is open.
  const liveTable = () => tablesOf(document).find((t) => summary.compareDocumentPosition(t) & Node.DOCUMENT_POSITION_FOLLOWING);
  const showPane = (table) => {
    const pane = table.closest('.tab-pane');
    if (!pane || pane.classList.contains('active') || !pane.id) return;
    const trigger = document.querySelector(`[data-bs-toggle="tab"][href="#${CSS.escape(pane.id)}"], [data-bs-toggle="tab"][data-bs-target="#${CSS.escape(pane.id)}"]`);
    if (trigger && window.bootstrap?.Tab) window.bootstrap.Tab.getOrCreateInstance(trigger).show();
  };
  const footerOf = (table) => {
    let el = table?.nextElementSibling;
    while (el && !el.classList.contains('card-footer')) el = el.nextElementSibling;
    return el || null;
  };
  // The bulk delete button and its script stay; only the paging and export controls are swapped.
  const swappable = (footer) => [...(footer?.children || [])].filter((c) => c.tagName !== 'SCRIPT' && !c.matches('#batch-delete-selected-btn') && !c.querySelector('#batch-delete-selected-btn'));

  const mark = () => {
    const status = current();
    cards.forEach((card) => {
      const own = statusOf(card.getAttribute('href'));
      const on = own === status;
      card.classList.toggle('is-on', on);
      card.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    document.querySelectorAll('.bf-body input[type="radio"][name="status"]').forEach((radio) => { radio.checked = radio.value === status; });
  };

  // Rows put in place: what FOSSBilling binds to rows once at page load is bound again here.
  const bindRows = (table) => {
    const deleteBtn = document.getElementById('batch-delete-selected-btn');
    if (deleteBtn) {
      deleteBtn.classList.add('d-none');
      deleteBtn.classList.remove('d-inline-flex');
    }
    table.addEventListener('change', (event) => {
      const boxes = [...table.querySelectorAll('input.batch-delete-checkbox')];
      if (event.target.matches('input.batch-delete-master-checkbox')) boxes.forEach((box) => { box.checked = event.target.checked; });
      if (!deleteBtn) return;
      const any = boxes.some((box) => box.checked);
      deleteBtn.classList.toggle('d-none', !any);
      deleteBtn.classList.toggle('d-inline-flex', any);
    });
    table.addEventListener('click', (event) => {
      const notes = event.target.closest('a.show-notes');
      if (!notes || typeof window.Modals?.create !== 'function') return;
      event.preventDefault();
      window.ClientFence.api.admin.post('support/ticket_get', { id: notes.getAttribute('rel') }, (result) => {
        const html = document.createElement('div');
        (result.notes || []).forEach((entry) => {
          const div = document.createElement('div');
          // ClientFence core patch: a note is plain text (it was stored XSS in every staff member's session).
          div.textContent = entry.note;
          html.append(div, document.createElement('hr'));
        });
        window.Modals.create({ title: frameText.notes || 'Notes', content: html.outerHTML });
      });
    });
    window.ClientFence?.api?._apiLink?.();
    if (window.bootstrap?.Tooltip) table.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => window.bootstrap.Tooltip.getOrCreateInstance(el));
  };

  let loading = 0;
  let shown = current();
  const load = async (url, push) => {
    const table = liveTable();
    const footer = footerOf(table);
    if (!table) { window.location.href = url.href; return; }
    showPane(table);
    const mine = ++loading;
    table.setAttribute('aria-busy', 'true');
    table.classList.add('bf-loading');
    try {
      const response = await fetch(url.href, { credentials: 'same-origin', headers: { Accept: 'text/html' } });
      if (!response.ok || response.redirected) throw new Error(`status ${response.status}`);
      const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
      if (mine !== loading) return;
      const index = tablesOf(document).indexOf(table);
      const fresh = tablesOf(doc)[index];
      if (!fresh || !fresh.querySelector('table')) throw new Error('no table');
      const adopted = document.importNode(fresh, true);
      table.replaceWith(adopted);
      const freshFooter = footerOf(fresh);
      if (footer && freshFooter) {
        swappable(footer).forEach((el) => el.remove());
        swappable(freshFooter).forEach((el) => footer.append(document.importNode(el, true)));
      }
      if (push) history.pushState({ bfStatus: true }, '', url.href);
      shown = url.searchParams.get('status') || '';
      mark();
      bindRows(adopted);
    } catch (error) {
      window.location.href = url.href;
    }
  };

  cards.forEach((card) => {
    card.addEventListener('click', (event) => {
      if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
      event.preventDefault();
      const own = statusOf(card.getAttribute('href'));
      const target = own !== '' && own !== current() ? own : '';
      if (target === current()) return;
      const url = new URL(window.location.href);
      url.hash = '';
      url.searchParams.delete('page');
      if (target) url.searchParams.set('status', target);
      else url.searchParams.delete('status');
      load(url, true);
    });
  });
  window.addEventListener('popstate', () => { if (current() !== shown) load(new URL(window.location.href), false); });
  mark();
}

// ---- Dashboard chart (FOSSBilling's own chart helper): a figure (income, or the counts of the
// Statistics table) over Today, 7, 30 or 90 days. Income comes with the page; another figure is
// fetched once when picked: 90 days from FOSSBilling's stats_get_*, whose last 30 and 7 points
// are the shorter ranges, and Today hour by hour from clientfence_dashboard_today.
const income = document.querySelector('[data-bf-income]');
if (income) {
  const metrics = readJson(income, 'metrics', {});
  const currency = readJson(income, 'currency', {});
  const lang = document.documentElement.lang || undefined;
  const countFormat = new Intl.NumberFormat(lang, { maximumFractionDigits: 0 });
  // Today's points are hours: the tooltip shows the hour instead of FOSSBilling's date.
  const hourFormat = new Intl.DateTimeFormat(lang, { hour: '2-digit', minute: '2-digit' });
  const select = income.querySelector('[data-bf-metric]');
  const totalEl = income.querySelector('[data-bf-total]');
  const labelEl = income.querySelector('[data-bf-total-label]');
  const errorEl = income.querySelector('[data-bf-error]');
  const hasToday = !!income.querySelector('[data-bf-range="today"]');
  const cache = { income: { series: readJson(income, 'series', {}), totals: readJson(income, 'totals', {}) } };
  let metric = 'income';
  let range = income.dataset.range || 'd30';
  let drawn = 0;

  const draw = async () => {
    const charts = window.ClientFence?.charts;
    const data = cache[metric];
    if (!data || !charts || typeof charts.renderTimeSeriesSparkline !== 'function') return;
    const mine = ++drawn;
    const isMoney = (metrics[metric]?.kind || 'currency') === 'currency';
    const chart = await charts.renderTimeSeriesSparkline('bf-income-chart', data.series[range] || [], {
      label: metrics[metric]?.label || income.dataset.label || 'Income',
      height: 200,
      showTooltip: true,
      ...(isMoney ? { valueFormat: 'currency', currency: currency.code || 'USD', currencyFormat: currency } : {}),
    });
    const tooltip = chart?.options?.plugins?.tooltip;
    if (mine !== drawn || range !== 'today' || !tooltip?.callbacks) return;
    tooltip.callbacks.title = (items) => {
      const timestamp = items[0]?.raw?.x;
      return Number.isFinite(timestamp) ? hourFormat.format(new Date(timestamp)) : '';
    };
    chart.update('none');
  };
  const show = () => {
    const data = cache[metric];
    if (totalEl) totalEl.textContent = data?.totals[range] ?? '';
    if (labelEl) labelEl.textContent = metrics[metric]?.total || '';
    draw();
  };

  const load = async (key) => {
    const info = metrics[key];
    if (!info?.endpoint) return null;
    const [days, hours] = await Promise.all([
      apiPost(info.endpoint, { date_from: income.dataset.from, date_to: income.dataset.to }),
      hasToday ? apiPost('clientfence/dashboard_today', { metric: key }) : Promise.resolve([]),
    ]);
    if (!Array.isArray(days) || !Array.isArray(hours)) return null;
    const sum = (points) => countFormat.format(points.reduce((carry, point) => carry + Number(point[1] || 0), 0));
    const series = { d90: days, d30: days.slice(-30), d7: days.slice(-7) };
    const totals = { d90: sum(series.d90), d30: sum(series.d30), d7: sum(series.d7) };
    if (hasToday) {
      series.today = hours;
      totals.today = countFormat.format(Number(info.today || 0));
    }
    return { series, totals };
  };

  if (select) {
    select.addEventListener('change', async () => {
      const key = select.value;
      if (errorEl) errorEl.hidden = true;
      if (!cache[key]) {
        income.classList.add('bf-loading');
        select.disabled = true;
        const data = await load(key);
        select.disabled = false;
        income.classList.remove('bf-loading');
        if (!data) {
          select.value = metric;
          if (errorEl) {
            errorEl.textContent = income.dataset.error || '';
            errorEl.hidden = false;
          }
          return;
        }
        cache[key] = data;
      }
      if (select.value !== key) return;
      metric = key;
      show();
    });
  }
  income.querySelectorAll('[data-bf-range]').forEach((button) => {
    button.addEventListener('click', () => {
      range = button.dataset.bfRange;
      income.querySelectorAll('[data-bf-range]').forEach((b) => {
        const on = b === button;
        b.classList.toggle('is-on', on);
        b.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
      show();
    });
  });
  draw();
}

// ---- Tabbed dashboard cards (Recent activity): a tab shows its list, and "View all" follows it.
document.querySelectorAll('[data-bf-tabset]').forEach((set) => {
  const tabs = [...set.querySelectorAll('[data-bf-tab]')];
  const link = set.querySelector('[data-bf-tabset-link]');
  const pick = (tab, focus) => {
    tabs.forEach((t) => {
      const on = t === tab;
      t.classList.toggle('is-on', on);
      t.setAttribute('aria-selected', on ? 'true' : 'false');
      t.tabIndex = on ? 0 : -1;
    });
    set.querySelectorAll('[data-bf-pane]').forEach((pane) => { pane.hidden = pane.dataset.bfPane !== tab.dataset.bfTab; });
    if (link && tab.dataset.bfHref) link.href = tab.dataset.bfHref;
    if (focus) tab.focus();
  };
  tabs.forEach((tab, index) => {
    tab.addEventListener('click', () => pick(tab, false));
    tab.addEventListener('keydown', (event) => {
      const rtl = getComputedStyle(tab).direction === 'rtl';
      const step = { ArrowRight: rtl ? -1 : 1, ArrowLeft: rtl ? 1 : -1 }[event.key];
      if (step) {
        event.preventDefault();
        pick(tabs[(index + step + tabs.length) % tabs.length], true);
      } else if (event.key === 'Home' || event.key === 'End') {
        event.preventDefault();
        pick(tabs[event.key === 'Home' ? 0 : tabs.length - 1], true);
      }
    });
  });
});

// ---- Dashboard cards in two columns: a Super Administrator arranges them ("Arrange cards": drag
//      a card, or its up, down and other-column buttons for touch and keyboard) and saves the order
//      in their account (clientfence/dashboard_layout_save); "Default order" drops it. While arranging,
//      the cards are inert, so a drag never opens a link.
//      The buttons sit in the greeting row ([data-bf-board-bar]), above the cards.
const board = document.querySelector('[data-bf-board]');
const boardBar = document.querySelector('[data-bf-board-bar]');
const arrange = boardBar?.querySelector('[data-bf-arrange]');
if (board && arrange) {
  const text = readJson(board, 'text', {});
  const tools = boardBar.querySelector('[data-bf-arrange-tools]');
  const status = boardBar.querySelector('[data-bf-arrange-status]');
  const saveButton = boardBar.querySelector('[data-bf-arrange-save]');
  const cols = [...board.querySelectorAll('[data-bf-col]')];
  const slots = () => [...board.querySelectorAll('[data-bf-card]')];
  const order = () => cols.map((col) => [...col.querySelectorAll(':scope > [data-bf-card]')].map((slot) => slot.dataset.bfCard));
  let before = null;
  let dragged = null;
  const setMode = (on) => {
    board.classList.toggle('is-arranging', on);
    arrange.hidden = on;
    arrange.setAttribute('aria-pressed', on ? 'true' : 'false');
    tools.hidden = !on;
    slots().forEach((slot) => {
      slot.draggable = on;
      const bar = slot.querySelector(':scope > .bf-slot-tools');
      if (bar) bar.hidden = !on;
      const card = slot.querySelector(':scope > .bf-card');
      if (card) card.inert = on;
    });
  };
  const say = (message) => { if (status) status.textContent = message || ''; };
  arrange.addEventListener('click', () => {
    before = cols.map((col) => [...col.children]);
    say('');
    setMode(true);
    tools.querySelector('button')?.focus();
  });
  boardBar.querySelector('[data-bf-arrange-cancel]')?.addEventListener('click', () => {
    if (before) before.forEach((children, index) => children.forEach((child) => cols[index].appendChild(child)));
    setMode(false);
    arrange.focus();
  });
  saveButton?.addEventListener('click', async () => {
    saveButton.disabled = true;
    const result = await apiPost('clientfence/dashboard_layout_save', { layout: JSON.stringify(order()) });
    saveButton.disabled = false;
    if (result) {
      setMode(false);
      say(text.saved);
      arrange.focus();
    } else {
      say(text.failed);
    }
  });
  boardBar.querySelector('[data-bf-arrange-reset]')?.addEventListener('click', async () => {
    const result = await apiPost('clientfence/dashboard_layout_reset', {});
    if (result) window.location.reload();
    else say(text.failed);
  });
  board.addEventListener('click', (event) => {
    const button = event.target.closest('[data-bf-move]');
    if (!button || !board.classList.contains('is-arranging')) return;
    const slot = button.closest('[data-bf-card]');
    const col = slot.parentElement;
    const move = button.dataset.bfMove;
    if (move === 'up' && slot.previousElementSibling) {
      col.insertBefore(slot, slot.previousElementSibling);
    } else if (move === 'down' && slot.nextElementSibling) {
      col.insertBefore(slot.nextElementSibling, slot);
    } else if (move === 'across') {
      const other = cols.find((c) => c !== col);
      const index = [...col.children].indexOf(slot);
      if (other) other.insertBefore(slot, other.children[index] || null);
    }
    button.focus();
  });
  board.addEventListener('dragstart', (event) => {
    const slot = event.target.closest?.('[data-bf-card]');
    if (!slot || !board.classList.contains('is-arranging')) return;
    dragged = slot;
    slot.classList.add('is-dragging');
    event.dataTransfer.effectAllowed = 'move';
    try { event.dataTransfer.setData('text/plain', slot.dataset.bfCard); } catch (e) { /* some browsers need data to drag */ }
  });
  board.addEventListener('dragend', () => {
    dragged?.classList.remove('is-dragging');
    dragged = null;
  });
  cols.forEach((col) => {
    col.addEventListener('dragover', (event) => {
      if (!dragged) return;
      event.preventDefault();
      const next = [...col.querySelectorAll(':scope > [data-bf-card]:not(.is-dragging)')]
        .find((slot) => { const box = slot.getBoundingClientRect(); return event.clientY < box.top + box.height / 2; });
      if (next) {
        if (next !== dragged.nextElementSibling) col.insertBefore(dragged, next);
      } else if (col.lastElementChild !== dragged) {
        col.appendChild(dragged);
      }
    });
    col.addEventListener('drop', (event) => { if (dragged) event.preventDefault(); });
  });
}

// ---- System health (ClientFence, Health.php), a tab of System settings: run the checks now, copy a
// plain report, and jump to a list (a count, or ?health=bad|warn|ok from the old page address).
const health = document.querySelector('[data-bf-health]');
if (health) {
  const jump = (state) => health.querySelector(`#bf-health-${CSS.escape(state)}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  health.addEventListener('click', (event) => {
    const button = event.target.closest('[data-bf-health-jump]');
    if (button) jump(button.dataset.bfHealthJump);
  });
  const wanted = new URLSearchParams(window.location.search).get('health');
  if (wanted) window.addEventListener('load', () => setTimeout(() => jump(wanted), 200));
  const text = readJson(health, 'text', {});
  const checks = readJson(health, 'checks', []);
  const errorEl = health.querySelector('[data-bf-health-error]');
  const runButton = health.querySelector('[data-bf-health-run]');
  const copyButton = health.querySelector('[data-bf-health-copy]');
  if (runButton) {
    runButton.addEventListener('click', async () => {
      runButton.disabled = true;
      runButton.setAttribute('aria-busy', 'true');
      if (errorEl) errorEl.hidden = true;
      const result = await apiPost('clientfence/health_run', {});
      if (result) {
        window.location.reload();
        return;
      }
      runButton.disabled = false;
      runButton.removeAttribute('aria-busy');
      if (errorEl) {
        errorEl.textContent = text.failed || '';
        errorEl.hidden = false;
      }
    });
  }
  if (copyButton) {
    const report = () => {
      const lines = [`${text.title || 'System health'}: ${text.company || ''}`, `${text.rating || ''} · ${text.ran || ''}`];
      ['bad', 'warn', 'ok'].forEach((state) => {
        const rows = checks.filter((check) => check.state === state);
        if (!rows.length) return;
        lines.push('', `${(text.states || {})[state] || state} (${rows.length})`);
        rows.forEach((check) => lines.push(`- [${(text.groups || {})[check.group] || check.group}] ${check.title}: ${check.detail}`));
      });
      return lines.join('\n');
    };
    const label = copyButton.textContent;
    copyButton.addEventListener('click', async () => {
      const value = report();
      let done = false;
      try {
        await navigator.clipboard.writeText(value);
        done = true;
      } catch (e) {
        const area = document.createElement('textarea');
        area.value = value;
        area.setAttribute('readonly', '');
        area.className = 'visually-hidden';
        document.body.appendChild(area);
        area.select();
        try { done = document.execCommand('copy'); } catch (err) { done = false; }
        area.remove();
      }
      if (!done) return;
      copyButton.textContent = text.copied || label;
      setTimeout(() => { copyButton.textContent = label; }, 2000);
    });
  }
}

// ---- Date quick picks (WHMCS-style: Today, Tomorrow, In 7 Days, 1 Month or 1 Year From Now, no
// end date, Custom), e.g. the order's "Override Auto-Suspend": a pick fills the date field
// (data-bf-until-for) and ticks its box (data-bf-until-check); Custom opens the calendar. A date
// in the field always goes with a ticked box (also one picked in the calendar or typed: the box is
// ticked as the form is sent), and unticking the box empties the date. Dates are the browser's
// day; the server ends the override after that day in its own time.
function isoDate(date) {
  const pad = (n) => String(n).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

// Months and years from today, kept on the last day of a shorter month (Jan 31 + 1 month is the
// end of February, not March 2).
function addMonths(date, months) {
  const day = date.getDate();
  const result = new Date(date.getFullYear(), date.getMonth() + months, 1);
  const last = new Date(result.getFullYear(), result.getMonth() + 1, 0).getDate();
  result.setDate(Math.min(day, last));
  return result;
}

const untilPairs = new Map();
document.querySelectorAll('[data-bf-until]').forEach((button) => {
  const input = document.getElementById(button.dataset.bfUntilFor || '');
  const box = document.getElementById(button.dataset.bfUntilCheck || '');
  if (!input) return;
  if (box) untilPairs.set(input, box);
  button.addEventListener('click', () => {
    if (box) box.checked = true;
    const pick = button.dataset.bfUntil || '';
    if (pick === 'custom') {
      input.focus();
      input.click();
      return;
    }
    const match = /^(\d+)([dmy])$/.exec(pick);
    if (!match) {
      input.value = '';
      return;
    }
    const n = Number(match[1]);
    let date = new Date();
    if (match[2] === 'd') date.setDate(date.getDate() + n);
    if (match[2] === 'm') date = addMonths(date, n);
    if (match[2] === 'y') date = addMonths(date, 12 * n);
    input.value = isoDate(date);
  });
});

untilPairs.forEach((box, input) => {
  const tickIfDated = () => { if (input.value.trim() !== '') box.checked = true; };
  input.addEventListener('input', tickIfDated);
  input.addEventListener('change', tickIfDated);
  box.addEventListener('change', () => { if (!box.checked) input.value = ''; });
  // Before the form's own submit handler (capture phase), for a date the calendar filled in.
  document.addEventListener('submit', (event) => {
    if (event.target === input.form) tickIfDated();
  }, true);
});

// ---- Ticket merge (Support/TicketMerge.php, as WHMCS): "Merge" in the ticket list's With Selected
// bars (below) and the "Merge Tickets" form on a ticket's Manage tab. Both ask first (a merge
// cannot be undone), then open the ticket that stays (the oldest).
const mergeAsk = (el, ids, params) => {
  if (typeof window.Modals?.create !== 'function' || !window.ClientFence?.api?.admin) return;
  const sorted = [...new Set(ids)].sort((a, b) => a - b);
  const text = document.createElement('p');
  text.textContent = (el.dataset.bfMergeText || '')
    .replace('%list%', sorted.map((id) => `#${id}`).join(', '))
    .replace('%id%', `#${sorted[0]}`);
  const title = document.createElement('span');
  title.textContent = el.dataset.bfMergeTitle || '';
  window.Modals.create({
    type: 'danger',
    title: title.innerHTML,
    content: text.outerHTML,
    confirmButton: el.dataset.bfMergeConfirm || 'Merge',
    cancelButton: el.dataset.bfMergeCancel || 'Cancel',
    confirmCallback: () => {
      window.ClientFence.api.admin.post('support/ticket_merge', params, (result) => {
        window.location.href = `${el.dataset.bfMergeUrl || el.dataset.bfTicketUrl}/${encodeURIComponent(String(result))}`;
      });
    },
  });
};
const mergeIds = (text) => (String(text).match(/\d+/g) || []).map(Number).filter((id) => id > 0);

// ---- Ticket list "With Selected" bars (above and below the list, as WHMCS; Support/TicketBulk.php):
// Pin, Unpin, Merge, Close, Delete, Block Sender & Delete act on the ticked tickets. Merge, Close
// and the deletes ask first. Delegated, because the counter cards replace the rows in place.
const bulkTicked = () => [...document.querySelectorAll('input.batch-delete-checkbox:checked')]
  .map((box) => Number(box.dataset.itemId)).filter((id) => id > 0);
const bulkAsk = (bar, kind, ids, call) => {
  const text = document.createElement('p');
  text.textContent = (bar.dataset[`bf${kind}Text`] || '').replace('%list%', ids.map((id) => `#${id}`).join(', '));
  const title = document.createElement('span');
  title.textContent = bar.dataset[`bf${kind}Title`] || '';
  window.Modals.create({
    type: 'danger',
    title: title.innerHTML,
    content: text.outerHTML,
    confirmButton: bar.dataset[`bf${kind}Confirm`] || 'OK',
    cancelButton: bar.dataset.bfCancel || 'Cancel',
    confirmCallback: call,
  });
};
const bulkNotice = (bar, message) => {
  const text = document.createElement('p');
  text.textContent = message;
  const title = document.createElement('span');
  title.textContent = bar.dataset.bfNoneTitle || '';
  window.Modals.create({ type: 'small', title: title.innerHTML, content: text.outerHTML });
};
if (document.querySelector('[data-bf-bulk]')) {
  document.addEventListener('change', (event) => {
    if (!event.target.matches?.('input.batch-delete-master-checkbox')) return;
    document.querySelectorAll('input.batch-delete-checkbox').forEach((box) => { box.checked = event.target.checked; });
  });
  document.addEventListener('click', (event) => {
    const button = event.target.closest?.('[data-bf-bulk-action]');
    if (!button || typeof window.Modals?.create !== 'function' || !window.ClientFence?.api?.admin) return;
    const bar = button.closest('[data-bf-bulk]');
    const action = button.dataset.bfBulkAction;
    const ids = bulkTicked().sort((a, b) => a - b);
    if (!ids.length) {
      bulkNotice(bar, bar.dataset.bfNoneText || '');
      return;
    }
    const post = (call) => window.ClientFence.api.admin.post(call, { ids }, () => window.location.reload());
    if (action === 'pin') post('support/batch_ticket_pin');
    else if (action === 'unpin') post('support/batch_ticket_unpin');
    else if (action === 'close') bulkAsk(bar, 'Close', ids, () => post('support/batch_ticket_close'));
    else if (action === 'delete') bulkAsk(bar, 'Delete', ids, () => post('support/batch_delete'));
    else if (action === 'block') bulkAsk(bar, 'Block', ids, () => post('support/batch_block_delete'));
    else if (action === 'merge') {
      if (ids.length < 2) bulkNotice(bar, bar.dataset.bfMergeFew || '');
      else mergeAsk(bar, ids, { ids });
    }
  });
}

document.querySelectorAll('form[data-bf-merge-form]').forEach((form) => {
  form.addEventListener('submit', (event) => {
    event.preventDefault();
    const own = Number(form.dataset.bfMergeId);
    const others = mergeIds(form.elements.ids?.value || '').filter((id) => id !== own);
    if (!others.length) {
      form.elements.ids?.focus();
      return;
    }
    mergeAsk(form, [own, ...others], { id: own, ids: others.join(','), subject: form.elements.subject?.value || '' });
  });
});

// ---- Quote form (Invoices > Quotes, Invoice/Quotes.php): a client or a prospect, lines added and
// removed (a <template> row), each line's amount and the totals while typing (with the tax rate
// saved on the quote), and after the first save the quote's own page.
const quoteForm = document.querySelector('form[data-bf-quote-form]');
if (quoteForm) {
  window.clientfenceQuoteSaved = (id) => {
    if (Number(id) > 0) window.location.href = `${quoteForm.dataset.quoteUrl}/${Number(id)}`;
  };

  const sections = quoteForm.querySelectorAll('[data-bf-quote-section]');
  const showFor = () => {
    const chosen = quoteForm.querySelector('[data-bf-quote-for]:checked')?.value || 'prospect';
    sections.forEach((section) => {
      const on = section.dataset.bfQuoteSection === chosen;
      section.hidden = !on;
      // Fields of the other choice are not sent: a chosen client wins over prospect details.
      section.querySelectorAll('input, select').forEach((field) => {
        field.disabled = !on;
        // A TomSelect picker keeps its own lock: switch it with the field (the client picker stayed locked).
        if (field.tomselect) {
          if (on) field.tomselect.enable(); else field.tomselect.disable();
        }
      });
    });
  };
  if (!quoteForm.querySelector('[data-bf-quote-for]')?.closest('fieldset')?.disabled) {
    quoteForm.querySelectorAll('[data-bf-quote-for]').forEach((radio) => radio.addEventListener('change', showFor));
    showFor();
  }

  const table = quoteForm.querySelector('[data-bf-quote-items]');
  const body = table?.tBodies[0];
  const rowTemplate = quoteForm.querySelector('template[data-bf-quote-row]');
  const currency = quoteForm.dataset.currency || '';
  const taxRate = Number(table?.dataset.taxRate || 0);
  const money = (value) => {
    try {
      if (currency) return new Intl.NumberFormat(document.documentElement.lang || undefined, { style: 'currency', currency }).format(value);
    } catch (e) { /* unknown currency code: the plain number */ }
    return value.toFixed(2);
  };
  const cents = (value) => Math.round(value * 100) / 100;
  const field = (row, name) => Number(row.querySelector(`[data-bf-quote-${name}]`)?.value || 0);
  const recount = () => {
    let subtotal = 0;
    let taxed = 0;
    body?.querySelectorAll('tr[data-bf-quote-line]').forEach((row) => {
      const unit = cents(field(row, 'price') * (100 - field(row, 'discount')) / 100);
      const quantity = Math.max(0, Math.floor(field(row, 'qty')));
      const amount = cents(unit * quantity);
      subtotal += amount;
      if (row.querySelector('input[type="checkbox"]')?.checked) taxed += amount;
      const cell = row.querySelector('[data-bf-quote-amount]');
      if (cell) cell.textContent = money(amount);
    });
    const set = (name, value) => {
      const cell = table?.querySelector(`[data-bf-quote-${name}]`);
      if (cell) cell.textContent = money(cents(value));
    };
    // As Quotes.php: the tax once, on the taxed lines' total (per unit it drifted by cents).
    const tax = taxRate > 0 ? cents(taxed * taxRate / 100) : 0;
    set('subtotal', subtotal);
    set('tax', tax);
    set('total', subtotal + tax);
  };
  let next = body ? body.rows.length : 0;
  quoteForm.querySelector('[data-bf-quote-add]')?.addEventListener('click', () => {
    if (!body || !rowTemplate) return;
    // Rising indexes keep the lines in order when the form becomes JSON.
    const html = rowTemplate.innerHTML.replaceAll('__INDEX__', String(next += 1));
    const holder = document.createElement('tbody');
    holder.innerHTML = html;
    const row = holder.querySelector('tr');
    if (!row) return;
    body.append(row);
    row.querySelector('input')?.focus();
    recount();
  });
  table?.addEventListener('click', (event) => {
    const button = event.target.closest?.('[data-bf-quote-remove]');
    if (!button || !body) return;
    const row = button.closest('tr');
    if (body.rows.length > 1) row?.remove();
    else row?.querySelectorAll('input').forEach((input) => {
      if (input.type === 'checkbox') input.checked = false;
      else input.value = input.type === 'number' && input.dataset.bfQuoteQty !== undefined ? '1' : '';
    });
    recount();
  });
  table?.addEventListener('input', recount);
  table?.addEventListener('change', recount);
  recount();
}

// ---- Configurable options (Product/Options.php): a new group opens its own page after the first
// save; an option's form shows the fields of its kind (minimum, maximum and unit for a quantity) and
// adds and removes value rows (a <template>). A yes/no or quantity option has exactly one value, its
// price, so its row has no name and cannot be removed.
window.clientfenceOptionGroupCreated = (id) => {
  if (Number(id) > 0) window.location.href = window.location.href.replace(/\/option-group\/0(?=[/?#]|$)/, `/option-group/${Number(id)}`);
};
const optionForm = document.querySelector('form[data-cf-option-form]');
if (optionForm) {
  const kindField = optionForm.querySelector('[data-cf-option-kind]');
  const list = optionForm.querySelector('[data-cf-option-values]');
  const template = optionForm.querySelector('template[data-cf-option-value-template]');
  const addButton = optionForm.querySelector('[data-cf-option-value-add]');
  const kind = () => (kindField?.tagName === 'SELECT' ? kindField.value : kindField?.dataset.cfOptionKind) || 'select';
  const rows = () => Array.from(list?.querySelectorAll('[data-cf-option-value]') || []);
  let next = rows().length;
  const addRow = () => {
    if (!template || !list || !addButton) return null;
    const holder = document.createElement('div');
    // Rising indexes keep the values in order when the form becomes JSON.
    holder.innerHTML = template.innerHTML.replaceAll('__INDEX__', String(next += 1));
    const row = holder.firstElementChild;
    if (row) list.insertBefore(row, addButton);
    return row;
  };
  const show = () => {
    const current = kind();
    const single = current === 'yesno' || current === 'quantity';
    optionForm.querySelectorAll('[data-cf-option-quantity]').forEach((el) => { el.hidden = current !== 'quantity'; });
    optionForm.querySelectorAll('[data-cf-option-hint]').forEach((el) => { el.hidden = el.dataset.cfOptionHint !== (single ? current : 'select'); });
    if (addButton) addButton.hidden = single;
    if (single) rows().slice(1).forEach((row) => row.remove());
    if (rows().length === 0) addRow();
    rows().forEach((row) => {
      const head = row.querySelector('[data-cf-option-value-head]');
      if (head) head.hidden = single;
      const name = row.querySelector('input[name$="[title]"]');
      if (name) name.required = !single;
    });
  };
  kindField?.addEventListener('change', show);
  addButton?.addEventListener('click', () => addRow()?.querySelector('input:not([type="hidden"])')?.focus());
  list?.addEventListener('click', (event) => {
    const button = event.target.closest?.('[data-cf-option-value-remove]');
    if (!button) return;
    if (rows().length > 1) button.closest('[data-cf-option-value]')?.remove();
  });
  show();
}

// Network issue form (Support/NetworkIssues.php): show the field of the chosen type (a server's
// select, nothing for the system, the "affects" text for other) and open the saved issue.
const issueForm = document.querySelector('[data-bf-issue-form]');
if (issueForm) {
  window.clientfenceIssueSaved = (result) => {
    const id = Number(result?.id ?? 0);
    if (id > 0) window.location.href = `${issueForm.dataset.issueUrl}/${id}?saved=1&emailed=${Number(result?.emailed ?? 0)}`;
  };
  const type = issueForm.querySelector('[data-bf-issue-type]');
  const showType = () => {
    issueForm.querySelectorAll('[data-bf-issue-for]').forEach((section) => {
      const on = section.dataset.bfIssueFor === type?.value;
      section.hidden = !on;
      section.querySelectorAll('input, select').forEach((field) => { field.disabled = !on; });
    });
  };
  type?.addEventListener('change', showType);
  showType();
}

// Bulk price update (Order/BulkPrices.php): after applying, reopen the page with what was done.
const bulkPrices = document.querySelector('[data-bf-bulk-prices]');
if (bulkPrices) {
  window.clientfenceBulkPricesApplied = (result) => {
    const done = new URL(bulkPrices.dataset.doneUrl, window.location.href);
    done.searchParams.set('done', String(Number(result?.updated ?? 0)));
    done.searchParams.set('emailed', String(Number(result?.emailed ?? 0)));
    done.searchParams.set('kept', String(Number(result?.unpaid_invoices ?? 0)));
    window.location.href = done.href;
  };
}

// Sign-in security tab (mod_clientfence_signin_panel, fb_api_form callback): turning the staff PIN on
// answers {confirm, email}: show the PIN field for a second save. Otherwise the settings are saved.
window.clientfenceSigninSaved = (result) => {
  const form = document.querySelector('[data-cf-signin-form]');
  if (!form) return;
  if (result && result.confirm) {
    const box = form.querySelector('[data-cf-signin-confirm]');
    const input = box.querySelector('input');
    box.hidden = false;
    box.querySelector('[data-cf-signin-email]').textContent = String(result.email || '');
    input.required = true;
    input.value = '';
    input.focus();
    window.ClientFence?.message?.(form.dataset.sentText, 'info');
    return;
  }
  window.ClientFence?.message?.(form.dataset.savedText, 'success');
  window.setTimeout(() => window.location.reload(), 900);
};

// SFTP/FTP backups card (mod_clientfence_backups_panel, fb_api_form callback): a test answers
// {tested} and keeps the typed details; "Save & Activate" reloads to show the saved state.
window.clientfenceBackupFtpSaved = (result) => {
  const form = document.querySelector('[data-cf-backup-ftp]');
  if (!form) return;
  if (result && result.tested) {
    window.ClientFence?.message?.(form.dataset.testedText, 'success');
    return;
  }
  window.ClientFence?.message?.(form.dataset.savedText, 'success');
  window.setTimeout(() => window.location.reload(), 900);
};

// ---- The bell: unread count on load, the newest notifications when opened. Opening an item marks
// it read, then follows its link; its buttons mark it read or dismiss it without leaving the page.
const bell = document.querySelector('[data-bf-bell]');
if (bell) {
  const text = readJson(bell, 'text', {});
  const count = bell.querySelector('[data-bf-bell-count]');
  const list = bell.querySelector('[data-bf-bell-list]');
  const all = bell.querySelector('[data-bf-bell-all]');
  const ago = new Intl.RelativeTimeFormat(document.documentElement.lang || undefined, { numeric: 'auto' });
  const age = (seconds) => {
    if (seconds < 60) return text.now || '';
    for (const [unit, size] of [['day', 86400], ['hour', 3600], ['minute', 60]]) {
      if (seconds >= size) return ago.format(-Math.floor(seconds / size), unit);
    }
    return '';
  };
  const action = (icon, label, call, id) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'bf-bell-act';
    button.title = label || '';
    button.setAttribute('aria-label', label || '');
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('class', 'icon');
    svg.setAttribute('aria-hidden', 'true');
    const use = document.createElementNS(svg.namespaceURI, 'use');
    use.setAttribute('href', '#' + icon);
    svg.append(use);
    button.append(svg);
    button.addEventListener('click', () => apiPost(call, { id }).then(() => reload(id, [...list.children].indexOf(button.closest('.bf-bell-row')))));
    return button;
  };
  const note = (message) => {
    const p = document.createElement('p');
    p.className = 'bf-bell-empty';
    p.textContent = message || '';
    list.replaceChildren(p);
  };
  const show = (result) => {
    if (!result) { note(text.error); return; }
    const unread = Number(result.unread) || 0;
    count.hidden = unread === 0;
    count.textContent = unread > 99 ? '99+' : String(unread);
    all.hidden = unread === 0;
    if (!result.items?.length) { note(text.empty); return; }
    list.replaceChildren(...result.items.map((item) => {
      const a = document.createElement('a');
      a.className = 'bf-bell-item' + (item.read ? '' : ' is-unread');
      a.dataset.id = String(item.id);
      a.href = item.url;
      const line = document.createElement('span');
      line.className = 'bf-bell-text';
      line.textContent = item.text;
      const when = document.createElement('span');
      when.className = 'bf-bell-when';
      when.textContent = age(Number(item.age) || 0);
      a.append(line, when);
      if (!item.read) {
        a.addEventListener('click', (event) => {
          const read = apiPost('clientfence/notifications_read', { id: item.id });
          if (event.ctrlKey || event.metaKey || event.shiftKey) return;
          event.preventDefault();
          read.then(() => { window.location.href = item.url; });
        });
      }
      const actions = document.createElement('div');
      actions.className = 'bf-bell-actions';
      if (!item.read) actions.append(action('check', text.read, 'clientfence/notifications_read', item.id));
      actions.append(action('x', text.dismiss, 'clientfence/notifications_dismiss', item.id));
      const row = document.createElement('div');
      row.className = 'bf-bell-row';
      row.append(a, actions);
      return row;
    }));
  };
  const load = () => apiPost('clientfence/notifications_get', {}).then(show);
  // Rebuilding the list drops the keyboard focus: put it back in the bell.
  const reload = (id, index = 0) => load().then(() => {
    const links = [...list.querySelectorAll('.bf-bell-item')];
    const link = links.find((a) => a.dataset.id === String(id)) || links[Math.min(index, links.length - 1)];
    (link || (!all.hidden && all) || bell.querySelector('[data-bs-toggle="dropdown"]')).focus();
  });
  load();
  bell.addEventListener('show.bs.dropdown', load);
  all.addEventListener('click', () => apiPost('clientfence/notifications_read', {}).then(() => reload()));
}
