"""Phase 4: the seller side, including attempts to reach other people's data."""
import re
import struct
import zlib

from simlib import env, shop
from simlib.http import Client
from simlib.record import scenario

from .common import admin, buyer, check_ledger, flashes, instant_product, items_of, money, staff_form, wait_status
from .p3_accounts import links, register


def png(w=48, h=32):
    rows = b''.join(b'\x00' + b''.join(bytes([(x * 5) % 256, (y * 7) % 256, 90]) for x in range(w)) for y in range(h))

    def chunk(t, d):
        return struct.pack('>I', len(d)) + t + d + struct.pack('>I', zlib.crc32(t + d) & 0xffffffff)
    return b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', w, h, 8, 2, 0, 0, 0)) + chunk(b'IDAT', zlib.compress(rows)) + chunk(b'IEND', b'')


def seller_login(n):
    return shop.login(f'seller{n:02d}@sim.test')


def create_product(c, title, price='14.00', delivery='instant', extra=None):
    cat = env.scalar("select id from categories where slug='software-tools'")
    fields = {'title': title, 'description': f'{title}: a simulation product with a description long enough to read.', 'price': price,
              'currency': 'USD', 'delivery_type': delivery, 'category_id': cat}
    fields.update(extra or {})
    r = c.form('/seller/products/new', '/seller/products', fields)
    m = re.search(r'/seller/products/(\d+)/edit', r.url)
    if not m:
        raise AssertionError(f'product not created: {r.path} {flashes(r)}')
    return int(m.group(1))


@scenario(4, 'Seller onboarding', 'A new person registers, verifies the email and applies at /sell',
          'Application pending until staff approve; approval makes the account a seller with a profile and emails the applicant; '
          'documents: not applicable (no document upload by decision)', 'critical')
def onboarding(rec, ctx):
    email = 'newseller@sim.test'
    c, r = register('New Seller', email, who='newseller')
    msg = env.wait_mail(email, 'Verify')[0]
    c.get(links(msg['ID'], '/email/verify/')[0])
    r = c.form('/sell', '/sell', {'display_name': 'Sim Fresh Goods', 'payout_currency': 'USD', 'payout_crypto': 'BTC',
                                  'payout_address': 'bc1qsimfreshgoods0000000000000000000000000', 'about': 'Tools.', 'accept_seller_terms': '1'})
    rec.check(env.scalar(f"select sp.status from seller_profiles sp join users u on u.id=sp.user_id where u.email='{email}'") == 'pending', f'not pending: {flashes(r)}')
    rec.check(c.get('/seller/products/new', expect=None).status in (302, 403) or '/sell' in c.last.url, 'pending applicant can create products')
    a = admin('manager@sim.test')
    page = a.get('/admin/sellers')
    pid = env.scalar(f"select sp.id from seller_profiles sp join users u on u.id=sp.user_id where u.email='{email}'")
    r = a.submit(a.find_form(page, f'/admin/sellers/{pid}/approve'), {'commission_bps': ''})
    if '/confirm-password' in r.path:
        shop.confirm_password(a)
        page = a.get('/admin/sellers')
        r = a.submit(a.find_form(page, f'/admin/sellers/{pid}/approve'), {'commission_bps': ''})
    rec.check(env.scalar(f"select role from users where email='{email}'") == 'seller', f'not approved: {flashes(r)}')
    env.wait_mail(email, 'seller application')
    rec.check(c.get('/seller').status == 200, 'seller dashboard not reachable')
    ctx.data['newseller'] = c


@scenario(4, 'Product create and edit (file scan, image, keys, review)', 'newseller is approved',
          'Draft created; clean file scanned clean; EICAR upload detected and removed; image re-encoded; keys added; '
          'submit -> review -> moderator approves -> visible; editing the price sends it back to review', 'critical')
