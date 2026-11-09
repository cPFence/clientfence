// @ts-nocheck -- Runtime DOM/widget integration; converted to TS without changing behavior.
import backToTop from "./ui/backToTop.ts";

function renderTimeSeriesSparkline(...args) {
  return import("./ui/charts.ts").then(({ renderTimeSeriesSparkline: renderChart }) => renderChart(...args));
}

globalThis.ClientFence = Object.assign(globalThis.ClientFence || {}, {
  // ClientFence: toasts as cards with a tinted icon, a title, the message and a countdown bar
  // (clientfence-admin.css, .cf-toast). Titles come translated from the container's data-title-*.
  message: (message, type = "info") => {
    // ClientFence core patch: api.js and this theme word some toasts in English; shown in the page language.
    try {
      const messages = JSON.parse(document.getElementById("clientfence-i18n")?.textContent || "{}").messages || {};
      message = Object.hasOwn(messages, message) ? messages[message] : message;
    } catch { /* shown as given */ }
    const kind = ["error", "warning", "success"].includes(type) ? type : "info";
    const icons = { error: "alert-circle", warning: "alert-triangle", success: "circle-check", info: "info-circle" };
    const fallback = { error: "Error", warning: "Warning", success: "Success", info: "Info" };
    const container = document.querySelector(".toast-container");
    if (!container) {
      return;
    }
    const svgIcon = (name, className) => {
      const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
      svg.setAttribute("class", className);
      svg.setAttribute("aria-hidden", "true");
      const use = document.createElementNS("http://www.w3.org/2000/svg", "use");
      use.setAttribute("href", `#${name}`);
      svg.appendChild(use);
      return svg;
    };

    const element = document.createElement("div");
    element.className = `toast cf-toast cf-toast-${kind}`;
    // The container is a polite live region already on the page, so each new toast is read out;
    // errors are also alerts, read out at once.
    if (kind === "error") {
      element.setAttribute("role", "alert");
    }
    element.setAttribute("aria-atomic", "true");

    const badge = document.createElement("span");
    badge.className = "cf-toast-icon";
    badge.appendChild(svgIcon(icons[kind], "icon"));

    const content = document.createElement("div");
    content.className = "cf-toast-content";
    const titleEl = document.createElement("strong");
    titleEl.className = "cf-toast-title";
    titleEl.textContent = container.dataset[`title${kind[0].toUpperCase()}${kind.slice(1)}`] || fallback[kind];
    const body = document.createElement("div");
    body.className = "cf-toast-message";
    body.textContent = message;
    content.append(titleEl, body);

    const closeButton = document.createElement("button");
    closeButton.type = "button";
    closeButton.className = "cf-toast-close";
    closeButton.setAttribute("data-bs-dismiss", "toast");
    closeButton.setAttribute("aria-label", container.dataset.close || "Close");
    closeButton.appendChild(svgIcon("x", "icon"));

    const bar = document.createElement("span");
    bar.className = "cf-toast-bar";
    bar.setAttribute("aria-hidden", "true");

    element.append(badge, content, closeButton, bar);
    container.appendChild(element);
    element.addEventListener("hidden.bs.toast", () => element.remove());

    // Errors stay longer, so there is time to read them; hovering keeps any toast open.
    new bootstrap.Toast(element, { delay: kind === "error" ? 8000 : 5000 }).show();
  },

  charts: {
    renderTimeSeriesSparkline,
  }
});

  document.addEventListener('DOMContentLoaded', function() {
    // Global error handler for unhandled Promise rejections (API-related only)
    window.addEventListener('unhandledrejection', function(event) {
      const error = event.reason;
      if (error && typeof error === 'object' && error.code) {
        event.preventDefault();
        const message = error.message || error.code || 'An unexpected error occurred';
        ClientFence.message(message, 'error');
      }
    });

    // Initialize backToTop
    ClientFence.backToTop = backToTop;
    ClientFence.backToTop();

    document.addEventListener("click", function(event) {
      const target = event.target;
      if (target.matches("div.msg span.close") || target.closest("div.msg span.close")) {
        event.preventDefault();
        const parent = target.parentElement;

        // Simple slide up effect
        const originalHeight = parent.offsetHeight;
        parent.style.overflow = "hidden";
        parent.style.transition = "height 70ms";
        parent.style.height = originalHeight + "px";

        setTimeout(() => {
          parent.style.height = "0";
          setTimeout(() => {
            parent.style.display = "none";
          }, 70);
        }, 10);

        return false;
      }
    });

   //===== Information boxes =====//
   document.querySelectorAll('.hideit').forEach(element => {
     element.addEventListener('click', function() {
       // Simple fade out effect
       let opacity = 1;
       const fadeEffect = setInterval(() => {
         if (opacity > 0) {
           opacity -= 0.1;
           this.style.opacity = opacity;
         } else {
           clearInterval(fadeEffect);
           this.style.display = 'none';
         }
       }, 40); // 40ms * 10 steps ~= 400ms duration
     });
   });

   //===== Tab deep-linking and persistence =====//
   const tabTriggers = document.querySelectorAll('[data-bs-toggle="tab"], [data-bs-toggle="pill"]');

   const getTabTargetSelector = (tabTrigger) => {
     const dataTarget = tabTrigger.getAttribute('data-bs-target');
     if (dataTarget && dataTarget.startsWith('#')) {
       return dataTarget;
     }

     const hrefTarget = tabTrigger.getAttribute('href');
     if (hrefTarget && hrefTarget.startsWith('#')) {
       return hrefTarget;
     }

     return null;
   };

   const findTabTrigger = (tabId) => {
     if (!tabId) {
       return null;
     }

     return document.querySelector(
       `[data-bs-toggle="tab"][data-bs-target="#${tabId}"], ` +
       `[data-bs-toggle="pill"][data-bs-target="#${tabId}"], ` +
       `[data-bs-toggle="tab"][href="#${tabId}"], ` +
       `[data-bs-toggle="pill"][href="#${tabId}"]`
     );
   };

   const showTabById = (tabId) => {
     const tabTrigger = findTabTrigger(tabId);
     if (!tabTrigger) {
       return false;
     }

     const tab = bootstrap.Tab.getOrCreateInstance(tabTrigger);
     tab.show();

     return true;
   };

   const syncTabUrl = (tabId) => {
     if (!tabId) {
       return;
     }

     const url = new URL(window.location.href);
     url.hash = tabId;
     url.searchParams.delete('tab');
     window.history.replaceState({}, '', url);
   };

   const hashTabId = window.location.hash.startsWith('#') ? window.location.hash.slice(1) : '';
   showTabById(hashTabId);

   tabTriggers.forEach((tabTrigger) => {
     tabTrigger.addEventListener('shown.bs.tab', function() {
       const targetSelector = getTabTargetSelector(this);
       if (targetSelector) {
         syncTabUrl(targetSelector.slice(1));
       }
     });
   });

   window.addEventListener('hashchange', () => {
     const nextTabId = window.location.hash.startsWith('#') ? window.location.hash.slice(1) : '';
     showTabById(nextTabId);
   });

   //===== Jump-to-tab links outside the tab nav (e.g. a note referencing another tab) =====//
   document.addEventListener('click', (event) => {
     if (!(event.target instanceof Element)) {
       return;
     }

     const trigger = event.target.closest('[data-tab-jump]');
     if (!trigger) {
       return;
     }

     const targetSelector = trigger.getAttribute('href');
     if (!targetSelector || !targetSelector.startsWith('#')) {
       return;
     }

     event.preventDefault();
     showTabById(targetSelector.slice(1));
   });

   //===== ClientFence: actions that were inline handlers (the script policy allows none) =====//
   document.addEventListener('click', (event) => {
     const target = event.target instanceof Element ? event.target : null;
     if (target?.closest('[data-cf-print]')) {
       window.print();
     }
     const notice = target?.closest('[data-cf-message]');
     if (notice) {
       event.preventDefault();
       ClientFence.message(notice.getAttribute('data-cf-message') ?? '', 'error');
     }
   });
   // data-cf-submit-on-change: submits the field's form, or the form with the id it names.
   document.addEventListener('change', (event) => {
     const field = event.target instanceof Element ? event.target.closest('[data-cf-submit-on-change]') : null;
     if (!field) {
       return;
     }
     const formId = field.getAttribute('data-cf-submit-on-change');
     const form = formId ? document.getElementById(formId) : field.closest('form');
     form?.requestSubmit?.();
   });
   // data-cf-requires="name": the named field of the form is required while this one is filled in.
   document.addEventListener('input', (event) => {
     const field = event.target instanceof HTMLInputElement ? event.target : null;
     const name = field?.getAttribute('data-cf-requires');
     const other = name && field?.form ? field.form.elements.namedItem(name) : null;
     if (other instanceof HTMLInputElement) {
       other.required = field.value.trim() !== '';
     }
   });

   // ClientFence core patch: the retired upstream community promotion has no popover.

   //===== Search filter toggle state =====//
   const syncSearchFilterToggleState = (toggle, panel) => {
     const targetSelector = toggle.getAttribute('data-bs-target');
     if (!targetSelector || !targetSelector.startsWith('#')) {
       return;
     }

     const isOpen = panel?.classList.contains('show') || toggle.getAttribute('aria-expanded') === 'true';
     toggle.classList.toggle('text-primary', isOpen);
     toggle.classList.toggle('text-secondary', !isOpen);

     const summary = document.querySelector('.filter-panel-summary');
     if (summary) {
       summary.classList.toggle('d-none', isOpen);
     }
   };

   document.querySelectorAll('.search-filter-toggle[data-bs-target]').forEach((toggle) => {
     const targetSelector = toggle.getAttribute('data-bs-target');
     if (!targetSelector || !targetSelector.startsWith('#')) {
       return;
     }

     const panel = document.querySelector(targetSelector);
     if (!panel) {
       return;
     }

     syncSearchFilterToggleState(toggle, panel);

     panel.addEventListener('shown.bs.collapse', () => syncSearchFilterToggleState(toggle, panel));
     panel.addEventListener('hidden.bs.collapse', () => syncSearchFilterToggleState(toggle, panel));
   });
 });
