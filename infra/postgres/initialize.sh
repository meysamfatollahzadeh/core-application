#!/bin/sh
set -eu
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres \
  --set=app_password="$APP_DB_PASSWORD" --set=keycloak_password="$KEYCLOAK_DB_PASSWORD" --set=camunda_password="$CAMUNDA_DB_PASSWORD" <<'SQL'
CREATE ROLE organization_app LOGIN PASSWORD :'app_password';
CREATE ROLE keycloak LOGIN PASSWORD :'keycloak_password';
CREATE ROLE camunda LOGIN PASSWORD :'camunda_password';
CREATE DATABASE organization_app OWNER organization_app ENCODING 'UTF8';
CREATE DATABASE keycloak OWNER keycloak ENCODING 'UTF8';
CREATE DATABASE camunda OWNER camunda ENCODING 'UTF8';
REVOKE ALL ON DATABASE organization_app FROM PUBLIC;
REVOKE ALL ON DATABASE keycloak FROM PUBLIC;
REVOKE ALL ON DATABASE camunda FROM PUBLIC;
SQL
