"""Phase 2: payment events from the gateway, honest and hostile."""
import hashlib
import hmac
import json
import threading
import time
import urllib.request

from simlib import env, shop
from simlib.http import Client
from simlib.record import scenario

from .common import balance, buyer, check_ledger, flashes, instant_product, items_of, money, wait_status

API_KEY = 'sim-api-key-0123456789'


def invoice(oid):
    req = urllib.request.Request(f'{env.MOCK}/api/v1/invoices/{oid}', headers={'X-Shkeeper-Api-Key': API_KEY})
    with urllib.request.urlopen(req, timeout=30) as r:
        return json.loads(r.read())['invoices'][0]


def payload(inv, amount, status=None, fiat=None):
    status = status or ('PAID' if float(amount) >= float(inv['amount_fiat']) else 'PARTIAL')
    return json.dumps({'external_id': inv['external_id'], 'crypto': inv['crypto'], 'addr': inv['wallet'], 'fiat': fiat or inv['fiat'],
                       'balance_fiat': amount, 'balance_crypto': '0', 'paid': status in ('PAID', 'OVERPAID'), 'status': status,
                       'transactions': [{'txid': hashlib.sha1(f'{amount}{status}'.encode()).hexdigest(), 'amount_fiat': amount, 'trigger': True}],
                       'fee_percent': '0', 'overpaid_fiat': '0.00'}, separators=(',', ':'))


def post_webhook(body, key=API_KEY, ts=None, sign=True, who='shkeeper'):
    """A callback as Shkeeper sends it, through the TLS front end."""
    headers = {'Content-Type': 'application/json'}
    if sign:
        ts = str(int(time.time())) if ts is None else str(ts)
        headers.update({'X-Shkeeper-Api-Key': key, 'X-Shkeeper-Timestamp': ts,
                        'X-Shkeeper-Signature': hmac.new(key.encode(), f'{ts}.{body}'.encode(), hashlib.sha256).hexdigest()})
    return Client(who).raw('POST', '/webhooks/shkeeper', body.encode() if isinstance(body, str) else body, headers)


def order_for(rec, n, product=None, method='crypto'):
    email, c = buyer(n)
    p = product or instant_product()
    shop.add_to_cart(c, p)
    r, oid = shop.checkout(c, method, 'BTC')
    rec.check(oid, f'no order: {flashes(r)}')
    row = shop.order_row(oid)
    rec.step(f"order {oid} for {money(row['total'])} {row['currency']} (buyer{n:02d})")
    return email, c, oid, row


def charges(oid):
    return env.sql(f"""select p.status, p.received_minor, p.provider from payments p join orders o on o.id=p.order_id
        where o.public_id='{oid}' and p.kind='charge' order by p.id""")


@scenario(2, 'Successful payment', 'buyer13 has a pending crypto order',
          'One confirmed charge for the exact total, order delivered, ledger booked, "paid" email once', 'blocker')
def success(rec, ctx):
    email, c, oid, row = order_for(rec, 13)
    r = shop.pay(oid)
    rec.check(r['callback_http_status'] == 202, f'webhook answered {r}')
    wait_status(oid, 'delivered')
    rec.check(charges(oid) == [['confirmed', str(row['total']), 'shkeeper']], f'charges {charges(oid)}')
    check_ledger(rec, oid)
    env.wait_mail(email, 'paid')
    time.sleep(2)
    rec.check(len(env.mails(email, 'paid')) == 1, 'more than one paid email')
    page = c.get(f'/orders/{oid}/result')
    rec.check('Delivered' in page.text or 'delivered' in page.text, 'result page does not show the delivered state')


@scenario(2, 'Underpayment (90%)', 'buyer14 has a pending crypto order',
          'Order stays pending, payment partial with 90% recorded, pay page shows what is still due; '
          'paying the rest completes the order', 'blocker')