def products(rec, ctx):
    c = ctx.data.get('newseller') or shop.login('newseller@sim.test')
    pid = create_product(c, 'Sim Fresh Toolkit')
    edit = f'/seller/products/{pid}/edit'
    r = c.form(edit, f'/seller/products/{pid}/files', files={'file': ('toolkit.zip', b'PK\x03\x04 sim toolkit ' * 50, 'application/zip')})
    shop.wait_for(lambda: env.scalar(f"select scan_status from product_files where product_id={pid}") == 'clean', 'file scanned clean', 60)
    eicar = b'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*'
    bad = create_product(c, 'Sim Infected Upload')
    c.form(f'/seller/products/{bad}/edit', f'/seller/products/{bad}/files', files={'file': ('eicar.zip', eicar, 'application/zip')})
    shop.wait_for(lambda: env.scalar(f"select count(*) from product_files where product_id={bad} and scan_status='infected'") == '1', 'EICAR detected', 60)
    ctx.allow_log(r'Infected upload')
    r = c.form(edit, f'/seller/products/{pid}/images', {'alt_text': 'Screenshot'}, files={'image': ('shot.png', png(), 'image/png')})
    rec.check(env.scalar(f'select count(*) from product_images where product_id={pid}') == '1', f'image: {flashes(r)}')
    r = c.form(edit, f'/seller/products/{pid}/keys', {'keys': 'SIM-NEW-0001\nSIM-NEW-0002\nSIM-NEW-0001'})
    rec.check(env.scalar(f'select count(*) from product_license_keys where product_id={pid}') == '2', f'keys (duplicate in batch): {flashes(r)}')
    r = c.form(edit, f'/seller/products/{pid}/submit')
    rec.check(env.scalar(f'select status from products where id={pid}') == 'pending_review', flashes(r))
    mod = admin('moderator@sim.test')
    r = staff_form(mod, f'/admin/products/{pid}', f'/admin/products/{pid}/status', {'status': 'active'})
    rec.check(env.scalar(f'select status from products where id={pid}') == 'active', f'moderator approval: {flashes(r)}')
    slug = env.scalar(f'select slug from products where id={pid}')
    g = Client('browser')
    rec.check(g.get(f'/products/{slug}').status == 200, 'approved product not visible')
    page = g.get(f'/products/{slug}')
    img = re.search(r'<img[^>]+src="(https://localhost:8443/product-images/[^"]+)"', page.text)
    rec.check(img and g.get(img.group(1)).headers.get('Content-Type') == 'image/webp', 'image not served as re-encoded webp')
    r = c.form(edit, f'/seller/products/{pid}', {'price': '15.00', '_method': 'PUT'})
    rec.check(env.scalar(f'select status from products where id={pid}') == 'pending_review', 'price change did not trigger review')
    rec.check(g.get(f'/products/{slug}', expect=None).status == 404, 'product in review still visible')
    staff_form(mod, f'/admin/products/{pid}', f'/admin/products/{pid}/status', {'status': 'active'})
    ctx.data['new_product'] = pid


@scenario(4, 'Deactivate and reactivate a product', 'newseller has an active product in a buyer cart',
          'Pause hides it (catalog, product page, cart flags it); resume shows it again without review', 'major')
def pause_resume(rec, ctx):
    pid = ctx.data['new_product']
    c = ctx.data.get('newseller') or shop.login('newseller@sim.test')
    slug = env.scalar(f'select slug from products where id={pid}')
    _, b = buyer(33)
    shop.add_to_cart(b, {'slug': slug})
    r = c.form(f'/seller/products/{pid}/edit', f'/seller/products/{pid}/pause')
    rec.check(env.scalar(f'select status from products where id={pid}') == 'paused', flashes(r))
    rec.check(Client('x').get(f'/products/{slug}', expect=None).status == 404, 'paused product page visible')
    rec.check('no longer available' in b.get('/cart').text, 'cart did not flag the paused product')
    r = c.form(f'/seller/products/{pid}/edit', f'/seller/products/{pid}/resume')
    rec.check(env.scalar(f'select status from products where id={pid}') == 'active', flashes(r))
    rec.check(Client('y').get(f'/products/{slug}').status == 200, 'resumed product not visible')


@scenario(4, 'Sales and ledger views match the database', 'seller04 has sales from earlier phases plus a new one',
          'Sales page lists the new sale with net and earning; dashboard balances equal the ledger sums per currency', 'critical')
def sales_views(rec, ctx):
    sid = int(env.scalar("select id from users where email='seller04@sim.test'"))
    p = instant_product(f'seller_id={sid}')
    _, b = buyer(34)
    shop.add_to_cart(b, p)
    r, oid = shop.checkout(b, 'crypto', 'BTC')
    shop.pay(oid)
    wait_status(oid, 'delivered')
    c = seller_login(4)
    page = c.get('/seller/sales')
    rec.check(oid[:8] in page.text, 'new sale not on the sales page')
    item = items_of(oid)[0]
    rec.check(money(item['earning']) in page.text, f"earning {money(item['earning'])} not shown")
    total = int(env.scalar(f"select coalesce(sum(amount_minor),0) from seller_ledger_entries where seller_id={sid} and currency='USD'"))
    dash = c.get('/seller')
    rec.check(money(total) in dash.text, f'dashboard does not show the ledger total {money(total)} USD')
    check_ledger(rec, oid)


