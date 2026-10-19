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
#   - It runs YOUR checkout's code: each run is a throwaway container with this checkout's ./backend mounted (see
#     docs/architecture/multi-agent-workspaces.md), and it refuses to run from inside a different checkout or against a vendor that
#     does not match this checkout's composer.lock.
# Environment: ERP_AGENT (or the .erp-agent file a worktree has), TEST_DB_NAME, ERP_TEST_LOCK_WAIT (seconds to wait for a lock, default 1500; 0 = fail at once),
# ERP_TEST_LOCK_DIR. `--parallel` is refused: the suite's committed-data and forked-process tests have not been proven under it.
set -e
. "$(dirname "$0")/test-lib.sh"

for a in "$@"; do
  case "$a" in --parallel*|--processes*) erp_note "refused: parallel Pest is not supported here (committed-data and pcntl_fork tests are unproven under it)."; exit 64 ;; esac
done

erp_check_checkout || exit 64
DB=$(erp_db_name)
erp_validate_db "$DB" || exit 64
erp_check_vendor || exit 65
FULL=0; erp_is_full "$@" && FULL=1
WHAT="backend tests on $DB ($([ $FULL = 1 ] && echo 'FULL suite' || echo focused))"
START=$(erp_now)
RUNNER="erp-run-$DB"   # the throwaway container of this database's run; a fixed name lets a stale one be found and removed
STARTED=0

# Order is fixed (stack, database, full suite) so runs can never wait on each other in a circle.
erp_lock stack 7 -s "$WHAT (the Docker stack is being restarted)"
erp_lock "db-$DB" 6 -x "$WHAT (another run is using database $DB; set ERP_AGENT=<you> for your own)"
[ "$FULL" = 1 ] && erp_lock full-suite 9 -x "$WHAT (only one full backend suite at a time)"
erp_register_run "$WHAT"
erp_journal start "full=$FULL args=$*"

stop_remote() { # remove this database's runner container if one is still around (kills the test process with it)
  [ -n "$(docker ps -aq -f "name=^$RUNNER\$")" ] || return 1
  docker rm -f "$RUNNER" >/dev/null 2>&1 || true
  echo stopped
}
finish() {
  rc=$?
  [ -n "$CHILD" ] && kill "$CHILD" 2>/dev/null || true
  [ "$STARTED" = 1 ] && [ "$rc" -ge 128 ] && { stop_remote >/dev/null || true; }
  erp_journal end "rc=$rc elapsed=$(( $(erp_now) - START ))s"
  erp_unregister_run
  exit "$rc"
}
trap finish EXIT
trap 'exit 130' INT
trap 'exit 143' TERM HUP

erp_ensure_db "$DB" || exit $?

stop_remote | grep -q stopped && erp_note "stopped a stale test process left on $DB by a killed run."

run() {
  # A plain background command (not a shell function): the shell forks and execs docker at once, so no leftover subshell can keep
  # a lock descriptor open after the wrapper is killed. The explicit closes cover the exec'd docker client itself.
  docker compose -p erp run --rm --no-deps -T --name "$RUNNER" -u "$(id -u):$(id -g)" -e HOME=/tmp -e "ERP_CHECKOUT=$ERP_ROOT" \
    -e TEST_DB_HOST=postgres-test -e TEST_DB_PORT=5432 -e "TEST_DB_DATABASE=$DB" -e "TEST_APPLICATION_NAME=erp-test:${ERP_AGENT:-unset}:$DB" \
    -v "$VENDOR_VOLUME:/var/www/html/vendor:ro" --entrypoint env app "$@" 6>&- 7>&- 8>&- 9>&- &
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
