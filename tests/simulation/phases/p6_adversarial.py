"""Phase 6: hostile input from buyers, sellers and strangers."""
import re
import time
import urllib.parse

from simlib import env, shop
from simlib.http import Client
from simlib.record import scenario

from .common import admin, buyer, flashes, instant_product, parallel, staff_form, wait_status
from .p2_payments import post_webhook
from .p4_seller import create_product

XSS = [
    '<script>alert(1)</script>',
    '"><img src=x onerror=alert(2)>',
    "javascript:alert(3)",
    '<svg/onload=alert(4)>',
    "'-alert(5)-'",
    '{{ 7*7 }}{!! "x" !!}@php echo 1; @endphp',
]
SQLI = ["' OR '1'='1", "1; DROP TABLE users;--", "' UNION SELECT password_hash FROM users--", "1' AND pg_sleep(5)--", '\\', "%' OR 1=1 --"]
UNICODE = 'Prüfung مرحبا 中文 \U0001F680\U0001F525 é ​‍Z̵̡a̶lgo \U0001F468‍\U0001F469‍\U0001F467'


DANGEROUS = ['<script>alert(1)</script>', '<img src=x onerror=alert(2)>', '<svg/onload=alert(4)>', 'href="javascript:', "src=\"javascript:"]


def raw_payload_in(html_text, payload):
    """True when a payload shows up as live markup instead of escaped text."""
    return any(d in html_text for d in DANGEROUS if d.split('=')[0].strip('"<') in payload or d in payload)


@scenario(6, 'XSS in every field people can write', 'buyer and seller accounts; staff viewing the data',
          'Every payload is shown as text (escaped) on buyer, seller and admin pages and in the search box; a strict CSP is sent; '
          'Blade syntax in input is not evaluated', 'blocker')
def xss(rec, ctx):
    email, b = buyer(50)
    s = shop.login('seller02@sim.test')
    sa = admin()
    hits = []
    for i, payload in enumerate(XSS):
        b.form('/account', '/account/profile', {'name': f'X{i} {payload}'[:80]})
        pid = create_product(s, f'XSS {i} {payload}'[:160], extra={'description': f'Desc {payload} ' * 3})
        s.form(f'/seller/products/{pid}/edit', f'/seller/products/{pid}/keys', {'keys': f'XSS-KEY-{i}-{int(time.time())}'})
        s.form(f'/seller/products/{pid}/edit', f'/seller/products/{pid}/submit')
        staff_form(sa, f'/admin/products/{pid}', f'/admin/products/{pid}/status', {'status': 'active'})
        slug = env.scalar(f'select slug from products where id={pid}')
        page = b.get(f'/products/{slug}')
        if f'/products/{slug}/reviews' in page.text:
            b.submit(b.find_form(page, f'/products/{slug}/reviews'), {'rating': '1', 'title': f'R {payload}'[:120], 'body': f'Body {payload}'})
        b.form('/tickets/new', '/tickets', {'subject': f'T {payload}'[:160], 'category': 'support', 'body': f'Help {payload}'})
        pages = [(b, f'/products/{slug}'), (b, '/products?q=' + urllib.parse.quote(payload)), (b, '/account'), (b, '/tickets'),
                 (s, f'/seller/products/{pid}/edit'), (s, '/seller/products'), (sa, f'/admin/products/{pid}'), (sa, '/admin/products'),
                 (sa, '/admin/users?q=buyer50'), (sa, '/admin/tickets'), (sa, '/admin/audit')]
        for c, path in pages:
            r = c.get(path, expect=None)
            if r.status >= 500:
                hits.append(f'{path}: HTTP {r.status}')
            if raw_payload_in(r.text, payload):
                hits.append(f'{path}: unescaped {payload!r}')
            if '{{ 7*7 }}' in payload and '>49<' in r.text:
                hits.append(f'{path}: template evaluated')
    rec.check(not hits, 'XSS findings: ' + '; '.join(hits[:10]))
    csp = b.get('/').headers.get('Content-Security-Policy') or ''
    rec.check("script-src 'self'" in csp or "default-src 'self'" in csp, f'CSP: {csp}')
    rec.check("'unsafe-inline'" not in csp.split('script-src')[-1].split(';')[0], f'CSP allows inline scripts: {csp}')


