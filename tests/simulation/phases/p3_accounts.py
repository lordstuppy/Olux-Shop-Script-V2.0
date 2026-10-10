"""Phase 3: account lifecycle, as a person with a browser and a mailbox."""
import copy
import hashlib
import hmac
import html
import os
import re
import time
import urllib.parse

from simlib import env, shop
from simlib.http import Client, forms_of
from simlib.record import scenario

from .common import flashes

NEW_PASSWORD = 'Brand-new-pass-2026'


def links(message_id, part):
    text = env.mail_text(message_id)
    return [html.unescape(u) for u in re.findall(r'https://localhost:8443/[^\s"<>\])]+', text) if part in u]


def register(name, email, password=shop.PASSWORD, who=None, wait=3.2):
    c = Client(who or email.split('@')[0])
    page = c.get('/register')
    time.sleep(wait)
    r = c.submit(c.find_form(page, '/register'), {'name': name, 'email': email, 'password': password,
                                                    'password_confirmation': password, 'accept_terms': '1'})
    return c, r


def app_key():
    path = os.path.join(env.WORK, 'app', '.env') if env.BACKEND == 'host' else os.path.join(env.HERE, 'sim.env')
    return re.search(r'^APP_KEY=(\S+)', open(path).read(), re.M).group(1)


def signed(url_without_signature):
    """Signs a URL the way Laravel's URL::signedRoute does (HMAC of the absolute URL with APP_KEY)."""
    return url_without_signature + ('&' if '?' in url_without_signature else '?') + 'signature=' + \
        hmac.new(app_key().encode(), url_without_signature.encode(), hashlib.sha256).hexdigest()


def uid(email):
    return env.scalar(f"select id from users where email='{email}'")


@scenario(3, 'Registration: valid, invalid and duplicate emails', 'Visitors on /register',
          'A valid address registers and gets a verification mail; malformed addresses are refused with a field error; '
          'an existing address (any letter case) is refused without revealing more; no 500s', 'critical')
def registration(rec, ctx):
    email = 'newbie01@sim.test'
    c, r = register('New Bie', email)
    rec.check(r.path == '/email/verify', f'after register: {r.path} {flashes(r)}')
    env.wait_mail(email, 'Verify')
    for bad in ['not-an-email', 'two@@sim.test', 'space in@sim.test', 'a' * 250 + '@sim.test', '<script>@sim.test', 'x@']:
        c2, r = register('Bad Email', bad, who='bad-email', wait=3.1)
        rec.check(r.path == '/register' and 'email' in flashes(r).lower(), f'{bad!r} not refused: {r.path} {flashes(r)}')
        rec.check(env.scalar(f"select count(*) from users where email='{bad.lower()}'") == '0', f'{bad!r} stored')
    for dup in ['buyer01@sim.test', 'BUYER01@SIM.TEST', ' Buyer01@sim.test ']:
        c3, r = register('Dupe', dup, who='dupe', wait=3.1)
        rec.check(r.path == '/register' and 'already exists' in flashes(r), f'duplicate {dup!r}: {r.path} {flashes(r)}')
    rec.check(env.scalar("select count(*) from users where lower(email)='buyer01@sim.test'") == '1', 'duplicate account created')
    _, r = register('Weak', 'weak01@sim.test', password='short', who='weak', wait=3.1)
    rec.check('at least 12' in flashes(r).lower() or 'password' in flashes(r).lower(), f'weak password: {flashes(r)}')
    ctx.data['newbie'] = (email, c)


@scenario(3, 'Verification link: valid, already used, expired, tampered', 'newbie01 registered and has the verification mail',
          'Valid link verifies; using it again is harmless; a tampered or expired link gets 403 and changes nothing', 'critical')
