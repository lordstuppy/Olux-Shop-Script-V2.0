"""Phase 10: third round - the features added for go-live, end to end on the
production stack. Staff alerts go through the real queue, scheduler commands
and mail server; a failed email goes from outage to retry on the health page;
seller email preferences, the dispute confirmation, the colour scheme and
security.txt are exercised through nginx and TLS; the new migrations run on a
database populated by all earlier scenarios.

Queue backoffs (10 s up to 15 min) are skipped by moving the stored
available_at of the waiting job to now, which is what waiting would do."""
import time

from simlib import env, shop
from simlib.http import Client
from simlib.record import scenario

from .common import admin, buyer, flashes, instant_product, items_of, wait_status
from .p2_payments import post_webhook

# SHOP_STAFF_ALERT_EMAIL is not set in the test stack, so alerts go to SHOP_SUPPORT_EMAIL.
STAFF = 'support@sim.test'


def forget_throttle(key):
    """Alerts of one kind are throttled; an earlier scenario may have used the period up."""
    env.artisan('cache:forget', 'staff-alert:' + key)


def run_queued_now(pattern):
    env.sql(f"update jobs set available_at = extract(epoch from now())::int where reserved_at is null and payload like '%{pattern}%'")


def settle_alerts():
    """Waits until queued staff alerts have been delivered, so mailbox counts are final."""
    shop.wait_for(lambda: env.scalar("select count(*) from jobs where payload like '%StaffAlertMail%'") == '0', 'queued alerts delivered', 60, 1)
    time.sleep(2)


def failed_jobs():
    return int(env.scalar('select count(*) from failed_jobs'))


def buy(n, product, rec):
    email, b = buyer(n)
    shop.add_to_cart(b, product)
    r, oid = shop.checkout(b, 'crypto', 'BTC')
    rec.check(oid, f'no order: {flashes(r)}')
    shop.pay(oid)
    wait_status(oid, 'delivered')
    env.wait_mail(email, f'Order {oid[:8]} paid', timeout=90)
    return email, b, oid


@scenario(10, 'Staff alert for rejected payment callbacks', 'Forged Shkeeper callbacks arrive from four addresses with a wrong key',
          'All are refused with 401; the staff mailbox gets exactly one alert that names the reason and links the gateway page', 'major')
def alert_forged(rec, ctx):
    ctx.allow_log(r'(?i)signature|rejected|webhook|callback')
    forget_throttle('webhook-rejected')
    settle_alerts()
    before = len(env.mails(STAFF, 'callbacks are being rejected'))
    body = '{"external_id": "00000000-0000-4000-8000-000000000000", "paid": true, "status": "PAID", "balance_fiat": "1.00", "fiat": "USD"}'
    codes = [post_webhook(body, key=f'attacker-key-{i:04d}', who=f'forger-{i}').status for i in range(4)]
    rec.check(codes == [401] * 4, f'statuses {codes}')
    found = env.wait_mail(STAFF, 'callbacks are being rejected', timeout=60, count=before + 1)
    text = env.mail_text(found[0]['ID'])
    rec.ev(text[:400])
    rec.check('wrong signature' in text and '/admin/gateway' in text, 'alert does not name the reason or link the gateway page')
    settle_alerts()
    rec.check(len(env.mails(STAFF, 'callbacks are being rejected')) == before + 1, 'more than one alert for one burst')


@scenario(10, 'A failed email: retried, kept, alerted once, sent again from the health page',
          'The mail server is down while a password reset email uses up its 5 tries',
          'The job ends in the failed jobs list and on the dashboard; staff get one alert once mail works again; a manager '
          'retries it on /admin/health and the buyer receives a reset link that works; nothing stays failed', 'critical')
