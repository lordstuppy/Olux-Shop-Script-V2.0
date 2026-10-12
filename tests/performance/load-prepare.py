"""Input for staged-load.js: listed product slugs, categories and the cookies
of 40 signed-in buyers (their pages poll /live).

  python3 -I tests/performance/load-prepare.py load-data.json
"""
import json
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(os.path.dirname(HERE))
sys.path.insert(0, os.path.join(ROOT, 'tests', 'simulation'))
from simlib import env, http as simhttp, shop  # noqa: E402

simhttp.CA = os.path.join(ROOT, 'tests', 'simulation', 'tls', 'cert.pem')


def main(out):
    slugs = [r[0] for r in env.sql("""select p.slug from products p join users u on u.id = p.seller_id
        where p.status = 'active' and u.status = 'active' and u.role <> 'buyer' order by p.id""")]
    categories = [r[0] for r in env.sql('select slug from categories order by id')]
    buyers = []
    for n in range(1, 41):
        c = shop.login(f'buyer{n:02d}@sim.test', who=f'load-buyer-{n}')
        buyers.append({k.name: k.value for k in c.jar})
    with open(out, 'w') as f:
        json.dump({'slugs': slugs, 'categories': categories, 'buyers': buyers}, f)
    print(f'{len(slugs)} products, {len(categories)} categories, {len(buyers)} signed-in buyers')


if __name__ == '__main__':
    main(sys.argv[1])
