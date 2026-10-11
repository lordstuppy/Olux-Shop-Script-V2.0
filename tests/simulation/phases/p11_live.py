"""Phase 11: the notification centre, live updates and seller replies to
reviews, on the production stack. The people are registered through the real
sign-up and email verification, because every seeded buyer already has a role
in earlier phases. The JavaScript side of live updates (swapping the page,
toasts, sound, other tabs) is covered by tests/browser/live.spec.js; this
phase checks what the server answers through nginx and TLS."""
import re
import time
import urllib.parse

from simlib import env, shop
from simlib.record import scenario

from .common import admin, flashes, instant_product, staff_form, wait_status
from .p2_payments import post_webhook
from .p3_accounts import links, register

STAFF = 'support@sim.test'


def new_person(tag):
    email = f'p11-{tag}@sim.test'
    c, r = register(f'P11 {tag.title()}', email, who=f'p11-{tag}')
    c.get(links(env.wait_mail(email, 'Verify')[0]['ID'], '/email/verify/')[0])
    return email, c


def user_id(email):
    return env.scalar(f"select id from users where email='{email}'")


def entries(email, kind=None):
    extra = f" and kind='{kind}'" if kind else ''
    return env.sql(f"select id, kind, title, url, read_at is not null from user_notifications where user_id={user_id(email)}{extra} order by id")


def unread(email):
    return int(env.scalar(f'select count(*) from user_notifications where user_id={user_id(email)} and read_at is null'))


def badge(page):
    m = re.search(r'data-live-unread(\s+hidden)?\s*><span data-live-unread-count>(\d+)', page.text)
    return None if m is None else (0 if m.group(1) else int(m.group(2)))


def live(c, path):
    return c.raw('GET', '/live?path=' + urllib.parse.quote(path), headers={'Accept': 'application/json'})


def crypto_order(c, product):
    shop.add_to_cart(c, product)
    r, oid = shop.checkout(c, 'crypto', 'BTC')
    if oid is None:
        raise AssertionError(f'checkout failed: {flashes(r)}')
    return oid


def run_queued_now(pattern):
    env.sql(f"update jobs set available_at = extract(epoch from now())::int where reserved_at is null and payload like '%{pattern}%'")


@scenario(11, 'Notification centre: events, badge, open and read all',
          'A new buyer buys with crypto from a seller who turned sale emails off',
          'The buyer sees "placed" and "paid" in the list and the header badge; opening an entry marks it read and goes to the order; '
          '"mark all as read" clears the badge; the seller gets no sale email but the sale is in the seller list; emails still arrive', 'critical')
def centre(rec, ctx):
    p = instant_product()
    seller = env.scalar(f"select email from users where id={p['seller_id']}")
    s = shop.login(seller)
    s.form('/account', '/account/notifications', {'notify_sales': [], 'notify_reviews': '1', 'notification_sound': '1'})
    try:
        email, b = new_person('buyer')
        ctx.data['p11'] = {'email': email, 'client': b, 'product': p, 'seller': seller}
        oid = crypto_order(b, p)
        shop.pay(oid)
        wait_status(oid, 'delivered')
        env.wait_mail(email, f'Order {oid[:8]} paid', timeout=90)
        ctx.data['p11']['order'] = oid

        titles = [e[2] for e in entries(email, 'order')]
        rec.ev(f'buyer entries: {titles}')
        rec.check(any('placed' in t for t in titles) and any(f'Order {oid[:8]} paid' == t for t in titles), 'order entries missing')
        rec.check(any(oid[:8] in e[2] for e in entries(seller, 'sale')), 'sale not in the seller list')
        rec.check(not env.mails(seller, f'New sale: order {oid[:8]}'), 'sale email sent although turned off')

        home = b.get('/')
        rec.check(badge(home) == unread(email) > 0, f'badge {badge(home)} vs unread {unread(email)}')
        listing = b.get('/notifications')
        paid = next(e for e in entries(email, 'order') if e[2] == f'Order {oid[:8]} paid')
        rec.check(f'/notifications/{paid[0]}/open' in listing.text and 'is-unread' in listing.text, 'entry not listed as unread')
        r = b.get(f'/notifications/{paid[0]}/open')
        rec.check(r.path == f'/orders/{oid}', f'open went to {r.path}')
        rec.check(next(e for e in entries(email) if e[0] == paid[0])[4] == 't', 'opened entry still unread')
        b.form('/notifications', '/notifications/read-all')
        rec.check(unread(email) == 0 and badge(b.get('/')) == 0, 'read all did not clear the badge')
        rec.check(b.get(f'/notifications/{paid[0]}/open', expect=None).status in (200, 302), 'own entry')
        other_email, other = new_person('other')
        rec.check(other.get(f'/notifications/{paid[0]}/open', expect=None).status == 404, 'someone else opened the entry')
    finally:
        env.sql(f"update users set notify_sales=true, notify_reviews=true where email='{seller}'")


