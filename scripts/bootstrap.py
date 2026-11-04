#!/usr/bin/env python3
"""Create private local configuration and render service bootstrap templates."""
from pathlib import Path
import base64
import json
import os
import secrets

root = Path(__file__).resolve().parents[1]
os.chdir(root)
env_path = root / '.env'
if not env_path.exists():
    values = (root / '.env.example').read_text()
    values = values.replace('APP_KEY=\n', 'APP_KEY=base64:' + base64.b64encode(secrets.token_bytes(32)).decode() + '\n')
    for key in ['POSTGRES_PASSWORD', 'APP_DB_PASSWORD', 'KEYCLOAK_DB_PASSWORD', 'CAMUNDA_DB_PASSWORD', 'REDIS_PASSWORD', 'KEYCLOAK_ADMIN_PASSWORD', 'OIDC_CLIENT_SECRET', 'CAMUNDA_CLIENT_SECRET', 'DEMO_PASSWORD', 'PGADMIN_PASSWORD']:
        values = values.replace(key + '=\n', key + '=' + secrets.token_hex(24) + '\n')
    env_path.write_text(values)
    env_path.chmod(0o600)
extra_secrets = ['IMPORT_CLIENT_SECRET', 'SECONDARY_KEYCLOAK_DB_PASSWORD', 'SECONDARY_KEYCLOAK_ADMIN_PASSWORD', 'SECONDARY_OIDC_CLIENT_SECRET', 'SECONDARY_IMPORT_CLIENT_SECRET', 'NUXT_AUTH_COOKIE_KEY']
existing = dict(line.split('=', 1) for line in env_path.read_text().splitlines() if line and not line.startswith('#') and '=' in line)
extra = []
for key in extra_secrets:
    if not existing.get(key):
        value = base64.b64encode(secrets.token_bytes(32)).decode() if key == 'NUXT_AUTH_COOKIE_KEY' else secrets.token_hex(24)
        extra.append(key + '=' + value)
if extra:
    env_path.write_text(env_path.read_text().rstrip() + '\n' + '\n'.join(extra) + '\n')
backend_env = root / 'backend/.env' 
if not backend_env.exists():
    backend_env.write_text((root / 'backend/.env.example').read_text())
    backend_env.chmod(0o600)
values = {}
for line in env_path.read_text().splitlines():
    if line and not line.startswith('#') and '=' in line:
        key, value = line.split('=', 1)
        values[key] = value
if values.get('APP_URL') != 'http://localhost:8081':
    raise SystemExit('This phase supports APP_URL=http://localhost:8081; changing origin requires updating identity clients and proxy configuration.')
required = ['APP_KEY', 'POSTGRES_PASSWORD', 'APP_DB_PASSWORD', 'KEYCLOAK_DB_PASSWORD', 'CAMUNDA_DB_PASSWORD', 'REDIS_PASSWORD', 'KEYCLOAK_ADMIN_PASSWORD', 'OIDC_CLIENT_SECRET', 'CAMUNDA_CLIENT_SECRET', 'DEMO_PASSWORD', 'PGADMIN_PASSWORD']
if any(not values.get(key) for key in required):
    raise SystemExit('Missing required development secrets in .env')
runtime = root / '.runtime'
runtime.mkdir(mode=0o700, exist_ok=True)
realm = json.loads((root / 'infra/keycloak/realm.template.json').read_text())
realm['users'][0]['username'] = values['DEMO_USERNAME']
realm['users'][0]['credentials'][0]['value'] = values['DEMO_PASSWORD']
for client in realm['clients']:
    client['secret'] = values['OIDC_CLIENT_SECRET' if client['clientId'] == 'organization-backend' else 'CAMUNDA_CLIENT_SECRET']
(runtime / 'realm.json').write_text(json.dumps(realm, indent=2) + '\n')
(runtime / 'realm.json').chmod(0o644)
source_dir = runtime / 'sources'
source_dir.mkdir(exist_ok=True)
for slug, title, prefix, public_path, internal_host in [
    ('primary', 'کاربران سازمان', '', '/auth', 'keycloak'),
    ('secondary', 'کاربران سازمان همکار', 'SECONDARY_', '/identity/secondary', 'keycloak-secondary'),
]:
    source = {'slug': slug, 'title': title, 'is_active': True, 'data': {
        'issuer': 'http://localhost:8081' + public_path + '/realms/organization-dev',
        'internal_issuer': 'http://' + internal_host + ':8080' + public_path + '/realms/organization-dev',
        'client_id': 'organization-backend', 'client_secret': values[prefix + 'OIDC_CLIENT_SECRET'],
        'callback_url': 'http://localhost:8081/login/callback/' + slug,
        'post_logout_redirect_uri': 'http://localhost:8081/login/logged-out',
        'import_client_id': 'organization-import', 'import_client_secret': values[prefix + 'IMPORT_CLIENT_SECRET'],
        'admin_base_url': 'http://' + internal_host + ':8080' + public_path + '/admin/realms/organization-dev',
        'allowed_algorithms': ['RS256'], 'timeout': 10, 'page_size': 100,
    }}
    (source_dir / (slug + '.json')).write_text(json.dumps(source, ensure_ascii=False, indent=2) + '\n')
    (source_dir / (slug + '.json')).chmod(0o644)
print('Development configuration ready. Secrets remain in ignored .env and .runtime/.')
