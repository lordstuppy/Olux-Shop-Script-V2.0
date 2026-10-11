"""Dynamic security probes against the simulation stack (production image).

Writes probe-results.json next to this file. Each probe records what was sent,
what came back and PASS/FAIL against the expected secure behaviour.
"""
import hashlib, hmac, http.client, json, os, re, ssl, statistics, subprocess, time, urllib.parse

HERE = os.path.dirname(os.path.abspath(__file__))
HOST, PORT = 'localhost', 8443
# The test stack's self-signed certificate (tests/simulation/tls/cert.pem) is the only trusted CA.
CERT = os.environ.get('PROBE_CA', os.path.join(os.path.dirname(HERE), '..', '..', 'tests', 'simulation', 'tls', 'cert.pem'))
CTX = ssl.create_default_context(cafile=CERT)
results = []


def req(method, path, headers=None, body=None, host=HOST, port=PORT, tls=True):
    conn = (http.client.HTTPSConnection(host, port, context=CTX, timeout=30) if tls  # nosemgrep: python.lang.security.audit.httpsconnection-detected.httpsconnection-detected -- CTX verifies against the test CA
            else http.client.HTTPConnection(host, port, timeout=30))
    h = {'User-Agent': 'security-probe'}
    h.update(headers or {})
    t = time.perf_counter()
    conn.request(method, path, body=body, headers=h)
    r = conn.getresponse()
    data = r.read()
    dt = time.perf_counter() - t
    out = (r.status, {k.lower(): v for k, v in r.getheaders()}, data.decode('utf-8', 'replace'), dt)
    conn.close()
    return out


def record(pid, title, ok, evidence):
    results.append({'id': pid, 'title': title, 'status': 'PASS' if ok else 'FAIL', 'evidence': evidence})
    print(('PASS ' if ok else 'FAIL ') + pid + ' ' + title + ' :: ' + json.dumps(evidence)[:300])


def sql(q):
    return subprocess.run(['docker', 'exec', 'shopsim-db-1', 'psql', '-U', 'shop', '-d', 'shop', '-At', '-c', q],
                          capture_output=True, text=True).stdout.strip()


def cookies(headers_list):
    jar = {}
    for k, v in headers_list:
        if k.lower() == 'set-cookie':
            name, val = v.split(';', 1)[0].split('=', 1)
            jar[name] = val
    return jar


def session_get(path, jar):
    conn = http.client.HTTPSConnection(HOST, PORT, context=CTX, timeout=30)  # nosemgrep: python.lang.security.audit.httpsconnection-detected.httpsconnection-detected -- CTX verifies against the test CA
    conn.request('GET', path, headers={'Cookie': '; '.join(f'{k}={v}' for k, v in jar.items())})
    r = conn.getresponse()
    body = r.read().decode('utf-8', 'replace')
    jar.update(cookies(r.getheaders()))
    return r.status, body


# --- CORS: no cross-origin read access to anything --------------------------------
for path in ['/', '/live', '/search/suggest?q=a', '/health', '/notifications', '/account']:
    for method in ['GET', 'OPTIONS']:
        s, h, b, _ = req(method, path, {'Origin': 'https://evil.example', 'Access-Control-Request-Method': 'GET'})
        acao = h.get('access-control-allow-origin')
        record('CORS', f'{method} {path} with foreign Origin', acao is None, {'status': s, 'acao': acao, 'acac': h.get('access-control-allow-credentials')})

# --- CSRF: state changes without a token or from a foreign site are refused ---------
s, h, b, _ = req('POST', '/cart/items', {'Content-Type': 'application/x-www-form-urlencoded', 'Origin': 'https://evil.example'}, 'product_id=1')
record('CSRF', 'POST /cart/items without token', s == 419, {'status': s})
s, h, b, _ = req('POST', '/theme', {'Content-Type': 'application/x-www-form-urlencoded'}, 'theme=dark')
record('CSRF', 'POST /theme without token', s == 419, {'status': s})
s, h, b, _ = req('GET', '/logout')
record('CSRF', 'GET /logout is not a state change', s in (404, 405), {'status': s})
s, h, b, _ = req('POST', '/login', {'Content-Type': 'application/json', 'Accept': 'application/json'}, json.dumps({'email': 'a@b.c', 'password': 'x'}))
record('CSRF', 'JSON POST /login without token', s == 419, {'status': s})

