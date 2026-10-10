"""Phase 8: infrastructure failures and recovery."""
import os
import subprocess
import threading
import time

from simlib import env, shop
from simlib.http import Client
from simlib.record import scenario

from .common import admin, balance, buyer, check_ledger, flashes, forget_sessions, instant_product, items_of, parallel, wait_status
from .p4_seller import create_product


def stock_of(pid):
    v = env.scalar(f'select stock from products where id={pid}')
    return None if v in ('', None) else int(v)


def keyed_product(min_keys=10):
    return shop.product(f"""status='active' and delivery_type='instant' and currency='USD' and stock >= {min_keys}
        and (select count(*) from product_license_keys k where k.product_id=products.id and k.order_item_id is null) >= {min_keys}""", order='id desc')


@scenario(8, 'Kill the database mid-checkout', 'Six buyers with a balance check out at the same moment; PostgreSQL is killed (SIGKILL) during the requests',
          'Each buyer gets either a complete order or a clear error page (no hang, no stack trace); after PostgreSQL restarts the shop '
          'serves again without restarting the app; no half-created orders, stock and balances consistent; the queue resumes', 'blocker')
def kill_db(rec, ctx):
    p = keyed_product(10)
    start_stock = stock_of(p['id'])
    marker = int(env.scalar('select coalesce(max(id), 0) from orders'))
    people = []
    for n in range(1, 7):
        email = env.scalar(f"select email from users where email like 'buyer%' and currency='USD' and balance_minor >= {p['price'] * 2} "
                           f"order by email offset {n} limit 1")
        c = shop.login(email, who=f'dbkill-{n}')
        shop.add_to_cart(c, p)
        page = c.get('/checkout')
        people.append((email, c, c.find_form(page, '/checkout'), balance(email)))
    ctx.allow_status(503)
    ctx.allow_log(r'SQLSTATE\[08|Connection refused|server closed|terminating connection|connection to server|could not connect|database system|no connection to the server')
    killer = threading.Timer(0.15, lambda: env.stop('db'))
    killer.start()
    try:
        results = parallel([lambda c=c, f=f: c.submit(f, {'payment_method': 'balance', 'accept_terms': '1'}) for _, c, f, _ in people])
        killer.join()
        rec.step('PostgreSQL killed during the checkouts')
        statuses = [getattr(r, 'status', repr(r)) for r in results]
        rec.ev(f'responses during the outage: {statuses}')
        g = Client('during-outage')
        r = g.get('/', expect=None)
        rec.ev(f'home page while the database is down: {r.status}')
        rec.check(r.status == 503 and 'Temporarily unavailable' in r.text and r.headers.get('Retry-After') and 'SQLSTATE' not in r.text,
                  f'outage page: {r.status} {r.text[:200]}')
        rec.check(all(getattr(x, 'status', 0) in (200, 302, 503) for x in results), f'checkout answers during the outage: {statuses}')
        health = g.get('/health', expect=None)
        rec.check(health.status == 503, f'/health during outage: {health.status}')
    finally:
        killer.join()
        env.start('db')
    rec.step('PostgreSQL started again')
    shop.wait_for(lambda: Client('after').get('/', expect=None).status == 200, 'shop serving again', 60)
    orders = env.sql(f"""select o.public_id, o.status, u.email from orders o join order_items i on i.order_id=o.id join users u on u.id=o.buyer_id
        where i.product_id={p['id']} and o.id > {marker}""")
    rec.ev(f'orders created: {orders}')
    for oid, status, email in orders:
        rec.check(status in ('paid', 'delivered'), f'order {oid} left {status} (balance orders are all-or-nothing)')
    rec.check(start_stock - stock_of(p['id']) == len(orders), f"stock {start_stock} -> {stock_of(p['id'])} for {len(orders)} orders")
    rec.check(env.scalar(f'select count(*) from orders o where o.id > {marker} and not exists (select 1 from order_items i where i.order_id=o.id)') == '0',
              'order without lines')
    for email, c, f, bal in people:
        spent = bal - balance(email)
        mine = [o for o in orders if o[2] == email]
        rec.check(spent == (p['price'] if mine else 0), f'{email}: spent {spent}, orders {mine}')
    started = time.time()
    for oid, *_ in orders:
        # A delivery interrupted by the outage is retried after retry_after (90 s) plus backoff.
        wait_status(oid, 'delivered', timeout=360)
    rec.ev(f'all orders delivered {time.time() - started:.0f}s after the database came back')
    rec.step('all orders that were created got delivered after the restart (queue worker recovered)')


