#!/bin/sh
# Read-only: list every unexpected exit of the test Postgres (a backend "exited with exit code N", "terminated by signal N",
# a PANIC, "No space left") with the test runs that were live around it, from the run journal the wrappers keep.
#
#   docker/backend/pg-crash-report.sh            # events in the retained log (docker keeps 5 x 20 MB)
#   docker/backend/pg-crash-report.sh 200        # only the last 200 lines
#
# For each event it prints the server log lines (time, PID, application name = erp-test:<agent>:<database>, the statement that was
# running when PostgreSQL recorded one) and every journal start/end within 15 minutes before to 1 minute after it. A SIGQUIT sent to
# one backend leaves the signature `exited with exit code 2` plus, since 2026-10-19, that backend's own
# "terminating connection because of unexpected SIGQUIT signal" line (docs/architecture/environments.md, "Unexplained backend exits").
. "$(dirname "$0")/test-lib.sh"
LOG=$(mktemp); trap 'rm -f "$LOG"' EXIT
if [ -n "$1" ]; then docker logs --tail "$1" erp-postgres-test-1 > "$LOG" 2>&1; else docker logs erp-postgres-test-1 > "$LOG" 2>&1; fi
J="$ERP_LOCK_DIR/runs.log"
n=0
grep -nE "server process \(PID [0-9]+\) (exited with exit code [02-9]|was terminated by signal)|PANIC|No space left on device|unexpected SIGQUIT" "$LOG" | while IFS=: read -r line rest; do
  n=$((n + 1))
  ts=$(sed -n "${line}p" "$LOG" | cut -c1-19)
  echo "=== $(sed -n "${line}p" "$LOG" | cut -c1-200)"
  sed -n "$((line + 1)),$((line + 2))p" "$LOG" | grep -E "DETAIL|Failed process" | cut -c1-200
  if [ -s "$J" ]; then
    t=$(date -u -d "$ts" +%s 2>/dev/null) || continue
    lo=$(date -u -d "@$((t - 900))" +%FT%TZ); hi=$(date -u -d "@$((t + 60))" +%FT%TZ)
    awk -v lo="$lo" -v hi="$hi" '$1 >= lo && $1 <= hi { print "    journal: " $0 }' "$J" | cut -c1-200
  fi
done
echo "(events listed above; none means the retained log holds no unexpected server exit)"