def verification(rec, ctx):
    email, c = ctx.data['newbie']
    msg = env.wait_mail(email, 'Verify')[0]
    link = links(msg['ID'], '/email/verify/')[0]
    rec.step(f'link {link[:90]}...')
    user_id = uid(email)
    tampered = re.sub(r'signature=[0-9a-f]+', 'signature=' + '0' * 64, link)
    rec.check(c.get(tampered, expect=None).status == 403, 'tampered signature accepted')
    other = link.replace(f'/email/verify/{user_id}/', f'/email/verify/{int(user_id) - 1}/')
    rec.check(c.get(other, expect=None).status in (403, 404), 'link for another user id accepted')
    base = link.split('?')[0]
    expired = signed(f"{base}?expires={int(time.time()) - 60}")
    rec.check(c.get(expired, expect=None).status == 403, 'expired (correctly signed) link accepted')
    rec.check(env.scalar(f"select email_verified_at is null from users where email='{email}'") == 't', 'verified by a bad link')
    r = c.get(link, expect=None)
    rec.check(r.status == 200, f'valid link: {r.status}')
    rec.check(env.scalar(f"select email_verified_at is not null from users where email='{email}'") == 't', 'not verified')
    r = c.get(link, expect=None)
    rec.check(r.status == 200, f'second use: {r.status}')
    guest = Client('stranger')
    r = guest.get(link, expect=None)
    rec.check(r.status in (200, 302) and '/login' in r.url, f'link opened while signed out: {r.status} {r.url}')


@scenario(3, 'Login: correct password, wrong password, unknown email', 'Seeded buyer23',
          'Correct password signs in; wrong password and unknown email give the same message; nothing reveals which', 'blocker')
def login_basic(rec, ctx):
    c = shop.login('buyer23@sim.test')
    rec.check(c.get('/account').status == 200, 'not signed in')
    msgs = []
    for email, pw in [('buyer23@sim.test', 'Wrong-password-123'), ('nobody99@sim.test', 'Wrong-password-123')]:
        x = Client('login-fail')
        r = x.form('/login', '/login', {'email': email, 'password': pw})
        rec.check(r.path == '/login', f'{email}: went to {r.path}')
        msgs.append(flashes(r))
    rec.check(msgs[0] == msgs[1] and 'incorrect' in msgs[0], f'messages differ or unclear: {msgs}')
    ctx.allow_log(r'Failed login')


@scenario(3, 'Lockout after 20 rapid failed logins', 'buyer24; an attacker tries 20 wrong passwords, first from one address, then spread over five',
          'From one address: throttled after 5 per minute with a clear 429 page, the right password is also refused while throttled. '
          'Across addresses: after 20 failures the account is locked for 15 minutes for every address, the owner is emailed once, '
          'a password reset unlocks it', 'critical')
def lockout(rec, ctx):
    email = 'buyer24@sim.test'
    ctx.allow_log(r'Failed login|locked')
    a = Client('attacker')
    statuses = []
    for i in range(20):
        r = a.form('/login', '/login', {'email': email, 'password': f'guess-{i}-aaaaaa'})
        statuses.append(r.status)
    rec.ev(f'single address statuses: {statuses}')
    rec.check(statuses.count(429) >= 14, f'not throttled: {statuses}')
    r = a.form('/login', '/login', {'email': email, 'password': shop.PASSWORD}) if a.get('/login', expect=None).status == 200 else a.last
    rec.check(r.status == 429 and 'Too many' in r.text, f'right password while throttled: {r.status}')
    rec.step('waiting 61 s for the per-minute window')
    time.sleep(61)
    # 20 failures spread over 5 source addresses (4 each, under every per-address limit).
    for n in range(5):
        x = Client(f'botnet-{n}', source=f'127.0.0.{10 + n}')
        for i in range(4):
            x.form('/login', '/login', {'email': email, 'password': f'spray-{n}-{i}-zz'})
    owner = Client('owner', source='127.0.0.20')
    r = owner.form('/login', '/login', {'email': email, 'password': shop.PASSWORD})
    rec.check('/login' in r.path and 'locked' in flashes(r).lower(), f'account not locked after 20 failures: {r.path} {flashes(r)}')
    env.wait_mail(email, 'sign-in')
    rec.check(env.scalar("select count(*) from audit_log where action='user.login_locked'") != '0', 'lock not audited')
    # The owner resets the password, which lifts the lock.
    owner.form('/forgot-password', '/forgot-password', {'email': email})
    msg = env.wait_mail(email, 'Reset')[0]
    link = links(msg['ID'], '/reset-password/')[0]
    owner.form(link.replace('https://localhost:8443', ''), '/reset-password', {'password': NEW_PASSWORD, 'password_confirmation': NEW_PASSWORD})
    c = shop.login(email, NEW_PASSWORD, who='owner-after-reset')
    rec.check(c.get('/account').status == 200, 'owner cannot sign in after reset')
    ctx.data['buyer24_password'] = NEW_PASSWORD


