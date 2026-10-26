/**
 * Two-factor sign-in settings (ClientFence, mod_client_twofactor_settings.html.twig): the setup (a new
 * key from client/twofactor_setup, drawn as a QR code for the authenticator app) and the forms
 * that answer with a backup code (switch on, new backup code), which is shown once.
 */
import qrcode from 'qrcode-generator';
import { api, errorText } from './store-utils.js';

const SVG = 'http://www.w3.org/2000/svg';

/** The QR code of a text as an SVG of squares (no style attributes: the CSP blocks them). */
function qrSvg(text) {
  const qr = qrcode(0, 'M');
  qr.addData(text);
  qr.make();
  const count = qr.getModuleCount();
  const margin = 2;
  const size = count + margin * 2;
  const svg = document.createElementNS(SVG, 'svg');
  svg.setAttribute('viewBox', `0 0 ${size} ${size}`);
  svg.setAttribute('shape-rendering', 'crispEdges');
  svg.setAttribute('role', 'img');
  const background = document.createElementNS(SVG, 'rect');
  background.setAttribute('width', String(size));
  background.setAttribute('height', String(size));
  background.setAttribute('fill', '#fff');
  svg.appendChild(background);
  let d = '';
  for (let row = 0; row < count; row += 1) {
    for (let col = 0; col < count; col += 1) {
      if (qr.isDark(row, col)) {
        d += `M${col + margin} ${row + margin}h1v1h-1z`;
      }
    }
  }
  const path = document.createElementNS(SVG, 'path');
  path.setAttribute('d', d);
  path.setAttribute('fill', '#000');
  svg.appendChild(path);
  return svg;
}

export default function initTwoFactor(root) {
  const start = root.querySelector('[data-clientfence-twofactor-start]');
  start?.addEventListener('click', () => {
    start.disabled = true;
    api('client', 'client/twofactor_setup')
      .then((result) => {
        const box = root.querySelector('[data-clientfence-twofactor-qr]');
        box.replaceChildren(qrSvg(result.uri));
        box.querySelector('svg').setAttribute('aria-label', result.secret);
        root.querySelector('[data-clientfence-twofactor-secret]').textContent = result.secret;
        root.querySelector('[data-clientfence-twofactor-setup]').hidden = false;
        start.hidden = true;
        root.querySelector('#setup_code')?.focus();
      })
      .catch((error) => {
        start.disabled = false;
        ClientFence.message(errorText(error), 'error');
      });
  });

  root.querySelectorAll('form[data-clientfence-twofactor-form]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      const button = form.querySelector('button[type="submit"]');
      button.disabled = true;
      api('client', form.dataset.clientfenceTwofactorForm, Object.fromEntries(new FormData(form)))
        .then((result) => {
          const panel = root.querySelector('[data-clientfence-twofactor-backup]');
          panel.querySelector('[data-clientfence-twofactor-backup-code]').textContent = result.backup_code;
          root.querySelectorAll('[data-clientfence-twofactor-on], [data-clientfence-twofactor-off]').forEach((el) => {
            el.hidden = true;
          });
          panel.hidden = false;
          panel.scrollIntoView({ block: 'start' });
        })
        .catch((error) => {
          button.disabled = false;
          ClientFence.message(errorText(error), 'error');
        });
    });
  });
}