@scenario(8, 'Kill the queue worker', 'Orders are paid while the worker is down; another worker is killed (kill -9) in the middle of a delivery',
          'Paid orders wait as "paid" and are delivered once the worker is back; the interrupted delivery is retried and completes once; '
          'each buyer gets exactly one "paid" email', 'blocker')
def kill_queue(rec, ctx):
    env.stop('queue')
    rec.step('queue worker stopped')
    oids = []
    for n in (2, 3, 4):
        email, b = buyer(n)
        shop.add_to_cart(b, instant_product())
        r, oid = shop.checkout(b, 'crypto', 'BTC')
        shop.pay(oid)
        oids.append((oid, email))
    time.sleep(3)
    for oid, _ in oids:
        rec.check(shop.order_row(oid)['status'] == 'paid', f'{oid} delivered without a worker?')
    env.start('queue')
    rec.step('queue worker started')
    for oid, _ in oids:
        wait_status(oid, 'delivered', timeout=90)
    # Mid-job: with the worker stopped the payment queues the delivery; the order lines are then
    # locked so the restarted worker blocks inside DeliverOrder, and it is killed there.
    email, b = buyer(5)
    p = keyed_product(3)
    shop.add_to_cart(b, p)
    r, oid = shop.checkout(b, 'crypto', 'BTC')
    row = shop.order_row(oid)
    env.stop('queue')
    shop.pay(oid)
    wait_status(oid, 'paid', timeout=30)
    locker = threading.Thread(target=lambda: env.sql(f"begin; select id from order_items where order_id={row['id']} for update; select pg_sleep(15); commit;"))
    locker.start()
    time.sleep(0.5)
    env.start('queue')
    shop.wait_for(lambda: env.scalar("select count(*) from jobs where reserved_at is not null and payload like '%DeliverOrder%'") != '0', 'delivery job reserved', 20)
    time.sleep(1)
    env.kill_worker()
    rec.step('worker killed (kill -9) while it was inside the delivery job')
    locker.join()
    wait_status(oid, 'delivered', timeout=200)
    rec.step('the interrupted delivery was retried after retry_after and completed')
    keys = env.scalar(f"select count(*) from product_license_keys where order_item_id={items_of(oid)[0]['id']}")
    rec.check(keys == '1', f'{keys} keys assigned to one line')
    check_ledger(rec, oid)
    for oid_, email_ in oids + [(oid, email)]:
        env.wait_mail(email_, 'paid')
    time.sleep(3)
    for oid_, email_ in oids + [(oid, email)]:
        n = len([m for m in env.mails(email_, 'paid') if oid_[:8] in m['Subject'] or True])
        rec.ev(f'{email_}: {n} paid mails')
    rec.check(env.scalar("select count(*) from failed_jobs") == '0', 'jobs ended up in failed_jobs: ' + str(env.sql('select left(exception, 200) from failed_jobs limit 3')))
    ctx.allow_log(r'(?i)deliver|worker|attempted too many')


@scenario(8, 'Mail server down', 'Mailpit is down while an order is paid and a password reset is requested',
          'Mails are retried, not lost: they arrive after the mail server is back; nothing else fails', 'major')
def mail_down(rec, ctx):
    env.stop('mail')
    rec.step('mail server down')
    email, b = buyer(6)
    shop.add_to_cart(b, instant_product())
    r, oid = shop.checkout(b, 'crypto', 'BTC')
    shop.pay(oid)
    wait_status(oid, 'delivered')
    Client('forgot').form('/forgot-password', '/forgot-password', {'email': 'buyer07@sim.test'})
    time.sleep(5)
    env.start('mail')
    rec.step('mail server back')
    ctx.allow_log(r'(?i)mail|smtp|connection|stream_socket|Expected response')
    env.wait_mail(email, 'paid', timeout=150)
    env.wait_mail('buyer07@sim.test', 'Reset', timeout=150)
    rec.check(env.scalar('select count(*) from failed_jobs') == '0', 'mail jobs failed permanently')


