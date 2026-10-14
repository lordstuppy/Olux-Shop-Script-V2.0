"""Final functional gate: scenario matrix and machine-readable gate results.

Builds docs/functional/scenario-matrix.md and docs/functional/functional-gates.json
from what actually ran: simulation records (tests/simulation/reports/*/run-N/
records.json), a PHPUnit JUnit file, a Playwright JSON report and the load
profile results. Scenarios that were identified but could not run are listed
here (GAPS) with their status and reason; nothing is counted as passed unless
an executed test passed.

  python3 -I tests/functional/matrix.py --revision <sha> --sim DIR [DIR ...] \\
      --phpunit junit.xml --browser browser.json --load DIR [--check]

--check exits 1 unless the release decision is PASS (used by CI). The script
derives statuses by fixed rules; it never approves an exception.
"""
import argparse
import glob
import json
import os
import re
import sys
import xml.etree.ElementTree as ET

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
DOCS = os.path.join(ROOT, 'docs', 'functional')

CATEGORIES = {
    'C01': 'Customer and seller workflows',
    'C02': 'Administrative workflows',
    'C03': 'Multi-user and multi-role workflows',
    'C04': 'Concurrent transactions',
    'C05': 'Invalid input and business-rule violations',
    'C06': 'Boundary conditions and data volume',
    'C07': 'Security and authorization failures',
    'C08': 'External integration failures',
    'C09': 'Network and infrastructure failures',
    'C10': 'Load and stress',
    'C11': 'Data consistency and race conditions',
    'C12': 'Recovery and retry',
    'C13': 'Mobile and desktop workflows',
    'C14': 'Long-running and background operations',
    'C15': 'Deployment and restart',
    'C16': 'Payments and financial integrity',
    'C17': 'State transitions',
    'C18': 'Real-time updates and multi-session consistency',
}

# Gate -> categories whose mandatory scenarios must all pass, plus prerequisites
# this environment cannot provide (each makes the gate BLOCKED, never PASS).
GATES = {
    'F1': ('Discover the application and define expected behaviour', [], []),
    'F2': ('Comprehensive real-world scenario matrix', [], []),
    'F3': ('End-to-end customer and administrator journeys', ['C01', 'C02'], []),
    'F4': ('Concurrent users and simultaneous operations', ['C04', 'C11'], []),
    'F5': ('Payments, orders and financial integrity', ['C16'],
           ['Reconciliation against the real Shkeeper (provider sandbox records): only the Shkeeper mock is available here']),
    'F6': ('State transitions and business logic', ['C17', 'C05'], []),
    'F7': ('Multi-user permissions and cross-tenant scenarios', ['C03', 'C07'], []),
    'F8': ('Real-time updates and multi-session consistency', ['C18'],
           ['Several application instances behind one load balancer: the stack runs one app container']),
    'F9': ('Failure injection and recovery', ['C08', 'C09', 'C12', 'C15'], []),
    'F10': ('Load, stress, spike and soak', ['C10'],
            ['An approved target workload and service-level objectives (the budgets are provisional, docs/performance)',
             'Production-like hardware with the load generator on a separate machine (4 shared cores here)']),
    'F11': ('Large data volumes and boundary conditions', ['C06'], []),
    'F12': ('Background jobs, queues and scheduled tasks', ['C14'], []),
    'F13': ('User experience during real-world activity', ['C13'],
            ['Screen readers, real devices and real users (docs/ux, gates U8 and U14)']),
    'F14': ('Browser, API and database cross-verification', ['C11', 'C16'], []),
    'F15': ('Regression testing after every fix', [], []),
    'F16': ('Final release candidate verification', [], []),
}