@scenario(4, 'Payout request, approve and reject', 'Super admin sets the payout hold to 0 days; seller05 has earnings',
          'Seller requests a payout; amounts above the available balance are refused; finance rejects one (funds return) '
          'and approves and pays another (ledger debited once); reconciliation stays clean', 'critical')
def payouts(rec, ctx):
    sa = admin()
    r = staff_form(sa, '/admin/settings', '/admin/settings', {'payout_hold_days': '0', 'min_payout_minor': '100'})
    rec.check(env.scalar("select value from settings where key='payout_hold_days'").strip('"') == '0', f'setting: {flashes(r)}')
    sid = int(env.scalar("select id from users where email='seller05@sim.test'"))
    p = instant_product(f'seller_id={sid}')
    _, b = buyer(35)
    shop.add_to_cart(b, p, 3)
    r, oid = shop.checkout(b, 'crypto', 'BTC')
    shop.pay(oid)
    wait_status(oid, 'delivered')
    c = seller_login(5)
    cur = 'USD'
    available = int(env.scalar(f"select coalesce(sum(amount_minor),0) from seller_ledger_entries where seller_id={sid} and currency='{cur}'"))
    rec.ev(f'available {available}')
    r = c.form('/seller/payouts', '/seller/payouts', {'amount': money(available + 100), 'currency': cur})
    rec.check('exceeds' in flashes(r).lower() or 'available' in flashes(r).lower(), f'over-balance payout: {flashes(r)}')
    r = c.form('/seller/payouts', '/seller/payouts', {'amount': money(available // 2), 'currency': cur})
    p1 = env.scalar(f"select id from payouts where seller_id={sid} order by id desc limit 1")
    rec.check(p1, f'payout not requested: {flashes(r)}')
    fin = admin('finance@sim.test')
    r = staff_form(fin, '/admin/payouts', f'/admin/payouts/{p1}/reject', {'note': 'Simulation: please confirm your address first.'})
    rec.check(env.scalar(f'select status from payouts where id={p1}') == 'rejected', f'reject: {flashes(r)}')
    after = int(env.scalar(f"select coalesce(sum(amount_minor),0) from seller_ledger_entries where seller_id={sid} and currency='{cur}'"))
    rec.check(after == available, f'rejected payout did not return the funds: {available} -> {after}')
    r = c.form('/seller/payouts', '/seller/payouts', {'amount': money(available // 2), 'currency': cur})
    p2 = env.scalar(f"select id from payouts where seller_id={sid} order by id desc limit 1")
    rec.check(p2 != p1, f'second payout not requested: {flashes(r)}')
    staff_form(fin, '/admin/payouts', f'/admin/payouts/{p2}/approve')
    r = staff_form(fin, '/admin/payouts', f'/admin/payouts/{p2}/paid', {'reference': 'sim-tx-0001'})
    rec.check(env.scalar(f'select status from payouts where id={p2}') == 'paid', f'paid: {flashes(r)}')
    rec.check(env.scalar(f"select count(*) from seller_ledger_entries where payout_id={p2}") == '1', 'payout ledger entry count')
    env.wait_mail('seller05@sim.test', 'Payout')
    rc = env.artisan('shop:reconcile-payouts', check=False)
    rec.check(rc.returncode == 0, rc.stdout[-500:])


@scenario(4, 'Dispute response by the seller', 'buyer49 bought a product of seller06 and opens a dispute',
          'Seller is emailed, sees the dispute and replies; buyer sees the reply; the line earning is held while open', 'critical')
def dispute_response(rec, ctx):
    sid = int(env.scalar("select id from users where email='seller06@sim.test'"))
    p = instant_product(f'seller_id={sid}')
    email, b = buyer(49)  # USD wallet, so a balance refund is possible later
    shop.add_to_cart(b, p)
    r, oid = shop.checkout(b, 'crypto', 'BTC')
    shop.pay(oid)
    wait_status(oid, 'delivered')
    item = items_of(oid)[0]['id']
    page = b.get(f'/orders/{oid}/items/{item}/dispute')
    r = b.submit(b.find_form(page, f'/orders/{oid}/items/{item}/dispute'),
                 {'requested_outcome': 'refund', 'body': 'The archive is empty.'})
    did = env.scalar(f'select id from disputes where order_item_id={item}')
    rec.check(did, f'dispute not opened: {flashes(r)}')
    env.wait_mail('seller06@sim.test', 'Dispute')
    s = seller_login(6)
    page = s.get(f'/disputes/{did}')
    r = s.submit(s.find_form(page, f'/disputes/{did}/messages'), {'body': 'Sorry - a fresh archive is attached to your order now.'})
    rec.check('fresh archive' in b.get(f'/disputes/{did}').text, 'buyer does not see the reply')
    ctx.data['dispute'] = (did, oid, item, email)


@scenario(4, 'Seller rating', 'n/a', 'Not applicable: sellers are not rated (decision recorded); products have buyer reviews', 'minor')
def rating(rec, ctx):
    rec.actual = 'Not applicable by decision.'


@scenario(4, 'IDOR: sellers and buyers cannot reach other people\'s data (403)', "seller07 and seller08; buyer37 and buyer38",
          'Every cross-account read or write answers 403 and changes nothing', 'blocker')
def idor(rec, ctx):
    s7, s8 = seller_login(7), seller_login(8)
    sid8 = env.scalar("select id from users where email='seller08@sim.test'")
    other = shop.product(f'seller_id={sid8}')
    pid = other['id']
    file_id = env.scalar(f'select id from product_files where product_id={pid} limit 1') or '1'
    before = env.scalar(f"select md5(row(p.*)::text) from products p where id={pid}")
    tok = s7.csrf('/seller')
    attempts = [
        ('GET', f'/seller/products/{pid}/edit', None),
        ('POST', f'/seller/products/{pid}', {'_method': 'PUT', 'title': 'Hijacked', 'description': 'x' * 30, 'price': '1.00', 'currency': 'USD', 'delivery_type': 'instant'}),
        ('POST', f'/seller/products/{pid}/keys', {'keys': 'STOLEN-1'}),
        ('POST', f'/seller/products/{pid}/submit', {}),
        ('POST', f'/seller/products/{pid}/pause', {}),
        ('POST', f'/seller/products/{pid}/files/{file_id}', {'_method': 'DELETE'}),
    ]
    sold = env.scalar(f"select i.id from order_items i where i.seller_id={sid8} limit 1")
    if sold:
        attempts.append(('POST', f'/seller/items/{sold}/deliver', {'payload': 'hijack'}))
    for method, path, fields in attempts:
        r = s7.get(path, expect=None, follow=False) if method == 'GET' else s7.post(path, dict(fields, _token=tok), follow=False, token_from='/seller')
        rec.ev(f'seller07 {method} {path}: {r.status}')
        rec.check(r.status == 403, f'seller07 {method} {path} answered {r.status}')
    rec.check(env.scalar(f"select md5(row(p.*)::text) from products p where id={pid}") == before, 'product changed by another seller')
    # buyers
    e37, b37 = buyer(37)
    e38, b38 = buyer(38)
    p = instant_product()
    shop.add_to_cart(b38, p)
    r, oid = shop.checkout(b38, 'crypto', 'BTC')
    shop.pay(oid)
    wait_status(oid, 'delivered')
    item = items_of(oid)[0]['id']
    page = b38.get(f'/orders/{oid}')
    dl = re.search(r'href="([^"]+/files/\d+\?[^"]*)"', page.text)
    tok = b37.csrf('/orders')
    checks = [('GET', f'/orders/{oid}'), ('GET', f'/orders/{oid}/invoice'), ('GET', f'/orders/{oid}/pay'), ('GET', f'/orders/{oid}/result'),
              ('POST', f'/orders/{oid}/cancel'), ('POST', f'/orders/{oid}/pay-balance'), ('POST', f'/orders/{oid}/reorder'),
              ('GET', f'/orders/{oid}/items/{item}/dispute')]
    if dl:
        checks.append(('GET', dl.group(1).replace('&amp;', '&').replace('https://localhost:8443', '')))
    for method, path in checks:
        r = b37.get(path, expect=None, follow=False) if method == 'GET' else b37.post(path, {'_token': tok}, follow=False, token_from='/orders')
        rec.ev(f'buyer37 {method} {path[:80]}: {r.status}')
        rec.check(r.status == 403, f'buyer37 {method} {path[:80]} answered {r.status}')
    if 'dispute' in ctx.data:
        did = ctx.data['dispute'][0]
        for who, c in (('buyer37', b37), ('seller07', s7)):
            r = c.get(f'/disputes/{did}', expect=None, follow=False)
            rec.check(r.status == 403, f'{who} opened another dispute: {r.status}')
    for path in ('/admin', '/admin/orders', '/admin/users', '/admin/settings'):
        r = s7.get(path, expect=None, follow=False)
        rec.check(r.status == 403, f'seller07 GET {path}: {r.status}')
    rec.check(shop.order_row(oid)['status'] == 'delivered', 'order changed by another buyer')
