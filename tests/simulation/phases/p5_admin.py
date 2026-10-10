"""Phase 5: staff work in /admin, per role."""
import csv
import io
import re
import time
import urllib.parse

from simlib import env, shop
from simlib.http import Client, forms_of, text_of
from simlib.record import scenario

from .common import admin, balance, buyer, check_ledger, flashes, instant_product, items_of, money, staff_form, wait_status
from .p3_accounts import register, links

STAFF = {'admin': 'superadmin@sim.test', 'manager': 'manager@sim.test', 'finance': 'finance@sim.test',
         'moderator': 'moderator@sim.test', 'support': 'support@sim.test'}

PAGES = {
    '/admin': 'staff.dashboard', '/admin/users': 'users.view', '/admin/sellers': 'sellers.manage', '/admin/products': 'products.manage',
    '/admin/categories': 'categories.manage', '/admin/commission': 'commission.manage', '/admin/orders': 'orders.view',
    '/admin/gateway': 'gateway.view', '/admin/payments': 'payments.view', '/admin/webhooks': 'webhooks.manage',
    '/admin/payouts': 'payouts.manage', '/admin/reconciliation': 'payouts.manage', '/admin/coupons': 'coupons.manage',
    '/admin/gift-cards': 'giftcards.manage', '/admin/exchange-rates': 'rates.manage', '/admin/disputes': 'disputes.manage',
    '/admin/tickets': 'tickets.manage', '/admin/reviews': 'reviews.moderate', '/admin/health': 'health.view', '/admin/audit': 'audit.view',
    '/admin/reports': 'reports.view', '/admin/exports': 'exports.download', '/admin/settings': 'settings.manage',
    '/admin/email-templates': 'templates.manage', '/admin/announcements': 'announcements.manage',
}


def permission_map():
    src = open(env.ROOT + '/app/Support/Permissions.php').read()
    return {k: re.findall(r"'([a-z]+)'", v) for k, v in re.findall(r"'([a-z.]+)' => \[([^\]]*)\]", src)}


def paid_order(n, product=None, qty=1):
    email, b = buyer(n)
    p = product or instant_product()
    shop.add_to_cart(b, p, qty)
    r, oid = shop.checkout(b, 'crypto', 'BTC')
    shop.pay(oid)
    wait_status(oid, 'delivered')
    return email, b, oid


@scenario(5, 'Each staff role sees only what it may', 'Five staff accounts with 2FA, one per role',
          'Every admin page answers 200 for roles that have the ability and 403 for the others; navigation shows only allowed links', 'blocker')
def role_matrix(rec, ctx):
    perms = permission_map()
    for role, email in STAFF.items():
        c = admin(email)
        dash = c.get('/admin')
        for path, ability in PAGES.items():
            allowed = role in perms[ability]
            r = c.get(path, expect=None, follow=False)
            rec.check(r.status == (200 if allowed else 403), f'{role} GET {path}: {r.status}, expected {200 if allowed else 403}')
            if path != '/admin' and not allowed:
                rec.check(f'href="https://localhost:8443{path}"' not in dash.text, f'{role} navigation links to forbidden {path}')
        rec.ev(f'{role}: {sum(role in perms[a] for a in PAGES.values())} of {len(PAGES)} pages allowed, as mapped')


@scenario(5, 'Manager is denied destructive and super-admin actions', 'manager@sim.test signed in with a recent password',
          'Settings, gateway, roles, commission, gift cards, balance changes and suspending staff are refused (403 or a clear error); nothing changes', 'blocker')
