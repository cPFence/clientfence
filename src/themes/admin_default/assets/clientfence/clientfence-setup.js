// ClientFence setup wizard (mod_clientfence_setup.html.twig; Clientfence Setup.php and
// Branding.php): one step at a time (the address's #step), each form saved by its own
// clientfence/setup_* call, logo uploads by drag and drop, and a live preview of the brand color
// and sign-in style. Server text goes in with textContent only.

const api = window.ClientFence?.api?.admin;
const notify = (message, type = 'error') => window.ClientFence?.ui?.notify ? window.ClientFence.ui.notify(message, type) : window.alert(message);
const post = (endpoint, data) => new Promise((resolve, reject) => {
  if (!api) { reject(new Error('API not loaded')); return; }
  api.post(endpoint, data, resolve, reject, false);
});

// Logo upload zones ([data-cf-drop], mod_clientfence_brand_drops.html.twig) inside `scope`, whose
// data-text-* hold the messages; each upload saves its setting through clientfence/setup_brand_asset.
function bindDrops(scope, uploaded = () => {}) {
  const text = (name) => scope.dataset[name] || '';
  const allowed = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
  scope.querySelectorAll('[data-cf-drop]').forEach((drop) => {
    const input = drop.querySelector('input[type="file"]');
    const img = drop.querySelector('[data-cf-drop-img]');
    const empty = drop.querySelector('.cf-drop-empty');
    const kind = drop.dataset.cfDrop;

    async function upload(file) {
      if (!file) return;
      if (!allowed.includes(file.type)) { notify(text('textNotImage')); return; }
      if (file.size > 2 * 1024 * 1024) { notify(text('textTooBig')); return; }
      const data = new FormData();
      data.append('kind', kind);
      data.append('file', file);
      drop.classList.add('is-busy');
      drop.setAttribute('aria-busy', 'true');
      try {
        const result = await post('clientfence/setup_brand_asset', data);
        img.src = result.url;
        img.hidden = false;
        if (empty) empty.hidden = true;
        uploaded(kind, result.url);
        notify(text('textSaved'), 'success');
      } catch (e) {
        notify(e.message || String(e));
      } finally {
        drop.classList.remove('is-busy');
        drop.removeAttribute('aria-busy');
        input.value = '';
      }
    }

    drop.addEventListener('click', () => input.click());
    drop.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); input.click(); }
    });
    input.addEventListener('click', (event) => event.stopPropagation());
    input.addEventListener('change', () => upload(input.files[0]));
    ['dragenter', 'dragover'].forEach((name) => drop.addEventListener(name, (event) => {
      event.preventDefault();
      drop.classList.add('is-over');
    }));
    ['dragleave', 'dragend'].forEach((name) => drop.addEventListener(name, () => drop.classList.remove('is-over')));
    drop.addEventListener('drop', (event) => {
      event.preventDefault();
      drop.classList.remove('is-over');
      upload(event.dataTransfer?.files?.[0]);
    });
  });
  // A file dropped beside a drop zone would open in the tab and leave the page (text drags still work).
  ['dragover', 'drop'].forEach((name) => window.addEventListener(name, (event) => {
    if (event.dataTransfer?.types?.includes('Files') && !event.target.closest?.('[data-cf-drop]')) event.preventDefault();
  }));
}

// System → Settings shows the same upload zones.
const settingsDrops = document.querySelector('[data-cf-brand-drops]');
if (settingsDrops) bindDrops(settingsDrops);

const root = document.querySelector('[data-cf-setup]');

