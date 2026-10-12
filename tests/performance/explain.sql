-- Query plans of the hot and periodic queries (docs/performance/database-performance-audit.md),
-- in the shape the application sends them, on the dataset from scale-data.sql.
--   docker exec -i shopsim-db-1 psql -U shop -d shop -q < tests/performance/explain.sql > plans.txt
-- Read-only (EXPLAIN ANALYZE of SELECTs only); seller 6 = seller01, buyer 16 = buyer01.
\pset pager off
\set visible 'products.status = ''active'' AND EXISTS (SELECT 1 FROM users WHERE users.id = products.seller_id AND users.status = ''active'' AND users.role <> ''buyer'')'
\set paid '(''paid'', ''delivered'', ''partially_refunded'', ''refunded'')'

\echo '### Q01 catalog, newest first (page 1)'
EXPLAIN (ANALYZE, BUFFERS, COSTS OFF) SELECT * FROM products WHERE :visible ORDER BY created_at DESC, id DESC LIMIT 12;
\echo '### Q02 catalog count'
EXPLAIN (ANALYZE, BUFFERS, COSTS OFF) SELECT count(*) FROM products WHERE :visible;
\set units_sold '(SELECT COALESCE(SUM(order_items.quantity), 0) FROM order_items JOIN orders ON orders.id = order_items.order_id WHERE order_items.product_id = products.id AND orders.status IN (''paid'', ''delivered'', ''partially_refunded''))'
\echo '### Q03-old catalog, best selling (before: units summed per product in the query)'
EXPLAIN (ANALYZE, BUFFERS, COSTS OFF) SELECT * FROM products WHERE :visible ORDER BY :units_sold DESC, id DESC LIMIT 12;
\echo '### Q04-old related products (before)'
EXPLAIN (ANALYZE, BUFFERS, COSTS OFF) SELECT * FROM products WHERE :visible AND category_id = 2 AND id <> 1000 ORDER BY :units_sold DESC, id DESC LIMIT 4;
\echo '### Q08-old seller filter options (before: every seller)'
EXPLAIN (ANALYZE, BUFFERS, COSTS OFF) SELECT display_name, user_id FROM seller_profiles WHERE status = 'approved'
  AND EXISTS (SELECT 1 FROM products WHERE products.seller_id = seller_profiles.user_id AND products.status = 'active')
  AND EXISTS (SELECT 1 FROM users WHERE users.id = seller_profiles.user_id AND users.status = 'active' AND users.role <> 'buyer') ORDER BY display_name;
\echo '### Q03 catalog, best selling (stored ranking; after migration 2026_10_16_000100)'
EXPLAIN (ANALYZE, BUFFERS, COSTS OFF) SELECT * FROM products WHERE :visible ORDER BY units_sold DESC, id DESC LIMIT 12;
\echo '### Q04 related products in a category (stored ranking)'
EXPLAIN (ANALYZE, BUFFERS, COSTS OFF) SELECT * FROM products WHERE :visible AND category_id = 2 AND id <> 1000 ORDER BY units_sold DESC, id DESC LIMIT 4;
\echo '### Q05 search "font", title or description'
EXPLAIN (ANALYZE, BUFFERS, COSTS OFF) SELECT * FROM products WHERE :visible AND (title ILIKE '%font%' OR description ILIKE '%font%') ORDER BY created_at DESC, id DESC LIMIT 12;
\echo '### Q06 search count'
EXPLAIN (ANALYZE, BUFFERS, COSTS OFF) SELECT count(*) FROM products WHERE :visible AND (title ILIKE '%font%' OR description ILIKE '%font%');
\echo '### Q07 search suggestions "fon"'
EXPLAIN (ANALYZE, BUFFERS, COSTS OFF) SELECT id, slug, title FROM products WHERE :visible AND (title ILIKE '%fon%' OR description ILIKE '%fon%') ORDER BY title LIMIT 8;
\echo '### Q08 seller filter options (top sellers by units sold)'
EXPLAIN (ANALYZE, BUFFERS, COSTS OFF) SELECT seller_profiles.display_name, seller_profiles.user_id FROM seller_profiles
  JOIN products ON products.seller_id = seller_profiles.user_id AND products.status = 'active'
  JOIN users ON users.id = seller_profiles.user_id AND users.status = 'active' AND users.role <> 'buyer'
  WHERE seller_profiles.status = 'approved' GROUP BY seller_profiles.user_id, seller_profiles.display_name ORDER BY SUM(products.units_sold) DESC, seller_profiles.user_id LIMIT 50;
