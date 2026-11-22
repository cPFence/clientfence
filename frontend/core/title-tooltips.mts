/**
 * ClientFence: every `title` in the admin and client areas shows the styled tooltip (css/tooltip.css), never the
 * browser's own. One shared card with Bootstrap's tooltip markup, placed here rather than by a Bootstrap Tooltip:
 * Bootstrap allows one component per element, and many titled buttons already hold a Dropdown or Tab.
 */

// Left native: a pattern field's title is its format hint in the browser's validation message; editors and SVG manage their own.
const SKIP = 'input[pattern], iframe, option, svg *, .ck, .ck *, .tooltip, .tooltip *';
const GAP = 8;

let card: HTMLElement | null = null;
let owner: Element | null = null;
let timer = 0;

function hide(): void {
  window.clearTimeout(timer);
  if (owner?.getAttribute('aria-describedby') === 'cf-tooltip') {
    owner.removeAttribute('aria-describedby');
  }
  owner = null;
  card?.remove();
}

function place(el: Element, text: string): void {
  if (!card) {
    card = Object.assign(document.createElement('div'), { id: 'cf-tooltip', className: 'tooltip fade' });
    card.setAttribute('role', 'tooltip');
  }
  card.replaceChildren(Object.assign(document.createElement('div'), { className: 'tooltip-arrow' }),
    Object.assign(document.createElement('div'), { className: 'tooltip-inner', textContent: text }));
  card.classList.remove('show');
  document.body.append(card);
  const box = el.getBoundingClientRect();
  const { offsetWidth: w, offsetHeight: h } = card;
  const below = box.top < h + GAP * 2;
  const left = Math.min(Math.max(box.left + box.width / 2 - w / 2, GAP), window.innerWidth - w - GAP);
  card.dataset.popperPlacement = below ? 'bottom' : 'top';
  card.style.left = `${left + window.scrollX}px`;
  card.style.top = `${(below ? box.bottom + GAP : box.top - h - GAP) + window.scrollY}px`;
  (card.firstElementChild as HTMLElement).style.left = `${Math.min(Math.max(box.left + box.width / 2 - left, 12), w - 12) - 6}px`;
  if (!el.hasAttribute('aria-describedby')) {
    el.setAttribute('aria-describedby', 'cf-tooltip');
  }
  requestAnimationFrame(() => card?.classList.add('show'));
}

function enter(event: Event): void {
  const el = event.target instanceof Element ? event.target.closest('[title], [data-cf-title]') : null;
  if (el === owner) {
    return;
  }
  hide();
  if (!el || el.matches(SKIP)) {
    return;
  }
  // Taken out of the attribute so the browser shows nothing; a script that sets a new title later is picked up here.
  const title = el.getAttribute('title');
  if (title !== null) {
    // The title stays the accessible name of an element with no text or label of its own (an icon button), kept current.
    const label = el.getAttribute('aria-label');
    if (label !== null && label === el.getAttribute('data-cf-title')) {
      el.setAttribute('aria-label', title);
    } else if (label === null && title.trim() && !el.textContent?.trim() && !el.matches('input, select, textarea')) {
      el.setAttribute('aria-label', title);
    }
    el.setAttribute('data-cf-title', title);
    el.removeAttribute('title');
  }
  const text = el.getAttribute('data-cf-title')?.trim() ?? '';
  // A title that only repeats the text on screen (a menu label) adds nothing.
  if (!text || (el as HTMLElement).innerText?.trim() === text) {
    return;
  }
  owner = el;
  timer = window.setTimeout(() => owner === el && el.isConnected && place(el, text), 350);
}

document.addEventListener('mouseover', enter, true);
document.addEventListener('focusin', enter, true);
document.addEventListener('focusout', hide, true);
document.addEventListener('mousedown', hide, true);
document.addEventListener('scroll', hide, true);
document.addEventListener('keydown', (event) => event.key === 'Escape' && hide());
