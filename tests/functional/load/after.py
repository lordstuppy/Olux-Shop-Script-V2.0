"""After a load profile: recovery, data invariants and a result file.

  python3 -I tests/functional/load/after.py <profile> <dir> <data.json>

Reads <dir>/k6-summary.json and <dir>/samples.jsonl, then:
- recovery: waits until the queue is empty (at most 3 minutes) and times 10
  catalogue requests after the load, against 10 timed before it (<dir>/before.json);
- invariants: the simulation's data invariants (simlib/shop.py), failed jobs,
  and for the contention profile: exactly the limited units sold, stock 0;
- writes <dir>/result.json with the workload, latency percentiles, error rate,
  resource peaks and a PASS/FAIL per check. Exit code 1 on any FAIL.
"""
import json
import os
import statistics
import sys
import time

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))))
sys.path.insert(0, os.path.join(ROOT, 'tests', 'simulation'))

from simlib import env, http, shop  # noqa: E402

http.CA = os.path.join(ROOT, 'tests', 'simulation', 'tls', 'cert.pem')


def probe(n=10):
    c = http.Client('recovery-probe')
    times = []
    for _ in range(n):
        r = c.raw('GET', '/products')
        times.append(r.duration_ms if r.status == 200 else 60000)
    return {'median_ms': statistics.median(times), 'max_ms': max(times)}


def mem_mb(text):
    num = float(''.join(ch for ch in text if ch.isdigit() or ch == '.') or 0)
    return num * (1024 if 'GiB' in text else 1 / 1024 if 'KiB' in text else 1)