PHASE_CATEGORY = {1: 'C01', 2: 'C16', 3: 'C01', 4: 'C01', 5: 'C02', 6: 'C05', 8: 'C12', 9: 'C01', 10: 'C14', 11: 'C18', 12: 'C04'}
NAME_CATEGORY = [  # first match wins
    (r'^FS-', 'C17'), (r'^FF-', 'C08'), (r'^FR-0?1', 'C09'), (r'^FR-0?2', 'C15'), (r'^FC-', 'C04'),
    (r'Concurrent|Double-click|race|at once|same moment', 'C04'),
    (r'IDOR|role sees|Manager is denied|Forged|XSS|SQL injection|Path traversal|Lockout|Sessions:|2FA', 'C07'),
    (r'timeout|Kill the payment gateway|gateway outage|Shkeeper stops|Rejected callbacks|Staff alert for rejected', 'C08'),
    (r'Kill the database|Disk full|Mail server down|failed email|without the mail server', 'C09'),
    (r'Kill the queue worker|Restore a backup', 'C12'),
    (r'Upgrade', 'C15'),
    (r'Oversized|Extremely long|Unicode|Malformed|Currencies|Bulk actions', 'C06'),
    (r'payment|Payment|Underpayment|Overpayment|callback|refund|Refund|Payout|payout|Gift card|Balance adjust|Coupon|Commission|Insufficient|Reporting|hold', 'C16'),
    (r'Dispute deadline|Subscription|Digest|Download limits', 'C14'),
    (r'Two devices', 'C03'),
    (r'Notification|Live updates|Staff alerts in the notification|replies to a review', 'C18'),
    (r'Approve|Suspend|Order filters|Audit log|Email templates|Gateway config|CSV export|Categories|Announcements|Support tickets', 'C02'),
]
PHPUNIT_CATEGORY = {
    'AccountSecurityTest': 'C07', 'AdminFeaturesTest': 'C02', 'AdminOrderFiltersTest': 'C02', 'AnalyticsDashboardTest': 'C02',
    'BulkOperationsTest': 'C02', 'CatalogFiltersTest': 'C01', 'CommissionTest': 'C16', 'CustomerFeaturesTest': 'C01',
    'DisputeTest': 'C17', 'EmailTemplatesTest': 'C02', 'FilesAndSubscriptionsTest': 'C14', 'FunctionalGateTest': 'C11',
    'GatewayTest': 'C08', 'LoginLockTest': 'C07', 'MailRenderingTest': 'C01', 'MultiSellerOrderTest': 'C16',
    'NotificationCentreTest': 'C18', 'NotificationsTest': 'C14', 'OrderAccessTest': 'C07', 'OutageTest': 'C09',
    'PaymentAutomationTest': 'C16', 'PerformanceAuditTest': 'C06', 'ProductModerationNoteTest': 'C17', 'PurchaseFlowTest': 'C16',
    'ReadinessTest': 'C15', 'RefundAndPayoutTest': 'C16', 'ReorderTest': 'C17', 'ReviewFindingsTest': 'C05', 'SavedCartTest': 'C01',
    'SecurityAuditTest': 'C07', 'SecurityTest': 'C07', 'SellerPauseTest': 'C17', 'ShopFeaturesTest': 'C01', 'StaffRolesTest': 'C03',
    'StorageFailureTest': 'C09', 'StuckOrderRecoveryTest': 'C12', 'SystemHealthTest': 'C14', 'UxAuditTest': 'C13',
    'WebhookRetryTest': 'C12', 'WebhookTest': 'C16', 'CartServiceTest': 'C01', 'ClamAvScannerTest': 'C08', 'MoneyTest': 'C06',
    'OrderServiceTest': 'C17', 'ShkeeperClientTest': 'C08', 'TranslationCatalogTest': 'C05',
}
BROWSER_CATEGORY = {'nojs.spec.js': 'C13', 'live.spec.js': 'C18', 'ux.spec.js': 'C13', 'a11y.spec.js': 'C13'}
LOAD_PROFILES = {
    'baseline': 'Baseline: 5 browsers and staff paging, 2 minutes',
    'load': 'Target workload: 50 browsers (30 signed in), 20 multi-seller checkouts paid through the gateway, ~100 callbacks a minute, staff paging',
    'stress': 'Stress: browsers in steps of 25, 50, 100, 150, 200 until an abort threshold',
    'stress-high': 'Stress, continued: 200, 250, 300, 400 browsers until an abort threshold',
    'spike': 'Spike: 10 -> 150 browsers in 10 s for a minute, then back to 10',
    'soak': 'Soak: 30 browsers, a checkout every 20 s, callbacks and staff paging for 20 minutes',
    'contention': 'Contention: 40 buyers for 10 units at once, 120 parallel callbacks for 5 orders',
}

