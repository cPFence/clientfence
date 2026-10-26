/**
 * ClientFence client area script.
 *
 * Keeps the behaviour FOSSBilling's module templates expect from a client theme
 * (toasts, flash messages, tooltips, tab hashes, phone input, the global
 * `bootstrap` object). Adapted from FOSSBilling's Huraga theme (Apache-2.0),
 * with the language switch and theme toggle reduced to plain links.
 */
import { Tooltip, Toast, Modal, Collapse, Tab, Dropdown, Offcanvas } from 'bootstrap/dist/js/bootstrap.esm.js';
import { texts } from './utils.js';
import { api } from './store-utils.js';

globalThis.bootstrap = { Tooltip, Toast, Modal, Collapse, Tab, Dropdown, Offcanvas };

/**
 * Sign-in forms (sign-in page, invitation page; fb_api_form callback): a sign-in with two-factor
 * sign-in on answers {clientfence_twofactor: true} and goes on to the code page; any other goes
 * where the form says.
 */
globalThis.clientfenceSignIn = (result) => {
  const form = document.querySelector('form[data-clientfence-sign-in]');
  window.location.assign(result && result.clientfence_twofactor ? form.dataset.twofactor : form.dataset.redirect);
};

/**
 * Sign-up page (fb_api_form callback): the account is made. With "auto login after sign-up" on
 * (Settings → Clients) the sign-up signed in too (one CAPTCHA, core patch): on to the client
 * area. Otherwise (or the email had an account already, which FOSSBilling never says) the note
 * asks to sign in.
 */
globalThis.clientfenceSignUp = async () => {
  const form = document.querySelector('form[data-clientfence-sign-up]');
  try {
    await api('client', 'profile/get');
    window.location.assign(form.dataset.redirect);
  } catch {
    form.hidden = true;
    form.parentElement.querySelector('[data-clientfence-sign-up-done]').hidden = false;
  }
};

// ClientFence Google sign-in page: the password was right, on to Google to choose the account.
globalThis.clientfenceGoogleLink = (result) => {
  if (result && typeof result.url === 'string' && result.url.startsWith('https://accounts.google.com/')) {
    window.location.assign(result.url);
  }
};

const errorText = (error, fallback = 'An unexpected error occurred') => {
  if (error && typeof error === 'object') {
    return error.message || error.code || fallback;
  }
  return typeof error === 'string' ? error : fallback;
};

/** Light / dark toggle. The choice lives in localStorage['theme']; partials/theme_init applies it before paint. */
function initTheme() {
  document.querySelectorAll('.js-theme-toggler').forEach((el) => {
    el.addEventListener('click', (event) => {
      event.preventDefault();
      const theme = el.dataset.theme;
      if (theme !== 'light' && theme !== 'dark') {
        return;
      }
      try { localStorage.setItem('theme', theme); } catch { /* storage unavailable */ }
      document.documentElement.setAttribute('data-bs-theme', theme);
    });
  });
}

/**
 * The menu collapsed to its icons (large screens), as in the admin area: a button by the logo and
 * one at the bottom of the menu share the choice, kept in localStorage; partials/theme_init applies it before paint.
 */
function initMenuCollapse() {
  const root = document.documentElement;
  const buttons = [...document.querySelectorAll('[data-cf-collapse]')];
  const show = () => {
    const collapsed = root.classList.contains('cf-menu-collapsed');
    buttons.forEach((button) => {
      const label = (collapsed ? button.dataset.labelExpand : button.dataset.labelCollapse) || '';
      button.setAttribute('aria-pressed', collapsed ? 'true' : 'false');
      button.title = label;
      if (button.hasAttribute('aria-label')) button.setAttribute('aria-label', label);
      const text = button.querySelector('[data-cf-collapse-label]');
      if (text) text.textContent = label;
    });
  };
  buttons.forEach((button) => button.addEventListener('click', () => {
    const collapsed = !root.classList.contains('cf-menu-collapsed');
    root.classList.toggle('cf-menu-collapsed', collapsed);
    try {
      if (collapsed) localStorage.setItem('clientfence_client_menu', 'collapsed');
      else localStorage.removeItem('clientfence_client_menu');
    } catch { /* storage unavailable: the choice lasts for this page */ }
    show();
    // The button by the logo hides once the menu is collapsed: keep the focus in the menu.
    if (button.offsetParent === null) buttons.find((b) => b.offsetParent !== null)?.focus();
  }));
  show();
}