@scenario(6, 'SQL injection in search, filters, paths and login', 'Guests, a buyer and an admin',
          'Every payload is treated as data: no 500, no extra rows, no delay, tables intact', 'blocker')
def sqli(rec, ctx):
    g = Client('sqli-guest')
    a = admin('finance@sim.test')
    users_before = env.scalar('select count(*) from users')
    problems = []
    for payload in SQLI:
        q = urllib.parse.quote(payload)
        for c, path in [(g, f'/products?q={q}'), (g, f'/products?category={q}'), (g, f'/products?sort={q}'), (g, f'/products?seller={q}'),
                        (g, f'/products?price_min={q}'), (g, f'/search/suggest?q={q}'), (g, f'/products/{q}'),
                        (a, f'/admin/orders?q={q}'), (a, f'/admin/orders?buyer={q}'), (a, f'/admin/users?q={q}'), (a, f'/admin/payments?q={q}'),
                        (a, f'/admin/orders/{q}'), (a, f'/tickets/new?order={q}'), (a, f'/orders/{q}'), (a, f'/orders/{q}/invoice')]:
            started = time.time()
            r = c.get(path, expect=None)
            took = time.time() - started
            if r.status >= 500:
                problems.append(f'{path}: {r.status}')
            if took > 4:
                problems.append(f'{path}: took {took:.1f}s')
            if c is g and '/products?q=' in path and r.status == 200 and len(re.findall(r'class="product-card', r.text)) > 0 and "'" in payload:
                problems.append(f'{path}: returned products for an injection string')
        r = Client('sqli-login').form('/login', '/login', {'email': f'buyer01@sim.test{payload}', 'password': payload})
        if '/login' not in r.path and r.status != 429:
            problems.append(f'login with {payload!r} went to {r.path}')
    rec.check(env.scalar('select count(*) from users') == users_before, 'users table changed')
    rec.check(not problems, '; '.join(problems[:10]))
    ctx.allow_log(r'Failed login')


@scenario(6, 'Path traversal', 'A buyer with a delivered order; a seller uploading files',
          'Traversal in download, image, export and static paths is refused (403/404); an upload named ../../x.php is stored under a safe name '
          'and never executed', 'blocker')
def traversal(rec, ctx):
    g = Client('traversal')
    for path in ['/storage/../.env', '/../.env', '/.env', '/%2e%2e/%2e%2e/etc/passwd', '/product-images/..%2f..%2f.env/thumb',
                 '/product-images/1/..%2f..%2fetc%2fpasswd', '/css/../../.env', '/index.php/../.env', '/composer.json', '/artisan',
                 '/storage/logs/laravel.log', '/vendor/autoload.php']:
        r = g.get(path, expect=None, follow=False)
        rec.ev(f'{path}: {r.status}')
        rec.check(r.status in (400, 403, 404), f'{path} answered {r.status}')
        rec.check('APP_KEY' not in r.text and 'root:x:0' not in r.text, f'{path} leaked file content')
    s = shop.login('seller02@sim.test')
    pid = create_product(s, 'Traversal Upload Test')
    r = s.form(f'/seller/products/{pid}/edit', f'/seller/products/{pid}/files',
               files={'file': ('../../../public/evil.php', b'<?php echo "pwned"; ?>', 'application/x-php')})
    stored = env.sql(f'select storage_path, original_name from product_files where product_id={pid}')
    rec.ev(f'upload result: {flashes(r)}; stored {stored}')
    rec.check(not stored or ('..' not in stored[0][0] and not stored[0][0].endswith('.php')), f'unsafe storage path {stored}')
    rec.check(g.get('/evil.php', expect=None).status == 404, 'uploaded php reachable')
    r = s.form(f'/seller/products/{pid}/edit', f'/seller/products/{pid}/files', files={'file': ('tool.zip', b'PK\x03\x04 ok' * 20, 'application/zip')})
    fid = env.scalar(f'select id from product_files where product_id={pid} order by id desc limit 1')
    a = admin('moderator@sim.test')
    r = a.get(f'/admin/products/{pid}/files/{fid}%2f..%2f..%2f.env', expect=None)
    rec.check(r.status in (403, 404), f'admin file download traversal: {r.status}')


