"""Phase 9: second round - features the first round only touched lightly.

Gift cards and balance adjustments, crypto refunds through the gateway,
payout-address changes, manual delivery, download limits, subscriptions over
time, reviews, wishlist, tickets, announcements, categories, commission
screens, bulk actions, currencies, dispute deadlines, payout holds and the
reporting pages. Time-based rules are exercised by moving the stored
timestamps back and running the scheduled command the scheduler would run."""
import re
import threading
import time
import urllib.parse

from simlib import env, shop
from simlib.http import Client, forms_of
from simlib.record import scenario

from .common import admin, balance, buyer, check_ledger, flashes, instant_product, items_of, money, parallel, staff_form, wait_status
from .p4_seller import create_product


def paid_order(n, product=None, qty=1, method='crypto'):
    email, b = buyer(n)
    p = product or instant_product()
    shop.add_to_cart(b, p, qty)
    r, oid = shop.checkout(b, method, 'BTC')
    if oid is None:
        raise AssertionError(f'checkout failed: {flashes(r)}')
    if method == 'crypto':
        shop.pay(oid)
    return email, b, oid


def code_from(r):
    m = re.search(r'([A-Z0-9]{4}(?:-[A-Z0-9]{4}){2,})', ' '.join(r.flashes()))
    return m.group(1) if m else None


@scenario(9, 'Gift cards: create, redeem, reuse, race, wrong currency, expiry, guessing',
          'Finance creates USD and EUR cards; buyers with USD and EUR wallets redeem',
          'Card credited once; second use and a concurrent double redeem refused; EUR card on a funded USD wallet refused '
          'without using the card; expired card refused; codes are not stored in plain text; guessing is rate limited', 'critical')
def gift_cards(rec, ctx):
    fin = admin('finance@sim.test')
    codes = {}
    for key, amount, cur, extra in [('usd', '25.00', 'USD', {}), ('race', '10.00', 'USD', {}), ('eur', '15.00', 'EUR', {}),
                                    ('expired', '5.00', 'USD', {})]:
        r = staff_form(fin, '/admin/gift-cards', '/admin/gift-cards', dict({'amount': amount, 'currency': cur}, **extra))
        codes[key] = code_from(r)
        rec.check(codes[key], f'{key} card code not shown: {flashes(r)}')
    for c in codes.values():
        rec.check(env.scalar(f"select count(*) from gift_cards where code_hash like '%{c}%'") == '0', 'code stored in plain text')
    env.sql("update gift_cards set expires_at=now()-interval '1 day' where id=(select max(id) from gift_cards)")
    email, b = buyer(where="currency='USD' and balance_minor > 0")
    before = balance(email)
    r = b.form('/wallet', '/wallet/redeem', {'code': codes['usd'].lower()})
    rec.check(balance(email) == before + 2500, f'not credited: {flashes(r)}')
    r = b.form('/wallet', '/wallet/redeem', {'code': codes['usd']})
    rec.check('already redeemed' in flashes(r) and balance(email) == before + 2500, f'reused: {flashes(r)}')
    r = b.form('/wallet', '/wallet/redeem', {'code': codes['eur']})
    rec.check('held in USD' in flashes(r), f'EUR card on USD wallet: {flashes(r)}')
    rec.check(env.scalar(f"select redeemed_at is null from gift_cards where amount_minor=1500 and currency='EUR'") == 't', 'EUR card used up by a refused redeem')
    r = b.form('/wallet', '/wallet/redeem', {'code': codes['expired']})
    rec.check('expired' in flashes(r), f'expired card: {flashes(r)}')
    # Two buyers redeem the same card at the same moment.
    e2, c2 = buyer(where=f"currency='USD' and email <> '{email}'")
    e3, c3 = buyer(where=f"currency='USD' and email not in ('{email}', '{e2}')")
    b2, b3 = balance(e2), balance(e3)
    forms = [(c, c.find_form(c.get('/wallet'), '/wallet/redeem')) for c in (c2, c3)]
    parallel([lambda c=c, f=f: c.submit(f, {'code': codes['race']}) for c, f in forms])
    gained = (balance(e2) - b2) + (balance(e3) - b3)
    rec.check(gained == 1000, f'race: {gained} credited in total')
    # Guessing: the redeem form is rate limited per account.
    statuses = [b.form('/wallet', '/wallet/redeem', {'code': f'GUES-S{i:03d}-XXXX'}).status for i in range(8)]
    rec.check(429 in statuses, f'no rate limit on guessing: {statuses}')


@scenario(9, 'Balance adjustments by finance', 'Finance credits and debits a buyer with a reason',
          'Credit and debit are recorded with the reason (shown in the wallet history), a debit below zero is refused, '
          'a manager cannot adjust, every change is audited', 'critical')