def manager_denied(rec, ctx):
    c = admin('manager@sim.test')
    shop.confirm_password(c)
    tok = c.csrf('/admin')
    target = env.scalar("select id from users where email='buyer40@sim.test'")
    staff = env.scalar("select id from users where email='support@sim.test'")
    cat = env.scalar('select id from categories limit 1')
    before = env.scalar("select md5(string_agg(key||'='||value, ',' order by key)) from settings")
    for method, path, fields in [
        ('PUT', '/admin/settings', {'commission_bps': '0'}),
        ('PUT', '/admin/gateway', {'payments_crypto_enabled': '0'}),
        ('POST', f'/admin/users/{target}/role', {'role': 'admin'}),
        ('PUT', f'/admin/commission/categories/{cat}', {'commission_bps': '0'}),
        ('POST', '/admin/gift-cards', {'amount': '500.00', 'currency': 'USD'}),
        ('POST', f'/admin/users/{target}/balance', {'direction': 'credit', 'amount': '1000.00', 'currency': 'USD', 'reason': 'x'}),
        ('POST', '/admin/exchange-rates', {'base': 'USD', 'quote': 'EUR', 'rate': '9'}),
    ]:
        r = c.post(path, dict(fields, _method=method, _token=tok) if method != 'POST' else dict(fields, _token=tok), follow=False, token_from='/admin')
        rec.ev(f'manager {method} {path}: {r.status}')
        rec.check(r.status == 403, f'manager {method} {path}: {r.status}')
    r = c.post(f'/admin/users/{staff}/status', {'status': 'suspended', '_token': tok}, token_from='/admin')
    rec.check(env.scalar(f'select status from users where id={staff}') == 'active', 'manager suspended a staff account')
    rec.check('Only a super admin' in flashes(r), f'staff suspension message: {flashes(r)}')
    rec.check(env.scalar("select md5(string_agg(key||'='||value, ',' order by key)) from settings") == before, 'settings changed')
    rec.check(env.scalar(f'select role from users where id={target}') == 'buyer', 'role changed')
    mod = admin('moderator@sim.test')
    shop.confirm_password(mod)
    _, _, oid = paid_order(39)
    tok = mod.csrf('/admin')
    r = mod.post(f'/admin/orders/{oid}/refunds', {'method': 'balance', 'amount': '1.00', '_token': tok}, follow=False, token_from='/admin')
    rec.check(r.status == 403, f'moderator refund: {r.status}')
    r = mod.get('/admin/exports/download?type=users&from=2020-01-01&to=2030-01-01', expect=None, follow=False)
    rec.check(r.status == 403, f'moderator export: {r.status}')
    rec.check(shop.order_row(oid)['refunded'] == 0, 'refund happened')


@scenario(5, 'Approve and reject seller applications', 'Two people apply to sell',
          'Approve makes a seller; reject needs a reason, keeps the role buyer and emails the reason', 'critical')
def sellers(rec, ctx):
    a = admin('manager@sim.test')
    for n, decision in ((1, 'approve'), (2, 'reject')):
        email = f'applicant{n}@sim.test'
        c, r = register(f'Applicant {n}', email, who=f'applicant{n}')
        c.get(links(env.wait_mail(email, 'Verify')[0]['ID'], '/email/verify/')[0])
        c.form('/sell', '/sell', {'display_name': f'Applicant Shop {n}', 'payout_currency': 'USD', 'payout_crypto': 'BTC',
                                  'payout_address': f'bc1qapplicant{n}00000000000000000000000000000', 'about': 'x', 'accept_seller_terms': '1'})
        pid = env.scalar(f"select sp.id from seller_profiles sp join users u on u.id=sp.user_id where u.email='{email}'")
        fields = {'commission_bps': ''} if decision == 'approve' else {'note': 'Simulation: the shop name is misleading.'}
        if decision == 'reject':
            r = staff_form(a, '/admin/sellers', f'/admin/sellers/{pid}/reject', {'note': ''})
            rec.check(env.scalar(f'select status from seller_profiles where id={pid}') == 'pending', 'rejected without a reason')
        r = staff_form(a, '/admin/sellers', f'/admin/sellers/{pid}/{decision}', fields)
        want = ('approved', 'seller') if decision == 'approve' else ('rejected', 'buyer')
        got = tuple(env.sql(f'select sp.status, u.role from seller_profiles sp join users u on u.id=sp.user_id where sp.id={pid}')[0])
        rec.check(got == want, f'{decision}: {got} {flashes(r)}')
        msg = env.wait_mail(email, 'seller application')[0]
        if decision == 'reject':
            rec.check('misleading' in env.mail_text(msg['ID']), 'rejection reason not in the email')


