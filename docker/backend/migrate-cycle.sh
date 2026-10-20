#!/bin/sh
# The migration check CI runs (fresh -> rollback -> re-apply) against THIS checkout's migrations, on a database that is only yours:
#
#   docker/backend/migrate-cycle.sh
#
# The database is `<your test database>_mig` on the throwaway test server (so it never touches the one your test runs use), dropped and
# rebuilt each time. Use it instead of the `docker compose exec app …` recipe, which acts on the MAIN checkout from a worktree.
# Rollback undoes the last batch, which after `migrate:fresh` is every migration, exactly as CI's codegen-check job does.
set -e
. "$(dirname "$0")/test-lib.sh"
erp_check_checkout || exit 64
BASE=$(erp_db_name); erp_validate_db "$BASE" || exit 64
DB=$(printf '%s_mig' "$BASE" | cut -c1-53)
erp_validate_db "$DB" || exit 64
erp_check_vendor || exit 65
erp_lock stack 7 -s "migration cycle on $DB (the Docker stack is being restarted)"
erp_lock "db-$DB" 6 -x "migration cycle on $DB (another run is using database $DB)"
erp_journal start "migrate-cycle"
trap 'erp_journal end "migrate-cycle rc=$?"' EXIT
erp_ensure_db "$DB" || exit $?
ENVS="-e TEST_DB_HOST=postgres-test -e TEST_DB_PORT=5432 -e TEST_DB_DATABASE=$DB -e TEST_APPLICATION_NAME=erp-test:${ERP_AGENT:-unset}:$DB"
step() { # name, artisan arguments: a failure stops the cycle and shows the end of the output (no pipefail in sh, so the status is kept explicitly)
  _name=$1; shift
  erp_note "== $_name"
  ERP_RUN_OPTS="$ENVS" erp_run php artisan "$@" --database=testing --force --no-interaction >"$ERP_LOCK_DIR/mig.$$" 2>&1 \
    || { grep -v '^ Container' "$ERP_LOCK_DIR/mig.$$" | tail -12 >&2; rm -f "$ERP_LOCK_DIR/mig.$$"; erp_note "FAILED: $_name"; exit 1; }
  grep -v '^ Container' "$ERP_LOCK_DIR/mig.$$" | tail -2 >&2; rm -f "$ERP_LOCK_DIR/mig.$$"
}
step "migrate:fresh (every migration, forward)" migrate:fresh
step "migrate:rollback (every down())" migrate:rollback
step "migrate (apply again)" migrate
erp_note "migration cycle passed on $DB"
