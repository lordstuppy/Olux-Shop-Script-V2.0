-- Performance dataset: grows a simulation-seeded database (tests/simulation/run.sh
-- reset) to a provisional "first year" scale so query plans and page latency
-- can be measured against realistic volumes. Test infrastructure only; never
-- run against a real shop. Deterministic (setseed), so runs are comparable.
--
--   docker exec -i shopsim-db-1 psql -U shop -d shop -v ON_ERROR_STOP=1 < tests/performance/scale-data.sql
--
-- Target volumes (added to the seed): 500 sellers, 50,000 buyers, 20,000
-- products, 200,000 orders with ~260,000 items and one charge each, ~460,000
-- ledger entries, 40,000 reviews, 500,000 notifications, 1,000,000 audit
-- entries, 200,000 webhook events.
-- Random timestamps: each LATERAL refers to g ("+ 0 * g"), or PostgreSQL would
-- evaluate it once and give every row the same time.
\timing on
SELECT setseed(0.42);

BEGIN;

CREATE TEMP TABLE words(w text);
INSERT INTO words SELECT unnest(string_to_array(
 'pixel font serif sans icon pack bundle template resume mockup course python laravel react vue design guide ebook licence pro studio retro neon modern classic minimal bold vector photo preset lightroom audio loop drum synth plugin theme wordpress shopify invoice planner notion habit tracker budget fitness yoga recipe cooking travel map poster flyer logo brand kit social media story highlight cover wallpaper sticker emoji script handwritten display gothic grotesk mono coding cheat sheet interview javascript typescript golang rust docker kubernetes security network linux excel spreadsheet finance crypto trading chart pattern watercolor texture brush procreate 3d model blender asset game sprite tileset ui kit dashboard figma sketch wireframe landing page email newsletter seo marketing copywriting podcast video lut transition overlay',' '));

-- Sellers (approved profiles) and buyers. They share one existing password hash;
-- nobody signs in as them.
INSERT INTO users (name, email, password_hash, role, currency, status, email_verified_at, created_at, updated_at)
SELECT 'Perf Seller '||g, 'perf-seller-'||g||'@perf.test', (SELECT password_hash FROM users WHERE role='buyer' ORDER BY id LIMIT 1),
       'seller', 'USD', 'active', now(), now() - (random()*400||' days')::interval, now()
FROM generate_series(1, 500) g;
INSERT INTO seller_profiles (user_id, display_name, payout_currency, payout_address, payout_crypto, status, created_at, updated_at)
SELECT id, 'Perf Studio '||id, 'USD', 'bc1qperf'||id, 'BTC', 'approved', created_at, now()
FROM users WHERE email LIKE 'perf-seller-%';
INSERT INTO users (name, email, password_hash, role, currency, status, email_verified_at, created_at, updated_at)
SELECT 'Perf Buyer '||g, 'perf-buyer-'||g||'@perf.test', (SELECT password_hash FROM users WHERE role='buyer' ORDER BY id LIMIT 1),
       'buyer', 'USD', 'active', now(), now() - (random()*400||' days')::interval, now()
FROM generate_series(1, 50000) g;

CREATE TEMP TABLE perf_sellers AS SELECT row_number() OVER (ORDER BY id) AS n, id FROM users WHERE email LIKE 'perf-seller-%';
CREATE TEMP TABLE perf_buyers AS SELECT row_number() OVER (ORDER BY id) AS n, id FROM users WHERE email LIKE 'perf-buyer-%';
CREATE TEMP TABLE w AS SELECT row_number() OVER () AS n, w FROM words;

-- Products: 85% active, the rest draft / pending / disabled / paused.
INSERT INTO products (seller_id, category_id, slug, title, description, price_minor, currency, stock, delivery_type, status, created_at, updated_at)
SELECT s.id, 1 + (g % 8),
       'perf-product-'||g,
       initcap(w1.w||' '||w2.w||' '||w3.w)||' '||g,
       'A '||w2.w||' '||w3.w||' for '||w1.w||' work. '||repeat('Includes source files, documentation and updates. ', 4),
       99 + (random()*19900)::int, CASE WHEN g % 4 = 0 THEN 'EUR' ELSE 'USD' END,
       CASE WHEN g % 5 = 0 THEN 100 ELSE NULL END,
       CASE WHEN g % 10 = 0 THEN 'manual' ELSE 'instant' END,
       CASE WHEN g % 20 < 17 THEN 'active' WHEN g % 20 = 17 THEN 'draft' WHEN g % 20 = 18 THEN 'pending_review' WHEN g % 40 = 19 THEN 'disabled' ELSE 'paused' END,
       now() - (random()*365||' days')::interval, now() - (random()*30||' days')::interval
FROM generate_series(1, 20000) g
JOIN perf_sellers s ON s.n = 1 + (g % 500)
JOIN w w1 ON w1.n = 1 + (g * 7) % (SELECT count(*) FROM words)
JOIN w w2 ON w2.n = 1 + (g * 13) % (SELECT count(*) FROM words)
JOIN w w3 ON w3.n = 1 + (g * 31) % (SELECT count(*) FROM words);