/**
 * Language switch. English is the default; picking a language stores it in
 * FOSSBilling's locale cookie for a year, so the choice is kept next time.
 */
function initLanguageSwitch() {
  document.querySelectorAll('.js-locale-switch').forEach((el) => {
    el.addEventListener('click', (event) => {
      event.preventDefault();
      const cookie = ClientFence.cookieNames?.locale || 'clientfence_locale';
      ClientFence.cookieCreate(cookie, el.dataset.locale, 365);
      window.location.reload();
    });
  });
}

/**
 * Message boxes follow the language typed in. An empty box takes the page direction (the
 * caret starts on the right on Arabic pages); once there is text, its first letter decides.
 * The whole box keeps one direction, so a new line starts on the same side. (dir="auto"
 * alone put the caret on the left of an empty box and of each new empty line.)
 */
function initTextDirection() {
  const pageDir = document.documentElement.dir === 'rtl' ? 'rtl' : 'ltr';
  const firstLetter = /[A-Za-zÀ-ɏͰ-ϿЀ-ӿ]|[֐-ࣿיִ-﷿ﹰ-﻿]/;
  document.querySelectorAll('textarea').forEach((box) => {
    const update = () => {
      const letter = box.value.match(firstLetter)?.[0];
      box.dir = letter ? (/[֐-ࣿיִ-﷿ﹰ-﻿]/.test(letter) ? 'rtl' : 'ltr') : pageDir;
    };
    update();
    box.addEventListener('input', update);
  });
}

/**
 * Form check messages in the page language. Browsers word them in their own language, so an
 * Arabic page showed "Please fill out this field." The message is set when a field fails its
 * check and cleared as soon as the client edits it, so the browser checks it again.
 */
function initValidationMessages() {
  const t = texts().validation;
  if (!t) {
    return;
  }
  const messageFor = (field) => {
    const v = field.validity;
    if (v.valueMissing) {
      if (field.type === 'checkbox') return t.checkbox;
      if (field.type === 'radio') return t.radio;
      if (field.tagName === 'SELECT') return t.select;
      if (field.type === 'file') return t.file;
      return t.required;
    }
    if (v.badInput) return t.number;
    if (v.typeMismatch) return field.type === 'email' ? t.email : t.url;
    if (v.tooShort) return t.tooShort.replace(':min', field.minLength);
    if (v.tooLong) return t.tooLong.replace(':max', field.maxLength);
    if (v.patternMismatch) return field.title ? `${t.pattern} ${field.title}` : t.pattern;
    if (v.rangeUnderflow) return t.min.replace(':min', field.min);
    if (v.rangeOverflow) return t.max.replace(':max', field.max);
    if (v.stepMismatch) return t.step;
    return '';
  };
  const isField = (el) => el instanceof HTMLInputElement || el instanceof HTMLSelectElement || el instanceof HTMLTextAreaElement;

  // `invalid` does not bubble: listen in the capture phase.
  document.addEventListener('invalid', (event) => {
    const field = event.target;
    // Leave alone a message a page script set itself.
    if (!isField(field) || (field.validity.customError && !field.dataset.clientfenceValidity)) {
      return;
    }
    field.setCustomValidity('');
    const message = messageFor(field);
    if (message) {
      field.setCustomValidity(message);
      field.dataset.clientfenceValidity = '1';
    } else {
      delete field.dataset.clientfenceValidity;
    }
  }, true);

  const clear = (event) => {
    const field = event.target;
    if (isField(field) && field.dataset.clientfenceValidity) {
      field.setCustomValidity('');
      delete field.dataset.clientfenceValidity;
    }
  };
  document.addEventListener('input', clear, true);
  document.addEventListener('change', clear, true);

  // A field can become valid without being edited (another radio of its group checked, a field
  // no longer required). Before each submit, drop the messages set here so the browser checks
  // every field as it is now; the invalid ones get their message again above.
  document.addEventListener('click', (event) => {
    const button = event.target instanceof Element ? event.target.closest('button, input') : null;
    if (!button?.form || (button.type !== 'submit' && button.type !== 'image')) {
      return;
    }
    [...button.form.elements].forEach((field) => clear({ target: field }));
  }, true);
}

