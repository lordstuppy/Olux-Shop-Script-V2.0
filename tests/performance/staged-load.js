// Staged read load (k6) against the simulation stack: a fixed request mix at
// rising arrival rates, 40 s per stage, to find where latency departs from
// the budget. Aborts on its own when the error rate passes 5% or the p95
// passes 5 s (k6 thresholds with abortOnFail), so it never runs a broken
// stack into the ground.
//
//   k6 run --local-ips=127.0.1.1-127.0.1.250 -e DATA=load-data.json -e SUMMARY=out.json tests/performance/staged-load.js
//
// DATA (tests/performance/load-prepare.py): product slugs, search words and
// the cookies of signed-in buyers whose pages poll /live.
// Mix per request: 35% catalogue pages (incl. sorts and categories), 25%
// product pages, 15% searches, 10% home, 15% live-update polls of signed-in buyers.
import http from 'k6/http';
import { check } from 'k6';
import { SharedArray } from 'k6/data';

const data = JSON.parse(open(__ENV.DATA));
const slugs = new SharedArray('slugs', () => data.slugs);
const words = ['font', 'icon', 'template', 'course', 'retro', 'python', 'design', 'pack', 'theme', 'sticker', 'planner', 'audio'];
const BASE = __ENV.BASE_URL || 'https://localhost:8443';
const RATES = (__ENV.RATES || '10,20,40,60,80').split(',').map(Number);
const STAGE = __ENV.STAGE || '40s';
const stageSeconds = parseInt(STAGE, 10);

const scenarios = {};
const thresholds = {
    http_req_failed: [{ threshold: 'rate<0.05', abortOnFail: true, delayAbortEval: '20s' }],
    http_req_duration: [{ threshold: 'p(95)<5000', abortOnFail: true, delayAbortEval: '20s' }],
};
RATES.forEach((rate, i) => {
    const name = `rate_${rate}`;
    scenarios[name] = {
        executor: 'constant-arrival-rate', exec: 'mix', rate, timeUnit: '1s', duration: STAGE,
        preAllocatedVUs: Math.max(20, rate * 2), maxVUs: rate * 6, startTime: `${i * stageSeconds}s`, gracefulStop: '10s',
    };
    // Per-stage figures in the summary.
    thresholds[`http_req_duration{scenario:${name}}`] = ['p(95)<60000'];
    thresholds[`http_req_failed{scenario:${name}}`] = ['rate<1'];
});
for (const n of ['catalog', 'product', 'search', 'home', 'live']) {
    thresholds[`http_req_duration{name:${n}}`] = ['p(95)<60000'];
}

export const options = { scenarios, thresholds, insecureSkipTLSVerify: true, summaryTrendStats: ['avg', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'] };

const pick = (a) => a[Math.floor(Math.random() * a.length)];

export function mix() {
    const r = Math.random();
    let res;
    if (r < 0.35) {
        const variants = ['/products', `/products?page=${1 + Math.floor(Math.random() * 50)}`, '/products?sort=best', '/products?sort=price_asc', `/products?category=${pick(data.categories)}`];
        res = http.get(BASE + pick(variants), { tags: { name: 'catalog' } });
    } else if (r < 0.60) {
        res = http.get(`${BASE}/products/${pick(slugs)}`, { tags: { name: 'product' } });
    } else if (r < 0.75) {
        res = http.get(`${BASE}/products?q=${pick(words)}`, { tags: { name: 'search' } });
    } else if (r < 0.85) {
        res = http.get(`${BASE}/`, { tags: { name: 'home' } });
    } else {
        const buyer = pick(data.buyers);
        res = http.get(`${BASE}/live?path=/orders`, { tags: { name: 'live' }, cookies: buyer, redirects: 0 });
    }
    check(res, { 'status 200': (x) => x.status === 200 });
}

export function handleSummary(summary) {
    return { [__ENV.SUMMARY || 'staged-load-summary.json']: JSON.stringify(summary, null, 1) };
}
