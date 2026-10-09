"""Browser-like HTTP client without JavaScript, for the simulation.

Each Client is one browser profile (own cookie jar). It loads pages, picks a
<form> (including inputs that join it through the HTML form attribute),
keeps hidden fields such as the CSRF token, fills fields and submits. Every
request and response is appended to a JSONL trace so a failure can be
replayed: method, URL, request headers and body, status, response headers,
body excerpt, duration and the shop's X-Request-Id.
"""
import html
import http.cookiejar
import json
import re
import ssl
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid
from html.parser import HTMLParser

BASE = 'https://localhost:8443'
CA = None
TRACE_PATH = None
_trace_lock = threading.Lock()
STATS = {'requests': 0, 'by_status': {}, 'server_errors': []}


def trace(entry):
    with _trace_lock:
        STATS['requests'] += 1
        STATS['by_status'][entry['status']] = STATS['by_status'].get(entry['status'], 0) + 1
        if isinstance(entry['status'], int) and entry['status'] >= 500:
            STATS['server_errors'].append({k: entry[k] for k in ('who', 'method', 'url', 'status', 'request_id')})
        if TRACE_PATH:
            with open(TRACE_PATH, 'a', encoding='utf-8') as f:
                f.write(json.dumps(entry) + '\n')


class FormParser(HTMLParser):
    """Collects forms, their fields, select options and inputs attached via form="id"."""

    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.forms = []
        self.by_id = {}
        self.cur = None
        self.select = None
        self.textarea = None
        self.detached = []  # (form id, [name, value]) for inputs outside their form

    def _target(self, attrs):
        fid = attrs.get('form')
        if fid:
            return ('detached', fid)
        return ('current', self.cur) if self.cur is not None else (None, None)

    def _add(self, attrs, field):
        kind, target = self._target(attrs)
        if kind == 'detached':
            self.detached.append((target, field))
        elif kind == 'current':
            target['fields'].append(field)

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == 'form':
            self.cur = {'id': a.get('id'), 'action': a.get('action', ''), 'method': a.get('method', 'get').lower(),
                        'enctype': a.get('enctype', ''), 'fields': [], 'choices': {}, 'optional': {}}
            self.forms.append(self.cur)
            if a.get('id'):
                self.by_id[a['id']] = self.cur
            return
        if tag == 'input':
            t = a.get('type', 'text').lower()
            name = a.get('name')
            if not name:
                return
            if t in ('checkbox', 'radio'):
                kind, target = self._target(a)
                if kind == 'current':
                    target['optional'].setdefault(name, []).append(a.get('value', 'on'))
                if 'checked' in a:
                    self._add(a, [name, a.get('value', 'on')])
            elif t not in ('submit', 'file', 'button', 'image', 'reset'):
                self._add(a, [name, a.get('value', '')])
        elif tag == 'select' and a.get('name'):
            self.select = {'attrs': a, 'name': a['name'], 'selected': None, 'first': None}
        elif tag == 'option' and self.select is not None:
            v = a.get('value', '')
            if self.cur is not None:
                self.cur['choices'].setdefault(self.select['name'], []).append(v)
            if self.select['first'] is None:
                self.select['first'] = v
            if 'selected' in a:
                self.select['selected'] = v
        elif tag == 'textarea' and a.get('name'):
            self.textarea = {'attrs': a, 'name': a['name'], 'text': ''}

    def handle_data(self, data):
        if self.textarea is not None:
            self.textarea['text'] += data

    def handle_endtag(self, tag):
        if tag == 'form':
            self.cur = None
        elif tag == 'select' and self.select is not None:
            sel = self.select
            value = sel['selected'] if sel['selected'] is not None else (sel['first'] or '')
            self._add(sel['attrs'], [sel['name'], value])
            self.select = None
        elif tag == 'textarea' and self.textarea is not None:
            self._add(self.textarea['attrs'], [self.textarea['name'], self.textarea['text']])
            self.textarea = None

    def close(self):
        super().close()
        for fid, field in self.detached:
            if fid in self.by_id:
                self.by_id[fid]['fields'].append(field)


def forms_of(body):
    p = FormParser()
    p.feed(body if isinstance(body, str) else '')
    p.close()
    return p.forms


def text_of(body):
    if not isinstance(body, str):
        return ''
    body = re.sub(r'<(script|style)[^>]*>.*?</\1>', ' ', body, flags=re.S)
    return re.sub(r'\s+', ' ', html.unescape(re.sub(r'<[^>]+>', ' ', body))).strip()


class Fail(AssertionError):
    pass


class Response:
    def __init__(self, status, headers, body, url, request_id, duration_ms):
        self.status = status
        self.headers = headers
        self.raw = body
        self.url = url
        self.request_id = request_id
        self.duration_ms = duration_ms
        ctype = headers.get('Content-Type') or ''
        self.text = body.decode('utf-8', 'replace') if any(t in ctype for t in ('text', 'json', 'xml')) else ''

    @property
    def path(self):
        return urllib.parse.urlparse(self.url).path

    def json(self):
        return json.loads(self.text)

    def flashes(self):
        found = re.findall(r'<div class="flash[^"]*"[^>]*>(.*?)</div>', self.text, flags=re.S)
        errs = re.findall(r'<span class="error-text"[^>]*>(.*?)</span>', self.text, flags=re.S)
        return [text_of(x) for x in found + errs]


