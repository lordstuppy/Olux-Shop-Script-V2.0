"""Helpers shared by the phase modules."""
import re
import threading

from simlib import env, shop
from simlib.http import Fail, text_of

_admin = {}


def admin(email='superadmin@sim.test'):
    """A logged-in staff browser, reused within a run (one per account)."""
    c = _admin.get(email)
    if c is not None:
        try:
            r = c.get('/admin', expect=None)
            if r.status == 200 and '/admin' in r.path:
                return c
        except Exception:  # noqa: BLE001 - stale session; log in again below
            pass
    c = shop.login(email, who=email.split('@')[0])
    _admin[email] = c
    return c


def forget_sessions():
    _admin.clear()


def staff_form(c, page, action, fields=None, **kw):
    """Submits a staff form, confirming the password first when the action asks for it."""
    r = c.form(page, action, fields, **kw)
    if '/confirm-password' in r.path:
        shop.confirm_password(c)
        r = c.form(page, action, fields, **kw)
    return r


def buyer(n=None, where=None):
    """Logs in a seeded buyer by number (1-50) or by an SQL condition on users."""
    if n is not None:
        email = f'buyer{n:02d}@sim.test'
    else:
        email = env.scalar(f"select email from users where email like 'buyer%@sim.test' and status='active' and {where} order by email limit 1")
        if not email:
            raise Fail(f'no seeded buyer with {where}')
    return email, shop.login(email)


def balance(email):
    return int(env.scalar(f"select balance_minor from users where email='{email}'"))


def stock(pid):
    v = env.scalar(f'select stock from products where id={pid}')
    return None if v in (None, '') else int(v)


def global_bps():
    v = env.scalar("select value from settings where key='commission_bps'")
    return int(str(v).strip('"')) if v not in (None, '') else 1000


def expected_bps(item_id):
    row = env.sql(f"""select p.commission_bps, sp.commission_bps, c.commission_bps from order_items i
        join products p on p.id=i.product_id left join seller_profiles sp on sp.user_id=p.seller_id
        left join categories c on c.id=p.category_id where i.id={item_id}""")[0]
    for v in row:
        if v not in ('', None):
            return int(v)
    return global_bps()


def apply_bps(minor, bps):
    """Money::applyBps: half-up rounding of minor * bps / 10000."""
    q, r = divmod(minor * bps, 10000)
    return q + (1 if r * 2 >= 10000 else 0)


def items_of(order_id):
    rows = env.sql(f"""select i.id, i.product_id, i.seller_id, i.unit_price_minor, i.quantity, i.discount_minor, i.commission_bps,
        i.seller_earning_minor, i.delivered_at is not null from order_items i join orders o on o.id=i.order_id
        where o.public_id='{order_id}' order by i.id""")
    keys = ('id', 'product_id', 'seller_id', 'unit', 'qty', 'discount', 'bps', 'earning', 'delivered')
    out = []
    for r in rows:
        d = dict(zip(keys, r))
        for k in keys[:-1]:
            d[k] = int(d[k])
        d['delivered'] = d['delivered'] == 't'
        out.append(d)
    return out


def check_ledger(rec, order_id):
    """Every line: sale = net, commission = -applyBps(net, frozen rate), rate = resolved rate."""
    for i in items_of(order_id):
        net = i['unit'] * i['qty'] - i['discount']
        fee = apply_bps(net, i['bps'])
        rec.check(i['bps'] == expected_bps(i['id']), f"line {i['id']}: frozen rate {i['bps']} bps, resolved rate {expected_bps(i['id'])} bps")
        entries = dict((t, int(a)) for t, a in env.sql(f"select type, sum(amount_minor) from seller_ledger_entries where order_item_id={i['id']} group by type"))
        rec.check(entries.get('sale') == net, f"line {i['id']}: sale entry {entries.get('sale')} != net {net}")
        rec.check(entries.get('commission', 0) == -fee, f"line {i['id']}: commission entry {entries.get('commission')} != -{fee}")
        rec.check(i['earning'] == net - fee, f"line {i['id']}: seller earning {i['earning']} != {net - fee}")
        rec.ev(f"line {i['id']} seller {i['seller_id']}: net {net}, rate {i['bps']} bps, fee {fee}, earning {i['earning']}")


def wait_status(order_id, statuses, timeout=90):
    statuses = (statuses,) if isinstance(statuses, str) else statuses
    return shop.wait_for(lambda: (shop.order_row(order_id) or {}).get('status') in statuses and shop.order_row(order_id),
                         f'order {order_id} in {statuses}', timeout)


def instant_product(extra='true', exclude=()):
    """An active instant product with a clean file, no stock limit, from an active seller."""
    ex = ' and products.id not in (%s)' % ','.join(str(x) for x in exclude) if exclude else ''
    return shop.product(f"""status='active' and delivery_type='instant' and stock is null and currency='USD'
        and exists (select 1 from product_files f where f.product_id=products.id and f.scan_status='clean')
        and not exists (select 1 from product_license_keys k where k.product_id=products.id) and {extra}{ex}""")


def flashes(r):
    return ' | '.join(r.flashes()) or text_of(r.text)[:300]


def parallel(fns):
    """Runs callables at the same moment; returns results (or exceptions) in order."""
    out = [None] * len(fns)
    barrier = threading.Barrier(len(fns))

    def run(i, fn):
        try:
            barrier.wait()
            out[i] = fn()
        except Exception as e:  # noqa: BLE001 - returned to the caller
            out[i] = e
    threads = [threading.Thread(target=run, args=(i, fn)) for i, fn in enumerate(fns)]
    for t in threads:
        t.start()
    for t in threads:
        t.join()
    return out


def money(minor):
    return f'{minor // 100}.{minor % 100:02d}'


def find_text(r, pattern):
    return re.search(pattern, text_of(r.text))
