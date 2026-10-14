// Final functional gate (docs/functional/load-stress-test-report.md): load
// profiles against the disposable simulation stack, never a real shop.
//   PROFILE=baseline    5 browsers, 2 minutes: normal latency
//   PROFILE=load        the target workload: 50 browsers (30 signed in), checkouts paid
//                       through the gateway, ~100 payment callbacks a minute, staff paging
//   PROFILE=stress      browsers in steps of 25, 50, 100, 150, 200 until an abort threshold
//   PROFILE=stress-high continues with 200, 250, 300, 400 browsers
//   PROFILE=spike       10 browsers, then 150 within 10 s for a minute, then 10 again
//   PROFILE=soak        30 browsers, a checkout every 20 s and callbacks for 20 minutes
//   PROFILE=contention  40 buyers at once for 10 units of one product, while the same
//                       five payment callbacks arrive 120 times in parallel
// Every profile aborts on its own when more than 5 % of requests fail or p95
// latency stays above 10 s (thresholds with abortOnFail). Input: DATA from
// prepare.py. Output: SUMMARY (JSON).
import http from 'k6/http';
import crypto from 'k6/crypto';
import { check, sleep } from 'k6';
import exec from 'k6/execution';
import { Counter, Trend } from 'k6/metrics';

const data = JSON.parse(open(__ENV.DATA));
const BASE = __ENV.BASE || 'https://localhost:8443';
const PROFILE = __ENV.PROFILE || 'baseline';

const ordersPlaced = new Counter('orders_placed');
const soldOut = new Counter('sold_out_answers');
const callbacksOk = new Counter('callbacks_ok');
const adminList = new Trend('admin_product_list', true);

const abort = {
    http_req_failed: [{ threshold: 'rate<0.05', abortOnFail: true, delayAbortEval: '30s' }],
    http_req_duration: [{ threshold: 'p(95)<10000', abortOnFail: true, delayAbortEval: '30s' }],
};

const PROFILES = {
    baseline: {
        browse: { executor: 'constant-vus', exec: 'browse', vus: 5, duration: '2m' },
        admin: { executor: 'constant-vus', exec: 'admin', vus: 1, duration: '2m' },
    },
    load: {
        browse: { executor: 'ramping-vus', exec: 'browse', startVUs: 5, stages: [{ duration: '30s', target: 50 }, { duration: '3m', target: 50 }, { duration: '15s', target: 0 }] },
        checkout: { executor: 'per-vu-iterations', exec: 'checkout', vus: 20, iterations: 1, maxDuration: '3m', startTime: '30s' },
        callbacks: { executor: 'constant-arrival-rate', exec: 'callback', rate: 100, timeUnit: '1m', duration: '72s', preAllocatedVUs: 5, maxVUs: 20, startTime: '40s' },
        admin: { executor: 'constant-vus', exec: 'admin', vus: 1, duration: '3m45s' },
    },
    stress: {
        browse: { executor: 'ramping-vus', exec: 'browse', startVUs: 0, gracefulRampDown: '10s',
            stages: [25, 50, 100, 150, 200].flatMap((n) => [{ duration: '20s', target: n }, { duration: '60s', target: n }]).concat([{ duration: '20s', target: 0 }]) },
    },
    // Continues stress beyond 200 browsers when the first run found no limit.
    'stress-high': {
        browse: { executor: 'ramping-vus', exec: 'browse', startVUs: 0, gracefulRampDown: '10s',
            stages: [200, 250, 300, 400].flatMap((n) => [{ duration: '20s', target: n }, { duration: '60s', target: n }]).concat([{ duration: '20s', target: 0 }]) },
    },
    spike: {
        browse: { executor: 'ramping-vus', exec: 'browse', startVUs: 10, gracefulRampDown: '10s',
            stages: [{ duration: '1m', target: 10 }, { duration: '10s', target: 150 }, { duration: '1m', target: 150 }, { duration: '10s', target: 10 }, { duration: '2m', target: 10 }] },
    },
    soak: {
        browse: { executor: 'constant-vus', exec: 'browse', vus: 30, duration: '20m' },
        checkout: { executor: 'constant-arrival-rate', exec: 'checkout', rate: 3, timeUnit: '1m', duration: '19m', preAllocatedVUs: 2, maxVUs: 4, startTime: '30s' },
        callbacks: { executor: 'constant-arrival-rate', exec: 'callback', rate: 6, timeUnit: '1m', duration: '19m', preAllocatedVUs: 1, maxVUs: 3, startTime: '30s' },
        admin: { executor: 'constant-vus', exec: 'admin', vus: 1, duration: '20m' },
    },
    contention: {
        buyers: { executor: 'per-vu-iterations', exec: 'lastUnits', vus: 40, iterations: 1, maxDuration: '2m' },
        callbacks: { executor: 'shared-iterations', exec: 'sameCallbacks', vus: 30, iterations: 120, maxDuration: '2m', startTime: '5s' },
    },
};