def failed_mail(rec, ctx):
    ctx.allow_log(r'(?i)mail|smtp|connection|stream_socket|Expected response|refused|could not be established|failed')
    forget_throttle('job-failed:App\\Notifications\\ResetPasswordQueued')
    before, alerts_before = failed_jobs(), len(env.mails(STAFF, 'A background job failed: ResetPasswordQueued'))
    env.stop('mail')
    rec.step('mail server down')
    try:
        Client('forgot16').form('/forgot-password', '/forgot-password', {'email': 'buyer16@sim.test'})

        def gave_up():
            run_queued_now('ResetPasswordQueued')
            return failed_jobs() > before
        shop.wait_for(gave_up, 'the reset email in failed_jobs', 240, 2)
        attempts = env.scalar("select payload::json->>'attempts' from failed_jobs where payload like '%ResetPasswordQueued%' order by id desc limit 1")
        rec.ev(f'gave up after the attempts recorded in the payload: {attempts}')
    finally:
        env.start('mail')
    rec.step('mail server back')
    uuid = env.scalar("select uuid from failed_jobs where payload like '%ResetPasswordQueued%' order by id desc limit 1")
    rec.check(uuid, 'no failed job for the reset email')

    def alerted():
        run_queued_now('StaffAlertMail')
        return env.mails(STAFF, 'A background job failed: ResetPasswordQueued')
    shop.wait_for(lambda: len(alerted()) > alerts_before, 'failed-job alert', 180, 2)
    time.sleep(3)
    rec.check(len(alerted()) == alerts_before + 1, 'not exactly one alert')

    m = admin('manager@sim.test')
    rec.check('Failed background jobs' in m.get('/admin').text, 'dashboard does not count failed jobs')
    page = m.get('/admin/health')
    rec.check('ResetPasswordQueued' in page.text and uuid in page.text, 'failed job not listed on the health page')
    r = m.submit(m.find_form(page, f'/admin/health/failed-jobs/{uuid}/retry'))
    rec.check('queued again' in flashes(r), f'retry: {flashes(r)}')
    mail = env.wait_mail('buyer16@sim.test', 'Reset', timeout=90)[0]
    rec.check(failed_jobs() == before, 'failed job still listed after the retry')
    link = next((w for w in env.mail_text(mail['ID']).split() if '/reset-password/' in w), None)
    rec.check(link and Client('reset16').get(link.replace('https://localhost:8443', ''), expect=None).status == 200, f'reset link {link}')


@scenario(10, 'Staff alert when Shkeeper stops answering', 'The gateway is stopped while a buyer tries to pay with crypto three times',
          'Checkout fails cleanly; the health check turns critical; shop:health-alerts mails staff even with the queue worker '
          'stopped (sent directly); a second run within the hour sends nothing', 'critical')
def gateway_alert(rec, ctx):
    ctx.allow_log(r'(?i)shkeeper|connection|cURL|could not|refused|invoice|gateway|timed out|resolve')
    label = 'Shkeeper API errors (last 15 minutes)'
    forget_throttle('health:' + label)
    subject = 'Health problem: ' + label
    before = len(env.mails(STAFF, subject))
    env.stop('mock')
    rec.step('gateway stopped')
    try:
        email, b = buyer(17)
        for _ in range(3):
            shop.add_to_cart(b, instant_product())
            r, oid = shop.checkout(b, 'crypto', 'BTC')
            rec.check(oid and 'could not create an invoice' in flashes(r), f'checkout with the gateway down: {flashes(r)}')
        rec.ev('api calls in the last 15 minutes: ' + str(env.sql("select outcome, count(*) from gateway_logs where channel='api' "
                                                                  "and created_at > now() - interval '15 minutes' group by outcome")))
        page = admin('finance@sim.test').get('/admin/health')
        rec.check('Shkeeper is not answering' in page.text, 'health page does not show the outage')
        env.stop('queue')
        rec.step('queue worker stopped')
        try:
            env.artisan('shop:health-alerts')
            found = env.wait_mail(STAFF, subject, timeout=30, count=before + 1)
        finally:
            env.start('queue')
        rec.check('Crypto checkout, refunds and payouts fail' in env.mail_text(found[0]['ID']), 'alert text')
        env.artisan('shop:health-alerts')
        time.sleep(3)
        rec.check(len(env.mails(STAFF, subject)) == before + 1, 'second alert within the hour')
    finally:
        env.start('mock')
    rec.step('gateway back')


@scenario(10, 'Needs-attention digest for staff', 'A buyer applies to sell and another opens a support ticket',
          'shop:staff-digest mails staff a summary that lists both; a run with nothing new sends nothing', 'major')