def underpay(rec, ctx):
    email, c, oid, row = order_for(rec, 14)
    part = row['total'] * 9 // 10
    r = shop.pay(oid, money(part))
    rec.check(r['callback_http_status'] == 202, r)
    time.sleep(1)
    rec.check(shop.order_row(oid)['status'] == 'pending', f"order {shop.order_row(oid)['status']} after 90%")
    rec.check(charges(oid)[0][:2] == ['partial', str(part)], f'charges {charges(oid)}')
    page = c.get(f'/orders/{oid}/pay')
    due = money(row['total'] - part)
    rec.check(f'{due} {row["currency"]} is still due' in page.text, f'pay page does not show {due} due')
    rec.check(env.scalar(f"select count(*) from seller_ledger_entries l join order_items i on i.id=l.order_item_id join orders o on o.id=i.order_id where o.public_id='{oid}'") == '0',
              'ledger booked for an unpaid order')
    r = shop.pay(oid, money(row['total']))
    wait_status(oid, 'delivered')
    check_ledger(rec, oid)


@scenario(2, 'Overpayment (110%)', 'buyer15 has a pending crypto order',
          'Order paid once; the extra 10% is credited to the buyer balance with an audit entry', 'critical')
def overpay(rec, ctx):
    email, c, oid, row = order_for(rec, 15, product=instant_product("currency='USD'"))
    rec.check(env.scalar(f"select currency from users where email='{email}'") == row['currency'], 'buyer currency differs from the order currency')
    before = balance(email)
    extra = row['total'] // 10
    shop.pay(oid, money(row['total'] + extra))
    wait_status(oid, 'delivered')
    shop.wait_for(lambda: balance(email) == before + extra, f'balance +{extra}', 30)
    rec.check(env.scalar(f"select count(*) from audit_log where action='payment.overpayment_credited' and metadata_json->>'order'='{oid}'") == '1', 'no audit entry')
    rec.check(env.scalar(f"select count(*) from balance_transactions b join users u on u.id=b.user_id where u.email='{email}' and b.type='overpayment_credit'") == '1',
              'overpayment credited more than once or not at all')
    check_ledger(rec, oid)
    rec.ev(f'balance {before} -> {balance(email)}')


@scenario(2, 'Expired quote and expired order, retry possible', 'buyer16 has a pending crypto order',
          'After the quote expires the pay page asks for a new amount and a new invoice can be paid; '
          'after the order itself expires, stock is released and the buyer can buy the same items again', 'critical')
def expired(rec, ctx):
    email, c, oid, row = order_for(rec, 16)
    env.sql(f"update payments set quoted_at=now()-interval '20 minutes' where order_id={row['id']}")
    rec.step('quote aged by 20 minutes (quote lifetime 15)')
    page = c.get(f'/orders/{oid}/pay')
    rec.check('get a current amount' in page.text, 'stale quote not flagged on the pay page')
    r = c.submit(c.find_form(page, f'/orders/{oid}/pay'), {'crypto': 'BTC'})
    rec.check('Invoice created' in flashes(r), f'requote: {flashes(r)}')
    rec.check(env.scalar(f"select quoted_at > now()-interval '1 minute' from payments where order_id={row['id']}") == 't', 'quote not refreshed')
    shop.pay(oid)
    wait_status(oid, 'delivered')
    # Second order: let it expire entirely.
    p = shop.product("""status='active' and delivery_type='instant' and currency='USD' and stock >= 2
        and (select count(*) from product_license_keys k where k.product_id=products.id and k.order_item_id is null) >= 2""")
    stock_before = int(env.scalar(f"select stock from products where id={p['id']}"))
    shop.add_to_cart(c, p)
    r, oid2 = shop.checkout(c, 'crypto', 'BTC')
    env.sql(f"update orders set expires_at=now()-interval '1 minute' where public_id='{oid2}'")
    rec.step(f'order {oid2} moved past its deadline; waiting for the scheduler (shop:expire-orders, every minute)')
    wait_status(oid2, 'expired', timeout=150)
    rec.check(int(env.scalar(f"select stock from products where id={p['id']}")) == stock_before, 'stock not released')
    page = c.get(f'/orders/{oid2}')
    form = [f for f in __import__('simlib.http', fromlist=['forms_of']).forms_of(page.text) if f['action'].endswith('/reorder')]
    rec.check(form, 'expired order offers no way to buy the items again')
    r = c.submit(form[0])
    rec.check(r.path == '/cart' and p['title'] in r.text, f'buy again: {r.path} {flashes(r)}')
    r, oid3 = shop.checkout(c, 'crypto', 'BTC')
    shop.pay(oid3)
    wait_status(oid3, 'delivered')
    # Paying the expired invoice now: credited to the balance, never a second delivery.
    before = balance(email)
    shop.pay(oid2)
    time.sleep(2)
    rec.check(shop.order_row(oid2)['status'] == 'expired', 'expired order was revived')
    rec.check(balance(email) == before + shop.order_row(oid2)['total'] or env.scalar(f"select currency from users where email='{email}'") != 'USD',
              'late payment not credited to the balance')
    ctx.allow_log(r'Late payment')