# Identified scenarios that cannot run here, or do not apply. Status and reason are stated, never PASS.
GAPS = [
    ('G-01', 'C16', 'Reconcile shop payments, refunds and payouts with the real Shkeeper sandbox records', 'BLOCKED',
     'No Shkeeper sandbox or credentials in this environment; every gateway scenario runs against the Shkeeper mock.', True),
    ('G-02', 'C16', 'Chargebacks and payment reversals by the provider', 'NOT APPLICABLE',
     'Crypto payments through Shkeeper cannot be charged back; refunds are explicit staff actions (covered).', False),
    ('G-03', 'C16', 'Recurring subscription renewals and cancellation of future charges', 'NOT APPLICABLE',
     'Subscriptions are prepaid periods; renewal is a new purchase (covered by "Subscription access over time").', False),
    ('G-04', 'C04', 'Two users reserving the same booking slot', 'NOT APPLICABLE', 'The shop has no bookings.', False),
    ('G-05', 'C07', 'Cross-tenant access to exports, notifications and files', 'NOT APPLICABLE',
     'Single-tenant shop; cross-user isolation is covered by the IDOR, export and notification scenarios.', False),
    ('G-06', 'C18', 'Consistency across several application instances behind a load balancer', 'NOT VERIFIED',
     'The stack runs one app container (PHP-FPM); state is in PostgreSQL only, so the design is instance-independent, but it was not executed.', True),
    ('G-07', 'C10', 'Target workload on production-like hardware against approved service-level objectives', 'BLOCKED',
     'Budgets are provisional (owner approval pending) and the sandbox shares 4 cores between k6, nginx, PHP and PostgreSQL.', True),
    ('G-08', 'C10', 'Soak over many hours (memory and connection leaks over a day)', 'NOT VERIFIED',
     'The background job limit allows a 20-minute soak; longer runs need a dedicated environment.', True),
    ('G-09', 'C09', 'Database failover and replica lag', 'NOT APPLICABLE', 'The deployment has one PostgreSQL server and no replicas.', False),
    ('G-10', 'C13', 'Journeys in Safari, Firefox and on real phones; with screen readers', 'BLOCKED',
     'Chromium with device emulation only (docs/ux U8, U14).', True),
    ('G-11', 'C16', 'A payment callback that arrives before the checkout transaction has committed', 'NOT VERIFIED',
     'Not reproducible from outside; by code review the callback is ignored and shop:reconcile-payments pays the order within about 7 minutes.', False),
]


def load_sim(dirs):
    """name -> list of statuses over all runs, plus record details."""
    out = {}
    for d in dirs:
        for f in sorted(glob.glob(os.path.join(d, 'run-*', 'records.json'))):
            for r in json.load(open(f))['records']:
                e = out.setdefault((r['phase'], r['name']), {'runs': [], 'rec': r, 'files': set()})
                e['runs'].append(r['status'])
                e['files'].add(os.path.relpath(os.path.dirname(f), ROOT))
                if r['status'] != 'pass':
                    e['rec'] = r
    return out


def load_phpunit(path):
    out = []
    if not path or not os.path.exists(path):
        return out
    for tc in ET.parse(path).getroot().iter('testcase'):
        cls = tc.get('class') or tc.get('classname') or ''
        status = 'pass'
        if tc.find('failure') is not None or tc.find('error') is not None:
            status = 'fail'
        elif tc.find('skipped') is not None:
            status = 'skipped'
        out.append((cls.split('\\')[-1], tc.get('name'), status))
    return out


def load_browser(path):
    out = []
    if not path or not os.path.exists(path):
        return out

    def walk(suite, file):
        file = suite.get('file', file)
        for spec in suite.get('specs', []):
            for t in spec.get('tests', []):
                res = [r.get('status') for r in t.get('results', [])]
                status = 'pass' if res and res[-1] == 'passed' and t.get('status') in ('expected', 'flaky') else (
                    'skipped' if t.get('status') == 'skipped' else 'fail')
                out.append((os.path.basename(spec.get('file', file)), spec['title'], t.get('projectName', ''), status, len(res)))
        for s in suite.get('suites', []):
            walk(s, file)
    for s in json.load(open(path)).get('suites', []):
        walk(s, s.get('file', ''))
    return out


