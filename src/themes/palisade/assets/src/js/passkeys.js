/**
 * Passkeys (ClientFence, Client/Passkeys.php), drawn only while they are on:
 * - [data-clientfence-passkey="signin"]: the sign-in page's button, and the passkey list of the email
 *   field's autofill (autocomplete="username webauthn") where the browser has one;
 * - [data-clientfence-passkey="list"]: the two-factor page's "Add a passkey" form (with the password);
 * - [data-clientfence-passkey="offer"]: "Sign in faster next time" after a password sign-in, shown only
 *   where the device has a fingerprint, face or PIN unlock. "Not now" is a cookie for a year.
 * Keys, challenges and ids travel as base64url (no padding).
 */
import { api, errorText } from './store-utils.js';

const bytes = (text) => Uint8Array.from(atob(text.replace(/-/g, '+').replace(/_/g, '/')), (c) => c.charCodeAt(0));
const text = (buffer) => btoa(String.fromCharCode(...new Uint8Array(buffer))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
// The browser's own "cancelled" or "aborted": nothing to say.
const quiet = (error) => error && (error.name === 'NotAllowedError' || error.name === 'AbortError');

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
    if (error && error.name === 'InvalidStateError') {
      throw new Error(root.dataset.already);
    }
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
  if (!window.PublicKeyCredential) {
    return;
  }
  button.hidden = false;
  // The pending request's (the autofill's or the button's); null while none is pending.
  let controller = null;
  const signIn = async (mediation) => {
    controller?.abort();
    const own = new AbortController();
    controller = own;
    try {
      const o = await api('guest', button.dataset.options);
      if (mediation === 'conditional') {
        // Some browsers keep an autofill request past its timeout: end it with its challenge, then wait again.
        setTimeout(() => own.abort(), o.timeout);
      }
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
      if (controller === own) {
        controller = null;
      }
    }
  };
  const failed = (error) => {
    if (!quiet(error)) {
      ClientFence.message(errorText(error), 'error');
    }
  };
  // The email field's passkey list works only while a conditional request waits: keep one waiting.
  const autofill = () => {
    if (controller) {
      return;
    }
    PublicKeyCredential.isConditionalMediationAvailable?.().then((available) => {
      if (!available || controller) {
        return;
      }
      const started = Date.now();
      signIn('conditional').catch((error) => {
        failed(error);
        // Ended (timeout, the browser's abort): wait again; one that ended at once waits for the field's focus.
        if (Date.now() - started > 5000) {
          autofill();
        }
      });
    });
  };
  button.addEventListener('click', () => {
    button.disabled = true;
    signIn('optional').catch(failed).finally(() => {
      button.disabled = false;
      autofill();
    });
  });
  document.querySelector('input[autocomplete~="webauthn"]')?.addEventListener('focus', autofill);
  // Back from a password manager app (iPhone): the browser may have ended the request meanwhile.
  document.addEventListener('visibilitychange', () => document.visibilityState === 'visible' && autofill());
  autofill();
}

function initList(root) {
  const form = root.querySelector('form[data-clientfence-passkey-add]');
  form?.addEventListener('submit', (event) => {
    event.preventDefault();
    const button = form.querySelector('button[type="submit"]');
    button.disabled = true;
    create(root, form.elements.password.value)
      .then(() => {
        ClientFence.message(root.dataset.saved, 'success');
        window.location.reload();
      })
      .catch((error) => {
        button.disabled = false;
        if (!quiet(error)) {
          ClientFence.message(errorText(error), 'error');
        }
      });
  });
}

async function initOffer(card) {
  // Only where this device unlocks passkeys itself (fingerprint, face, PIN).
  const available = await window.PublicKeyCredential?.isUserVerifyingPlatformAuthenticatorAvailable?.().catch(() => false);
  if (!available) {
    return;
  }
  card.hidden = false;
  card.querySelector('[data-clientfence-passkey-later]').addEventListener('click', () => {
    document.cookie = `${card.dataset.cookie}=1; max-age=31536000; path=/; SameSite=Lax${window.location.protocol === 'https:' ? '; Secure' : ''}`;
    card.hidden = true;
  });
  const button = card.querySelector('[data-clientfence-passkey-create]');
  button.addEventListener('click', () => {
    button.disabled = true;
    create(card)
      .then(() => {
        card.hidden = true;
        ClientFence.message(card.dataset.saved, 'success');
      })
      .catch((error) => {
        button.disabled = false;
        if (!quiet(error)) {
          ClientFence.message(errorText(error), 'error');
        }
      });
  });
}

export default function initPasskeys() {
  document.querySelectorAll('[data-clientfence-passkey]').forEach((el) => {
    ({ signin: initSignIn, list: initList, offer: initOffer })[el.dataset.clientfencePasskey]?.(el);
  });
}
