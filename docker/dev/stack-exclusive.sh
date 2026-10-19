#!/bin/sh
# Run a command that disturbs the shared dev stack (restart, `up --force-recreate`, rebuild) only when nobody is testing.
#
#   docker/dev/stack-exclusive.sh docker compose restart app
#
# Every backend test run and browser run holds a shared lock on the stack; this needs it exclusively, so it refuses (exit 75)
# and lists who is testing, rather than breaking their run. ERP_TEST_LOCK_WAIT=<seconds> makes it wait instead of failing.
# Recreating `postgres-test` wipes every agent's test database: it is covered by this rule too.
set -e
. "$(dirname "$0")/../backend/test-lib.sh"
[ $# -gt 0 ] || { erp_note "usage: stack-exclusive.sh <command...>"; exit 64; }
ERP_LOCK_WAIT="${ERP_TEST_LOCK_WAIT:-0}"
DB=n/a
if ! { exec 7>>"$ERP_LOCK_DIR/stack.lock"; flock -x -n 7; }; then
  erp_note "NOT RUNNING '$*': someone is testing against the shared stack right now:"
  erp_live_runs | sed 's/^/  /' >&2
  [ "$ERP_LOCK_WAIT" -eq 0 ] && exit 75
  erp_lock stack 7 -x "stack change: $*"
fi
erp_note "no tests running; running: $*"
"$@" 7>&-