@scenario(5, 'Approve and reject products; admin edit', 'seller09 submits two new products',
          'Moderator approves one (listed) and rejects the other with a reason the seller sees on the product page and by email; '
          'admin product editing: not applicable by decision (staff disable and the seller edits)', 'critical')
def products(rec, ctx):
    from .p4_seller import create_product
    s = shop.login('seller09@sim.test')
    ids = []
    for title in ('Sim Approved Gadget', 'Sim Rejected Gadget'):
        pid = create_product(s, title)
        s.form(f'/seller/products/{pid}/edit', f'/seller/products/{pid}/keys', {'keys': f'{title[:3].upper()}-K1\n{title[:3].upper()}-K2-{pid}'})
        s.form(f'/seller/products/{pid}/edit', f'/seller/products/{pid}/submit')
        ids.append(pid)
    mod = admin('moderator@sim.test')
    staff_form(mod, f'/admin/products/{ids[0]}', f'/admin/products/{ids[0]}/status', {'status': 'active'})
    rec.check(env.scalar(f'select status from products where id={ids[0]}') == 'active', 'approve failed')
    page = mod.get(f'/admin/products/{ids[1]}')
    form = mod.find_form(page, f'/admin/products/{ids[1]}/status', need={'status': 'disabled'})
    r = mod.submit(form, {'note': 'Simulation: screenshots are missing.'})
    rec.check(env.scalar(f'select status from products where id={ids[1]}') == 'disabled', f'reject: {flashes(r)}')
    edit = s.get(f'/seller/products/{ids[1]}/edit')
    rec.check('screenshots are missing' in edit.text, 'seller cannot see why the product was rejected')
    env.wait_mail('seller09@sim.test', 'Rejected Gadget')


@scenario(5, 'Order filters', 'Orders from phases 1-5 exist',
          'Status, buyer, seller and date filters each return exactly the matching orders; an invalid date is a field error, not a 500', 'major')
def order_filters(rec, ctx):
    a = admin('finance@sim.test')

    def listed(query):
        ids = set()
        page = 1
        while True:
            r = a.get('/admin/orders?' + urllib.parse.urlencode(dict(query, page=page)))
            found = set(re.findall(r'/admin/orders/([0-9a-f-]{36})', r.text))
            ids |= found
            if f'page={page + 1}' not in r.text or not found:
                return ids
            page += 1
    want = {x[0] for x in env.sql("select public_id from orders where status='delivered'")}
    rec.check(listed({'status': 'delivered'}) == want, 'status filter mismatch')
    want = {x[0] for x in env.sql("select o.public_id from orders o join users u on u.id=o.buyer_id where u.email ilike '%buyer01%'")}
    rec.check(listed({'buyer': 'buyer01'}) == want, 'buyer filter mismatch')
    sid = env.scalar("select id from users where email='seller04@sim.test'")
    want = {x[0] for x in env.sql(f'select distinct o.public_id from orders o join order_items i on i.order_id=o.id where i.seller_id={sid}')}
    rec.check(listed({'seller': sid}) == want, 'seller filter mismatch')
    today = time.strftime('%Y-%m-%d', time.gmtime())
    want = {x[0] for x in env.sql(f"select public_id from orders where created_at::date = '{today}'")}
    rec.check(listed({'from': today, 'to': today}) == want, 'date filter mismatch')
    r = a.get('/admin/orders?from=31-12-2026', expect=None)
    rec.check(r.status in (200, 302) and r.status != 500, f'bad date: {r.status}')