def status_of(results):
    if not results:
        return 'NOT VERIFIED', False, False
    if all(r == 'pass' for r in results):
        return 'PASS', True, True
    if any(r in ('fail', 'error') for r in results):
        return 'FAIL', True, True
    return 'NOT VERIFIED', True, False  # skipped: attempted, not completed, never a pass


def sim_category(phase, name):
    for pattern, cat in NAME_CATEGORY:
        if re.search(pattern, name):
            return cat
    return PHASE_CATEGORY.get(phase, 'C01')


def build(args):
    rows = []
    sim = load_sim(args.sim or [])
    for (phase, name), e in sorted(sim.items(), key=lambda x: (x[0][0], x[0][1])):
        r = e['rec']
        na = name in ('Guest checkout', 'Account deletion', 'Seller rating') or r.get('expected', '').startswith('Not applicable')
        st, attempted, completed = status_of(e['runs'])
        if na and st == 'PASS':
            st = 'NOT APPLICABLE'
        rows.append({
            'id': f'S{phase:02d}-{len([x for x in rows if x["id"].startswith(f"S{phase:02d}-")]) + 1:02d}',
            'source': 'simulation', 'category': sim_category(phase, name), 'objective': f'P{phase} {name}',
            'actors': '', 'initial_state': r.get('precondition', ''), 'action_sequence': 'see evidence (steps with timings)',
            'concurrency': 'barrier-synchronised' if re.search(r'^F[CR]-|Concurrent|race|at once|Double', name) else '-',
            'expected': r.get('expected', ''), 'invariants': 'all data invariants (simlib/shop.py) after the scenario; no 5xx; no undeclared error log',
            'verification': 'HTTP responses, SQL on PostgreSQL, Mailpit, app log', 'cleanup': 'fresh migrate + seed before every run',
            'priority': {'blocker': 'P0', 'critical': 'P1', 'major': 'P2', 'minor': 'P3'}.get(r.get('severity'), 'P2'),
            'mandatory': not na, 'status': st, 'attempted': attempted, 'completed': completed,
            'runs': f'{e["runs"].count("pass")}/{len(e["runs"])} passed',
            'evidence': ', '.join(sorted(e['files'])) + ('' if r['status'] == 'pass' else ' -- ' + r.get('actual', '')[:300]),
        })
    for cls, name, status in load_phpunit(args.phpunit):
        st, attempted, completed = status_of([status])
        rows.append({'id': f'U-{cls}::{name}', 'source': 'phpunit', 'category': PHPUNIT_CATEGORY.get(cls, 'C05'),
                     'objective': name.replace('test_', '').replace('_', ' '), 'actors': '', 'initial_state': 'factories, RefreshDatabase (PostgreSQL)',
                     'action_sequence': f'tests/{"Unit" if cls in ("CartServiceTest", "ClamAvScannerTest", "MoneyTest", "OrderServiceTest", "ShkeeperClientTest", "TranslationCatalogTest") else "Feature"}/{cls}.php',
                     'concurrency': 'interleaving replayed step by step' if cls == 'FunctionalGateTest' else '-', 'expected': 'assertions in the test',
                     'invariants': 'per test', 'verification': 'HTTP responses and database state', 'cleanup': 'transaction rolled back',
                     'priority': 'P1' if cls in ('FunctionalGateTest', 'WebhookTest', 'PurchaseFlowTest', 'RefundAndPayoutTest', 'PaymentAutomationTest', 'SecurityAuditTest') else 'P2',
                     'mandatory': True, 'status': st, 'attempted': attempted, 'completed': completed, 'runs': status, 'evidence': args.phpunit})
    for file, title, project, status, tries in load_browser(args.browser):
        st, attempted, completed = status_of([status])
        rows.append({'id': f'B-{file}:{title[:60]} [{project}]', 'source': 'browser', 'category': BROWSER_CATEGORY.get(file, 'C13'),
                     'objective': title, 'actors': '', 'initial_state': 'fresh seed (tests/browser/run.sh)', 'action_sequence': f'tests/browser/{file}',
                     'concurrency': 'several tabs or users' if file in ('live.spec.js', 'ux.spec.js') else '-', 'expected': 'assertions in the test',
                     'invariants': 'per test', 'verification': 'Chromium DOM, server state through the UI', 'cleanup': 'fresh seed per suite run',
                     'priority': 'P2', 'mandatory': True, 'status': st, 'attempted': attempted, 'completed': completed,
                     'runs': f'{status} after {tries} attempt(s)', 'evidence': args.browser})
    for profile, objective in LOAD_PROFILES.items():
        res_file = os.path.join(args.load, profile, 'result.json') if args.load else ''
        if res_file and os.path.exists(res_file):
            res = json.load(open(res_file))
            st = 'PASS' if all(v == 'PASS' for v in res['checks'].values()) else 'FAIL'
            ev = f"{os.path.relpath(res_file, ROOT)}: {res['requests']} requests, {res['requests_per_s']}/s, p95 {res['latency_ms'].get('p(95)')} ms, errors {res['error_rate']:.2%}"
            if res.get('thresholds_crossed'):
                ev += f"; abort thresholds crossed: {', '.join(res['thresholds_crossed'])}"
            attempted = completed = True
        else:
            st, attempted, completed, ev = 'NOT VERIFIED', False, False, 'not run'
        rows.append({'id': f'L-{profile}', 'source': 'load', 'category': 'C10' if profile != 'contention' else 'C04', 'objective': objective,
                     'actors': 'k6 virtual users, each from its own address', 'initial_state': 'reset + seed, prepare.py',
                     'action_sequence': 'tests/functional/load/profiles.js', 'concurrency': objective.split(':')[1].strip(),
                     'expected': 'error rate < 5 %, invariants hold, no failed job, queue drained, latency back to normal after the load',
                     'invariants': 'all data invariants; contention: exactly 10 units sold, one charge per order',
                     'verification': 'k6 summary, resource samples, SQL', 'cleanup': 'reset before the profile set', 'priority': 'P1',
                     'mandatory': True, 'status': st, 'attempted': attempted, 'completed': completed, 'runs': '1', 'evidence': ev})
    if args.large and os.path.exists(args.large):
        large = json.load(open(args.large))
        for i, c in enumerate(large['checks'], 1):
            rows.append({'id': f'D-{i:02d}', 'source': 'large-data', 'category': 'C06', 'objective': c['check'],
                         'actors': '', 'initial_state': 'simulation seed + tests/performance/scale-data.sql: ' + ', '.join(f'{k} {v}' for k, v in large['dataset'].items()),
                         'action_sequence': 'tests/functional/large_data.py', 'concurrency': '-',
                         'expected': 'correct answer' + (f' within {c["ceiling_s"]} s (a guard, not an SLO)' if c.get('ceiling_s') else ''),
                         'invariants': '-', 'verification': 'HTTP status and content, SQL counts', 'cleanup': 'reset before the dataset',
                         'priority': 'P2', 'mandatory': True, 'status': c['status'], 'attempted': True, 'completed': True, 'runs': '1',
                         'evidence': f'{c["detail"]}' + (f'; {c["seconds"]} s' if c.get('seconds') is not None else '')})
    else:
        rows.append({'id': 'D-00', 'source': 'large-data', 'category': 'C06', 'objective': 'Year-scale data volumes and input limits',
                     'actors': '', 'initial_state': '', 'action_sequence': 'tests/functional/large_data.py', 'concurrency': '-', 'expected': '',
                     'invariants': '', 'verification': '', 'cleanup': '', 'priority': 'P2', 'mandatory': True, 'status': 'NOT VERIFIED',
                     'attempted': False, 'completed': False, 'runs': '-', 'evidence': 'not run'})
    for gid, cat, objective, st, reason, mandatory in GAPS:
        rows.append({'id': gid, 'source': 'gap', 'category': cat, 'objective': objective, 'actors': '', 'initial_state': '', 'action_sequence': '',
                     'concurrency': '', 'expected': '', 'invariants': '', 'verification': '', 'cleanup': '', 'priority': 'P1' if mandatory else 'P3',
                     'mandatory': mandatory and st != 'NOT APPLICABLE', 'status': st, 'attempted': False, 'completed': False, 'runs': '-', 'evidence': reason})
    return rows