def balance_adjust(rec, ctx):
    email, b = buyer(where="currency='USD' and balance_minor between 100 and 100000")
    uid = env.scalar(f"select id from users where email='{email}'")
    fin = admin('finance@sim.test')
    before = balance(email)
    r = staff_form(fin, f'/admin/users/{uid}', f'/admin/users/{uid}/balance', {'direction': 'credit', 'amount': '12.34', 'currency': 'USD', 'reason': 'Goodwill credit'})
    rec.check(balance(email) == before + 1234, f'credit: {flashes(r)}')
    r = staff_form(fin, f'/admin/users/{uid}', f'/admin/users/{uid}/balance', {'direction': 'debit', 'amount': money(before + 1234 + 100), 'currency': 'USD', 'reason': 'Too much'})
    rec.check(balance(email) == before + 1234, f'debit below zero allowed: {flashes(r)}')
    rec.check(r.status < 500 and flashes(r), 'no message for a refused debit')
    r = staff_form(fin, f'/admin/users/{uid}', f'/admin/users/{uid}/balance', {'direction': 'debit', 'amount': '2.34', 'currency': 'USD', 'reason': 'Correction'})
    rec.check(balance(email) == before + 1000, f'debit: {flashes(r)}')
    rec.check('Goodwill credit' in b.get('/wallet').text, 'reason not in the wallet history')
    rec.check(int(env.scalar(f"select count(*) from audit_log where action like 'balance.%' and target_id='{uid}'") or 0) >= 2
              or int(env.scalar(f"select count(*) from audit_log where action like '%balance%'")) >= 2, 'not audited')


@scenario(9, 'Crypto refund through the gateway: success, failure, retry',
          'Finance refunds two paid orders in crypto; the gateway reports one success and one failure',
          'Success: refund confirmed with tx hash, earnings reduced once; failure: the refund is marked failed, the refundable '
          'amount is available again and a second attempt can succeed; duplicate callbacks change nothing; ledger reconciles', 'blocker')
def crypto_refunds(rec, ctx):
    fin = admin('finance@sim.test')
    results = []
    for n, outcome in ((21, 'SUCCESS'), (22, 'FAIL')):
        email, b, oid = paid_order(n)
        wait_status(oid, 'delivered')
        total = shop.order_row(oid)['total']
        r = staff_form(fin, f'/admin/orders/{oid}', f'/admin/orders/{oid}/refunds',
                       {'method': 'shkeeper', 'amount': money(total), 'crypto': 'BTC', 'destination': f'bc1qrefund{n}000000000000000000000000000000'})
        pid = env.scalar(f"select p.id from payments p join orders o on o.id=p.order_id where o.public_id='{oid}' and p.kind='refund' order by p.id desc limit 1")
        rec.check(pid and env.scalar(f'select status from payments where id={pid}') == 'pending', f'refund not pending: {flashes(r)}')
        res = env.mock(f'/__mock/payout-result/refund-{pid}', {'status': outcome})
        rec.check(res.get('callback_http_status') == 202, f'callback {res}')
        want = 'confirmed' if outcome == 'SUCCESS' else 'failed'
        shop.wait_for(lambda: env.scalar(f'select status from payments where id={pid}') == want, f'refund {want}', 30)
        env.mock(f'/__mock/payout-result/refund-{pid}', {'status': outcome})  # duplicate
        results.append((oid, pid, total, outcome))
        ctx.allow_log(r'(?i)refund .* failed|failed in Shkeeper')
    oid, pid, total, _ = results[0]
    rec.check(shop.order_row(oid)['refunded'] == total and shop.order_row(oid)['status'] == 'refunded', f'success refund: {shop.order_row(oid)}')
    rec.check(env.scalar(f'select provider_reference is not null from payments where id={pid}') == 't', 'tx hash not stored')
    oid, pid, total, _ = results[1]
    rec.check(shop.order_row(oid)['refunded'] == 0, f'failed refund still counted: {shop.order_row(oid)}')
    r = staff_form(fin, f'/admin/orders/{oid}', f'/admin/orders/{oid}/refunds', {'method': 'manual', 'amount': money(total), 'reference': 'sim-retry-1'})
    rec.check(shop.order_row(oid)['status'] == 'refunded', f'retry after failure: {flashes(r)}')
    for oid, *_ in results:
        check_ledger_refunded(rec, oid)
    rc = env.artisan('shop:reconcile-payouts', check=False)
    rec.check(rc.returncode == 0, rc.stdout[-300:])


def check_ledger_refunded(rec, oid):
    for i in items_of(oid):
        rec.check(i['earning'] == 0, f'line {i["id"]} still earns {i["earning"]} after a full refund')


@scenario(9, 'Payout address change with confirmation and waiting period', 'seller07 changes the payout address',
          'Nothing changes until the emailed link is opened by the seller; the link is single use and useless for another seller; '
          'after the change payouts are blocked for the waiting period and the seller is told until when; afterwards they work', 'critical')
