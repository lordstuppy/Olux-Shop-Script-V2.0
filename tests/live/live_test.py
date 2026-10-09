"""End-to-end live test against a running production-like stack (nginx with
TLS -> app, queue worker, scheduler, Shkeeper mock, fake clamd, SMTP sink,
PostgreSQL 15). Started by tests/live/run.sh; not meant to be run directly.
Usage: python3 -I live_test.py <live work dir>"""
import sys, os, re, json, time, subprocess, html, urllib.parse
L = sys.argv[1]
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import client as C
from client import Client, Fail, text_of, forms_of, totp

C.CA = os.path.join(L, 'tls', 'cert.pem')
APP = os.path.join(L, 'app')
MAIL_LOG = os.path.join(L, 'logs', 'mail.txt')
MOCK = 'http://127.0.0.1:8081'
PW = 'Correct-horse-battery-7'
RESULTS = []
STATE = {}


def step(name):
    def deco(fn):
        def run():
            t = time.time()
            try:
                fn()
                RESULTS.append((name, 'PASS', round(time.time() - t, 1), ''))
                print(f'PASS  {name}', flush=True)
            except Exception as e:  # noqa
                RESULTS.append((name, 'FAIL', round(time.time() - t, 1), str(e)))
                print(f'FAIL  {name}\n      {e}', flush=True)
                raise
        run.__name__ = fn.__name__
        return run
    return deco


def check(cond, msg):
    if not cond:
        raise Fail(msg)


def sql(q):
    out = subprocess.run(['docker', 'exec', 'shoplive-db', 'psql', '-p', '5433', '-U', 'shop', '-d', 'shop', '-At', '-F', '|', '-c', q],
                         capture_output=True, text=True)
    if out.returncode != 0:
        raise Fail('sql failed: ' + out.stderr)
    return out.stdout.strip()


def artisan(*args):
    out = subprocess.run(['php', 'artisan', *args], cwd=APP, capture_output=True, text=True)
    return out.returncode, out.stdout + out.stderr


def wait_for(fn, what, timeout=60):
    end = time.time() + timeout
    while time.time() < end:
        v = fn()
        if v:
            return v
        time.sleep(1)
    raise Fail(f'timed out waiting for {what}')


def mail_pos():
    return os.path.getsize(MAIL_LOG) if os.path.exists(MAIL_LOG) else 0


def mails_since(pos):
    if not os.path.exists(MAIL_LOG):
        return ''
    with open(MAIL_LOG, 'rb') as f:
        f.seek(pos)
        return f.read().decode('utf-8', 'replace')


def wait_mail(pos, to, subject_part, timeout=60):
    """Waits for a queued mail to `to` whose subject contains subject_part; returns its text."""
    def find():
        blob = mails_since(pos)
        # Each logged mail starts with a log line followed by the MIME message.
        for chunk in blob.split('\n=====MAIL ')[1:]:
            if re.search(r'^To: (?:.*<)?' + re.escape(to) + r'>?\s*$', chunk, re.M) and subject_part.lower() in re.sub(r'\s+', ' ', chunk).lower():
                return chunk
        return None
    return wait_for(find, f'mail to {to} with subject {subject_part!r}', timeout)


def mail_links(chunk):
    body = re.sub(r'=\r?\n', '', chunk).replace('=3D', '=')
    return [html.unescape(u) for u in re.findall(r'https://localhost:8443/[^\s"<>]+', body)]


def register(c, name, email):
    c.get('/register')
    page = c.last[2]
    time.sleep(3.2)  # time trap
    c.form('/register', '/register', {'name': name, 'email': email, 'password': PW, 'password_confirmation': PW, 'accept_terms': '1'}, page=page)


def login(c, email, password=PW, secret=None):
    c.form('/login', '/login', {'email': email, 'password': password})
    if '/two-factor-challenge' in c.last[1]:
        check(secret, f'{email} needs a 2FA code')
        code = fresh_totp(secret)
        c.form('/two-factor-challenge', '/two-factor-challenge', {'code': code})


USED_STEPS = {}