def gates(rows, args):
    out = []
    by_cat = {}
    for r in rows:
        by_cat.setdefault(r['category'], []).append(r)
    defects_open = args.open_defects
    for gid, (title, cats, prereqs) in GATES.items():
        scoped = [r for c in cats for r in by_cat.get(c, []) if r['mandatory']]
        failing = [r['id'] for r in scoped if r['status'] == 'FAIL']
        blocked = [r['id'] for r in scoped if r['status'] == 'BLOCKED']
        unverified = [r['id'] for r in scoped if r['status'] == 'NOT VERIFIED']
        if gid == 'F1':
            status, why = ('PASS', 'docs/functional/functional-test-summary.md#inventory') if os.path.exists(os.path.join(DOCS, 'functional-test-summary.md')) else ('NOT VERIFIED', 'inventory missing')
        elif gid == 'F2':
            empty = [c for c in CATEGORIES if not any(r['attempted'] for r in by_cat.get(c, []))]
            status, why = ('PASS', f'{len(rows)} scenarios in {len(CATEGORIES)} categories') if not empty else ('NOT VERIFIED', f'categories without an executed scenario: {empty}')
        elif gid == 'F15':
            status, why = ('PASS', 'every fixed defect has a regression test that failed before the fix') if defects_open == 0 else ('FAIL', f'{defects_open} defects open')
        elif gid == 'F16':
            others = [g for g in out if g['status'] != 'PASS']
            status, why = ('PASS', 'all gates pass on the candidate') if not others else ('BLOCKED', 'gates not passing: ' + ', '.join(g['id'] for g in others))
        elif failing:
            status, why = 'FAIL', f'failing: {failing[:10]}'
        elif blocked or prereqs:
            status, why = 'BLOCKED', '; '.join(prereqs + ([f'blocked scenarios: {blocked}'] if blocked else []))
        elif unverified:
            status, why = 'NOT VERIFIED', f'not verified: {unverified[:10]}'
        elif not scoped:
            status, why = 'NOT VERIFIED', 'no executed scenario'
        else:
            status, why = 'PASS', f'{len(scoped)} mandatory scenarios pass'
        out.append({'id': gid, 'title': title, 'status': status, 'categories': cats, 'mandatory_scenarios': len(scoped),
                    'failing': failing, 'blocked': blocked, 'not_verified': unverified, 'reason': why})
    return out