\echo '### Q09 buyer order list version (live updates): latest change of the buyer''s orders'
EXPLAIN (ANALYZE, BUFFERS, COSTS OFF) SELECT max(updated_at) FROM orders WHERE buyer_id = 16;
SELECT buyer_id AS quiet_buyer FROM orders GROUP BY buyer_id ORDER BY max(updated_at) LIMIT 1 \gset
\echo '### Q09b the same for a buyer whose last order is old (most buyers): the latest-change scan walks all newer orders'
EXPLAIN (ANALYZE, BUFFERS, COSTS OFF) SELECT max(updated_at) FROM orders WHERE buyer_id = :quiet_buyer;
\echo '### Q10 seller live version: latest sale id'
EXPLAIN (ANALYZE, BUFFERS, COSTS OFF) SELECT max(id) FROM order_items WHERE seller_id = 6;
\echo '### Q11 seller live version: latest ledger entry id'
EXPLAIN (ANALYZE, BUFFERS, COSTS OFF) SELECT max(id) FROM seller_ledger_entries WHERE seller_id = 6;
\echo '### Q12 seller dashboard / sales page: latest paid sales'
EXPLAIN (ANALYZE, BUFFERS, COSTS OFF) SELECT * FROM order_items WHERE seller_id = 6 AND EXISTS (SELECT 1 FROM orders WHERE orders.id = order_items.order_id AND orders.status IN ('paid', 'delivered', 'partially_refunded', 'refunded')) ORDER BY id DESC LIMIT 25;
\echo '### Q13 seller balances: held earnings'
EXPLAIN (ANALYZE, BUFFERS, COSTS OFF) SELECT orders.currency, SUM(order_items.seller_earning_minor) FROM order_items JOIN orders ON orders.id = order_items.order_id
  WHERE order_items.seller_id = 6 AND order_items.seller_earning_minor > 0 AND orders.status IN ('paid', 'delivered', 'partially_refunded')
  AND NOT EXISTS (SELECT 1 FROM disputes WHERE disputes.order_item_id = order_items.id AND disputes.status IN ('awaiting_seller', 'awaiting_staff'))
  AND (orders.paid_at > now() - interval '0 days' OR order_items.delivered_at IS NULL OR order_items.delivered_at > now() - interval '14 days') GROUP BY orders.currency;
\echo '### Q14 admin dashboard KPI: paid orders in the last 30 days'
EXPLAIN (ANALYZE, BUFFERS, COSTS OFF) SELECT COUNT(*), COALESCE(SUM(total_minor - refunded_minor), 0) FROM orders WHERE status IN :paid AND paid_at >= now() - interval '30 days' AND currency = 'USD';
\echo '### Q15 admin dashboard KPI: commission in the last 30 days'
EXPLAIN (ANALYZE, BUFFERS, COSTS OFF) SELECT sum(order_items.unit_price_minor * order_items.quantity - order_items.discount_minor - order_items.refunded_minor - order_items.seller_earning_minor)
  FROM order_items JOIN orders ON orders.id = order_items.order_id WHERE orders.status IN :paid AND orders.paid_at >= now() - interval '30 days' AND orders.currency = 'USD';
\echo '### Q16 admin dashboard KPI: orders created in the last 30 days'
EXPLAIN (ANALYZE, BUFFERS, COSTS OFF) SELECT count(*) FROM orders WHERE created_at >= now() - interval '30 days';
\echo '### Q17 admin order list version (live updates): latest delivery'
EXPLAIN (ANALYZE, BUFFERS, COSTS OFF) SELECT max(delivered_at) FROM order_items;
\echo '### Q18 shop:recover-stuck-orders (every minute): uninvoiced paid orders of the last 7 days'
EXPLAIN (ANALYZE, BUFFERS, COSTS OFF) SELECT id FROM orders WHERE status IN ('paid', 'delivered', 'partially_refunded') AND paid_at < now() - interval '10 minutes' AND paid_at > now() - interval '7 days'
  AND NOT EXISTS (SELECT 1 FROM invoices WHERE invoices.order_id = orders.id) LIMIT 200;
