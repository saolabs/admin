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
assert page['data']['uiLocale'] == 'en'
assert page['data']['account']['name'] == 'Workbench Operator'
assert page['data']['account']['initials'] == 'WO' and page['data']['account']['logoutUrl'] == ''
assert 'password' not in page['data']['account'] and 'api_token' not in page['data']['account']
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

api = '/control/_api/languages'
status, raw, _ = request('/control/languages?locale=vi', accept='text/html')
assert status == 200 and b'id="language-save"' in raw
status, raw, _ = request(api)
assert status == 200
language_editor = json.loads(raw)['editor']; document = language_editor['document']; token = language_editor['csrfToken']
original_defaults = {key: document[key] for key in ('uiDefault', 'uiFallback', 'contentDefault')}
def configuration(document):
    return {**document, 'locales': [{key: row[key] for key in ('code', 'name', 'uiEnabled', 'contentEnabled')} for row in document['locales']]}
body = configuration(document)
if not any(row['code'] == 'ja' for row in body['locales']):
    body['locales'].append({'code': 'ja', 'name': '日本語', 'uiEnabled': True, 'contentEnabled': True})
body['contentDefault'] = 'ja'
status, _, _ = request(api, 'PUT', body)
assert status == 419, ('locale csrf', status)
status, raw, _ = request(api, 'PUT', body, token)
assert status == 200, ('add locale', status)
document = json.loads(raw)['editor']['document']
status, _, _ = request(api, 'PUT', body, token)
assert status == 409, ('locale revision', status)
status, raw, _ = request(api + '/ja/export', 'POST', {}, token)
assert status == 200
export = json.loads(raw)['export']
assert isinstance(export['tree']['admin'], dict) and 'users' in export['tree']['admin']
status, _, _ = request(api + '/ja/preview', 'POST', {'exportBatch': export['batch'], 'json': '{"admin":{"users":{"title":"A","title":"B"}}}'}, token)
assert status == 422, ('strict duplicate keys', status)
status, raw, _ = request(api + '/ja/preview', 'POST', {'exportBatch': export['batch'], 'json': '{"admin":{"users":{"title":"ユーザー"}}}'}, token)
assert status == 200
batch = json.loads(raw)['preview']['batch']
status, raw, _ = request(api + '/batches/' + batch, 'POST', {'publish': False}, token)
assert status == 200 and json.loads(raw)['preview']['status'] == 'draft'
status, raw, _ = request(api + '/batches/' + batch, 'POST', {'publish': True}, token)
assert status == 200 and json.loads(raw)['preview']['status'] == 'published'
status, raw, _ = request('/control/languages?locale=vi', accept='text/html')
assert status == 200 and batch.encode() in raw and 'Đã công bố'.encode() in raw, ('SSR with language history', status)
status, raw, _ = request('/saola/languages/admin/ja')
assert status == 200 and json.loads(raw)['messages']['admin']['users']['title'] == 'ユーザー'
body = configuration(document); body.update({'uiDefault': 'ja', 'uiFallback': 'vi'})
status, raw, _ = request(api, 'PUT', body, token)
assert status == 200
document = json.loads(raw)['editor']['document']
status, raw, _ = request('/control?locale=ja')
assert status == 200 and json.loads(raw)['i18n']['fallbackLocale'] == 'vi'
body = configuration(document); body.update(original_defaults)
status, _, _ = request(api, 'PUT', body, token)
assert status == 200
print('Language management HTTP: locale metadata/defaults CAS + CSRF, pure JSON export, strict preview, draft/publish and live Japanese dictionary passed; original defaults restored.')

status, raw, _ = request('/control/settings?group=system-display&locale=vi&content_locale=unknown')
assert status == 200
shared_editor = json.loads(raw)['data']['editor']; shared_doc = shared_editor['document']
assert json.loads(raw)['data']['currentPage'] == 'settings.system'
settings_navigation = next(item for item in json.loads(raw)['data']['navigation'] if item['key'] == 'settings')
assert settings_navigation['open'] is True and settings_navigation['children'][1]['active'] is True
assert len(shared_editor['tabs']) == 2 and all(tab['category'] == 'system' for tab in shared_editor['tabs'])
assert shared_editor['localizedGroup'] is False and shared_editor['contentLocale'] == '' and shared_doc['contentLocales'] == []
assert len(shared_editor['categories']) == 2 and sum(len(category['groups']) for category in shared_editor['categories']) == 5
shared_fields = {field['key']: field for field in shared_editor['fields']}
page_size = shared_fields['items_per_page']['value']; changed_size = 21 if page_size != 21 else 22
status, raw, _ = request('/control/settings/system-display', 'PUT', {'revision': shared_doc['revision'], 'patch': {'values': {'items_per_page': changed_size}}}, token)
assert status == 200
shared_revision = json.loads(raw)['data']['revision']
for language in ('en', 'vi'):
    status, raw, _ = request('/control/settings?group=system-display&locale=' + language)
    shared = json.loads(raw)['data']['editor']
    assert status == 200 and shared['document']['revision'] == shared_revision and shared['document']['values']['items_per_page'] == changed_size
