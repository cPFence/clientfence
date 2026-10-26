/**
 * JavaScript for the "Back to Top" button.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license   Apache-2.0
 *
 * This source file is subject to the Apache-2.0 License that is bundled
 * with this source code in the file LICENSE
 *
 * ClientFence: the button fades in once the page is scrolled past `minimum`, its ring shows how
 * far down the page is (--bf-top-progress, 0 to 100) and it scrolls back smoothly unless the
 * visitor asks for reduced motion (clientfence-admin.css, .bf-top).
 */

export default function (options) {
  const defaults = {
    visibleClass: 'is-visible',
    buttonID: 'back-to-top',
    minimum: 300,
  };

  const settings = {...defaults, ...options};
  const button = document.getElementById(settings.buttonID);
  if (!button) {
    return;
  }

  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  button.addEventListener('click', () => {
    window.scrollTo({top: 0, behavior: reducedMotion.matches ? 'auto' : 'smooth'});
  });

  let queued = false;
  const update = () => {
    queued = false;
    const top = window.scrollY || document.documentElement.scrollTop;
    const room = document.documentElement.scrollHeight - window.innerHeight;
    button.classList.toggle(settings.visibleClass, top > settings.minimum);
    button.style.setProperty('--bf-top-progress', String(room > 0 ? Math.min(100, Math.round((top / room) * 100)) : 0));
  };
  window.addEventListener('scroll', () => {
    if (!queued) {
      queued = true;
      window.requestAnimationFrame(update);
    }
  }, {passive: true});
  window.addEventListener('resize', update, {passive: true});
  update();
};