def digest(rec, ctx):
    env.artisan('shop:staff-digest')  # starts a fresh window
    settle_alerts()
    before = len(env.mails(STAFF, 'Needs attention since'))
    _, b18 = buyer(18)
    b18.form('/sell', '/sell', {'display_name': 'Sim Digest Goods', 'payout_currency': 'USD', 'payout_crypto': 'BTC',
                                'payout_address': 'bc1qsimdigestgoods00000000000000000000000', 'about': 'Fonts.', 'accept_seller_terms': '1'})
    rec.check(env.scalar("select sp.status from seller_profiles sp join users u on u.id=sp.user_id where u.email='buyer18@sim.test'") == 'pending',
              'application not pending')
    _, b19 = buyer(19)
    b19.form('/tickets/new', '/tickets', {'subject': 'Digest check', 'category': 'support', 'body': 'Where is my licence key?'})
    time.sleep(1)
    env.artisan('shop:staff-digest')
    found = env.wait_mail(STAFF, 'Needs attention since', count=before + 1)
    # The scheduler runs the same command every 15 minutes, so the two items may be split over two mails.
    text = '\n'.join(env.mail_text(m['ID']) for m in found[:len(found) - before])
    rec.ev(text[:600])
    rec.check('seller applications' in text and 'new support tickets' in text, 'digest does not list the application and the ticket')
    settle_alerts()
    count = len(env.mails(STAFF, 'Needs attention since'))
    env.artisan('shop:staff-digest')
    settle_alerts()
    rec.check(len(env.mails(STAFF, 'Needs attention since')) == count, 'digest sent with nothing new')


@scenario(10, 'Seller email preferences and the review email', 'A seller turns off sale and review emails; buyers buy and review',
          'No sale email while off, one again after switching it back on; a review sends the seller an email only while '
          'review emails are on; the buyer is mailed either way', 'major')
def seller_prefs(rec, ctx):
    p = instant_product()
    seller = env.scalar(f"select email from users where id={p['seller_id']}")
    s = shop.login(seller)
    s.form('/account', '/account/notifications', {'notify_sales': [], 'notify_reviews': []})
    rec.check(env.sql(f"select notify_sales, notify_reviews from users where email='{seller}'") == [['f', 'f']], 'preferences not saved')
    try:
        _, b20, oid = buy(20, p, rec)
        time.sleep(3)
        rec.check(not env.mails(seller, f'New sale: order {oid[:8]}'), 'sale email sent although turned off')
        s.form('/account', '/account/notifications', {'notify_sales': '1'})
        _, _, oid2 = buy(20, p, rec)
        env.wait_mail(seller, f'New sale: order {oid2[:8]}', timeout=60)

        reviews = len(env.mails(seller, 'review of'))
        b20.form(f"/products/{p['slug']}", f"/products/{p['slug']}/reviews", {'rating': '2', 'title': 'Too slow', 'body': 'Works, but slowly.'})
        time.sleep(4)
        rec.check(len(env.mails(seller, 'review of')) == reviews, 'review email sent although turned off')
        s.form('/account', '/account/notifications', {'notify_sales': '1', 'notify_reviews': '1'})
        _, b22, _ = buy(22, p, rec)
        b22.form(f"/products/{p['slug']}", f"/products/{p['slug']}/reviews", {'rating': '5', 'title': 'Great', 'body': 'Does what it says.'})
        found = env.wait_mail(seller, 'New 5-star review of', timeout=60)
        rec.check(len(env.mails(seller, 'review of')) == reviews + 1, 'not exactly one review email')
        rec.check('Does what it says.' in env.mail_text(found[0]['ID']), 'review text missing')
    finally:
        env.sql(f"update users set notify_sales=true, notify_reviews=true where email='{seller}'")


@scenario(10, 'Buyer gets a confirmation when opening a dispute', 'A buyer opens a dispute on a delivered item',
          'Buyer, seller and staff each get the dispute email written for them', 'major')
def dispute_confirmation(rec, ctx):
    p = instant_product()
    email, b, oid = buy(16, p, rec)
    item = items_of(oid)[0]['id']
    page = b.get(f'/orders/{oid}/items/{item}/dispute')
    r = b.submit(b.find_form(page, f'/orders/{oid}/items/{item}/dispute'), {'requested_outcome': 'replacement', 'body': 'The key is rejected.'})
    did = env.scalar(f'select id from disputes where order_item_id={item}')
    rec.check(did, f'dispute not opened: {flashes(r)}')
    subject = f'Dispute #{did} opened'
    mine = env.wait_mail(email, subject)[0]
    rec.check('You opened dispute' in env.mail_text(mine['ID']), 'buyer got the text meant for someone else')
    seller = env.scalar(f"select email from users where id={p['seller_id']}")
    rec.check('A buyer opened dispute' in env.mail_text(env.wait_mail(seller, subject)[0]['ID']), 'seller text')
    env.wait_mail(STAFF, subject)


@scenario(10, 'Colour scheme, security.txt and static files through nginx and TLS',
          'A visitor uses the footer colour-scheme form; scanners ask for security.txt and dotfiles; a browser loads the stylesheet',
          'The choice is stored in a Secure, HttpOnly cookie and applies to the next pages, error pages included; security.txt answers with the contact; '
          'other dotfiles stay blocked; CSS is gzipped and cached for a year under its hashed URL; HTML is neither', 'major')
