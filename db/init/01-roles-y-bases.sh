#!/usr/bin/env bash
# Corre una sola vez al crear el volumen de Postgres (docker-entrypoint-initdb.d) y en CI.
# Commit 1: solo el rol dueño. El rol de aplicación (sin ownership ni BYPASSRLS) llega en el commit 2.
set -euo pipefail

: "${POSTGRES_USER:?}"
: "${PYME_OWNER_PASSWORD:?}"

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres \
  -v owner_pass="$PYME_OWNER_PASSWORD" <<'SQL'
CREATE ROLE pyme_owner LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS NOREPLICATION
  PASSWORD :'owner_pass';

CREATE DATABASE pyme      OWNER pyme_owner ENCODING 'UTF8' TEMPLATE template0;
CREATE DATABASE pyme_test OWNER pyme_owner ENCODING 'UTF8' TEMPLATE template0;

REVOKE ALL ON DATABASE pyme      FROM PUBLIC;
REVOKE ALL ON DATABASE pyme_test FROM PUBLIC;

ALTER DATABASE pyme      SET timezone TO 'UTC';
ALTER DATABASE pyme_test SET timezone TO 'UTC';
SQL

for db in pyme pyme_test; do
  psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$db" <<'SQL'
ALTER SCHEMA public OWNER TO pyme_owner;
REVOKE ALL ON SCHEMA public FROM PUBLIC;
SQL
done