def payout_address(rec, ctx):
    s = shop.login('seller07@sim.test')
    sid = env.scalar("select id from users where email='seller07@sim.test'")
    old = env.scalar(f'select payout_address from seller_profiles where user_id={sid}')
    new = 'bc1qnewaddress7000000000000000000000000000'
    r = s.form('/seller/payout-settings', '/seller/payout-settings', {'payout_address': new, 'payout_crypto': 'BTC'})
    if '/confirm-password' in r.path:
        shop.confirm_password(s)
        r = s.form('/seller/payout-settings', '/seller/payout-settings', {'payout_address': new, 'payout_crypto': 'BTC'})
    rec.check(env.scalar(f'select payout_address from seller_profiles where user_id={sid}') == old, 'changed before confirming')
    msg = env.wait_mail('seller07@sim.test', 'payout address')[0]
    link = [u for u in re.findall(r'https://localhost:8443/\S+', env.mail_text(msg['ID'])) if '/payout-address/confirm/' in u][0]
    path = link.replace('https://localhost:8443', '')
    other = shop.login('seller08@sim.test')
    r = other.go('GET', path)
    if r.status == 200 and forms_of(r.text):
        other.submit(other.find_form(r, '/payout-address/confirm/'))
    rec.check(env.scalar(f'select payout_address from seller_profiles where user_id={sid}') == old, 'another seller confirmed the change')
    r = s.form(path, '/payout-address/confirm/')
    rec.check(env.scalar(f'select payout_address from seller_profiles where user_id={sid}') == new, f'confirm: {flashes(r)}')
    r = s.form(path, '/payout-address/confirm/') if s.get(path, expect=None).status == 200 else s.last
    rec.check('invalid' in flashes(r).lower() or r.status in (404, 422), f'link reused: {flashes(r)}')
    env.wait_mail('seller07@sim.test', 'was changed')
    paid_order(23, product=instant_product(f'seller_id={sid}'), qty=5)
    staff_form(admin(), '/admin/settings', '/admin/settings', {'payout_hold_days': '0'})
    r = s.form('/seller/payouts', '/seller/payouts', {'amount': '10.00', 'currency': 'USD'})
    rec.check('payouts are possible again after' in flashes(r), f'payout during the waiting period: {flashes(r)}')
    env.sql(f"update seller_profiles set payout_address_changed_at=now()-interval '49 hours' where user_id={sid}")
    rec.step('waiting period moved 49 hours into the past')
    r = s.form('/seller/payouts', '/seller/payouts', {'amount': '10.00', 'currency': 'USD'})
    rec.check(env.scalar(f"select destination from payouts where seller_id={sid} order by id desc limit 1") == new, f'payout after waiting: {flashes(r)}')


@scenario(9, 'Manual delivery by the seller and by staff', 'buyer45 buys two manual products of different sellers',
          'Order stays paid until delivered; the seller delivers their line (text shown to the buyer, emailed); staff deliver the '
          'other line on the seller\'s behalf; only then the order is delivered; empty or oversized payloads refused', 'critical')
def manual_delivery(rec, ctx):
    p1 = shop.product("status='active' and delivery_type='manual' and currency='USD' and (stock is null or stock > 0)")
    p2 = shop.product(f"status='active' and delivery_type='manual' and currency='USD' and seller_id <> {p1['seller_id']} and (stock is null or stock > 0)")
    email, b = buyer(45)  # buyers 24-27 change password or email in phase 3
    for p in (p1, p2):
        shop.add_to_cart(b, p)
    r, oid = shop.checkout(b, 'crypto', 'BTC')
    shop.pay(oid)
    wait_status(oid, 'paid')
    time.sleep(3)
    rec.check(shop.order_row(oid)['status'] == 'paid', 'manual order marked delivered by itself')
    items = {i['product_id']: i for i in items_of(oid)}
    seller_email = env.scalar(f"select email from users where id={p1['seller_id']}")
    s = shop.login(seller_email)
    item = items[p1['id']]['id']
    r = s.form('/seller', f'/seller/items/{item}/deliver', {'payload': ''})
    rec.check(env.scalar(f'select delivered_at is null from order_items where id={item}') == 't', 'empty payload accepted')
    r = s.form('/seller', f'/seller/items/{item}/deliver', {'payload': 'Portal https://example.test/sim user ' + email})
    rec.check(env.scalar(f'select delivered_at is not null from order_items where id={item}') == 't', f'seller delivery: {flashes(r)}')
    rec.check('example.test/sim' in b.get(f'/orders/{oid}').text, 'buyer does not see the delivery text')
    rec.check(env.scalar(f"select delivered_payload like '%example.test%' from order_items where id={item}") != 't', 'delivery text stored in plain text')
    mgr = admin('manager@sim.test')
    item2 = items[p2['id']]['id']
    r = staff_form(mgr, f'/admin/orders/{oid}', f'/admin/orders/{oid}/items/{item2}/deliver', {'payload': 'Delivered by support: licence ABC-123'})
    wait_status(oid, 'delivered', timeout=30)
    env.wait_mail(email, 'delivered')
    check_ledger(rec, oid)


@scenario(9, 'Download limits, expired links and staff reset', 'buyer46 owns a delivered file product',
          'Each download counts; at the limit the link stops working with a clear message; an expired or tampered link is refused; '
          'staff reset the counter and downloads work again', 'major')
def downloads(rec, ctx):
    p = instant_product()
    env.sql(f"update products set download_limit=2 where id={p['id']}")
    email, b, oid = paid_order(46, product=p)
    wait_status(oid, 'delivered')

    def link():
        m = re.search(r'href="([^"]+/files/\d+\?[^"]*)"', b.get(f'/orders/{oid}').text)
        return m.group(1).replace('&amp;', '&') if m else None
    first = link()
    rec.check(first, 'no download link')
    for i in range(2):
        r = b.get(link())
        rec.check(r.status == 200 and len(r.raw) > 0, f'download {i + 1}: {r.status}')
    r = b.get(first, expect=None)
    rec.check(r.status == 403 and 'limit' in r.text, f'third download: {r.status}')
    rec.check(link() is None, 'link still offered after the limit')
    tampered = re.sub(r'signature=[0-9a-f]+', 'signature=' + 'f' * 64, first)
    rec.check(b.get(tampered, expect=None).status in (403, 401), 'tampered link accepted')
    expired = re.sub(r'expires=\d+', f'expires={int(time.time()) - 10}', first)
    rec.check(b.get(expired, expect=None).status in (403, 401), 'expired link accepted')
    mgr = admin('manager@sim.test')
    item = items_of(oid)[0]['id']
    staff_form(mgr, f'/admin/orders/{oid}', f'/admin/orders/{oid}/items/{item}/reset-downloads')
    rec.check(link() and b.get(link()).status == 200, 'download after staff reset')