@scenario(11, 'The notification list does not depend on the mail server',
          'The mail server is down when support answers a ticket',
          'The buyer sees the reply in the notification list at once; the email follows once the mail server is back', 'major')
def without_mail(rec, ctx):
    ctx.allow_log(r'(?i)mail|smtp|connection|stream_socket|Expected response|refused|could not be established')
    email, b = new_person('ticket')
    b.form('/tickets/new', '/tickets', {'subject': 'Phase 11 ticket', 'category': 'support', 'body': 'Is the key ready?'})
    tid = env.scalar(f"select t.id from tickets t join users u on u.id=t.user_id where u.email='{email}'")
    env.stop('mail')
    rec.step('mail server down')
    try:
        staff_form(admin('support@sim.test'), f'/tickets/{tid}', f'/tickets/{tid}/messages', {'body': 'Yes, it is ready.'})
        rec.check(any(e[1] == 'ticket' for e in entries(email)), 'no ticket entry while mail is down')
        rec.check(badge(b.get('/')) == 1, 'badge does not show the reply')
    finally:
        env.start('mail')
    rec.step('mail server back')
    shop.wait_for(lambda: (run_queued_now('TicketReplyMail'), env.mails(email, 'New reply on ticket'))[1], 'ticket reply email', 120, 2)


@scenario(11, 'Staff alerts in the notification list of super admins and managers',
          'Forged payment callbacks arrive',
          'Super admin and manager see the alert in their list; support, finance and moderator do not', 'major')
def staff_entries(rec, ctx):
    ctx.allow_log(r'(?i)signature|rejected|webhook|callback')
    env.artisan('cache:forget', 'staff-alert:webhook-rejected')
    before = {e: len(entries(e, 'staff')) for e in ('superadmin@sim.test', 'manager@sim.test', 'support@sim.test', 'finance@sim.test', 'moderator@sim.test')}
    post_webhook('{"external_id": "00000000-0000-4000-8000-000000000001", "paid": true}', key='forged-key-p11', who='p11-forger')
    after = {e: len(entries(e, 'staff')) for e in before}
    rec.ev(f'staff entries before {before} after {after}')
    rec.check(after['superadmin@sim.test'] == before['superadmin@sim.test'] + 1 and after['manager@sim.test'] == before['manager@sim.test'] + 1,
              'super admin or manager has no entry')
    rec.check(all(after[e] == before[e] for e in ('support@sim.test', 'finance@sim.test', 'moderator@sim.test')), 'other staff got the alert')
    rec.check('callbacks are being rejected' in admin().get('/notifications').text, 'not on the super admin list page')


@scenario(11, 'Live updates endpoint through nginx and TLS',
          'Buyers, another buyer, a guest and staff ask /live about pages while an order is paid and a ticket is answered',
          'Guests get 401; the order version changes when the order is paid and matches the version on the page; balance and unread '
          'count are current; pages the user may not see have no version; internal staff notes do not change the customer version '
          'but a reply does; responses are not cached; polling is limited to 30 a minute', 'critical')
