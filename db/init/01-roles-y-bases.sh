#!/usr/bin/env bash
# Corre una sola vez al crear el volumen de Postgres (docker-entrypoint-initdb.d) y en CI.
# Dos roles: pyme_owner (dueño de bases y tablas, solo migraciones) y pyme_app (runtime, sin ownership ni BYPASSRLS).
# Los GRANT sobre tablas van explícitos en cada migración, aquí pyme_app solo conecta y ve el esquema public.
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