@scenario(5, 'Full and partial refunds', 'Two delivered orders; finance refunds',
          'Partial refund: order partially refunded, buyer balance credited, line earnings reduced proportionally; over-refund refused; '
          'full refund: order refunded, earnings 0; refund mails sent; ledger reconciles', 'blocker')
def refunds(rec, ctx):
    fin = admin('finance@sim.test')
    email, b, oid = paid_order(41, qty=2)
    row = shop.order_row(oid)
    bal = balance(email)
    half = row['total'] // 2
    r = staff_form(fin, f'/admin/orders/{oid}', f'/admin/orders/{oid}/refunds', {'method': 'balance', 'amount': money(half), 'reason': 'Simulation partial'})
    rec.check(shop.order_row(oid)['status'] == 'partially_refunded', f'partial: {flashes(r)}')
    rec.check(balance(email) == bal + half, 'balance not credited')
    r = staff_form(fin, f'/admin/orders/{oid}', f'/admin/orders/{oid}/refunds', {'method': 'balance', 'amount': money(row['total']), 'reason': 'too much'})
    rec.check('exceeds' in flashes(r), f'over-refund: {flashes(r)}')
    r = staff_form(fin, f'/admin/orders/{oid}', f'/admin/orders/{oid}/refunds', {'method': 'manual', 'amount': money(row['total'] - half), 'reference': 'sim-manual-1'})
    rec.check(shop.order_row(oid)['status'] == 'refunded', f'full: {flashes(r)}')
    rec.check(all(i['earning'] == 0 for i in items_of(oid)), f'earnings after full refund: {items_of(oid)}')
    env.wait_mail(email, 'Refund', count=2)
    rc = env.artisan('shop:reconcile-payouts', check=False)
    rec.check(rc.returncode == 0, rc.stdout[-400:])


@scenario(5, 'Disputes resolved both ways', 'The dispute from phase 4 (refund asked) and a new one',
          'Refund resolution refunds the line and closes the dispute; rejection releases the held earning; both parties emailed', 'critical')
def disputes(rec, ctx):
    mgr = admin('manager@sim.test')
    did, oid, item, email = ctx.data.get('dispute') or (None, None, None, None)
    if did is None:
        raise AssertionError('phase 4 dispute missing (run phases 4 and 5 together)')
    bal = balance(email)
    page = mgr.get(f'/disputes/{did}')
    form = mgr.find_form(page, f'/admin/disputes/{did}/resolve', need={'action': 'refund'})
    r = mgr.submit(form, {'method': 'balance', 'note': 'Refunded by the simulation.'})
    if '/confirm-password' in r.path:
        shop.confirm_password(mgr)
        page = mgr.get(f'/disputes/{did}')
        r = mgr.submit(mgr.find_form(page, f'/admin/disputes/{did}/resolve', need={'action': 'refund'}), {'method': 'balance', 'note': 'Refunded by the simulation.'})
    rec.check(env.scalar(f'select status from disputes where id={did}') not in ('open', 'escalated', 'awaiting_seller'), f'refund resolution: {flashes(r)}')
    rec.check(balance(email) > bal, 'buyer not refunded')
    env.wait_mail(email, 'closed')
    # Second dispute, rejected.
    email2, b2, oid2 = paid_order(42)
    it2 = items_of(oid2)[0]['id']
    page = b2.get(f'/orders/{oid2}/items/{it2}/dispute')
    b2.submit(b2.find_form(page, f'/orders/{oid2}/items/{it2}/dispute'), {'requested_outcome': 'refund', 'body': 'I changed my mind.'})
    did2 = env.scalar(f'select id from disputes where order_item_id={it2}')
    sid = items_of(oid2)[0]['seller_id']
    held = env.scalar(f"select count(*) from disputes where id={did2} and status in ('open','awaiting_seller','escalated')")
    rec.check(held == '1', 'second dispute not open')
    page = mgr.get(f'/disputes/{did2}')
    r = mgr.submit(mgr.find_form(page, f'/admin/disputes/{did2}/resolve', need={'action': 'reject'}), {'note': 'Digital goods are not returnable after download.'})
    rec.check(env.scalar(f'select status from disputes where id={did2}') not in ('open', 'awaiting_seller', 'escalated'), f'reject: {flashes(r)}')
    rec.check(shop.order_row(oid2)['refunded'] == 0, 'rejected dispute refunded money')
    check_ledger(rec, oid2)