def live_endpoint(rec, ctx):
    from simlib.http import Client
    guest = live(Client('p11-guest'), '/')
    rec.check(guest.status == 401, f'guest got {guest.status}')

    email, b = new_person('live')
    oid = crypto_order(b, instant_product())
    path = f'/orders/{oid}'
    page = b.get(path)
    first = live(b, path)
    data = first.json()
    rec.check('no-store' in (first.headers.get('Cache-Control') or ''), f"cache-control {first.headers.get('Cache-Control')}")
    rec.check(data['version'] and f'data-live-version="{data["version"]}"' in page.text, 'page and /live disagree on the version')
    shop.pay(oid)
    wait_status(oid, 'delivered')
    later = live(b, path).json()
    rec.check(later['version'] != data['version'], 'paying did not change the version')
    rec.check(later['unread'] == unread(email) and later['latest'] and '/notifications/' in later['latest']['url'], f'unread/latest: {later}')
    rec.check(re.match(r'^\d+\.\d{2} [A-Z]{3}$', later['balance'] or ''), f"balance {later['balance']}")

    _, other = new_person('peek')
    for p in (path, '/admin', '/seller/sales', '/no/such/page', '//evil.example', 'relative'):
        rec.check(live(other, p).json()['version'] is None, f'{p} has a version for another user')
    rec.check(live(admin(), '/admin').json()['version'], 'staff dashboard has no version')

    b.form('/tickets/new', '/tickets', {'subject': 'Live ticket', 'category': 'support', 'body': 'Hello'})
    tid = env.scalar(f"select t.id from tickets t join users u on u.id=t.user_id where u.email='{email}'")
    tpath = f'/tickets/{tid}'
    v1 = live(b, tpath).json()['version']
    support = admin('support@sim.test')
    staff_form(support, tpath, f'/tickets/{tid}/messages', {'body': 'Checking the payment logs.', 'internal': '1'})
    rec.check(live(b, tpath).json()['version'] == v1, 'an internal note changed the customer version')
    staff_form(support, tpath, f'/tickets/{tid}/messages', {'body': 'All good now.'})
    rec.check(live(b, tpath).json()['version'] != v1, 'a reply did not change the customer version')

    _, flood = new_person('flood')
    codes = [live(flood, '/').status for _ in range(31)]
    rec.check(codes[:30] == [200] * 30 and codes[30] == 429, f'rate limit: {codes[-3:]}')


@scenario(11, 'Seller replies to a review', 'The buyer from the first scenario reviews the product; its seller answers, edits; another seller tries; '
          'a moderator removes the reply',
          'The seller is told about the review; the reply shows under the review; the reviewer gets one email and one list entry that '
          'links to the review; editing sends nothing; another seller is refused; the moderator can remove it', 'major')
def review_reply(rec, ctx):
    d = ctx.data.get('p11')
    rec.check(d, 'first phase 11 scenario did not run')
    p, b, email, seller = d['product'], d['client'], d['email'], d['seller']
    b.form(f"/products/{p['slug']}", f"/products/{p['slug']}/reviews", {'rating': '4', 'title': 'Solid tool', 'body': 'Does the job.'})
    rid = env.scalar(f"select r.id from product_reviews r join users u on u.id=r.user_id where u.email='{email}' and r.product_id={p['id']}")
    rec.check(rid, 'review not stored')
    env.wait_mail(seller, 'New 4-star review of')

    s = shop.login(seller)
    r = s.form('/seller/reviews', f'/seller/reviews/{rid}/reply', {'reply': 'Thank you! A faster version ships next week.'})
    rec.check('published' in flashes(r), f'reply: {flashes(r)}')
    page = shop.login(email).get(f"/products/{p['slug']}")
    rec.check(f'id="review-{rid}"' in page.text and 'A faster version ships next week.' in page.text and 'Reply from' in page.text, 'reply not shown')
    env.wait_mail(email, 'The seller replied to your review')
    entry = [e for e in entries(email, 'review')]
    rec.check(len(entry) == 1 and entry[0][3] == f"/products/{p['slug']}#review-{rid}", f'review entry: {entry}')

    s.form('/seller/reviews', f'/seller/reviews/{rid}/reply', {'reply': 'Thank you! The faster version is out.'})
    time.sleep(4)
    rec.check(len(env.mails(email, 'The seller replied to your review')) == 1 and len(entries(email, 'review')) == 1, 'edit was announced again')

    stranger = env.scalar(f"select email from users where role='seller' and id<>{p['seller_id']} order by id limit 1")
    r = shop.login(stranger).post(f'/seller/reviews/{rid}/reply', {'_method': 'PUT', 'reply': 'Buy mine instead'}, token_from='/seller/reviews')
    rec.check('only reply to reviews of your own products' in flashes(r), f'other seller: {flashes(r)}')

    mod = admin('moderator@sim.test')
    staff_form(mod, '/admin/reviews', f'/admin/reviews/{rid}/reply')
    rec.check(env.scalar(f'select seller_reply is null from product_reviews where id={rid}') == 't', 'reply not removed')
    rec.check(env.scalar(f"select count(*) from audit_log where action='review.reply_removed' and target_id='{rid}'") == '1', 'removal not audited')