if (root) {
  const text = (name) => root.dataset[name] || '';
  const panes = Array.from(root.querySelectorAll('[data-cf-pane]'));
  const order = panes.map((pane) => pane.dataset.cfPane);
  const links = new Map(Array.from(root.querySelectorAll('[data-cf-step-link]')).map((a) => [a.dataset.cfStepLink, a]));
  const progress = root.querySelector('[data-cf-progress]');
  // Steps saved in this browser tab, kept across the reload when the admin address moves.
  const DONE_KEY = 'clientfence_setup_done';
  const done = new Set((() => { try { return JSON.parse(sessionStorage.getItem(DONE_KEY) || '[]'); } catch (e) { return []; } })());
  const markDone = (id) => {
    done.add(id);
    try { sessionStorage.setItem(DONE_KEY, JSON.stringify([...done])); } catch (e) { /* private mode */ }
  };

  // ---- steps ----------------------------------------------------------------------------------
  function show(id, focus = true) {
    if (!order.includes(id)) id = order[0];
    for (const pane of panes) pane.hidden = pane.dataset.cfPane !== id;
    const index = order.indexOf(id);
    links.forEach((link, step) => {
      link.classList.toggle('is-current', step === id);
      link.classList.toggle('is-done', done.has(step));
      if (step === id) link.setAttribute('aria-current', 'step');
      else link.removeAttribute('aria-current');
    });
    if (progress) progress.style.width = `${Math.round((index / (order.length - 1)) * 100)}%`;
    // The admin layout has a <base> element: a bare "#step" would point at the theme folder.
    if (location.hash !== `#${id}`) history.replaceState(null, '', `${location.pathname}${location.search}#${id}`);
    if (focus) {
      const heading = root.querySelector(`[data-cf-pane="${id}"] h1`);
      if (heading) { heading.tabIndex = -1; heading.focus({ preventScroll: true }); }
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }
  }

  const current = () => (panes.find((pane) => !pane.hidden) || panes[0]).dataset.cfPane;
  const step = (by) => show(order[Math.min(order.length - 1, Math.max(0, order.indexOf(current()) + by))]);

  links.forEach((link, id) => link.addEventListener('click', (event) => { event.preventDefault(); show(id); }));
  root.querySelectorAll(`[data-cf-pane="${order[0]}"] [data-cf-back]`).forEach((b) => { b.hidden = true; });
  root.querySelectorAll('[data-cf-next]').forEach((b) => b.addEventListener('click', () => step(1)));
  root.querySelectorAll('[data-cf-back]').forEach((b) => b.addEventListener('click', () => step(-1)));
  window.addEventListener('hashchange', () => show(location.hash.slice(1), false));

  // A form's fields as an object: checkboxes are 1 or 0, disabled and nameless fields are left out.
  function values(form) {
    const data = {};
    for (const field of form.elements) {
      if (!field.name || field.disabled || field.type === 'file') continue;
      if (field.type === 'checkbox') data[field.name] = field.checked ? 1 : 0;
      else if (field.type === 'radio') { if (field.checked) data[field.name] = field.value; }
      else if (field.type === 'select-multiple') data[field.name] = Array.from(field.selectedOptions, (o) => o.value);
      else data[field.name] = field.value;
    }
    return data;
  }

  async function save(form) {
    const hex = form.querySelector('[data-cf-color-hex]');
    if (hex && normalize(hex.value)) hex.value = normalize(hex.value);
    const button = form.querySelector('button[type="submit"]');
    if (button) button.disabled = true;
    try {
      const result = await post(form.dataset.cfEndpoint, values(form));
      if (!result?.confirm) markDone(form.dataset.cfPane);
      return result;
    } finally {
      if (button) button.disabled = false;
    }
  }

  for (const form of root.querySelectorAll('form[data-cf-endpoint]')) {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      try {
        const result = await save(form);
        // Turning the staff PIN on: a PIN went to this admin, and the step is saved again with it.
        if (result && typeof result === 'object' && result.confirm) {
          const box = form.querySelector('[data-cf-pin-confirm]');
          box.querySelector('[data-cf-pin-email]').textContent = result.email || '';
          box.hidden = false;
          box.querySelector('input').focus();
          notify(form.dataset.textPinSent, 'success');
          return;
        }
        // A new admin address: this page now lives there.
        if (result && typeof result === 'object' && result.admin_url) {
          window.location.assign(result.admin_url);
          return;
        }
        const pinBox = form.querySelector('[data-cf-pin-confirm]');
        if (pinBox) { pinBox.hidden = true; pinBox.querySelector('input').value = ''; }
        if (form.dataset.cfPane === 'product') form.reset();
        if (form.dataset.cfPane === 'payments' && form.elements.currency && !form.elements.currency.disabled) {
          root.querySelectorAll('[data-cf-currency-code]').forEach((el) => { el.textContent = form.elements.currency.value; });
        }
        notify(text('textSaved'), 'success');
        step(1);
      } catch (e) {
        notify(e.message || String(e));
      }
    });
  }

  // ---- fields shown only for some choices ----------------------------------------------------
  function refreshToggles() {
    const on = new Set();
    root.querySelectorAll('[data-cf-toggle]').forEach((input) => {
      const name = input.dataset.cfToggle;
      if (input.type === 'checkbox' && input.checked) on.add(name);
      // A radio turns on the fields named like its value (mailer "smtp", store mode "testers").
      if (input.type === 'radio' && input.checked && input.value === name) on.add(name);
    });
    root.querySelectorAll('[data-cf-select-toggle]').forEach((select) => {
      const name = select.dataset.cfSelectToggle;
      if (name === 'captcha' ? select.value !== 'none' : select.value === name) on.add(name);
    });
    root.querySelectorAll('[data-cf-when]').forEach((el) => {
      const visible = on.has(el.dataset.cfWhen);
      el.hidden = !visible;
      el.querySelectorAll('input, select, textarea').forEach((field) => { field.disabled = !visible; });
    });
  }
  root.addEventListener('change', (event) => {
    if (event.target.matches('[data-cf-toggle], [data-cf-select-toggle]')) refreshToggles();
  });
  refreshToggles();

  // ---- email test ------------------------------------------------------------------------------
  root.querySelector('[data-cf-test-email]')?.addEventListener('click', async (event) => {
    const button = event.currentTarget;
    const form = button.closest('form');
    if (!form.reportValidity()) return;
    button.disabled = true;
    try {
      await save(form);
      await post('clientfence/setup_test_email', {});
      notify(text('textTestSent'), 'success');
    } catch (e) {
      notify(e.message || String(e));
    } finally {
      button.disabled = false;
    }
  });

  // ---- Stripe webhook address ----------------------------------------------------------------------
  // The address names the Stripe gateway, so Stripe is installed (switched off) when it is turned
  // on here for the first time.
  const stripeUrl = root.querySelector('[data-cf-stripe-url]');
  root.querySelector('input[name="stripe_enabled"]')?.addEventListener('change', async (event) => {
    if (!event.target.checked || !stripeUrl || stripeUrl.value) return;
    try {
      stripeUrl.value = (await post('clientfence/setup_stripe_webhook', {})).url;
    } catch (e) {
      notify(e.message || String(e));
    }
  });
  root.querySelectorAll('[data-cf-copy]').forEach((button) => button.addEventListener('click', async () => {
    const field = root.querySelector(button.dataset.cfCopy);
    if (!field?.value) return;
    try {
      await navigator.clipboard.writeText(field.value);
      notify(text('textCopied'), 'success');
    } catch (e) {
      field.select();
    }
  }));

  // ---- admin address ---------------------------------------------------------------------------
  root.querySelector('[data-cf-admin-random]')?.addEventListener('click', () => {
    const words = ['desk', 'team', 'office', 'panel', 'staff', 'crew', 'hub'];
    const bytes = crypto.getRandomValues(new Uint8Array(6));
    const word = words[bytes[0] % words.length];
    const tail = Array.from(bytes.slice(1), (b) => (b % 36).toString(36)).join('');
    const input = root.querySelector('[data-cf-admin-path]');
    input.value = `/${word}-${tail}`;
    input.focus();
  });

  // ---- finish / skip ---------------------------------------------------------------------------
  async function finish() {
    try {
      await post('clientfence/setup_finish', {});
    } catch (e) {
      notify(e.message || String(e));
      return;
    }
    window.location.assign(root.dataset.dashboard);
  }
  root.querySelector('[data-cf-finish]')?.addEventListener('click', finish);
  root.querySelector('[data-cf-skip-all]')?.addEventListener('click', finish);

  // ---- brand color and live preview ------------------------------------------------------------
  const preview = root.querySelector('[data-cf-preview]');
  const hexInput = root.querySelector('[data-cf-color-hex]');
  const picker = root.querySelector('[data-cf-color-picker]');
  const contrast = root.querySelector('[data-cf-contrast]');
  const swatchBox = root.querySelector('[data-cf-swatches]');

  const toRgb = (hex) => [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16));
  const toHex = (rgb) => `#${rgb.map((c) => Math.round(c).toString(16).padStart(2, '0')).join('')}`;
  const mix = (hex, withHex, share) => { const a = toRgb(hex); const b = toRgb(withHex); return toHex(a.map((c, i) => c + (b[i] - c) * share)); };
  // Same rules as Branding.php: white text up to a luminance of about 0.18, near black above.
  const luminance = (hex) => {
    const [r, g, b] = toRgb(hex).map((c) => { c /= 255; return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4; });
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
  };
  function normalize(value) {
    let v = String(value || '').trim().toLowerCase();
    if (!v.startsWith('#')) v = `#${v}`;
    if (/^#[0-9a-f]{3}$/.test(v)) v = `#${v[1]}${v[1]}${v[2]}${v[2]}${v[3]}${v[3]}`;
    return /^#[0-9a-f]{6}$/.test(v) ? v : null;
  }

  function applyColor(hex, from) {
    const color = normalize(hex);
    if (!color) return;
    const textColor = luminance(color) > 0.18 ? '#111827' : '#ffffff';
    const accent = mix(color, '#ffffff', 0.3);
    const vars = {
      '--cfp-brand': color,
      '--cfp-text': textColor,
      '--cfp-deep': mix(color, '#000000', 0.45),
      '--cfp-subtle': mix(color, '#ffffff', 0.85),
      '--cfp-accent-rgb': toRgb(accent).join(', '),
    };
    for (const [name, value] of Object.entries(vars)) {
      preview?.style.setProperty(name, value);
      root.style.setProperty(name, value);
    }
    // The wizard itself takes the color too.
    root.style.setProperty('--cf-accent', color);
    root.style.setProperty('--cf-accent-rgb', toRgb(color).join(', '));
    if (from !== 'hex' && hexInput) hexInput.value = color;
    if (from !== 'picker' && picker) picker.value = color;
    swatchBox?.querySelectorAll('[data-cf-swatch]').forEach((s) => s.setAttribute('aria-checked', String(s.dataset.cfSwatch === color)));
    if (contrast) {
      const ratio = (Math.max(luminance(color), luminance(textColor)) + 0.05) / (Math.min(luminance(color), luminance(textColor)) + 0.05);
      contrast.textContent = text('textContrast').replace(':ratio', ratio.toFixed(1));
    }
  }

  swatchBox?.querySelectorAll('[data-cf-swatch]').forEach((s) => s.style.setProperty('--cf-swatch', s.dataset.cfSwatch));
  swatchBox?.addEventListener('click', (event) => {
    const swatch = event.target.closest('[data-cf-swatch]');
    if (swatch) applyColor(swatch.dataset.cfSwatch);
  });
  picker?.addEventListener('input', () => applyColor(picker.value, 'picker'));
  hexInput?.addEventListener('input', () => applyColor(hexInput.value, 'hex'));
  hexInput?.addEventListener('blur', () => { const c = normalize(hexInput.value); if (c) hexInput.value = c; });
  root.querySelector('[data-cf-color-reset]')?.addEventListener('click', () => applyColor(root.dataset.defaultColor));

  const loginMock = root.querySelector('[data-cf-preview-login]');
  function applyLoginStyle() {
    const style = root.querySelector('[data-cf-login-style]:checked')?.value || 'dark';
    loginMock?.classList.remove('is-dark', 'is-brand', 'is-light');
    loginMock?.classList.add(`is-${style}`);
    refreshLogos();
  }
  root.querySelectorAll('[data-cf-login-style]').forEach((r) => r.addEventListener('change', applyLoginStyle));

  // The business name typed on the first step names the mocks that have no logo.
  root.querySelector('[data-cf-company-name]')?.addEventListener('input', (event) => {
    root.querySelectorAll('[data-cf-preview-name]').forEach((b) => { b.textContent = event.target.value; });
  });

  // ---- logos -------------------------------------------------------------------------------------
  const logoUrl = (kind) => root.querySelector(`[data-cf-drop="${kind}"] [data-cf-drop-img]:not([hidden])`)?.getAttribute('src') || '';
  function refreshLogos() {
    const style = root.querySelector('[data-cf-login-style]:checked')?.value || 'dark';
    const light = logoUrl('logo') || logoUrl('logo_dark');
    const dark = logoUrl('logo_dark') || logoUrl('logo');
    root.querySelectorAll('[data-cf-preview-logo]').forEach((holder) => {
      const src = holder.dataset.cfPreviewLogo === 'side' && style !== 'light' ? dark : light;
      const img = holder.querySelector('img');
      const name = holder.querySelector('b');
      img.hidden = !src;
      name.hidden = Boolean(src);
      if (src) img.src = src;
    });
  }

  // "From your logo": the most colorful common color of the uploaded logo, offered as a swatch.
  function logoColor(url) {
    const img = new Image();
    img.onload = () => {
      const size = 64;
      const canvas = document.createElement('canvas');
      canvas.width = size;
      canvas.height = size;
      const ctx = canvas.getContext('2d', { willReadFrequently: true });
      ctx.drawImage(img, 0, 0, size, size);
      let data;
      try { data = ctx.getImageData(0, 0, size, size).data; } catch (e) { return; }
      const buckets = new Map();
      for (let i = 0; i < data.length; i += 4) {
        const [r, g, b, a] = [data[i], data[i + 1], data[i + 2], data[i + 3]];
        if (a < 200) continue;
        const max = Math.max(r, g, b);
        const min = Math.min(r, g, b);
        const sat = max === 0 ? 0 : (max - min) / max;
        if (sat < 0.35 || max < 50 || max > 250 && min > 230) continue;
        const key = `${r >> 4},${g >> 4},${b >> 4}`;
        const bucket = buckets.get(key) || { n: 0, r: 0, g: 0, b: 0, sat: 0 };
        bucket.n += 1; bucket.r += r; bucket.g += g; bucket.b += b; bucket.sat += sat;
        buckets.set(key, bucket);
      }
      let best = null;
      for (const bucket of buckets.values()) {
        const score = bucket.n * (bucket.sat / bucket.n);
        if (!best || score > best.score) best = { ...bucket, score };
      }
      if (!best || best.n < 6) return;
      const color = toHex([best.r / best.n, best.g / best.n, best.b / best.n]);
      let swatch = swatchBox.querySelector('.is-logo');
      if (!swatch) {
        swatch = document.createElement('button');
        swatch.type = 'button';
        swatch.className = 'cf-swatch is-logo';
        swatch.setAttribute('role', 'radio');
        swatch.textContent = text('textFromLogo');
        swatchBox.prepend(swatch);
      }
      swatch.dataset.cfSwatch = color;
      swatch.setAttribute('aria-label', `${text('textFromLogo')} ${color}`);
      swatch.style.setProperty('--cf-swatch', color);
      swatch.style.setProperty('--cf-swatch-text', luminance(color) > 0.18 ? '#111827' : '#ffffff');
      swatch.setAttribute('aria-checked', String(normalize(hexInput?.value) === color));
    };
    img.src = url;
  }

  bindDrops(root, (kind, url) => {
    refreshLogos();
    if (kind === 'logo') logoColor(url);
  });

  // ---- start ---------------------------------------------------------------------------------------
  applyColor(hexInput?.value || root.dataset.defaultColor);
  applyLoginStyle();
  show(location.hash.slice(1), false);
}