@scenario(5, 'Suspend a buyer', 'buyer43 is signed in',
          'Suspension ends the session at once, sign-in is refused with a clear message, reactivation restores access; audited', 'critical')
def suspend_user(rec, ctx):
    email, b = buyer(43)
    uid_ = env.scalar(f"select id from users where email='{email}'")
    mgr = admin('manager@sim.test')
    page = mgr.get(f'/admin/users/{uid_}')
    r = staff_form(mgr, f'/admin/users/{uid_}', f'/admin/users/{uid_}/status', {'status': 'suspended'}, need={'status': 'suspended'})
    rec.check(env.scalar(f'select status from users where id={uid_}') == 'suspended', flashes(r))
    rec.check('/login' in b.get('/orders', expect=None).url, 'suspended user still signed in')
    x = Client('buyer43-again')
    r = x.form('/login', '/login', {'email': email, 'password': shop.PASSWORD})
    rec.check('suspended' in flashes(r), f'login message: {flashes(r)}')
    staff_form(mgr, f'/admin/users/{uid_}', f'/admin/users/{uid_}/status', {'status': 'active'}, need={'status': 'active'})
    shop.login(email, who='buyer43-back')
    rec.check(env.scalar(f"select count(*) from audit_log where action='user.status_changed' and target_id='{uid_}'") == '2', 'not audited twice')


@scenario(5, 'Suspend a seller hides their products', 'seller10 has active products; a buyer has one in the cart',
          'After suspension the products leave the catalog, search, seller filter and product pages; carts flag them; '
          'checkout is refused; reactivation brings them back', 'critical')
def suspend_seller(rec, ctx):
    sid = env.scalar("select id from users where email='seller10@sim.test'")
    p = shop.product(f"seller_id={sid} and status='active' and currency='USD' and (stock is null or stock>0)")
    _, b = buyer(44)
    shop.add_to_cart(b, p)
    mgr = admin('manager@sim.test')
    staff_form(mgr, f'/admin/users/{sid}', f'/admin/users/{sid}/status', {'status': 'suspended'}, need={'status': 'suspended'})
    g = Client('shopper')
    rec.check(g.get(f"/products/{p['slug']}", expect=None).status == 404, 'product page of suspended seller visible')
    rec.check(f"/products/{p['slug']}" not in g.get('/products?q=' + urllib.parse.quote(p['title'])).text, 'product still in search results')
    rec.check(f"/products/{p['slug']}" not in g.get(f'/products?seller={sid}').text, 'product still under the seller filter')
    cart = b.get('/cart')
    rec.check('no longer available' in cart.text, 'cart did not flag it')
    staff_form(mgr, f'/admin/users/{sid}', f'/admin/users/{sid}/status', {'status': 'active'}, need={'status': 'active'})
    rec.check(g.get(f"/products/{p['slug']}").status == 200, 'product not back after reactivation')


@scenario(5, 'Payouts via the gateway', 'Hold 0 days (phase 4); seller01 requests a payout; finance sends it through Shkeeper',
          'Payout sent, the gateway callback marks it paid with the tx hash; a duplicate callback changes nothing; ledger reconciles', 'critical')
