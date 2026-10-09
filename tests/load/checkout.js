// k6 load test: concurrent checkouts of the same product (stock row lock)
// paid from balance, with occasional double submits of the same form.
// Run via tests/load/run.sh.
import http from 'k6/http';
import { check, fail } from 'k6';
import { Trend, Counter } from 'k6/metrics';

const BASE = __ENV.SHOP_URL || 'http://127.0.0.1:8000';
const checkoutDuration = new Trend('checkout_duration', true);
const duplicateSubmits = new Counter('duplicate_submits');

export const options = {
    scenarios: {
        checkout: {
            executor: 'ramping-vus',
            startVUs: 1,
            stages: [
                { duration: '15s', target: Number(__ENV.VUS || 20) },
                { duration: '45s', target: Number(__ENV.VUS || 20) },
                { duration: '10s', target: 0 },
            ],
        },
    },
    thresholds: {
        http_req_failed: ['rate<0.01'],
        checkout_duration: ['p(95)<1500'],
    },
};

function token(body) {
    const m = body.match(/name="_token" value="([^"]+)"/);
    if (!m) fail('no CSRF token on page');
    return m[1];
}

export function setup() {
    return { users: Number(__ENV.LOAD_USERS || 50) };
}

export default function (data) {
    const jar = http.cookieJar();
    if (!jar.cookiesForURL(BASE).loggedIn) {
        const email = `load${((__VU - 1) % data.users) + 1}@example.test`;
        const login = http.get(`${BASE}/login`);
        const res = http.post(`${BASE}/login`, { _token: token(login.body), email, password: 'correct-horse-battery-1' }, { redirects: 0 });
        check(res, { 'login redirect': (r) => r.status === 302 });
        jar.set(BASE, 'loggedIn', '1');
    }

    const product = http.get(`${BASE}/products/load-test-product`);
    const add = http.post(`${BASE}/cart/items`, { _token: token(product.body), product_id: product.body.match(/name="product_id" value="(\d+)"/)[1], quantity: 1 }, { redirects: 0 });
    check(add, { 'added to cart': (r) => r.status === 302 });

    const page = http.get(`${BASE}/checkout`);
    check(page, { 'checkout page': (r) => r.status === 200 });
    const key = page.body.match(/name="idempotency_key" value="([^"]+)"/)[1];
    const form = { _token: token(page.body), idempotency_key: key, payment_method: 'balance', accept_terms: '1' };

    const started = Date.now();
    const res = http.post(`${BASE}/checkout`, form, { redirects: 0 });
    checkoutDuration.add(Date.now() - started);
    check(res, { 'order placed': (r) => r.status === 302 && /\/orders\/[0-9a-f-]{36}\/result/.test(r.headers.Location || '') });

    // Every fifth iteration re-submits the same form, as a double click would.
    if (__ITER % 5 === 0) {
        const again = http.post(`${BASE}/checkout`, form, { redirects: 0 });
        duplicateSubmits.add(1);
        check(again, { 'double submit resolves to same order': (r) => r.headers.Location === res.headers.Location });
    }
}
