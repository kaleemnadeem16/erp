#!/bin/sh
# Look after your own test database on the RAM-disk Postgres (service `postgres-test`). Only ever touches the one it names.
#
#   docker/backend/test-db.sh name            # the database this agent/checkout gets (ERP_AGENT=<you>, or TEST_DB_NAME=...)
#   docker/backend/test-db.sh list            # every erp_* database on the test server, with sizes, plus RAM-disk use
#   docker/backend/test-db.sh drop [name]     # drop yours (or the named erp_testing_* one); it is recreated on the next run
#   docker/backend/test-db.sh reset           # drop yours and rebuild its schema
set -e
. "$(dirname "$0")/test-lib.sh"

cmd="${1:-name}"
case "$cmd" in
  name) erp_db_name; echo; exit 0 ;;
  list)
    erp_psql -c "SELECT datname || '  ' || pg_size_pretty(pg_database_size(datname)) FROM pg_database WHERE datname LIKE 'erp%' ORDER BY 1"
    echo "RAM disk: $(erp_tmpfs_use)% used"; exit 0 ;;
  drop|reset) ;;
  *) erp_note "usage: test-db.sh name|list|drop [name]|reset"; exit 64 ;;
esac

DB="${2:-$(erp_db_name)}"
erp_validate_db "$DB" || exit 64
# Wait for any run using this database (and block new ones) while it is dropped.
erp_lock "db-$DB" 6 -x "$cmd of $DB"
erp_dc --profile test-db up -d --wait --no-recreate postgres-test >/dev/null
erp_psql -c "DROP DATABASE IF EXISTS \"$DB\" WITH (FORCE)" >/dev/null
echo "dropped $DB"
if [ "$cmd" = reset ]; then
  erp_psql -c "CREATE DATABASE \"$DB\" OWNER erp" >/dev/null
  erp_dc exec -T -e TEST_DB_HOST=postgres-test -e TEST_DB_PORT=5432 -e "TEST_DB_DATABASE=$DB" app php artisan migrate --database=testing --force --no-interaction >/dev/null 6>&-
  echo "rebuilt $DB"
fi
