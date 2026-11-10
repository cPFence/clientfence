/**
 * Store page (mod_order_index.html.twig): category tabs, the billing-cycle switch that updates
 * every card (price, "per", monthly price, Select link) and its "Save up to" badges, and the
 * "Show all features" buttons.
 */
import { MONTHS, cycleTexts, fill, moneyFormatter, readJson } from './store-utils.js';

export default function initStore(root) {
  const texts = readJson(root, '[data-bf-store-texts]');
  const cycles = cycleTexts(texts.cycles);
  const money = moneyFormatter(root.dataset.currency || 'USD');
  const rate = Number(root.dataset.rate) || 1;
  const cards = [...root.querySelectorAll('.bf-product')];
  const pricesOf = (card) => {
    try {
      return JSON.parse(card.dataset.prices || '{}');
    } catch {
      return {};
    }
  };
  let cycle = root.dataset.cycle || '';
  let category = '';

  const render = () => {
    cards.forEach((card) => {
      card.hidden = category !== '' && card.dataset.category !== category;
      // One-time, free and domain products (data-fixed) keep the price the page shows.
      if (card.dataset.once !== undefined || card.dataset.fixed !== undefined) {
        return;
      }
      const price = pricesOf(card)[cycle];
      const select = card.querySelector('[data-select]');
      const priceEl = card.querySelector('[data-price]');
      const perEl = card.querySelector('[data-per]');
      const monthlyEl = card.querySelector('[data-monthly]');
      const offered = price !== undefined;
      card.classList.toggle('is-unavailable', !offered);
      select.classList.toggle('disabled', !offered);
      if (offered) {
        select.removeAttribute('aria-disabled');
      } else {
        select.setAttribute('aria-disabled', 'true');
      }
      select.tabIndex = offered ? 0 : -1;
      if (!offered) {
        priceEl.textContent = '—';
        perEl.textContent = '';
        monthlyEl.textContent = texts.notOffered;
        return;
      }
      priceEl.textContent = money(price * rate);
      perEl.textContent = cycles[cycle]?.per ?? '';
      if (cycle === '1M') {
        monthlyEl.textContent = texts.billedMonthly;
      } else if (cycle === '1W') {
        monthlyEl.textContent = texts.billedWeekly;
      } else {
        monthlyEl.textContent = fill(texts.perMonth, { amount: money((price * rate) / MONTHS[cycle]) });
      }
      select.href = `${card.dataset.href}?period=${encodeURIComponent(cycle)}`;
    });

    root.querySelectorAll('.bf-seg-cycles [data-cycle]').forEach((button) => {
      const code = button.dataset.cycle;
      button.classList.toggle('is-on', code === cycle);
      button.setAttribute('aria-pressed', code === cycle ? 'true' : 'false');
      let best = 0;
      cards.filter((card) => !card.hidden && card.dataset.once === undefined && card.dataset.fixed === undefined).forEach((card) => {
        const prices = pricesOf(card);
        if (prices['1M'] > 0 && prices[code] !== undefined && MONTHS[code] > 1) {
          best = Math.max(best, Math.round((1 - prices[code] / (prices['1M'] * MONTHS[code])) * 100));
        }
      });
      const badge = button.querySelector('[data-save]');
      if (badge) {
        badge.hidden = best <= 0;
        badge.textContent = fill(texts.saveUpTo, { percent: best });
      }
    });

    root.querySelectorAll('[data-category-note]').forEach((note) => {
      note.hidden = note.dataset.categoryNote !== category;
    });
    root.querySelectorAll('.bf-tab').forEach((tab) => {
      const on = tab.dataset.category === category;
      tab.classList.toggle('is-on', on);
      tab.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
  };

  root.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    const cycleButton = target?.closest('.bf-seg-cycles [data-cycle]');
    const tab = target?.closest('.bf-tab');
    const showAll = target?.closest('[data-show-all]');
    const select = target?.closest('[data-select]');
    if (cycleButton) {
      cycle = cycleButton.dataset.cycle;
      render();
    } else if (tab) {
      category = tab.dataset.category;
      render();
    } else if (showAll) {
      const list = showAll.previousElementSibling;
      const open = showAll.getAttribute('aria-expanded') !== 'true';
      list?.querySelectorAll('.bf-more').forEach((item) => { item.hidden = !open; });
      showAll.setAttribute('aria-expanded', open ? 'true' : 'false');
      showAll.dataset.label ??= showAll.textContent;
      showAll.textContent = open ? (texts.showFewer ?? showAll.dataset.label) : showAll.dataset.label;
    } else if (select?.classList.contains('disabled')) {
      event.preventDefault();
    }
  });

  render();
}