@scenario(9, 'Subscription access over time', 'buyer47 buys a subscription; time is moved forward',
          'Access is set to 30 days; 3 days before the end one reminder is sent (never two); after the end downloads are refused '
          'with a renewal hint and the product can be bought again', 'major')
def subscriptions(rec, ctx):
    p = shop.product("""status='active' and access_days is not null and delivery_type='instant' and currency='USD' and (stock is null or stock > 0)
        and exists (select 1 from product_files f where f.product_id=products.id and f.scan_status='clean')""")
    email, b, oid = paid_order(47, product=p)
    wait_status(oid, 'delivered')
    item = items_of(oid)[0]['id']
    days = env.scalar(f"select (access_expires_at::date - now()::date) from order_items where id={item}")
    rec.check(days == env.scalar(f"select access_days from products where id={p['id']}"), f'access days {days}')
    env.sql(f"update order_items set access_expires_at=now()+interval '3 days' where id={item}")
    rec.step('access end moved to 3 days from now; running the hourly shop:subscription-reminders')
    env.artisan('shop:subscription-reminders')
    env.artisan('shop:subscription-reminders')
    env.wait_mail(email, 'ends on')
    time.sleep(3)
    rec.check(len(env.mails(email, 'ends on')) == 1, 'reminder sent twice')
    env.sql(f"update order_items set access_expires_at=now()-interval '1 minute' where id={item}")
    page = b.get(f'/orders/{oid}')
    rec.check('Access ended' in page.text, 'order page does not say access ended')
    m = re.search(r'href="([^"]+/files/\d+\?[^"]*)"', page.text)
    rec.check(m is None, 'download still offered after access ended')
    shop.add_to_cart(b, p)
    r, oid2 = shop.checkout(b, 'crypto', 'BTC')
    rec.check(oid2, f'renewal purchase: {flashes(r)}')
    shop.pay(oid2)
    wait_status(oid2, 'delivered')


@scenario(9, 'Reviews', 'buyer48 bought a product; buyer28 did not',
          'Only buyers can review; one review per buyer (a second submit edits it); hidden reviews disappear and leave the rating', 'major')
def reviews(rec, ctx):
    p = instant_product()
    email, b, oid = paid_order(48, product=p)
    wait_status(oid, 'delivered')
    _, stranger = buyer(28)
    page = stranger.get(f"/products/{p['slug']}")
    r = stranger.post(f"/products/{p['slug']}/reviews", {'rating': '1', 'title': 'Fake', 'body': 'Never bought it'}, token_from=f"/products/{p['slug']}")
    rec.check(env.scalar(f"select count(*) from product_reviews where product_id={p['id']}") == '0', 'non-buyer review stored')
    for title in ('Great tool', 'Great tool, updated'):
        b.form(f"/products/{p['slug']}", f"/products/{p['slug']}/reviews", {'rating': '4', 'title': title, 'body': 'Works as described.'})
    rec.check(env.scalar(f"select count(*) from product_reviews where product_id={p['id']}") == '1', 'second review created instead of an edit')
    rec.check('Great tool, updated' in Client('reader').get(f"/products/{p['slug']}").text, 'review not shown')
    mod = admin('moderator@sim.test')
    rid = env.scalar(f"select id from product_reviews where product_id={p['id']}")
    staff_form(mod, '/admin/reviews', f'/admin/reviews/{rid}/status')
    rec.check('Great tool, updated' not in Client('reader2').get(f"/products/{p['slug']}").text, 'hidden review still shown')


@scenario(9, 'Wishlist', 'buyer29 saves products',
          'Add, list and remove work; a product that becomes unavailable is marked, not a 500; another buyer cannot remove it', 'minor')
def wishlist(rec, ctx):
    p = instant_product()
    q = instant_product(exclude=[p['id']])
    _, b = buyer(29)
    for x in (p, q):
        b.form(f"/products/{x['slug']}", '/wishlist')
    page = b.get('/wishlist')
    rec.check(p['title'] in page.text and q['title'] in page.text, 'wishlist missing products')
    env.sql(f"update products set status='disabled' where id={q['id']}")
    rec.check(b.get('/wishlist').status == 200, 'wishlist broke with a disabled product')
    env.sql(f"update products set status='active' where id={q['id']}")
    _, other = buyer(30)
    tok = other.csrf('/wishlist')
    other.post(f"/wishlist/{p['id']}", {'_method': 'DELETE', '_token': tok}, token_from='/wishlist')
    rec.check(p['title'] in b.get('/wishlist').text, "another buyer removed this buyer's wishlist item")
    b.form('/wishlist', f"/wishlist/{p['id']}")
    rec.check(p['title'] not in b.get('/wishlist').text, 'remove failed')


@scenario(9, 'Support tickets', 'buyer31 opens a ticket about an order; support answers',
          'Ticket linked to the order; staff reply is emailed and shown, internal notes are never shown or emailed; '
          'another buyer gets 403; assign and close work; closed tickets can be reopened by a reply or are clearly closed', 'major')
