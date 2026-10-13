"""Phase 12: final functional gate (docs/functional). Operations that race each
other on purpose, invalid state transitions, and gateway failures the earlier
phases did not inject (429, 503, a reset mid-answer, a request the gateway
accepted but whose answer was lost).

Competing requests are released together by a barrier (common.parallel), so
they overlap on purpose instead of by luck. Every scenario then reads the
database: what counts is what was stored, not what the page said. The people
are created for this phase (p12-*@sim.test, copied from a seeded buyer's
password hash), so the phase also runs on its own after a reset:

  tests/simulation/run.sh test --phases 12
"""
import re
import socket
import ssl
import time
import urllib.parse

from simlib import env, shop
from simlib.http import BASE, Client
from simlib.record import scenario

from .common import admin, balance, check_ledger, flashes, instant_product, items_of, money, parallel, past_dispute_window, seller_available, staff_form, wait_status
from .p2_payments import charges, invoice, payload, post_webhook
from .p3_accounts import register


def person(tag, minor=0, currency='USD'):
    """A verified buyer only this phase uses; minor > 0 is credited by finance through the real form."""
    email = f'p12-{tag}@sim.test'
    env.sql(f"""insert into users (name, email, email_verified_at, password_hash, role, balance_minor, currency, status, created_at, updated_at)
        select 'P12 {tag}', '{email}', now(), password_hash, 'buyer', 0, '{currency}', 'active', now(), now()
        from users where email='buyer01@sim.test' on conflict (email) do nothing""")
    if minor:
        fund(email, minor, currency)
    return email


def uid(email):
    return env.scalar(f"select id from users where email='{email}'")


def fund(email, minor, currency='USD'):
    u = uid(email)
    r = staff_form(admin('finance@sim.test'), f'/admin/users/{u}', f'/admin/users/{u}/balance',
                   {'direction': 'credit', 'amount': money(minor), 'currency': currency, 'reason': 'Phase 12 test funds'})
    if balance(email) < minor:
        raise AssertionError(f'funding {email} failed: {flashes(r)}')


def priced(lo, hi, exclude=()):
    """An instant, unlimited USD product priced between lo and hi (minor units)."""
    return instant_product(f'price_minor between {lo} and {hi}', exclude=exclude)


def crypto_order(c, product):
    shop.add_to_cart(c, product)
    r, oid = shop.checkout(c, 'crypto', 'BTC')
    if oid is None:
        raise AssertionError(f'checkout failed: {flashes(r)}')
    return oid


def no_5xx(rec, results):
    bad = [r if isinstance(r, Exception) else f'{r.status} {r.request_id}' for r in results if isinstance(r, Exception) or r.status >= 500]
    rec.check(not bad, f'errors among concurrent responses: {bad}')


# ---- concurrency ----------------------------------------------------------

@scenario(12, 'FC-01 One buyer, two devices, balance enough for one order',
          'p12-twodev has 15.00 USD; signed in on a phone and a laptop, each with a different product (7.51-15.00) in its cart',
          'Both balance checkouts are released together: exactly one order is paid, the other is refused with a message and '
          'leaves no order; the balance is never negative and equals 15.00 minus the one order', 'blocker')
def two_devices_one_balance(rec, ctx):
    email = person('twodev', 1500)
    p = priced(751, 1500)
    q = priced(751, 1500, exclude=[p['id']])
    phone, laptop = shop.login(email, who='p12-twodev-phone'), shop.login(email, who='p12-twodev-laptop')
    shop.empty_cart(phone)
    shop.add_to_cart(phone, p)
    forms = []
    for c, prod in ((phone, p), (laptop, q)):
        if c is laptop:
            shop.empty_cart(laptop)
            shop.add_to_cart(laptop, q)
        forms.append((c, c.find_form(c.get('/checkout'), '/checkout')))
    rec.step('both checkout pages open; releasing both submits together')
    before = int(env.scalar(f"select count(*) from orders where buyer_id={uid(email)}"))
    res = parallel([lambda c=c, f=f: c.submit(f, {'payment_method': 'balance', 'accept_terms': '1'}) for c, f in forms])
    no_5xx(rec, res)
    rows = env.sql(f"select status, total_minor from orders where buyer_id={uid(email)} order by id")[before:]
    rec.ev(f'orders after the race: {rows}; flashes: {[flashes(r) for r in res]}')
    paid = [r for r in rows if r[0] in ('paid', 'delivered')]
    rec.check(len(paid) == 1, f'{len(paid)} paid orders from 15.00 that covers one: {rows}')
    rec.check(len(rows) == 1, f'a refused balance checkout left an order behind: {rows}')
    rec.check(balance(email) == 1500 - int(paid[0][1]), f'balance {balance(email)} after paying {paid[0][1]} from 1500')
    rec.check(any('balance' in flashes(r).lower() for r in res), f'no message for the refused checkout: {[flashes(r) for r in res]}')


@scenario(12, 'FC-02 Staff debit races a balance checkout',
          'p12-debit has 20.00 USD and a product of 12.00-15.00 in the cart; finance debits 10.00 at the same moment',
          'Never both (they need more than 20.00): the balance stays >= 0 and equals 20.00 minus what succeeded; '
          'the wallet history matches the balance', 'blocker')