@scenario(2, 'Duplicate callback delivered five times', 'buyer17 has a pending crypto order',
          'All five answered 202; exactly one confirmed charge, one ledger booking, one paid email, one delivery', 'blocker')
def duplicate(rec, ctx):
    email, c, oid, row = order_for(rec, 17)
    r = shop.pay(oid, repeat=5)
    rec.ev(f"callback statuses {r['callback_http_statuses']}")
    rec.check(r['callback_http_statuses'] == [202] * 5, f"statuses {r['callback_http_statuses']}")
    wait_status(oid, 'delivered')
    time.sleep(3)
    rec.check(len(charges(oid)) == 1, f'charges {charges(oid)}')
    check_ledger(rec, oid)
    rec.check(len(env.mails(email, 'paid')) == 1, f"{len(env.mails(email, 'paid'))} paid emails")
    rec.check(env.scalar(f"select count(*) from audit_log where action='order.paid' and target_id=(select id::text from orders where public_id='{oid}')") == '1', 'order.paid audited more than once')


@scenario(2, 'Out-of-order callbacks', 'buyer18 has a pending crypto order',
          'PAID arriving before an older PARTIAL: order paid once and stays paid, recorded amount never goes down; '
          'two PARTIALs out of order keep the higher cumulative amount', 'critical')