export const options = {
    insecureSkipTLSVerify: true, // self-signed certificate of the test stack
    scenarios: PROFILES[PROFILE],
    // The per-page rows only make k6 report each page's percentiles; the abort thresholds decide.
    thresholds: { ...abort, admin_product_list: ['p(95)<60000'],
        ...Object.fromEntries(['home', 'catalog', 'search', 'product', 'add to cart', 'cart', 'login', 'checkout page', 'place order', 'webhook', 'admin products']
            .map((n) => [`http_req_duration{name:${n}}`, ['p(95)<60000']])) },
    summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

function token(res) {
    return res.html().find('input[name=_token]').first().attr('value') || '';
}

function login(email) {
    const page = http.get(`${BASE}/login`, { tags: { name: 'login page' } });
    const res = http.post(`${BASE}/login`, { _token: token(page), email, password: data.password }, { tags: { name: 'login' } });
    return res.status === 200 && !res.url.includes('/login');
}

function pick(list) {
    return list[Math.floor(Math.random() * list.length)];
}

export function browse() {
    // The first 30 browsers sign in once (one buyer each, so no per-account limit applies).
    if (exec.vu.iterationInScenario === 0 && exec.vu.idInTest <= 30) {
        login(data.browse_buyers[exec.vu.idInTest % data.browse_buyers.length]);
    }
    http.get(`${BASE}/`, { tags: { name: 'home' } });
    const list = http.get(`${BASE}/products?category=${pick(data.categories)}&sort=${pick(['newest', 'price_asc', 'price_desc'])}`, { tags: { name: 'catalog' } });
    check(list, { 'catalog 200': (r) => r.status === 200 });
    if (Math.random() < 0.3) {
        http.get(`${BASE}/products?q=${pick(['pro', 'key', 'font', 'icon', 'guide', 'pack'])}`, { tags: { name: 'search' } });
    }
    const page = http.get(`${BASE}/products/${pick(data.slugs)}`, { tags: { name: 'product' } });
    check(page, { 'product 200': (r) => r.status === 200 });
    if (Math.random() < 0.3 && page.status === 200) {
        const id = page.html().find('input[name=product_id]').first().attr('value');
        if (id) {
            http.post(`${BASE}/cart/items`, { _token: token(page), product_id: id, quantity: '1' }, { tags: { name: 'add to cart' } });
            http.get(`${BASE}/cart`, { tags: { name: 'cart' } });
        }
    }
    sleep(1 + Math.random() * 2);
}

function placeOrder(email, products, method) {
    if (!login(email)) {
        console.error(`login failed for ${email}`);
        return null;
    }
    for (const p of products) {
        const page = http.get(`${BASE}/products/${p.slug}`, { tags: { name: 'product' } });
        http.post(`${BASE}/cart/items`, { _token: token(page), product_id: String(p.id), quantity: '1' }, { tags: { name: 'add to cart' } });
    }
    const co = http.get(`${BASE}/checkout`, { tags: { name: 'checkout page' } });
    const key = co.html().find('input[name=idempotency_key]').first().attr('value');
    const total = co.html().find('input[name=expected_total]').first().attr('value');
    const res = http.post(`${BASE}/checkout`, { _token: token(co), idempotency_key: key, expected_total: total, payment_method: method, crypto: 'BTC', accept_terms: '1' },
        { tags: { name: 'place order' } });
    if (res.body && res.body.includes('just sold out')) {
        soldOut.add(1);
    }
    const m = res.url.match(/\/orders\/([0-9a-f-]{36})/);
    if (m) {
        ordersPlaced.add(1);
    }
    return m ? m[1] : null;
}

export function checkout() {
    const n = exec.scenario.iterationInTest;
    const email = data.checkout_buyers[n % data.checkout_buyers.length];
    const sellers = Object.keys(data.sellers);
    const start = (n * 3) % sellers.length;
    const products = [0, 1, 2].map((i) => data.sellers[sellers[(start + i) % sellers.length]][0]);
    const order = placeOrder(email, products, 'crypto');
    if (order) {
        const pay = http.post(`${data.mock}/__mock/pay/${order}`, '{}', { headers: { 'Content-Type': 'application/json' }, tags: { name: 'gateway pay' } });
        check(pay, { 'callback accepted': (r) => r.status === 200 && r.json('callback_http_status') === 202 });
    }
}

function signedCallback(order) {
    const body = JSON.stringify({
        external_id: order.id, crypto: order.crypto, addr: order.addr, fiat: order.fiat, balance_fiat: order.amount, balance_crypto: '0',
        paid: true, status: 'PAID', transactions: [{ txid: `load-${order.id}`, amount_fiat: order.amount, trigger: true }], fee_percent: '0', overpaid_fiat: '0.00',
    });
    const ts = String(Math.floor(Date.now() / 1000));
    const res = http.post(`${BASE}/webhooks/shkeeper`, body, {
        headers: { 'Content-Type': 'application/json', 'X-Shkeeper-Api-Key': data.api_key, 'X-Shkeeper-Timestamp': ts,
            'X-Shkeeper-Signature': crypto.hmac('sha256', data.api_key, `${ts}.${body}`, 'hex') },
        tags: { name: 'webhook' },
    });
    if (check(res, { 'webhook 202': (r) => r.status === 202 })) {
        callbacksOk.add(1);
    }
}

export function callback() {
    const n = exec.scenario.iterationInTest;
    signedCallback(data.orders[n % data.orders.length]); // after the first pass every callback is a duplicate
}

export function lastUnits() {
    const email = data.contention_buyers[exec.vu.idInTest % data.contention_buyers.length];
    placeOrder(email, [data.contention_product], 'balance');
}

export function sameCallbacks() {
    signedCallback(data.orders[exec.scenario.iterationInTest % 5]);
}

export function admin() {
    const jar = http.cookieJar();
    for (const [name, value] of Object.entries(data.admin_cookies)) {
        jar.set(BASE, name, value);
    }
    const res = http.get(`${BASE}/admin/products${pick(['', '?status=active', '?status=pending_review', '?page=2', '?page=5'])}`, { tags: { name: 'admin products' } });
    check(res, { 'admin products 200': (r) => r.status === 200 && r.url.includes('/admin/products') });
    adminList.add(res.timings.duration);
    sleep(1);
}

export function handleSummary(summary) {
    return { [__ENV.SUMMARY]: JSON.stringify(summary, null, 1), stdout: '\n' };
}
