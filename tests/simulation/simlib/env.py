"""Operations on the stack under test, for both backends:

compose  docker compose -f docker-compose.yml -f docker-compose.test.yml
         (the production image, PHP-FPM; the certification backend)
host     the same containers for nginx/TLS, PostgreSQL 15, Mailpit and the
         Shkeeper mock, with the app, queue worker and scheduler as host PHP
         processes (used while the app image cannot be built)

Both expose: https://localhost:8443 (TLS front end), PostgreSQL on
127.0.0.1:5433, Mailpit on 127.0.0.1:8025, the mock on 127.0.0.1:8081.
"""
import json
import os
import subprocess
import time
import urllib.error
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))))
HERE = os.path.join(ROOT, 'tests', 'simulation')
BACKEND = os.environ.get('SIM_BACKEND', 'compose')
WORK = os.environ.get('SIM_WORK', os.path.join(HERE, 'work'))
PG = {'host': '127.0.0.1', 'port': '5433', 'user': os.environ.get('SIM_DB_USER', 'shop'),
      'password': os.environ.get('SIM_DB_PASSWORD', 'shop'), 'db': os.environ.get('SIM_DB_NAME', 'shop')}
MAILPIT = 'http://127.0.0.1:8025'
MOCK = 'http://127.0.0.1:8081'
COMPOSE = ['docker', 'compose', '-p', 'shopsim', '-f', os.path.join(ROOT, 'docker-compose.yml'),
           '-f', os.path.join(ROOT, 'docker-compose.test.yml'), '--profile', 'test']


def sh(cmd, check=True, timeout=600, env=None, input=None):
    out = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout, env=env, input=input)
    if check and out.returncode != 0:
        raise RuntimeError(f'{" ".join(cmd)} failed ({out.returncode}): {out.stdout[-1500:]} {out.stderr[-1500:]}')
    return out


def sql(query):
    """Rows as lists of strings (psql -At, fields separated by |)."""
    env = dict(os.environ, PGPASSWORD=PG['password'])
    out = sh(['psql', '-h', PG['host'], '-p', PG['port'], '-U', PG['user'], '-d', PG['db'], '-At', '-F', '|', '-v', 'ON_ERROR_STOP=1', '-c', query], env=env)
    return [line.split('|') for line in out.stdout.strip().splitlines() if line != '']


def scalar(query):
    rows = sql(query)
    return rows[0][0] if rows else None


def artisan(*args, check=True, extra_env=None, timeout=600):
    extra_env = extra_env or {}
    if BACKEND == 'compose':
        cmd = COMPOSE + ['exec', '-T'] + [x for k, v in extra_env.items() for x in ('-e', f'{k}={v}')] + ['app', 'php', 'artisan', *args]
        return sh(cmd, check=check, timeout=timeout)
    out = subprocess.run(['php', 'artisan', *args], cwd=os.path.join(WORK, 'app'), capture_output=True, text=True,
                         timeout=timeout, env=dict(os.environ, **extra_env))
    if check and out.returncode != 0:
        raise RuntimeError(f'artisan {" ".join(args)} failed ({out.returncode}): {out.stdout[-1500:]} {out.stderr[-1500:]}')
    return out


def run_sh(*args, check=True, timeout=900):
    """tests/simulation/run.sh <command> for service control."""
    return sh(['sh', os.path.join(HERE, 'run.sh'), *args], check=check, timeout=timeout,
              env=dict(os.environ, SIM_BACKEND=BACKEND, SIM_WORK=WORK))


# ---- services --------------------------------------------------------------

def stop(service):
    run_sh('stop-service', service)


def start(service):
    run_sh('start-service', service)


def restart_workers():
    run_sh('restart-workers')


def kill_worker():
    """kill -9 the queue worker process; its supervisor starts a new one."""
    run_sh('kill-worker')


def disk_full(free_kb=256):
    """Leaves only free_kb of space for product file uploads."""
    run_sh('disk-full', str(free_kb))


def disk_free():
    run_sh('disk-free')


def backup():
    """Runs scripts/backup.sh the way the deployment does; returns (ok, output, backup dir)."""
    if BACKEND == 'compose':
        out = sh(COMPOSE + ['--profile', 'backup', 'run', '--rm', '-T', '--entrypoint', 'sh', 'backup', '-c',
                            'cd /app && sh scripts/backup.sh /backups'], check=False)
    else:
        app = os.path.join(WORK, 'app')
        out = subprocess.run(['sh', os.path.join(app, 'scripts', 'backup.sh'), os.path.join(WORK, 'backups')],
                             cwd=app, capture_output=True, text=True)
    text = out.stdout + out.stderr
    return out.returncode == 0, text, (out.stdout.strip().split() or [''])[-1]