@scenario(6, 'Oversized uploads and request bodies', 'A seller; a guest',
          'A 60 MB upload is refused (413) without a 500; a 6 MB image is refused with a field error; '
          'a 20 MB form post is refused; nothing half-stored', 'major')
def oversized(rec, ctx):
    s = shop.login('seller02@sim.test')
    pid = create_product(s, 'Oversize Upload Test')
    files_before = env.scalar(f'select count(*) from product_files where product_id={pid}')
    r = s.form(f'/seller/products/{pid}/edit', f'/seller/products/{pid}/files', files={'file': ('huge.zip', b'\0' * (60 * 1024 * 1024), 'application/zip')})
    rec.ev(f'60 MB file: HTTP {r.status} {flashes(r)[:120]}')
    rec.check(r.status in (200, 413) and r.status < 500, f'60 MB upload: {r.status}')
    r = s.form(f'/seller/products/{pid}/edit', f'/seller/products/{pid}/images', {'alt_text': 'big'},
               files={'image': ('big.png', b'\x89PNG\r\n\x1a\n' + b'\0' * (6 * 1024 * 1024), 'image/png')})
    rec.check(r.status < 500 and 'larger than 5 MB' in flashes(r),
              f'6 MB image: {r.status} {flashes(r)[:200]}')
    rec.check(env.scalar(f'select count(*) from product_files where product_id={pid}') == files_before, 'oversized file stored')
    rec.check(env.scalar(f'select count(*) from product_images where product_id={pid}') == '0', 'oversized image stored')
    g = Client('big-body')
    tok = g.csrf('/login')
    r = g.go('POST', '/login', ('_token=' + tok + '&email=a%40b.c&password=' + 'A' * (20 * 1024 * 1024)).encode(),
             {'Content-Type': 'application/x-www-form-urlencoded'})
    rec.check(r.status in (200, 302, 413, 422) and r.status < 500, f'20 MB form: {r.status}')


@scenario(6, 'Unicode and emoji input', 'buyer09; seller02',
          'Names, product titles, reviews and searches with emoji, RTL, combining and zero-width characters are stored and shown intact; '
          'checkout, invoice PDF, emails and CSV export work', 'major')
def unicode(rec, ctx):
    s = shop.login('seller02@sim.test')
    title = ('Emoji ' + UNICODE)[:150]
    pid = create_product(s, title, extra={'description': UNICODE * 3})
    s.form(f'/seller/products/{pid}/edit', f'/seller/products/{pid}/keys', {'keys': f'UNI-{int(time.time())}-1\nUNI-{int(time.time())}-2'})
    s.form(f'/seller/products/{pid}/edit', f'/seller/products/{pid}/submit')
    staff_form(admin(), f'/admin/products/{pid}', f'/admin/products/{pid}/status', {'status': 'active'})
    rec.check(env.scalar(f'select title from products where id={pid}') == title, 'title changed in storage')
    email, b = buyer(9)
    b.form('/account', '/account/profile', {'name': 'Zoë \U0001F600 مريم'})
    rec.check(env.scalar(f"select name from users where email='{email}'") == 'Zoë \U0001F600 مريم', 'name mangled')
    slug = env.scalar(f'select slug from products where id={pid}')
    page = b.get(f'/products/{slug}')
    rec.check('\U0001F680' in page.text, 'emoji not shown on the product page')
    r = b.get('/products?q=' + urllib.parse.quote('\U0001F680'))
    rec.check(r.status == 200, f'emoji search: {r.status}')
    shop.add_to_cart(b, {'slug': slug})
    r, oid = shop.checkout(b, 'crypto', 'BTC')
    rec.check(oid, f'checkout: {flashes(r)}')
    shop.pay(oid)
    wait_status(oid, 'delivered')
    shop.wait_for(lambda: env.scalar(f"select count(*) from invoices i join orders o on o.id=i.order_id where o.public_id='{oid}'") == '1', 'invoice', 60)
    pdf = b.get(f'/orders/{oid}/invoice')
    rec.check(pdf.raw[:4] == b'%PDF', 'invoice is not a PDF')
    env.wait_mail(email, 'paid')


