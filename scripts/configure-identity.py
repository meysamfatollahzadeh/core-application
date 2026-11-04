#!/usr/bin/env python3
"""Reconcile the application's own development OIDC clients without resetting realms."""
import argparse
import json
from pathlib import Path
import urllib.error
import urllib.parse
import urllib.request

root = Path(__file__).resolve().parents[1]
values = dict(line.split('=', 1) for line in (root / '.env').read_text().splitlines() if line and not line.startswith('#'))
parser = argparse.ArgumentParser()
parser.add_argument('--source', choices=['primary', 'secondary'], required=True)
args = parser.parse_args()
secondary = args.source == 'secondary'
prefix = 'SECONDARY_' if secondary else ''
base = 'http://localhost:8083' + ('/identity/secondary' if secondary else '/auth')
realm = 'organization-dev'

def request(method, path, data=None, token=None, form=False):
    headers = {'Content-Type': 'application/x-www-form-urlencoded' if form else 'application/json'}
    if token:
        headers['Authorization'] = 'Bearer ' + token
    body = (urllib.parse.urlencode(data).encode() if form else json.dumps(data).encode()) if data is not None else None
    with urllib.request.urlopen(urllib.request.Request(base + path, data=body, headers=headers, method=method), timeout=30) as response:
        raw = response.read()
        return json.loads(raw) if raw else None

try:
    admin = request('POST', '/realms/master/protocol/openid-connect/token', {
        'grant_type': 'password', 'client_id': 'admin-cli', 'username': values['KEYCLOAK_ADMIN'],
        'password': values[prefix + 'KEYCLOAK_ADMIN_PASSWORD'],
    }, form=True)['access_token']
    admin_path = '/admin/realms/' + realm
    clients = request('GET', admin_path + '/clients', token=admin)
    by_name = {client['clientId']: client for client in clients}
    client = by_name['organization-backend']
    client['secret'] = values[prefix + 'OIDC_CLIENT_SECRET']
    client['redirectUris'] = ['http://localhost:8081/login/callback/' + args.source]
    client['attributes'].update({
        'post.logout.redirect.uris': 'http://localhost:8081/login/logged-out',
        'backchannel.logout.url': 'http://backend:8000/api/login/sources/' + args.source + '/backchannel-logout',
        'backchannel.logout.session.required': 'true',
        'backchannel.logout.revoke.offline.tokens': 'true',
        'pkce.code.challenge.method': 'S256',
    })
    mappers = [mapper for mapper in client.get('protocolMappers', []) if mapper.get('name') != 'login-audience']
    mappers.append({'name': 'login-audience', 'protocol': 'openid-connect', 'protocolMapper': 'oidc-audience-mapper',
                    'config': {'included.client.audience': 'organization-backend', 'id.token.claim': 'false', 'access.token.claim': 'true', 'introspection.token.claim': 'true'}})
    client['protocolMappers'] = mappers
    client['defaultClientScopes'] = list(dict.fromkeys(client.get('defaultClientScopes', []) + ['basic']))
    request('PUT', admin_path + '/clients/' + client['id'], client, admin)
    scopes = request('GET', admin_path + '/client-scopes', token=admin)
    basic_scope = next(scope['id'] for scope in scopes if scope['name'] == 'basic')
    request('PUT', admin_path + '/clients/' + client['id'] + '/default-client-scopes/' + basic_scope, token=admin)
    importer = {
        'clientId': 'organization-import', 'enabled': True, 'protocol': 'openid-connect',
        'publicClient': False, 'secret': values[prefix + 'IMPORT_CLIENT_SECRET'],
        'standardFlowEnabled': False, 'directAccessGrantsEnabled': False, 'serviceAccountsEnabled': True,
    }
    if 'organization-import' in by_name:
        importer_id = by_name['organization-import']['id']
        request('PUT', admin_path + '/clients/' + importer_id, importer, admin)
    else:
        request('POST', admin_path + '/clients', importer, admin)
        importer_id = request('GET', admin_path + '/clients?clientId=organization-import', token=admin)[0]['id']
    account = request('GET', admin_path + '/clients/' + importer_id + '/service-account-user', token=admin)
    management_id = by_name['realm-management']['id']
    roles = [request('GET', admin_path + '/clients/' + management_id + '/roles/' + role, token=admin) for role in ['view-users', 'query-users']]
    request('POST', admin_path + '/users/' + account['id'] + '/role-mappings/clients/' + management_id, roles, admin)
    print('Configured OIDC/logout and least-privilege import client for ' + args.source + '.')
except (urllib.error.HTTPError, urllib.error.URLError, KeyError, ValueError):
    raise SystemExit('Identity client configuration failed for ' + args.source + '; no credentials were printed.')