def restore(backup_dir):
    """Runs scripts/restore.sh (answers its confirmation); returns (ok, output)."""
    if BACKEND == 'compose':
        out = sh(COMPOSE + ['--profile', 'backup', 'run', '--rm', '-T', '--entrypoint', 'sh', 'backup', '-c',
                            f'cd /app && sh scripts/restore.sh {backup_dir}'], check=False, input='restore\n')
        if out.returncode == 0:
            artisan('optimize:clear', check=False)
    else:
        app = os.path.join(WORK, 'app')
        out = subprocess.run(['sh', os.path.join(app, 'scripts', 'restore.sh'), backup_dir], cwd=app,
                             capture_output=True, text=True, input='restore\n')
    return out.returncode == 0, out.stdout + out.stderr


def app_logs():
    """All JSON log lines the app wrote so far (parsed)."""
    if BACKEND == 'compose':
        out = sh(COMPOSE + ['exec', '-T', 'app', 'sh', '-c', 'cat storage/logs/shop-*.log 2>/dev/null || true']).stdout
    else:
        out = ''
        logdir = os.path.join(WORK, 'app', 'storage', 'logs')
        for f in sorted(os.listdir(logdir)) if os.path.isdir(logdir) else []:
            if f.startswith('shop'):
                with open(os.path.join(logdir, f), encoding='utf-8', errors='replace') as fh:
                    out += fh.read()
    lines = []
    for line in out.splitlines():
        try:
            lines.append(json.loads(line))
        except ValueError:
            pass
    return lines


def db_log():
    """PostgreSQL server log (data changes and statements over 200 ms)."""
    name = 'shopsim-db-1' if BACKEND == 'compose' else 'shopsim-db'
    out = sh(['docker', 'logs', name], check=False)
    return out.stdout + out.stderr


# ---- mail (Mailpit) -------------------------------------------------------

def _http(method, url, body=None, timeout=60):
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(url, data=data, method=method, headers={'Content-Type': 'application/json'})
    for attempt in range(10):  # a test service that was just restarted may not accept connections yet
        try:
            with urllib.request.urlopen(req, timeout=timeout) as r:
                raw = r.read()
            break
        except (ConnectionResetError, ConnectionRefusedError, urllib.error.URLError) as e:
            if isinstance(e, urllib.error.HTTPError) or attempt == 9:
                raise
            time.sleep(1)
    try:
        return json.loads(raw) if raw else None
    except ValueError:
        return raw.decode('utf-8', 'replace')


def mails(to=None, subject=None):
    """Messages (newest first) matching recipient and subject substring."""
    data = _http('GET', MAILPIT + '/api/v1/messages?limit=500')
    out = []
    for m in data.get('messages', []):
        rcpts = [a['Address'].lower() for a in m.get('To', [])]
        if to and to.lower() not in rcpts:
            continue
        if subject and subject.lower() not in (m.get('Subject') or '').lower():
            continue
        out.append(m)
    return out


def mail_text(message_id):
    return _http('GET', f'{MAILPIT}/api/v1/message/{message_id}')['Text']


def wait_mail(to, subject, timeout=60, count=1):
    end = time.time() + timeout
    while time.time() < end:
        found = mails(to, subject)
        if len(found) >= count:
            return found
        time.sleep(1)
    raise AssertionError(f'no mail to {to} with subject containing {subject!r} within {timeout}s (have: {[m["Subject"] for m in mails(to)][:8]})')


def clear_mail():
    _http('DELETE', MAILPIT + '/api/v1/messages')


# ---- Shkeeper mock -----------------------------------------------------

def mock(path, body=None, timeout=90):
    return _http('POST', MOCK + path, body or {}, timeout=timeout)


def mock_get(path):
    return _http('GET', MOCK + path)


def callback_url():
    return 'http://web:8080/webhooks/shkeeper' if BACKEND == 'compose' else 'http://127.0.0.1:8080/webhooks/shkeeper'


# ---- reset ----------------------------------------------------------------

def reset():
    """Fresh database with the simulation seed, empty mailbox, mock and uploads."""
    run_sh('reset')
    clear_mail()
    mock('/__mock/reset')
