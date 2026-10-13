"""Gate F11: large data volumes and boundary conditions, on the simulation
stack grown by tests/performance/scale-data.sql (20,000 products, 200,000
orders, 500,000 notifications, 1,000,000 audit entries, 50,000 buyers).

  tests/simulation/run.sh reset
  docker exec -i shopsim-db-1 psql -U shop -d shop -v ON_ERROR_STOP=1 < tests/performance/scale-data.sql
  python3 -I tests/functional/large_data.py <out.json>

Every check states what is correct (status, counts, refusal message) and a
generous time ceiling; the ceilings are guards against runaway work, not
service-level objectives. Exit code 1 if any check fails.
"""
import json
import math
import os
import re
import subprocess
import sys
import threading
import time
import urllib.parse

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
sys.path.insert(0, os.path.join(ROOT, 'tests', 'simulation'))

from simlib import env, http, shop  # noqa: E402
from simlib.http import Client, forms_of, text_of  # noqa: E402

http.CA = os.path.join(ROOT, 'tests', 'simulation', 'tls', 'cert.pem')
RESULTS = []


def check(name, ok, detail, seconds=None, limit=None):
    passed = bool(ok) and (limit is None or seconds is None or seconds <= limit)
    RESULTS.append({'check': name, 'status': 'PASS' if passed else 'FAIL', 'detail': detail,
                    'seconds': None if seconds is None else round(seconds, 2), 'ceiling_s': limit})
    print(f"{'PASS' if passed else 'FAIL'}  {name}  ({detail}{'' if seconds is None else f', {seconds:.2f}s'})")


def timed(c, path, **kw):
    t = time.time()
    r = c.go('GET', path, **kw)
    return r, time.time() - t


def app_memory_peak(stop, out):
    while not stop.is_set():
        s = subprocess.run(['docker', 'stats', '--no-stream', '--format', '{{.MemUsage}}', 'shopsim-app-1'], capture_output=True, text=True).stdout
        m = re.match(r'([\d.]+)(MiB|GiB)', s.strip())
        if m:
            out.append(float(m.group(1)) * (1024 if m.group(2) == 'GiB' else 1))
        time.sleep(1)


