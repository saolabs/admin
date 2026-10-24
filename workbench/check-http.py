"""Exercise the live, disposable Laravel workbench. Never point this at a real host."""
from html.parser import HTMLParser
import http.cookiejar
import json
import sys
import urllib.error
import urllib.parse
import urllib.request

origin = (sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8769').rstrip('/')
assert urllib.parse.urlparse(origin).hostname in ('127.0.0.1', 'localhost'), 'Workbench must be loopback-only'
client = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

def request(path, method='GET', body=None, token=None, accept='application/json'):
    headers = {'Accept': accept}
    if body is not None: headers['Content-Type'] = 'application/json'
    if token: headers['X-CSRF-TOKEN'] = token
    req = urllib.request.Request(origin + path, headers=headers, method=method, data=None if body is None else json.dumps(body).encode())
    try:
        with client.open(req, timeout=10) as response:
            return response.status, response.read(), response.headers
    except urllib.error.HTTPError as error:
        return error.code, error.read(), error.headers

status, raw, headers = request('/control/settings?locale=en&content_locale=vi')
assert status == 200, ('editor', status)
page = json.loads(raw)
assert page['view'] == 'admin.pages.settings'
editor = page['data']['editor']; revision = editor['document']['revision']; token = editor['csrfToken']
assert editor['contentLocale'] == 'vi' and editor['canUpdate'] is True
assert 'no-store' in headers['Cache-Control']
patch = {'revision': revision, 'patch': {'values': {'contact': 'Disposable HTTP workbench'}, 'localized': {'vi': {'website_name': ''}}}}
status, _, _ = request('/control/settings/general', 'PUT', patch)
assert status == 419, ('missing csrf', status)
status, raw, _ = request('/control/settings/general', 'PUT', patch, token)
assert status == 200, ('write', status)
result = json.loads(raw)['data']
assert result['revision'] == revision + 1 and result['localized']['vi']['website_name'] == ''
status, _, _ = request('/control/settings/general', 'PUT', patch, token)
assert status == 409, ('stale revision', status)
status, _, _ = request('/control/settings/general', 'PUT', {'revision': revision + 1, 'patch': {'values': {'unknown_field': True}}}, token)
assert status == 422, ('invalid field', status)
status, raw, _ = request('/control/settings?locale=vi&content_locale=vi')
assert status == 200
editor = json.loads(raw)['data']['editor']
assert editor['fields'][0]['present'] is True and editor['fields'][0]['value'] == ''
assert editor['document']['revision'] == revision + 1
status, raw, _ = request('/control/settings?locale=vi', accept='text/html')
assert status == 200 and b'id="settings-save"' in raw and 'Ngôn ngữ nội dung'.encode() in raw
class Assets(HTMLParser):
    def __init__(self): super().__init__(); self.urls = []
    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'script' and attrs.get('src'): self.urls.append(attrs['src'])
        if tag == 'link' and attrs.get('rel') == 'stylesheet' and attrs.get('href'): self.urls.append(attrs['href'])
assets = Assets(); assets.feed(raw.decode())
assert assets.urls, 'SSR must include built resources'
for source in assets.urls:
    parsed = urllib.parse.urlparse(source)
    assert not parsed.hostname or parsed.hostname in ('localhost', '127.0.0.1'), ('external asset', source)
    status, _, _ = request(parsed.path, accept='*/*')
    assert status == 200, ('missing resource', source, status)
status, _, _ = request('/control/settings/not-a-group')
assert status == 404
status, raw, headers = request('/control/users?locale=vi&per_page=10')
assert status == 200
users = json.loads(raw)['data']['page']
assert len(users['rows']) == 10 and users['listing']['total'] == 27
assert users['rows'][0] == {'id': '1', 'name': 'Member 01', 'email': 'member1@example.test'}
assert 'password' not in raw.decode() and 'no-store' in headers['Cache-Control']
status, raw, _ = request(users['listing']['next'])
assert status == 200 and json.loads(raw)['data']['page']['rows'][0]['id'] == '11'
status, raw, _ = request('/control/users?q=outside')
assert status == 200 and json.loads(raw)['data']['page']['listing']['total'] == 0
status, _, _ = request('/control/users?sort=password')
assert status == 422
status, raw, _ = request('/control/users?locale=vi', accept='text/html')
assert status == 200 and 'Người dùng'.encode() in raw and b'id="resource-search"' in raw
print('Live Laravel HTTP: SSR/page-data 200, missing CSRF 419, save 200, stale revision 409, invalid field 422, unknown group 404; explicit empty translation retained.')
print('User directory HTTP: scoped rows/count, pagination, search, invalid sort 422 and Vietnamese SSR passed.')