def fresh_totp(secret):
    """Codes are single use per time step; wait for a new step if this secret already used the current one."""
    while USED_STEPS.get(secret) == int(time.time() // 30):
        time.sleep(1)
    USED_STEPS[secret] = int(time.time() // 30)
    return totp(secret)


def confirm_password(c, password=PW):
    c.form('/confirm-password', '/confirm-password', {'password': password})


def mock(path, body=None, auth=None):
    import urllib.request
    req = urllib.request.Request(MOCK + path, data=json.dumps(body or {}).encode(), method='POST',
                                 headers={'Content-Type': 'application/json'})
    with urllib.request.urlopen(req, timeout=60) as r:
        return json.loads(r.read())


# ---------------------------------------------------------------- phases

@step('infrastructure: TLS, security headers, cookies, health, metrics, errors')
def infra():
    g = Client('guest')
    s, body, h = g.get('/')
    for k, want in [('Strict-Transport-Security', 'max-age='), ('Content-Security-Policy', "default-src 'self'"),
                    ('X-Content-Type-Options', 'nosniff'), ('X-Frame-Options', 'DENY'), ('Referrer-Policy', 'same-origin'),
                    ('Permissions-Policy', ''), ('X-Request-Id', '')]:
        check(h.get(k) is not None and want in h.get(k), f'header {k} missing or wrong: {h.get(k)}')
    check(h.get('X-Powered-By') is None, 'X-Powered-By leaked')
    check(h.get('Server') == 'nginx', f'server header {h.get("Server")}')
    cookies = h.get_all('Set-Cookie') or []
    check(cookies and all('secure' in c.lower() and 'samesite=lax' in c.lower() for c in cookies), f'cookies not Secure/Lax: {cookies}')
    check(any('httponly' in c.lower() for c in cookies if 'session' in c.lower()), 'session cookie not HttpOnly')
    check(f'href="https://localhost:8443/products"' in body, 'absolute URLs are not https (TrustProxies)')
    s, body, h = g.get('/health')
    check(json.loads(body) == {'status': 'ok'}, f'anonymous health detail leaked: {body}')
    s, body, h = g.go('GET', '/health', headers={'Authorization': 'Bearer live-metrics-token'})
    check(json.loads(body)['checks']['database']['status'] == 'ok', body)
    g.get('/metrics', expect=401)
    s, body, h = g.go('GET', '/metrics', headers={'Authorization': 'Bearer live-metrics-token'}, expect=200)
    check('shop_orders' in body, 'metrics content')
    s, body, h = g.get('/no-such-page', expect=404)
    check(h['X-Request-Id'] in body, '404 page lacks request id')
    s, body, h = g.go('GET', '/storage/../.env')
    check(s in (403, 404), f'.env via traversal: {s}')
    s, body, h = g.go('GET', '/composer.json')
    check(s == 404, f'composer.json served: {s}')
    s, body, h = g.go('GET', '/server.php')
    check(s == 404, f'php file executable: {s}')
    s, body, h = g.go('GET', '/.env')
    check(s in (403, 404), f'.env served: {s}')
    g.get('/robots.txt')
    s, body, h = g.get('/sitemap.xml')
    check('<urlset' in body, 'sitemap')
    # CSRF: a POST without token is rejected with 419
    g.go('POST', '/login', b'email=a%40b.c&password=x', {'Content-Type': 'application/x-www-form-urlencoded'}, expect=419)
    # Webhook without/with bad signature -> 401
    g.go('POST', '/webhooks/shkeeper', b'{}', {'Content-Type': 'application/json'}, expect=401)
    ts = str(int(time.time()))
    g.go('POST', '/webhooks/shkeeper', b'{}', {'Content-Type': 'application/json', 'X-Shkeeper-Timestamp': ts, 'X-Shkeeper-Signature': 'bad'}, expect=401)
    # Admin is not reachable for guests
    s, body, h = g.go('GET', '/admin', follow=False)
    check(s == 302 and '/login' in h['Location'], f'/admin for guest -> {s} {h.get("Location")}')


@step('admin onboarding: CLI-created admin must enable 2FA before /admin')
def admin_onboarding():
    a = Client('admin')
    login(a, 'admin@live.test', 'Admin-live-pass-2026')
    s, body, h = a.go('GET', '/admin')
    check('/account/two-factor' in a.last[1], f'admin without 2FA reached {a.last[1]}')
    page = a.last[2]
    m = re.search(r'Setup key: <span class="mono">([A-Z2-7 ]+)</span>', page)
    if not m:
        confirm_password(a, 'Admin-live-pass-2026')
        a.get('/account/two-factor')
        page = a.last[2]
        m = re.search(r'Setup key: <span class="mono">([A-Z2-7 ]+)</span>', page)
    check(m, 'no setup key on two-factor page:\n' + text_of(page)[:500])
    secret = m.group(1).replace(' ', '')
    a.form('/account/two-factor', '/account/two-factor', {'code': fresh_totp(secret)}, page=page)
    codes = re.findall(r'\b[a-zA-Z0-9]{5}-[a-zA-Z0-9]{5}\b', a.last[2])
    check(len(codes) >= 8, f'recovery codes not shown: {a.flash()}')
    STATE['admin_secret'] = secret
    STATE['admin_recovery'] = codes[:8]
    a.get('/admin')
    check('/admin' == urllib.parse.urlparse(a.last[1]).path or a.last[1].endswith('/admin'), a.last[1])
    STATE['admin'] = a


@step('admin: categories and settings for the test run')
def admin_setup():
    a = STATE['admin']
    confirm_password(a, 'Admin-live-pass-2026')
    for name, slug in [('Software', 'software'), ('E-books', 'e-books'), ('Licences', 'licences')]:
        a.form('/admin/categories', '/admin/categories', {'name': name, 'slug': slug})
    check(sql("select count(*) from categories") == '3', 'categories not created')
    # Payout hold 0 days so the payout flow can run today; min payout 1.00
    a.get('/admin/settings')
    a.form('/admin/settings', '/admin/settings', {'payout_hold_days': '0', 'min_payout_minor': '100'})
    a.expect_flash('saved')
    check(sql("select value from settings where key='payout_hold_days'") in ('0', '"0"'), 'setting not stored: ' + sql("select key, value from settings"))


@step('buyer: register, verify email, sign in')
def buyer_register():
    b = Client('buyer')
    pos = mail_pos()
    register(b, 'Live Buyer', 'buyer@live.test')
    check('/email/verify' in b.last[1] or 'verify' in b.last[2].lower(), f'after register: {b.last[1]}')
    chunk = wait_mail(pos, 'buyer@live.test', 'Verify')
    link = [u for u in mail_links(chunk) if '/email/verify/' in u]
    check(link, 'no verification link in mail')
    b.get(link[0])
    check(sql("select email_verified_at is not null from users where email='buyer@live.test'") == 't', 'email not verified')
    STATE['buyer'] = b
    # honeypot: a bot filling "website" is rejected
    bot = Client('bot')
    bot.get('/register')
    page = bot.last[2]
    time.sleep(3.2)
    bot.form('/register', '/register', {'name': 'Bot', 'email': 'bot@live.test', 'password': PW, 'password_confirmation': PW, 'accept_terms': '1', 'website': 'http://spam'}, page=page)
    check(sql("select count(*) from users where email='bot@live.test'") == '0', 'honeypot let a bot register')
    # too fast submission rejected
    fast = Client('fast')
    fast.get('/register')
    fast.form('/register', '/register', {'name': 'Fast', 'email': 'fast@live.test', 'password': PW, 'password_confirmation': PW, 'accept_terms': '1'}, page=fast.last[2])
    check(sql("select count(*) from users where email='fast@live.test'") == '0', 'time trap let an instant submission register')


def png_bytes(w=64, h=48):
    import zlib, struct as st
    rows = b''.join(b'\x00' + b''.join(bytes([(x * 4) % 256, (y * 5) % 256, 120]) for x in range(w)) for y in range(h))
    def chunk(t, d):
        return st.pack('>I', len(d)) + t + d + st.pack('>I', zlib.crc32(t + d) & 0xffffffff)
    return b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', st.pack('>IIBBBBB', w, h, 8, 2, 0, 0, 0)) + chunk(b'IDAT', zlib.compress(rows)) + chunk(b'IEND', b'')


EICAR = b'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*'


@step('seller: register, apply, admin approves')
def seller_onboarding():
    sl = Client('seller')
    pos = mail_pos()
    register(sl, 'Live Seller', 'seller@live.test')
    chunk = wait_mail(pos, 'seller@live.test', 'Verify')
    sl.get([u for u in mail_links(chunk) if '/email/verify/' in u][0])
    sl.form('/sell', '/sell', {'display_name': 'Live Software Studio', 'payout_crypto': 'BTC', 'payout_currency': 'USD',
                               'payout_address': 'bc1qliveseller0000000000000000000000000000', 'about': 'We make tools.', 'accept_seller_terms': '1'})
    check(sql("select status from seller_profiles") == 'pending', f'seller profile not pending: {sl.last[1]} {sl.flash()}')
    sl.get('/seller', expect=None)
    a = STATE['admin']
    pos = mail_pos()
    a.form('/admin/sellers', '/approve', {'commission_bps': '1000'})
    a.expect_flash('approved')
    wait_mail(pos, 'seller@live.test', 'seller application')
    check(sql("select role from users where email='seller@live.test'") == 'seller', 'role not seller')
    STATE['seller'] = sl


def create_product(sl, title, price, delivery, extra=None):
    f = {'title': title, 'description': f'{title}. Live test product with a long enough description.', 'price': price,
         'currency': 'USD', 'delivery_type': delivery, 'category_id': sql("select id from categories where slug='software'")}
    f.update(extra or {})
    sl.form('/seller/products/new', '/seller/products', f)
    check('/edit' in sl.last[1], f'product create did not go to edit page: {sl.last[1]} {sl.flash()}')
    return int(re.search(r'/seller/products/(\d+)/edit', sl.last[1]).group(1))


@step('seller: products with files (virus scan), images, licence keys, manual delivery')
def seller_products():
    sl = STATE['seller']
    pid = create_product(sl, 'Live PDF Toolkit', '19.00', 'instant', {'stock': '50'})
    edit = f'/seller/products/{pid}/edit'
    sl.form(edit, f'/seller/products/{pid}/files', files={'file': ('toolkit.zip', b'PK\x03\x04 live toolkit payload ' * 40, 'application/zip')})
    sl.expect_flash('upload')
    wait_for(lambda: sql(f"select scan_status from product_files where product_id={pid}") == 'clean', 'file scanned clean')
    sl.form(edit, f'/seller/products/{pid}/images', {'alt_text': 'Toolkit screenshot'}, files={'image': ('shot.png', png_bytes(), 'image/png')})
    check(sql(f"select count(*) from product_images where product_id={pid}") == '1', 'image not stored: ' + str(sl.flash()))
    # infected upload is detected, deleted and the product is not approvable
    bad = create_product(sl, 'Live Infected Upload', '5.00', 'instant')
    sl.form(f'/seller/products/{bad}/edit', f'/seller/products/{bad}/files', files={'file': ('eicar.zip', EICAR, 'application/zip')})
    wait_for(lambda: sql(f"select count(*) from product_files where product_id={bad} and scan_status='infected'") == '1', 'EICAR detected')
    sl.form(edit, f'/seller/products/{pid}/submit')
    keys = create_product(sl, 'Live Licence Key', '9.00', 'instant')
    sl.form(f'/seller/products/{keys}/edit', f'/seller/products/{keys}/keys', {'keys': 'KEY-AAAA-0001\nKEY-AAAA-0002\nKEY-AAAA-0003'})
    check(sql(f"select count(*) from product_license_keys where product_id={keys}") == '3', 'keys not stored')
    sl.form(f'/seller/products/{keys}/edit', f'/seller/products/{keys}/submit')
    manual = create_product(sl, 'Live Icon Subscription', '29.00', 'manual', {'access_days': '30'})
    sl.form(f'/seller/products/{manual}/edit', f'/seller/products/{manual}/submit')
    STATE['products'] = {'file': pid, 'keys': keys, 'manual': manual, 'infected': bad}
    a = STATE['admin']
    for p in (pid, keys, manual):
        a.get(f'/admin/products/{p}')
        a.form(f'/admin/products/{p}', f'/admin/products/{p}/status', {'status': 'active'})
        a.expect_flash('now active')
    # the infected product cannot be approved
    a.form(f'/admin/products/{bad}', f'/admin/products/{bad}/status', {'status': 'active'})
    check(sql(f"select status from products where id={bad}") != 'active', 'infected product approved')
    g = Client('guest2')
    s, body, h = g.get('/products')
    for t in ('Live PDF Toolkit', 'Live Licence Key', 'Live Icon Subscription'):
        check(t in body, f'{t} not in catalog')
    check('Live Infected Upload' not in body, 'infected product listed')
    s, body, h = g.get('/products?q=toolkit')
    check('Live PDF Toolkit' in body and 'Live Licence Key' not in body, 'search')
    slug = sql(f"select slug from products where id={pid}")
    s, body, h = g.get(f'/products/{slug}')
    img = re.search(r'<img[^>]+src="([^"]+)"', body)
    check(img, 'product image not shown')
    s, imgbody, h = g.get(img.group(1))
    check(h['Content-Type'] == 'image/webp' and imgbody[:4] == b'RIFF', f'image not re-encoded to webp: {h["Content-Type"]}')
    STATE['slugs'] = {k: sql(f"select slug from products where id={v}") for k, v in STATE['products'].items()}


@step('buyer: crypto purchase via Shkeeper (address + QR), webhook, delivery, download, invoice, emails')
def crypto_purchase():
    b = STATE['buyer']
    pos = mail_pos()
    b.form(f"/products/{STATE['slugs']['file']}", '/cart/items')
    b.get('/checkout')
    b.form('/checkout', '/checkout', {'payment_method': 'crypto', 'crypto': 'BTC', 'accept_terms': '1'})
    page = b.last[2]
    check('/pay' in b.last[1] or 'BTC' in page, f'checkout did not reach payment page: {b.last[1]}')
    if 'data:image/svg+xml' not in page and forms_of(page):
        b.form(b.last[1].replace(C.BASE, ''), '/pay', {'crypto': 'BTC'}, page=page)
        page = b.last[2]
    check('data:image/svg+xml' in page or '<svg' in page, 'no QR code on payment page')
    order = sql("select public_id from orders order by id desc limit 1")
    STATE['order1'] = order
    inv = json.loads(subprocess.run(['curl', '-s', '-H', 'X-Shkeeper-Api-Key: live-api-key-0123456789', f'{MOCK}/api/v1/invoices/{order}'], capture_output=True, text=True).stdout)['invoices'][0]
    check(inv['wallet'] in page, 'wallet address not shown on payment page')
    r = mock(f'/__mock/pay/{order}')
    check(r['callback_http_status'] == 202, f'webhook answered {r}')
    wait_for(lambda: sql(f"select status from orders where public_id='{order}'") == 'delivered', 'order delivered', 90)
    b.get(f'/orders/{order}')
    page = b.last[2]
    dl = re.search(r'href="([^"]+/files/\d+\?[^"]*)"', page)
    check(dl, 'no download link on order page')
    s, data, h = b.get(html.unescape(dl.group(1)))
    check(isinstance(data, bytes) and data.startswith(b'PK'), f'download content wrong: {h.get("Content-Type")}')
    check('attachment' in (h.get('Content-Disposition') or ''), 'download not an attachment')
    inv_link = re.search(r'href="([^"]+/invoice)"', page)
    check(inv_link, 'no invoice link')
    wait_for(lambda: sql(f"select count(*) from invoices i join orders o on o.id=i.order_id where o.public_id='{order}'") == '1', 'invoice generated')
    s, pdf, h = b.get(html.unescape(inv_link.group(1)))
    check(isinstance(pdf, bytes) and pdf[:4] == b'%PDF', 'invoice is not a PDF')
    for subj in ('placed', 'paid'):
        wait_mail(pos, 'buyer@live.test', subj)
    wait_mail(pos, 'seller@live.test', 'New sale')
    # a signed download link does not work for another user
    other = Client('other')
    s, body, h = other.go('GET', html.unescape(dl.group(1)), follow=False)
    check(s in (302, 403, 404), f'download link usable by a guest: {s}')
    # replaying the same webhook does not double-pay
    before = sql("select count(*) from payments where kind='charge' and status='confirmed'")
    mock(f'/__mock/pay/{order}')
    time.sleep(3)
    check(sql("select count(*) from payments where kind='charge' and status='confirmed'") == before, 'replayed webhook created a second payment')


def balance_of(email):
    return int(sql(f"select balance_minor from users where email='{email}'"))


def checkout(c, slug, method, crypto='BTC', qty=None, coupon=None):
    c.form(f'/products/{slug}', '/cart/items', {'quantity': qty} if qty else None)
    if coupon:
        c.form('/checkout', '/checkout/coupon', {'code': coupon})
        c.expect_flash('applied')
    c.form('/checkout', '/checkout', {'payment_method': method, 'crypto': crypto, 'accept_terms': '1'})
    return sql("select public_id from orders order by id desc limit 1")


@step('buyer: partial payment, then overpayment credited to the balance; licence key delivered')
def partial_and_overpay():
    b = STATE['buyer']
    order = checkout(b, STATE['slugs']['keys'], 'crypto', 'LTC')
    r = mock(f'/__mock/pay/{order}', {'amount': '4.00'})
    check(r['callback_http_status'] == 202, r)
    time.sleep(2)
    check(sql(f"select status from orders where public_id='{order}'") == 'pending', 'partial payment marked the order paid')
    b.get(f'/orders/{order}/pay')
    check('We received 4.00 USD so far. 5.00 USD is still due.' in text_of(b.last[2]), 'partial amount not shown to buyer')
    before = balance_of('buyer@live.test')
    r = mock(f'/__mock/pay/{order}', {'amount': '12.00'})
    check(r['callback_http_status'] == 202, r)
    wait_for(lambda: sql(f"select status from orders where public_id='{order}'") == 'delivered', 'key order delivered', 90)
    wait_for(lambda: balance_of('buyer@live.test') == before + 300, f'overpayment credit (balance {balance_of("buyer@live.test")})', 30)
    b.get(f'/orders/{order}')
    check(re.search(r'KEY-AAAA-000\d', b.last[2]), 'licence key not shown on order page')
    check(sql(f"select count(*) from product_license_keys where order_item_id is not null") == '1', 'key not assigned exactly once')


@step('gift card, coupon, balance checkout, manual delivery by the seller, subscription access')
def giftcard_coupon_manual():
    a, b, sl = STATE['admin'], STATE['buyer'], STATE['seller']
    confirm_password(a, 'Admin-live-pass-2026')
    a.form('/admin/gift-cards', '/admin/gift-cards', {'amount': '50.00', 'currency': 'USD'})
    code = re.search(r'Code: ([A-Z0-9-]{10,})', ' '.join(a.flash()))
    check(code, f'gift card code not shown: {a.flash()}')
    check(code.group(1) not in sql("select string_agg(code_hash, ',') from gift_cards"), 'gift card stored in plain text')
    before = balance_of('buyer@live.test')
    b.form('/wallet', '/wallet/redeem', {'code': code.group(1)})
    b.expect_flash('50.00')
    check(balance_of('buyer@live.test') == before + 5000, 'gift card not credited')
    b.form('/wallet', '/wallet/redeem', {'code': code.group(1)})
    check(balance_of('buyer@live.test') == before + 5000, 'gift card redeemed twice')
    a.form('/admin/coupons', '/admin/coupons', {'code': 'LIVE10', 'type': 'percent', 'value': '10', 'max_per_user': '1'})
    check(sql("select count(*) from coupons where code='LIVE10'") == '1', f'coupon not created {a.flash()}')
    pos = mail_pos()
    bal = balance_of('buyer@live.test')
    order = checkout(b, STATE['slugs']['manual'], 'balance', coupon='LIVE10')
    if sql(f"select status from orders where public_id='{order}'") == 'pending':
        b.form(f'/orders/{order}/pay', '/pay-balance')
    check(sql(f"select status, total_minor from orders where public_id='{order}'") == 'paid|2610', 'balance order: ' + sql(f"select status, total_minor from orders where public_id='{order}'"))
    check(balance_of('buyer@live.test') == bal - 2610, 'balance not debited')
    wait_mail(pos, 'seller@live.test', 'New sale')
    sl.get('/seller')
    item = sql(f"select oi.id from order_items oi join orders o on o.id=oi.order_id where o.public_id='{order}'")
    sl.form('/seller', f'/seller/items/{item}/deliver', {'payload': 'Icon pack portal: https://icons.example.test/live user live-buyer'})
    wait_for(lambda: sql(f"select status from orders where public_id='{order}'") == 'delivered', 'manual order delivered', 30)
    b.get(f'/orders/{order}')
    check('icons.example.test' in b.last[2], 'manual delivery payload not visible to buyer')
    check(sql(f"select access_expires_at::date - now()::date from order_items where id={item}") in ('30', '29'), 'subscription access not 30 days')
    wait_mail(pos, 'buyer@live.test', 'delivered')
    # the coupon is limited to one use per buyer
    b.form(f"/products/{STATE['slugs']['file']}", '/cart/items')
    b.form('/checkout', '/checkout/coupon', {'code': 'LIVE10'})
    check(not any('applied' in f for f in b.flash()), 'coupon used twice by the same buyer')
    b.get('/cart')
    rm = [f for f in forms_of(b.last[2]) if f['action'].endswith(f"/cart/items/{STATE['products']['file']}") and ['_method', 'DELETE'] in f['fields']]
    check(rm, 'no remove form in cart')
    b.form('/cart', f"/cart/items/{STATE['products']['file']}", index=[f['fields'] for f in forms_of(b.last[2]) if f['action'].endswith(f"/cart/items/{STATE['products']['file']}")].index(rm[0]['fields']), page=b.last[2])
    check('Your cart is empty' in b.last[2], 'cart not empty after removal')


@step('reviews and wishlist')
def reviews_wishlist():
    b, a = STATE['buyer'], STATE['admin']
    slug = STATE['slugs']['file']
    b.form(f'/products/{slug}', f'/products/{slug}/reviews', {'rating': '5', 'title': 'Works well', 'body': 'Converted my docs in seconds.'})
    b.expect_flash('review')
    g = Client('guest3')
    s, body, h = g.get(f'/products/{slug}')
    check('Converted my docs in seconds.' in body, 'review not visible')
    rid = sql("select id from product_reviews limit 1")
    a.form('/admin/reviews', f'/admin/reviews/{rid}/status')
    s, body, h = g.get(f'/products/{slug}')
    check('Converted my docs in seconds.' not in body, 'hidden review still visible')
    b.form(f"/products/{STATE['slugs']['keys']}", '/wishlist')
    b.get('/wishlist')
    check('Live Licence Key' in b.last[2], 'wishlist missing product')
    b.form('/wishlist', f"/wishlist/{STATE['products']['keys']}")
    b.get('/wishlist')
    check('Live Licence Key' not in b.last[2], 'wishlist remove failed')


@step('support tickets: reply emails the customer, internal notes stay internal')
def tickets():
    b, a = STATE['buyer'], STATE['admin']
    b.form('/tickets/new', '/tickets', {'subject': 'Download question', 'category': 'order_issue', 'body': 'Where is my file?'})
    tid = sql("select id from tickets order by id desc limit 1")
    pos = mail_pos()
    a.form(f'/tickets/{tid}', f'/tickets/{tid}/messages', {'body': 'INTERNAL: buyer is fine', 'internal': '1'})
    a.form(f'/tickets/{tid}', f'/tickets/{tid}/messages', {'body': 'It is on your order page.'})
    wait_mail(pos, 'buyer@live.test', 'New reply on ticket')
    b.get(f'/tickets/{tid}')
    check('It is on your order page.' in b.last[2] and 'INTERNAL: buyer is fine' not in b.last[2], 'internal note leaked or reply missing')
    check('INTERNAL' not in mails_since(pos), 'internal note emailed')
    a.form(f'/tickets/{tid}', f'/tickets/{tid}/assign', {'assigned_to': sql("select id from users where email='admin@live.test'")})
    b.form(f'/tickets/{tid}', f'/tickets/{tid}/close')
    check(sql(f"select status from tickets where id={tid}") == 'closed', 'ticket not closed')


@step('refunds: balance, manual and on-chain via Shkeeper payout API')
def refunds():
    a, b = STATE['admin'], STATE['buyer']
    order = STATE['order1']
    page = f'/admin/orders/{order}'
    confirm_password(a, 'Admin-live-pass-2026')
    bal = balance_of('buyer@live.test')
    pos = mail_pos()
    a.form(page, f'/admin/orders/{order}/refunds', {'method': 'balance', 'amount': '5.00', 'reason': 'goodwill'})
    a.expect_flash('refund')
    check(balance_of('buyer@live.test') == bal + 500, 'balance refund not credited')
    check(sql(f"select status from orders where public_id='{order}'") == 'partially_refunded', 'order not partially refunded')
    wait_mail(pos, 'buyer@live.test', 'Refund')
    a.form(page, f'/admin/orders/{order}/refunds', {'method': 'manual', 'amount': '2.00', 'reference': 'bank-ref-123'})
    a.expect_flash('refund')
    a.form(page, f'/admin/orders/{order}/refunds', {'method': 'shkeeper', 'amount': '3.00', 'crypto': 'BTC', 'destination': 'bc1qbuyerrefund00000000000000000000000000'})
    pid = sql("select id from payments where kind='refund' and provider='shkeeper' order by id desc limit 1")
    check(pid and sql(f"select status from payments where id={pid}") == 'pending', 'crypto refund not pending')
    # too much: 19.00 - 5 - 2 - 3 pending = 9.00 left
    a.form(page, f'/admin/orders/{order}/refunds', {'method': 'balance', 'amount': '9.50'})
    check(any('exceeds' in f for f in a.flash()), f'over-refund not rejected: {a.flash()}')
    r = mock(f'/__mock/payout-result/refund-{pid}', {'status': 'SUCCESS'})
    check(r.get('callback_http_status') == 202, r)
    wait_for(lambda: sql(f"select status from payments where id={pid}") == 'confirmed', 'crypto refund confirmed', 30)
    check(sql(f"select refunded_minor from orders where public_id='{order}'") == '1000', 'refunded total wrong')
    check(sql(f"select provider_reference is not null from payments where id={pid}") == 't', 'refund txid not stored')


@step('payouts: address change with email confirmation, request, approval, Shkeeper payout and callback')
def payouts():
    a, sl = STATE['admin'], STATE['seller']
    confirm_password(a, 'Admin-live-pass-2026')
    a.form('/admin/settings', '/admin/settings', {'payout_address_cooldown_hours': '0'})
    pos = mail_pos()
    confirm_password(sl)
    sl.form('/seller/payout-settings', '/seller/payout-settings', {'payout_crypto': 'BTC', 'payout_address': 'bc1qnewselleraddress000000000000000000000'})
    chunk = wait_mail(pos, 'seller@live.test', 'Confirm your new payout address')
    link = [u for u in mail_links(chunk) if '/payout-address/confirm/' in u]
    check(link, 'no confirmation link')
    sl.get(link[0])
    sl.form(urllib.parse.urlparse(link[0]).path, '/seller/payout-address/confirm/' + link[0].rstrip('/').split('/')[-1], page=sl.last[2])
    check(sql("select payout_address from seller_profiles") == 'bc1qnewselleraddress000000000000000000000', 'payout address not changed')
    wait_mail(pos, 'seller@live.test', 'payout address was changed')
    sl.get('/seller/payouts')
    sl.form('/seller/payouts', '/seller/payouts', {'amount': '10.00', 'currency': 'USD'})
    payout = sql("select id from payouts order by id desc limit 1")
    check(payout, f'payout not requested: {sl.flash()}')
    a.form('/admin/payouts', f'/admin/payouts/{payout}/approve')
    a.form('/admin/payouts', f'/admin/payouts/{payout}/send')
    check(sql(f"select status from payouts where id={payout}") == 'processing', 'payout not processing: ' + sql(f"select status, failure_reason from payouts where id={payout}"))
    pos = mail_pos()
    r = mock(f'/__mock/payout-result/payout-{payout}', {'status': 'SUCCESS'})
    check(r.get('callback_http_status') == 202, r)
    wait_for(lambda: sql(f"select status from payouts where id={payout}") == 'paid', 'payout paid', 30)
    wait_mail(pos, 'seller@live.test', 'Payout')
    rc, out = artisan('shop:reconcile-payouts')
    check(rc == 0, 'ledger reconciliation failed:\n' + out)


@step('account security: 2FA login with code and recovery code, sessions, email change, password change')
def account_security():
    b = STATE['buyer']
    confirm_password(b)
    b.get('/account/two-factor')
    m = re.search(r'Setup key: <span class="mono">([A-Z2-7 ]+)</span>', b.last[2])
    check(m, 'no setup key')
    secret = m.group(1).replace(' ', '')
    b.form('/account/two-factor', '/account/two-factor', {'code': fresh_totp(secret)})
    codes = re.findall(r'\b[a-zA-Z0-9]{5}-[a-zA-Z0-9]{5}\b', b.last[2])
    check(len(codes) >= 8, 'no recovery codes')
    b2 = Client('buyer-2nd-device')
    pos = mail_pos()
    login(b2, 'buyer@live.test', secret=secret)
    check('/two-factor-challenge' not in b2.last[1], 'TOTP login failed')
    wait_mail(pos, 'buyer@live.test', 'New sign-in')
    b3 = Client('buyer-3rd-device')
    b3.form('/login', '/login', {'email': 'buyer@live.test', 'password': PW})
    b3.form('/two-factor-challenge', '/two-factor-challenge', {'code': codes[0]})
    check('/two-factor-challenge' not in b3.last[1], 'recovery code login failed')
    b4 = Client('buyer-4th-device')
    b4.form('/login', '/login', {'email': 'buyer@live.test', 'password': PW})
    b4.form('/two-factor-challenge', '/two-factor-challenge', {'code': codes[0]})
    check('/two-factor-challenge' in b4.last[1], 'recovery code accepted twice')
    b.get('/account')
    check(len(re.findall(r'/account/sessions/[A-Za-z0-9_-]{10,}', b.last[2])) >= 2, 'other sessions not listed')
    b.form('/account', '/account/sessions')
    b2.get('/orders', expect=None)
    check('/login' in b2.last[1], 'ended session still signed in')
    # email change: confirmation to the new address, notice to the old one
    pos = mail_pos()
    confirm_password(b)
    b.form('/account', '/account/email', {'email': 'buyer.new@live.test', 'current_password': PW})
    wait_mail(pos, 'buyer@live.test', 'Email change requested')
    chunk = wait_mail(pos, 'buyer.new@live.test', 'Confirm your new email')
    link = [u for u in mail_links(chunk) if '/account/email/confirm/' in u]
    b.get(link[0])
    b.form(urllib.parse.urlparse(link[0]).path, urllib.parse.urlparse(link[0]).path, page=b.last[2])
    check(sql("select email from users where id=2") == 'buyer.new@live.test', 'email not changed')
    # password change signs out other sessions
    b5 = Client('buyer-5th-device')
    login(b5, 'buyer.new@live.test', secret=secret)
    newpw = 'Another-horse-battery-8'
    b.form('/account', '/account/password', {'current_password': PW, 'password': newpw, 'password_confirmation': newpw})
    b5.get('/orders', expect=None)
    check('/login' in b5.last[1], 'other session survived a password change')
    b.get('/orders')
    STATE['buyer_pw'] = newpw
    STATE['buyer_secret'] = secret


@step('admin: every page, exports, reports, roles and permissions, balance adjustment, announcement, audit log')
def admin_tour():
    a = STATE['admin']
    pages = ['/admin', '/admin/orders', '/admin/payments', '/admin/webhooks', '/admin/products', '/admin/categories', '/admin/users',
             '/admin/sellers', '/admin/payouts', '/admin/reconciliation', '/admin/coupons', '/admin/gift-cards', '/admin/exchange-rates',
             '/admin/tickets', '/admin/reviews', '/admin/reports', '/admin/exports', '/admin/announcements', '/admin/settings', '/admin/audit',
             f"/admin/orders/{STATE['order1']}", f"/admin/products/{STATE['products']['file']}", '/admin/users/2']
    for p in pages:
        a.get(p)
    check('No differences' in text_of(a.get('/admin/reconciliation')[1]) or 'match' in text_of(a.last[2]).lower(), 'reconciliation shows differences:\n' + text_of(a.last[2])[:600])
    s, body, h = a.get('/admin/reports')
    check('<svg' in body, 'no charts on reports page')
    confirm_password(a, 'Admin-live-pass-2026')
    a.get('/admin/exports')
    exp_form = [f for f in forms_of(a.last[2]) if 'exports/download' in f['action']][0]
    types = [t for t in exp_form.get('choices', {}).get('type', []) if t]
    check(len(types) >= 4, f'export types: {types}')
    for t in types:
        s, csv, h = a.form('/admin/exports', '/admin/exports/download', {'type': t})
        check('text/csv' in h['Content-Type'], f'export {t}: {h["Content-Type"]} {a.flash()}')
        check(isinstance(csv, str) and csv.count('\n') >= 1, f'export {t} empty')
    STATE['export_types'] = types
    s, body, h = a.get('/admin/audit?action=refund.')
    check('refund.created' in body, 'audit filter')
    # balance adjustment
    bal = balance_of('buyer.new@live.test')
    a.form('/admin/users/2', '/admin/users/2/balance', {'direction': 'credit', 'amount': '1.25', 'currency': 'USD', 'reason': 'Live test goodwill'})
    check(balance_of('buyer.new@live.test') == bal + 125, 'balance adjustment')
    # a support agent sees tickets but not settings or exports
    sup = Client('support')
    register(sup, 'Live Support', 'support@live.test')
    a.form('/admin/users/' + sql("select id from users where email='support@live.test'"), '/role', {'role': 'support'})
    sup.get('/admin', expect=None)
    check('/account/two-factor' in sup.last[1] or '/email/verify' in sup.last[1], f'support without 2FA reached {sup.last[1]}')
    sql("update users set email_verified_at = now() where email='support@live.test'")
    confirm_password(sup)
    sup.get('/account/two-factor')
    sec = re.search(r'Setup key: <span class="mono">([A-Z2-7 ]+)</span>', sup.last[2]).group(1).replace(' ', '')
    sup.form('/account/two-factor', '/account/two-factor', {'code': fresh_totp(sec)})
    for p, want in [('/admin', 200), ('/admin/tickets', 200), ('/admin/orders', 200), ('/admin/users', 200), ('/admin/reviews', 200),
                    ('/admin/settings', 403), ('/admin/exports', 403), ('/admin/payouts', 403), ('/admin/gift-cards', 403), ('/admin/coupons', 403)]:
        st, _, _ = sup.go('GET', p)
        check(st == want, f'support role: {p} -> {st}, expected {want}')
    oid = STATE['order1']
    sup.get(f'/admin/orders/{oid}')
    check(not any('/refunds' in f['action'] for f in forms_of(sup.last[2])), 'support sees the refund form')
    st, _, _ = sup.go('POST', f'/admin/orders/{oid}/refunds', urllib.parse.urlencode({'_token': re.search(r'name="_token" value="([^"]+)"', sup.last[2]).group(1), 'method': 'balance', 'amount': '1.00'}).encode(), {'Content-Type': 'application/x-www-form-urlencoded'})
    check(st == 403, f'support refund POST -> {st}')
    # announcement on every page
    a.form('/admin/announcements', '/admin/announcements', {'title': 'Live maintenance notice', 'body': 'Short maintenance tonight.'})
    s, body, h = Client('guest4').get('/')
    check('Live maintenance notice' in body, 'announcement not shown')
    # exchange rate and currency switch
    a.form('/admin/exchange-rates', '/admin/exchange-rates', {'base': 'USD', 'quote': 'EUR', 'rate': '0.9'})
    g = Client('guest5')
    g.form('/', '/cart/currency', {'currency': 'EUR'})
    s, body, h = g.get('/products')
    check('EUR' in body, 'EUR prices not shown')


@step('scheduler: unpaid order expires and releases stock; lost webhook recovered by reconciliation')
def scheduler_jobs():
    b = STATE['buyer']
    b.get('/orders', expect=None)
    if '/login' in b.last[1]:
        login(b, 'buyer.new@live.test', STATE['buyer_pw'], STATE['buyer_secret'])
    fid = STATE['products']['file']
    stock_before = int(sql(f"select stock from products where id={fid}"))
    order = checkout(b, STATE['slugs']['file'], 'crypto', 'BTC', qty='2')
    qty = int(sql(f"select sum(quantity) from order_items oi join orders o on o.id=oi.order_id where o.public_id='{order}'"))
    check(qty == 2, f'order quantity {qty}, expected 2 (cart not empty before?)')
    check(int(sql(f"select stock from products where id={fid}")) == stock_before - 2, 'stock not reserved by the unpaid order')
    sql(f"update orders set expires_at = now() - interval '1 minute' where public_id='{order}'")
    wait_for(lambda: sql(f"select status from orders where public_id='{order}'") == 'expired', 'scheduler expired the order', 90)
    check(int(sql(f"select stock from products where id={fid}")) == stock_before, 'stock not released after expiry')
    # lost webhook: Shkeeper cannot reach the shop while the buyer pays
    order2 = checkout(b, STATE['slugs']['file'], 'crypto', 'BTC')
    subprocess.run(['docker', 'stop', 'shoplive-nginx'], capture_output=True)
    try:
        r = mock(f'/__mock/pay/{order2}')
        check(r['callback_http_status'] == 0, f'callback should have failed: {r}')
    finally:
        subprocess.run(['docker', 'start', 'shoplive-nginx'], capture_output=True)
        time.sleep(2)
    check(sql(f"select status from orders where public_id='{order2}'") == 'pending', 'paid without webhook?')
    print('      waiting for shop:reconcile-payments (runs every 5 minutes)...', flush=True)
    wait_for(lambda: sql(f"select status from orders where public_id='{order2}'") in ('paid', 'delivered'), 'reconciliation picked up the payment', 330)


@step('commission levels, payment gateway switches and order limits')
def commission_and_gateway():
    a, b = STATE['admin'], STATE['buyer']
    confirm_password(a, 'Admin-live-pass-2026')
    cat = sql("select id from categories where slug='software'")
    a.form('/admin/commission', f'/admin/commission/categories/{cat}', {'commission_percent': '15'})
    a.expect_flash('15.00%')
    a.get(f"/admin/products/{STATE['products']['manual']}")
    check('commission 15.00% (category rate)' in text_of(a.last[2]), 'effective commission not shown: ' + text_of(a.last[2])[:300])
    order = checkout(b, STATE['slugs']['manual'], 'balance')
    if sql(f"select status from orders where public_id='{order}'") == 'pending':
        b.form(f'/orders/{order}/pay', '/pay-balance')
    check(sql(f"select oi.commission_bps from order_items oi join orders o on o.id=oi.order_id where o.public_id='{order}'") == '1500', 'category commission not frozen on the line')
    STATE['manual_order2'] = order
    # Switch balance payments off: checkout no longer offers it and refuses it.
    a.form('/admin/gateway', '/admin/gateway', {'payments_balance_enabled': '0', 'order_min': '1', 'order_max': '1000'})
    a.expect_flash('Saved')
    b.form(f"/products/{STATE['slugs']['file']}", '/cart/items')
    b.get('/checkout')
    check('Shop balance (' not in b.last[2], 'balance still offered while switched off')
    a.form('/admin/gateway', '/admin/gateway', {'payments_balance_enabled': '1', 'order_min': '0', 'order_max': '0'})
    b.get('/checkout')
    check('Shop balance (' in b.last[2], 'balance not offered again')
    b.get('/cart')
    forms = [f for f in forms_of(b.last[2]) if f['action'].endswith(f"/cart/items/{STATE['products']['file']}")]
    b.form('/cart', f"/cart/items/{STATE['products']['file']}", index=[f['fields'] for f in forms].index([f for f in forms if ['_method', 'DELETE'] in f['fields']][0]['fields']), page=b.last[2])
    a.get('/admin/gateway')
    page = text_of(a.last[2])
    check('rejected signature' in page and 'GET /api/v1/crypto' in page, 'gateway log incomplete')


@step('dispute: buyer opens, seller answers, staff sends a replacement key; earnings held meanwhile')
def dispute_flow():
    b, sl, a = STATE['buyer'], STATE['seller'], STATE['admin']
    item = sql(f"select oi.id from order_items oi join orders o on o.id=oi.order_id where oi.product_id={STATE['products']['keys']} and o.status='delivered' order by oi.id limit 1")
    order = sql(f"select o.public_id from orders o join order_items oi on oi.order_id=o.id where oi.id={item}")
    old_key = None
    b.get(f'/orders/{order}')
    old_key = re.search(r'KEY-AAAA-000\d', b.last[2]).group(0)
    check('Report a problem with this item' in b.last[2], 'no dispute link on the order page')
    pos = mail_pos()
    b.form(f'/orders/{order}/items/{item}/dispute', f'/orders/{order}/items/{item}/dispute',
           {'reason': 'not_working', 'requested_outcome': 'replacement', 'body': 'The key is rejected as already used.'})
    dispute = sql('select id from disputes order by id desc limit 1')
    check(dispute and sql(f'select status from disputes where id={dispute}') == 'awaiting_seller', 'dispute not opened')
    wait_mail(pos, 'seller@live.test', 'Dispute #' + dispute)
    sl.get('/seller/payouts')
    check('held for open disputes' in text_of(sl.last[2]), 'disputed earnings not shown as held')
    sl.form(f'/disputes/{dispute}', f'/disputes/{dispute}/messages', {'body': 'Sorry, please try this one instead.'})
    check(sql(f'select status from disputes where id={dispute}') == 'awaiting_staff', 'seller reply did not hand over to staff')
    wait_mail(pos, 'buyer.new@live.test', 'New message on dispute')
    confirm_password(a, 'Admin-live-pass-2026')
    a.form(f'/disputes/{dispute}', f'/admin/disputes/{dispute}/resolve', {'action': 'replacement', 'note': 'New key issued.'}, index=1)
    a.expect_flash('replacement delivered')
    wait_mail(pos, 'buyer.new@live.test', 'closed')
    b.get(f'/orders/{order}')
    new_key = re.search(r'KEY-AAAA-000\d', b.last[2]).group(0)
    check(new_key != old_key, 'replacement key not shown')


@step('email template edited by staff is used for the next real email')
def email_template():
    a, b = STATE['admin'], STATE['buyer']
    a.form('/admin/email-templates/order_paid', '/admin/email-templates/order_paid',
           {'subject': 'Live thanks for order {order_number}', 'body': 'Thank you!\n\n{items}\n\nYour files: {order_url}'})
    a.expect_flash('Saved')
    pos = mail_pos()
    order = checkout(b, STATE['slugs']['file'], 'balance')
    chunk = wait_mail(pos, 'buyer.new@live.test', 'Live thanks for order')
    check('Thank you!' in chunk and order in re.sub(r'=\r?\n', '', chunk), 'custom body not used')
    a.form('/admin/email-templates/order_paid', '/admin/email-templates/order_paid', index=1)
    a.expect_flash('built-in text')


@step('bulk actions, analytics dashboard and system health on the live stack')
def bulk_dashboard_health():
    a = STATE['admin']
    confirm_password(a, 'Admin-live-pass-2026')
    s, csv, h = a.form('/admin/orders', '/admin/bulk/orders/export', {'scope': 'filtered'})
    check('text/csv' in h['Content-Type'] and csv.count('\n') >= 5, 'bulk order export')
    pid = STATE['products']['manual']
    a.get('/admin/products')
    a.form('/admin/products', '/admin/bulk/products', {'action': 'disabled', 'scope': 'selected', 'ids': [str(pid)]})
    check(sql(f'select status from products where id={pid}') == 'disabled', 'bulk disable')
    a.form('/admin/products', '/admin/bulk/products', {'action': 'active', 'scope': 'selected', 'ids': [str(pid)]})
    check(sql(f'select status from products where id={pid}') == 'active', 'bulk approve')
    a.get('/admin')
    page = text_of(a.last[2])
    for needle in ('Net revenue', 'Platform commission', 'Revenue trend', 'Order volume', 'Top products', 'Payment gateway health'):
        check(needle in page, f'dashboard lacks {needle}')
    # Let both heartbeats come in (the queue heartbeat job is queued every five minutes).
    wait_for(lambda: 'Queue worker: last job' in text_of(a.get('/admin/health')[1]) and 'Scheduler: last run' in text_of(a.last[2]), 'heartbeats', 330)
    page = text_of(a.last[2])
    for label in ('Queue worker', 'Scheduler', 'Connection', 'Virus scanner', 'Last successful Shkeeper webhook'):
        m = re.search(r'(OK|Check|Problem) ' + re.escape(label) + ':', page)
        check(m and m.group(1) == 'OK', f'health check {label}: {m.group(1) if m else "missing"}\n' + page[:1500])


@step('backup: scripts/backup.sh against the live data, restore into a scratch database, compare')
def backup_restore():
    dest = os.path.join(L, 'backups')
    out = subprocess.run(['sh', 'scripts/backup.sh', dest], cwd=APP, capture_output=True, text=True)
    check(out.returncode == 0, 'backup failed:\n' + out.stdout + out.stderr)
    target = re.search(r'Backup written to (\S+)', out.stdout).group(1)
    sums = subprocess.run(['sha256sum', '-c', 'SHA256SUMS'], cwd=target, capture_output=True, text=True)
    check(sums.returncode == 0, 'checksums: ' + sums.stdout + sums.stderr)
    listing = subprocess.run(['tar', '-tzf', os.path.join(target, 'files.tar.gz')], capture_output=True, text=True).stdout
    check('storage/app/private/products/' in listing and 'storage/invoices/' in listing and 'product-images' in listing, 'files archive incomplete:\n' + listing[:500])
    subprocess.run(['docker', 'exec', 'shoplive-db', 'psql', '-p', '5433', '-U', 'shop', '-d', 'shop', '-c', 'DROP DATABASE IF EXISTS shop_restore'], capture_output=True)
    subprocess.run(['docker', 'exec', 'shoplive-db', 'psql', '-p', '5433', '-U', 'shop', '-d', 'shop', '-c', 'CREATE DATABASE shop_restore'], capture_output=True, check=True)
    r = subprocess.run(['pg_restore', '-h', '127.0.0.1', '-p', '5433', '-U', 'shop', '-d', 'shop_restore', '--no-owner', os.path.join(target, 'database.dump')],
                       capture_output=True, text=True, env={**os.environ, 'PGPASSWORD': 'live-db-pass'})
    check(r.returncode == 0, 'pg_restore: ' + r.stderr[:800])
    for t in ('users', 'orders', 'order_items', 'payments', 'payouts', 'seller_ledger_entries', 'audit_log', 'product_files', 'invoices'):
        a_ = sql(f'select count(*) from {t}')
        b_ = subprocess.run(['docker', 'exec', 'shoplive-db', 'psql', '-p', '5433', '-U', 'shop', '-d', 'shop_restore', '-At', '-c', f'select count(*) from {t}'], capture_output=True, text=True).stdout.strip()
        check(a_ == b_, f'{t}: live {a_} vs restored {b_}')


@step('final health: no errors in logs, no failed jobs or webhooks, ledger reconciles, metrics clean')
def final_health():
    time.sleep(5)
    check(sql("select count(*) from failed_jobs") == '0', 'failed jobs: ' + sql("select left(exception, 400) from failed_jobs"))
    check(sql("select count(*) from webhook_events where status in ('failed','dead')") == '0', 'failed webhook events')
    check(sql("select count(*) from jobs") in ('0', '1', '2'), 'queue backlog: ' + sql("select count(*) from jobs"))
    rc, out = artisan('shop:reconcile-payouts')
    check(rc == 0, out)
    bad = []
    logdir = os.path.join(APP, 'storage', 'logs')
    for f in os.listdir(logdir):
        if f.startswith('shop'):
            for line in open(os.path.join(logdir, f)):
                d = json.loads(line)
                if d['level'] >= 400:
                    bad.append(d['message'][:300])
    check(not bad, 'error log entries:\n' + '\n'.join(bad[:10]))
    s, body, h = Client('ops').go('GET', '/metrics', headers={'Authorization': 'Bearer live-metrics-token'}, expect=200)
    check(re.search(r'^shop_exceptions_total 0$', body, re.M), 'exceptions counted:\n' + '\n'.join(l for l in body.splitlines() if 'exceptions' in l))
    five = [r for r in C.REQUEST_LOG if r[3] >= 500]
    check(not five, f'5xx responses: {five}')


PHASES = [infra, admin_onboarding, admin_setup, buyer_register, seller_onboarding, seller_products, crypto_purchase,
          partial_and_overpay, giftcard_coupon_manual, reviews_wishlist, tickets, refunds, payouts, account_security, admin_tour,
          scheduler_jobs, commission_and_gateway, dispute_flow, email_template, bulk_dashboard_health, backup_restore, final_health]

if __name__ == '__main__':
    try:
        for ph in PHASES:
            ph()
    except Exception:
        pass
    statuses = {}
    for who, m, p, st, rid in C.REQUEST_LOG:
        statuses[st] = statuses.get(st, 0) + 1
    passed = sum(1 for r in RESULTS if r[1] == 'PASS')
    print('requests by status:', dict(sorted(statuses.items())))
    print('passed', passed, 'of', len(PHASES))
    with open(os.path.join(L, 'logs', 'live_results.json'), 'w') as f:
        json.dump({'results': RESULTS, 'requests': C.REQUEST_LOG}, f, indent=1)
    sys.exit(0 if passed == len(PHASES) else 1)