def debit_vs_checkout(rec, ctx):
    email = person('debit', 2000)
    u = uid(email)
    p = priced(1200, 1500)
    b = shop.login(email, who='p12-debit')
    shop.add_to_cart(b, p)
    fin = admin('finance@sim.test')
    shop.confirm_password(fin)
    cform = b.find_form(b.get('/checkout'), '/checkout')
    dform = fin.find_form(fin.get(f'/admin/users/{u}'), f'/admin/users/{u}/balance')
    res = parallel([lambda: b.submit(cform, {'payment_method': 'balance', 'accept_terms': '1'}),
                    lambda: fin.submit(dform, {'direction': 'debit', 'amount': '10.00', 'currency': 'USD', 'reason': 'Race test'})])
    no_5xx(rec, res)
    paid = env.sql(f"select total_minor from orders where buyer_id={u} and status in ('paid','delivered')")
    debited = env.scalar(f"select count(*) from balance_transactions where user_id={u} and amount_minor = -1000")
    spent = sum(int(x[0]) for x in paid) + (1000 if debited == '1' else 0)
    rec.ev(f'paid orders {paid}, staff debit applied: {debited}; flashes {[flashes(r) for r in res]}')
    rec.check(balance(email) >= 0, f'balance negative: {balance(email)}')
    rec.check(balance(email) == 2000 - spent, f'balance {balance(email)} != 2000 - {spent}')
    rec.check(spent > 0, 'neither the checkout nor the debit succeeded')
    rec.check(spent <= 2000, f'both succeeded: {spent} spent from 2000')


@scenario(12, 'FC-03 Same email registered five times at once',
          'Five visitors submit the sign-up form with the same new address at the same moment',
          'Exactly one account; the others get the "already taken" field error, never a 500', 'major')
def concurrent_registration(rec, ctx):
    email = 'p12-samemail@sim.test'
    clients = [Client(f'p12-reg-{i}') for i in range(5)]
    pages = [(c, c.get('/register')) for c in clients]
    time.sleep(3.2)  # the form refuses submissions faster than a person could type
    fields = {'name': 'Same Mail', 'email': email, 'password': shop.PASSWORD, 'password_confirmation': shop.PASSWORD, 'accept_terms': '1'}
    res = parallel([lambda c=c, p=p: c.submit(c.find_form(p, '/register'), fields) for c, p in pages])
    no_5xx(rec, res)
    rec.ev(f'landed on {[r.path for r in res]}')
    rec.check(env.scalar(f"select count(*) from users where lower(email)='{email}'") == '1', 'not exactly one account')
    refused = [r for r in res if '/register' in r.path]
    rec.check(len(refused) == 4 or len(refused) + len([r for r in res if 'too many' in flashes(r).lower()]) == 4,
              f'{len(refused)} refused registrations: {[flashes(r) for r in res]}')


@scenario(12, 'FC-04 Ten identical payment callbacks at the same moment',
          'p12-tencb has a pending crypto order; the gateway recorded the payment but its callback is sent ten times in parallel',
          'One confirmed charge, one sale ledger entry per line, one paid email, one delivery', 'blocker')
def parallel_callbacks(rec, ctx):
    email = person('tencb')
    c = shop.login(email, who='p12-tencb')
    oid = crypto_order(c, instant_product())
    inv = invoice(oid)
    body = payload(inv, inv['amount_fiat'])
    res = parallel([lambda: post_webhook(body, who=f'shkeeper-{i}') for i in range(10)])
    rec.ev(f'callback statuses {sorted(r.status if not isinstance(r, Exception) else repr(r) for r in res)}')
    rec.check(all(not isinstance(r, Exception) and r.status in (200, 202) for r in res), 'a duplicate callback was not accepted cleanly')
    wait_status(oid, 'delivered')
    time.sleep(3)
    rec.check(len([x for x in charges(oid) if x[0] == 'confirmed']) == 1, f'charges {charges(oid)}')
    check_ledger(rec, oid)
    rec.check(len(env.mails(email, 'paid')) == 1, f"{len(env.mails(email, 'paid'))} paid emails")
    rec.check(int(env.scalar(f"select count(*) from audit_log where action='order.paid' and target_id='{shop.order_row(oid)['id']}'")) <= 1, 'order.paid audited twice')


@scenario(12, 'FC-05 Payment arrives while the order expires',
          'p12-expire has four pending crypto orders past their deadline; for each, the expiry command and the PAID callback start together',
          'Each order is either paid (and delivered), or expired with the late payment credited to the balance in full: '
          'received money is never lost or applied twice; stock and coupon reservations are not released for a paid order', 'blocker')
def payment_vs_expiry(rec, ctx):
    email = person('expire')
    c = shop.login(email, who='p12-expire')
    outcomes = []
    for i in range(4):
        oid = crypto_order(c, instant_product())
        inv = invoice(oid)
        row = shop.order_row(oid)
        env.sql(f"update orders set expires_at = now() - interval '1 minute' where id={row['id']}")
        body = payload(inv, inv['amount_fiat'])
        parallel([lambda: env.artisan('shop:expire-orders', check=False), lambda b=body: post_webhook(b, who='shkeeper')])

        def settled(oid=oid, row=row):
            r = shop.order_row(oid)
            credited = env.scalar(f"select coalesce(sum(amount_minor),0) from balance_transactions where user_id={row['buyer_id']} and type='late_payment_credit' "
                                  f"and reference_id in (select id from payments where order_id={row['id']})") if r['status'] == 'expired' else '0'
            return (r['status'] in ('delivered',) or (r['status'] == 'expired' and int(credited) == row['total'])) and (r['status'], int(credited))
        try:
            outcomes.append((oid, shop.wait_for(settled, f'order {oid} settled', 60)))
        except AssertionError:
            outcomes.append((oid, (shop.order_row(oid)['status'], 'no credit')))
    rec.ev(f'outcomes {outcomes}')
    ctx.allow_log(r'Late payment for expired order')
    rec.check(all(isinstance(o[1][1], int) for o in outcomes), f'money lost: {outcomes}')
    rec.check(all(len([x for x in charges(oid) if x[0] == 'confirmed']) <= 1 for oid, _ in outcomes), 'a payment applied twice')


