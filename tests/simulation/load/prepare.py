"""Phase 7 preparation: 100 pending crypto orders (2 for each of the 50 buyers),
product lists per seller, and a signed-in super admin session for k6.

Writes the k6 input to the file given as the first argument."""
import json
import os
import sys
import urllib.request

HERE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, HERE)

from simlib import env, http, shop  # noqa: E402

API_KEY = 'sim-api-key-0123456789'


def invoice(oid):
    req = urllib.request.Request(f'{env.MOCK}/api/v1/invoices/{oid}', headers={'X-Shkeeper-Api-Key': API_KEY})
    with urllib.request.urlopen(req, timeout=30) as r:
        return json.loads(r.read())['invoices'][0]


def main(out):
    http.CA = os.path.join(HERE, 'tls', 'cert.pem')
    products = env.sql("""select p.seller_id, p.slug, p.id from products p join users u on u.id=p.seller_id
        where p.status='active' and u.status='active' and p.currency='USD' and p.delivery_type='instant' and p.stock is null
        and exists (select 1 from product_files f where f.product_id=p.id and f.scan_status='clean') order by p.id""")
    by_seller = {}
    for seller, slug, pid in products:
        by_seller.setdefault(seller, []).append({'slug': slug, 'id': int(pid)})
    all_slugs = [p[0] for p in env.sql("select slug from products where status='active' order by id")]
    categories = [c[0] for c in env.sql('select slug from categories order by id')]

    orders = []
    plain = [p for ps in by_seller.values() for p in ps]
    for n in range(1, 51):
        c = shop.login(f'buyer{n:02d}@sim.test', who=f'prep-{n}')
        for k in range(2):
            p = plain[(n * 2 + k) % len(plain)]
            shop.add_to_cart(c, p)
            r, oid = shop.checkout(c, 'crypto', 'BTC')
            if oid is None:
                raise SystemExit(f'prepare: checkout failed for buyer{n:02d}: {r.flashes()}')
            inv = invoice(oid)
            orders.append({'id': oid, 'amount': inv['amount_fiat'], 'fiat': inv['fiat'], 'crypto': inv['crypto'], 'addr': inv['wallet']})
    a = shop.login('superadmin@sim.test', who='load-admin')
    cookies = {k.name: k.value for k in a.jar}
    data = {'orders': orders, 'sellers': by_seller, 'slugs': all_slugs, 'categories': categories, 'admin_cookies': cookies,
            'browse_buyers': [f'buyer{n:02d}@sim.test' for n in range(1, 31)],
            'checkout_buyers': [f'buyer{n:02d}@sim.test' for n in range(31, 51)],
            'password': shop.PASSWORD, 'api_key': API_KEY, 'mock': env.MOCK}
    with open(out, 'w') as f:
        json.dump(data, f)
    print(f'prepared {len(orders)} pending orders, {len(by_seller)} sellers, {len(all_slugs)} products')


if __name__ == '__main__':
    main(sys.argv[1])