// Theme setting "Show Top Button": fades in once the page is scrolled down; its ring shows how far
// (--bf-top-progress, 0 to 100); scrolls back smoothly unless the visitor asks for reduced motion.
function initBackToTop() {
  const button = document.querySelector('.bf-to-top');
  if (!button) {
    return;
  }
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  button.addEventListener('click', () => {
    window.scrollTo({ top: 0, behavior: reducedMotion.matches ? 'auto' : 'smooth' });
  });
  let queued = false;
  const update = () => {
    queued = false;
    const top = window.scrollY || document.documentElement.scrollTop;
    const room = document.documentElement.scrollHeight - window.innerHeight;
    button.classList.toggle('is-visible', top > 300);
    button.style.setProperty('--bf-top-progress', String(room > 0 ? Math.min(100, Math.round((top / room) * 100)) : 0));
  };
  window.addEventListener('scroll', () => {
    if (!queued) {
      queued = true;
      window.requestAnimationFrame(update);
    }
  }, { passive: true });
  window.addEventListener('resize', update, { passive: true });
  update();
}

/**
 * The bell in the top bar: unread count on load, the newest notifications when opened. Opening
 * an unread item marks it read, then follows its link; its buttons mark it read or dismiss it
 * without leaving the page. Text goes in with textContent only.
 */
// Tables marked .table-stack become one card per row on phones (scss); each cell is labelled with its column.
function initStackedTables() {
  document.querySelectorAll('table.table-stack').forEach((table) => {
    const heads = [...table.querySelectorAll('thead th')].map((th) => th.textContent.trim());
    table.querySelectorAll('tbody tr, tfoot tr').forEach((row) => {
      let column = 0;
      [...row.children].forEach((cell) => {
        if (!cell.hasAttribute('colspan') && heads[column]) {
          cell.dataset.label = heads[column];
        }
        column += cell.colSpan || 1;
      });
    });
  });
}