@scenario(6, 'Extremely long inputs', 'Guests and a buyer',
          'Over-long search, email, name, coupon, review and ticket inputs get field errors or are cut safely; never a 500', 'major')
def long_inputs(rec, ctx):
    g = Client('long')
    big = 'A' * 200_000
    for path in ['/products?q=' + big[:8000], '/search/suggest?q=' + big[:8000], '/products?category=' + big[:5000]]:
        r = g.get(path, expect=None)
        rec.check(r.status in (200, 302, 414, 422) and r.status < 500, f'{path[:40]}...: {r.status}')
    r = g.form('/login', '/login', {'email': big[:5000] + '@sim.test', 'password': big[:5000]})
    rec.check(r.status < 500, f'long login: {r.status}')
    _, b = buyer(8)
    for page, action, fields in [('/account', '/account/profile', {'name': big}), ('/tickets/new', '/tickets', {'subject': big, 'category': 'support', 'body': big}),
                                 ('/wallet', '/wallet/redeem', {'code': big[:10000]})]:
        r = b.form(page, action, fields)
        rec.check(r.status < 500, f'{action}: {r.status}')
    shop.add_to_cart(b, instant_product())
    r = b.form('/checkout', '/checkout/coupon', {'code': big[:10000]})
    rec.check(r.status < 500, f'coupon: {r.status}')
    rec.check(len(env.scalar("select name from users where email='buyer08@sim.test'")) <= 80, 'over-long name stored')
    shop.empty_cart(b)


@scenario(6, 'Malformed webhook JSON returns 400, never 500', 'none',
          'Invalid UTF-8, wrong content type, deeply nested and duplicate-key JSON: 400 (or 202 when it is a valid object), no 500', 'major')
def webhook_junk(rec, ctx):
    for name, body in [('invalid utf-8', b'{"external_id": "\xff\xfe"}'), ('nested', ('[' * 5000 + ']' * 5000).encode()),
                       ('nul bytes', b'{"a":"\x00"}'), ('bom', b'\xef\xbb\xbf{"external_id": "x"}')]:
        r = post_webhook(body.decode('latin-1'))
        rec.ev(f'{name}: {r.status}')
        rec.check(r.status in (400, 202) and r.status < 500, f'{name}: {r.status}')
    ctx.allow_log(r'unparseable|not a JSON')


@scenario(6, 'Double-click on checkout and pay', 'buyer01 with a balance submits the checkout form twice at the same moment',
          'Exactly one order and one balance debit; the second click lands on the same order', 'blocker')
def double_click(rec, ctx):
    email, b = buyer(where="balance_minor >= 20000 and currency='USD' and email not in ('buyer01@sim.test')")
    bal_before = int(env.scalar(f"select balance_minor from users where email='{email}'"))
    p = instant_product('price_minor < 5000')
    shop.add_to_cart(b, p)
    page = b.get('/checkout')
    form = b.find_form(page, '/checkout')
    before = int(env.scalar(f"select count(*) from orders o join users u on u.id=o.buyer_id where u.email='{email}'"))
    results = parallel([lambda: b.submit(form, {'payment_method': 'balance', 'accept_terms': '1'}) for _ in range(3)])
    rec.ev(f'responses: {[getattr(r, "path", repr(r)) for r in results]}')
    after = int(env.scalar(f"select count(*) from orders o join users u on u.id=o.buyer_id where u.email='{email}'"))
    rec.check(after == before + 1, f'{after - before} orders from one double-click')
    debits = env.scalar(f"select count(*) from balance_transactions b join users u on u.id=b.user_id where u.email='{email}' and b.type='order_payment'")
    rec.check(debits == '1', f'{debits} balance debits')
    rec.check(int(env.scalar(f"select balance_minor from users where email='{email}'")) == bal_before - p['price'], 'balance debited wrong amount')
    rec.check(all(not isinstance(r, Exception) and r.status < 500 for r in results), f'errors: {results}')
