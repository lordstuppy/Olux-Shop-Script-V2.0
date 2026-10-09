"""Scenario records: every scenario produces a structured record with the
precondition, the exact steps (URLs, fields, timing), expected and actual
results, severity if it fails, and evidence (request ids, SQL results, log
lines, mail subjects)."""
import time
import traceback

SCENARIOS = []  # registered in phase modules, in order


class Record:
    def __init__(self, phase, name, precondition, expected, severity):
        self.phase = phase
        self.name = name
        self.precondition = precondition
        self.expected = expected
        self.severity = severity
        self.steps = []
        self.evidence = []
        self.status = 'not run'
        self.actual = ''
        self.started = None
        self.duration = 0.0

    def step(self, text):
        self.steps.append(f'{time.time() - self.started:7.2f}s  {text}')

    def ev(self, text):
        self.evidence.append(str(text)[:2000])

    def check(self, cond, message):
        if not cond:
            raise AssertionError(message)

    def as_dict(self):
        return {k: getattr(self, k) for k in ('phase', 'name', 'precondition', 'expected', 'severity', 'steps', 'evidence', 'status', 'actual', 'duration')}


def scenario(phase, name, precondition, expected, severity='major'):
    """Registers a scenario function f(rec, ctx)."""
    def deco(fn):
        SCENARIOS.append({'phase': phase, 'name': name, 'precondition': precondition, 'expected': expected, 'severity': severity, 'fn': fn})
        return fn
    return deco


def run_one(spec, ctx):
    rec = Record(spec['phase'], spec['name'], spec['precondition'], spec['expected'], spec['severity'])
    rec.started = time.time()
    try:
        spec['fn'](rec, ctx)
        rec.status = 'pass'
        rec.actual = rec.actual or 'As expected.'
    except AssertionError as e:
        rec.status = 'fail'
        rec.actual = str(e)[:3000]
    except Exception as e:  # harness or environment error: recorded, never hidden
        rec.status = 'error'
        rec.actual = f'{type(e).__name__}: {e}'[:3000]
        rec.ev(traceback.format_exc()[-3000:])
    rec.duration = round(time.time() - rec.started, 1)
    return rec
