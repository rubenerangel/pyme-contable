#!/usr/bin/env bash
# Runs once when the Postgres volume is created (docker-entrypoint-initdb.d) and in CI.
# Two roles: pyme_owner (owns databases and tables, migrations only) and pyme_app (runtime, no ownership and no BYPASSRLS).
# Table GRANTs are explicit in each migration; here pyme_app only connects and sees the public schema.
set -euo pipefail

: "${POSTGRES_USER:?}"
: "${PYME_OWNER_PASSWORD:?}"
: "${PYME_APP_PASSWORD:?}"

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres \
  -v owner_pass="$PYME_OWNER_PASSWORD" -v app_pass="$PYME_APP_PASSWORD" <<'SQL'
CREATE ROLE pyme_owner LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS NOREPLICATION
  PASSWORD :'owner_pass';
CREATE ROLE pyme_app LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS NOREPLICATION
  PASSWORD :'app_pass';

CREATE DATABASE pyme      OWNER pyme_owner ENCODING 'UTF8' TEMPLATE template0;
CREATE DATABASE pyme_test OWNER pyme_owner ENCODING 'UTF8' TEMPLATE template0;

REVOKE ALL ON DATABASE pyme      FROM PUBLIC;
REVOKE ALL ON DATABASE pyme_test FROM PUBLIC;
REVOKE CONNECT ON DATABASE postgres  FROM PUBLIC;

GRANT CONNECT ON DATABASE pyme      TO pyme_app;
GRANT CONNECT ON DATABASE pyme_test TO pyme_app;

ALTER DATABASE pyme      SET timezone TO 'UTC';
ALTER DATABASE pyme_test SET timezone TO 'UTC';
SQL

for db in pyme pyme_test; do
  psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$db" <<'SQL'
ALTER SCHEMA public OWNER TO pyme_owner;
REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO pyme_app;
SQL
done