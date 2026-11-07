#!/usr/bin/env python3
"""Verify fail-closed Nuxt SSR and grant persistence during a provider restart."""
from pathlib import Path
import runpy
import subprocess

root = Path(__file__).resolve().parents[1]
login = runpy.run_path(str(root / 'scripts/smoke-login.py'))
browser = login['Browser']()
browser.login('primary')
import json
access = json.loads(browser.request('/gateway/manage/access/me')[0])['data']
management = 'companies.view' in access['global']
try:
    subprocess.run(['docker', 'compose', 'stop', 'keycloak'], cwd=root, check=True, capture_output=True)
    assert browser.identity()['status'] == 'unavailable', 'Provider outage did not fail closed'
    html, _ = browser.request('/', expected=503)
    assert 'بررسی وضعیت حساب' in html and 'ورود با حساب سازمانی' not in html, 'Unavailable SSR showed anonymous login'
    assert any(cookie.name == 'organization_credential' for cookie in browser.cookies), 'Outage discarded the credential'
    if management:
        html, _ = browser.request('/admin/companies', expected=503)
        assert 'بررسی وضعیت حساب' in html and 'ورود با حساب سازمانی' not in html, 'Management outage did not fail closed'
    print('PASS: real provider outage yields unavailable SSR/503 without anonymous flash or credential loss')
finally:
    subprocess.run(['docker', 'compose', 'up', '-d', '--no-deps', '--wait', '--wait-timeout', '150', 'keycloak'], cwd=root, check=True, capture_output=True)
assert browser.identity()['status'] == 'authenticated', 'Valid grant did not survive provider restart'
if management:
    browser.request('/admin/companies')
browser.logout()
print('PASS: provider restart preserves the valid SSO grant; application logout still works')
