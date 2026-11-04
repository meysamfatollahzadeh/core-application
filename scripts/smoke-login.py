#!/usr/bin/env python3
"""Exercise real OIDC, Nuxt SSR/cookies, source isolation and both logout directions."""
from concurrent.futures import ThreadPoolExecutor
from html.parser import HTMLParser
from http.cookiejar import CookieJar, DefaultCookiePolicy
from pathlib import Path
import json
import subprocess
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
VALUES = dict(line.split('=', 1) for line in (ROOT / '.env').read_text().splitlines() if '=' in line and not line.startswith('#'))
ORIGIN = 'http://localhost:8081'

class Forms(HTMLParser):
    def __init__(self, html):
        super().__init__()
        self.forms = []
        self.current = None
        self.feed(html)

    def handle_starttag(self, tag, attributes):
        attributes = dict(attributes)
        if tag == 'form':
            self.current = {'action': attributes.get('action', ''), 'id': attributes.get('id'), 'fields': {}}
            self.forms.append(self.current)
        if tag == 'input' and self.current is not None and attributes.get('name'):
            self.current['fields'][attributes['name']] = attributes.get('value', '')

    def handle_endtag(self, tag):
        if tag == 'form':
            self.current = None

class Browser:
    def __init__(self):
        self.cookies = CookieJar(policy=DefaultCookiePolicy(secure_protocols=('https', 'wss', 'http')))
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.cookies))

    def request(self, path, data=None, csrf=None, form=False, expected=200, origin=ORIGIN):
        url = urllib.parse.urljoin(ORIGIN, path)
        assert urllib.parse.urlsplit(url).netloc in ['localhost:8081', 'localhost:8083'], 'Unexpected external application endpoint'
        headers = {}
        if data is not None:
            headers.update({'Origin': origin, 'Content-Type': 'application/x-www-form-urlencoded' if form else 'application/json'})
        if csrf:
            headers['X-CSRF-Token'] = csrf
        body = (urllib.parse.urlencode(data).encode() if form else json.dumps(data).encode()) if data is not None else None
        try:
            response = self.opener.open(urllib.request.Request(url, data=body, headers=headers), timeout=45)
        except urllib.error.HTTPError as error:
            response = error
        assert response.status == expected, f'Unexpected status {response.status} (expected {expected}) at {urllib.parse.urlsplit(response.geturl()).path}'
        content = response.read().decode()
        return content, response

    def identity(self):
        return json.loads(self.request('/gateway/login/me')[0])

    def login(self, slug):
        state = self.identity()
        result = json.loads(self.request('/gateway/login/sources/' + slug + '/authorize', {}, csrf=state['csrf'])[0])
        page, response = self.request(result['url'])
        forms = Forms(page).forms
        login = next((form for form in forms if form['id'] == 'kc-form-login'), None)
        if login:
            login['fields'].update({'username': VALUES['DEMO_USERNAME'], 'password': VALUES['DEMO_PASSWORD']})
            self.request(urllib.parse.urljoin(response.geturl(), login['action']), login['fields'], form=True)
        state = self.identity()
        assert state['status'] == 'authenticated' and state['identity']['source']['slug'] == slug, 'Source login failed for ' + slug + ': ' + state['status']
        credential = next(cookie for cookie in self.cookies if cookie.name == 'organization_credential')
        assert credential.has_nonstandard_attr('HttpOnly') and credential.get_nonstandard_attr('SameSite') == 'Lax', 'Unprotected credential cookie'
        assert not any(cookie.name in ['laravel_session', 'organization_session'] for cookie in self.cookies), 'Legacy session cookie present'
        return state

    def assert_ssr(self, expected):
        html, response = self.request('/')
        assert 'no-store' in response.headers.get('Cache-Control', ''), 'Authenticated HTML cached'
        assert 'خروج از حساب' in html and 'ورود با حساب سازمانی' not in html, 'Initial HTML has anonymous login flash'
        assert expected['identity']['source']['title'] in html, 'SSR source isolation failed'
        for secret in ['access_token', 'refresh_token', 'id_token_hint', 'Bearer ', VALUES['OIDC_CLIENT_SECRET'], VALUES['SECONDARY_OIDC_CLIENT_SECRET']]:
            assert secret not in html, 'Credential leaked into SSR'
        return True

    def logout(self):
        state = self.identity()
        result = json.loads(self.request('/gateway/login/logout', {}, csrf=state['csrf'])[0])
        page, _ = self.request(result['url'])
        form = next(form for form in Forms(page).forms if form['id'] == 'logout-form')
        assert 'id_token_hint' not in form['action'], 'ID token in logout URL'
        self.request(form['action'], form['fields'], form=True)
        assert self.identity()['status'] == 'anonymous', 'Local logout failed'


