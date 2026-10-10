// Phase 7 load test (k6). Four workloads run at the same time:
//   browsers  50 virtual browsers (30 signed in) browsing, searching, adding to the cart
//   checkout  20 buyers each buying from three sellers, paid through the Shkeeper mock
//   webhooks  ~100 signed payment callbacks per minute (100 orders + 20 duplicates)
//   admin     a super admin paging and filtering the product list (budget 500 ms)
// Input: DATA (JSON from prepare.py). Output: SUMMARY (JSON).
import http from 'k6/http';
import crypto from 'k6/crypto';
import { check, sleep } from 'k6';
import exec from 'k6/execution';
import { Counter, Trend } from 'k6/metrics';

const data = JSON.parse(open(__ENV.DATA));
const BASE = __ENV.BASE || 'https://localhost:8443';
const DURATION = __ENV.DURATION || '2m';

const checkoutsOk = new Counter('checkouts_ok');
const webhooksOk = new Counter('webhooks_ok');
const adminProducts = new Trend('admin_product_list', true);

export const options = {
    insecureSkipTLSVerify: true, // self-signed certificate of the test stack
    discardResponseBodies: false,
    scenarios: {
        browsers: { executor: 'constant-vus', exec: 'browse', vus: 50, duration: DURATION },
        checkout: { executor: 'per-vu-iterations', exec: 'checkout', vus: 20, iterations: 1, maxDuration: DURATION, startTime: '10s' },
        webhooks: { executor: 'constant-arrival-rate', exec: 'webhook', rate: 100, timeUnit: '1m', duration: '72s',
            preAllocatedVUs: 5, maxVUs: 20, startTime: '5s' },
        admin: { executor: 'constant-vus', exec: 'admin', vus: 1, duration: DURATION },
    },
    thresholds: {
        // Per-request rows for the report (generous limits; the budgets are below).
        ...Object.fromEntries(['home', 'catalog', 'search', 'product', 'add to cart', 'cart', 'login', 'checkout page', 'place order', 'webhook', 'admin products']
            .map((n) => [`http_req_duration{name:${n}}`, ['p(95)<5000']])),
        'http_req_failed{scenario:browsers}': ['rate<0.01'],
        'http_req_duration{scenario:browsers}': ['p(95)<1500'],
        admin_product_list: ['p(95)<500'],
        checkouts_ok: ['count==20'],
        webhooks_ok: ['count>=120'],
    },
};

function token(res) {
    const t = res.html().find('input[name=_token]').first().attr('value');
    return t || '';
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
    // 30 of the 50 browsers sign in once (iteration numbers are per scenario, not per test).
    if (exec.vu.iterationInScenario === 0 && exec.vu.idInTest % 5 < 3) {
        login(data.browse_buyers[exec.vu.idInTest % data.browse_buyers.length]);
    }
    http.get(`${BASE}/`, { tags: { name: 'home' } });
    const list = http.get(`${BASE}/products?category=${pick(data.categories)}&sort=${pick(['newest', 'price_asc', 'price_desc'])}`, { tags: { name: 'catalog' } });
    check(list, { 'catalog 200': (r) => r.status === 200 });
    if (Math.random() < 0.3) {
        http.get(`${BASE}/products?q=${pick(['pro', 'key', 'font', 'icon', 'guide', 'pack'])}`, { tags: { name: 'search' } });
    }
    const slug = pick(data.slugs);
    const page = http.get(`${BASE}/products/${slug}`, { tags: { name: 'product' } });
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

export function checkout() {
    const n = exec.scenario.iterationInTest;
    const email = data.checkout_buyers[n];
    if (!login(email)) {
        console.error(`checkout: login failed for ${email}`);
        return;
    }
    const sellers = Object.keys(data.sellers);
    const start = (n * 3) % sellers.length;
    for (let i = 0; i < 3; i++) {
        const p = data.sellers[sellers[(start + i) % sellers.length]][0];
        const page = http.get(`${BASE}/products/${p.slug}`, { tags: { name: 'product' } });
        http.post(`${BASE}/cart/items`, { _token: token(page), product_id: String(p.id), quantity: '1' }, { tags: { name: 'add to cart' } });
    }
    const co = http.get(`${BASE}/checkout`, { tags: { name: 'checkout page' } });
    const key = co.html().find('input[name=idempotency_key]').first().attr('value');
    const res = http.post(`${BASE}/checkout`, { _token: token(co), idempotency_key: key, payment_method: 'crypto', crypto: 'BTC', accept_terms: '1' },
        { tags: { name: 'place order' } });
    const m = res.url.match(/\/orders\/([0-9a-f-]{36})/);
    if (!m) {
        console.error(`checkout: no order for ${email}: ${res.status} ${res.url}`);
        return;
    }
    const pay = http.post(`${data.mock}/__mock/pay/${m[1]}`, '{}', { headers: { 'Content-Type': 'application/json' }, tags: { name: 'gateway pay' } });
    if (check(pay, { 'callback accepted': (r) => r.status === 200 && r.json('callback_http_status') === 202 })) {
        checkoutsOk.add(1);
    }
}

export function webhook() {
    const n = exec.scenario.iterationInTest % 120;
    const order = data.orders[n < 100 ? n : n - 100]; // the last 20 are duplicates
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
        webhooksOk.add(1);
    }
}

export function admin() {
    const jar = http.cookieJar();
    for (const [name, value] of Object.entries(data.admin_cookies)) {
        jar.set(BASE, name, value);
    }
    const queries = ['', '?status=active', '?status=pending_review', '?page=2', '?page=5', `?q=${pick(['pro', 'key', 'font'])}`];
    const res = http.get(`${BASE}/admin/products${pick(queries)}`, { tags: { name: 'admin products' } });
    check(res, { 'admin products 200': (r) => r.status === 200 && r.url.includes('/admin/products') });
    adminProducts.add(res.timings.duration);
    sleep(1);
}

export function handleSummary(summary) {
    return { [__ENV.SUMMARY]: JSON.stringify(summary, null, 1), stdout: '\n' };
}
