"""Page benchmark against the simulation stack (tests/simulation/run.sh up,
grown with tests/performance/scale-data.sql).

For every page: one request with PostgreSQL statement logging on, to count
the SQL statements the request runs and list the slowest; then, with
logging back at the stack's normal 200 ms threshold, WARMUP untimed and
RUNS timed sequential requests over one keep-alive TLS connection, from
which p50/p95/p99/max and the body size are reported. Queue worker and
scheduler are paused while measuring so their queries do not mix in.

  python3 -I tests/performance/bench.py OUT.json [--runs 30] [--only name,name]

Test infrastructure only: it changes PostgreSQL logging settings and pauses
workers on the disposable simulation stack.
"""
import argparse
import http.client
import json
import os
import re
import ssl
import statistics
import subprocess
import sys
import time

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(os.path.dirname(HERE))
sys.path.insert(0, os.path.join(ROOT, 'tests', 'simulation'))
from simlib import env, http as simhttp, shop  # noqa: E402

simhttp.CA = os.path.join(ROOT, 'tests', 'simulation', 'tls', 'cert.pem')
simhttp.LOOPBACK_PER_CLIENT = False
DB = ['docker', 'exec', 'shopsim-db-1', 'psql', '-U', 'shop', '-d', 'shop', '-Atq', '-c']


def psql(q):
    return subprocess.run(DB + [q], capture_output=True, text=True, check=True).stdout.strip()


def set_logging(all_statements):
    # A role setting: the stack starts PostgreSQL with -c log_min_duration_statement=200,
    # which ALTER SYSTEM cannot override; role settings apply to every new
    # connection (PHP-FPM connects per request).
    if all_statements:
        psql("ALTER ROLE shop IN DATABASE shop SET log_min_duration_statement = 0")
    else:
        psql("ALTER ROLE shop IN DATABASE shop RESET log_min_duration_statement")
    time.sleep(0.5)


def compose(*args):
    subprocess.run(env.COMPOSE + list(args), capture_output=True, check=True)


def pages():
    q = lambda s: env.scalar(s)  # noqa: E731
    product = q("select slug from products p where status='active' and exists (select 1 from product_reviews r where r.product_id=p.id) order by id limit 1")
    order = q("select public_id from orders where buyer_id=(select id from users where email='buyer01@sim.test') order by id desc limit 1")
    admin_order = q("select public_id from orders order by id desc limit 1")
    big_user = q("select id from users where email='buyer01@sim.test'")
    seller_product = q("select id from products where seller_id=(select id from users where email='seller01@sim.test') order by id desc limit 1")
    return [
        # (name, who, path)
        ('home', None, '/'),
        ('catalog', None, '/products'),
        ('catalog page 50', None, '/products?page=50'),
        ('catalog best sellers', None, '/products?sort=best'),
        ('catalog category', None, '/products?category=fonts'),
        ('catalog price range', None, '/products?price_min=5&price_max=50'),
        ('catalog seller', None, '/products?seller=6'),
        ('search', None, '/products?q=font'),
        ('search rare', None, '/products?q=zzzqx'),
        ('search suggest', None, '/search/suggest?q=fon'),
        ('product', None, f'/products/{product}'),
        ('sitemap', None, '/sitemap.xml'),
        ('buyer orders', 'buyer01@sim.test', '/orders'),
        ('buyer order', 'buyer01@sim.test', f'/orders/{order}'),
        ('buyer notifications', 'buyer01@sim.test', '/notifications'),
        ('buyer live poll', 'buyer01@sim.test', '/live?path=/orders'),
        ('buyer wallet', 'buyer01@sim.test', '/wallet'),
        ('buyer product', 'buyer01@sim.test', f'/products/{product}'),
        ('seller dashboard', 'seller01@sim.test', '/seller'),
        ('seller sales', 'seller01@sim.test', '/seller/sales'),
        ('seller products', 'seller01@sim.test', '/seller/products'),
        ('seller product edit', 'seller01@sim.test', f'/seller/products/{seller_product}/edit'),
        ('seller payouts', 'seller01@sim.test', '/seller/payouts'),
        ('seller live poll', 'seller01@sim.test', '/live?path=/seller'),
        ('seller reviews', 'seller01@sim.test', '/seller/reviews'),
        ('admin dashboard', 'superadmin@sim.test', '/admin'),
        ('admin reports', 'superadmin@sim.test', '/admin/reports'),
        ('admin orders', 'superadmin@sim.test', '/admin/orders'),
        ('admin orders search', 'superadmin@sim.test', '/admin/orders?q=perf'),
        ('admin order', 'superadmin@sim.test', f'/admin/orders/{admin_order}'),
        ('admin products', 'superadmin@sim.test', '/admin/products'),
        ('admin users', 'superadmin@sim.test', '/admin/users'),
        ('admin users search', 'superadmin@sim.test', '/admin/users?q=perf-buyer-4999'),
        ('admin user', 'superadmin@sim.test', f'/admin/users/{big_user}'),
        ('admin audit', 'superadmin@sim.test', '/admin/audit'),
        ('admin payments', 'superadmin@sim.test', '/admin/payments'),
        ('admin payouts', 'superadmin@sim.test', '/admin/payouts'),
        ('admin gateway', 'superadmin@sim.test', '/admin/gateway'),
        ('admin health', 'superadmin@sim.test', '/admin/health'),
        ('admin reviews', 'superadmin@sim.test', '/admin/reviews'),
        ('admin sellers', 'superadmin@sim.test', '/admin/sellers'),
        ('admin disputes', 'superadmin@sim.test', '/admin/disputes'),
        ('admin reconciliation', 'superadmin@sim.test', '/admin/reconciliation'),
        ('admin live poll dashboard', 'superadmin@sim.test', '/live?path=/admin'),
        ('admin live poll orders', 'superadmin@sim.test', '/live?path=/admin/orders'),
    ]


