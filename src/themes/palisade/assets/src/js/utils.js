let i18n;

/** Texts in the page language from partials/i18n.html.twig (`validation`, `messages`, `prefixes`). */
export function texts() {
  if (!i18n) {
    try {
      i18n = JSON.parse(document.getElementById('clientfence-i18n')?.textContent || '{}');
    } catch {
      i18n = {};
    }
  }
  return i18n;
}

/**
 * ClientFence.message(): toast notifications used by module templates.
 * Adapted from FOSSBilling's Huraga theme (Apache-2.0).
 */
window.ClientFence = Object.assign(window.ClientFence || {}, {
  message: (message, type = 'info') => {
    // FOSSBilling's api.js and some module templates word toasts in English only ("Form Updated").
    if (typeof message === 'string') {
      const { messages = {}, prefixes = {} } = texts();
      const prefix = Object.keys(prefixes).find((start) => message.startsWith(start));
      message = Object.hasOwn(messages, message) ? messages[message]
        : prefix ? prefixes[prefix] + message.slice(prefix.length) : message;
    }
    // A CAPTCHA answer works once: after a failed request (wrong password, expired check)
    // give the visitor a fresh widget, or the next try fails verification.
    if (type === 'error') {
      try {
        if (document.querySelector('.g-recaptcha')) window.grecaptcha?.reset?.();
        if (document.querySelector('.cf-turnstile')) window.turnstile?.reset?.();
      } catch {
        // The widget script may not have loaded yet.
      }
    }
    // A card with a tinted icon, a title, the message and a countdown bar (palisade.scss, .cf-toast).
    const kind = ['error', 'warning', 'success'].includes(type) ? type : 'info';
    const icons = { error: 'alert-circle', warning: 'alert-triangle', success: 'circle-check', info: 'info-circle' };
    const container = document.querySelector('.toast-container');
    if (!container) {
      return;
    }
    const svgIcon = (name) => {
      const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      svg.setAttribute('class', 'icon');
      svg.setAttribute('aria-hidden', 'true');
      const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
      use.setAttribute('href', `#${name}`);
      svg.appendChild(use);
      return svg;
    };

    const element = document.createElement('div');
    element.className = `toast cf-toast cf-toast-${kind}`;
    // The container is a polite live region already on the page, so each new toast is read out;
    // errors are also alerts, read out at once.
    if (kind === 'error') {
      element.setAttribute('role', 'alert');
    }
    element.setAttribute('aria-atomic', 'true');

    const badge = document.createElement('span');
    badge.className = 'cf-toast-icon';
    badge.appendChild(svgIcon(icons[kind]));

    const content = document.createElement('div');
    content.className = 'cf-toast-content';
    const title = document.createElement('strong');
    title.className = 'cf-toast-title';
    title.textContent = container.dataset[`title${kind[0].toUpperCase()}${kind.slice(1)}`] || container.dataset.title || 'System message';
    const body = document.createElement('div');
    body.className = 'cf-toast-message';
    body.textContent = message;
    content.append(title, body);

    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'cf-toast-close';
    close.setAttribute('data-bs-dismiss', 'toast');
    close.setAttribute('aria-label', container.dataset.close || 'Close');
    close.appendChild(svgIcon('x'));

    const bar = document.createElement('span');
    bar.className = 'cf-toast-bar';
    bar.setAttribute('aria-hidden', 'true');

    element.append(badge, content, close, bar);
    container.appendChild(element);
    element.addEventListener('hidden.bs.toast', () => element.remove());
    // Errors stay longer, so there is time to read them; hovering keeps any toast open.
    new bootstrap.Toast(element, { delay: kind === 'error' ? 8000 : 5000 }).show();
  },
});