def through_nginx(rec, ctx):
    c = Client('scheme')
    rec.check('data-theme=' not in c.get('/').text, 'scheme set without a choice')
    r = c.form('/', '/theme', {'theme': 'dark'}, follow=False)
    cookies = r.headers.get_all('Set-Cookie') or []
    theme = next((x for x in cookies if x.startswith('theme=')), '')
    rec.ev(theme[:200])
    rec.check(theme and 'secure' in theme.lower() and 'httponly' in theme.lower(), f'theme cookie flags: {theme[:200]}')
    for path in ('/', '/products', '/login'):
        rec.check('data-theme="dark"' in c.get(path).text, f'{path} ignores the choice')
    missing = c.get('/no-such-page', expect=404)
    rec.check('data-theme="dark"' in missing.text, 'the 404 page for an unknown URL ignores the choice')
    c.form('/', '/theme', {'theme': 'system'})
    rec.check('data-theme=' not in c.get('/').text, 'back to the device setting failed')

    sec = c.get('/.well-known/security.txt')
    rec.check(sec.headers.get('Content-Type', '').startswith('text/plain') and 'Contact: mailto:' in sec.text and 'Expires:' in sec.text,
              f'security.txt: {sec.text[:200]}')
    for path in ('/.env', '/.git/config', '/.well-known/../.env'):
        rec.check(c.get(path, expect=None).status in (403, 404), f'{path} is served')

    home = c.get('/')
    css = home.text.split('css/app.css?v=')[1].split('"')[0] if 'css/app.css?v=' in home.text else None
    rec.check(css, 'stylesheet URL has no content hash')
    r = c.raw('GET', f'/css/app.css?v={css}', headers={'Accept-Encoding': 'gzip'})
    rec.check(r.status == 200 and r.headers.get('Content-Encoding') == 'gzip' and 'immutable' in (r.headers.get('Cache-Control') or ''),
              f"css: {r.status} {r.headers.get('Content-Encoding')} {r.headers.get('Cache-Control')}")
    r = c.raw('GET', '/products', headers={'Accept-Encoding': 'gzip'})
    rec.check(not r.headers.get('Content-Encoding') and 'private' in (r.headers.get('Cache-Control') or ''),
              f"html: {r.headers.get('Content-Encoding')} {r.headers.get('Cache-Control')}")


@scenario(10, 'Upgrade: the go-live migrations on a populated database',
          'All earlier scenarios left their data; the three go-live migrations are rolled back and applied again, as an upgrade from the previous release would',
          'Both directions run cleanly; no row is lost; existing users get both email preferences switched on; the new indexes '
          'exist again; the shop keeps serving buyers and staff', 'major')
def upgrade(rec, ctx):
    ctx.allow_log(r'(?i)pending migrations|notify_')
    tables = ('users', 'orders', 'order_items', 'payments', 'seller_ledger_entries', 'tickets', 'disputes', 'product_reviews')

    def counts():
        return {t: env.scalar(f'select count(*) from {t}') for t in tables}

    def has(sql):
        return env.scalar(sql) != '0'
    column = "select count(*) from information_schema.columns where table_name='users' and column_name='notify_sales'"
    index = "select count(*) from pg_indexes where indexname='order_items_order_id_index'"
    centre = "select count(*) from information_schema.tables where table_name='user_notifications'"
    before = counts()
    rec.ev(f'rows before: {before}')
    # The three go-live migrations: foreign-key indexes, email preferences, notification centre.
    env.artisan('migrate:rollback', '--step=3', '--force')
    rec.check(not has(column) and not has(index) and not has(centre), 'rollback left a column, index or table')
    env.artisan('migrate', '--force')
    rec.check(has(column) and has(index) and has(centre), 'migration did not add the column, index or table')
    rec.check(counts() == before, f'rows changed: {before} -> {counts()}')
    rec.check(env.scalar('select count(*) from users where not notify_sales or not notify_reviews or not notification_sound') == '0',
              'existing users not opted in')
    rec.check(env.scalar("select count(*) from migrations where migration like '2026_10_13_%' or migration like '2026_10_14_%'") == '3',
              'migrations table')
    email, b = buyer(16)
    oid = env.scalar(f"select o.public_id from orders o join users u on u.id=o.buyer_id where u.email='{email}' order by o.id desc limit 1")
    rec.check(b.get(f'/orders/{oid}').status == 200 and admin().get(f'/admin/orders/{oid}').status == 200, 'pages after the upgrade')