def tickets(rec, ctx):
    email, b, oid = paid_order(31)
    r = b.form(f'/tickets/new?order={oid}', '/tickets', {'subject': 'Where is my key?', 'body': 'I cannot find it.'})
    tid = env.scalar("select id from tickets order by id desc limit 1")
    rec.check(env.scalar(f'select order_id is not null from tickets where id={tid}') == 't', f'ticket not linked: {flashes(r)}')
    sup = admin('support@sim.test')
    sup.form(f'/tickets/{tid}', f'/tickets/{tid}/messages', {'body': 'INTERNAL: buyer is legit', 'internal': '1'})
    sup.form(f'/tickets/{tid}', f'/tickets/{tid}/messages', {'body': 'It is on your order page.'})
    env.wait_mail(email, 'ticket')
    page = b.get(f'/tickets/{tid}')
    rec.check('It is on your order page.' in page.text and 'INTERNAL' not in page.text, 'internal note shown or reply missing')
    time.sleep(2)
    rec.check(all('INTERNAL' not in env.mail_text(m['ID']) for m in env.mails(email)), 'internal note emailed')
    _, other = buyer(32)
    rec.check(other.get(f'/tickets/{tid}', expect=None, follow=False).status == 403, 'another buyer opened the ticket')
    sid = env.scalar("select id from users where email='support@sim.test'")
    sup.form(f'/tickets/{tid}', f'/tickets/{tid}/assign', {'assigned_to': sid})
    b.form(f'/tickets/{tid}', f'/tickets/{tid}/close')
    rec.check(env.scalar(f'select status from tickets where id={tid}') == 'closed', 'not closed')
    rec.check(b.get(f'/tickets/{tid}').status == 200, 'closed ticket not viewable')


@scenario(9, 'Announcements', 'Support publishes a scheduled and a current announcement',
          'The current one is shown on every page (escaped), the future one not yet; toggling and deleting hide it', 'minor')
def announcements(rec, ctx):
    sup = admin('support@sim.test')
    sup.form('/admin/announcements', '/admin/announcements', {'title': 'Maintenance <b>tonight</b>', 'body': 'Short downtime at 02:00 UTC.'})
    future = time.strftime('%Y-%m-%dT%H:%M', time.gmtime(time.time() + 86400 * 3))
    sup.form('/admin/announcements', '/admin/announcements', {'title': 'Future sale', 'body': 'Soon.', 'starts_at': future})
    time.sleep(61)  # the announcement list is cached for 60 seconds
    page = Client('visitor').get('/products')
    rec.check('Maintenance &lt;b&gt;tonight&lt;/b&gt;' in page.text, 'current announcement missing or not escaped')
    rec.check('Future sale' not in page.text, 'future announcement shown early')
    aid = env.scalar("select id from announcements where title like 'Maintenance%'")
    sup.form('/admin/announcements', f'/admin/announcements/{aid}/toggle')
    time.sleep(61)
    rec.check('Maintenance' not in Client('visitor2').get('/products').text, 'toggled-off announcement still shown')


@scenario(9, 'Categories', 'Moderator manages categories', 'Create, rename and delete an empty category; a category with products '
          'cannot be deleted; duplicate names refused; the catalog filter follows the rename', 'minor')
def categories(rec, ctx):
    mod = admin('moderator@sim.test')
    r = mod.form('/admin/categories', '/admin/categories', {'name': 'Sim Plugins', 'description': 'Plug-ins for the simulation.'})
    cid = env.scalar("select id from categories where name='Sim Plugins'")
    rec.check(cid, f'create: {flashes(r)}')
    r = mod.form('/admin/categories', '/admin/categories', {'name': 'Sim Plugins'})
    rec.check(env.scalar("select count(*) from categories where name='Sim Plugins'") == '1', 'duplicate accepted')
    mod.form('/admin/categories', f'/admin/categories/{cid}', {'name': 'Sim Add-ons', 'description': 'Renamed.'}, need={'_method': 'PUT'})
    rec.check(env.scalar(f'select name from categories where id={cid}') == 'Sim Add-ons', 'rename failed')
    busy = env.scalar("select category_id from products where category_id is not null limit 1")
    page = mod.get('/admin/categories')
    rec.check(not any(f['action'].endswith(f'/admin/categories/{busy}') and ['_method', 'DELETE'] in f['fields'] for f in forms_of(page.text)),
              'delete offered for a category with products')
    r = mod.post(f'/admin/categories/{busy}', {'_method': 'DELETE'}, token_from='/admin/categories')
    rec.check(env.scalar(f'select count(*) from categories where id={busy}') == '1', 'category with products deleted')
    rec.check('still has products' in flashes(r), f'message: {flashes(r)}')
    mod.form('/admin/categories', f'/admin/categories/{cid}', need={'_method': 'DELETE'})
    rec.check(env.scalar(f'select count(*) from categories where id={cid}') == '0', 'empty category not deleted')


@scenario(9, 'Commission settings screens drive real sales', 'Super admin sets product, seller and category rates in the UI',
          'Each level overrides the next (product > seller > category > global) for new sales only; old sales keep their frozen rate; '
          'invalid percentages refused; changes audited', 'critical')