@scenario(3, 'Password reset', 'buyer25 forgot the password; two other sessions are open',
          'Same answer for known and unknown emails; link works once; old password stops working; other sessions are signed out', 'blocker')
def password_reset(rec, ctx):
    email = 'buyer25@sim.test'
    other = shop.login(email, who='buyer25-laptop')
    g = Client('forgetful')
    r1 = g.form('/forgot-password', '/forgot-password', {'email': email})
    r2 = Client('probe').form('/forgot-password', '/forgot-password', {'email': 'nobody77@sim.test'})
    rec.check(flashes(r1).replace(email, 'X') == flashes(r2).replace('nobody77@sim.test', 'X'), f'reset answers differ: {flashes(r1)} vs {flashes(r2)}')
    msg = env.wait_mail(email, 'Reset')[0]
    link = links(msg['ID'], '/reset-password/')[0]
    path = link.replace('https://localhost:8443', '')
    r = g.form(path, '/reset-password', {'password': NEW_PASSWORD, 'password_confirmation': NEW_PASSWORD})
    rec.check('/login' in r.path or 'reset' in flashes(r).lower(), f'reset: {r.path} {flashes(r)}')
    r = Client('reuse').form(path, '/reset-password', {'password': 'Another-pass-2026x', 'password_confirmation': 'Another-pass-2026x'})
    rec.check('invalid' in flashes(r).lower() or 'expired' in flashes(r).lower(), f'reset link reused: {flashes(r)}')
    x = Client('old-pw')
    r = x.form('/login', '/login', {'email': email, 'password': shop.PASSWORD})
    rec.check(r.path == '/login', 'old password still works')
    shop.login(email, NEW_PASSWORD, who='buyer25-new')
    r = other.get('/account', expect=None)
    rec.check('/login' in r.url, f'other session still signed in after reset: {r.url}')
    ctx.allow_log(r'Failed login')


@scenario(3, 'Password change', 'buyer26 signed in on two devices',
          'Wrong current password refused; weak new password refused; success signs out the other device; new password works', 'critical')
def password_change(rec, ctx):
    email = 'buyer26@sim.test'
    a = shop.login(email, who='buyer26-phone')
    b = shop.login(email, who='buyer26-laptop')
    r = a.form('/account', '/account/password', {'current_password': 'nope-nope-1234', 'password': NEW_PASSWORD, 'password_confirmation': NEW_PASSWORD})
    rec.check('incorrect' in flashes(r), f'wrong current password: {flashes(r)}')
    r = a.form('/account', '/account/password', {'current_password': shop.PASSWORD, 'password': 'abc', 'password_confirmation': 'abc'})
    rec.check('12' in flashes(r), f'weak password: {flashes(r)}')
    r = a.form('/account', '/account/password', {'current_password': shop.PASSWORD, 'password': NEW_PASSWORD, 'password_confirmation': NEW_PASSWORD})
    rec.check('Password changed' in flashes(r), f'change: {flashes(r)}')
    rec.check(a.get('/account').status == 200, 'changing device was signed out')
    rec.check('/login' in b.get('/account', expect=None).url, 'other device still signed in')
    shop.login(email, NEW_PASSWORD, who='buyer26-again')


@scenario(3, 'Email change', 'buyer27 changes the address to buyer27-new@sim.test',
          'Needs the password; confirmation goes to the new address, a notice to the old; the address changes only after '
          'confirming; the link is single use; an address already in use is refused', 'critical')
