#!/bin/sh
# Real-backend browser journeys: Playwright (project `real-backend`) against THIS checkout's actual Laravel application and a private, throwaway database.
#
#   ERP_AGENT=claude docker/frontend/run-real-backend-e2e.sh                       # every *.real.spec.ts
#   ERP_AGENT=claude docker/frontend/run-real-backend-e2e.sh sales-handover        # a spec filter
#   ERP_REAL_BACKEND_KEEP=1 ...                                                    # leave the server up afterwards and print how to stop it
#
# What it does (docs/architecture/real-backend-browser-tests.md): migrates the private database `erp_testing_<agent>_<workspace>_e2e` from scratch, builds the committed test
# world with the real Actions (tests/E2E/RealBackend/BuildWorldTest.php), starts the real application on a loopback-only port with `php -S` through the TEST-ONLY router
# tests/E2E/RealBackend/serve.php, runs the browser specs against it, then removes the server. It never touches the dev stack, the dev database or the shared saved login, so
# unlike docker/frontend/run-e2e.sh it is safe from a worktree: it runs THIS checkout's code.
set -e
. "$(dirname "$0")/../backend/test-lib.sh"
erp_check_checkout || exit 64
DB="$(erp_db_name)_e2e"
erp_validate_db "$DB" || exit 64
erp_check_vendor || exit 65
VENDOR_VOLUME=$(erp_vendor_volume)
WHAT="real-backend browser journeys on $DB"
START=$(erp_now)
SERVER="erp-e2e-$DB"
WORLD_REL="storage/e2e-world.json"
STARTED=0

erp_lock stack 7 -s "$WHAT (the Docker stack is being restarted)"
erp_lock "db-$DB" 6 -x "$WHAT (another run is using database $DB)"
erp_register_run "$WHAT"
erp_journal start "real-backend args=$*"

stop_server() { [ -n "$(docker ps -aq -f "name=^$SERVER\$")" ] && docker rm -f "$SERVER" >/dev/null 2>&1 || true; }
finish() {
  rc=$?
  [ -n "$CHILD" ] && kill "$CHILD" 2>/dev/null || true
  if [ -z "$ERP_REAL_BACKEND_KEEP" ]; then stop_server; else erp_note "server left running on 127.0.0.1:$PORT; stop it with: docker rm -f $SERVER"; fi
  [ -z "$ERP_REAL_BACKEND_KEEP" ] && rm -f "$ERP_ROOT/backend/$WORLD_REL"
  erp_journal end "real-backend rc=$rc elapsed=$(( $(erp_now) - START ))s"
  erp_unregister_run
  exit "$rc"
}
trap finish EXIT
trap 'exit 130' INT
trap 'exit 143' TERM HUP

erp_ensure_db "$DB" || exit $?
stop_server

# A free loopback port for this run.
PORT=${ERP_REAL_BACKEND_PORT:-}
if [ -z "$PORT" ]; then
  for p in $(seq 18090 18190); do
    (exec 3<>/dev/tcp/127.0.0.1/$p) 2>/dev/null || { PORT=$p; break; }
  done
fi
[ -n "$PORT" ] || { erp_note "no free port in 18090-18190"; exit 70; }

compose_run() { # compose_run <extra docker args> -- <command...>
  _extra=$1; shift; shift
  # shellcheck disable=SC2086
  ERP_VENDOR_VOLUME="$VENDOR_VOLUME" docker compose -p erp -f docker-compose.yml -f docker/backend/runner.compose.yml \
    run --rm --no-deps -T -u "$(id -u):$(id -g)" -e HOME=/tmp -e "ERP_CHECKOUT=$ERP_ROOT" \
    -e TEST_DB_HOST=postgres-test -e TEST_DB_PORT=5432 -e "TEST_DB_DATABASE=$DB" -e ERP_E2E_BACKEND=1 -e "ERP_E2E_WORLD_FILE=/var/www/html/$WORLD_REL" \
    $_extra --entrypoint env app "$@" 6>&- 7>&-
}

erp_note "migrating $DB and building the test world…"
compose_run "" -- php artisan migrate:fresh --database=testing --force --no-interaction >/dev/null
compose_run "" -- php artisan test tests/E2E/RealBackend/BuildWorldTest.php >/dev/null || { erp_note "building the test world failed; rerun without >/dev/null to see why:  docker/backend/run.sh ..."; exit 71; }
[ -s "$ERP_ROOT/backend/$WORLD_REL" ] || { erp_note "the test world was not written"; exit 71; }

erp_note "starting the real application on 127.0.0.1:$PORT…"
compose_run "--name $SERVER -p 127.0.0.1:$PORT:8099 -e PHP_CLI_SERVER_WORKERS=6" -- php -S 0.0.0.0:8099 -t public tests/E2E/RealBackend/serve.php >"$ERP_LOCK_DIR/$SERVER.log" 2>&1 &
up=0
for _ in $(seq 1 60); do
  if curl -fsS "http://127.0.0.1:$PORT/up" >/dev/null 2>&1; then up=1; break; fi
  sleep 1
done
[ "$up" = 1 ] || { erp_note "the real application did not come up; log: $ERP_LOCK_DIR/$SERVER.log"; tail -n 20 "$ERP_LOCK_DIR/$SERVER.log" >&2; exit 72; }

cd "$ERP_ROOT/frontend/apps/e2e"
E2E_REAL_BACKEND_URL="http://127.0.0.1:$PORT" E2E_REAL_BACKEND_WORLD="$ERP_ROOT/backend/$WORLD_REL" \
  npx playwright test --project=real-backend "$@" 6>&- 7>&- &
CHILD=$!
wait "$CHILD" && rc=0 || rc=$?
CHILD=
exit $rc