def provider_sessions(slug, logout=False):
    prefix = 'SECONDARY_' if slug == 'secondary' else ''
    base = 'http://localhost:8083' + ('/identity/secondary' if prefix else '/auth')
    browser = Browser()
    token = json.loads(browser.request(base + '/realms/master/protocol/openid-connect/token', {
        'grant_type': 'password', 'client_id': 'admin-cli', 'username': VALUES['KEYCLOAK_ADMIN'], 'password': VALUES[prefix + 'KEYCLOAK_ADMIN_PASSWORD'],
    }, form=True, origin='http://localhost:8083')[0])['access_token']
    path = base + '/admin/realms/organization-dev/users'
    headers = {'Authorization': 'Bearer ' + token}
    with urllib.request.urlopen(urllib.request.Request(path + '?username=' + urllib.parse.quote(VALUES['DEMO_USERNAME']) + '&exact=true', headers=headers), timeout=30) as response:
        subject = json.load(response)[0]['id']
    if logout:
        with urllib.request.urlopen(urllib.request.Request(path + '/' + subject + '/logout', data=b'', headers=headers, method='POST'), timeout=30) as response:
            assert response.status == 204
    with urllib.request.urlopen(urllib.request.Request(path + '/' + subject + '/sessions', headers=headers), timeout=30) as response:
        return json.load(response)


def main():
    first, second = Browser(), Browser()
    anonymous = first.identity()
    first.request('/gateway/login/sources/primary/authorize', {}, expected=403)
    first.request('/gateway/login/sources/primary/authorize', {}, csrf=anonymous['csrf'], expected=403, origin='https://untrusted.example')
    print('PASS: Nuxt mutations reject missing CSRF and foreign Origin')
    prior_sessions = {session['id'] for session in provider_sessions('primary')}
    identities = [first.login('primary'), second.login('secondary')]
    created_sessions = {session['id'] for session in provider_sessions('primary')} - prior_sessions
    assert created_sessions, 'New primary SSO session was not observed'
    assert identities[0]['identity']['user']['id'] != identities[1]['identity']['user']['id'], 'Independent sources were merged'
    with ThreadPoolExecutor(max_workers=4) as executor:
        checks = list(executor.map(lambda i: [first, second][i % 2].assert_ssr(identities[i % 2]), range(12)))
    assert all(checks)
    print('PASS: both independent sources login; concurrent SSR has correct identity, protected cookies and no credentials')
    first.logout()
    assert not created_sessions.intersection(session['id'] for session in provider_sessions('primary')), 'Originating Keycloak session survived application logout'
    assert second.identity()['status'] == 'authenticated', 'Other source was logged out'
    print('PASS: application POST logout ends the originating Keycloak SSO session only')
    provider_sessions('secondary', logout=True)
    # Count before /me so successful revocation proves webhook delivery rather than introspection fallback.
    result = subprocess.run(['docker', 'compose', 'exec', '-T', 'postgres', 'psql', '-U', 'postgres', '-d', 'organization_app', '-Atc', "SELECT count(*) FROM personal_access_tokens t JOIN members m ON m.id=t.member_id WHERE m.user_source_slug='secondary'"], cwd=ROOT, capture_output=True, text=True, check=True)
    assert result.stdout.strip() == '0', 'Backchannel did not revoke secondary grants'
    assert second.identity()['status'] == 'anonymous', 'Provider logout did not clear app identity'
    print('PASS: real signed Keycloak backchannel logout revokes grants before the next request')

if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        raise SystemExit('Login acceptance failed: ' + (str(error) if isinstance(error, AssertionError) else type(error).__name__) + '. Sensitive response details suppressed.')
