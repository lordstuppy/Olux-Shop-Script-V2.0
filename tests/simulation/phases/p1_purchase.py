"""Phase 1: purchase scenarios, as a buyer in a browser without JavaScript."""
from simlib import env, shop
from simlib.http import Client
from simlib.record import scenario

from .common import (admin, balance, buyer, check_ledger, flashes, instant_product, items_of, money, parallel,
                     staff_form, stock, wait_status)


def crypto_buy(rec, c, products, qty=None):
    """Adds products to the cart, checks out with crypto and pays exactly. Returns the order id."""
    for p in products:
        r = shop.add_to_cart(c, p, (qty or {}).get(p['id'], 1))
        rec.check(r.path == '/cart' and 'Added' in flashes(r), f"add {p['title']}: {flashes(r)}")
    r, oid = shop.checkout(c, 'crypto', 'BTC')
    rec.check(oid is not None, f'checkout did not create an order: {r.path} {flashes(r)}')
    rec.step(f'checkout -> order {oid} ({r.path})')
    pay = shop.pay(oid)
    rec.ev(f'mock pay: {pay}')
    rec.check(pay.get('callback_http_status') in (200, 202), f'webhook answered {pay}')
    return oid


@scenario(1, 'Single item purchase (crypto)', 'buyer01 logged in; an instant product with a clean file',
          'Order paid and delivered, file downloadable, one sale + commission ledger entry, emails sent', 'blocker')
def single_item(rec, ctx):
    email, c = buyer(1)
    p = instant_product()
    rec.step(f"buy {p['title']} ({p['id']}, {money(p['price'])} {p['currency']})")
    oid = crypto_buy(rec, c, [p])
    wait_status(oid, 'delivered')
    page = c.get(f'/orders/{oid}')
    rec.check('/files/' in page.text, 'no download link on the order page')
    check_ledger(rec, oid)
    for subject in ('placed', 'paid'):
        env.wait_mail(email, subject)
    ctx.data['single_order'] = oid


@scenario(1, 'Two items from the same vendor', 'buyer02; two instant products of one seller',
          'One order with two lines, both delivered; one "new sale" mail to the seller', 'critical')
def two_same_vendor(rec, ctx):
    email, c = buyer(2)
    seller = env.scalar("""select seller_id from products p where status='active' and delivery_type='instant' and stock is null and currency='USD'
        and exists (select 1 from product_files f where f.product_id=p.id and f.scan_status='clean')
        and not exists (select 1 from product_license_keys k where k.product_id=p.id) group by seller_id having count(*) >= 2 order by seller_id limit 1""")
    a = instant_product(f'seller_id={seller}')
    b = instant_product(f'seller_id={seller}', exclude=[a['id']])
    seller_email = env.scalar(f'select email from users where id={seller}')
    before = len(env.mails(seller_email, 'New sale'))
    oid = crypto_buy(rec, c, [a, b])
    wait_status(oid, 'delivered')
    items = items_of(oid)
    rec.check(len(items) == 2 and all(i['delivered'] for i in items), f'lines: {items}')
    check_ledger(rec, oid)
    env.wait_mail(seller_email, 'New sale', count=before + 1)
    import time
    time.sleep(3)
    rec.check(len(env.mails(seller_email, 'New sale')) == before + 1, 'seller got more than one sale mail for one order')


@scenario(1, 'Three vendors; one delivery fails without blocking the others',
          'buyer03; products of three sellers (one a licence-key product whose keys are withdrawn after checkout)',
          'Per-seller lines, rate and ledger correct; two lines delivered; the failing line stays undelivered, '
          'order stays paid, delivery.failed audited, no ledger loss', 'critical')