class Client:
    """One browser profile: cookies on, JavaScript off, no extensions."""

    def __init__(self, who, timeout=60):
        self.who = who
        self.timeout = timeout
        self.jar = http.cookiejar.CookieJar()
        ctx = ssl.create_default_context(cafile=CA)

        class NoRedirect(urllib.request.HTTPRedirectHandler):
            def redirect_request(self, *a, **k):
                return None

        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar),
                                                  urllib.request.HTTPSHandler(context=ctx), NoRedirect)
        self.last = None

    def raw(self, method, path, data=None, headers=None):
        url = path if path.startswith('http') else BASE + path
        req = urllib.request.Request(url, data=data, method=method, headers=headers or {})
        req.add_header('User-Agent', 'sim/' + self.who)
        started = time.time()
        try:
            r = self.opener.open(req, timeout=self.timeout)
            status, hdrs, body = r.status, r.headers, r.read()
        except urllib.error.HTTPError as e:
            status, hdrs, body = e.code, e.headers, e.read()
        except Exception as e:  # connection refused, timeout: recorded, then re-raised
            trace({'t': started, 'who': self.who, 'method': method, 'url': url, 'status': 'neterr', 'request_id': '',
                   'error': repr(e), 'duration_ms': int((time.time() - started) * 1000)})
            raise
        duration = int((time.time() - started) * 1000)
        rid = hdrs.get('X-Request-Id', '')
        req_body = data.decode('utf-8', 'replace')[:4000] if isinstance(data, bytes) and b'\x00' not in data[:200] else (f'<{len(data)} bytes>' if data else '')
        trace({'t': started, 'who': self.who, 'method': method, 'url': url, 'request_headers': dict(req.header_items()),
               'request_body': req_body, 'status': status, 'response_headers': dict(hdrs.items()),
               'response_excerpt': body[:2000].decode('utf-8', 'replace') if b'\x00' not in body[:200] else f'<{len(body)} bytes>',
               'duration_ms': duration, 'request_id': rid})
        return Response(status, hdrs, body, url, rid, duration)

    def go(self, method, path, data=None, headers=None, follow=True):
        r = self.raw(method, path, data, headers)
        hops = 0
        while follow and r.status in (301, 302, 303, 307) and hops < 8:
            r = self.raw('GET', urllib.parse.urljoin(r.url, r.headers['Location']))
            hops += 1
        self.last = r
        return r

    def get(self, path, expect=200, follow=True):
        r = self.go('GET', path, follow=follow)
        if expect is not None and r.status != expect:
            raise Fail(f'{self.who}: GET {path} expected {expect}, got {r.status} ({r.request_id}): {text_of(r.text)[:300]}')
        return r

    def find_form(self, page, action_part, index=0, need=None):
        forms = forms_of(page.text)
        exact = [f for f in forms if urllib.parse.urlparse(html.unescape(f['action'])).path.endswith(action_part)]
        matches = exact or [f for f in forms if action_part in html.unescape(f['action'])]
        if need:
            matches = [f for f in matches if all(any(x[0] == k and x[1] == v for x in f['fields']) for k, v in need.items())]
        if len(matches) <= index:
            raise Fail(f'{self.who}: no form for {action_part!r} on {page.path}; forms: {[f["action"] for f in forms]}')
        return matches[index]

    def submit(self, form, fields=None, files=None, referer=None, follow=True):
        data = [list(x) for x in form['fields']]
        for k, v in (fields or {}).items():
            data = [x for x in data if x[0] != k]
            for one in (v if isinstance(v, list) else [v]):
                data.append([k, one])
        method = form['method'].upper()
        action = html.unescape(form['action'])
        if method == 'GET':
            q = urllib.parse.urlencode([(k, v) for k, v in data if k])
            return self.go('GET', action + ('&' if '?' in action else '?') + q, follow=follow)
        headers = {'Referer': referer or (self.last.url if self.last else BASE)}
        if files or form['enctype'] == 'multipart/form-data':
            boundary = uuid.uuid4().hex
            parts = [f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode() for k, v in data if k]
            for k, (fname, content, ctype) in (files or {}).items():
                parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"; filename="{fname}"\r\nContent-Type: {ctype}\r\n\r\n'.encode() + content + b'\r\n')
            parts.append(f'--{boundary}--\r\n'.encode())
            body = b''.join(parts)
            headers['Content-Type'] = f'multipart/form-data; boundary={boundary}'
        else:
            body = urllib.parse.urlencode([(k, v) for k, v in data if k]).encode()
            headers['Content-Type'] = 'application/x-www-form-urlencoded'
        return self.go('POST', action, body, headers, follow=follow)

    def form(self, page_path, action_part, fields=None, files=None, index=0, need=None, follow=True):
        page = self.get(page_path)
        return self.submit(self.find_form(page, action_part, index, need), fields, files, referer=page.url, follow=follow)

    def csrf(self, page_path='/'):
        page = self.get(page_path)
        m = re.search(r'name="_token" value="([^"]+)"', page.text)
        if not m:
            raise Fail(f'{self.who}: no CSRF token on {page_path}')
        return m.group(1)

    def post(self, path, fields, follow=True, token_from='/'):
        """Hand-made POST with this session's CSRF token, for requests no form offers."""
        fields = dict(fields)
        fields.setdefault('_token', self.csrf(token_from))
        body = urllib.parse.urlencode([(k, v) for k, vs in fields.items() for v in (vs if isinstance(vs, list) else [vs])]).encode()
        return self.go('POST', path, body, {'Content-Type': 'application/x-www-form-urlencoded', 'Referer': BASE + token_from}, follow=follow)
