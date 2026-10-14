#!/usr/bin/env bash
# Back up the ERP database: dump, verify, checksum, prune, then hand the file to any off-site destinations.
# Runs on mainvm from a systemd timer (docs/architecture/backup-and-restore.md); safe to run by hand.
#
# Secrets come from $BACKUP_ENV_FILE (default /srv/apps/erp/backup.env, mode 600, never in git):
#   BACKUP_DB_HOST  BACKUP_DB_PORT (5432)  BACKUP_DB_NAME (erp)  BACKUP_DB_USER  BACKUP_DB_PASSWORD
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib.sh
. "$HERE/lib.sh"
load_env
BACKUP_DIR="${BACKUP_DIR:-/srv/apps/erp/backups}"; STATE_FILE="${BACKUP_STATE_FILE:-$BACKUP_DIR/.state}"
: "${BACKUP_DB_HOST:?set BACKUP_DB_HOST in the env file}" "${BACKUP_DB_USER:?}" "${BACKUP_DB_PASSWORD:?}"
BACKUP_DB_PORT="${BACKUP_DB_PORT:-5432}"; BACKUP_DB_NAME="${BACKUP_DB_NAME:-erp}"
MIN_ENTRIES="${BACKUP_MIN_ENTRIES:-50}"     # a dump with fewer catalogue entries than this is treated as broken

umask 077
mkdir -p "$BACKUP_DIR"; chmod 700 "$BACKUP_DIR"
exec 9>"$BACKUP_DIR/.lock"
flock -n 9 || { log "another backup is running; exiting"; exit 0; }

started=$(date +%s); stamp="$(date -u +%Y%m%dT%H%M%SZ)"
partial="$BACKUP_DIR/.erp-$stamp.partial"; final="$BACKUP_DIR/erp-$stamp.dump"
fail() {
  log "BACKUP FAILED: $*" >&2
  rm -f -- "${partial:?}"
  state_set backup_last_run_success 0; write_metrics
  exit 1
}

log "dumping $BACKUP_DB_NAME from $BACKUP_DB_HOST:$BACKUP_DB_PORT as $BACKUP_DB_USER"
# The password reaches the container through the environment (-e NAME with no value), never on the command line.
PGPASSWORD="$BACKUP_DB_PASSWORD" docker run --rm -e PGPASSWORD "$PG_IMAGE" \
  pg_dump -h "$BACKUP_DB_HOST" -p "$BACKUP_DB_PORT" -U "$BACKUP_DB_USER" -d "$BACKUP_DB_NAME" -Fc --no-owner --no-privileges \
  > "$partial" || fail "pg_dump exited with an error"

[ -s "$partial" ] || fail "the dump is empty"
entries="$(docker run --rm -i "$PG_IMAGE" pg_restore --list < "$partial" | grep -vc '^;' || true)"
[ "${entries:-0}" -ge "$MIN_ENTRIES" ] || fail "the dump lists only ${entries:-0} objects (minimum $MIN_ENTRIES): treating it as broken"
tables="$(docker run --rm -i "$PG_IMAGE" pg_restore --list < "$partial" | grep -c ' TABLE public ' || true)"

mv -f "$partial" "$final"
( cd "$BACKUP_DIR" && sha256sum "$(basename "$final")" > "$(basename "$final").sha256" )
size=$(stat -c %s "$final"); duration=$(( $(date +%s) - started ))
cat > "${final%.dump}.json" <<JSON
{"file":"$(basename "$final")","created_utc":"$stamp","database":"$BACKUP_DB_NAME","host":"$BACKUP_DB_HOST","bytes":$size,"catalogue_entries":$entries,"tables":$tables,"sha256":"$(cut -d' ' -f1 "$final.sha256")","image":"$PG_IMAGE","seconds":$duration}
JSON
chmod 600 "$final" "$final.sha256" "${final%.dump}.json"
log "ok: $(basename "$final") $size bytes, $entries objects, $tables tables, ${duration}s"

state_set backup_last_success_ts "$(date +%s)"; state_set backup_last_run_success 1
state_set backup_last_size "$size"; state_set backup_last_duration "$duration"

# Off-site destinations: each executable *.sh in destinations.d/ receives the dump path. A failing destination is
# reported but never stops the others and never removes the local copy.
for hook in "$HERE"/destinations.d/*.sh; do
  [ -x "$hook" ] || continue
  name="$(basename "$hook" .sh)"
  if "$hook" "$final"; then
    state_set "dest_${name}_ts" "$(date +%s)"; log "destination $name: ok"
  else
    log "destination $name: FAILED (the local copy is kept)" >&2
  fi
done

"$HERE/prune.sh" "$BACKUP_DIR" | grep -c '^delete' | sed 's/^/pruned: /' || true
write_metrics
