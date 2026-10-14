#!/usr/bin/env bash
# Prove a backup can be restored: load the newest dump (or the one given) into a throwaway PostgreSQL container
# with no network, then check what came back. Never touches the real database or the shared server.
#
#   restore-test.sh [path/to/erp-YYYYMMDDTHHMMSSZ.dump]
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib.sh
. "$HERE/lib.sh"
load_env
BACKUP_DIR="${BACKUP_DIR:-/srv/apps/erp/backups}"; STATE_FILE="${BACKUP_STATE_FILE:-$BACKUP_DIR/.state}"
umask 077
[ -d "$BACKUP_DIR" ] || { echo "no backup directory $BACKUP_DIR" >&2; exit 1; }

dump="${1:-$(find "$BACKUP_DIR" -maxdepth 1 -type f -name 'erp-[0-9]*T[0-9]*Z.dump' | sort | tail -1)}"
name="erp-restore-test-$$"
cleanup() { docker rm -f "$name" >/dev/null 2>&1 || true; }
trap cleanup EXIT
fail() { log "RESTORE TEST FAILED: $*" >&2; state_set restore_last_run_success 0; write_metrics; exit 1; }

[ -n "$dump" ] && [ -f "$dump" ] || fail "no dump to test"
log "testing $(basename "$dump")"
( cd "$(dirname "$dump")" && sha256sum --check --status "$(basename "$dump").sha256" ) || fail "checksum does not match: the file changed since it was written"

# A random password for a container that has no network and is deleted at the end.
pw="$(head -c 24 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')"
docker run -d --rm --name "$name" --network none --memory 1g -e POSTGRES_PASSWORD="$pw" "$PG_IMAGE" >/dev/null || fail "could not start the scratch database"
for _ in $(seq 1 60); do docker exec "$name" pg_isready -U postgres -q 2>/dev/null && break; sleep 1; done
docker exec "$name" pg_isready -U postgres -q || fail "the scratch database did not start"
docker exec "$name" createdb -U postgres restore_test || fail "could not create the scratch database"

docker exec -i "$name" pg_restore -U postgres -d restore_test --no-owner --no-privileges --exit-on-error < "$dump" \
  || fail "pg_restore reported an error"

q() { docker exec "$name" psql -U postgres -d restore_test -Atc "$1"; }
tables="$(q "select count(*) from information_schema.tables where table_schema='public' and table_type='BASE TABLE'")"
migrations="$(q "select count(*) from migrations")"
expected="$(sed -n 's/.*"tables":\([0-9]*\).*/\1/p' "${dump%.dump}.json" 2>/dev/null || true)"
[ "${migrations:-0}" -ge 1 ] || fail "the migrations table is empty"
if [ -n "$expected" ] && [ "$tables" -ne "$expected" ]; then fail "restored $tables tables but the manifest says $expected"; fi
users="$(q "select count(*) from users")"; companies="$(q "select count(*) from companies")"
[ "$companies" -ge 1 ] || fail "no company rows came back"

log "ok: restored $(basename "$dump"): $tables tables, $migrations migrations, $companies company(ies), $users user(s)"
state_set restore_last_success_ts "$(date +%s)"; state_set restore_last_run_success 1
write_metrics
