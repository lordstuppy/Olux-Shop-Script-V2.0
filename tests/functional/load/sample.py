"""Samples resources every 5 s while a load profile runs, and aborts the load
generator when the host itself runs short (a resource ceiling, so a stress
test cannot take the machine down).

  python3 -I tests/functional/load/sample.py <out.jsonl> <stop-file>

Per sample: CPU and memory of each stack container (docker stats), database
connections and their states, queued and failed jobs, host load and free
memory. Stops when <stop-file> exists.
"""
import json
import os
import subprocess
import sys
import time

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))))
sys.path.insert(0, os.path.join(ROOT, 'tests', 'simulation'))

from simlib import env  # noqa: E402

MIN_FREE_MB = 1024  # abort below this much available host memory
MIN_DISK_MB = 400   # or below this much free disk


def meminfo():
    vals = {}
    for line in open('/proc/meminfo'):
        k, v = line.split(':', 1)
        vals[k] = int(v.split()[0]) // 1024
    return vals


def docker_stats():
    out = subprocess.run(['docker', 'stats', '--no-stream', '--format', '{{json .}}'], capture_output=True, text=True, timeout=30).stdout
    stats = {}
    for line in out.splitlines():
        s = json.loads(line)
        if s['Name'].startswith('shopsim'):
            stats[s['Name']] = {'cpu': float(s['CPUPerc'].rstrip('%') or 0), 'mem': s['MemUsage'].split(' / ')[0]}
    return stats


def main(out, stop):
    with open(out, 'a') as f:
        while not os.path.exists(stop):
            t = time.time()
            m = meminfo()
            st = os.statvfs('/')
            sample = {'t': round(t, 1), 'load1': os.getloadavg()[0], 'host_avail_mb': m['MemAvailable'],
                      'disk_free_mb': st.f_bavail * st.f_frsize // 1048576}
            try:
                sample['containers'] = docker_stats()
                rows = env.sql("select coalesce(state, '-'), count(*) from pg_stat_activity where datname=current_database() group by 1")
                sample['db_connections'] = {r[0]: int(r[1]) for r in rows}
                sample['jobs_queued'] = int(env.scalar('select count(*) from jobs'))
                sample['jobs_failed'] = int(env.scalar('select count(*) from failed_jobs'))
            except Exception as e:  # noqa: BLE001 - a sample can fail while the stack is busy; recorded
                sample['error'] = repr(e)[:200]
            if sample['host_avail_mb'] < MIN_FREE_MB or sample['disk_free_mb'] < MIN_DISK_MB:
                sample['abort'] = 'host resource ceiling reached; stopping k6'
                subprocess.run(['pkill', '-INT', '-x', 'k6'])
            f.write(json.dumps(sample) + '\n')
            f.flush()
            time.sleep(max(0.0, 5 - (time.time() - t)))


if __name__ == '__main__':
    main(sys.argv[1], sys.argv[2])