\echo '### Q19 shop:recover-stuck-orders: undelivered instant lines'
EXPLAIN (ANALYZE, BUFFERS, COSTS OFF) SELECT DISTINCT orders.id FROM orders JOIN order_items ON order_items.order_id = orders.id JOIN products ON products.id = order_items.product_id
  WHERE orders.status = 'paid' AND orders.paid_at < now() - interval '10 minutes' AND orders.paid_at > now() - interval '7 days' AND order_items.delivered_at IS NULL AND products.delivery_type = 'instant'
  AND NOT EXISTS (SELECT 1 FROM audit_log WHERE audit_log.action = 'delivery.failed' AND audit_log.target_type = 'OrderItem' AND audit_log.target_id = order_items.id::text) LIMIT 200;
\echo '### Q20 admin reports: revenue per day, last 30 days'
EXPLAIN (ANALYZE, BUFFERS, COSTS OFF) SELECT currency, DATE(paid_at), SUM(total_minor - refunded_minor) FROM orders WHERE status IN :paid AND paid_at BETWEEN now() - interval '30 days' AND now() GROUP BY currency, DATE(paid_at);
\echo '### Q21 system health: last processed webhook'
EXPLAIN (ANALYZE, BUFFERS, COSTS OFF) SELECT max(processed_at) FROM webhook_events WHERE provider = 'shkeeper' AND status = 'processed';
\echo '### Q22 CSV export, OFFSET paging: page 150 of a year of orders'
EXPLAIN (ANALYZE, BUFFERS, COSTS OFF) SELECT orders.*, users.email FROM orders JOIN users ON users.id = orders.buyer_id WHERE orders.created_at BETWEEN now() - interval '366 days' AND now() ORDER BY id LIMIT 1000 OFFSET 150000;
\echo '### Q23 CSV export, keyset paging: the same page'
EXPLAIN (ANALYZE, BUFFERS, COSTS OFF) SELECT orders.*, users.email FROM orders JOIN users ON users.id = orders.buyer_id WHERE orders.created_at BETWEEN now() - interval '366 days' AND now()
  AND orders.id > (SELECT id FROM orders ORDER BY id OFFSET 150000 LIMIT 1) ORDER BY orders.id LIMIT 1000;
\echo '### Q24 reconciliation check 1 in SQL: lines whose earning does not follow from their amounts'
EXPLAIN (ANALYZE, BUFFERS, COSTS OFF) SELECT order_items.id FROM order_items JOIN orders ON orders.id = order_items.order_id WHERE orders.status IN ('paid', 'delivered', 'partially_refunded')
  AND order_items.seller_earning_minor <> (order_items.unit_price_minor * order_items.quantity - order_items.discount_minor - order_items.refunded_minor)
  - ((order_items.unit_price_minor * order_items.quantity - order_items.discount_minor - order_items.refunded_minor) * order_items.commission_bps + 5000) / 10000;
\echo '### Q25 sitemap'
EXPLAIN (ANALYZE, BUFFERS, COSTS OFF) SELECT slug, updated_at FROM products WHERE :visible ORDER BY id LIMIT 45000;
\echo '### Q26 unread notifications badge (every signed-in page and poll)'
EXPLAIN (ANALYZE, BUFFERS, COSTS OFF) SELECT count(*) FROM user_notifications WHERE user_id = 16 AND read_at IS NULL;
\echo '### Q27 admin audit log, newest page'
EXPLAIN (ANALYZE, BUFFERS, COSTS OFF) SELECT * FROM audit_log ORDER BY id DESC LIMIT 50;
\echo '### Q28 sales ranking refresh (shop:refresh-sales-ranking), read part'
EXPLAIN (ANALYZE, BUFFERS, COSTS OFF) SELECT products.id, COALESCE(sold.units, 0) FROM products LEFT JOIN (SELECT order_items.product_id, SUM(order_items.quantity) AS units FROM order_items
  JOIN orders ON orders.id = order_items.order_id WHERE orders.status IN ('paid', 'delivered', 'partially_refunded') GROUP BY order_items.product_id) sold ON sold.product_id = products.id;