CREATE TEMP TABLE perf_products AS
SELECT row_number() OVER (ORDER BY id) AS n, id, seller_id, title, price_minor, currency FROM products WHERE slug LIKE 'perf-product-%' AND status = 'active';

-- Orders over the last 365 days; mostly delivered. (No partial refunds or paid-but-undelivered orders: both are transient or per-line states not worth modelling here.)
INSERT INTO orders (public_id, buyer_id, status, subtotal_minor, discount_minor, total_minor, refunded_minor, currency, idempotency_key, expires_at, created_at, updated_at, paid_at, delivered_at)
SELECT gen_random_uuid(), b.id, st.status, 0, 0, 0, 0, 'USD', 'perf-'||g,
       ts + interval '30 minutes', ts, ts,
       CASE WHEN st.status IN ('paid','delivered','refunded','partially_refunded') THEN ts + interval '5 minutes' END,
       CASE WHEN st.status IN ('delivered','refunded','partially_refunded') THEN ts + interval '6 minutes' END
FROM generate_series(1, 200000) g
JOIN perf_buyers b ON b.n = 1 + (g * 7919) % 50000
CROSS JOIN LATERAL (SELECT now() - make_interval(secs => (random() * 365 * 86400)::int + 0 * g) AS ts) t
CROSS JOIN LATERAL (SELECT CASE WHEN g % 100 < 75 THEN 'delivered' WHEN g % 100 < 87 THEN 'expired' WHEN g % 100 < 92 THEN 'cancelled'
                                WHEN g % 100 < 98 THEN 'refunded' ELSE 'pending' END AS status) st;

-- One item per order, a second one for every fourth order (different seller).
INSERT INTO order_items (order_id, product_id, seller_id, title, unit_price_minor, quantity, list_price_minor, list_currency, fx_rate, discount_minor, refunded_minor, commission_bps, seller_earning_minor, delivered_payload, delivered_at, created_at, updated_at, download_count)
SELECT o.id, p.id, p.seller_id, p.title, p.price_minor, 1, p.price_minor, p.currency, 1, 0,
       -- Earnings as PayoutService::earningFor computes them (10% commission, half up); a refunded line earns nothing.
       CASE WHEN o.status = 'refunded' THEN p.price_minor ELSE 0 END, 1000,
       CASE WHEN o.status = 'refunded' THEN 0 ELSE p.price_minor - (p.price_minor * 1000 + 5000) / 10000 END,
       NULL, o.delivered_at, o.created_at, o.created_at, 0  -- delivered_payload is encrypted by the app: left empty
FROM orders o
CROSS JOIN LATERAL (SELECT k FROM generate_series(1, CASE WHEN o.id % 4 = 0 THEN 2 ELSE 1 END) k) items
JOIN perf_products p ON p.n = 1 + ((o.id * 104729 + items.k * 7) % (SELECT count(*) FROM perf_products))
WHERE o.idempotency_key LIKE 'perf-%';

UPDATE orders o SET subtotal_minor = s.total, total_minor = s.total,
       refunded_minor = CASE WHEN o.status = 'refunded' THEN s.total ELSE 0 END
FROM (SELECT order_id, sum(unit_price_minor * quantity) AS total FROM order_items GROUP BY order_id) s
WHERE s.order_id = o.id AND o.idempotency_key LIKE 'perf-%';

INSERT INTO payments (order_id, kind, provider, provider_reference, amount_minor, received_minor, currency, status, crypto, created_at, updated_at, confirmed_at)
SELECT id, 'charge', 'shkeeper', 'perf-inv-'||id, total_minor, CASE WHEN paid_at IS NOT NULL THEN total_minor ELSE 0 END, 'USD',
       CASE WHEN paid_at IS NOT NULL THEN 'confirmed' WHEN status = 'pending' THEN 'pending' ELSE 'failed' END, 'BTC', created_at, created_at, paid_at
FROM orders WHERE idempotency_key LIKE 'perf-%' AND total_minor > 0;

-- Paid orders have their invoice (rows only; without them the recovery job would render thousands of PDFs).
INSERT INTO invoices (order_id, number, storage_path, issued_at, created_at, updated_at)
SELECT id, 'PERF-'||id, NULL, paid_at, paid_at, paid_at FROM orders WHERE idempotency_key LIKE 'perf-%' AND paid_at IS NOT NULL;

INSERT INTO seller_ledger_entries (seller_id, order_item_id, type, amount_minor, currency, created_at)
SELECT seller_id, id, 'sale', unit_price_minor, 'USD', delivered_at FROM order_items WHERE delivered_at IS NOT NULL AND id IN (SELECT oi.id FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE o.idempotency_key LIKE 'perf-%');
INSERT INTO seller_ledger_entries (seller_id, order_item_id, type, amount_minor, currency, created_at)
SELECT seller_id, order_item_id, 'commission', -((amount_minor * 1000 + 5000) / 10000), 'USD', created_at FROM seller_ledger_entries WHERE type = 'sale' AND order_item_id IN (SELECT oi.id FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE o.idempotency_key LIKE 'perf-%');
-- Refunded lines: the refund takes the earning back.
INSERT INTO seller_ledger_entries (seller_id, order_item_id, type, amount_minor, currency, created_at)
SELECT oi.seller_id, oi.id, 'refund_adjustment', -(oi.unit_price_minor - (oi.unit_price_minor * 1000 + 5000) / 10000), 'USD', oi.delivered_at + interval '2 days'
FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE o.idempotency_key LIKE 'perf-%' AND o.status = 'refunded';