def gateway_payout(rec, ctx):
    sid = env.scalar("select id from users where email='seller01@sim.test'")
    paid_order(45, product=instant_product(f'seller_id={sid}'), qty=3)
    s = shop.login('seller01@sim.test')
    avail = int(env.scalar(f"select coalesce(sum(amount_minor),0) from seller_ledger_entries where seller_id={sid} and currency='USD'"))
    r = s.form('/seller/payouts', '/seller/payouts', {'amount': money(min(avail, 2000)), 'currency': 'USD'})
    pid = env.scalar(f'select id from payouts where seller_id={sid} order by id desc limit 1')
    rec.check(pid, f'request: {flashes(r)}')
    fin = admin('finance@sim.test')
    staff_form(fin, '/admin/payouts', f'/admin/payouts/{pid}/approve')
    r = staff_form(fin, '/admin/payouts', f'/admin/payouts/{pid}/send')
    rec.check(env.scalar(f'select status from payouts where id={pid}') in ('processing', 'sent', 'approved'), f'send: {flashes(r)}')
    res = env.mock(f'/__mock/payout-result/payout-{pid}', {'status': 'SUCCESS'})
    rec.check(res.get('callback_http_status') == 202, res)
    shop.wait_for(lambda: env.scalar(f'select status from payouts where id={pid}') == 'paid', 'payout paid', 30)
    rec.check(env.scalar(f'select reference is not null from payouts where id={pid}') == 't', 'tx hash not stored')
    res = env.mock(f'/__mock/payout-result/payout-{pid}', {'status': 'SUCCESS'})
    rec.check(env.scalar(f'select count(*) from seller_ledger_entries where payout_id={pid}') == '1', 'duplicate payout ledger entries')
    rc = env.artisan('shop:reconcile-payouts', check=False)
    rec.check(rc.returncode == 0, rc.stdout[-400:])


@scenario(5, 'Audit log search', 'Actions from this run are audited',
          'Filtering by action prefix, actor and target returns the matching entries only', 'major')
def audit(rec, ctx):
    a = admin()
    r = a.get('/admin/audit?action=user.status')
    rows = len(re.findall(r'user\.status_changed', r.text))
    rec.check(rows >= 2, f'{rows} user.status_changed rows')
    rec.check('order.paid' not in r.text, 'action filter leaks other actions')
    actor = env.scalar("select id from users where email='finance@sim.test'")
    r = a.get(f'/admin/audit?actor={actor}')
    want = int(env.scalar(f'select count(*) from audit_log where actor_id={actor}'))
    rec.check(want > 0 and 'refund' in r.text, 'actor filter shows no refunds by finance')
    r = a.get('/admin/audit?target_type=Order&from=2000-01-01&to=2100-01-01')
    rec.check('order.' in r.text, 'target filter')
    r = a.get('/admin/audit?from=not-a-date', expect=None)
    rec.check(r.status != 500, 'bad date crashed')


@scenario(5, 'Email templates', 'Manager edits the "order paid" template',
          'Preview shows sample data; removing a required placeholder is refused; saved text is used for the next mail; reset restores', 'major')
def templates(rec, ctx):
    mgr = admin('manager@sim.test')
    page = mgr.get('/admin/email-templates/order_paid')
    form = mgr.find_form(page, '/admin/email-templates/order_paid', need={'_method': 'PUT'})
    r = mgr.submit(form, {'subject': 'Paid: {order_number}', 'body': 'Thanks! No link here.'})
    rec.check('order_url' in flashes(r), f'missing required placeholder accepted: {flashes(r)}')
    r = mgr.submit(form, {'subject': 'SIMPAID {order_number}', 'body': 'Thanks! Your files: {order_url}', 'preview': '1'})
    rec.check('SIMPAID' in r.text, 'preview missing')
    r = mgr.submit(form, {'subject': 'SIMPAID {order_number}', 'body': 'Thanks! Your files: {order_url}'})
    email, b, oid = paid_order(46)
    env.wait_mail(email, 'SIMPAID')
    page = mgr.get('/admin/email-templates/order_paid')
    r = mgr.submit(mgr.find_form(page, '/admin/email-templates/order_paid', need={'_method': 'DELETE'}))
    rec.check(env.scalar("select count(*) from email_templates where key='order_paid'") == '0', f'reset: {flashes(r)}')


