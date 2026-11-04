#!/usr/bin/env python3
"""Verify the real development gateway, identity metadata and service isolation."""
import json
from pathlib import Path
import subprocess
import urllib.error
import urllib.request

root = Path(__file__).resolve().parents[1]

def get(url):
    with urllib.request.urlopen(urllib.request.Request(url, headers={"Accept": "text/html"} if url.startswith("http://localhost:8084") else {}), timeout=30) as response:
        return response.status, response.read(), response.geturl()

def compose(*args, success=True):
    result = subprocess.run(['docker', 'compose', *args], cwd=root, capture_output=True, text=True)
    if success and result.returncode != 0:
        raise AssertionError('Compose verification failed: ' + result.stderr[-400:])
    if not success and result.returncode == 0:
        raise AssertionError('Expected cross-database connection to be denied')
    return result.stdout

status, body, _ = get('http://localhost:8081/api/health')
assert status == 200 and json.loads(body)['status'] == 'ok'
print('PASS: Laravel readiness checks PostgreSQL and Redis through 8081')
status, body, _ = get('http://localhost:8081/')
assert status == 200 and 'سامانه'.encode() in body
print('PASS: Persian frontend through 8081')
for prefix in ['/auth', '/identity/secondary']:
    status, body, _ = get('http://localhost:8081' + prefix + '/realms/organization-dev/.well-known/openid-configuration')
    metadata = json.loads(body)
    assert metadata['issuer'] == 'http://localhost:8081' + prefix + '/realms/organization-dev'
    for field in ['authorization_endpoint', 'token_endpoint', 'end_session_endpoint', 'jwks_uri']:
        assert metadata[field].startswith('http://localhost:8081' + prefix + '/'), field
    print('PASS: canonical Keycloak issuer/endpoints on 8081 for ' + prefix)
for port, path in [(8082, '/misc/ping'), (8083, '/auth/admin/'), (8083, '/identity/secondary/admin/'), (8084, '/operate')]:
    assert get(f'http://localhost:{port}{path}')[0] == 200
    print(f'PASS: management entry point {port}')
for role, password_env in [('organization_app', 'APP_DB_PASSWORD'), ('keycloak', 'KEYCLOAK_DB_PASSWORD'), ('camunda', 'CAMUNDA_DB_PASSWORD'), ('keycloak_secondary', 'SECONDARY_KEYCLOAK_DB_PASSWORD')]:
    for target in ['organization_app', 'keycloak', 'camunda', 'keycloak_secondary']:
        command = f'PGPASSWORD="${password_env}" psql -h 127.0.0.1 -U {role} -d {target} -v ON_ERROR_STOP=1 -c "SELECT 1"'
        compose('exec', '-T', 'postgres', 'sh', '-c', command, success=role == target)
print('PASS: all service roles can connect only to their own database')
services = json.loads(compose('config', '--format', 'json'))['services']
ports = []
for name, service in services.items():
    for port in service.get('ports', []):
        assert name == 'nginx' and port['host_ip'] == '127.0.0.1'
        ports.append(str(port['published']))
assert sorted(ports) == ['8081', '8082', '8083', '8084']
print('PASS: only the four requested loopback gateway ports are published')
source_files = (root / 'frontend/app').rglob('*.vue')
for source in source_files:
    text = source.read_text()
    assert '<style' not in text and ' style=' not in text and ':style=' not in text, source
print('PASS: project Vue components contain no embedded or inline styles')
