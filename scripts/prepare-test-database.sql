SELECT format('CREATE DATABASE %I OWNER organization_app', 'organization_test')
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'organization_test')\gexec
REVOKE ALL ON DATABASE organization_test FROM PUBLIC;
