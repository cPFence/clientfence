/**
 * ClientFence passkeys in the admin area (Client/Passkeys.php), loaded only where one is drawn: by the staff
 * sign-in page while passkeys are on for staff, and by clientfence-admin.js on other pages.
 * - [data-cf-passkey="signin"]: the sign-in page's button, and the passkey list of the email field's autofill;
 * - [data-cf-passkey="list"]: the profile's "Add a passkey" form (with the password);
 * - [data-cf-passkey="offer"]: "Sign in faster next time" after a password sign-in, shown only where the
 *   device has a fingerprint, face or PIN unlock. "Not now" is a cookie for a year.
 * Keys, challenges and ids travel as base64url (no padding). Same steps as Palisade's passkeys.js.
 */
const bytes = (text) => Uint8Array.from(atob(text.replace(/-/g, '+').replace(/_/g, '/')), (c) => c.charCodeAt(0));
const text = (buffer) => btoa(String.fromCharCode(...new Uint8Array(buffer))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
// The browser's own "cancelled" or "aborted": nothing to say.
const quiet = (error) => error && (error.name === 'NotAllowedError' || error.name === 'AbortError');
const say = (error) => {
  if (!quiet(error)) window.ClientFence?.message?.(error?.message || String(error), 'error');
};

function api(role, endpoint, data = {}) {
  return new Promise((resolve, reject) => {
    window.ClientFence.api[role].post(endpoint, data, resolve, (error) => reject(error), false);
  });
}

/** The browser's prompt for a new passkey, then the server saves it. No password after a sign-in offer. */
async function create(root, password) {
  const o = await api(root.dataset.role, root.dataset.options, password === undefined ? {} : { password });
  let credential;
  try {
    credential = await navigator.credentials.create({
      publicKey: {
        ...o,
        challenge: bytes(o.challenge),
        user: { ...o.user, id: bytes(o.user.id) },
        excludeCredentials: o.excludeCredentials.map((c) => ({ ...c, id: bytes(c.id) })),
      },
    });
  } catch (error) {
    if (error && error.name === 'InvalidStateError') throw new Error(root.dataset.already);
    throw error;
  }
  const r = credential.response;
  // No getPublicKey() (an old browser): the server refuses with "this browser cannot create a passkey".
  const key = typeof r.getPublicKey === 'function' ? r.getPublicKey() : null;
  return api(root.dataset.role, root.dataset.add, {
    id: credential.id,
    clientDataJSON: text(r.clientDataJSON),
    authenticatorData: typeof r.getAuthenticatorData === 'function' ? text(r.getAuthenticatorData()) : '',
    publicKey: key ? text(key) : '',
    publicKeyAlgorithm: typeof r.getPublicKeyAlgorithm === 'function' ? r.getPublicKeyAlgorithm() : 0,
  });
}

function initSignIn(button) {
  if (!window.PublicKeyCredential) return;
  button.hidden = false;
  // The pending request's (the autofill's or the button's); null while none is pending.
  let controller = null;
  const signIn = async (mediation) => {
    controller?.abort();
    const own = new AbortController();
    controller = own;
    try {
      const o = await api('guest', button.dataset.options);
      // Some browsers keep an autofill request past its timeout: end it with its challenge, then wait again.
      if (mediation === 'conditional') setTimeout(() => own.abort(), o.timeout);
      const credential = await navigator.credentials.get({
        mediation,
        signal: own.signal,
        publicKey: { challenge: bytes(o.challenge), rpId: o.rpId, userVerification: o.userVerification, timeout: o.timeout },
      });
      const r = credential.response;
      await api('guest', button.dataset.signin, {
        id: credential.id,
        clientDataJSON: text(r.clientDataJSON),
        authenticatorData: text(r.authenticatorData),
        signature: text(r.signature),
        userHandle: r.userHandle ? text(r.userHandle) : '',
      });
      window.location.assign(button.dataset.redirect);
    } finally {
      if (controller === own) controller = null;
    }
  };
  // The email field's passkey list works only while a conditional request waits: keep one waiting.
  const autofill = () => {
    if (controller) return;
    PublicKeyCredential.isConditionalMediationAvailable?.().then((available) => {
      if (!available || controller) return;
      const started = Date.now();
      signIn('conditional').catch((error) => {
        say(error);
        // Ended (timeout, the browser's abort): wait again; one that ended at once waits for the field's focus.
        if (Date.now() - started > 5000) autofill();
      });
    });
  };
  button.addEventListener('click', () => {
    button.disabled = true;
    signIn('optional').catch(say).finally(() => {
      button.disabled = false;
      autofill();
    });
  });
  const field = document.querySelector('input[autocomplete~="webauthn"]');
  field?.addEventListener('focus', autofill);
  // Back from a password manager app (iPhone): the browser may have ended the request meanwhile.
  document.addEventListener('visibilitychange', () => document.visibilityState === 'visible' && autofill());
  autofill();
  // ClientFence: no autofocus while passkeys are on: an iPhone shows a focused field's waiting request as a sheet on load
  // that does nothing (GitHub #55); other devices get it focused unless the visitor is already in a field.
  if (field && document.activeElement === document.body && !window.matchMedia('(pointer: coarse)').matches) field.focus();
}

function initList(root) {
  const form = root.querySelector('form[data-cf-passkey-add]');
  form?.addEventListener('submit', (event) => {
    event.preventDefault();
    const button = form.querySelector('button[type="submit"]');
    button.disabled = true;
    create(root, form.elements.password.value)
      .then(() => {
        window.ClientFence?.message?.(root.dataset.saved, 'success');
        window.location.reload();
      })
      .catch((error) => {
        button.disabled = false;
        say(error);
      });
  });
}

async function initOffer(card) {
  // Only where this device unlocks passkeys itself (fingerprint, face, PIN).
  const available = await window.PublicKeyCredential?.isUserVerifyingPlatformAuthenticatorAvailable?.().catch(() => false);
  if (!available) return;
  card.hidden = false;
  card.querySelector('[data-cf-passkey-later]').addEventListener('click', () => {
    document.cookie = `${card.dataset.cookie}=1; max-age=31536000; path=/; SameSite=Lax${window.location.protocol === 'https:' ? '; Secure' : ''}`;
    card.hidden = true;
  });
  const button = card.querySelector('[data-cf-passkey-create]');
  button.addEventListener('click', () => {
    button.disabled = true;
    create(card)
      .then(() => {
        card.hidden = true;
        window.ClientFence?.message?.(card.dataset.saved, 'success');
      })
      .catch((error) => {
        button.disabled = false;
        say(error);
      });
  });
}

document.querySelectorAll('[data-cf-passkey]').forEach((el) => {
  ({ signin: initSignIn, list: initList, offer: initOffer })[el.dataset.cfPasskey]?.(el);
});