def main(profile, d, data_path):
    if sys.argv[1:2] == ['--before']:
        return
    if not os.path.exists(os.path.join(d, 'k6-summary.json')):
        json.dump({'profile': profile, 'checks': {'k6 produced a summary': 'FAIL'}, 'error': open(os.path.join(d, 'k6.log')).read()[-2000:]},
                  open(os.path.join(d, 'result.json'), 'w'), indent=1)
        print(f'{profile}: no k6 summary (see k6.log)')
        return 1
    summary = json.load(open(os.path.join(d, 'k6-summary.json')))
    m = summary['metrics']
    dur = m['http_req_duration']['values']
    samples = [json.loads(x) for x in open(os.path.join(d, 'samples.jsonl'))] if os.path.exists(os.path.join(d, 'samples.jsonl')) else []
    peaks = {}
    for s in samples:
        for name, c in (s.get('containers') or {}).items():
            p = peaks.setdefault(name, {'cpu_peak': 0.0, 'cpu_sum': 0.0, 'n': 0, 'mem_peak_mb': 0.0})
            p['cpu_peak'] = max(p['cpu_peak'], c['cpu'])
            p['cpu_sum'] += c['cpu']
            p['n'] += 1
            p['mem_peak_mb'] = max(p['mem_peak_mb'], round(mem_mb(c['mem']), 1))
    for p in peaks.values():
        p['cpu_avg'] = round(p.pop('cpu_sum') / max(1, p.pop('n')), 1)
    db_peak = max((sum(s.get('db_connections', {}).values()) for s in samples), default=None)
    queue_peak = max((s.get('jobs_queued', 0) for s in samples), default=None)
    load_peak = max((s['load1'] for s in samples), default=None)
    aborted_by_ceiling = any('abort' in s for s in samples)

    drained_after = None
    t0 = time.time()
    while time.time() - t0 < 180:
        if int(env.scalar('select count(*) from jobs where available_at <= extract(epoch from now())')) == 0:
            drained_after = round(time.time() - t0, 1)
            break
        time.sleep(2)
    time.sleep(5)
    before = json.load(open(os.path.join(d, 'before.json')))
    after = probe()
    problems = shop.check_invariants()
    failed_jobs = int(env.scalar('select count(*) from failed_jobs'))

    checks = {
        'error rate under 5 %': m['http_req_failed']['values']['rate'] < 0.05,
        'no data invariant violated': not problems,
        'no failed background job': failed_jobs == 0,
        'queue drained within 3 minutes': drained_after is not None,
        'recovered: catalogue median within 2x of before the load (+100 ms)': after['median_ms'] <= 2 * before['median_ms'] + 100,
    }
    extra = {}
    if profile.startswith('stress'):
        # Stress looks for the limit: crossing an abort threshold is the expected end, not a failure.
        # What must hold is the data, the queue and the recovery afterwards.
        checks.pop('error rate under 5 %')
        crossed = [k for k, v in m.items() if any(not t.get('ok', True) for t in (v.get('thresholds') or {}).values())]
        extra['operating_limit'] = (f'abort threshold crossed ({", ".join(crossed)}) at up to {int(m.get("vus_max", {}).get("values", {}).get("max", 0))} browsers'
                                    if crossed else f'no abort threshold crossed up to {int(m.get("vus_max", {}).get("values", {}).get("max", 0))} browsers')
    if profile == 'contention':
        data = json.load(open(data_path))
        pid = data['contention_product']['id']
        sold = int(env.scalar(f"select coalesce(sum(i.quantity),0) from order_items i join orders o on o.id=i.order_id where i.product_id={pid} "
                              f"and o.status in ('pending','paid','delivered','partially_refunded','refunded')"))
        stock = int(env.scalar(f'select stock from products where id={pid}'))
        confirmed = env.sql("select o.public_id, count(*) from payments p join orders o on o.id=p.order_id where p.kind='charge' and p.status='confirmed' "
                            f"and o.public_id in ({','.join(repr(o['id']) for o in data['orders'][:5])}) group by 1")
        extra = {'units_sold': sold, 'stock_left': stock, 'charges_per_callback_order': {r[0]: int(r[1]) for r in confirmed}}
        checks['exactly the limited units sold, stock 0'] = sold == data['contention_units'] and stock == 0
        checks['120 parallel callbacks for 5 orders: one charge each'] = all(int(r[1]) == 1 for r in confirmed)

    result = {
        'profile': profile,
        'requests': int(m['http_reqs']['values']['count']),
        'requests_per_s': round(m['http_reqs']['values']['rate'], 1),
        'max_vus': int(m.get('vus_max', {}).get('values', {}).get('max', 0)),
        'duration_s': round(summary['state']['testRunDurationMs'] / 1000, 1),
        'latency_ms': {k: round(dur[k], 1) for k in ('med', 'p(90)', 'p(95)', 'p(99)', 'max') if k in dur},
        'error_rate': round(m['http_req_failed']['values']['rate'], 4),
        'per_endpoint_p95_ms': {k.split('name:')[1].rstrip('}'): round(v['values']['p(95)'], 1)
                                for k, v in m.items() if k.startswith('http_req_duration{name:') and 'p(95)' in v.get('values', {})},
        'orders_placed': int(m.get('orders_placed', {}).get('values', {}).get('count', 0)),
        'sold_out_answers': int(m.get('sold_out_answers', {}).get('values', {}).get('count', 0)),
        'callbacks_ok': int(m.get('callbacks_ok', {}).get('values', {}).get('count', 0)),
        'thresholds_crossed': [k for k, v in m.items() if any(not t.get('ok', True) for t in (v.get('thresholds') or {}).values())],
        'aborted_by_host_ceiling': aborted_by_ceiling,
        'resources': {'containers': peaks, 'db_connections_peak': db_peak, 'jobs_queued_peak': queue_peak, 'host_load1_peak': load_peak},
        'recovery': {'queue_drained_after_s': drained_after, 'catalogue_before': before, 'catalogue_after': after},
        'invariant_problems': problems, 'failed_jobs': failed_jobs,
        'checks': {k: 'PASS' if v else 'FAIL' for k, v in checks.items()}, **extra,
    }
    json.dump(result, open(os.path.join(d, 'result.json'), 'w'), indent=1)
    print(json.dumps({k: result[k] for k in ('profile', 'requests', 'requests_per_s', 'max_vus', 'latency_ms', 'error_rate', 'thresholds_crossed', 'checks')}, indent=1))
    return 0 if all(checks.values()) else 1


if __name__ == '__main__':
    if sys.argv[1] == '--before':
        json.dump(probe(), open(os.path.join(sys.argv[2], 'before.json'), 'w'))
        sys.exit(0)
    sys.exit(main(*sys.argv[1:4]))
