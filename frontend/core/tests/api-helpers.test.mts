import assert from 'node:assert/strict';
import { describe, test } from 'node:test';

import {
  isEditEvent,
  claimSignInReload,
  clientSignInUrl,
  isSignedOut,
  buildHeaders,
  buildRequestBody,
  injectCSRFToken,
  interpretResponse,
  normalizeApiError,
  parseResponseBody,
  validateHttpResponse,
} from '../api-helpers.mts';

describe('API request helpers', () => {
  test('injects a missing CSRF token without replacing an existing one', () => {
    const params: Record<string, unknown> = {};
    assert.equal(injectCSRFToken(params, 'token'), params);
    assert.equal(params.CSRFToken, 'token');

    const existing = { CSRFToken: 'existing' };
    injectCSRFToken(existing, 'token');
    assert.equal(existing.CSRFToken, 'existing');

    const form = new FormData();
    injectCSRFToken(form, 'token');
    assert.equal(form.get('CSRFToken'), 'token');
  });

  test('puts GET parameters in the URL', () => {
    const url = new URL('https://example.test/api');
    assert.deepEqual(buildRequestBody('GET', { id: 1, CSRFToken: 'secret' }, url), { body: null, isFormData: false });
    assert.equal(url.searchParams.get('id'), '1');
    assert.equal(url.searchParams.has('CSRFToken'), false);

    const form = new FormData();
    form.append('name', 'Ada');
    form.append('CSRFToken', 'secret');
    const formUrl = new URL('https://example.test/api');
    assert.equal(buildRequestBody('GET', form, formUrl).body, null);
    assert.equal(formUrl.searchParams.get('name'), 'Ada');
    assert.equal(formUrl.searchParams.has('CSRFToken'), false);

    const stringUrl = new URL('https://example.test/api');
    buildRequestBody('GET', 'name=Ada&CSRFToken=secret', stringUrl);
    assert.equal(stringUrl.search, '?name=Ada');
  });

  test('builds mutation request bodies', () => {
    const url = new URL('https://example.test/api');
    assert.equal(buildRequestBody('POST', { id: 1 }, url).body, '{"id":1}');
    assert.equal(buildRequestBody('PATCH', 'raw', url).body, 'raw');

    const form = new FormData();
    assert.equal(buildRequestBody('DELETE', form, url).body, form);
  });

  test('builds same-origin JSON headers', () => {
    const url = new URL('https://example.test/api');
    assert.deepEqual(buildHeaders({
      url,
      body: '{}',
      isFormData: false,
      csrfToken: 'token',
      origin: url.origin,
    }), {
      Accept: 'application/json',
      'X-CSRF-Token': 'token',
      'X-Requested-With': 'XMLHttpRequest',
      'Content-Type': 'application/json',
    });

    const crossOrigin = buildHeaders({
      url,
      body: new FormData(),
      isFormData: true,
      csrfToken: null,
      origin: 'https://other.test',
    });
    assert.equal(crossOrigin['X-Requested-With'], undefined);
    assert.equal(crossOrigin['X-CSRF-Token'], undefined);
    assert.equal(crossOrigin['Content-Type'], undefined);
  });
});