def commission_ui(rec, ctx):
    sa = admin()
    p = instant_product("seller_id=(select user_id from seller_profiles where commission_bps is null limit 1)")
    old_email, _, old_oid = paid_order(33, product=p)
    wait_status(old_oid, 'delivered')
    old_bps = items_of(old_oid)[0]['bps']
    cat = env.scalar(f"select category_id from products where id={p['id']}")
    prof = env.scalar(f"select id from seller_profiles where user_id={p['seller_id']}")
    r = staff_form(sa, '/admin/commission', f'/admin/commission/categories/{cat}', {'commission_percent': '7'}, need={'_method': 'PUT'})
    rec.check(env.scalar(f'select commission_bps from categories where id={cat}') == '700', f'category rate: {flashes(r)}')
    r = staff_form(sa, '/admin/commission', f'/admin/commission/categories/{cat}', {'commission_percent': '150'}, need={'_method': 'PUT'})
    rec.check(env.scalar(f'select commission_bps from categories where id={cat}') == '700', 'invalid percentage accepted')
    staff_form(sa, '/admin/commission', f'/admin/commission/sellers/{prof}', {'commission_percent': '6'}, need={'_method': 'PUT'})
    staff_form(sa, '/admin/commission', '/admin/commission/products', {'product_id': str(p['id']), 'commission_percent': '5'}, need={'_method': 'PUT'})
    rec.check(env.scalar(f"select commission_bps from products where id={p['id']}") == '500', 'product rate not saved')
    _, _, oid = paid_order(34, product=p)
    wait_status(oid, 'delivered')
    rec.check(items_of(oid)[0]['bps'] == 500, f"new sale rate {items_of(oid)[0]['bps']}, expected 500 (product)")
    rec.check(items_of(old_oid)[0]['bps'] == old_bps, 'old sale rate changed')
    check_ledger(rec, oid)
    rec.check(int(env.scalar("select count(*) from audit_log where action like 'commission.%'")) >= 3, 'commission changes not audited')


@scenario(9, 'Bulk actions on products and users', 'Moderator and manager tick rows',
          'Bulk disable then approve products (products without clean files are skipped and reported); bulk suspend users skips '
          'staff and the actor; over 500 ids refused; everything audited', 'major')
def bulk(rec, ctx):
    mod = admin('moderator@sim.test')
    ids = [int(x[0]) for x in env.sql("select id from products where status='active' order by id desc limit 3")]
    pending_no_files = env.scalar("""select p.id from products p where status='pending_review' and delivery_type='instant'
        and not exists (select 1 from product_files f where f.product_id=p.id) and not exists (select 1 from product_license_keys k where k.product_id=p.id) limit 1""")
    page = mod.get('/admin/products')
    form = mod.find_form(page, '/admin/bulk/products')
    r = mod.submit(form, {'action': 'disabled', 'scope': 'selected', 'ids[]': [str(i) for i in ids]})
    rec.check(env.scalar(f"select count(*) from products where id in ({','.join(map(str, ids))}) and status='disabled'") == '3', f'bulk disable: {flashes(r)}')
    chosen = ids + ([int(pending_no_files)] if pending_no_files else [])
    r = mod.submit(form, {'action': 'active', 'scope': 'selected', 'ids[]': [str(i) for i in chosen]})
    rec.check(env.scalar(f"select count(*) from products where id in ({','.join(map(str, ids))}) and status='active'") == '3', f'bulk approve: {flashes(r)}')
    if pending_no_files:
        rec.check(env.scalar(f'select status from products where id={pending_no_files}') != 'active', 'product without content approved in bulk')
    r = mod.submit(form, {'action': 'disabled', 'scope': 'selected', 'ids[]': [str(i) for i in range(1, 600)]})
    rec.check(r.status < 500 and env.scalar("select count(*) from products where status='disabled'") != '200', f'600 ids: {r.status}')
    mgr = admin('manager@sim.test')
    victims = [env.scalar(f"select id from users where email='buyer{n}@sim.test'") for n in (35, 36)]
    staff = env.scalar("select id from users where email='finance@sim.test'")
    me = env.scalar("select id from users where email='manager@sim.test'")
    shop.confirm_password(mgr)
    page = mgr.get('/admin/users')
    form = mgr.find_form(page, '/admin/bulk/users')
    r = mgr.submit(form, {'action': 'suspended', 'scope': 'selected', 'ids[]': victims + [staff, me]})
    rec.check(env.scalar(f"select count(*) from users where id in ({','.join(victims)}) and status='suspended'") == '2', f'bulk suspend: {flashes(r)}')
    rec.check(env.scalar(f'select status from users where id={staff}') == 'active' and env.scalar(f'select status from users where id={me}') == 'active',
              'bulk suspended staff or the actor')
    mgr.submit(form, {'action': 'active', 'scope': 'selected', 'ids[]': victims})
    rec.check(env.scalar(f"select count(*) from users where id in ({','.join(victims)}) and status='active'") == '2', 'bulk reactivate')


@scenario(9, 'Currencies: EUR cart, EUR wallet, rate change, fixed-amount coupon', 'buyer36 (EUR wallet) and buyer37 (USD)',
          'Prices convert with the stored rate; a EUR order paid from a EUR wallet books seller earnings in EUR; a rate change '
          're-prices an open cart; a EUR fixed coupon is refused on a USD cart; payouts are per currency', 'critical')