def three_vendors(rec, ctx):
    email, c = buyer(3)
    sellers = [int(x[0]) for x in env.sql("""select distinct seller_id from products p where status='active' and delivery_type='instant' and stock is null
        and currency='USD' and exists (select 1 from product_files f where f.product_id=p.id and f.scan_status='clean')
        and not exists (select 1 from product_license_keys k where k.product_id=p.id) order by seller_id""")]
    p1 = instant_product(f'seller_id={sellers[0]}')
    p2 = instant_product(f'seller_id={sellers[1]}')
    p3 = shop.product(f"""status='active' and delivery_type='instant' and currency='USD' and seller_id not in ({sellers[0]},{sellers[1]})
        and stock >= 2 and (select count(*) from product_license_keys k where k.product_id=products.id and k.order_item_id is null) >= 2""")
    rec.step(f"sellers {p1['seller_id']}, {p2['seller_id']}, {p3['seller_id']}")
    for p in (p1, p2, p3):
        shop.add_to_cart(c, p)
    r, oid = shop.checkout(c, 'crypto', 'BTC')
    rec.check(oid, f'no order: {flashes(r)}')
    keys = env.sql(f"select id from product_license_keys where product_id={p3['id']} and order_item_id is null")
    env.sql(f"delete from product_license_keys where product_id={p3['id']} and order_item_id is null")
    rec.step(f"fault injected: deleted {len(keys)} unassigned keys of product {p3['id']} before payment")
    ctx.allow_log(r'(?i)deliver')
    shop.pay(oid)
    wait_status(oid, ('paid', 'delivered'))
    shop.wait_for(lambda: sum(i['delivered'] for i in items_of(oid)) >= 2, 'two lines delivered', 90)
    import time
    time.sleep(4)
    items = {i['product_id']: i for i in items_of(oid)}
    rec.check(items[p1['id']]['delivered'] and items[p2['id']]['delivered'], f'working lines not delivered: {items}')
    rec.check(not items[p3['id']]['delivered'], 'line without keys marked delivered')
    rec.check(shop.order_row(oid)['status'] == 'paid', f"order status {shop.order_row(oid)['status']}")
    rec.check(env.scalar(f"select count(*) from audit_log where action='delivery.failed' and context::text like '%{p3['id']}%'") not in ('0', None)
              or env.scalar("select count(*) from audit_log where action='delivery.failed'") != '0', 'delivery.failed not audited')
    check_ledger(rec, oid)
    rec.check(len({i['seller_id'] for i in items.values()}) == 3, 'lines not split per seller')


@scenario(1, 'Five units of one product', 'buyer04; a licence-key product with >= 5 keys in stock',
          'One line quantity 5, five distinct keys delivered, stock reduced by 5', 'critical')
def five_units(rec, ctx):
    email, c = buyer(4)
    p = shop.product("""status='active' and delivery_type='instant' and currency='USD'
        and (select count(*) from product_license_keys k where k.product_id=products.id and k.order_item_id is null) >= 6 and stock >= 6""")
    before = stock(p['id'])
    oid = crypto_buy(rec, c, [p], {p['id']: 5})
    wait_status(oid, 'delivered')
    items = items_of(oid)
    rec.check(len(items) == 1 and items[0]['qty'] == 5, f'lines {items}')
    assigned = env.sql(f"select count(distinct key_fingerprint) from product_license_keys where order_item_id={items[0]['id']}")[0][0]
    rec.check(assigned == '5', f'{assigned} keys assigned instead of 5')
    rec.check(stock(p['id']) == before - 5, f"stock {before} -> {stock(p['id'])}")
    check_ledger(rec, oid)


@scenario(1, 'Mixed stock states in the cart', 'buyer05; one unlimited, one limited (stock 2), one out of stock product',
          'Out-of-stock product cannot be added; more than stock is refused with the stock count; after another sale '
          'lowers stock the cart flags it and reduces the quantity; checkout charges the reduced quantity', 'major')
def mixed_stock(rec, ctx):
    email, c = buyer(5)
    free = instant_product()
    limited = shop.product("""status='active' and delivery_type='instant' and currency='USD' and stock >= 3
        and (select count(*) from product_license_keys k where k.product_id=products.id and k.order_item_id is null) >= 3""", order='id desc')
    out = shop.product("status='active' and stock=0")
    page = c.get(f"/products/{out['slug']}")
    rec.check('out of stock' in page.text.lower() or 'sold out' in page.text.lower(), 'out-of-stock product page does not say so')
    r = c.post('/cart/items', {'product_id': str(out['id']), 'quantity': '1'}, token_from=f"/products/{out['slug']}")
    rec.check('not available' in flashes(r).lower() or 'stock' in flashes(r).lower(), f'adding an out-of-stock product: {flashes(r)}')
    s = stock(limited['id'])
    r = shop.add_to_cart(c, limited, s + 1)
    rec.check('left in stock' in flashes(r) or 'at most' in flashes(r), f'over-stock quantity accepted: {flashes(r)}')
    shop.add_to_cart(c, free)
    shop.add_to_cart(c, limited, 3)
    rec.step(f"cart: {free['id']} x1, {limited['id']} x3 (stock {s})")
    env.sql(f"update products set stock=2 where id={limited['id']}")
    rec.step('stock of the limited product lowered to 2 (another buyer)')
    cart = c.get('/cart')
    rec.check('Only 2 of' in cart.text and 'reduced' in cart.text, f'cart did not flag the stock change: {flashes(cart)}')
    r, oid = shop.checkout(c, 'crypto', 'BTC')
    rec.check(oid, f'checkout failed: {flashes(r)}')
    items = {i['product_id']: i for i in items_of(oid)}
    rec.check(items[limited['id']]['qty'] == 2, f"ordered {items[limited['id']]['qty']} of the limited product")
    rec.check(stock(limited['id']) == 0, f"stock after order {stock(limited['id'])}")
    shop.pay(oid)
    wait_status(oid, 'delivered')
    check_ledger(rec, oid)


