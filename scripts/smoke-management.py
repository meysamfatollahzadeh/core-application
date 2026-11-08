#!/usr/bin/env python3
"""Exercise management through real OIDC and Nuxt; disposable acceptance project only."""
import json
import os
from pathlib import Path
import subprocess
import importlib.util

spec = importlib.util.spec_from_file_location('organization_login_smoke', Path(__file__).with_name('smoke-login.py'))
login = importlib.util.module_from_spec(spec)
spec.loader.exec_module(login)
Browser = login.Browser
ROOT = Path(__file__).resolve().parents[1]

if os.environ.get('COMPOSE_PROJECT_NAME') != 'organization-fresh-acceptance':
    raise SystemExit('Refusing administrative fixtures outside organization-fresh-acceptance.')


def compose(*args):
    result = subprocess.run(['docker', 'compose', *args], cwd=ROOT, capture_output=True, text=True)
    if result.returncode:
        raise AssertionError('Disposable management command failed: ' + result.stderr[-250:])
    return result.stdout


admin = Browser()
identity = admin.login('primary')
compose('exec', '-T', 'backend', 'php', 'artisan', 'access:sync-permissions')
compose('exec', '-T', 'backend', 'php', 'artisan', 'access:bootstrap', '--source=primary', '--subject=' + identity['identity']['member']['uuid'])
other = Browser()
other.login('secondary')
csrf = identity['csrf']


def api(path, data=None, method=None, expected=200, browser=admin, proof=csrf, origin=login.ORIGIN):
    import urllib.request
    import urllib.error
    headers = {'Accept': 'application/json'}
    if data is not None or method in ['DELETE', 'PUT', 'PATCH']:
        headers.update({'Origin': origin, 'Content-Type': 'application/json'})
        if proof:
            headers['X-CSRF-TOKEN'] = proof
    request = urllib.request.Request(login.ORIGIN + '/gateway/manage/' + path, data=json.dumps(data).encode() if data is not None else None, headers=headers, method=method or ('POST' if data is not None else 'GET'))
    try:
        response = browser.opener.open(request, timeout=60)
    except urllib.error.HTTPError as error:
        response = error
    assert response.status == expected, f'{path}: {response.status}, expected {expected}: {response.read().decode()[:250]}'
    body = response.read().decode()
    return json.loads(body) if body else None


assert api('access/me')['data']['global']
api('companies', browser=other, expected=403)
api('companies', {'title': 'CSRF rejected'}, expected=403, proof=None)
api('companies', {'title': 'Origin rejected'}, expected=403, origin='http://untrusted.invalid')
a = api('companies', {'title': 'شرکت آزمون الف'}, expected=201)['data']['id']
assert api('companies')['links']['first'].startswith(login.ORIGIN + '/api/companies'), 'Pagination escaped public origin'
b = api('companies', {'title': 'شرکت آزمون ب'}, expected=201)['data']['id']
root = api(f'companies/{a}/positions', {'title': 'مدیر مالی'}, expected=201)['data']['id']
child = api(f'companies/{a}/positions', {'title': 'کارشناس مالی', 'parent_position_id': root}, expected=201)['data']['id']
external = api(f'companies/{b}/positions', {'title': 'سرپرست مشترک'}, expected=201)['data']['id']
user = identity['identity']['user']['id']
assignment = api(f'companies/{a}/user-positions', {'user_id': user, 'position_id': child, 'report_position_ids': [root, external]}, expected=201)['data']['id']
api(f'companies/{a}/user-positions/{assignment}', {'report_position_ids': []}, method='PUT', expected=422)
assert len(api(f'companies/{a}/user-positions/{assignment}')['data']['report_position_ids']) == 2
building = api('buildings', {'title': 'ساختمان آزمون'}, expected=201)['data']['id']
area = api(f'buildings/{building}/areas')['data'][0]['id']
api(f'buildings/{building}/areas/{area}', method='DELETE', expected=409)
permissions = api('permissions?per_page=100')['data']
view_positions = next(p['id'] for p in permissions if p['key'] == 'positions.view')
role = api('roles', {'key': 'acceptance-company-reader', 'title': 'مشاهده چارت شرکت'}, expected=201)['data']['id']
api(f'roles/{role}/permissions', {'permission_ids': [view_positions]}, method='PUT')
other_user = other.identity()['identity']['user']['id']
api(f'companies/{a}/users/{other_user}/roles', {'role_ids': [role]}, method='PUT')
api(f'companies/{a}/positions', browser=other)
api(f'companies/{b}/positions', browser=other, expected=403)
api('companies', browser=other, expected=403)
api(f'companies/{a}/users/{other_user}/roles', {'role_ids': []}, method='PUT')
api(f'companies/{a}/positions', browser=other, expected=403)
# Position-derived global grant takes effect without reissuing the existing other-user token.
api(f'companies/{a}/user-positions', {'user_id': other_user, 'position_id': root, 'report_position_ids': []}, expected=201)
api(f'companies/{a}/positions/{root}/roles', {'grants': [{'role_id': role, 'scope': 'global'}]}, method='PUT')
api(f'companies/{b}/positions', browser=other)
api(f'companies/{a}/positions/{root}/roles', {'grants': []}, method='PUT')
api(f'companies/{b}/positions', browser=other, expected=403)
html, response = admin.request('/admin/companies')
assert 'شرکت آزمون الف' in html and 'no-store' in response.headers.get('Cache-Control', '')
for secret in ['Bearer ', 'access_token', 'refresh_token', login.VALUES['OIDC_CLIENT_SECRET']]:
    assert secret not in html
other.request('/admin/companies', expected=403)
for section in ['positions', 'assignments', 'personnel', 'buildings', 'roles', 'grants']:
    html, _ = admin.request('/admin/' + section + ('?company=' + str(a) if section in ['positions', 'assignments'] else ''))
    assert 'مدیریت سازمان' in html
# Save only encrypted browser transport cookies for optional local visual QA.
output = ROOT / '.runtime/phase2-acceptance-browser.json'
cookies = []
for cookie in admin.cookies:
    if cookie.name.startswith('organization_'):
        cookies.append({'name': cookie.name, 'value': cookie.value, 'domain': 'localhost', 'path': '/', 'expires': cookie.expires or -1, 'httpOnly': cookie.has_nonstandard_attr('HttpOnly'), 'secure': cookie.secure, 'sameSite': 'Lax'})
output.write_text(json.dumps({'cookies': cookies, 'origins': []}))
output.chmod(0o600)
print('PASS: isolated real management/OIDC/SSR/CSRF, company boundaries, current reporting/location defaults and immediate grant changes; encrypted browser fixture saved.')