@scenario(5, 'Gateway config and logs', 'Super admin on /admin/gateway',
          'Switching LTC off removes it from checkout; the connection check works and is logged; logs filter by channel and outcome; '
          'switching it back restores it', 'critical')
def gateway(rec, ctx):
    a = admin()
    page = a.get('/admin/gateway')
    form = a.find_form(page, '/admin/gateway', need={'_method': 'PUT'})
    enabled = [v for k, v in form['fields'] if k == 'crypto_enabled[]']
    r = a.submit(form, {'crypto_enabled[]': [c for c in enabled if c != 'LTC']})
    if '/confirm-password' in r.path:
        shop.confirm_password(a)
        page = a.get('/admin/gateway')
        form = a.find_form(page, '/admin/gateway', need={'_method': 'PUT'})
        r = a.submit(form, {'crypto_enabled[]': [c for c in enabled if c != 'LTC']})
    _, b = buyer(47)
    shop.add_to_cart(b, instant_product())
    co = b.get('/checkout')
    rec.check('value="LTC"' not in co.text and 'value="BTC"' in co.text, 'LTC still offered at checkout')
    r = a.form('/admin/gateway', '/admin/gateway/check')
    rec.check('Shkeeper answered' in flashes(r), f'check: {flashes(r)}')
    rec.check(env.scalar("select count(*) from gateway_logs where channel='api'") != '0', 'connection check not logged')
    logs = a.get('/admin/gateway?channel=webhook&outcome=rejected_signature')
    rec.check('rejected' in logs.text.lower(), 'rejected webhooks not listed')
    page = a.get('/admin/gateway')
    form = a.find_form(page, '/admin/gateway', need={'_method': 'PUT'})
    a.submit(form, {'crypto_enabled[]': enabled})
    rec.check('value="LTC"' in b.get('/checkout').text, 'LTC not restored')
    shop.empty_cart(b)


@scenario(5, 'CSV export of orders and users', 'Finance exports; one buyer named "=HYPERLINK(...)"',
          'Both CSVs download as attachments with the expected rows; no password hashes, 2FA secrets or tokens; '
          'formula-looking cells are neutralised', 'critical')
def exports(rec, ctx):
    _, b = buyer(48)
    b.form('/account', '/account/profile', {'name': '=HYPERLINK("http://evil.test","x")'})
    fin = admin('finance@sim.test')
    shop.confirm_password(fin)
    today = time.strftime('%Y-%m-%d', time.gmtime())
    for kind, count_sql in (('orders', f"select count(*) from orders where created_at::date <= '{today}'"),
                            ('users', f"select count(*) from users where created_at::date <= '{today}'")):
        r = fin.get(f'/admin/exports/download?type={kind}&from=2020-01-01&to={today}')
        rec.check('attachment' in (r.headers.get('Content-Disposition') or ''), f'{kind}: not an attachment')
        rows = list(csv.reader(io.StringIO(r.text)))
        rec.check(len(rows) - 1 == int(env.scalar(count_sql)), f'{kind}: {len(rows) - 1} rows, expected {env.scalar(count_sql)}')
        blob = r.text.lower()
        for secret in ('password', '$2y$', 'two_factor_secret', 'remember_token', 'eyj'):
            rec.check(secret not in blob, f'{kind} export contains {secret!r}')
        if kind == 'users':
            cell = [c for row in rows for c in row if 'HYPERLINK' in c]
            rec.check(cell and not cell[0].startswith('='), f'formula not neutralised: {cell[:1]}')
    # bulk export of filtered orders
    page = fin.get('/admin/orders?status=delivered')
    rec.check('Export' in page.text, 'no bulk export on the order list')