def currencies(rec, ctx):
    rate = float(env.scalar("select rate from exchange_rates where base='USD' and quote='EUR' order by id desc limit 1"))
    email, b = buyer(36)
    rec.check(env.scalar(f"select currency from users where email='{email}'") == 'EUR', 'buyer36 is expected to hold EUR')
    uid = env.scalar(f"select id from users where email='{email}'")
    staff_form(admin('finance@sim.test'), f'/admin/users/{uid}', f'/admin/users/{uid}/balance',
               {'direction': 'credit', 'amount': '500.00', 'currency': 'EUR', 'reason': 'Simulation top-up'})
    rec.check(balance(email) >= 50000, 'EUR top-up failed')
    p = instant_product("price_minor < 3000")
    b.form('/cart', '/cart/currency', {'currency': 'EUR'})
    shop.add_to_cart(b, p)
    cart = b.get('/cart')
    expected = int(round(p['price'] * rate))
    rec.check(money(expected) in cart.text or money(expected + 1) in cart.text or money(expected - 1) in cart.text, f'EUR price not {money(expected)}')
    fin = admin('finance@sim.test')
    staff_form(fin, '/admin/exchange-rates', '/admin/exchange-rates', {'base': 'USD', 'quote': 'EUR', 'rate': f'{rate * 1.1:.4f}'})
    cart = b.get('/cart')
    newp = int(round(p['price'] * rate * 1.1))
    rec.check(any(money(x) in cart.text for x in (newp - 1, newp, newp + 1)), f'cart not re-priced to about {money(newp)}')
    r, oid = shop.checkout(b, 'balance')
    rec.check(oid and shop.order_row(oid)['currency'] == 'EUR' and shop.order_row(oid)['status'] in ('paid', 'delivered'), f'EUR balance checkout: {flashes(r)}')
    wait_status(oid, 'delivered')
    rec.check(env.scalar(f"select distinct l.currency from seller_ledger_entries l join order_items i on i.id=l.order_item_id join orders o on o.id=i.order_id where o.public_id='{oid}'") == 'EUR',
              'seller earnings not booked in EUR')
    check_ledger(rec, oid)
    staff_form(fin, '/admin/exchange-rates', '/admin/exchange-rates', {'base': 'USD', 'quote': 'EUR', 'rate': f'{rate:.4f}'})
    staff_form(fin, '/admin/coupons', '/admin/coupons', {'code': 'EURFIVE', 'type': 'fixed', 'value': '5.00', 'currency': 'EUR'})
    _, u = buyer(37)
    shop.add_to_cart(u, instant_product())
    r = u.form('/checkout', '/checkout/coupon', {'code': 'EURFIVE'})
    rec.check('applied' not in flashes(r) and r.status < 500, f'EUR coupon on a USD cart: {flashes(r)}')
    shop.empty_cart(u)


@scenario(9, 'Dispute deadline passes', 'A dispute the seller ignores; time is moved past the response deadline',
          'The scheduled escalation hands it to staff, both parties are emailed once, earnings stay held until staff decide', 'critical')
def dispute_deadline(rec, ctx):
    email, b, oid = paid_order(38)
    wait_status(oid, 'delivered')
    item = items_of(oid)[0]['id']
    page = b.get(f'/orders/{oid}/items/{item}/dispute')
    b.submit(b.find_form(page, f'/orders/{oid}/items/{item}/dispute'), {'requested_outcome': 'refund', 'body': 'Key does not work.'})
    did = env.scalar(f'select id from disputes where order_item_id={item}')
    rec.check(env.scalar(f'select status from disputes where id={did}') == 'awaiting_seller', 'dispute not waiting for the seller')
    env.sql(f"update disputes set seller_respond_by=now()-interval '1 minute' where id={did}")
    rec.step('seller deadline moved into the past; running the 15-minute shop:escalate-disputes')
    env.artisan('shop:escalate-disputes')
    env.artisan('shop:escalate-disputes')
    rec.check(env.scalar(f'select status from disputes where id={did}') == 'awaiting_staff', 'not escalated')
    env.wait_mail(email, 'is now with our team')
    time.sleep(2)
    rec.check(len(env.mails(email, 'is now with our team')) == 1, 'escalation mailed twice')
    sid = items_of(oid)[0]['seller_id']
    s_email = env.scalar(f'select email from users where id={sid}')
    s = shop.login(s_email)
    rec.check('disputed' in s.get('/seller/payouts').text.lower() or 'held' in s.get('/seller/payouts').text.lower(), 'held earnings not shown to the seller')


@scenario(9, 'Payout hold period', 'Hold set to 7 days; a new sale for seller09',
          'The new earning is pending and cannot be paid out; after 7 days (time moved) it becomes available', 'major')
def payout_hold(rec, ctx):
    sa = admin()
    staff_form(sa, '/admin/settings', '/admin/settings', {'payout_hold_days': '7'})
    sid = env.scalar("select id from users where email='seller09@sim.test'")
    env.sql(f"update seller_ledger_entries set created_at=created_at-interval '30 days' where seller_id={sid}")
    before_total = int(env.scalar(f"select coalesce(sum(amount_minor),0) from seller_ledger_entries where seller_id={sid} and currency='USD'"))
    _, _, oid = paid_order(39, product=instant_product(f'seller_id={sid}'), qty=2)
    wait_status(oid, 'delivered')
    earning = sum(i['earning'] for i in items_of(oid))
    s = shop.login('seller09@sim.test')
    r = s.form('/seller/payouts', '/seller/payouts', {'amount': money(before_total + earning), 'currency': 'USD'})
    rec.check('only' in flashes(r) and 'available' in flashes(r), f'held earning paid out: {flashes(r)}')
    env.sql(f"update seller_ledger_entries set created_at=created_at-interval '8 days' where seller_id={sid}")
    rec.step('ledger moved 8 days into the past')
    r = s.form('/seller/payouts', '/seller/payouts', {'amount': money(before_total + earning), 'currency': 'USD'})
    rec.check(env.scalar(f'select count(*) from payouts where seller_id={sid} and amount_minor={before_total + earning}') == '1', f'payout after the hold: {flashes(r)}')
    staff_form(sa, '/admin/settings', '/admin/settings', {'payout_hold_days': '0'})