# --- Exposed files and debug endpoints -----------------------------------------------
for path in ['/.env', '/.env.example', '/.git/config', '/.git/HEAD', '/composer.json', '/composer.lock', '/artisan', '/storage/logs/laravel.log',
             '/vendor/autoload.php', '/index.php.bak', '/phpinfo.php', '/info.php', '/server-status', '/nginx_status', '/telescope', '/horizon',
             '/_ignition/health-check', '/_debugbar', '/storage/app/private/products/1/', '/../.env', '/%2e%2e/.env', '/public/../.env',
             '/docker-compose.yml', '/Dockerfile', '/tests/simulation/sim.env', '/backups/', '/up', '/api/user']:
    s, h, b, _ = req('GET', path)
    leaked = any(m in b for m in ['APP_KEY=', 'DB_PASSWORD', '[core]', '"require"', '<?php', 'phpinfo()', 'Stack trace', 'Whoops'])
    record('EXPOSURE', f'GET {path}', s in (400, 403, 404) and not leaked, {'status': s, 'leak': leaked})
s, h, b, _ = req('GET', '/metrics')
record('EXPOSURE', 'GET /metrics without token', s in (401, 403, 404), {'status': s})
s, h, b, _ = req('GET', '/health')
record('EXPOSURE', 'GET /health reveals no internals', s == 200 and 'password' not in b.lower() and 'exception' not in b.lower() and len(b) < 400, {'status': s, 'body': b[:200]})
s, h, b, _ = req('GET', '/products?sort=' + urllib.parse.quote("price'"))
record('ERRORS', 'Malformed input shows no stack trace', 'Stack trace' not in b and 'SQLSTATE' not in b and s < 500, {'status': s})
s, h, b, _ = req('GET', '/')
record('HEADERS', 'No X-Powered-By, no nginx version', 'x-powered-by' not in h and h.get('server', '') == 'nginx', {'server': h.get('server'), 'x-powered-by': h.get('x-powered-by')})

# --- Host header: links always use APP_URL -------------------------------------------
s, h, b, _ = req('GET', '/login', {'Host': 'evil.example'})
hosts = sorted(set(re.findall(r'(?:href|action|src)="https?://([^/"]+)', b)))
record('HOST', 'Forged Host header does not reach absolute URLs', s == 200 and hosts == ['localhost:8443'], {'status': s, 'hosts_in_links': hosts})
s, h, b, _ = req('GET', '/login', {'X-Forwarded-Host': 'evil.example'})
hosts = sorted(set(re.findall(r'(?:href|action|src)="https?://([^/"]+)', b)))
record('HOST', 'X-Forwarded-Host is ignored', hosts == ['localhost:8443'], {'hosts_in_links': hosts})

# --- Request size limits --------------------------------------------------------------
big = b'a' * (61 * 1024 * 1024)
try:
    s, h, b, _ = req('POST', '/cart/items', {'Content-Type': 'application/x-www-form-urlencoded'}, big)
except Exception as e:  # nginx may close the connection early
    s = f'closed: {type(e).__name__}'
record('LIMITS', '61 MB body is refused before PHP', s == 413 or str(s).startswith('closed'), {'status': s})
s, h, b, _ = req('GET', '/products?q=' + 'a' * 9000)
record('LIMITS', 'Very long query string', s in (200, 414, 422, 400), {'status': s})
s, h, b, _ = req('GET', '/products', {'X-Big': 'b' * 20000})
record('LIMITS', 'Oversized header', s in (400, 431, 200), {'status': s})

# --- Webhooks: forged, unsigned, stale, replayed -------------------------------------
body = json.dumps({'external_id': '00000000-0000-0000-0000-000000000000', 'status': 'PAID', 'paid': True, 'balance_fiat': '999.00', 'fiat': 'USD'})
s, h, b, _ = req('POST', '/webhooks/shkeeper', {'Content-Type': 'application/json'}, body)
record('WEBHOOK', 'Unsigned payment callback', s == 401, {'status': s})
s, h, b, _ = req('POST', '/webhooks/shkeeper', {'Content-Type': 'application/json', 'X-Shkeeper-Api-Key': 'guess', 'X-Shkeeper-Timestamp': str(int(time.time())),
                                                'X-Shkeeper-Signature': 'a' * 64}, body)