class Session:
    """Keep-alive HTTPS connection carrying a signed-in user's cookies."""

    def __init__(self, who):
        self.jar = {}
        if who:
            c = shop.login(who)
            self.jar = {k.name: k.value for k in c.jar}
        self.ctx = ssl.create_default_context(cafile=simhttp.CA)
        self.conn = None

    def get(self, path):
        for attempt in (1, 2):
            if self.conn is None:
                self.conn = http.client.HTTPSConnection('localhost', 8443, context=self.ctx, timeout=120)
            headers = {'User-Agent': 'perf-bench', 'Accept-Encoding': 'gzip'}
            if self.jar:
                headers['Cookie'] = '; '.join(f'{k}={v}' for k, v in self.jar.items())
            try:
                started = time.perf_counter()
                self.conn.request('GET', path, headers=headers)
                r = self.conn.getresponse()
                body = r.read()
                # Keep cookies like a browser, so anonymous visitors keep one session.
                for name, value in r.getheaders():
                    if name.lower() == 'set-cookie':
                        k, _, v = value.split(';', 1)[0].partition('=')
                        self.jar[k.strip()] = v.strip()
                return r.status, (time.perf_counter() - started) * 1000, len(body), dict(r.getheaders())
            except (http.client.HTTPException, OSError):
                self.conn = None
                if attempt == 2:
                    raise


MARK = 'perf-bench-mark'


def statements_for(session, path):
    """Runs one request between two marker statements and returns the app's statements from the PostgreSQL log."""
    # Docker's json-file log of the database container, read from the current
    # end (docker logs --since scans the whole file every time).
    log_path = subprocess.run(['docker', 'inspect', '--format', '{{.LogPath}}', 'shopsim-db-1'], capture_output=True, text=True).stdout.strip()
    offset = os.path.getsize(log_path)
    psql(f"SELECT '{MARK}-start'")
    status, _, _, _ = session.get(path)
    time.sleep(0.3)
    psql(f"SELECT '{MARK}-end'")
    time.sleep(0.3)
    with open(log_path, 'rb') as f:
        f.seek(offset)
        lines = [json.loads(x).get('log', '').rstrip('\n') for x in f.read().decode('utf-8', 'replace').splitlines() if x.strip()]
    try:
        a = max(i for i, x in enumerate(lines) if f'{MARK}-start' in x)
        b = max(i for i, x in enumerate(lines) if f'{MARK}-end' in x)
    except ValueError:
        return status, None
    by_pid, pending = {}, {}
    for line in lines[a + 1:b]:
        m = re.search(r'\[(\d+)\] \S*?LOG:\s+(?:duration: ([\d.]+) ms\s*)?(?:(statement|execute [^:]*|parse [^:]*|bind [^:]*): (.*))?$', line)
        if not m:
            continue
        pid, ms, kind, text = m.groups()
        if kind is None:
            # Bare "duration:" line: belongs to the write statement just logged by log_statement=mod.
            if ms is not None and pid in pending:
                pending.pop(pid)['ms'] += float(ms)
            continue
        if MARK in text:
            continue
        stmts = by_pid.setdefault(pid, [])
        if kind.startswith(('parse', 'bind')):
            # Extended protocol: parse and bind (planning) are separate round trips; their time counts towards the next execute.
            pending_time = by_pid.setdefault(pid + ':pre', [0.0])
            pending_time[0] += float(ms or 0)
            # A named prepared statement (PDO's default) is its own round trip;
            # an unnamed one (PQexecParams) travels with its bind and execute.
            if kind.startswith('parse pdo_stmt_'):
                stmts.append({'ms': 0.0, 'sql': 'PREPARE', 'kind': 'parse'})
            continue
        pre = by_pid.pop(pid + ':pre', [0.0])[0]
        entry = {'ms': float(ms or 0) + pre, 'sql': text[:400], 'kind': kind.split(' ')[0]}
        stmts.append(entry)
        if ms is None:
            pending[pid] = entry
    by_pid = {k: v for k, v in by_pid.items() if not k.endswith(':pre')}
    if not by_pid:
        return status, []
    # PHP-FPM opens one connection per request, so the request is the backend with
    # the most statements (container health checks run their own few).
    return status, max(by_pid.values(), key=len)


