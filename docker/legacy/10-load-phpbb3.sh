#!/bin/sh
# Loads the phpPgAdmin dump of the old phpBB 3 install.
# The dump cannot be replayed verbatim: its `CREATE OPERATOR` statements for the `varchar_ci` domain use a
# syntax PostgreSQL rejects (`COMMUTATOR = <(varchar_ci,varchar_ci)`), and they are irrelevant to the data,
# so they are filtered out. Everything else (roles, domain, tables, rows) is loaded as is.
# The dump also runs `ALTER DATABASE "comtso2" ...`, so the database must be called comtso2.
set -eu
sed '/^CREATE OPERATOR /,/^);/d' /legacy/phpbb3.sql \
  | psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" -q -o /dev/null