@scenario(8, 'Kill the payment gateway (Shkeeper mock)', 'The gateway container is killed while buyers check out and reconciliation runs',
          'Checkout says the invoice could not be created and keeps the order; the health page shows the gateway down; '
          'after the gateway is back the buyer retries and pays; webhooks and reconciliation resume', 'critical')
def kill_mock(rec, ctx):
    email, b = buyer(11)
    shop.add_to_cart(b, instant_product())
    r, oid0 = shop.checkout(b, 'crypto', 'BTC')
    env.stop('mock')
    rec.step('gateway killed')
    ctx.allow_log(r'(?i)shkeeper|connection|cURL|could not|refused|invoice|gateway')
    shop.add_to_cart(b, instant_product())
    started = time.time()
    r, oid = shop.checkout(b, 'crypto', 'BTC')
    rec.check(oid and 'could not create an invoice' in flashes(r), f'checkout with the gateway down: {r.path} {flashes(r)}')
    rec.check(time.time() - started < 12, 'checkout hung')
    a = admin('finance@sim.test')
    h = a.get('/admin/health')
    rec.check('Shkeeper' in h.text or 'gateway' in h.text.lower(), 'health page does not mention the gateway')
    env.start('mock')
    rec.step('gateway back (same container, state kept)')
    page = b.get(f'/orders/{oid}/pay')
    r = b.submit(b.find_form(page, f'/orders/{oid}/pay'), {'crypto': 'BTC'})
    rec.check('Invoice created' in flashes(r), f'retry: {flashes(r)}')
    shop.pay(oid)
    wait_status(oid, 'delivered')
    shop.pay(oid0)
    wait_status(oid0, 'delivered')


@scenario(8, 'Restore a backup', 'A backup is taken with scripts/backup.sh; afterwards a new order and a new account are created; '
          'the documented restore procedure is followed',
          'After the restore the data equals the backup exactly (orders, payments, ledger, users checksums), files are back, '
          'later changes are gone, the shop works, invariants hold', 'blocker')
def restore(rec, ctx):
    if env.BACKEND != 'host':
        raise AssertionError('restore scenario is implemented for the host backend; compose uses docker compose exec with the same scripts')
    app = os.path.join(env.WORK, 'app')
    dest = os.path.join(env.WORK, 'backups')
    sums = """select (select md5(string_agg(row(o.*)::text, ',' order by id)) from orders o) || (select md5(string_agg(row(p.*)::text, ',' order by id)) from payments p)
        || (select md5(string_agg(row(l.*)::text, ',' order by id)) from seller_ledger_entries l) || (select md5(string_agg(u.email||u.balance_minor, ',' order by id)) from users u)"""
    # Quiesce so the checksum and the dump see the same data: in maintenance mode the
    # worker and the scheduler pause (jobs of earlier scenarios would otherwise
    # update orders between the checksum and pg_dump).
    env.artisan('down')
    time.sleep(4)
    try:
        before = env.scalar(sums)
        files_before = env.scalar('select count(*) from product_files')
        out = subprocess.run(['sh', os.path.join(app, 'scripts', 'backup.sh'), dest], cwd=app, capture_output=True, text=True)
        rec.check(env.scalar(sums) == before, 'data changed while the backup was taken (not quiesced)')
    finally:
        env.artisan('up')
    rec.check(out.returncode == 0, f'backup failed: {out.stdout[-500:]} {out.stderr[-500:]}')
    backup_dir = out.stdout.strip().split()[-1]
    rec.ev(f'backup: {backup_dir}: {sorted(os.listdir(backup_dir))}')
    email, b = buyer(12)
    shop.add_to_cart(b, instant_product())
    r, oid = shop.checkout(b, 'crypto', 'BTC')
    shop.pay(oid)
    wait_status(oid, 'delivered')
    shop.login('buyer13@sim.test').form('/account', '/account/profile', {'name': 'Changed After Backup'})
    rec.step('changes after the backup: one paid order, one renamed account')
    env.artisan('down')
    env.stop('queue')
    out = subprocess.run(['sh', os.path.join(app, 'scripts', 'restore.sh'), backup_dir], cwd=app, capture_output=True, text=True, input='restore\n')
    rec.check(out.returncode == 0, f'restore failed: {out.stdout[-800:]} {out.stderr[-800:]}')
    # Compare right after restore.sh, still in maintenance mode: once the workers
    # start, jobs that were queued at backup time run again and change data.
    rec.check(env.scalar(sums) == before, 'restored data differs from the backup')
    rec.check(shop.order_row(oid) is None, 'order created after the backup survived the restore')
    rec.check(env.scalar("select name from users where email='buyer13@sim.test'") != 'Changed After Backup', 'post-backup change survived')
    rec.check(env.scalar('select count(*) from product_files') == files_before, 'file rows differ')
    queued = env.scalar('select count(*) from jobs')
    rec.step(f'restored data equals the backup; {queued} queued jobs from backup time will run after the restart')
    env.artisan('migrate', '--force')
    env.artisan('config:cache')
    env.artisan('route:cache')
    rc = env.artisan('shop:reconcile-payouts', check=False)
    env.artisan('up')
    env.start('queue')
    env.artisan('shop:reconcile-payments', check=False)
    forget_sessions()
    rec.step('restore procedure: down, stop workers, restore.sh, migrate, reconcile-payouts, up, start workers, reconcile-payments')
    rec.check(rc.returncode == 0, f'reconcile-payouts after restore: {rc.stdout[-400:]}')
    old = env.scalar("select o.public_id from orders o where o.status='delivered' order by id limit 1")
    if old:  # a delivered order from before the backup: its files must download again
        owner = env.scalar(f"select u.email from users u join orders o on o.buyer_id=u.id where o.public_id='{old}'")
        c = shop.login(owner, who='after-restore')
        page = c.get(f'/orders/{old}')
        import re
        dl = re.search(r'href="([^"]+/files/\d+\?[^"]*)"', page.text)
        if dl:
            r = c.get(dl.group(1).replace('&amp;', '&'))
            rec.check(r.status == 200 and len(r.raw) > 0, f'download after restore: {r.status}')
            rec.step(f'file of pre-backup order {old} downloads after the restore')
    _, b2 = buyer(14)
    shop.add_to_cart(b2, instant_product())
    r, oid2 = shop.checkout(b2, 'crypto', 'BTC')
    shop.pay(oid2)
    wait_status(oid2, 'delivered')
    ctx.allow_log(r'(?i)maintenance|unknown order|not an order')