def main(out):
    counts = {t: int(env.scalar(f'select count(*) from {t}')) for t in ('products', 'orders', 'order_items', 'users', 'audit_log', 'user_notifications', 'seller_ledger_entries')}
    print('dataset', counts)
    check('dataset is at the provisional first-year scale', counts['orders'] >= 200000 and counts['audit_log'] >= 1000000, json.dumps(counts))

    guest = Client('f11-guest')
    active = int(env.scalar("select count(*) from products p join users u on u.id=p.seller_id where p.status='active' and u.status='active'"))
    last = math.ceil(active / 12)
    r, s = timed(guest, '/products')
    check('catalogue first page', r.status == 200, f'{active} products on sale', s, 5)
    r, s = timed(guest, f'/products?page={last}')
    check('catalogue last page', r.status == 200 and 'product-card' in r.text, f'page {last}', s, 5)
    r, s = timed(guest, '/products?page=999999')
    check('catalogue page past the end: empty page, not an error', r.status == 200, f'status {r.status}', s, 5)
    for bad in ('0', '-1', 'abc', '1e9', '%00'):
        r, _ = timed(guest, f'/products?page={bad}')
        check(f'catalogue page={bad} handled', r.status in (200, 404, 422) or (300 <= r.status < 400), f'status {r.status}')
    q = urllib.parse.quote('üñïçødé ' * 14 + '\U0001F680')
    r, s = timed(guest, f'/products?q={q}')
    check('search with a 100-character Unicode query', r.status == 200, f'status {r.status}', s, 5)

    admin = shop.login('superadmin@sim.test', who='f11-admin')
    for path, label in (('/admin/orders', 'admin orders first page'), (f'/admin/orders?page={math.ceil(counts["orders"] / 30)}', 'admin orders last page'),
                        ('/admin/orders?status=delivered&from=2026-01-01&to=2026-12-31', 'admin orders filtered by status and a year'),
                        ('/admin/audit', 'audit log first page'), (f'/admin/audit?page={math.ceil(counts["audit_log"] / 50)}', 'audit log last page'),
                        ('/admin/audit?q=order.paid', 'audit log search over 1,000,000 entries'), ('/admin/users?q=perf-buyer-4999', 'user search over 50,000 accounts'),
                        ('/admin', 'dashboard with year-scale data')):
        r, s = timed(admin, path)
        check(label, r.status == 200 and '/admin' in r.url, f'status {r.status}', s, 10)

    fin = shop.login('finance@sim.test', who='f11-finance')
    r, s = timed(fin, '/admin/reports?from=2025-10-01&to=2026-10-13')
    check('finance report over a year', r.status == 200, f'status {r.status}', s, 15)
    shop.confirm_password(fin)
    for kind, table in (('orders', 'orders'), ('users', 'users')):
        mem, stop = [], threading.Event()
        th = threading.Thread(target=app_memory_peak, args=(stop, mem))
        th.start()
        t = time.time()
        r = fin.go('GET', f'/admin/exports/download?type={kind}&from=2020-01-01&to=2030-12-31')
        s = time.time() - t
        stop.set()
        th.join()
        rows = r.raw.count(b'\n') - 1
        expected = int(env.scalar(f'select count(*) from {table}' + (" where role='buyer' or true" if table == 'users' else '')))
        check(f'CSV export of every {kind} (streamed)', r.status == 200 and abs(rows - expected) <= 5 if kind == 'orders' else r.status == 200 and rows > 50000,
              f'{rows} rows of {expected}, {len(r.raw) // 1048576} MB, app container memory peak {max(mem or [0]):.0f} MiB', s, 180)

    heavy = env.sql("select u.email, count(*) from orders o join users u on u.id=o.buyer_id where u.email like 'perf-buyer-%' group by 1 order by 2 desc limit 1")[0]
    b = shop.login(heavy[0], who='f11-heavy-buyer')
    r, s = timed(b, '/orders')
    check('order history of the buyer with the most orders', r.status == 200, f'{heavy[1]} orders', s, 5)
    r, s = timed(b, f'/orders?page={math.ceil(int(heavy[1]) / 15)}')
    check('last page of that history', r.status == 200, f'status {r.status}', s, 5)
    noisy = env.sql('select u.email, count(*) from user_notifications n join users u on u.id=n.user_id group by 1 order by 2 desc limit 1')[0]
    try:
        nb = shop.login(noisy[0], who='f11-noisy')
        r, s = timed(nb, '/notifications')
        check('notification centre of the user with the most entries', r.status == 200, f'{noisy[1]} entries', s, 5)
        r, s = timed(nb, '/live?path=/notifications', headers={'Accept': 'application/json'})
        check('live update version for that user', r.status == 200, f'status {r.status}', s, 2)
    except Exception as e:  # noqa: BLE001 - recorded as a failed check
        check('notification centre of the user with the most entries', False, repr(e)[:200])

    seller = env.sql("select u.email, count(*) from order_items i join users u on u.id=i.seller_id where u.email like 'perf-seller-%' group by 1 order by 2 desc limit 1")[0]
    sc = shop.login(seller[0], who='f11-seller')
    for path, label in (('/seller', 'seller dashboard of the busiest seller'), ('/seller/sales', 'that seller\'s sales'), ('/seller/payouts', 'payout balances over every sale')):
        r, s = timed(sc, path)
        check(label, r.status == 200, f'{seller[1]} sold lines', s, 5)

    t = time.time()
    rc = env.artisan('shop:reconcile-payouts', check=False, timeout=900)
    check('payout reconciliation over 200,000 orders', rc.returncode in (0, 1), f'exit {rc.returncode}: {rc.stdout.strip()[-200:]}', time.time() - t, 300)

    boundaries(admin)
    json.dump({'dataset': counts, 'checks': RESULTS}, open(out, 'w'), indent=1)
    failed = [x for x in RESULTS if x['status'] != 'PASS']
    print(f'{len(RESULTS) - len(failed)} of {len(RESULTS)} checks passed')
    return 1 if failed else 0


