"""Test data for the ClientFence local test site, added through the admin API.

    python3 -I dev/seed.py dev/.env

Each record is looked up first, so running it again only adds what is missing; the base
settings (company, mail, store, the Support group's permissions, product prices) are set again
every time. Every
email the site sends goes to the Mailpit inbox; hosting and domains use the "Custom" server
manager and registrar, which call nothing outside.
"""
import base64
import json
import sys
import urllib.error
import urllib.request


def read_env(path):
    env = {}
    with open(path) as f:
        for line in f:
            line = line.strip()
            if line and not line.startswith('#') and '=' in line:
                key, value = line.split('=', 1)
                env[key] = value
    return env


ENV = read_env(sys.argv[1])
BASE = ENV['SITE_URL'].rstrip('/') + '/api/admin/'
AUTH = 'Basic ' + base64.b64encode(f"admin:{ENV['ADMIN_API_TOKEN']}".encode()).decode()


class ApiError(Exception):
    pass


def api(call, data=None):
    body = json.dumps(data or {}).encode()
    request = urllib.request.Request(BASE + call, data=body, method='POST', headers={
        'Authorization': AUTH,
        'Content-Type': 'application/json',
        'Accept': 'application/json',
    })
    try:
        with urllib.request.urlopen(request, timeout=60) as response:
            payload = json.load(response)
    except urllib.error.HTTPError as e:
        payload = json.loads(e.read() or b'{}')
    if payload.get('error'):
        raise ApiError(f"{call}: {payload['error'].get('message')}")
    return payload.get('result')


def rows(call, data=None):
    result = api(call, {'per_page': 100, **(data or {})})
    return result.get('list', []) if isinstance(result, dict) else (result or [])


def find(call, key, value, data=None):
    for row in rows(call, data):
        if str(row.get(key)) == str(value):
            return row
    return None


def step(title):
    print(f'== {title}')


step('company, email and store')
api('system/update_params', {
    'company_name': 'ClientFence Test',
    'company_email': f"test-billing@{ENV['TEST_EMAIL_DOMAIN']}",
})
api('extension/config_save', {
    'ext': 'mod_email',
    'mailer': 'custom',
    'custom_dsn': 'smtp://mail:1025',
})
api('clientfence/store_update', {'store_mode': 'public'})

step('payment gateway')
gateway_id = None
for gateway in rows('invoice/gateway_get_list'):
    if gateway.get('code') == 'Custom':
        gateway_id = gateway['id']
    if gateway.get('code') == 'Custom' and not gateway.get('enabled'):
        api('invoice/gateway_update', {
            'id': gateway['id'],
            'title': 'Bank transfer',
            'enabled': 1,
            'allow_single': 1,
            'allow_recurrent': 0,
            'config': {'single': 'Pay by bank transfer to the test account.'},
        })

step('staff')
group = find('staff/group_get_list', 'name', 'Support')
group_id = group['id'] if group else api('staff/group_create', {'name': 'Support'})
# The help desk and reading clients, nothing else.
api('staff/group_update', {'id': group_id, 'permissions': {
    'support': {'access': True, 'view': True, 'manage_tickets': True},
    'client': {'access': True, 'view': True},
}})
if not find('staff/get_list', 'email', ENV['STAFF_EMAIL']):
    api('staff/create', {
        'email': ENV['STAFF_EMAIL'],
        'password': ENV['STAFF_PASSWORD'],
        'name': 'Support Agent',
        'group_id': group_id,
        'status': 'active',
    })

step('clients')
pairs = api('client/group_get_pairs') or {}
client_group_id = next((gid for gid, title in pairs.items() if title == 'Testers'), None) \
    or api('client/group_create', {'title': 'Testers'})
clients = {}
for first, last, country in [('Alice', 'Hosting', 'US'), ('Bob', 'Billing', 'EG'), ('Carol', 'Support', 'GB')]:
    email = f"test-{first.lower()}@{ENV['TEST_EMAIL_DOMAIN']}"
    row = find('client/get_list', 'email', email, {'search': email})
    clients[first] = row['id'] if row else api('client/create', {
        'email': email,
        'first_name': first,
        'last_name': last,
        'password': ENV['CLIENT_PASSWORD'],
        'country': country,
        'group_id': client_group_id,
        'company': f'{first} Test Co',
        'phone_cc': '1',
        'phone': '5550100',
        'address_1': '1 Test Street',
        'city': 'Testville',
        'postcode': '10001',
    })

step('hosting server and plan')
server = find('servicehosting/server_get_list', 'name', 'Test server')
server_id = server['id'] if server else api('servicehosting/server_create', {
    'name': 'Test server',
    'ip': '192.0.2.10',
    'hostname': 'server1.clientfence.test',
    'manager': 'Custom',
    'active': 1,
})
plan = find('servicehosting/hp_get_list', 'name', 'Starter')
plan_id = plan['id'] if plan else api('servicehosting/hp_create', {'name': 'Starter'})

step('products')
categories = api('product/category_get_pairs') or {}
category_id = next((cid for cid, title in categories.items() if title == 'Hosting'), None) \
    or api('product/category_create', {'title': 'Hosting'})


def product(title, kind, monthly, yearly, config=None):
    row = find('product/get_list', 'title', title)
    product_id = row['id'] if row else api('product/prepare', {
        'title': title, 'type': kind, 'product_category_id': category_id,
    })
    # Set every time, so a product left half made by a failed run is completed.
    api('product/update', {
        'id': product_id,
        'status': 'enabled',
        'pricing': {
            'type': 'recurrent',
            'recurrent': {
                '1M': {'price': monthly, 'setup': 0, 'enabled': 1},
                '1Y': {'price': yearly, 'setup': 0, 'enabled': 1},
            },
        },
    })
    if config:
        api('product/update_config', {'id': product_id, 'config': config})
    return product_id


hosting_id = product('Starter Hosting', 'hosting', 5, 50, {'server_id': server_id, 'hosting_plan_id': plan_id})
care_id = product('Website Care', 'custom', 20, 200)

step('domain extension')
registrar = find('servicedomain/registrar_get_list', 'title', 'Custom')
if registrar and not find('servicedomain/tld_get_list', 'tld', '.test'):
    api('servicedomain/tld_create', {
        'tld': '.test',
        'tld_registrar_id': registrar['id'],
        'price_registration': 10,
        'price_renew': 10,
        'price_transfer': 10,
        'min_years': 1,
        'allow_register': 1,
        'allow_transfer': 1,
        'active': 1,
    })

step('orders and invoices')
if not rows('order/get_list', {'client_id': clients['Alice']}):
    api('order/create', {
        'client_id': clients['Alice'],
        'product_id': hosting_id,
        'period': '1M',
        'config': {'domain': {'action': 'owndomain', 'owndomain_sld': 'alice', 'owndomain_tld': '.test'}},
        'invoice_option': 'issue-invoice',
        'mark_invoice_paid': True,
        'gateway_id': gateway_id,
        'transactionId': 'TEST-0001',
        'activate': True,
    })
if not rows('order/get_list', {'client_id': clients['Bob']}):
    api('order/create', {
        'client_id': clients['Bob'],
        'product_id': care_id,
        'period': '1M',
        'invoice_option': 'issue-invoice',
    })

step('support')
desks = rows('support/helpdesk_get_list')
if desks and not rows('support/ticket_get_list', {'client_id': clients['Carol']}):
    api('support/ticket_create', {
        'client_id': clients['Carol'],
        'support_helpdesk_id': desks[0]['id'],
        'subject': 'Test ticket',
        'content': 'A first ticket on the test site.',
    })

print('seed done')