def out_of_order(rec, ctx):
    email, c, oid, row = order_for(rec, 18)
    inv = invoice(oid)
    half, most, full = money(row['total'] // 2), money(row['total'] * 9 // 10), money(row['total'])
    r1 = post_webhook(payload(inv, most))
    r2 = post_webhook(payload(inv, half))
    rec.check(r1.status == 202 and r2.status == 202, f'{r1.status} {r2.status}')
    rec.check(charges(oid)[0][1] == str(row['total'] * 9 // 10), f'received amount went down: {charges(oid)}')
    r3 = post_webhook(payload(inv, full))
    r4 = post_webhook(payload(inv, half))
    rec.check(r3.status == 202 and r4.status == 202, f'{r3.status} {r4.status}')
    wait_status(oid, 'delivered')
    rec.check(charges(oid) == [['confirmed', str(row['total']), 'shkeeper']], f'charges {charges(oid)}')
    check_ledger(rec, oid)


@scenario(2, 'Callback delayed 30 minutes', 'buyer19 paid on time; the callback is held back for 30 minutes (timestamps moved back)',
          'The shop finds the payment by polling before the callback arrives or accepts the late callback; '
          'order paid exactly once and delivered, not expired', 'critical')
def delayed(rec, ctx):
    email, c, oid, row = order_for(rec, 19)
    shop.pay(oid, deliver=False)
    env.sql(f"""update orders set created_at=created_at-interval '30 minutes', expires_at=expires_at-interval '30 minutes' where id={row['id']};
               update payments set created_at=created_at-interval '30 minutes', quoted_at=quoted_at-interval '30 minutes' where order_id={row['id']}""")
    rec.step('payment made, callback withheld, clock moved 30 minutes forward for this order')
    try:
        wait_status(oid, ('paid', 'delivered'), timeout=330)
        rec.step('reconciliation (polling every 5 minutes) found the payment')
    except AssertionError:
        rec.step('not found by polling within 5.5 minutes; sending the delayed callback')
    inv = invoice(oid)
    r = post_webhook(payload(inv, money(row['total'])))
    rec.check(r.status == 202, f'delayed callback answered {r.status}')
    wait_status(oid, 'delivered')
    rec.check(len([x for x in charges(oid) if x[0] == 'confirmed']) == 1, f'charges {charges(oid)}')
    check_ledger(rec, oid)


@scenario(2, 'Forged callbacks are rejected (401) and audited', 'buyer20 has a pending crypto order',
          'Unsigned, wrong-key, tampered-body, stale-timestamp and missing-header callbacks get 401, '
          'change nothing, and each leaves an audit entry; the order stays pending', 'blocker')
def forged(rec, ctx):
    email, c, oid, row = order_for(rec, 20)
    inv = invoice(oid)
    body = payload(inv, money(row['total']))
    audit_before = int(env.scalar("select count(*) from audit_log where action like 'webhook.%'"))
    ts = str(int(time.time()))
    good_sig = hmac.new(API_KEY.encode(), f'{ts}.{body}'.encode(), hashlib.sha256).hexdigest()
    attempts = {
        'unsigned': post_webhook(body, sign=False, who='attacker-1'),
        'wrong key': post_webhook(body, key='attacker-key-0000', who='attacker-2'),
        'stale timestamp': post_webhook(body, ts=int(time.time()) - 3600, who='attacker-3'),
        'tampered body': Client('attacker-4').raw('POST', '/webhooks/shkeeper', body.replace(money(row['total']), money(row['total'] * 10)).encode(),
                                                {'Content-Type': 'application/json', 'X-Shkeeper-Timestamp': ts, 'X-Shkeeper-Signature': good_sig}),
        'signature only': Client('attacker-5').raw('POST', '/webhooks/shkeeper', body.encode(), {'Content-Type': 'application/json', 'X-Shkeeper-Signature': good_sig}),
    }
    for name, r in attempts.items():
        rec.ev(f'{name}: HTTP {r.status} {r.text[:80]}')
        rec.check(r.status == 401, f'{name} callback answered {r.status}')
    ctx.allow_log(r'signature mismatch')
    rec.check(shop.order_row(oid)['status'] == 'pending', 'forged callback changed the order')
    rec.check(charges(oid)[0][0] == 'pending', f'charges {charges(oid)}')
    audits = int(env.scalar("select count(*) from audit_log where action like 'webhook.%'")) - audit_before
    rec.check(audits == len(attempts), f'{audits} audit entries for {len(attempts)} forged callbacks from {len(attempts)} addresses')
    flood = [post_webhook(body, key='attacker-key-0000', who='flooder').status for _ in range(10)]
    rec.check(flood == [401] * 10, f'flood statuses {flood}')
    rec.check(int(env.scalar("select count(*) from audit_log where action like 'webhook.%'")) - audit_before == len(attempts) + 1,
              'a flood from one address wrote more than one audit entry per minute')
    rec.check(env.scalar("select count(*) from gateway_logs where outcome='rejected_signature'") not in ('0', None), 'gateway log has no rejected entries')
    # A correctly signed callback for an order the gateway never invoiced is ignored.
    fake = json.loads(body)
    fake['external_id'] = '00000000-0000-4000-8000-000000000000'
    r = post_webhook(json.dumps(fake))
    rec.check(r.status == 202 and shop.order_row(fake['external_id']) is None, f'unknown order callback: {r.status}')
    env.sql(f"update orders set status='cancelled' where public_id='{oid}'")


@scenario(2, 'Malformed webhook bodies return 400, never 500', 'none',
          'Signed non-JSON, truncated JSON, a JSON array, an empty body and a huge body get 400 (or 413); nothing stored', 'major')
def malformed(rec, ctx):
    events = env.scalar('select count(*) from webhook_events')
    for name, body in [('not json', 'hello'), ('truncated', '{"external_id": "abc'), ('array', '[1,2,3]'), ('empty', ''),
                       ('number', '42'), ('nested junk', '{"external_id": {"$gt": ""}, "balance_fiat": [1]}')]:
        r = post_webhook(body)
        rec.ev(f'{name}: {r.status}')
        rec.check(r.status == 400, f'{name} body answered {r.status}')
    big = '{"pad": "' + 'A' * (3 * 1024 * 1024) + '"}'
    r = post_webhook(big)
    rec.check(r.status == 413, f'3 MB body answered {r.status}')
    ctx.allow_log(r'unparseable|not a JSON')
    rec.check(env.scalar('select count(*) from webhook_events') == events, 'malformed bodies were stored')


@scenario(2, 'Gateway timeout on invoice creation', 'buyer21 checks out while the gateway hangs on payment_request',
          'Buyer lands on the order page with a clear retry message after at most the client timeout; '
          'no confirmed charge; retrying later creates the invoice and the order can be paid', 'critical')
def invoice_timeout(rec, ctx):
    env.mock('/__mock/config', {'fail_next': [{'match': 'payment_request', 'mode': 'timeout', 'count': 1, 'seconds': 15}]})
    ctx.allow_log(r'Failed to create Shkeeper invoice|timed out|cURL error')
    email, c = buyer(21)
    p = instant_product()
    shop.add_to_cart(c, p)
    started = time.time()
    r, oid = shop.checkout(c, 'crypto', 'BTC')
    took = time.time() - started
    rec.ev(f'checkout took {took:.1f}s; landed on {r.path}: {flashes(r)}')
    rec.check(oid and r.path.endswith('/pay'), f'landed on {r.path}')
    rec.check('could not create an invoice' in flashes(r), f'message: {flashes(r)}')
    rec.check(took < 14, f'buyer waited {took:.1f}s')
    page = c.get(f'/orders/{oid}/pay')
    r = c.submit(c.find_form(page, f'/orders/{oid}/pay'), {'crypto': 'BTC'})
    rec.check('Invoice created' in flashes(r), f'retry: {flashes(r)}')
    shop.pay(oid)
    wait_status(oid, 'delivered')
    rec.check(len(charges(oid)) == 1, f'charges {charges(oid)}')
    rec.check(env.scalar("select count(*) from gateway_logs where channel='api' and outcome in ('timeout','error')") not in ('0', None), 'timeout not in the gateway log')


@scenario(2, 'Gateway timeout during callback processing', 'buyer22 pays while the order row is locked for 14 s (slow database)',
          'The first callback times out at the gateway (10 s); the gateway re-sends; the order is paid exactly once', 'critical')
def callback_timeout(rec, ctx):
    email, c, oid, row = order_for(rec, 22)
    locker = threading.Thread(target=lambda: env.sql(f"begin; select id from orders where id={row['id']} for update; select pg_sleep(14); commit;"))
    locker.start()
    time.sleep(0.5)
    rec.step('order row locked for 14 s by another transaction')
    r = shop.pay(oid, repeat=2)
    rec.ev(f"callback statuses {r['callback_http_statuses']}")
    locker.join()
    rec.check(r['callback_http_statuses'][0] in (0, 202), f"first callback {r['callback_http_statuses']}")
    wait_status(oid, 'delivered')
    time.sleep(2)
    rec.check(len([x for x in charges(oid) if x[0] == 'confirmed']) == 1, f'charges {charges(oid)}')
    check_ledger(rec, oid)
    rec.check(len(env.mails(email, 'paid')) == 1, 'paid email count')
    rec.check(len(items_of(oid)) >= 1, 'items')
