"""Shop-level helpers: logins (with TOTP for staff), cart and checkout as a
real browser does them, payments through the Shkeeper mock, and the data
invariants checked after every scenario."""
import base64
import hashlib
import hmac
import os
import re
import struct
import time
import uuid

from . import env
from .http import Client, Fail, forms_of, text_of

PASSWORD = 'Sim-Password-2026'
_used_steps = {}


def staff_secrets():
    src = open(os.path.join(env.ROOT, 'database', 'seeders', 'SimulationSeeder.php'), encoding='utf-8').read()
    return dict(re.findall(r"'([a-z]+@sim\.test)' => \['role' => '[a-z]+', 'name' => '[^']*', 'secret' => '([A-Z2-7]+)'\]", src))


def totp(secret, step=None):
    key = base64.b32decode(secret + '=' * (-len(secret) % 8))
    step = int(time.time() // 30) if step is None else step
    h = hmac.new(key, struct.pack('>Q', step), hashlib.sha1).digest()
    o = h[-1] & 15
    return '%06d' % ((struct.unpack('>I', h[o:o + 4])[0] & 0x7fffffff) % 1000000)


def fresh_totp(secret):
    """TOTP codes are single use per 30 s step; wait for a new step when needed."""
    while _used_steps.get(secret) == int(time.time() // 30):
        time.sleep(0.5)
    _used_steps[secret] = int(time.time() // 30)
    return totp(secret)


def login(email, password=PASSWORD, who=None, secret=None):
    c = Client(who or email.split('@')[0])
    r = c.form('/login', '/login', {'email': email, 'password': password})
    if '/two-factor-challenge' in r.url:
        if secret is None:
            secret = staff_secrets().get(email)
        if secret is None:
            raise Fail(f'{email} needs a 2FA code')
        r = c.form('/two-factor-challenge', '/two-factor-challenge', {'code': fresh_totp(secret)})
    if '/login' in r.path or '/two-factor-challenge' in r.path:
        raise Fail(f'login failed for {email}: {r.flashes()}')
    return c


def confirm_password(c, password=PASSWORD):
    c.form('/confirm-password', '/confirm-password', {'password': password})


def product(where="status='active'", order='id'):
    row = env.sql(f"select id, slug, price_minor, currency, seller_id, title from products where {where} order by {order} limit 1")
    if not row:
        raise Fail(f'no product matching {where}')
    pid, slug, price, cur, seller, title = row[0]
    return {'id': int(pid), 'slug': slug, 'price': int(price), 'currency': cur, 'seller_id': int(seller), 'title': title}


def add_to_cart(c, prod, qty=1):
    return c.form(f"/products/{prod['slug']}", '/cart/items', {'quantity': str(qty)})


def empty_cart(c):
    page = c.get('/cart')
    while True:
        forms = [f for f in forms_of(page.text)
                 if '/cart/items/' in f['action'] and ['_method', 'DELETE'] in f['fields']]
        if not forms:
            return
        page = c.submit(forms[0])


def checkout(c, method='crypto', crypto='BTC', key=None, follow=True):
    """Submits the checkout form; returns (response, order public id or None)."""
    page = c.get('/checkout')
    form = c.find_form(page, '/checkout')
    fields = {'payment_method': method, 'accept_terms': '1'}
    if method == 'crypto':
        fields['crypto'] = crypto
    if key:
        fields['idempotency_key'] = key
    r = c.submit(form, fields, follow=follow)
    m = re.search(r'/orders/([0-9a-f-]{36})', r.url + ' ' + (r.headers.get('Location') or ''))
    return r, (m.group(1) if m else None)


def pay(order_id, amount=None, **extra):
    body = dict(extra)
    if amount is not None:
        body['amount'] = amount
    return env.mock(f'/__mock/pay/{order_id}', body)


def order_row(order_id):
    row = env.sql(f"select id, status, total_minor, refunded_minor, currency, buyer_id from orders where public_id='{order_id}'")
    if not row:
        return None
    i, s, t, r, c, b = row[0]
    return {'id': int(i), 'status': s, 'total': int(t), 'refunded': int(r), 'currency': c, 'buyer_id': int(b)}


def wait_for(fn, what, timeout=60, interval=0.5):
    end = time.time() + timeout
    last = None
    while time.time() < end:
        last = fn()
        if last:
            return last
        time.sleep(interval)
    raise AssertionError(f'timed out after {timeout}s waiting for {what} (last value {last!r})')


def new_key():
    return str(uuid.uuid4())


# ---- invariants -----------------------------------------------------------

INVARIANTS = {
    'paid orders have a confirmed charge covering the total': """
        select o.public_id from orders o where o.status in ('paid','delivered','partially_refunded','refunded') and o.total_minor > 0
        and coalesce((select sum(p.received_minor) from payments p where p.order_id=o.id and p.kind='charge' and p.status='confirmed'),0) < o.total_minor""",
    'order total equals subtotal minus discount': "select public_id from orders where total_minor <> subtotal_minor - discount_minor",
    'line amounts add up to the order subtotal': """
        select o.public_id from orders o where o.subtotal_minor <> (select coalesce(sum(i.unit_price_minor*i.quantity),0) from order_items i where i.order_id=o.id)""",
    'refunds never exceed the total': "select public_id from orders where refunded_minor > total_minor or refunded_minor < 0",
    'item refunds add up to the order refund': """
        select o.public_id from orders o where o.refunded_minor <> (select coalesce(sum(i.refunded_minor),0) from order_items i where i.order_id=o.id)""",
    'every sold line has exactly one sale ledger entry': """
        select i.id from order_items i join orders o on o.id=i.order_id where o.status in ('paid','delivered','partially_refunded','refunded')
        and (select count(*) from seller_ledger_entries l where l.order_item_id=i.id and l.type='sale') <> 1""",
    'unpaid orders have no ledger entries': """
        select i.id from order_items i join orders o on o.id=i.order_id where o.status in ('pending','cancelled','expired')
        and exists (select 1 from seller_ledger_entries l where l.order_item_id=i.id)""",
    'seller earnings match the ledger per line': """
        select i.id from order_items i where i.seller_earning_minor <> (select coalesce(sum(l.amount_minor),0) from seller_ledger_entries l where l.order_item_id=i.id and l.type in ('sale','commission','refund_adjustment'))
        and exists (select 1 from seller_ledger_entries l where l.order_item_id=i.id)""",
    'no negative stock or balances': "select 'product '||id from products where stock < 0 union all select 'user '||id from users where balance_minor < 0",
    'balance history matches balances': """
        select u.id from users u where u.balance_minor <> coalesce((select b.balance_after_minor from balance_transactions b where b.user_id=u.id order by b.id desc limit 1), u.balance_minor)""",
    'licence keys are assigned to at most one line and only to paid lines': """
        select k.id from product_license_keys k join order_items i on i.id=k.order_item_id join orders o on o.id=i.order_id
        where o.status not in ('paid','delivered','partially_refunded','refunded')""",
    'delivered lines belong to paid orders': """
        select i.id from order_items i join orders o on o.id=i.order_id where i.delivered_at is not null and o.status not in ('paid','delivered','partially_refunded','refunded')""",
    'no duplicate charges per order': """
        select order_id from payments where kind='charge' and status='confirmed' group by order_id having count(*) > 1""",
    'no orphan rows': """
        select 'item '||id from order_items i where not exists (select 1 from orders o where o.id=i.order_id)
        union all select 'payment '||id from payments p where not exists (select 1 from orders o where o.id=p.order_id)
        union all select 'ledger '||id from seller_ledger_entries l where l.order_item_id is not null and not exists (select 1 from order_items i where i.id=l.order_item_id)""",
    'one paid-order email per order at most': None,  # checked from Mailpit in the phases
}


def check_invariants():
    """Returns a list of violated invariants with sample rows."""
    problems = []
    for name, query in INVARIANTS.items():
        if query is None:
            continue
        rows = env.sql(query)
        if rows:
            problems.append(f'{name}: {rows[:5]}')
    rc = env.artisan('shop:reconcile-payouts', check=False)
    if rc.returncode != 0:
        problems.append('shop:reconcile-payouts reported differences: ' + rc.stdout[-800:])
    return problems
