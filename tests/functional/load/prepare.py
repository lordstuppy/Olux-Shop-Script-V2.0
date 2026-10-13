"""Input for profiles.js: the phase 7 data (100 pending crypto orders, product
lists, a signed-in super admin) plus, for the contention profile, 40 buyers
with a USD balance and one licence-key product limited to 10 units.

  python3 -I tests/functional/load/prepare.py <out.json>
"""
import json
import os
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))))
sys.path.insert(0, os.path.join(ROOT, 'tests', 'simulation'))
sys.path.insert(0, os.path.join(ROOT, 'tests', 'simulation', 'load'))

import prepare as phase7  # noqa: E402
from simlib import env  # noqa: E402

UNITS = 10


def main(out):
    phase7.main(out)
    data = json.load(open(out))
    pid, slug = env.sql(f"""select p.id, p.slug from products p join users s on s.id=p.seller_id
        where p.status='active' and s.status='active' and p.currency='USD' and p.stock is not null and p.price_minor <= 5000
        and (select count(*) from product_license_keys k where k.product_id=p.id and k.order_item_id is null) >= {UNITS}
        order by p.id limit 1""")[0]
    env.sql(f'update products set stock={UNITS} where id={pid}')
    emails = [f'load-buyer{n:02d}@sim.test' for n in range(1, 41)]
    env.sql("""insert into users (name, email, email_verified_at, password_hash, role, balance_minor, currency, status, created_at, updated_at)
        select 'Load buyer ' || n, 'load-buyer' || lpad(n::text, 2, '0') || '@sim.test', now(), u.password_hash, 'buyer', 100000, 'USD', 'active', now(), now()
        from generate_series(1, 40) n, (select password_hash from users where email='buyer01@sim.test') u
        on conflict (email) do nothing""")
    data.update({'contention_product': {'id': int(pid), 'slug': slug}, 'contention_units': UNITS, 'contention_buyers': emails})
    json.dump(data, open(out, 'w'))
    print(f'contention: product {pid} limited to {UNITS} units, {len(emails)} buyers with a balance')


if __name__ == '__main__':
    main(sys.argv[1])