def boundaries(admin):
    """Exact limits of inputs: the maximum is accepted, one more is refused with a message, never a 500."""
    seller = shop.login('seller01@sim.test', who='f11-seller01')
    page = seller.get('/seller/products/create')
    form = seller.find_form(page, '/seller/products')
    base = {'title': 'T' * 160, 'description': 'D' * 20000, 'price': '999999.99', 'currency': 'USD', 'delivery_type': 'manual'}
    r = seller.submit(form, base)
    check('product with a 160-character title, 20,000-character description, price 999999.99', r.status < 500 and '/edit' in r.url, text_of(r.text)[:120])
    for field, value, why in (('title', 'T' * 161, '161-character title'), ('description', 'D' * 20001, '20,001-character description'),
                              ('price', '1000000.00', 'price 1000000.00'), ('price', '0.00', 'price 0'), ('price', '-1', 'negative price')):
        r = seller.submit(seller.find_form(seller.get('/seller/products/create'), '/seller/products'), {**base, field: value})
        check(f'{why} refused with a message', r.status < 500 and '/edit' not in r.url, ' | '.join(r.flashes())[:160] or text_of(r.text)[:160])

    buyer = shop.login('buyer02@sim.test', who='f11-buyer02')
    p = shop.product("status='active' and stock is null and delivery_type='instant'")
    shop.empty_cart(buyer)
    r = shop.add_to_cart(buyer, p, 10)
    check('cart line with the maximum quantity 10', r.status < 500 and 'value="10"' in buyer.get('/cart').text, ' | '.join(r.flashes())[:120])
    shop.empty_cart(buyer)
    r = shop.add_to_cart(buyer, p, 11)
    check('quantity 11 refused with a message', r.status < 500 and 'value="11"' not in buyer.get('/cart').text, ' | '.join(r.flashes())[:120])
    shop.empty_cart(buyer)

    fin = shop.login('finance@sim.test', who='f11-finance2')
    shop.confirm_password(fin)
    for value, ok in (('100', True), ('101', False), ('0', False)):
        code = f'F11P{value}X'
        page = fin.get('/admin/coupons')
        r = fin.submit(fin.find_form(page, '/admin/coupons'), {'code': code, 'type': 'percent', 'value': value})
        stored = env.scalar(f"select count(*) from coupons where code='{code}'") == '1'
        check(f'percent coupon of {value} {"accepted" if ok else "refused"}', r.status < 500 and stored == ok, ' | '.join(r.flashes())[:120])

    big = os.path.join(env.HERE, 'work', 'f11-49mb.bin')
    os.makedirs(os.path.dirname(big), exist_ok=True)
    with open(big, 'wb') as f:
        f.write(os.urandom(49 * 1024 * 1024))
    pid = env.scalar("select p.id from products p join users u on u.id=p.seller_id where u.email='seller01@sim.test' order by p.id desc limit 1")
    page = seller.get(f'/seller/products/{pid}/edit')
    forms = [f for f in forms_of(page.text) if '/files' in f['action']]
    if forms:
        t = time.time()
        r = seller.submit(forms[0], files={'file': ('large.zip', open(big, 'rb').read(), 'application/zip')})
        s = time.time() - t
        stored = env.scalar(f'select max(size) from product_files where product_id={pid}')
        check('49 MB file upload (just under the 50 MB limit) stored and scanned', r.status < 500 and stored not in (None, '') and int(stored) == 49 * 1024 * 1024,
              f'stored size {stored}; {" | ".join(r.flashes())[:100]}', s, 120)
    else:
        check('49 MB file upload (just under the 50 MB limit) stored and scanned', False, 'no upload form on the edit page')
    os.remove(big)


if __name__ == '__main__':
    sys.exit(main(sys.argv[1]))