describe('API response helpers', () => {
  test('parses JSON, empty, and invalid response bodies', async () => {
    assert.deepEqual(await parseResponseBody({ status: 204 }), { payload: null, rawText: '' });
    assert.deepEqual(
      await parseResponseBody({ status: 200, text: async () => '{"result":1}' }),
      { payload: { result: 1 }, rawText: '{"result":1}' },
    );
    assert.deepEqual(
      await parseResponseBody({ status: 200, text: async () => '<html>' }),
      { payload: null, rawText: '<html>' },
    );
  });

  test('validates HTTP responses', () => {
    const parsed = { payload: { result: 1 }, rawText: '{"result":1}' };
    assert.equal(validateHttpResponse({ ok: true }, parsed), parsed.payload);
    assert.throws(
      () => validateHttpResponse(
        { ok: false, status: 422, statusText: 'Invalid' },
        { payload: { error: { message: 'Bad input', code: 'bad_input' } }, rawText: 'body' },
      ),
      (error: any) => error.message === 'Bad input' && error.code === 'bad_input' && error.status === 422,
    );
    assert.throws(
      () => validateHttpResponse({ ok: true }, { payload: null, rawText: '<html>' }),
      /non-JSON response/,
    );
  });

  test('returns results and throws API errors', () => {
    assert.equal(interpretResponse(null), null);
    assert.equal(interpretResponse({ result: 0 }), 0);
    assert.throws(
      () => interpretResponse({ error: { message: 'Denied', code: 'denied' } }),
      (error: any) => error.message === 'Denied' && error.code === 'denied',
    );
  });

  test('normalizes timeout, network, and generic errors', () => {
    assert.deepEqual(
      normalizeApiError({ name: 'AbortError' }, { timeoutMs: 5000 }),
      { message: 'Request timed out after 5 seconds', code: 'timeout_error' },
    );
    assert.deepEqual(
      normalizeApiError({ name: 'TypeError', message: 'NetworkError failed' }, {}),
      { message: 'Network connection error', code: 'network_error' },
    );
    assert.deepEqual(
      normalizeApiError({ name: 'TypeError', message: 'Failed to fetch' }, {}),
      { message: 'Network connection error', code: 'network_error' },
    );
    assert.deepEqual(
      normalizeApiError({ name: 'TypeError', message: 'Load failed' }, {}),
      { message: 'Network connection error', code: 'network_error' },
    );
    assert.deepEqual(
      normalizeApiError({ message: 'Denied', code: 'denied' }, {}),
      { message: 'Denied', code: 'denied' },
    );
  });

  test('a session that ran out is told apart from other errors', () => {
    const origin = 'https://billing.example.test';
    assert.equal(isSignedOut({ code: 201 }, new URL(`${origin}/api/client/invoice/get`), origin), true);
    assert.equal(isSignedOut({ code: '201' }, new URL(`${origin}/sub/api/admin/invoice/get`), origin), true);
    assert.equal(isSignedOut({ code: 205 }, new URL(`${origin}/api/admin/invoice/get`), origin), false);
    assert.equal(isSignedOut({ code: 201 }, new URL(`${origin}/api/guest/cart/get`), origin), false);
    assert.equal(isSignedOut({ code: 201 }, new URL('https://other.example.test/api/client/x/y'), origin), false);
  });

  test('the page reloads for the sign-in at most once in 30 seconds', () => {
    const store = new Map<string, string>();
    const storage = { getItem: (k: string) => store.get(k) ?? null, setItem: (k: string, v: string) => { store.set(k, v); } };
    assert.equal(claimSignInReload(storage, 100000), true);
    assert.equal(claimSignInReload(storage, 120000), false);
    assert.equal(claimSignInReload(storage, 131000), true);
    assert.equal(claimSignInReload(null, 100000), false);
    assert.equal(claimSignInReload({ getItem: () => { throw new Error('blocked'); }, setItem: () => {} }, 100000), false);
  });

  test('a rich editor\'s paste, drop, cut or Enter counts as an edit; moving around does not', () => {
    for (const type of ['input', 'beforeinput', 'change', 'paste', 'drop', 'cut']) {
      assert.equal(isEditEvent(type), true, type);
    }
    assert.equal(isEditEvent('keydown', 'a'), true);
    assert.equal(isEditEvent('keydown', 'Enter'), true);
    assert.equal(isEditEvent('keydown', 'Backspace'), true);
    assert.equal(isEditEvent('keydown', 'Tab'), false);
    assert.equal(isEditEvent('keydown', 'ArrowDown'), false);
    assert.equal(isEditEvent('keydown', 'c', true), false);
    assert.equal(isEditEvent('click'), false);
  });

  test('the client sign-in page sits next to the client API', () => {
    assert.equal(clientSignInUrl(new URL('https://b.example.test/api/client/invoice/get')), 'https://b.example.test/login');
    assert.equal(clientSignInUrl(new URL('https://b.example.test/billing/api/client/x/y')), 'https://b.example.test/billing/login');
    assert.equal(clientSignInUrl(new URL('https://b.example.test/api/admin/x/y')), null);
  });
});