def counts(rows):
    c = {k: 0 for k in ('PASS', 'FAIL', 'BLOCKED', 'NOT VERIFIED', 'NOT APPLICABLE')}
    for r in rows:
        c[r['status']] += 1
    return c


def write(rows, gate_rows, args):
    c = counts(rows)
    g = counts(gate_rows)
    decision = ('PASS - APPROVED TO SHIP' if all(x['status'] == 'PASS' for x in gate_rows) else
                'FAIL - DO NOT SHIP' if any(x['status'] == 'FAIL' for x in gate_rows) else
                'BLOCKED - DO NOT SHIP' if any(x['status'] == 'BLOCKED' for x in gate_rows) else 'NOT VERIFIED - DO NOT SHIP')
    executed = [r for r in rows if r['attempted']]
    report = {
        'audit': 'Final functional gate: functional testing, real-world simulation, concurrency, stress and failure recovery',
        'generated_by': 'tests/functional/matrix.py', 'tested_revision': args.revision, 'artifact': args.artifact,
        'statuses': list(c.keys()), 'decision': decision,
        'gates_summary': {'total': len(gate_rows), **g}, 'gates': gate_rows,
        'scenarios_summary': {'identified': len(rows), 'executed': len(executed), 'passed': c['PASS'], 'failed': c['FAIL'],
                              'blocked': c['BLOCKED'], 'not_verified': c['NOT VERIFIED'], 'not_applicable': c['NOT APPLICABLE'],
                              'skipped_not_counted_as_pass': len([r for r in rows if r['attempted'] and not r['completed']]),
                              'by_source': {s: len([r for r in rows if r['source'] == s]) for s in ('simulation', 'phpunit', 'browser', 'load', 'large-data', 'gap')}},
        'scenarios': rows,
    }
    os.makedirs(DOCS, exist_ok=True)
    json.dump(report, open(os.path.join(DOCS, 'functional-gates.json'), 'w'), indent=1)

    md = ['# Scenario Matrix', '',
          f'Generated by `tests/functional/matrix.py` from the results of revision `{args.revision}`. Do not edit by hand.', '',
          f'**{len(rows)} scenarios identified; {len(executed)} executed.** PASS {c["PASS"]}, FAIL {c["FAIL"]}, BLOCKED {c["BLOCKED"]}, '
          f'NOT VERIFIED {c["NOT VERIFIED"]}, NOT APPLICABLE {c["NOT APPLICABLE"]}. A skipped test is never counted as passed.', '',
          'Every scenario carries the fields of the gate specification. For simulation scenarios the action sequence with timings, '
          'the evidence (request ids, SQL results, mails, log lines) and the HTTP trace are in the run directory named under Evidence. '
          'Machine-readable: [functional-gates.json](functional-gates.json).', '',
          '## By category', '', '| Category | Scenarios | PASS | FAIL | BLOCKED | NOT VERIFIED | N/A |', '|---|---|---|---|---|---|---|']
    for cid, title in CATEGORIES.items():
        rs = [r for r in rows if r['category'] == cid]
        cc = counts(rs)
        md.append(f'| {cid} {title} | {len(rs)} | {cc["PASS"]} | {cc["FAIL"]} | {cc["BLOCKED"]} | {cc["NOT VERIFIED"]} | {cc["NOT APPLICABLE"]} |')
    md += ['', '## Simulation, load, large data and identified gaps', '',
           'One row per scenario. Initial state, expected outcome and priority come from the scenario definition; '
           'invariants: all data invariants of `tests/simulation/simlib/shop.py` hold after each scenario, no 5xx, no undeclared error log; '
           'cleanup: every run starts from `migrate:fresh` and the simulation seed.', '',
           '| ID | Category | Scenario | Initial state | Concurrency | Expected | Priority | Status | Runs | Evidence |', '|---|---|---|---|---|---|---|---|---|---|']
    esc = lambda t: str(t).replace('|', '/').replace('\n', ' ')  # noqa: E731
    for r in rows:
        if r['source'] in ('simulation', 'load', 'large-data', 'gap'):
            md.append(f'| {r["id"]} | {r["category"]} | {esc(r["objective"])} | {esc(r["initial_state"])[:220]} | {esc(r["concurrency"])} | '
                      f'{esc(r["expected"])[:300]} | {r["priority"]} | **{r["status"]}** | {r["runs"]} | {esc(r["evidence"])[:300]} |')
    md += ['', '## PHPUnit and browser tests', '',
           'Each test is a scenario with its own initial state (factories, or a fresh seed for the browser) and assertions on the response '
           'and the stored data. Listed per test class or spec file.', '', '| Source | Class or spec | Category | Tests | PASS | FAIL | NOT VERIFIED |', '|---|---|---|---|---|---|---|']
    groups = {}
    for r in rows:
        if r['source'] in ('phpunit', 'browser'):
            key = (r['source'], r['action_sequence'], r['category'])
            groups.setdefault(key, []).append(r)
    for (src, where, cat), rs in sorted(groups.items()):
        cc = counts(rs)
        md.append(f'| {src} | `{where}` | {cat} | {len(rs)} | {cc["PASS"]} | {cc["FAIL"]} | {cc["NOT VERIFIED"]} |')
    failing = [r for r in rows if r['status'] in ('FAIL', 'NOT VERIFIED') and r['source'] in ('phpunit', 'browser')]
    if failing:
        md += ['', 'Not passing:', ''] + [f'- {r["id"]}: {r["status"]}' for r in failing]
    open(os.path.join(DOCS, 'scenario-matrix.md'), 'w').write('\n'.join(md) + '\n')
    print(json.dumps({'decision': decision, 'gates': g, 'scenarios': report['scenarios_summary']}, indent=1))
    return decision


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--revision', required=True)
    ap.add_argument('--artifact', default='')
    ap.add_argument('--sim', nargs='*')
    ap.add_argument('--phpunit')
    ap.add_argument('--browser')
    ap.add_argument('--load')
    ap.add_argument('--large')
    ap.add_argument('--open-defects', type=int, default=0)
    ap.add_argument('--check', action='store_true')
    args = ap.parse_args()
    rows = build(args)
    decision = write(rows, gates(rows, args), args)
    if args.check and not decision.startswith('PASS'):
        sys.exit(1)


if __name__ == '__main__':
    main()
