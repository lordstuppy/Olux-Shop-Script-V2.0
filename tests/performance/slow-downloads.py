"""Resource experiment: do slow downloads hold PHP-FPM workers?

Downloads stream through PHP (DownloadController). nginx buffers FastCGI
responses by default (fastcgi_buffering on, up to fastcgi_max_temp_file_size
1024m on disk), so PHP should hand a file to nginx at disk speed and be free
again while nginx feeds a slow client. This script checks that on the stack:

1. seller01 attaches a 20 MB file to one of their listed products; buyer03
   buys it with their balance;
2. CONCURRENCY clients (more than the 20 FPM workers) download it at
   RATE bytes/s each, which would take minutes per download;
3. meanwhile the catalogue page is requested every second; its latency shows
   whether requests still find a free PHP worker.

  python3 -I tests/performance/slow-downloads.py OUT.json

Test infrastructure only (simulation stack).
"""
import json
import os
import re
import socket
import ssl
import sys
import threading
import time

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(os.path.dirname(HERE))
sys.path.insert(0, os.path.join(ROOT, 'tests', 'simulation'))
from simlib import env, http as simhttp, shop  # noqa: E402

simhttp.CA = os.path.join(ROOT, 'tests', 'simulation', 'tls', 'cert.pem')
CONCURRENCY = 25
RATE = 50_000  # bytes per second per client
HOLD = 45  # seconds each slow client keeps reading


def slow_download(url, cookies, result):
    """Reads the response at RATE bytes/s for HOLD seconds, then hangs up."""
    ctx = ssl.create_default_context(cafile=simhttp.CA)
    path = url.split('8443', 1)[1]
    s = ctx.wrap_socket(socket.create_connection(('localhost', 8443)), server_hostname='localhost')
    s.sendall(f'GET {path} HTTP/1.1\r\nHost: localhost:8443\r\nCookie: {cookies}\r\nConnection: close\r\n\r\n'.encode())
    s.setsockopt(socket.SOL_SOCKET, socket.SO_RCVBUF, 16384)
    started, got, first = time.time(), 0, b''
    while time.time() - started < HOLD:
        chunk = s.recv(min(16384, RATE))
        if not chunk:
            break
        if not first:
            first = chunk[:12]
        got += len(chunk)
        time.sleep(len(chunk) / RATE)
    s.close()
    result.append({'status_line': first.decode('latin1'), 'bytes_read': got})


def main(out):
    seller = shop.login('seller01@sim.test')
    pid = env.scalar("""select p.id from products p join users u on u.id = p.seller_id where u.email = 'seller01@sim.test'
        and p.status = 'active' and p.delivery_type = 'instant' and p.stock is null order by p.id limit 1""")
    payload = os.urandom(20 * 1024 * 1024)
    r = seller.form(f'/seller/products/{pid}/edit', f'/seller/products/{pid}/files', {}, files={'file': ('big.zip', payload, 'application/zip')})
    env.sql(f"update products set status = 'active' where id = {pid} and status = 'pending_review'")
    shop.wait_for(lambda: env.scalar(f"select count(*) from product_files where product_id = {pid} and original_name = 'big.zip' and scan_status = 'clean'") != '0',
                  'virus scan of the 20 MB file', timeout=120)
    env.sql(f"update product_files set retired_at = now() where product_id = {pid} and original_name <> 'big.zip'")

    buyer = shop.login('buyer03@sim.test')
    shop.add_to_cart(buyer, {'id': int(pid), 'slug': env.scalar(f'select slug from products where id = {pid}')})
    _, oid = shop.checkout(buyer, 'balance')
    shop.wait_for(lambda: env.scalar(f"select status from orders where public_id = '{oid}'") == 'delivered', 'delivery', timeout=60)
    env.sql(f"update order_items set download_count = 0 where order_id = (select id from orders where public_id = '{oid}')")
    env.sql(f"update products set download_limit = 1000 where id = {pid}")
    page = buyer.get(f'/orders/{oid}').text
    url = re.search(r'href="(https://localhost:8443/orders/[^"]+/files/[^"]+)"', page).group(1).replace('&amp;', '&')
    cookies = '; '.join(f'{k.name}={k.value}' for k in buyer.jar)

    probe = simhttp.Client('probe')
    before = [probe.get('/products').duration_ms for _ in range(5)]
    results, threads = [], []
    for _ in range(CONCURRENCY):
        t = threading.Thread(target=slow_download, args=(url, cookies, results))
        t.start()
        threads.append(t)
    time.sleep(5)
    during = []
    for _ in range(30):
        during.append(probe.get('/products').duration_ms)
        time.sleep(1)
    workers = env.sh(['docker', 'exec', 'shopsim-app-1', 'sh', '-c', 'ps -o stat,args | grep "php-fpm: pool" | grep -v grep'], check=False).stdout
    for t in threads:
        t.join()
    after = [probe.get('/products').duration_ms for _ in range(5)]
    report = {'file_bytes': len(payload), 'concurrency': CONCURRENCY, 'rate_bytes_per_s': RATE, 'hold_s': HOLD, 'fpm_max_children': 20,
              'catalog_ms_before': before, 'catalog_ms_during': during, 'catalog_ms_after': after,
              'catalog_p50_during': sorted(during)[len(during) // 2], 'catalog_max_during': max(during),
              'downloads': results, 'fpm_workers_during': workers.strip().splitlines()}
    json.dump(report, open(out, 'w'), indent=1)
    print(json.dumps({k: report[k] for k in ('catalog_ms_before', 'catalog_p50_during', 'catalog_max_during', 'catalog_ms_after')}))
    print('downloads:', [(d['status_line'], d['bytes_read']) for d in results][:5], '...')


if __name__ == '__main__':
    main(sys.argv[1])