def email_change(rec, ctx):
    email, new = 'buyer27@sim.test', 'buyer27-new@sim.test'
    c = shop.login(email)
    r = c.form('/account', '/account/email', {'email': 'buyer28@sim.test', 'current_password': shop.PASSWORD})
    rec.check('already uses' in flashes(r), f'taken address: {flashes(r)}')
    r = c.form('/account', '/account/email', {'email': new, 'current_password': 'wrong-password-99'})
    rec.check('incorrect' in flashes(r), f'wrong password: {flashes(r)}')
    r = c.form('/account', '/account/email', {'email': new, 'current_password': shop.PASSWORD})
    rec.check('confirmation link' in flashes(r), flashes(r))
    env.wait_mail(email, '')
    msg = env.wait_mail(new, 'Confirm')[0]
    link = links(msg['ID'], '/account/email/confirm/')[0].replace('https://localhost:8443', '')
    rec.check(env.scalar(f"select email from users where id={uid(email)}") == email, 'changed before confirming')
    stranger = shop.login('buyer28@sim.test', who='buyer28')
    r = stranger.go('GET', link)
    if r.status == 200 and forms_of(r.text):
        r = stranger.submit(stranger.find_form(r, '/account/email/confirm/'))
    rec.check(env.scalar(f"select count(*) from users where email='{new}'") == '0', 'another account confirmed the change')
    r = c.form(link, '/account/email/confirm/')
    rec.check(new in flashes(r), f'confirm: {flashes(r)}')
    r = c.form(link, '/account/email/confirm/')
    rec.check('invalid' in flashes(r), f'link reused: {flashes(r)}')
    shop.login(new, who='buyer27-new')


@scenario(3, '2FA: enable, sign in, recovery codes, disable', 'buyer29 without 2FA',
          'Enabling needs a valid code; sign-in then asks for a code; a wrong code is refused; each recovery code works once; '
          'turning it off needs a code', 'critical')
def two_factor(rec, ctx):
    email = 'buyer29@sim.test'
    c = shop.login(email)
    page = c.get('/account/two-factor')
    if '/confirm-password' in page.path:
        shop.confirm_password(c)
        page = c.get('/account/two-factor')
    secret = re.search(r'Setup key: <span class="mono">([A-Z2-7 ]+)</span>', page.text).group(1).replace(' ', '')
    r = c.submit(c.find_form(page, '/account/two-factor'), {'code': '000000'})
    rec.check(env.scalar(f"select two_factor_confirmed_at is null from users where email='{email}'") == 't', 'wrong code enabled 2FA')
    page = c.get('/account/two-factor')
    r = c.submit(c.find_form(page, '/account/two-factor'), {'code': shop.fresh_totp(secret)})
    codes = re.findall(r'<li>([A-Za-z0-9]{5}-[A-Za-z0-9]{5})</li>', r.text)
    rec.check(len(codes) >= 8, f'recovery codes not shown: {flashes(r)}')
    x = Client('buyer29-2fa')
    r = x.form('/login', '/login', {'email': email, 'password': shop.PASSWORD})
    rec.check('/two-factor-challenge' in r.path, f'no 2FA challenge: {r.path}')
    r = x.form('/two-factor-challenge', '/two-factor-challenge', {'code': '123456'})
    rec.check('/two-factor-challenge' in r.path, 'wrong code accepted')
    r = x.form('/two-factor-challenge', '/two-factor-challenge', {'code': codes[0]})
    rec.check(x.get('/account', expect=None).path == '/account', f'recovery code refused: {flashes(r)}')
    y = Client('buyer29-reuse')
    y.form('/login', '/login', {'email': email, 'password': shop.PASSWORD})
    r = y.form('/two-factor-challenge', '/two-factor-challenge', {'code': codes[0]})
    rec.check('/two-factor-challenge' in r.path or '/login' in r.path, 'recovery code worked twice')
    z = Client('buyer29-totp')
    z.form('/login', '/login', {'email': email, 'password': shop.PASSWORD})
    z.form('/two-factor-challenge', '/two-factor-challenge', {'code': shop.fresh_totp(secret)})
    rec.check(z.get('/account', expect=None).path == '/account', 'TOTP sign-in failed')
    page = c.get('/account/two-factor')
    r = c.submit(c.find_form(page, '/account/two-factor', need={'_method': 'DELETE'}), {'code': codes[1]})
    if '/confirm-password' in r.path:
        shop.confirm_password(c)
        page = c.get('/account/two-factor')
        r = c.submit(c.find_form(page, '/account/two-factor', need={'_method': 'DELETE'}), {'code': codes[1]})
    rec.check(env.scalar(f"select two_factor_confirmed_at is null from users where email='{email}'") == 't', f'2FA not turned off: {flashes(r)}')
    ctx.allow_log(r'(?i)two-factor|Failed login')