def pct(values, p):
    values = sorted(values)
    k = (len(values) - 1) * p / 100
    f, c = int(k), min(int(k) + 1, len(values) - 1)
    return values[f] + (values[c] - values[f]) * (k - f)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('out')
    ap.add_argument('--runs', type=int, default=30)
    ap.add_argument('--warmup', type=int, default=3)
    ap.add_argument('--only', default='')
    a = ap.parse_args()

    only = set(filter(None, a.only.split(',')))
    sessions = {}
    results = []
    compose('stop', 'queue', 'scheduler')
    try:
        todo = [p for p in pages() if not only or p[0] in only]
        set_logging(True)
        for name, who, path in todo:
            if who not in sessions:
                sessions[who] = Session(who)
            s = sessions[who]
            s.get(path)  # warm caches the same way as the timed runs
            status, stmts = statements_for(s, path)
            real = [x for x in stmts or [] if x['kind'] != 'parse' and not x['sql'].startswith(('DEALLOCATE', 'set search_path'))]
            results.append({'name': name, 'who': who or 'anonymous', 'path': path, 'status': status,
                            'queries': None if stmts is None else len(real),
                            # PDO's server-side prepares: PREPARE, EXECUTE and DEALLOCATE are one round trip each.
                            'round_trips': None if stmts is None else len(stmts),
                            'query_ms': None if stmts is None else round(sum(x['ms'] for x in real), 1),
                            'slowest': sorted(stmts or [], key=lambda x: -x['ms'])[:3],
                            'statements': [x['sql'][:160] for x in real]})
        set_logging(False)
        for r in results:
            s = sessions[None if r['who'] == 'anonymous' else r['who']]
            for _ in range(a.warmup):
                s.get(r['path'])
            times, size = [], 0
            for _ in range(a.runs):
                status, ms, size, _ = s.get(r['path'])
                times.append(ms)
            r.update({'runs': a.runs, 'p50_ms': round(pct(times, 50), 1), 'p95_ms': round(pct(times, 95), 1),
                      'p99_ms': round(pct(times, 99), 1), 'max_ms': round(max(times), 1),
                      'mean_ms': round(statistics.mean(times), 1), 'stdev_ms': round(statistics.pstdev(times), 1),
                      'bytes_gzip': size})
            print(f"{r['name']:<24} {r['status']} q={r['queries']!s:>4} rt={r['round_trips']!s:>4} qms={r['query_ms']!s:>8} p50={r['p50_ms']:>8} p95={r['p95_ms']:>8} {size}B", flush=True)
    finally:
        set_logging(False)
        compose('start', 'queue', 'scheduler')
    meta = {'generated': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()),
            'revision': subprocess.run(['git', '-C', ROOT, 'rev-parse', 'HEAD'], capture_output=True, text=True).stdout.strip(),
            'image': subprocess.run(['docker', 'inspect', '--format', '{{.Image}}', 'shopsim-app-1'], capture_output=True, text=True).stdout.strip(),
            'runs': a.runs, 'warmup': a.warmup, 'cpus': os.cpu_count(),
            'rows': {t: int(psql(f'select count(*) from {t}')) for t in ('products', 'orders', 'order_items', 'users', 'audit_log', 'user_notifications', 'product_reviews', 'seller_ledger_entries', 'webhook_events')}}
    with open(a.out, 'w', encoding='utf-8') as f:
        json.dump({'meta': meta, 'pages': results}, f, indent=1)


if __name__ == '__main__':
    main()
