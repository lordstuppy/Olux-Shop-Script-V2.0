"""Form-aware HTTP client for the live test. Behaves like a browser without
JavaScript: it loads a page, picks a <form>, keeps its hidden fields (CSRF
token, _method, form tokens), fills the given fields and submits it."""
import http.cookiejar, urllib.request, urllib.parse, urllib.error, ssl, re, uuid, html, json, time, base64, hmac, hashlib, struct
from html.parser import HTMLParser

BASE = 'https://localhost:8443'
CA = None
REQUEST_LOG = []  # (who, method, path, status, request_id)


class FormParser(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.forms = []
        self.cur = None
        self.select = None
        self.textarea = None

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == 'form':
            self.cur = {'action': a.get('action', ''), 'method': a.get('method', 'get').lower(),
                        'enctype': a.get('enctype', ''), 'fields': [], 'buttons': []}
            self.forms.append(self.cur)
        elif self.cur is None:
            return
        elif tag == 'input':
            t = a.get('type', 'text').lower()
            if t in ('checkbox', 'radio'):
                if 'checked' in a:
                    self.cur['fields'].append([a.get('name'), a.get('value', 'on')])
                self.cur.setdefault('choices', {}).setdefault(a.get('name'), []).append(a.get('value', 'on'))
            elif t == 'submit':
                if a.get('name'):
                    self.cur['buttons'].append((a.get('name'), a.get('value', '')))
            elif t != 'file' and a.get('name'):
                self.cur['fields'].append([a.get('name'), a.get('value', '')])
        elif tag == 'button' and a.get('name'):
            self.cur['buttons'].append((a.get('name'), a.get('value', '')))
        elif tag == 'select' and a.get('name'):
            self.select = [a.get('name'), None, None]
        elif tag == 'option' and self.select is not None:
            v = a.get('value', '')
            if self.cur is not None:
                self.cur.setdefault('choices', {}).setdefault(self.select[0], []).append(v)
            if self.select[2] is None:
                self.select[2] = v
            if 'selected' in a:
                self.select[1] = v
        elif tag == 'textarea' and a.get('name'):
            self.textarea = [a.get('name'), '']

    def handle_data(self, data):
        if self.textarea is not None:
            self.textarea[1] += data

    def handle_endtag(self, tag):
        if tag == 'form':
            self.cur = None
        elif tag == 'select' and self.select is not None and self.cur is not None:
            name, sel, first = self.select
            self.cur['fields'].append([name, sel if sel is not None else (first or '')])
            self.select = None
        elif tag == 'textarea' and self.textarea is not None and self.cur is not None:
            self.cur['fields'].append(self.textarea)
            self.textarea = None


def forms_of(body):
    p = FormParser()
    p.feed(body)
    return p.forms


def text_of(body):
    body = re.sub(r'<(script|style)[^>]*>.*?</\1>', ' ', body, flags=re.S)
    return re.sub(r'\s+', ' ', html.unescape(re.sub(r'<[^>]+>', ' ', body))).strip()


def totp(secret, offset=0):
    key = base64.b32decode(secret.replace(' ', '').upper() + '=' * (-len(secret.replace(' ', '')) % 8))
    step = int(time.time() // 30) + offset
    h = hmac.new(key, struct.pack('>Q', step), hashlib.sha1).digest()
    o = h[-1] & 15
    return '%06d' % ((struct.unpack('>I', h[o:o + 4])[0] & 0x7fffffff) % 1000000)


class Fail(Exception):
    pass


class Client:
    def __init__(self, who):
        self.who = who
        self.jar = http.cookiejar.CookieJar()
        ctx = ssl.create_default_context(cafile=CA)

        class NoRedirect(urllib.request.HTTPRedirectHandler):
            def redirect_request(self, *a, **k):
                return None
        self.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar),
                                              urllib.request.HTTPSHandler(context=ctx), NoRedirect)
        self.last = None

    def raw(self, method, path, data=None, headers=None):
        url = path if path.startswith('http') else BASE + path
        req = urllib.request.Request(url, data=data, method=method, headers=headers or {})
        req.add_header('User-Agent', 'live-test/' + self.who)
        try:
            r = self.op.open(req, timeout=60)
            status, hdrs, body = r.status, r.headers, r.read()
        except urllib.error.HTTPError as e:
            status, hdrs, body = e.code, e.headers, e.read()
        rid = hdrs.get('X-Request-Id', '')
        REQUEST_LOG.append((self.who, method, urllib.parse.urlparse(url).path, status, rid))
        if status >= 500:
            raise Fail(f'{self.who}: {method} {url} -> {status} (request id {rid})\n{text_of(body.decode("utf-8", "replace"))[:600]}')
        return status, hdrs, body

    def go(self, method, path, data=None, headers=None, follow=True, expect=None):
        status, hdrs, body = self.raw(method, path, data, headers)
        hops = 0
        while follow and status in (301, 302, 303, 307) and hops < 8:
            loc = hdrs['Location']
            status, hdrs, body = self.raw('GET', loc)
            path = loc
            hops += 1
        ctype = hdrs.get('Content-Type') or ''
        text = body.decode('utf-8', 'replace') if any(t in ctype for t in ('text', 'json', 'xml')) else body
        self.last = (status, path, text, hdrs)
        if expect is not None and status != expect:
            snippet = text_of(text)[:800] if isinstance(text, str) else repr(text[:200])
            raise Fail(f'{self.who}: {method} {path} expected {expect}, got {status}\n{snippet}')
        return status, text, hdrs

    def get(self, path, expect=200, **kw):
        return self.go('GET', path, expect=expect, **kw)

    def form(self, page_path, action_part, fields=None, files=None, expect=200, button=None, index=0, page=None, follow=True):
        """Loads page_path (unless page html is given), finds the index-th form whose
        action contains action_part, fills fields and submits it."""
        if page is None:
            _, page, _ = self.get(page_path)
        all_forms = forms_of(page)
        matches = [f for f in all_forms if urllib.parse.urlparse(html.unescape(f['action'])).path.endswith(action_part)]
        if not matches:
            matches = [f for f in all_forms if action_part in html.unescape(f['action'])]
        if len(matches) <= index:
            acts = [f['action'] for f in forms_of(page)]
            raise Fail(f'{self.who}: no form with action containing {action_part!r} on {page_path}; forms: {acts}')
        f = matches[index]
        data = [list(x) for x in f['fields']]
        for k, v in (fields or {}).items():
            vals = v if isinstance(v, list) else [v]
            data = [x for x in data if x[0] != k]
            for one in vals:
                data.append([k, one])
        if button:
            data.append(list(button))
        method = f['method'].upper()
        action = html.unescape(f['action'])
        if method == 'GET':
            q = urllib.parse.urlencode([(k, v) for k, v in data if k])
            return self.go('GET', action + ('&' if '?' in action else '?') + q, expect=expect, follow=follow)
        if files or f['enctype'] == 'multipart/form-data':
            boundary = uuid.uuid4().hex
            parts = []
            for k, v in data:
                if k is None:
                    continue
                parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode())
            for k, (fname, content, ctype) in (files or {}).items():
                parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"; filename="{fname}"\r\nContent-Type: {ctype}\r\n\r\n'.encode() + content + b'\r\n')
            parts.append(f'--{boundary}--\r\n'.encode())
            body = b''.join(parts)
            headers = {'Content-Type': f'multipart/form-data; boundary={boundary}', 'Referer': BASE + page_path}
        else:
            body = urllib.parse.urlencode([(k, v) for k, v in data if k]).encode()
            headers = {'Content-Type': 'application/x-www-form-urlencoded', 'Referer': BASE + page_path}
        return self.go('POST', action, body, headers, expect=expect, follow=follow)

    def flash(self):
        """Returns flash/alert texts of the last page."""
        status, path, page, _ = self.last
        found = re.findall(r'<div class="flash[^"]*"[^>]*>(.*?)</div>', page, flags=re.S)
        errs = re.findall(r'<(?:p|span|li) class="(?:field-error|error)[^"]*"[^>]*>(.*?)</(?:p|span|li)>', page, flags=re.S)
        return [text_of(x) for x in found + errs]

    def expect_flash(self, needle):
        fl = self.flash()
        if not any(needle.lower() in x.lower() for x in fl):
            status, path, page, _ = self.last
            raise Fail(f'{self.who}: expected flash containing {needle!r} after {path}; got {fl}\n{text_of(page)[:400] if isinstance(page, str) else ""}')
        return fl
