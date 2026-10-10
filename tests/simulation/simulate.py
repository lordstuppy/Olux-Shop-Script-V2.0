"""Runs the simulation phases against the stack started by run.sh.

  python3 -I tests/simulation/simulate.py [--runs N] [--phases 1,2] [--only text] [--no-reset]

Each run starts from a fresh, seeded database. After every scenario the
data invariants are checked, the HTTP trace is scanned for 5xx responses and
the app log for error entries (unless the scenario declared them expected).
Reports land in tests/simulation/reports/<timestamp>/.
"""
import argparse
import importlib
import json
import os
import re
import sys
import time

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)

from simlib import env, http, record, shop  # noqa: E402

PHASE_MODULES = ['p1_purchase', 'p2_payments', 'p3_accounts', 'p4_seller', 'p5_admin', 'p6_adversarial', 'p8_recovery']


class Ctx:
    """Shared state for one run; scenarios add what later ones need."""

    def __init__(self):
        self.data = {}
        self.allowed_logs = []
        self.allowed_status = set()

    def allow_log(self, pattern):
        """Log entries (including reported exceptions) whose message matches are expected in this scenario."""
        self.allowed_logs.append(re.compile(pattern))

    def allow_status(self, *statuses):
        """5xx statuses that are the correct answer in this scenario (e.g. 503 while the database is down)."""
        self.allowed_status.update(statuses)


def log_problems(before, ctx):
    problems = []
    for entry in env.app_logs()[before:]:
        level = entry.get('level', 0)
        context = entry.get('context') or {}
        message = str(entry.get('message', ''))
        exception = context.get('exception')
        if exception and not isinstance(exception, str):
            exception = exception.get('class') if isinstance(exception, dict) else str(exception)
        if exception and not any(p.search(message) for p in ctx.allowed_logs):
            problems.append(f'unhandled exception logged: {exception}: {message[:300]} ({context.get("request_id")})')
        elif exception:
            continue
        elif level >= 400 and not any(p.search(message) for p in ctx.allowed_logs):
            problems.append(f'{entry.get("level_name")} log: {message[:300]} ({context.get("request_id")})')
    return problems


def run(args, run_no, out_dir):
    run_dir = os.path.join(out_dir, f'run-{run_no}')
    os.makedirs(run_dir, exist_ok=True)
    http.TRACE_PATH = os.path.join(run_dir, 'trace.jsonl')
    http.STATS.update({'requests': 0, 'by_status': {}, 'server_errors': []})
    if not args.no_reset:
        env.reset()
    ctx = Ctx()
    records = []
    for spec in record.SCENARIOS:
        if args.phases and spec['phase'] not in args.phases:
            continue
        if args.only and args.only.lower() not in spec['name'].lower():
            continue
        ctx.allowed_logs = []
        ctx.allowed_status = set()
        errors_before = len(http.STATS['server_errors'])
        logs_before = len(env.app_logs())
        rec = record.run_one(spec, ctx)
        extra = []
        new_5xx = [e for e in http.STATS['server_errors'][errors_before:] if e['status'] not in ctx.allowed_status]
        if new_5xx:
            extra.append(f'5xx responses: {new_5xx}')
        try:
            extra += log_problems(logs_before, ctx)
            extra += shop.check_invariants()
        except Exception as e:  # e.g. a service a scenario failed to restart
            extra.append(f'post-scenario checks could not run: {type(e).__name__}: {str(e)[:300]}')
        if extra and rec.status == 'pass':
            rec.status = 'fail'
            rec.actual = 'Scenario steps passed, but: ' + ' | '.join(extra)[:3000]
        elif extra:
            rec.actual += ' | also: ' + ' | '.join(extra)[:2000]
        for e in extra:
            rec.ev(e)
        records.append(rec)
        print(f'[run {run_no}] {rec.status.upper():5} P{rec.phase} {rec.name} ({rec.duration}s)' + ('' if rec.status == 'pass' else f'\n          {rec.actual[:600]}'), flush=True)
    with open(os.path.join(run_dir, 'records.json'), 'w') as f:
        json.dump({'run': run_no, 'records': [r.as_dict() for r in records], 'http': http.STATS}, f, indent=1)
    write_markdown(os.path.join(run_dir, 'report.md'), run_no, records)
    return records


def write_markdown(path, run_no, records):
    lines = [f'# Simulation run {run_no}', '', f'{sum(r.status == "pass" for r in records)} of {len(records)} scenarios passed. '
             f'{http.STATS["requests"]} HTTP requests; by status: {dict(sorted(http.STATS["by_status"].items(), key=lambda x: str(x[0])))}.', '']
    for r in records:
        lines += [f'## P{r.phase} {r.name}: {r.status.upper()}', '', f'- **Precondition:** {r.precondition}', f'- **Expected:** {r.expected}',
                  f'- **Actual:** {r.actual}', f'- **Severity if failing:** {r.severity}', f'- **Duration:** {r.duration}s', '', '**Steps**', '']
        lines += [f'    {s}' for s in r.steps] + ['', '**Evidence**', ''] + [f'    {e}' for e in r.evidence] + ['']
    with open(path, 'w') as f:
        f.write('\n'.join(lines))


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--runs', type=int, default=1)
    ap.add_argument('--phases', type=lambda s: [int(x) for x in s.split(',')], default=None)
    ap.add_argument('--only', default=None)
    ap.add_argument('--no-reset', action='store_true')
    args = ap.parse_args()
    http.CA = os.path.join(HERE, 'tls', 'cert.pem')
    for name in PHASE_MODULES:
        if os.path.exists(os.path.join(HERE, 'phases', name + '.py')):
            importlib.import_module('phases.' + name)

    out_dir = os.path.join(HERE, 'reports', time.strftime('%Y%m%d-%H%M%S'))
    os.makedirs(out_dir, exist_ok=True)
    streak = {}
    history = {}
    for n in range(1, args.runs + 1):
        for r in run(args, n, out_dir):
            key = f'P{r.phase} {r.name}'
            history.setdefault(key, []).append(r.status)
            streak[key] = streak.get(key, 0) + 1 if r.status == 'pass' else 0
    summary = ['# Simulation summary', '', f'Backend: {env.BACKEND}. Runs: {args.runs}.', '', '| Scenario | Results | Consecutive passes |', '|---|---|---|']
    for key, results in history.items():
        summary.append(f'| {key} | {" ".join(x.upper() for x in results)} | {streak[key]} |')
    with open(os.path.join(out_dir, 'summary.md'), 'w') as f:
        f.write('\n'.join(summary) + '\n')
    failed = [k for k, v in history.items() if any(x != 'pass' for x in v)]
    print(f'\nReports: {out_dir}\n{len(history) - len(failed)} of {len(history)} scenarios passed in every run.')
    sys.exit(1 if failed else 0)


if __name__ == '__main__':
    main()