record('WEBHOOK', 'Wrong signature', s == 401, {'status': s})
secret = 'sim-api-key-0123456789'  # test stack secret, to prove the replay window
ts = str(int(time.time()) - 3600)
sig = hmac.new(secret.encode(), (ts + '.' + body).encode(), hashlib.sha256).hexdigest()
s, h, b, _ = req('POST', '/webhooks/shkeeper', {'Content-Type': 'application/json', 'X-Shkeeper-Timestamp': ts, 'X-Shkeeper-Signature': sig}, body)
record('WEBHOOK', 'Correct signature but timestamp one hour old', s == 401, {'status': s})
s, h, b, _ = req('POST', '/webhooks/shkeeper/payouts', {'Content-Type': 'application/json'}, json.dumps({'external_id': 'payout-1', 'status': 'SUCCESS'}))
record('WEBHOOK', 'Unsigned payout callback', s == 401, {'status': s})

# --- Rate limits cannot be dodged with forwarded headers ----------------------------
def login_form():
    conn = http.client.HTTPSConnection(HOST, PORT, context=CTX, timeout=30)  # nosemgrep: python.lang.security.audit.httpsconnection-detected.httpsconnection-detected -- CTX verifies against the test CA
    conn.request('GET', '/login')
    r = conn.getresponse(); page = r.read().decode(); jar = cookies(r.getheaders()); conn.close()
    return re.search(r'name="_token" value="([^"]+)"', page).group(1), jar


def post_login(token, jar, email, extra=None):
    conn = http.client.HTTPSConnection(HOST, PORT, context=CTX, timeout=30)  # nosemgrep: python.lang.security.audit.httpsconnection-detected.httpsconnection-detected -- CTX verifies against the test CA
    h = {'Content-Type': 'application/x-www-form-urlencoded', 'Cookie': '; '.join(f'{k}={v}' for k, v in jar.items())}
    h.update(extra or {})
    t = time.perf_counter()
    conn.request('POST', '/login', body=urllib.parse.urlencode({'_token': token, 'email': email, 'password': 'Wrong-password-123'}), headers=h)
    r = conn.getresponse(); r.read(); conn.close()
    return r.status, time.perf_counter() - t


token, jar = login_form()
statuses = [post_login(token, jar, 'probe-target@sim.test', {'X-Forwarded-For': f'198.51.100.{i}'})[0] for i in range(8)]
record('RATELIMIT', 'Per-email login limit (5/min) holds while X-Forwarded-For rotates', statuses[:5] == [302] * 5 and statuses[5:] == [429] * 3,
       {'statuses': statuses})
statuses = []
for i in range(80):
    s, h, b, _ = req('GET', '/search/suggest?q=ab', {'X-Forwarded-For': f'198.51.100.{i}', 'Accept': 'application/json'})
    statuses.append(s)
record('RATELIMIT', 'Search limiter (60/min) holds while X-Forwarded-For rotates', 429 in statuses, {'first_429_at': statuses.index(429) if 429 in statuses else None})

# --- Login timing: known vs unknown email --------------------------------------------
known, unknown, codes = [], [], []
time.sleep(61)  # let the login limiters from the probes above expire
token, jar = login_form()
for i in range(8):
    sk, tk = post_login(token, jar, f'buyer{40 + i:02d}@sim.test')
    su, tu = post_login(token, jar, f'no-such-user-{i}@sim.test')
    known.append(tk); unknown.append(tu); codes += [sk, su]
record('ENUM', 'All timing samples were real login attempts (not throttled)', all(c == 302 for c in codes), {'statuses': sorted(set(codes))})
mk, mu = statistics.median(known), statistics.median(unknown)
record('ENUM', 'Login response time does not reveal registered emails', abs(mk - mu) < 0.05,
       {'median_known_s': round(mk, 4), 'median_unknown_s': round(mu, 4)})

json.dump(results, open(os.path.join(HERE, 'probe-results.json'), 'w'), indent=1)
print('passed', sum(r['status'] == 'PASS' for r in results), 'of', len(results))