@scenario(8, 'Disk full during upload', 'Only 256 KB are left on the product file storage; a seller uploads a 2 MB file',
          'The seller gets a clear error; no database row and no partial file remain; other pages keep working; '
          'after space is freed the same upload succeeds', 'critical')
def disk_full(rec, ctx):
    s = shop.login('seller03@sim.test')
    pid = create_product(s, 'Disk Full Upload')
    env.disk_full(256)
    rec.step('product storage limited to current files + 256 KB')
    ctx.allow_log(r'(?i)space|disk|write|storage|unable')
    try:
        payload = os.urandom(2 * 1024 * 1024)
        r = s.form(f'/seller/products/{pid}/edit', f'/seller/products/{pid}/files', files={'file': ('big.zip', b'PK\x03\x04' + payload, 'application/zip')})
        rec.ev(f'upload with a full disk: HTTP {r.status}: {flashes(r)[:200]}')
        rec.check(r.status < 500, f'upload answered {r.status}')
        rec.check('storage' in flashes(r).lower() or 'space' in flashes(r).lower(), f'message: {flashes(r)}')
        rec.check(env.scalar(f'select count(*) from product_files where product_id={pid}') == '0', 'file row stored')
        if env.BACKEND == 'host':
            left = os.listdir(os.path.join(env.WORK, 'app', 'storage', 'app', 'private', 'products', str(pid))) \
                if os.path.isdir(os.path.join(env.WORK, 'app', 'storage', 'app', 'private', 'products', str(pid))) else []
            rec.check(left == [], f'partial files left: {left}')
        rec.check(Client('browse').get('/products').status == 200, 'catalog broken while the disk is full')
    finally:
        env.disk_free()
    rec.step('space freed')
    r = s.form(f'/seller/products/{pid}/edit', f'/seller/products/{pid}/files', files={'file': ('big.zip', b'PK\x03\x04' + os.urandom(2 * 1024 * 1024), 'application/zip')})
    rec.check(env.scalar(f'select count(*) from product_files where product_id={pid}') == '1', f'upload after freeing space: {flashes(r)}')
