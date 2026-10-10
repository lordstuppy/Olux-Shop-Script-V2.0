"""Phase 7 evaluation after k6: latency budgets, data correctness after the
load (every order paid exactly once, invariants, no failed jobs, no logged
exceptions) and PostgreSQL statements slower than 200 ms.

  python3 -I analyze.py <k6 summary json> <data json> <report md> <db log since (ISO time)>"""
import json
import os
import re
import sys
import time

HERE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, HERE)

from simlib import env, shop  # noqa: E402


def metric(summary, name):
    return summary['metrics'].get(name, {}).get('values', {})


def main(summary_path, data_path, report_path, since):
    summary = json.load(open(summary_path))
    data = json.load(open(data_path))
    findings = []
    lines = ['# Phase 7 load test', '', f'Backend: {env.BACKEND}. Started {since} UTC.', '']

    # Let the queue finish delivery of everything that was paid.
    shop.wait_for(lambda: env.scalar("select count(*) from jobs where queue='default' and payload not like '%QueueHeartbeat%'") == '0',
                  'queue drained', 300, 2)

    lines += ['## Latency', '', '| Request | p50 ms | p95 ms | max ms |', '|---|---|---|---|']
    for key, m in sorted(summary['metrics'].items()):
        if key.startswith('http_req_duration{name:'):
            v = m['values']
            lines.append(f"| {key[len('http_req_duration{name:'):-1]} | {v.get('med', 0):.0f} | {v.get('p(95)', 0):.0f} | {v.get('max', 0):.0f} |")
    overall = metric(summary, 'http_req_duration')
    lines += ['', f"All requests: {int(metric(summary, 'http_reqs').get('count', 0))}, p95 {overall.get('p(95)', 0):.0f} ms, "
              f"failed rate {metric(summary, 'http_req_failed').get('rate', 0):.4f}.", '']

    lines += ['## Thresholds', '']
    for key, m in summary['metrics'].items():
        for expr, res in (m.get('thresholds') or {}).items():
            ok = res.get('ok', not res) if isinstance(res, dict) else res
            lines.append(f"- `{key}` {expr}: {'pass' if ok else 'FAIL'}")
            if not ok:
                findings.append(f'threshold {key} {expr} failed')
    admin = metric(summary, 'admin_product_list')
    lines.append(f"- admin product list p95 {admin.get('p(95)', 0):.0f} ms (budget 500 ms)")

    lines += ['', '## Data after the load', '']
    ids = "','".join(o['id'] for o in data['orders'])
    paid = env.scalar(f"select count(*) from orders where public_id in ('{ids}') and status in ('paid','delivered')")
    charges = env.sql(f"""select o.public_id, count(p.id) from orders o left join payments p on p.order_id=o.id and p.kind='charge' and p.status='confirmed'
        where o.public_id in ('{ids}') group by o.public_id having count(p.id) <> 1""")
    lines.append(f'- Webhook orders paid: {paid} of {len(data["orders"])}; orders without exactly one confirmed charge: {len(charges)}')
    if paid != str(len(data['orders'])) or charges:
        findings.append(f'webhook orders: {paid} paid, charge anomalies {charges[:5]}')
    buyers = "','".join(data['checkout_buyers'])
    mixed = env.sql(f"""select o.public_id, o.status, count(distinct i.seller_id) from orders o join users u on u.id=o.buyer_id join order_items i on i.order_id=o.id
        where u.email in ('{buyers}') and o.created_at >= '{since}' and o.public_id not in ('{ids}') group by o.public_id, o.status""")
    lines.append(f'- Mixed-vendor checkouts: {len(mixed)} orders, statuses {sorted({m[1] for m in mixed})}, sellers per order {sorted({m[2] for m in mixed})}')
    if len(mixed) != 20 or any(m[1] != 'delivered' or m[2] != '3' for m in mixed):
        findings.append(f'mixed-vendor orders not all delivered with 3 sellers: {mixed}')
    problems = shop.check_invariants()
    lines.append(f'- Invariants: {"all hold" if not problems else problems}')
    findings += problems
    failed = env.scalar('select count(*) from failed_jobs')
    lines.append(f'- Failed jobs: {failed}')
    if failed != '0':
        findings.append(f'{failed} failed jobs')
    errors = [e for e in env.app_logs() if e.get('level', 0) >= 400 and e.get('datetime', '') >= since.replace(' ', 'T')]
    lines.append(f'- Log entries at error level or above during the run: {len(errors)}')
    for e in errors[:10]:
        lines.append(f"    - {e.get('level_name')}: {str(e.get('message'))[:200]}")
    if errors:
        findings.append(f'{len(errors)} error log entries')

    lines += ['', '## Statements over 200 ms (PostgreSQL log)', '']
    slow = []
    for line in env.db_log().splitlines():
        m = re.match(r'(\S+ \S+) \S+ \[\d+\] \S+ LOG:\s+duration: ([\d.]+) ms\s+(?:statement|execute [^:]*): (.*)', line)
        if m and m.group(1) >= since and float(m.group(2)) >= 200:
            slow.append((float(m.group(2)), m.group(3)[:300]))
    if not slow:
        lines.append('None.')
    for ms, sql in sorted(slow, reverse=True)[:30]:
        lines.append(f'- {ms:.0f} ms: `{sql}`')
    if slow:
        findings.append(f'{len(slow)} statements over 200 ms')

    lines += ['', '## Result', '', 'PASS' if not findings else 'FAIL:\n' + '\n'.join(f'- {f}' for f in findings)]
    with open(report_path, 'w') as f:
        f.write('\n'.join(lines) + '\n')
    print('\n'.join(lines))
    sys.exit(0 if not findings else 1)


if __name__ == '__main__':
    main(*sys.argv[1:5])
