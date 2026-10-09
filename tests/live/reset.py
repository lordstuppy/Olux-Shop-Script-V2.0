# Resets the live environment to a fresh production install with one admin
# created through the interactive shop:create-admin command (driven via a pty).
import os, sys, pty, time, select, subprocess, shutil, urllib.request
L = sys.argv[1]
APP = os.path.join(L, 'app')
RUN = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'run.sh')
subprocess.run(['php', 'artisan', 'migrate:fresh', '--force', '-q'], cwd=APP, check=True)
subprocess.run(['sh', RUN, 'restart'], check=True)
time.sleep(2)
for d in ['storage/app/private', 'storage/invoices']:
    p = os.path.join(APP, d)
    if os.path.isdir(p):
        shutil.rmtree(p)
for p in [os.path.join(L, 'logs', 'mail.txt')]:
    if os.path.exists(p): os.remove(p)
for f in os.listdir(os.path.join(APP, 'storage/logs')):
    if f.startswith('shop'): os.remove(os.path.join(APP, 'storage/logs', f))
urllib.request.urlopen(urllib.request.Request('http://127.0.0.1:8081/__mock/reset', data=b'{}', method='POST'))
pid, fd = pty.fork()
if pid == 0:
    os.chdir(APP)
    os.execvp('php', ['php', 'artisan', 'shop:create-admin', 'admin@live.test', '--name=Live Admin'])
def drain(t):
    end = time.time() + t
    while time.time() < end:
        r, _, _ = select.select([fd], [], [], 0.2)
        if r:
            try: os.read(fd, 4096)
            except OSError: return
drain(3); os.write(fd, b'Admin-live-pass-2026\r'); drain(2); os.write(fd, b'Admin-live-pass-2026\r'); drain(4)
_, st = os.waitpid(pid, 0)
if st != 0:
    sys.exit('shop:create-admin failed')
print('Reset to a fresh install with admin@live.test.')