-- Reviews: unique (product, user), from delivered items.
INSERT INTO product_reviews (product_id, user_id, order_item_id, rating, title, body, status, created_at, updated_at)
SELECT DISTINCT ON (oi.product_id, o.buyer_id) oi.product_id, o.buyer_id, oi.id, 1 + (oi.id % 5), 'Review '||oi.id,
       'Works as described. '||repeat('Good value. ', 5), CASE WHEN oi.id % 50 = 0 THEN 'hidden' ELSE 'visible' END, oi.delivered_at + interval '1 day', oi.delivered_at + interval '1 day'
FROM order_items oi JOIN orders o ON o.id = oi.order_id
WHERE o.idempotency_key LIKE 'perf-%' AND oi.delivered_at IS NOT NULL AND oi.id % 5 = 0
ORDER BY oi.product_id, o.buyer_id, oi.id
LIMIT 40000;

-- Notifications: ten per buyer, about 20% unread.
INSERT INTO user_notifications (user_id, kind, title, body, url, read_at, created_at)
SELECT b.id, (ARRAY['order','sale','account','review','ticket'])[1 + g % 5], 'Notification '||g, 'Something happened.', '/orders',
       CASE WHEN g % 5 = 0 THEN NULL ELSE ts + interval '1 hour' END, ts
FROM generate_series(1, 500000) g
JOIN perf_buyers b ON b.n = 1 + (g % 50000)
CROSS JOIN LATERAL (SELECT now() - make_interval(secs => (random() * 365 * 86400)::int + 0 * g) AS ts) t;

INSERT INTO audit_log (actor_id, action, target_type, target_id, metadata_json, ip, request_id, created_at)
SELECT b.id, (ARRAY['user.login','order.created','order.paid','product.updated','review.created','cart.updated','user.logout','webhook.received'])[1 + g % 8],
       'order', (g % 200000)::text, '{"k":1}', '10.0.'||(g % 250)||'.'||(g % 200), md5(g::text),
       now() - ((random() * 365 * 86400)::int || ' seconds')::interval
FROM generate_series(1, 1000000) g
JOIN perf_buyers b ON b.n = 1 + (g % 50000);

INSERT INTO webhook_events (provider, event_key, external_id, payload, status, attempts, processed_at, source_ip, created_at, updated_at)
SELECT 'shkeeper', encode(sha256(('perf-'||g)::bytea), 'hex'), gen_random_uuid()::text, '{"status":"PAID","paid":true}', 'processed', 1,
       ts, '172.18.0.5', ts, ts
FROM generate_series(1, 200000) g
CROSS JOIN LATERAL (SELECT now() - make_interval(secs => (random() * 365 * 86400)::int + 0 * g) AS ts) t;

COMMIT;

VACUUM ANALYZE;
SELECT 'users', count(*) FROM users UNION ALL SELECT 'products', count(*) FROM products UNION ALL SELECT 'orders', count(*) FROM orders
UNION ALL SELECT 'order_items', count(*) FROM order_items UNION ALL SELECT 'payments', count(*) FROM payments
UNION ALL SELECT 'ledger', count(*) FROM seller_ledger_entries UNION ALL SELECT 'reviews', count(*) FROM product_reviews
UNION ALL SELECT 'notifications', count(*) FROM user_notifications UNION ALL SELECT 'audit', count(*) FROM audit_log
UNION ALL SELECT 'webhooks', count(*) FROM webhook_events;

-- Heavy accounts the benchmark signs in as: buyer01 gets 500 of the orders
-- (and their notifications), seller01 takes over ten generated sellers' catalogues and sales.
BEGIN;
UPDATE orders SET buyer_id = (SELECT id FROM users WHERE email = 'buyer01@sim.test')
WHERE idempotency_key LIKE 'perf-%' AND id % 400 = 0;
UPDATE user_notifications SET user_id = (SELECT id FROM users WHERE email = 'buyer01@sim.test')
WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'perf-buyer-%' ORDER BY id LIMIT 10);
CREATE TEMP TABLE heavy_sellers AS SELECT id FROM users WHERE email LIKE 'perf-seller-%' ORDER BY id LIMIT 10;
UPDATE products SET seller_id = (SELECT id FROM users WHERE email = 'seller01@sim.test') WHERE seller_id IN (SELECT id FROM heavy_sellers);
UPDATE order_items SET seller_id = (SELECT id FROM users WHERE email = 'seller01@sim.test') WHERE seller_id IN (SELECT id FROM heavy_sellers);
UPDATE seller_ledger_entries SET seller_id = (SELECT id FROM users WHERE email = 'seller01@sim.test') WHERE seller_id IN (SELECT id FROM heavy_sellers);
COMMIT;
ANALYZE;