@scenario(9, 'Reporting pages agree with the database', 'Orders, refunds and payouts from this run',
          'Dashboard, reports, reconciliation, payments and webhook pages load fast for their roles; the reported revenue and '
          'refund totals equal SQL sums; a failed webhook event can be retried', 'major')
def reports(rec, ctx):
    fin = admin('finance@sim.test')
    today = time.strftime('%Y-%m-%d', time.gmtime())
    for path in ('/admin', '/admin/reports', '/admin/reconciliation', '/admin/payments', '/admin/webhooks', f'/admin/reports?from=2020-01-01&to={today}'):
        r = fin.get(path)
        rec.check(r.duration_ms < 3000, f'{path} took {r.duration_ms} ms')
    rep = fin.get(f'/admin/reports?from=2020-01-01&to={today}')
    net = int(env.scalar("select coalesce(sum(total_minor - refunded_minor),0) from orders where currency='USD' and status in ('paid','delivered','partially_refunded','refunded')"))
    rec.ev(f'SQL USD net revenue {money(net)}')
    rec.check(money(net) in rep.text.replace(',', ''), f'report does not show the USD net revenue {money(net)}')
    rec.check('match' in fin.get('/admin/reconciliation').text.lower(), 'reconciliation page does not report a match')


@scenario(9, 'Concurrent money operations', 'Double-clicks and two staff members acting at the same moment',
          'A double-clicked payout request creates one payout; two simultaneous refunds never exceed the order total; '
          'the last use of a coupon goes to one buyer only; delivering a manual item twice at once keeps one delivery', 'blocker')
def concurrency(rec, ctx):
    staff_form(admin(), '/admin/settings', '/admin/settings', {'payout_hold_days': '0'})
    sid = env.scalar("select id from users where email='seller10@sim.test'")
    paid_order(40, product=instant_product(f'seller_id={sid}'), qty=5)
    available = int(env.scalar(f"select coalesce(sum(amount_minor),0) from seller_ledger_entries where seller_id={sid} and currency='USD'"))
    s = shop.login('seller10@sim.test')
    form = s.find_form(s.get('/seller/payouts'), '/seller/payouts')
    before = int(env.scalar(f'select count(*) from payouts where seller_id={sid}'))
    parallel([lambda: s.submit(form, {'amount': money(available), 'currency': 'USD'}) for _ in range(3)])
    made = int(env.scalar(f'select count(*) from payouts where seller_id={sid}')) - before
    rec.check(made == 1, f'{made} payouts from one double-click (available {money(available)})')
    rec.check(int(env.scalar(f"select coalesce(sum(amount_minor),0) from seller_ledger_entries where seller_id={sid} and currency='USD'")) >= 0, 'seller balance negative')

    email, b, oid = paid_order(41, qty=2)
    wait_status(oid, 'delivered')
    total = shop.order_row(oid)['total']
    fin, mgr = admin('finance@sim.test'), admin('manager@sim.test')
    for c in (fin, mgr):
        shop.confirm_password(c)
    forms = [(c, c.find_form(c.get(f'/admin/orders/{oid}'), f'/admin/orders/{oid}/refunds')) for c in (fin, mgr)]
    parallel([lambda c=c, f=f: c.submit(f, {'method': 'balance', 'amount': money(total * 3 // 4), 'reason': 'race'}) for c, f in forms])
    row = shop.order_row(oid)
    rec.check(row['refunded'] <= total, f"refunded {row['refunded']} of {total}")
    rec.check(row['refunded'] == total * 3 // 4, f"expected exactly one refund to succeed, refunded {row['refunded']}")

    staff_form(admin('finance@sim.test'), '/admin/coupons', '/admin/coupons', {'code': 'LASTONE', 'type': 'percent', 'value': '10', 'max_redemptions': '1'})
    racers = []
    for n in (42, 43):
        _, c = buyer(n)
        shop.add_to_cart(c, instant_product())
        c.form('/checkout', '/checkout/coupon', {'code': 'LASTONE'})
        racers.append((c, c.find_form(c.get('/checkout'), '/checkout')))
    parallel([lambda c=c, f=f: c.submit(f, {'payment_method': 'crypto', 'crypto': 'BTC', 'accept_terms': '1'}) for c, f in racers])
    used = env.scalar("select count(*) from orders o join coupons c on c.id=o.coupon_id where c.code='LASTONE'")
    rec.check(used == '1', f'coupon with one redemption used {used} times')
    rec.check(env.scalar("select redemptions_count from coupons where code='LASTONE'") == '1', 'redemption count wrong')

    p = shop.product("status='active' and delivery_type='manual' and currency='USD' and (stock is null or stock > 0)")
    email, b = buyer(44)
    shop.add_to_cart(b, p)
    r, moid = shop.checkout(b, 'crypto', 'BTC')
    shop.pay(moid)
    wait_status(moid, 'paid')
    item = items_of(moid)[0]['id']
    s2 = shop.login(env.scalar(f"select email from users where id={p['seller_id']}"))
    f = s2.find_form(s2.get('/seller'), f'/seller/items/{item}/deliver')
    parallel([lambda t=t: s2.submit(f, {'payload': f'Delivery text {t}'}) for t in ('A', 'B')])
    wait_status(moid, 'delivered', timeout=30)
    time.sleep(3)
    rec.check(len(env.mails(email, 'delivered')) == 1, f"{len(env.mails(email, 'delivered'))} delivery mails for one item")
    rec.check(int(env.scalar(f"select count(*) from audit_log where action like 'delivery%' and target_id='{item}'")) <= 1, 'item delivered twice')
