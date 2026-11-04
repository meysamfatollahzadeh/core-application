#!/bin/sh
set -eu
psql -v ON_ERROR_STOP=1 --username postgres --dbname postgres --set=secondary_password="$SECONDARY_KEYCLOAK_DB_PASSWORD" <<'SQL'
SELECT format('CREATE ROLE keycloak_secondary LOGIN PASSWORD %L', :'secondary_password')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'keycloak_secondary') \gexec
SELECT 'CREATE DATABASE keycloak_secondary OWNER keycloak_secondary ENCODING ''UTF8'''
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'keycloak_secondary') \gexec
REVOKE ALL ON DATABASE keycloak_secondary FROM PUBLIC;
SQL
