#!/usr/bin/env python3
"""Create private local configuration and render service bootstrap templates."""
from pathlib import Path
import base64
import json
import os
import secrets
import string

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
print('Development configuration ready. Secrets remain in ignored .env and .runtime/.')
