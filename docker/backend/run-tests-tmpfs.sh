#!/bin/sh
# Run the backend test suite against the throwaway tmpfs Postgres (service `postgres-test`) instead of the
# dev database server. Run from the repo root:
#
#   docker/backend/run-tests-tmpfs.sh                       # whole suite
#   docker/backend/run-tests-tmpfs.sh --filter=Estimating   # arguments go to `php artisan test`
#   TEST_DB_NAME=erp_testing_b docker/backend/run-tests-tmpfs.sh   # a private database, so two people
#                                                                  # (or agents) can run at once without
#                                                                  # deadlocking on schema drops
#
# The data lives in RAM and is lost whenever the `postgres-test` container stops; the schema is rebuilt here on demand.
set -e

DB="${TEST_DB_NAME:-erp_testing}"

docker compose --profile test-db up -d --wait postgres-test >/dev/null

docker compose exec -T postgres-test psql -U erp -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname = '$DB'" | grep -q 1 \
  || docker compose exec -T postgres-test psql -U erp -d postgres -c "CREATE DATABASE \"$DB\" OWNER erp" >/dev/null

run() {
  docker compose exec -T -e TEST_DB_HOST=postgres-test -e TEST_DB_PORT=5432 -e "TEST_DB_DATABASE=$DB" app "$@"
}

run php artisan migrate --database=testing --force --no-interaction >/dev/null
run php artisan test "$@"