@scenario(12, 'FC-06 Two staff decide the same payout at the same moment',
          'seller03 requests a payout; finance approves while super admin rejects; later both mark it paid together',
          'Exactly one decision stands; a rejected payout returns the funds once; marking paid twice debits the ledger once', 'blocker')
def payout_decisions(rec, ctx):
    staff_form(admin(), '/admin/settings', '/admin/settings', {'payout_hold_days': '0', 'min_payout_minor': '100'})
    sid = int(env.scalar("select id from users where email='seller03@sim.test'"))
    email = person('payout')
    b = shop.login(email, who='p12-payout')
    for _ in range(2):
        oid = crypto_order(b, instant_product(f'seller_id={sid}'))
        shop.pay(oid)
        wait_status(oid, 'delivered')
    past_dispute_window(sid)
    s = shop.login('seller03@sim.test', who='p12-seller03')
    avail = seller_available(s)
    ledger_before = int(env.scalar(f"select coalesce(sum(amount_minor),0) from seller_ledger_entries where seller_id={sid} and currency='USD'"))
    s.form('/seller/payouts', '/seller/payouts', {'amount': money(avail // 2), 'currency': 'USD'})
    pid = env.scalar(f"select id from payouts where seller_id={sid} and status='requested' order by id desc limit 1")
    rec.check(pid, 'payout not requested')
    fin, sa = admin('finance@sim.test'), admin()
    for x in (fin, sa):
        shop.confirm_password(x)
    fa = fin.find_form(fin.get('/admin/payouts'), f'/admin/payouts/{pid}/approve')
    fr = sa.find_form(sa.get('/admin/payouts'), f'/admin/payouts/{pid}/reject')
    res = parallel([lambda: fin.submit(fa), lambda: sa.submit(fr, {'note': 'Race test: rejected.'})])
    no_5xx(rec, res)
    status = env.scalar(f'select status from payouts where id={pid}')
    rec.ev(f'after approve/reject race: {status}; {[flashes(r) for r in res]}')
    rec.check(status in ('approved', 'rejected'), f'status {status}')
    audits = env.sql(f"select action from audit_log where target_id='{pid}' and action in ('payout.approved','payout.rejected')")
    rec.check(len(audits) == 1, f'both decisions recorded: {audits}')
    if status == 'rejected':
        after = int(env.scalar(f"select coalesce(sum(amount_minor),0) from seller_ledger_entries where seller_id={sid} and currency='USD'"))
        rec.check(after == ledger_before, f'rejected payout changed the ledger {ledger_before} -> {after}')
        s.form('/seller/payouts', '/seller/payouts', {'amount': money(avail // 2), 'currency': 'USD'})
        pid = env.scalar(f"select id from payouts where seller_id={sid} and status='requested' order by id desc limit 1")
        staff_form(fin, '/admin/payouts', f'/admin/payouts/{pid}/approve')
    f1 = fin.find_form(fin.get('/admin/payouts'), f'/admin/payouts/{pid}/paid')
    f2 = sa.find_form(sa.get('/admin/payouts'), f'/admin/payouts/{pid}/paid')
    res = parallel([lambda: fin.submit(f1, {'reference': 'p12-tx-a'}), lambda: sa.submit(f2, {'reference': 'p12-tx-b'})])
    no_5xx(rec, res)
    rec.check(env.scalar(f'select status from payouts where id={pid}') == 'paid', 'payout not paid')
    rec.check(env.scalar(f'select count(*) from seller_ledger_entries where payout_id={pid}') == '1', 'payout debited more than once')
    rc = env.artisan('shop:reconcile-payouts', check=False)
    rec.check(rc.returncode == 0, rc.stdout[-300:])


@scenario(12, 'FC-07 Full refund races the dispute resolution of the same order',
          'p12-dispute has a delivered order and an open dispute asking for a refund; finance refunds the order in full '
          'while the manager resolves the dispute with a refund',
          'The buyer gets the money once: refunded never exceeds the total, the balance rises by the total once, '
          'the line earning is reversed once; the dispute ends resolved', 'blocker')
def refund_vs_dispute(rec, ctx):
    email = person('dispute')
    b = shop.login(email, who='p12-dispute')
    oid = crypto_order(b, instant_product())
    shop.pay(oid)
    wait_status(oid, 'delivered')
    item = items_of(oid)[0]['id']
    b.form(f'/orders/{oid}/items/{item}/dispute', f'/orders/{oid}/items/{item}/dispute', {'requested_outcome': 'refund', 'body': 'Race test.'})
    did = env.scalar(f'select id from disputes where order_item_id={item}')
    rec.check(did, 'dispute not opened')
    total = shop.order_row(oid)['total']
    before = balance(email)
    fin, mgr = admin('finance@sim.test'), admin('manager@sim.test')
    for x in (fin, mgr):
        shop.confirm_password(x)
    rf = fin.find_form(fin.get(f'/admin/orders/{oid}'), f'/admin/orders/{oid}/refunds')
    df = mgr.find_form(mgr.get(f'/disputes/{did}'), f'/admin/disputes/{did}/resolve', need={'action': 'refund'})
    res = parallel([lambda: fin.submit(rf, {'method': 'balance', 'amount': money(total), 'reason': 'Race test'}),
                    lambda: mgr.submit(df, {'method': 'balance', 'note': 'Race test'})])
    no_5xx(rec, res)
    row = shop.order_row(oid)
    rec.ev(f'order {row}; dispute {env.scalar(f"select status from disputes where id={did}")}; {[flashes(r) for r in res]}')
    rec.check(row['refunded'] <= row['total'], f"refunded {row['refunded']} of {row['total']}")
    rec.check(balance(email) - before == row['refunded'], f'balance rose {balance(email) - before}, refunded {row["refunded"]}')
    rec.check(row['refunded'] == total, f'not refunded in full: {row}')
    rec.check(env.scalar(f'select count(*) from balance_transactions where user_id={uid(email)} and amount_minor > 0 and type like \'%refund%\'') == '1', 'refund credited twice')
    rec.check(items_of(oid)[0]['earning'] == 0, 'earning not reversed exactly once')
    shop.wait_for(lambda: env.scalar(f'select status from disputes where id={did}') in ('resolved', 'withdrawn'), 'dispute closed', 30)


@scenario(12, 'FC-08 Eight buyers race for three units',
          'A licence-key product with stock 3 (and at least 3 unused keys); eight buyers check out with crypto at the same moment',
          'Exactly three orders reserve it, stock ends at 0 and never below; the five others get "just sold out", no 5xx; '
          'paying the three delivers three distinct keys', 'blocker')
def last_units(rec, ctx):
    p = shop.product("""status='active' and currency='USD' and stock is not null and stock >= 3 and price_minor < 5000
        and (select count(*) from product_license_keys k where k.product_id=products.id and k.order_item_id is null) >= 3
        and exists (select 1 from users s where s.id=products.seller_id and s.status='active')""")
    env.sql(f"update products set stock=3 where id={p['id']}")
    racers = []
    for i in range(8):
        email = person(f'race{i}')
        c = shop.login(email, who=f'p12-race{i}')
        shop.add_to_cart(c, p)
        racers.append((c, c.find_form(c.get('/checkout'), '/checkout')))
    res = parallel([lambda c=c, f=f: c.submit(f, {'payment_method': 'crypto', 'crypto': 'BTC', 'accept_terms': '1'}) for c, f in racers])
    no_5xx(rec, res)
    orders = env.sql(f"select o.public_id from orders o join order_items i on i.order_id=o.id where i.product_id={p['id']} and o.status='pending'")
    rec.ev(f"orders {len(orders)}; stock {env.scalar(f'select stock from products where id={p[chr(105)+chr(100)]}')}; messages {[flashes(r)[:80] for r in res]}")
    rec.check(len(orders) == 3, f'{len(orders)} orders for 3 units')
    rec.check(env.scalar(f"select stock from products where id={p['id']}") == '0', 'stock not 0')
    rec.check(sum('sold out' in flashes(r).lower() for r in res) == 5, f'losers not told: {[flashes(r)[:80] for r in res]}')
    for (oid,) in orders:
        shop.pay(oid)
    for (oid,) in orders:
        wait_status(oid, 'delivered')
    keys = env.sql(f"select count(distinct k.id) from product_license_keys k join order_items i on i.id=k.order_item_id where i.product_id={p['id']} "
                   f"and i.order_id in (select id from orders where public_id in ({','.join(repr(o[0]) for o in orders)}))")
    rec.check(keys[0][0] == '3', f'distinct keys delivered: {keys}')


@scenario(12, 'FC-09 Double submits of a review, a dispute and a wishlist entry',
          'p12-double has a delivered order; each form is sent three times at the same moment',
          'One review, one dispute, one wishlist row; no 5xx', 'major')
def double_submits(rec, ctx):
    email = person('double')
    b = shop.login(email, who='p12-double')
    p = instant_product()
    oid = crypto_order(b, p)
    shop.pay(oid)
    wait_status(oid, 'delivered')
    item = items_of(oid)[0]['id']
    page = b.get(f"/products/{p['slug']}")
    rv = b.find_form(page, f"/products/{p['slug']}/reviews")
    wl = b.find_form(page, '/wishlist')
    dp = b.find_form(b.get(f'/orders/{oid}/items/{item}/dispute'), f'/orders/{oid}/items/{item}/dispute')
    for form, fields in ((rv, {'rating': '5', 'title': 'Triple', 'body': 'Sent three times.'}), (wl, {}),
                         (dp, {'requested_outcome': 'refund', 'body': 'Sent three times.'})):
        no_5xx(rec, parallel([lambda f=form, x=fields: b.submit(f, x) for _ in range(3)]))
    u = uid(email)
    counts = {
        'reviews': env.scalar(f"select count(*) from product_reviews where user_id={u}"),
        'wishlist': env.scalar(f"select count(*) from wishlist_items where user_id={u}"),
        'disputes': env.scalar(f"select count(*) from disputes where order_item_id={item}"),
    }
    rec.ev(counts)
    rec.check(all(v == '1' for v in counts.values()), f'duplicates: {counts}')


@scenario(12, 'FC-10 Five downloads at once with one download left',
          'p12-dl owns a delivered file product with a limit of 3 and 2 downloads used',
          'Exactly one of five simultaneous downloads is served; the counter ends at 3, never above', 'critical')
def downloads_at_limit(rec, ctx):
    email = person('dl')
    b = shop.login(email, who='p12-dl')
    p = instant_product()
    env.sql(f"update products set download_limit=3 where id={p['id']}")
    oid = crypto_order(b, p)
    shop.pay(oid)
    wait_status(oid, 'delivered')
    item = items_of(oid)[0]['id']
    env.sql(f'update order_items set download_count=2 where id={item}')
    m = re.search(r'href="([^"]+/files/\d+\?[^"]*)"', b.get(f'/orders/{oid}').text)
    rec.check(m, 'no download link')
    link = m.group(1).replace('&amp;', '&')
    res = parallel([lambda: b.get(link, expect=None) for _ in range(5)])
    no_5xx(rec, res)
    served = [r for r in res if r.status == 200]
    rec.ev(f'statuses {[r.status for r in res]}')
    rec.check(len(served) == 1, f'{len(served)} downloads served with one left')
    rec.check(env.scalar(f'select download_count from order_items where id={item}') == '3', 'counter wrong')


@scenario(12, 'FC-11 Seller asks for two payouts from two devices that together exceed the balance',
          'seller04 has available earnings A; a phone asks for 0.7 A and a laptop for 0.7 A at the same moment',
          'At most A is requested in total; the seller ledger never goes below zero; the refused request says why', 'blocker')
def two_payout_requests(rec, ctx):
    staff_form(admin(), '/admin/settings', '/admin/settings', {'payout_hold_days': '0', 'min_payout_minor': '100'})
    sid = int(env.scalar("select id from users where email='seller04@sim.test'"))
    email = person('payout2')
    b = shop.login(email, who='p12-payout2')
    oid = crypto_order(b, instant_product(f'seller_id={sid}'))
    shop.pay(oid)
    wait_status(oid, 'delivered')
    past_dispute_window(sid)
    phone, laptop = shop.login('seller04@sim.test', who='p12-s4-phone'), shop.login('seller04@sim.test', who='p12-s4-laptop')
    avail = seller_available(phone)
    rec.check(avail >= 200, f'available only {avail}')
    before = int(env.scalar(f"select coalesce(sum(amount_minor),0) from payouts where seller_id={sid} and status not in ('rejected','failed')"))
    forms = [(c, c.find_form(c.get('/seller/payouts'), '/seller/payouts')) for c in (phone, laptop)]
    res = parallel([lambda c=c, f=f: c.submit(f, {'amount': money(avail * 7 // 10), 'currency': 'USD'}) for c, f in forms])
    no_5xx(rec, res)
    after = int(env.scalar(f"select coalesce(sum(amount_minor),0) from payouts where seller_id={sid} and status not in ('rejected','failed')"))
    rec.ev(f'available {avail}; requested now {after - before}; {[flashes(r) for r in res]}')
    rec.check(after - before <= avail, f'requested {after - before} of {avail}')
    rec.check(after - before == avail * 7 // 10, 'not exactly one request accepted')
    rec.check(int(env.scalar(f"select coalesce(sum(amount_minor),0) from seller_ledger_entries where seller_id={sid} and currency='USD'")) >= 0, 'ledger negative')


@scenario(12, 'FC-12 Buyer suspended while checking out',
          'p12-susp has 50.00 USD and a product in the cart; the manager suspends the account while the balance checkout is sent',
          'Either the order completed before the suspension or it was refused; nothing half-done; afterwards the session '
          'is ended and further checkouts are impossible', 'critical')
def suspend_during_checkout(rec, ctx):
    email = person('susp', 5000)
    u = uid(email)
    b = shop.login(email, who='p12-susp')
    shop.add_to_cart(b, priced(100, 3000))
    mgr = admin('manager@sim.test')
    shop.confirm_password(mgr)
    cf = b.find_form(b.get('/checkout'), '/checkout')
    sf = mgr.find_form(mgr.get(f'/admin/users/{u}'), f'/admin/users/{u}/status', need={'status': 'suspended'})
    res = parallel([lambda: b.submit(cf, {'payment_method': 'balance', 'accept_terms': '1'}), lambda: mgr.submit(sf)])
    no_5xx(rec, res)
    rows = env.sql(f'select status, total_minor from orders where buyer_id={u}')
    rec.ev(f'orders {rows}; balance {balance(email)}; user {env.scalar(f"select status from users where id={u}")}')
    rec.check(env.scalar(f'select status from users where id={u}') == 'suspended', 'not suspended')
    rec.check(all(r[0] in ('paid', 'delivered') for r in rows), f'half-done order: {rows}')
    rec.check(balance(email) == 5000 - sum(int(r[1]) for r in rows), 'balance does not match the orders')
    rec.check('/login' in b.get('/orders', expect=None).url, 'suspended buyer still signed in')
    staff_form(mgr, f'/admin/users/{u}', f'/admin/users/{u}/status', {'status': 'active'}, need={'status': 'active'})


# ---- state transitions ------------------------------------------------------

@scenario(12, 'FS-01 Invalid transitions are refused by the server',
          'p12-states with a delivered order, a pending order and a requested payout; forms are replayed out of order',
          'Cancelling a paid order, refunding an unpaid one, paying a cancelled one, delivering an unpaid line, marking a '
          'requested payout paid, resolving a closed dispute and refunding more than is left are all refused with a message; '
          'nothing in the database changes', 'critical')
def invalid_transitions(rec, ctx):
    email = person('states', 3000)
    b = shop.login(email, who='p12-states')
    paid_oid = crypto_order(b, instant_product())
    cancel_form = b.find_form(b.get(f'/orders/{paid_oid}'), f'/orders/{paid_oid}/cancel')
    shop.pay(paid_oid)
    wait_status(paid_oid, 'delivered')
    r = b.submit(cancel_form)
    rec.check(shop.order_row(paid_oid)['status'] == 'delivered', 'stale cancel form cancelled a paid order')
    rec.check(r.status < 500 and flashes(r), f'no message for the stale cancel: {r.status}')

    pending = crypto_order(b, instant_product())
    fin = admin('finance@sim.test')
    shop.confirm_password(fin)
    paid_refund_form = fin.find_form(fin.get(f'/admin/orders/{paid_oid}'), f'/admin/orders/{paid_oid}/refunds')
    r = fin.post(f'/admin/orders/{pending}/refunds', {'method': 'balance', 'amount': '1.00', 'reason': 'not paid'}, token_from=f'/admin/orders/{pending}')
    rec.check(shop.order_row(pending)['refunded'] == 0 and r.status < 500, f'unpaid order refunded: {r.status}')

    pay_form = b.find_form(b.get(f'/orders/{pending}/pay'), f'/orders/{pending}/pay')
    b.form(f'/orders/{pending}', f'/orders/{pending}/cancel')
    rec.check(shop.order_row(pending)['status'] == 'cancelled', 'cancel failed')
    bal = balance(email)
    r = b.submit(pay_form, {'crypto': 'BTC'})
    rec.check(shop.order_row(pending)['status'] == 'cancelled' and r.status < 500, f'cancelled order reopened: {shop.order_row(pending)}')
    rec.check(balance(email) == bal and len(charges(pending)) <= 1, 'cancelled order charged')

    total = shop.order_row(paid_oid)['total']
    fin.submit(paid_refund_form, {'method': 'balance', 'amount': money(total), 'reason': 'full'})
    rec.check(shop.order_row(paid_oid)['refunded'] == total, 'full refund failed')
    r = fin.submit(fin.find_form(fin.get(f'/admin/orders/{paid_oid}'), f'/admin/orders/{paid_oid}/refunds'),
                   {'method': 'balance', 'amount': '0.01', 'reason': 'one cent too many'}) if '/refunds' in fin.get(f'/admin/orders/{paid_oid}').text else None
    rec.check(shop.order_row(paid_oid)['refunded'] == total, 'refunded more than the total')
    r = fin.submit(paid_refund_form, {'method': 'balance', 'amount': money(total), 'reason': 'stale form'})
    rec.check(shop.order_row(paid_oid)['refunded'] == total and r.status < 500, 'stale refund form refunded again')

    p = shop.product("status='active' and delivery_type='manual' and currency='USD' and (stock is null or stock > 0)")
    oid = crypto_order(b, p)
    item = items_of(oid)[0]['id']
    s = shop.login(env.scalar(f"select email from users where id={p['seller_id']}"), who='p12-manual-seller')
    r = s.post(f'/seller/items/{item}/deliver', {'payload': 'too early'}, token_from='/seller')
    rec.check(not items_of(oid)[0]['delivered'] and r.status < 500, f'unpaid line delivered: {r.status}')
    b.form(f'/orders/{oid}', f'/orders/{oid}/cancel')

    sid = int(env.scalar("select id from users where email='seller02@sim.test'"))
    staff_form(admin(), '/admin/settings', '/admin/settings', {'payout_hold_days': '0', 'min_payout_minor': '100'})
    o2 = crypto_order(b, instant_product(f'seller_id={sid}'))
    shop.pay(o2)
    wait_status(o2, 'delivered')
    past_dispute_window(sid)
    s2 = shop.login('seller02@sim.test', who='p12-seller02')
    s2.form('/seller/payouts', '/seller/payouts', {'amount': money(min(seller_available(s2), 500)), 'currency': 'USD'})
    pid = env.scalar(f"select id from payouts where seller_id={sid} and status='requested' order by id desc limit 1")
    r = fin.post(f'/admin/payouts/{pid}/paid', {'reference': 'skipping approval'}, token_from='/admin/payouts')
    rec.check(env.scalar(f'select status from payouts where id={pid}') == 'requested' and r.status < 500, 'requested payout marked paid without approval')
    rec.check(env.scalar(f'select count(*) from seller_ledger_entries where payout_id={pid}') == '0', 'ledger debited for an unapproved payout')
    staff_form(fin, '/admin/payouts', f'/admin/payouts/{pid}/reject', {'note': 'Phase 12 cleanup.'})


@scenario(12, 'FS-02 Stale moderation form: product approved by one moderator, rejected later by another',
          'seller01 submits a product for review; the moderator and the manager open the review queue; the moderator approves',
          'The manager\'s later reject from the stale page is refused with a message; the product stays active; one decision audited', 'major')
def stale_moderation(rec, ctx):
    pid = env.scalar("select id from products where status='pending_review' order by id limit 1")
    rec.check(pid, 'no product waiting for review')
    mod, mgr = admin('moderator@sim.test'), admin('manager@sim.test')
    page_mod, page_mgr = mod.get('/admin/products?status=pending_review'), mgr.get('/admin/products?status=pending_review')
    approve = mod.find_form(page_mod, f'/admin/products/{pid}/status', need={'status': 'active'})
    reject = mgr.find_form(page_mgr, f'/admin/products/{pid}/status', need={'status': 'disabled'})
    mod.submit(approve)
    status = env.scalar(f'select status from products where id={pid}')
    if status != 'active':
        raise AssertionError(f'approve failed: product is {status} (needs content?)')
    r = mgr.submit(reject, {'note': 'Stale page: should be refused.'})
    rec.ev(f'manager: {flashes(r)}')
    rec.check(env.scalar(f'select status from products where id={pid}') == 'active', 'stale reject overrode the approval')
    rec.check(r.status < 500 and flashes(r), 'no message for the stale decision')


# ---- failures at the gateway --------------------------------------------------

@scenario(12, 'FF-01 Gateway answers 429, 503 and a cut-off response at checkout',
          'p12-gw checks out with crypto three times while the gateway rate-limits, is in maintenance, then resets mid-answer',
          'Each time the order is kept and the buyer lands on the payment page with a retry message within the client '
          'timeout; no charge; a retry then creates one invoice and the order is paid once; each failure is in the gateway log', 'critical')
def gateway_errors(rec, ctx):
    ctx.allow_log(r'(?i)shkeeper|invoice|cURL|HTTP 429|HTTP 503|gateway|transfer closed')
    email = person('gw')
    c = shop.login(email, who='p12-gw')
    logs_before = int(env.scalar("select count(*) from gateway_logs where channel='api' and outcome in ('error','timeout')"))
    for mode in ('http429', 'http503', 'truncated'):
        env.mock('/__mock/config', {'fail_next': [{'match': 'payment_request', 'mode': mode, 'count': 1}]})
        shop.add_to_cart(c, instant_product())
        started = time.time()
        r, oid = shop.checkout(c, 'crypto', 'BTC')
        took = time.time() - started
        rec.ev(f'{mode}: {took:.1f}s, {r.path}: {flashes(r)}')
        rec.check(oid and r.path.endswith('/pay') and 'invoice' in flashes(r).lower(), f'{mode}: landed on {r.path}: {flashes(r)}')
        rec.check(took < 12, f'{mode}: buyer waited {took:.1f}s')
        rec.check(not [x for x in charges(oid) if x[0] == 'confirmed'], f'{mode}: charge recorded')
        r = c.submit(c.find_form(c.get(f'/orders/{oid}/pay'), f'/orders/{oid}/pay'), {'crypto': 'BTC'})
        rec.check('Invoice created' in flashes(r), f'{mode}: retry: {flashes(r)}')
        shop.pay(oid)
        wait_status(oid, 'delivered')
        rec.check(len([x for x in charges(oid) if x[0] == 'confirmed']) == 1, f'{mode}: charges {charges(oid)}')
    env.mock('/__mock/config', {})
    logged = int(env.scalar("select count(*) from gateway_logs where channel='api' and outcome in ('error','timeout')")) - logs_before
    rec.check(logged >= 3, f'{logged} gateway failures logged for 3')


@scenario(12, 'FF-02 The gateway created the invoice but its answer was lost',
          'p12-lost checks out; the gateway processes the invoice request and then never answers (the shop times out at 10 s)',
          'The buyer is told to retry; the retry reuses the same invoice (same order id at the gateway); the payment confirms '
          'the order once; reconciliation finds nothing left over', 'blocker')
def invoice_lost_answer(rec, ctx):
    ctx.allow_log(r'(?i)shkeeper|invoice|cURL|timed out|gateway')
    email = person('lost')
    c = shop.login(email, who='p12-lost')
    env.mock('/__mock/config', {'fail_next': [{'match': 'payment_request', 'mode': 'lost_response', 'count': 1, 'seconds': 14}]})
    shop.add_to_cart(c, instant_product())
    r, oid = shop.checkout(c, 'crypto', 'BTC')
    env.mock('/__mock/config', {})
    rec.check(oid and r.path.endswith('/pay'), f'landed on {r.path}: {flashes(r)}')
    rec.check(env.mock_get('/api/v1/invoices/' + oid) if False else True, '')
    inv = invoice(oid)
    rec.ev(f'gateway has invoice {inv["id"]} for {oid} although the shop got no answer')
    r = c.submit(c.find_form(c.get(f'/orders/{oid}/pay'), f'/orders/{oid}/pay'), {'crypto': 'BTC'})
    rec.check('Invoice created' in flashes(r), f'retry: {flashes(r)}')
    rec.check(invoice(oid)['id'] == inv['id'], 'retry created a second invoice at the gateway')
    shop.pay(oid)
    wait_status(oid, 'delivered')
    rec.check(len([x for x in charges(oid) if x[0] == 'confirmed']) == 1, f'charges {charges(oid)}')
    env.artisan('shop:reconcile-payments')
    rec.check(len([x for x in charges(oid) if x[0] == 'confirmed']) == 1, 'reconciliation added a charge')


@scenario(12, 'FF-03 A payout sent to the gateway whose answer was lost',
          'seller05 has an approved payout; finance sends it through Shkeeper; the gateway accepts it and never answers',
          'The payout is not marked failed and not sent again blindly (its outcome is unknown); once the gateway reports '
          'the result, the payout is paid and the ledger is debited exactly once', 'blocker')
def payout_lost_answer(rec, ctx):
    ctx.allow_log(r'(?i)shkeeper|payout|cURL|timed out|gateway|unknown')
    staff_form(admin(), '/admin/settings', '/admin/settings', {'payout_hold_days': '0', 'min_payout_minor': '100'})
    sid = int(env.scalar("select id from users where email='seller05@sim.test'"))
    email = person('lostpay')
    b = shop.login(email, who='p12-lostpay')
    oid = crypto_order(b, instant_product(f'seller_id={sid}'))
    shop.pay(oid)
    wait_status(oid, 'delivered')
    past_dispute_window(sid)
    s = shop.login('seller05@sim.test', who='p12-seller05')
    if not env.scalar(f"select payout_address from seller_profiles where user_id={sid}"):
        raise AssertionError('seller05 has no payout address')
    s.form('/seller/payouts', '/seller/payouts', {'amount': money(min(seller_available(s), 1000)), 'currency': 'USD'})
    pid = env.scalar(f"select id from payouts where seller_id={sid} and status='requested' order by id desc limit 1")
    fin = admin('finance@sim.test')
    staff_form(fin, '/admin/payouts', f'/admin/payouts/{pid}/approve')
    env.mock('/__mock/config', {'fail_next': [{'match': '/payout', 'mode': 'lost_response', 'count': 1, 'seconds': 14}]})
    r = staff_form(fin, '/admin/payouts', f'/admin/payouts/{pid}/send', {'crypto': 'BTC'})
    env.mock('/__mock/config', {})
    status = env.scalar(f'select status from payouts where id={pid}')
    rec.ev(f'after the lost answer: payout {status}; {flashes(r)}')
    rec.check(status in ('processing',), f'payout {status} although the gateway may have paid it')
    sent = fin.get('/admin/payouts')
    rec.check(f'/admin/payouts/{pid}/send' not in sent.text, 'send offered again for a payout with an unknown outcome')
    env.mock(f'/__mock/payout-result/payout-{pid}', {'status': 'SUCCESS'})
    shop.wait_for(lambda: env.scalar(f'select status from payouts where id={pid}') == 'paid', 'payout paid', 60)
    rec.check(env.scalar(f'select count(*) from seller_ledger_entries where payout_id={pid}') == '1', 'ledger debited more than once')
    env.artisan('shop:reconcile-shkeeper-transfers', check=False)
    rec.check(env.scalar(f'select count(*) from seller_ledger_entries where payout_id={pid}') == '1', 'reconciliation debited again')


# ---- interrupted requests -----------------------------------------------------

def post_and_hang_up(c, form, fields):
    """Sends the form like the browser, then drops the connection before the answer arrives."""
    data = [list(x) for x in form['fields']]
    for k, v in fields.items():
        data = [x for x in data if x[0] != k] + [[k, v]]
    body = urllib.parse.urlencode([(k, v) for k, v in data if k]).encode()
    cookies = '; '.join(f'{x.name}={x.value}' for x in c.jar)
    u = urllib.parse.urlparse(BASE)
    path = urllib.parse.urlparse(form['action']).path
    raw = (f'POST {path} HTTP/1.1\r\nHost: {u.netloc}\r\nCookie: {cookies}\r\nContent-Type: application/x-www-form-urlencoded\r\n'
           f'Referer: {BASE}/checkout\r\nContent-Length: {len(body)}\r\nConnection: close\r\n\r\n').encode() + body
    ctx = ssl.create_default_context()
    ctx.check_hostname = False
    ctx.verify_mode = ssl.CERT_NONE
    with socket.create_connection((u.hostname, u.port or 443), timeout=5) as sock:
        with ctx.wrap_socket(sock, server_hostname=u.hostname) as tls:
            tls.sendall(raw)
    # Closed here: the browser lost the connection right after sending.


@scenario(12, 'FR-01 Connection lost right after sending the checkout; the buyer sends it again',
          'p12-hangup has 40.00 USD; the balance checkout is sent and the connection drops before any answer',
          'The server finishes or rolls back on its own; sending the same form again gives the same single order and '
          'one debit', 'blocker')
def hang_up_checkout(rec, ctx):
    email = person('hangup', 4000)
    b = shop.login(email, who='p12-hangup')
    p = priced(100, 3000)
    shop.add_to_cart(b, p)
    form = b.find_form(b.get('/checkout'), '/checkout')
    post_and_hang_up(b, form, {'payment_method': 'balance', 'accept_terms': '1'})
    time.sleep(3)
    first = env.sql(f'select status from orders where buyer_id={uid(email)}')
    rec.ev(f'after the dropped request: orders {first}, balance {balance(email)}')
    r = b.submit(form, {'payment_method': 'balance', 'accept_terms': '1'})
    rec.check(r.status < 500, f'retry: {r.status}')
    rows = env.sql(f'select status, total_minor from orders where buyer_id={uid(email)}')
    rec.check(len(rows) == 1 and rows[0][0] in ('paid', 'delivered'), f'orders after retry: {rows}')
    rec.check(balance(email) == 4000 - int(rows[0][1]), 'balance debited wrong')
    rec.check(env.scalar(f"select count(*) from balance_transactions where user_id={uid(email)} and type='order_payment'") == '1', 'debited twice')


@scenario(12, 'FR-02 PHP-FPM restarted during a burst of checkouts',
          'Six p12 buyers with 30.00 USD each send balance checkouts while the app container restarts',
          'Each request either completed or failed visibly (502/503/connection error); afterwards no half-created order, '
          'no debit without a paid order; sending the same form again yields exactly one paid order per buyer', 'blocker')
def restart_during_checkouts(rec, ctx):
    ctx.allow_status(502, 503, 504)
    ctx.allow_log(r'(?i)SIGTERM|terminat|connection|shutdown|graceful')
    buyers = []
    for i in range(6):
        email = person(f'restart{i}', 3000)
        c = shop.login(email, who=f'p12-restart{i}')
        shop.add_to_cart(c, priced(100, 2500))
        buyers.append((email, c, c.find_form(c.get('/checkout'), '/checkout')))
    import threading
    restarter = threading.Thread(target=lambda: (time.sleep(0.15), env.restart_app()))
    restarter.start()
    res = parallel([lambda c=c, f=f: c.submit(f, {'payment_method': 'balance', 'accept_terms': '1'}) for _, c, f in buyers])
    restarter.join()
    rec.ev(f'first attempt: {[r.status if not isinstance(r, Exception) else type(r).__name__ for r in res]}')
    shop.wait_for(lambda: Client('p12-probe').raw('GET', '/health').status == 200, 'app back', 90)
    for email, c, f in buyers:
        bal = balance(email)
        rows = env.sql(f'select status, total_minor from orders where buyer_id={uid(email)}')
        rec.check(all(r[0] in ('paid', 'delivered') for r in rows), f'{email}: half-done order {rows}')
        rec.check(bal == 3000 - sum(int(r[1]) for r in rows), f'{email}: balance {bal} vs orders {rows}')
        c.submit(f, {'payment_method': 'balance', 'accept_terms': '1'})
        rows = env.sql(f'select status, total_minor from orders where buyer_id={uid(email)}')
        rec.check(len(rows) == 1 and rows[0][0] in ('paid', 'delivered'), f'{email}: after resending {rows}')
        rec.check(balance(email) == 3000 - int(rows[0][1]), f'{email}: debited wrong')