function initBell() {
  const bell = document.querySelector('[data-cf-bell]');
  if (!bell) return;
  let text = {};
  try { text = JSON.parse(bell.dataset.text || '{}'); } catch { /* no texts: blank notes */ }
  const count = bell.querySelector('[data-cf-bell-count]');
  const list = bell.querySelector('[data-cf-bell-list]');
  const all = bell.querySelector('[data-cf-bell-all]');
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
    button.className = 'topbar-bell-act';
    button.title = label || '';
    button.setAttribute('aria-label', label || '');
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('class', 'icon');
    svg.setAttribute('aria-hidden', 'true');
    const use = document.createElementNS(svg.namespaceURI, 'use');
    use.setAttribute('href', '#' + icon);
    svg.append(use);
    button.append(svg);
    button.addEventListener('click', () => api('client', call, { id }).catch(() => {}).then(() => reload(id, [...list.children].indexOf(button.closest('.topbar-bell-row')))));
    return button;
  };
  const note = (message) => {
    const p = document.createElement('p');
    p.className = 'topbar-bell-empty';
    p.textContent = message || '';
    list.replaceChildren(p);
  };
  const show = (result) => {
    const unread = Number(result.unread) || 0;
    count.hidden = unread === 0;
    count.textContent = unread > 99 ? '99+' : String(unread);
    all.hidden = unread === 0;
    if (!result.items?.length) { note(text.empty); return; }
    list.replaceChildren(...result.items.map((item) => {
      const a = document.createElement('a');
      a.className = 'topbar-bell-item' + (item.read ? '' : ' is-unread');
      a.dataset.id = String(item.id);
      a.href = item.url;
      const line = document.createElement('span');
      line.textContent = item.text;
      const when = document.createElement('small');
      when.textContent = age(Number(item.age) || 0);
      a.append(line, when);
      if (!item.read) {
        a.addEventListener('click', (event) => {
          const read = api('client', 'clientfence/notifications_read', { id: item.id }).catch(() => {});
          if (event.ctrlKey || event.metaKey || event.shiftKey) return;
          event.preventDefault();
          read.then(() => window.location.assign(item.url));
        });
      }
      const actions = document.createElement('div');
      actions.className = 'topbar-bell-actions';
      if (!item.read) actions.append(action('check', text.read, 'clientfence/notifications_read', item.id));
      actions.append(action('x', text.dismiss, 'clientfence/notifications_dismiss', item.id));
      const row = document.createElement('div');
      row.className = 'topbar-bell-row';
      row.append(a, actions);
      return row;
    }));
  };
  const load = () => api('client', 'clientfence/notifications_get').then(show, () => note(text.error));
  // Rebuilding the list drops the keyboard focus: put it back in the bell.
  const reload = (id, index = 0) => load().then(() => {
    const links = [...list.querySelectorAll('.topbar-bell-item')];
    const link = links.find((a) => a.dataset.id === String(id)) || links[Math.min(index, links.length - 1)];
    (link || (!all.hidden && all) || bell.querySelector('[data-bs-toggle="dropdown"]')).focus();
  });
  load();
  bell.addEventListener('show.bs.dropdown', load);
  all.addEventListener('click', () => api('client', 'clientfence/notifications_read').catch(() => {}).then(() => reload()));
}

