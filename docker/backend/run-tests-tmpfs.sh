#!/bin/sh
# The approved way to run the backend test suite. Run from anywhere; it works from the repository root:
#
#   ERP_AGENT=claude docker/backend/run-tests-tmpfs.sh --filter=Estimating   # focused: private database, no suite lock
#   ERP_AGENT=claude docker/backend/run-tests-tmpfs.sh                        # whole suite: also takes the exclusive full-suite lock
#   docker/backend/test-db.sh name                                            # which database would I get?
#
# What it guarantees (docs/architecture/environments.md "Test database on tmpfs"):
#   - A private database per agent and checkout: erp_testing_<ERP_AGENT>_<workspace> (or TEST_DB_NAME=erp_testing_<you>). The
#     shared `erp_testing` is refused. Creation, migration, reset and cleanup only ever touch that one database.
#   - Focused runs (--filter, --group, a path) never wait for each other or for a full suite.
#   - A whole-suite run takes one exclusive lock, so two never overlap; a waiting run says who holds it and for how long.
#   - Runs on one database are serialised, so two agents that forgot ERP_AGENT cannot drop each other's tables.
#   - Locks are flock(1) locks: the kernel frees them when a run dies, however it dies. An interrupted run also stops its test
#     process inside the container, and a stale one left by a killed wrapper is stopped before the next run on that database.
#   - Tests hold a shared lock on the Docker stack; docker/dev/stack-exclusive.sh needs it exclusively (no restart mid-test).
# Environment: ERP_AGENT, TEST_DB_NAME, ERP_TEST_LOCK_WAIT (seconds to wait for a lock, default 1500; 0 = fail at once),
# ERP_TEST_LOCK_DIR. `--parallel` is refused: the suite's committed-data and forked-process tests have not been proven under it.
set -e
. "$(dirname "$0")/test-lib.sh"

for a in "$@"; do
  case "$a" in --parallel*|--processes*) erp_note "refused: parallel Pest is not supported here (committed-data and pcntl_fork tests are unproven under it)."; exit 64 ;; esac
done

DB=$(erp_db_name)
erp_validate_db "$DB" || exit 64
FULL=0; erp_is_full "$@" && FULL=1
WHAT="backend tests on $DB ($([ $FULL = 1 ] && echo 'FULL suite' || echo focused))"
START=$(erp_now)
PIDFILE="/tmp/erp-test-run-$DB.pid"
STARTED=0

# Order is fixed (stack, database, full suite) so runs can never wait on each other in a circle.
erp_lock stack 7 -s "$WHAT (the Docker stack is being restarted)"
erp_lock "db-$DB" 6 -x "$WHAT (another run is using database $DB; set ERP_AGENT=<you> for your own)"
[ "$FULL" = 1 ] && erp_lock full-suite 9 -x "$WHAT (only one full backend suite at a time)"
erp_register_run "$WHAT"
erp_journal start "full=$FULL args=$*"

stop_remote() { # stop the test process inside the container, if a pid file says one is still alive
  erp_dc exec -T app sh -c "f='$PIDFILE'; [ -f \$f ] || exit 0; p=\$(cat \$f); if kill -0 \$p 2>/dev/null && grep -q artisan /proc/\$p/cmdline 2>/dev/null; then kill -TERM \$p; sleep 2; kill -KILL \$p 2>/dev/null; echo stopped; fi; rm -f \$f" 2>/dev/null || true
}
finish() {
  rc=$?
  [ -n "$CHILD" ] && kill "$CHILD" 2>/dev/null || true
  [ "$STARTED" = 1 ] && [ "$rc" -ge 128 ] && stop_remote
  erp_journal end "rc=$rc elapsed=$(( $(erp_now) - START ))s"
  erp_unregister_run
  exit "$rc"
}
trap finish EXIT
trap 'exit 130' INT
trap 'exit 143' TERM HUP

# --no-recreate: `up` must never rebuild a running container (a changed compose file would wipe other agents' databases).
erp_dc --profile test-db up -d --wait --no-recreate postgres-test >/dev/null

USE=$(erp_tmpfs_use)
if [ "$USE" -ge 90 ]; then
  erp_note "refused: the test server's RAM disk is ${USE}% full. Free space with docker/backend/test-db.sh drop <database> (see 'list')."; exit 70
elif [ "$USE" -ge 75 ]; then
  erp_note "warning: the test server's RAM disk is ${USE}% full (docker/backend/test-db.sh list shows who uses it)."
fi

# Create only this database (a no-op when it exists).
erp_psql -c "SELECT 1 FROM pg_database WHERE datname = '$DB'" | grep -q 1 \
  || erp_psql -c "CREATE DATABASE \"$DB\" OWNER erp" >/dev/null

stop_remote | grep -q stopped && erp_note "stopped a stale test process left on $DB by a killed run."

run() {
  # A plain background command (not a shell function): the shell forks and execs docker at once, so no leftover subshell can keep
  # a lock descriptor open after the wrapper is killed. The explicit closes cover the exec'd docker client itself.
  docker compose exec -T \
    -e TEST_DB_HOST=postgres-test -e TEST_DB_PORT=5432 -e "TEST_DB_DATABASE=$DB" -e "TEST_APPLICATION_NAME=erp-test:${ERP_AGENT:-unset}:$DB" \
    app sh -c 'echo $$ > "$1"; shift; exec "$@"' sh "$PIDFILE" "$@" 6>&- 7>&- 8>&- 9>&- &
  CHILD=$!
  STARTED=1
  wait "$CHILD"
  status=$?
  CHILD=
  return $status
}

run php artisan migrate --database=testing --force --no-interaction >/dev/null
run php artisan test "$@" && rc=0 || rc=$?
STARTED=0
erp_note "test run on $DB finished: exit $rc, $(( $(erp_now) - START ))s, RAM disk $(erp_tmpfs_use)% used, database $(erp_psql -c "SELECT pg_size_pretty(pg_database_size('$DB'))")"
exit $rc
