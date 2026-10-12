"""Checks measurements against tests/performance/budgets.json.

  python3 -I tests/performance/check-budgets.py assets
  python3 -I tests/performance/check-budgets.py bench BENCH.json
  python3 -I tests/performance/check-budgets.py webvitals WEBVITALS.json
  python3 -I tests/performance/check-budgets.py image APP_IMAGE WEB_IMAGE

Prints one line per item and exits 1 when any budget is exceeded or a page
did not answer 200 (a failed request is never within budget).
"""
import gzip
import json
import os
import subprocess
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(os.path.dirname(HERE))
BUDGETS = json.load(open(os.path.join(HERE, 'budgets.json'), encoding='utf-8'))


def budget_for(name):
    for group in BUDGETS['groups']:
        if group['match'] in name:
            return {**BUDGETS['defaults'], **group}
    return BUDGETS['defaults']


def check_bench(path):
    failed = 0
    for page in json.load(open(path, encoding='utf-8'))['pages']:
        b = budget_for(page['name'])
        problems = []
        if page['status'] != 200:
            problems.append(f"status {page['status']}")
        if page['p95_ms'] > b['p95_ms']:
            problems.append(f"p95 {page['p95_ms']} ms > {b['p95_ms']}")
        if page['queries'] is None or page['queries'] > b['max_queries']:
            problems.append(f"queries {page['queries']} > {b['max_queries']}")
        failed += bool(problems)
        print(f"{'FAIL' if problems else 'PASS'} {page['name']}: p95 {page['p95_ms']} ms (budget {b['p95_ms']}), "
              f"{page['queries']} queries (budget {b['max_queries']}){' - ' + '; '.join(problems) if problems else ''}")
    return failed


def check_assets():
    failed = 0
    for rel, b in BUDGETS['assets'].items():
        raw = open(os.path.join(ROOT, rel), 'rb').read()
        gz = len(gzip.compress(raw, 6))
        bad = len(raw) > b['raw_bytes'] or gz > b['gzip_bytes']
        failed += bad
        print(f"{'FAIL' if bad else 'PASS'} {rel}: {len(raw)} bytes (budget {b['raw_bytes']}), gzip {gz} (budget {b['gzip_bytes']})")
    return failed


def check_webvitals(path):
    b = BUDGETS['web_vitals_lab']
    failed = 0
    for r in json.load(open(path, encoding='utf-8'))['results']:
        over = [k for k in ('lcp_ms', 'cls', 'tbt_ms', 'inp_ms_lab') if r[k] > b[k]]
        failed += bool(over)
        print(f"{'FAIL' if over else 'PASS'} {r['name']}: LCP {r['lcp_ms']} ms, CLS {r['cls']}, TBT {r['tbt_ms']} ms, INP(lab) {r['inp_ms_lab']} ms"
              f"{' - over budget: ' + ', '.join(over) if over else ''}")
    return failed


def check_image(app, web):
    failed = 0
    for image, limit in ((app, BUDGETS['image']['app_max_mb']), (web, BUDGETS['image']['web_max_mb'])):
        size = int(subprocess.run(['docker', 'image', 'inspect', '--format', '{{.Size}}', image], capture_output=True, text=True, check=True).stdout.strip())
        mb = round(size / 1e6, 1)
        bad = mb > limit
        failed += bad
        print(f"{'FAIL' if bad else 'PASS'} image {image}: {mb} MB (budget {limit} MB)")
    return failed


def main():
    kind = sys.argv[1]
    failed = {'bench': lambda: check_bench(sys.argv[2]), 'assets': check_assets,
              'webvitals': lambda: check_webvitals(sys.argv[2]), 'image': lambda: check_image(sys.argv[2], sys.argv[3])}[kind]()
    print(f"budget status: {BUDGETS['status']}; {failed} over budget")
    sys.exit(1 if failed else 0)


if __name__ == '__main__':
    main()
