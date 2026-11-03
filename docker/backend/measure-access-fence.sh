#!/bin/sh
# Synthetic measurements only. Rebuilds this agent's PRIVATE database on postgres-test.
set -eu
. "$(dirname "$0")/test-lib.sh"
erp_check_checkout
erp_check_vendor
DB=$(erp_db_name)
erp_validate_db "$DB"
erp_lock stack 7 -s 'access-fence synthetic measurement'
erp_lock "db-$DB" 6 -x 'access-fence synthetic measurement'
erp_ensure_db "$DB"
# Use the checkout runner, never the running application or its database.
docker/backend/run.sh env APP_ENV=testing TEST_DB_HOST=postgres-test TEST_DB_PORT=5432 "TEST_DB_DATABASE=$DB" php artisan migrate:fresh --database=testing --force --no-interaction >&2
docker/backend/run.sh env APP_ENV=testing TEST_DB_HOST=postgres-test TEST_DB_PORT=5432 "TEST_DB_DATABASE=$DB" php tools/measure-access-fence.php "$@"
