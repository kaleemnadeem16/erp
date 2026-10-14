# Shared helpers for backup.sh and restore-test.sh. Sourced, not executed.

log() { printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"; }

# Reads KEY=VALUE secrets (the database role, never in git) into the environment.
load_env() {
  local f="${BACKUP_ENV_FILE:-/srv/apps/erp/backup.env}"
  if [ -f "$f" ]; then set -a; . "$f"; set +a; fi
}

BACKUP_DIR="${BACKUP_DIR:-/srv/apps/erp/backups}"
PG_IMAGE="${PG_IMAGE:-postgres:16-alpine}"
STATE_FILE="${BACKUP_STATE_FILE:-$BACKUP_DIR/.state}"
BACKUP_METRICS_FILE="${BACKUP_METRICS_FILE:-}"

# --- state: the last known outcome of each activity, kept across runs so a failure does not erase the last success.
state_get() { [ -f "$STATE_FILE" ] && sed -n "s/^$1=//p" "$STATE_FILE" | tail -1 || true; }
state_set() {
  local k="$1" v="$2" tmp
  tmp="$(mktemp "$STATE_FILE.XXXXXX")"
  { [ -f "$STATE_FILE" ] && grep -v "^$k=" "$STATE_FILE" || true; echo "$k=$v"; } > "$tmp"
  mv -f "$tmp" "$STATE_FILE"
}

# --- Prometheus textfile metrics (node-exporter --collector.textfile.directory). Written atomically; a no-op if unset.
write_metrics() {
  [ -n "$BACKUP_METRICS_FILE" ] || return 0
  local tmp dumps
  tmp="$(mktemp "$BACKUP_METRICS_FILE.XXXXXX")"
  dumps="$(find "$BACKUP_DIR" -maxdepth 1 -type f -name 'erp-[0-9]*T[0-9]*Z.dump' | wc -l)"
  {
    echo "# HELP erp_backup_last_success_timestamp_seconds Unix time of the last successful ERP database backup."
    echo "# TYPE erp_backup_last_success_timestamp_seconds gauge"
    echo "erp_backup_last_success_timestamp_seconds $(state_get backup_last_success_ts || true)"
    echo "# HELP erp_backup_last_run_success 1 if the most recent backup run succeeded, 0 if it failed."
    echo "# TYPE erp_backup_last_run_success gauge"
    echo "erp_backup_last_run_success $(state_get backup_last_run_success)"
    echo "# HELP erp_backup_last_size_bytes Size of the most recent successful dump."
    echo "# TYPE erp_backup_last_size_bytes gauge"
    echo "erp_backup_last_size_bytes $(state_get backup_last_size)"
    echo "# HELP erp_backup_last_duration_seconds How long the most recent successful backup took."
    echo "# TYPE erp_backup_last_duration_seconds gauge"
    echo "erp_backup_last_duration_seconds $(state_get backup_last_duration)"
    echo "# HELP erp_backup_dumps Dumps currently kept locally."
    echo "# TYPE erp_backup_dumps gauge"
    echo "erp_backup_dumps $dumps"
    echo "# HELP erp_backup_restore_test_last_success_timestamp_seconds Unix time of the last successful restore test."
    echo "# TYPE erp_backup_restore_test_last_success_timestamp_seconds gauge"
    echo "erp_backup_restore_test_last_success_timestamp_seconds $(state_get restore_last_success_ts)"
    echo "# HELP erp_backup_restore_test_last_run_success 1 if the most recent restore test passed, 0 if it failed."
    echo "# TYPE erp_backup_restore_test_last_run_success gauge"
    echo "erp_backup_restore_test_last_run_success $(state_get restore_last_run_success)"
    echo "# HELP erp_backup_destination_last_success_timestamp_seconds Unix time of the last successful copy to an off-site destination."
    echo "# TYPE erp_backup_destination_last_success_timestamp_seconds gauge"
    if [ -f "$STATE_FILE" ]; then
      sed -n 's/^dest_\(.*\)_ts=\(.*\)$/erp_backup_destination_last_success_timestamp_seconds{destination="\1"} \2/p' "$STATE_FILE"
    fi
  } | sed 's/ $/ 0/' > "$tmp"
  chmod 644 "$tmp"
  mv -f "$tmp" "$BACKUP_METRICS_FILE"
}
