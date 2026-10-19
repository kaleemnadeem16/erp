#!/bin/sh
# The approved way to run Playwright browser specs against the shared dev stack (Vite :5176, nginx :8090, the dev database).
#
#   ERP_AGENT=claude docker/frontend/run-e2e.sh settings-index.spec.ts --project=chromium
#
# One browser run at a time: they share the dev database, the sign-in throttle (5 a minute), the saved login in
# frontend/apps/e2e/.auth and the ports, so two overlapping runs corrupt each other's results. A second request waits (and says
# who is running); ERP_TEST_LOCK_WAIT=0 fails at once instead. It also holds the shared stack lock, so nobody restarts the stack
# under it. Pure-function specs (`--project=unit`) need no stack and are not locked. Locks are flock(1): freed when the run dies.
set -e
. "$(dirname "$0")/../backend/test-lib.sh"
DB=dev-stack
case " $* " in
  *" --project=unit "*|*" --project unit "*) cd "$ERP_ROOT/frontend/apps/e2e" && exec npx playwright test "$@" ;;
esac
WHAT="browser specs against the shared dev stack: $*"
erp_lock stack 7 -s "$WHAT (the Docker stack is being restarted)"
erp_lock e2e 8 -x "$WHAT (only one browser run at a time)"
erp_register_run "$WHAT"
erp_journal start "e2e args=$*"
START=$(erp_now)
finish() { rc=$?; [ -n "$CHILD" ] && kill "$CHILD" 2>/dev/null || true; erp_journal end "e2e rc=$rc elapsed=$(( $(erp_now) - START ))s"; erp_unregister_run; exit "$rc"; }
trap finish EXIT
trap 'exit 130' INT
trap 'exit 143' TERM HUP
cd "$ERP_ROOT/frontend/apps/e2e"
npx playwright test "$@" 6>&- 7>&- 8>&- 9>&- &
CHILD=$!
wait "$CHILD" && rc=0 || rc=$?
CHILD=
exit $rc