document.addEventListener('DOMContentLoaded', () => {
  initBackToTop();
  window.addEventListener('unhandledrejection', (event) => {
    ClientFence.message(errorText(event.reason), 'error');
  });

  window.onerror = (message, source, lineno, colno, error) => {
    ClientFence.message(error?.message || message, 'error');
  };

  initTheme();
  initMenuCollapse();
  initLanguageSwitch();
  initTextDirection();
  initValidationMessages();
  initBell();
  initStackedTables();
  // The phone widget is large; load it only on pages with a phone field.
  if (document.querySelector('.js-phone-input')) {
    import('./phone-input.js').then((module) => module.default());
  }
  // Store, checkout and payment pages.
  const store = document.querySelector('[data-bf-store]');
  if (store) {
    import('./store.js').then((module) => module.default(store));
  }
  const checkout = document.querySelector('[data-bf-checkout]');
  if (checkout) {
    import('./checkout.js').then((module) => module.default(checkout));
  }
  // Configurable options on FOSSBilling's order steps (the checkout prices its own).
  document.querySelectorAll('[data-cf-options]').forEach((box) => {
    if (!box.closest('[data-bf-checkout]')) {
      import('./product-options.js').then((module) => module.default(box));
    }
  });
  const optionsChange = document.querySelector('[data-cf-options-change]');
  if (optionsChange) {
    import('./product-options.js').then((module) => module.initOptionsChange(optionsChange));
  }
  if (document.querySelector('[data-bf-invoice], [data-bf-wallet]')) {
    import('./payments.js').then((module) => module.default());
  }
  const twoFactor = document.querySelector('[data-clientfence-twofactor]');
  if (twoFactor) {
    import('./twofactor.js').then((module) => module.default(twoFactor));
  }
  if (document.querySelector('[data-clientfence-ticket-upload]')) {
    import('./ticketfiles.js').then((module) => module.default());
  }

  // Print buttons (the CSP blocks inline handlers).
  document.querySelectorAll('[data-bf-print]').forEach((el) => el.addEventListener('click', () => window.print()));
  document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => new Tooltip(el));

  // Open the tab named in the URL hash, and keep the hash in sync with the open tab.
  const tabSelector = '[data-bs-toggle="tab"], [data-bs-toggle="pill"], [data-bs-toggle="list"]';
  const showLinkedTab = () => {
    const id = window.location.hash.slice(1);
    const trigger = [...document.querySelectorAll(tabSelector)]
      .find((tab) => (tab.getAttribute('data-bs-target') || tab.getAttribute('href')) === `#${id}`);
    if (trigger) {
      Tab.getOrCreateInstance(trigger).show();
    }
  };
  showLinkedTab();
  window.addEventListener('hashchange', showLinkedTab);
  document.querySelectorAll(tabSelector).forEach((trigger) => {
    trigger.addEventListener('shown.bs.tab', () => {
      const target = trigger.getAttribute('data-bs-target') || trigger.getAttribute('href');
      if (target?.startsWith('#')) {
        history.replaceState({}, '', `${window.location.pathname}${window.location.search}${target}`);
      }
    });
  });

  // Messages that survive a reload (used by module templates through flashMessage()).
  globalThis.flashMessage = ({ message = '', reload = false, type = 'info' }) => {
    const key = 'flash-message';
    const stored = sessionStorage.getItem(key);
    if (message === '' && stored) {
      ClientFence.message(stored, type);
      sessionStorage.removeItem(key);
      return;
    }
    if (message) {
      sessionStorage.setItem(key, message);
      if (reload === true) {
        window.location.reload();
      } else if (typeof reload === 'string') {
        window.location.assign(reload);
      }
    }
  };
  flashMessage({ type: 'success' });

  // FOSSBilling's API forms and links (data-fb-api) reload or leave the page before they show
  // their success message: keep a form's own message for the next page, which shows it above.
  const fbApi = globalThis.ClientFence?.api;
  if (fbApi && typeof fbApi._afterComplete === 'function') {
    const afterComplete = fbApi._afterComplete;
    fbApi._afterComplete = function (element, result) {
      let config = {};
      try {
        config = globalThis.ClientFence.tools.parseDataAttr(element.dataset.fbApi || '{}');
      } catch { /* FOSSBilling reports it */ }
      if (typeof globalThis[config.callback] !== 'function' && ('redirect' in config || 'reload' in config)
        && typeof config.message === 'string' && config.message) {
        try {
          sessionStorage.setItem('flash-message', config.message);
        } catch { /* private mode: no message */ }
      }
      return afterComplete.call(this, element, result);
    };
  }

  // Mark required fields, except on the sign-in forms.
  document.querySelectorAll('input[required], textarea[required]').forEach((input) => {
    const label = input.previousElementSibling;
    const isAuth = input.closest('.auth') !== null;
    if (!isAuth && label && label.tagName.toLowerCase() === 'label' && !label.querySelector('.required-mark')) {
      const mark = document.createElement('span');
      mark.textContent = ' *';
      mark.className = 'required-mark text-danger';
      label.appendChild(mark);
    }
  });

  // Forms that ask before they post (data-clientfence-confirm="question"; ClientFence Users page).
  // Runs before FOSSBilling's form handler, which listens on the form itself.
  document.addEventListener('submit', (event) => {
    const form = event.target;
    const question = form instanceof HTMLFormElement ? form.dataset.clientfenceConfirm : undefined;
    if (question && !window.confirm(question)) {
      event.preventDefault();
      event.stopImmediatePropagation();
    }
  }, true);

  // Account switch of an authorized user (ClientFence; top bar menu).
  document.querySelectorAll('.js-clientfence-switch').forEach((button) => {
    button.addEventListener('click', () => {
      button.disabled = true;
      ClientFence.api.client.post('client/user_switch', { client_id: button.dataset.clientId },
        () => window.location.assign(button.dataset.home),
        (error) => {
          button.disabled = false;
          ClientFence.message(errorText(error), 'error');
        });
    });
  });

  document.querySelectorAll('select.currency_selector').forEach((select) => {
    select.addEventListener('change', () => {
      ClientFence.api.guest.post('cart/set_currency', { currency: select.value },
        () => location.reload(),
        (error) => ClientFence.message(errorText(error), 'error'));
    });
  });
});