status, _, _ = request('/control/settings/system-display', 'PUT', {'revision': shared_revision, 'patch': {'localized': {'vi': {'items_per_page': 30}}}}, token)
assert status == 422
status, _, _ = request('/control/settings/system-display', 'PUT', {'revision': shared_revision, 'patch': {'values': {'items_per_page': page_size}}}, token)
assert status == 200
status, raw, _ = request('/control/settings?group=system-display&locale=vi', accept='text/html')
assert status == 200 and b'id="settings-content-locale"' not in raw
assert b'sa-admin-settings-tabs' in raw and b'id="settings-groups-toggle"' not in raw
assert b'id="admin-submenu-settings"' in raw
status, raw, _ = request('/control/settings?group=seo&locale=vi')
seo_editor = json.loads(raw)['data']['editor']
assert status == 200 and seo_editor['localizedGroup'] is True
assert {'meta_title', 'meta_description', 'meta_keywords', 'og_title', 'og_description', 'og_image_url', 'indexable'} == {field['key'] for field in seo_editor['fields']}
print('Settings groups HTTP: five subgroups, one shared system value set across UI locales, localized system writes 422, no content-language selector for shared groups, SEO fields passed.')

# Media writes use multipart for bytes and raw JSON for metadata. This fixture
# belongs only to the disposable workbench, and ends in recoverable trash.
import base64
import uuid
boundary = 'saola-' + uuid.uuid4().hex
filename = 'http-media-' + uuid.uuid4().hex + '.png'
image = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a3e0AAAAASUVORK5CYII=')
multipart = ('--' + boundary + '\r\nContent-Disposition: form-data; name="file"; filename="' + filename + '"\r\nContent-Type: image/png\r\n\r\n').encode() + image + ('\r\n--' + boundary + '--\r\n').encode()

def media_upload(csrf=None):
    headers = {'Accept': 'application/json', 'Content-Type': 'multipart/form-data; boundary=' + boundary}
    if csrf: headers['X-CSRF-TOKEN'] = csrf
    req = urllib.request.Request(origin + '/control/_api/media', headers=headers, method='POST', data=multipart)
    try:
        with client.open(req, timeout=10) as response: return response.status, response.read()
    except urllib.error.HTTPError as error: return error.code, error.read()

status, raw, _ = request('/control/media?locale=vi')
assert status == 200 and json.loads(raw)['view'] == 'admin.pages.media'
media_editor = json.loads(raw)['data']['mediaEditor']; token = media_editor['csrfToken']
assert media_upload()[0] == 419
status, raw = media_upload(token)
assert status == 201, ('media upload', status, raw[:100])
media = json.loads(raw)['item']; item_api = '/control/_api/media/' + media['id']
assert media['mime'] == 'image/png' and media['width'] == 1 and media['revision'] == 1
status, content, headers = request(media['url'], accept='image/png')
assert status == 200 and content == image and headers['X-Content-Type-Options'] == 'nosniff'
status, raw, _ = request('/control/_api/media?q=&kind=image&status=active&page=1')
assert status == 200 and any(item['id'] == media['id'] for item in json.loads(raw)['data']['items'])
status, raw, _ = request(item_api, 'PUT', {'revision': 1, 'changes': {'title': filename, 'alt': '', 'caption': 'Line 1\nLine 2'}}, token)
assert status == 200 and json.loads(raw)['item']['alt'] == '' and json.loads(raw)['item']['caption'] == 'Line 1\nLine 2'
assert request(item_api, 'PUT', {'revision': 1, 'changes': {'title': 'Stale'}}, token)[0] == 409
assert request(item_api + '/trash', 'POST', {'revision': 2}, token)[0] == 200
assert request(media['url'], accept='image/png')[0] == 200
status, raw, _ = request('/control/_api/media?status=trash&q=' + urllib.parse.quote(filename))
assert status == 200 and json.loads(raw)['data']['total'] == 1
assert request(item_api + '/restore', 'POST', {'revision': 3}, token)[0] == 200
status, raw, _ = request('/control/settings?group=branding&locale=vi')
branding = json.loads(raw)['data']['editor']
assert status == 200 and branding['fields'][0]['media'] is True and branding['mediaEditor']['permissions']['view'] is True
assert request(item_api + '/trash', 'POST', {'revision': 4}, token)[0] == 200
print('Media HTTP: multipart CSRF 419/upload 201, detected MIME/public bytes, empty search, metadata CAS 409, empty alt/newline, trash/restore and settings picker metadata passed.')