@scenario(3, 'Profile update', 'buyer30 signed in', 'Name updated and shown; empty or 81-character names refused', 'minor')
def profile(rec, ctx):
    c = shop.login('buyer30@sim.test')
    r = c.form('/account', '/account/profile', {'name': 'Renamed Thirty'})
    rec.check('updated' in flashes(r) and env.scalar("select name from users where email='buyer30@sim.test'") == 'Renamed Thirty', flashes(r))
    for bad in ['', 'x' * 81]:
        r = c.form('/account', '/account/profile', {'name': bad})
        rec.check(env.scalar("select name from users where email='buyer30@sim.test'") == 'Renamed Thirty', f'{len(bad)}-char name stored')


@scenario(3, 'Account deletion', 'n/a', 'Not applicable: account self-deletion is not offered (decision recorded); '
          'support handles erasure requests (see the data retention page)', 'minor')
def deletion(rec, ctx):
    r = Client('guest').get('/data-retention')
    rec.check(r.status == 200, 'data retention page missing')
    rec.actual = 'Not applicable by decision; the data retention page explains how to request erasure.'


@scenario(3, 'Sessions: logout, expiry, fixation resistance', 'buyer31',
          'Logout ends the session server-side (old cookie no longer works); an idle session older than the lifetime is refused; '
          'the session id changes at login so a planted id is useless', 'blocker')
def sessions(rec, ctx):
    email = 'buyer31@sim.test'
    c = Client('buyer31')
    c.get('/login')
    before = {k.name: k.value for k in c.jar}
    planted = [v for k, v in before.items() if 'session' in k]
    shop_c = c
    shop_c.form('/login', '/login', {'email': email, 'password': shop.PASSWORD})
    after = {k.name: k.value for k in c.jar}
    rec.check(planted and all(v not in after.values() for v in planted), 'session id not rotated at login')
    attacker = Client('fixation')
    for cookie in c.jar:
        if 'session' in cookie.name:
            old = copy.copy(cookie)
            old.value = planted[0]
            attacker.jar.set_cookie(old)
    rec.check('/login' in attacker.get('/account', expect=None).url, 'pre-login session id is signed in')
    stolen = [copy.copy(k) for k in c.jar]
    r = c.post('/logout', {}, token_from='/account')
    rec.check('/login' in c.get('/account', expect=None).url, 'still signed in after logout')
    replay = Client('replay')
    for k in stolen:
        replay.jar.set_cookie(k)
    rec.check('/login' in replay.get('/account', expect=None).url, 'cookie from before logout still works')
    d = shop.login(email, who='buyer31-idle')
    env.sql(f"update sessions set last_activity=extract(epoch from now())::int - 3*3600 where user_id={uid(email)}")
    rec.step('session made idle for 3 hours (lifetime 120 minutes)')
    rec.check('/login' in d.get('/account', expect=None).url, 'expired session still signed in')


@scenario(3, 'Two devices at once', 'buyer32 signs in on a phone and a laptop',
          'Both work independently; the account page lists both; signing out the other session from the phone ends the laptop session only', 'major')
def two_devices(rec, ctx):
    email = 'buyer32@sim.test'
    phone = shop.login(email, who='buyer32-phone')
    laptop = shop.login(email, who='buyer32-laptop')
    p1 = instant = shop.product("status='active' and stock is null and currency='USD'")
    shop.add_to_cart(phone, p1)
    rec.check(laptop.get('/account').status == 200 and phone.get('/account').status == 200, 'one device lost its session')
    page = phone.get('/account')
    rec.check(page.text.count('sim/buyer32-') >= 2, 'account page does not list both sessions')
    r = phone.submit(phone.find_form(page, '/account/sessions', need={'_method': 'DELETE'}))
    if '/confirm-password' in r.path:
        shop.confirm_password(phone)
        page = phone.get('/account')
        r = phone.submit(phone.find_form(page, '/account/sessions', need={'_method': 'DELETE'}))
    rec.check('Signed out 1 other session' in flashes(r), flashes(r))
    rec.check('/login' in laptop.get('/account', expect=None).url, 'laptop still signed in')
    rec.check(phone.get('/account').status == 200, 'phone signed out too')
    rec.check(instant['title'] in phone.get('/cart').text, 'cart lost')