@scenario(1, 'Coupon on the subtotal; commission on the discounted amount',
          'Super admin creates SIM20 (20% off); buyer06 buys two products of different sellers',
          'Discount = 20% of the subtotal, allocated across lines; commission computed on each net line amount', 'critical')
def coupon(rec, ctx):
    a = admin()
    r = staff_form(a, '/admin/coupons', '/admin/coupons', {'code': 'SIM20', 'type': 'percent', 'value': '20', 'max_per_user': '1'})
    rec.check(env.scalar("select count(*) from coupons where code='SIM20'") == '1', f'coupon not created: {flashes(r)}')
    email, c = buyer(6)
    p1 = instant_product()
    p2 = instant_product(f"seller_id<>{p1['seller_id']}")
    shop.add_to_cart(c, p1)
    shop.add_to_cart(c, p2)
    r = c.form('/checkout', '/checkout/coupon', {'code': 'sim20'})
    rec.check('applied' in flashes(r), f'coupon not applied: {flashes(r)}')
    r, oid = shop.checkout(c, 'crypto', 'BTC')
    o = env.sql(f"select subtotal_minor, discount_minor, total_minor from orders where public_id='{oid}'")[0]
    sub, disc, total = map(int, o)
    rec.ev(f'subtotal {sub}, discount {disc}, total {total}')
    rec.check(disc == (sub * 2000 + 5000) // 10000 or disc == sub * 2000 // 10000, f'discount {disc} is not 20% of {sub}')
    items = items_of(oid)
    rec.check(sum(i['discount'] for i in items) == disc, 'line discounts do not add up')
    shop.pay(oid)
    wait_status(oid, 'delivered')
    check_ledger(rec, oid)
    # one use per buyer
    shop.add_to_cart(c, p1)
    r = c.form('/checkout', '/checkout/coupon', {'code': 'SIM20'})
    rec.check('applied' not in flashes(r), 'coupon used twice by one buyer')
    shop.empty_cart(c)


@scenario(1, 'Insufficient balance: blocked, no partial order',
          'buyer10 (balance 0); a product in the cart; pays with balance (also forcing the disabled radio)',
          'Clear error with total and available amount, no order, stock and balance unchanged, cart kept', 'blocker')
def insufficient_balance(rec, ctx):
    email, c = buyer(10)
    rec.check(balance(email) == 0, 'buyer10 is expected to have no balance')
    p = shop.product("""status='active' and delivery_type='instant' and currency='USD' and stock >= 1
        and (select count(*) from product_license_keys k where k.product_id=products.id and k.order_item_id is null) >= 1""")
    before_stock = stock(p['id'])
    before_orders = env.scalar(f"select count(*) from orders o join users u on u.id=o.buyer_id where u.email='{email}'")
    shop.add_to_cart(c, p)
    r, oid = shop.checkout(c, 'balance')
    rec.check(oid is None and r.path == '/checkout', f'went to {r.path}')
    msg = flashes(r)
    rec.check('Insufficient balance' in msg and 'available 0.00' in msg, f'error message: {msg}')
    rec.check(env.scalar(f"select count(*) from orders o join users u on u.id=o.buyer_id where u.email='{email}'") == before_orders, 'an order was created')
    rec.check(stock(p['id']) == before_stock, 'stock changed')
    rec.check(balance(email) == 0, 'balance changed')
    rec.check(p['title'] in c.get('/cart').text, 'cart was emptied')
    shop.empty_cart(c)


@scenario(1, 'Abandoned cart, return later: cart kept, prices revalidated, sold-out items flagged',
          'buyer07 adds two products and leaves; the session ends (new browser, log in again); meanwhile one price changes and one product sells out',
          'Cart is still there after logging in again; new price shown and charged; the sold-out item is flagged and removed', 'major')
def abandoned_cart(rec, ctx):
    email, c = buyer(7)
    p1 = instant_product()
    p2 = shop.product("""status='active' and delivery_type='instant' and currency='USD' and stock >= 1
        and (select count(*) from product_license_keys k where k.product_id=products.id and k.order_item_id is null) >= 1""", order='id desc')
    shop.add_to_cart(c, p1)
    shop.add_to_cart(c, p2)
    rec.step('buyer leaves; browser closed (session cookie gone)')
    new_price = p1['price'] + 500
    env.sql(f"update products set price_minor={new_price} where id={p1['id']}")
    env.sql(f"update products set stock=0 where id={p2['id']}")
    rec.step(f"price of {p1['id']} raised to {new_price}; {p2['id']} sold out")
    c2 = shop.login(email, who='buyer07-later')
    cart = c2.get('/cart')
    rec.check(p1['title'] in cart.text, 'cart was not kept for the returning buyer')
    rec.check(money(new_price) in cart.text, 'cart shows a stale price')
    rec.check('no longer available' in cart.text or 'Only 0' in cart.text or 'sold out' in cart.text.lower(), 'sold-out item not flagged')
    r, oid = shop.checkout(c2, 'crypto', 'BTC')
    rec.check(oid, f'checkout failed: {flashes(r)}')
    items = items_of(oid)
    rec.check(len(items) == 1 and items[0]['unit'] == new_price, f'charged {items}')
    shop.pay(oid)
    wait_status(oid, 'delivered')
    env.sql(f"update products set price_minor={p1['price']} where id={p1['id']}")


@scenario(1, 'Guest checkout', 'A visitor who is not logged in, with a product in the cart',
          'Not applicable by design (accounts are required for delivery and downloads): the visitor can fill a cart and is sent '
          'to log in at checkout; after logging in the cart is still there', 'minor')
def guest(rec, ctx):
    g = Client('guest')
    p = instant_product()
    r = shop.add_to_cart(g, p)
    rec.check('Added' in flashes(r), f'guest cannot add to cart: {flashes(r)}')
    r = g.get('/checkout', expect=None)
    rec.check(r.path == '/login', f'guest checkout reached {r.path}')
    r = g.submit(g.find_form(r, '/login'), {'email': 'buyer08@sim.test', 'password': shop.PASSWORD})
    rec.check(p['title'] in g.get('/cart').text, 'cart lost at login')
    r, oid = shop.checkout(g, 'crypto', 'BTC')
    rec.check(oid, f'checkout after login failed: {flashes(r)}')
    env.sql(f"update orders set status='cancelled' where public_id='{oid}'")
    rec.actual = 'Not applicable by design (decision recorded); the cart survives login and checkout continues.'


@scenario(1, 'Concurrent purchase of the last unit', 'buyer11 and buyer12 have the same product with stock 1 in the cart',
          'Exactly one order gets the unit; the other buyer gets a clear out-of-stock error; stock never negative', 'blocker')
def last_unit(rec, ctx):
    p = shop.product("""status='active' and delivery_type='instant' and currency='USD' and stock >= 1
        and (select count(*) from product_license_keys k where k.product_id=products.id and k.order_item_id is null) >= 1""", order='id desc')
    env.sql(f"update products set stock=1 where id={p['id']}")
    rec.step(f"product {p['id']} set to stock 1")
    clients = []
    for n in (11, 12):
        _, c = buyer(n)
        shop.add_to_cart(c, p)
        page = c.get('/checkout')
        clients.append((c, c.find_form(page, '/checkout')))
    results = parallel([lambda c=c, f=f: c.submit(f, {'payment_method': 'crypto', 'crypto': 'BTC', 'accept_terms': '1'}) for c, f in clients])
    orders = env.sql(f"select o.public_id, o.status from orders o join order_items i on i.order_id=o.id where i.product_id={p['id']} and o.status='pending'")
    rec.ev(f'orders: {orders}; responses: {[getattr(r, "path", r) for r in results]}')
    rec.check(len(orders) == 1, f'{len(orders)} orders got the last unit')
    rec.check(stock(p['id']) == 0, f"stock {stock(p['id'])}")
    loser = [r for r in results if not hasattr(r, 'path') or '/orders/' not in r.url]
    rec.check(len(loser) == 1 and 'sold out' in flashes(loser[0]), f'loser message: {[flashes(r) for r in loser if hasattr(r, "text")]}')
    shop.pay(orders[0][0])
    wait_status(orders[0][0], 'delivered')
