// k6 load test: signed Shkeeper callbacks for many orders. Each order gets two
// callbacks with different bodies (as Shkeeper sends one per transaction),
// usually handled concurrently by different VUs, so idempotency rests on the
// payment row lock rather than on body de-duplication.
import http from 'k6/http';
import { check } from 'k6';
import crypto from 'k6/crypto';
import { SharedArray } from 'k6/data';
import exec from 'k6/execution';

const BASE = __ENV.SHOP_URL || 'http://127.0.0.1:8000';
const SECRET = __ENV.SHKEEPER_WEBHOOK_SECRET || 'dev-api-key';
const orders = new SharedArray('orders', () => JSON.parse(open('./webhook-orders.json')));

export const options = {
    scenarios: {
        webhooks: {
            executor: 'shared-iterations',
            vus: Number(__ENV.VUS || 20),
            iterations: orders.length * 2,
            maxDuration: '3m',
        },
    },
    thresholds: {
        http_req_failed: ['rate<0.01'],
        http_req_duration: ['p(95)<1000'],
    },
};

export default function () {
    // Test-wide iteration index (__ITER is per VU in shared-iterations).
    const n = exec.scenario.iterationInTest;
    const order = orders[Math.floor(n / 2) % orders.length];
    const body = JSON.stringify({
        external_id: order, crypto: 'BTC', addr: 'bc1qload', fiat: 'USD', balance_fiat: '1.00',
        balance_crypto: '0.0000017', paid: true, status: 'PAID',
        transactions: [{ txid: `tx-${order}-${n % 2}`, amount_fiat: '1.00', trigger: true }], fee_percent: '0', overpaid_fiat: '0.00',
    });
    const ts = String(Math.floor(Date.now() / 1000));
    const res = http.post(`${BASE}/webhooks/shkeeper`, body, {
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Shkeeper-Timestamp': ts,
            'X-Shkeeper-Signature': crypto.hmac('sha256', SECRET, `${ts}.${body}`, 'hex'),
        },
    });
    check(res, { 'accepted (202)': (r) => r.status === 202 });
}
