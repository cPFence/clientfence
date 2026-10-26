"""The install test's checks, run against a ClientFence site (tools/install-test.sh).

    python3 -I tools/install-test/smoke.py SITE_URL ADMIN_EMAIL ADMIN_PASSWORD API_TOKEN [TAG]

Staff sign in with the password and see the dashboard (after skipping a new install's setup
wizard); through the admin API (the token) a
product and a client are added; the client signs in, orders the product and the invoice is paid
with the manual gateway (staff mark it paid), which activates the order; the client opens a
ticket and staff see it. TAG keeps the records of a second run apart (the upgrade test runs
this before and after the update). Exits non-zero at the first failed check.
"""
import base64
import http.cookiejar
import json
import sys
import urllib.error
import urllib.parse
import urllib.request

SITE, ADMIN_EMAIL, ADMIN_PASSWORD, TOKEN = sys.argv[1:5]
TAG = sys.argv[5] if len(sys.argv) > 5 else 'a'
SITE = SITE.rstrip('/') + '/'


def fail(message):
    print(f'FAILED: {message}')
    sys.exit(1)


def check(title, ok, detail=''):
    if not ok:
        fail(f'{title} {detail}'.strip())
    print(f'ok  {title}')


class Browser:
    """A cookie session, as a browser has (the client area and the staff area)."""

    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))

    def get(self, path):
        try:
            with self.opener.open(SITE + path.lstrip('/'), timeout=60) as response:
                return response.status, response.read().decode('utf-8', 'replace')
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode('utf-8', 'replace')

    def cookie(self, name):
        return next((c.value for c in self.jar if c.name == name), None)

    def api(self, call, data=None):
        headers = {'Content-Type': 'application/json', 'Accept': 'application/json'}
        csrf = self.cookie('clientfence_csrf')
        if csrf:
            headers['X-CSRF-TOKEN'] = urllib.parse.unquote(csrf)
        request = urllib.request.Request(SITE + 'api/' + call, data=json.dumps(data or {}).encode(), method='POST', headers=headers)
        return result(self.opener, request, call)


def result(opener, request, call):
    try:
        with opener.open(request, timeout=120) as response:
            payload = json.load(response)
    except urllib.error.HTTPError as e:
        payload = json.loads(e.read() or b'{}')
    if payload.get('error'):
        fail(f"{call}: {payload['error'].get('message')}")
    return payload.get('result')


AUTH = 'Basic ' + base64.b64encode(f'admin:{TOKEN}'.encode()).decode()


def admin(call, data=None):
    request = urllib.request.Request(SITE + 'api/admin/' + call, data=json.dumps(data or {}).encode(), method='POST', headers={
        'Authorization': AUTH, 'Content-Type': 'application/json', 'Accept': 'application/json',
    })
    return result(urllib.request.build_opener(), request, 'admin/' + call)


# Public pages.
guest = Browser()
for path in ['', 'login', 'order', 'admin/staff/login']:
    status, _ = guest.get(path)
    check(f'GET /{path} answers 200', status == 200, f'(got {status})')

# Staff sign in with their password and reach the dashboard.
staff = Browser()
staff.get('admin/staff/login')
staff.api('guest/staff/login', {'email': ADMIN_EMAIL, 'password': ADMIN_PASSWORD})
status, page = staff.get('admin')
# A new install opens the setup wizard first (Clientfence Setup.php); skipping it shows the dashboard.
if 'data-cf-setup' in page:
    check('a new install opens the setup wizard', status == 200)
    staff.api('admin/clientfence/setup_finish')
    status, page = staff.get('admin')
check('staff sign in and see the dashboard', status == 200 and 'staff/login' not in page[:5000] and 'Dashboard' in page, f'(status {status})')

# A product with a monthly price.
categories = admin('product/category_get_pairs') or {}
category_id = next(iter(categories), None) or admin('product/category_create', {'title': 'Services'})
product_id = admin('product/prepare', {'title': f'Install test {TAG}', 'type': 'custom', 'product_category_id': category_id})
admin('product/update', {'id': product_id, 'status': 'enabled', 'pricing': {
    'type': 'recurrent', 'recurrent': {'1M': {'price': 10, 'setup': 0, 'enabled': 1}},
}})
check('staff add a product', bool(product_id))

# A client with a password.
email = f'install-test-{TAG}@clientfence.com'
client_password = 'Test-Client-1a!'
client_id = admin('client/create', {
    'email': email, 'first_name': 'Install', 'last_name': 'Test', 'password': client_password,
    'country': 'US', 'phone_cc': '1', 'phone': '5550100', 'address_1': '1 Test Street',
    'city': 'Testville', 'postcode': '10001', 'state': 'NY',
})
check('staff add a client', bool(client_id))

# The client signs in, orders and gets an invoice.
client = Browser()
client.get('login')
client.api('guest/client/login', {'email': email, 'password': client_password})
client.get('dashboard')
me = client.api('client/profile/get')
check('the client signs in', bool(me) and me.get('email') == email)
manual = next((g for g in admin('invoice/gateway_get_list', {'per_page': 100})['list'] if g['code'] == 'Custom'), None)
check('the manual gateway is installed', manual is not None)
admin('invoice/gateway_update', {'id': manual['id'], 'enabled': 1})
# A new install's store is closed until staff open it (Orders settings).
admin('clientfence/store_update', {'store_mode': 'public'})
client.api('guest/cart/reset')
client.api('guest/cart/add_item', {'id': product_id, 'period': '1M'})
order = client.api('client/cart/checkout', {'gateway_id': manual['id']})
check('the client orders the product', bool(order and order.get('invoice_hash')), f'({order})')

# Paid with the manual gateway: staff mark the invoice paid, which activates the order.
invoices = [i for i in admin('invoice/get_list', {'client_id': client_id, 'per_page': 10})['list'] if i['hash'] == order['invoice_hash']]
check('the order has its invoice', len(invoices) == 1)
invoice = invoices[0]
admin('invoice/mark_as_paid', {'id': invoice['id'], 'gateway_id': manual['id'], 'transactionId': f'INSTALL-TEST-{TAG}', 'execute': True})
check('the invoice is paid', admin('invoice/get', {'id': invoice['id']})['status'] == 'paid')
orders = admin('order/get_list', {'client_id': client_id, 'per_page': 10})['list']
check('the order is active', bool(orders) and orders[0]['status'] == 'active', f"({[o['status'] for o in orders]})")

# The client opens a ticket and staff see it.
desks = client.api('client/support/helpdesk_get_pairs') or {}
ticket_id = client.api('client/support/ticket_create', {
    'support_helpdesk_id': next(iter(desks)), 'subject': f'Install test {TAG}', 'content': 'Hello from the install test.',
})
ticket = admin('support/ticket_get', {'id': ticket_id})
check('the client opens a ticket and staff see it', ticket['subject'] == f'Install test {TAG}')
status, page = staff.get(f'admin/support/ticket/{ticket_id}')
check('staff open the ticket page', status == 200 and 'Hello from the install test.' in page, f'(status {status})')

print('install test checks passed')
