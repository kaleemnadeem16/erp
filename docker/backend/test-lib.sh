#!/bin/sh
# Shared helpers for the approved test wrappers (run-tests-tmpfs.sh, test-db.sh, docker/frontend/run-e2e.sh,
# docker/dev/stack-exclusive.sh). Source it; do not run it. See docs/architecture/environments.md "Test database on tmpfs".
#
# Locks are OS-managed flock(1) locks on files under $ERP_TEST_LOCK_DIR (default /tmp/erp-test-locks), which every
# agent sharing this machine sees. The kernel releases a lock the moment the holding process dies, however it dies
# (including kill -9), so a crashed run can never leave one behind. Descriptors used:
#   6 = this run's private database   7 = shared stack (shared while testing, exclusive while restarting)
#   8 = the browser suite             9 = the full backend suite

ERP_CALLER_PWD=$(pwd -P)
ERP_ROOT=$(cd "$(dirname "$0")/../.." && pwd -P)
cd "$ERP_ROOT"   # compose files are found from here; also lets a child be a plain `docker ...` command (see run() in the wrapper)
ERP_LOCK_DIR="${ERP_TEST_LOCK_DIR:-/tmp/erp-test-locks}"
mkdir -p "$ERP_LOCK_DIR/runs"
ERP_LOCK_WAIT="${ERP_TEST_LOCK_WAIT:-1500}"   # seconds a run waits for a lock; 0 = fail at once (exit 75)

# A worktree records who works in it (docker/dev/worktree.sh add writes .erp-agent), so ERP_AGENT need not be typed each time.
[ -z "${ERP_AGENT:-}" ] && [ -f "$ERP_ROOT/.erp-agent" ] && ERP_AGENT=$(head -n 1 "$ERP_ROOT/.erp-agent")

erp_note() { printf '%s\n' "$*" >&2; }

erp_slug() { printf '%s' "$1" | tr 'A-Z' 'a-z' | sed 's/[^a-z0-9][^a-z0-9]*/_/g; s/^_//; s/_$//' | cut -c1-30; }

# Who is running: ERP_AGENT (claude, codex, ...) plus the checkout, so a future worktree gets its own name too.
erp_agent() { erp_slug "${ERP_AGENT:-}"; }
erp_workspace() {
  ws=$(basename "$ERP_ROOT")
  [ "$ws" = erp ] && return 0
  erp_slug "${ws#erp-}"
}

# Stable, documented database name: erp_testing_<agent>_<workspace>; erp_testing_main when neither is known.
erp_db_name() {
  if [ -n "${TEST_DB_NAME:-}" ]; then printf '%s' "$TEST_DB_NAME"; return; fi
  name=$(printf '%s_%s' "$(erp_agent)" "$(erp_workspace)" | sed 's/^_//; s/_$//' | cut -c1-40)
  printf 'erp_testing_%s' "${name:-main}"
}

# Never the shared erp_testing, never a dev/system database; a bounded, boring identifier.
erp_validate_db() {
  case "$1" in
    erp_testing) erp_note "refused: 'erp_testing' is the shared database the whole team used to deadlock on. Set ERP_AGENT=<you> (e.g. claude, codex) or TEST_DB_NAME=erp_testing_<you>."; return 1 ;;
    erp_testing_*) ;;
    *) erp_note "refused: test database '$1' must start with 'erp_testing_'. Set ERP_AGENT=<you> or TEST_DB_NAME=erp_testing_<you>."; return 1 ;;
  esac
  if ! printf '%s' "$1" | grep -Eq '^erp_testing_[a-z0-9_]{1,40}$'; then
    erp_note "refused: '$1' must be lowercase letters, digits and underscores, at most 53 characters."; return 1
  fi
}

erp_now() { date +%s; }
erp_age() { # seconds since the epoch value in $1, as "12m05s"
  s=$(( $(erp_now) - $1 )); printf '%dm%02ds' $((s / 60)) $((s % 60))
}

# erp_lock <name> <fd> <-x|-s> <what-this-run-wants> -- blocks (announcing who holds it) up to ERP_LOCK_WAIT seconds.
erp_lock() {
  _name=$1; _fd=$2; _mode=$3; _what=$4
  _file="$ERP_LOCK_DIR/$_name.lock"
  eval "exec $_fd>>\"\$_file\""
  _waited=0
  if ! flock $_mode -n "$_fd"; then
    erp_note "WAITING: cannot start $_what yet. $(erp_holder "$_name")"
    [ "$ERP_LOCK_WAIT" -eq 0 ] && { erp_note "ERP_TEST_LOCK_WAIT=0, so not waiting. Nothing was run."; exit 75; }
    until flock $_mode -w 5 "$_fd"; do
      _waited=$((_waited + 5))
      [ $((_waited % 30)) -eq 0 ] && erp_note "STILL WAITING (${_waited}s): $_what. $(erp_holder "$_name")"
      if [ "$_waited" -ge "$ERP_LOCK_WAIT" ]; then
        erp_note "GAVE UP after ${_waited}s waiting for the '$_name' lock (ERP_TEST_LOCK_WAIT=$ERP_LOCK_WAIT). Nothing was run."; exit 75
      fi
    done
    erp_note "lock '$_name' acquired after ${_waited}s; starting."
  fi
  if [ "$_mode" = "-x" ]; then
    printf 'pid=%s agent=%s db=%s since=%s what=%s\n' "$$" "${ERP_AGENT:-unset}" "${DB:-n/a}" "$(erp_now)" "$_what" > "$_file.info"
  fi
}

erp_holder() { # human description of whoever holds lock $1
  if [ -s "$ERP_LOCK_DIR/$1.lock.info" ]; then
    _i=$(cat "$ERP_LOCK_DIR/$1.lock.info"); _since=${_i#*since=}; _since=${_since%% *}
    printf 'Held by: %s (running %s).' "$_i" "$(erp_age "$_since")"
  else
    printf 'Held by another run (no details recorded).'
  fi
}

# Run journal: one line per start/end, UTC, so a PostgreSQL crash time can be matched to what was running.
erp_journal() {
  _j="$ERP_LOCK_DIR/runs.log"
  printf '%s %s pid=%s agent=%s db=%s %s\n' "$(date -u +%FT%TZ)" "$1" "$$" "${ERP_AGENT:-unset}" "${DB:-n/a}" "$2" >> "$_j"
  [ "$(wc -l < "$_j")" -gt 2000 ] && { tail -n 1000 "$_j" > "$_j.tmp" && mv "$_j.tmp" "$_j"; }
  return 0
}

# Live runs (checked against the process table, so a killed run never lingers in the list).
erp_register_run() { printf 'pid=%s agent=%s db=%s what=%s since=%s\n' "$$" "${ERP_AGENT:-unset}" "${DB:-n/a}" "$1" "$(erp_now)" > "$ERP_LOCK_DIR/runs/$$.info"; }
erp_unregister_run() { rm -f "$ERP_LOCK_DIR/runs/$$.info"; }
erp_live_runs() {
  for f in "$ERP_LOCK_DIR"/runs/*.info; do
    [ -e "$f" ] || continue
    p=$(basename "$f" .info)
    if kill -0 "$p" 2>/dev/null; then cat "$f"; else rm -f "$f"; fi
  done
}

# Is this a whole-suite request? Anything narrowing the run (a filter, group, path) is a focused run.
erp_is_full() {
  for a in "$@"; do
    case "$a" in
      --filter*|--group*|--exclude-group*|--covers*) return 1 ;;
      --testsuite*) return 0 ;;
      -*) ;;
      tests|tests/|tests/Feature|tests/Feature/|tests/Unit|tests/Unit/) return 0 ;;
      *) return 1 ;;
    esac
  done
  return 0
}

# Compose helpers (always from the repository root; stdin closed so nothing blocks on a terminal).
# Always the shared project `erp` (a worktree's .env sets another COMPOSE_PROJECT_NAME on purpose, see docker/dev/worktree.sh),
# but with THIS checkout's compose file, so relative mounts (./backend) resolve to this checkout.
erp_dc() { docker compose -p erp "$@"; }

# Refuse to run a checkout's script from inside another checkout: that would test the wrong code.
erp_check_checkout() {
  _top=$(git -C "$ERP_CALLER_PWD" rev-parse --show-toplevel 2>/dev/null) || return 0
  _top=$(cd "$_top" && pwd -P)
  [ "$_top" = "$ERP_ROOT" ] && return 0
  erp_note "refused: you are in $_top but this script belongs to $ERP_ROOT, so it would run that checkout's code, not yours."
  erp_note "Run $_top/${0#"$ERP_ROOT"/} instead."
  return 1
}

# One-off container for THIS checkout: its own ./backend mounted at /var/www/html, the shared read-only vendor, the dev image, network
# and environment, running as you (so files it writes are yours). Nothing stays running afterwards. Extra docker-compose-run options
# can be passed in $ERP_RUN_OPTS (word-split on purpose), the command and its arguments follow.
erp_run() {
  # Shared vendor, read-only, unless this workspace has its own (docker/dev/worktree.sh vendor <name>).
  # shellcheck disable=SC2086
  docker compose -p erp run --rm --no-deps -T -u "$(id -u):$(id -g)" -e HOME=/tmp -e "ERP_CHECKOUT=$ERP_ROOT" \
    -v "$(erp_vendor_volume):/var/www/html/vendor:ro" $ERP_RUN_OPTS --entrypoint env app "$@"
}
erp_vendor_volume() {
  docker volume inspect "erp_vendor_$(erp_workspace_or_main)" >/dev/null 2>&1 && { printf 'erp_vendor_%s' "$(erp_workspace_or_main)"; return; }
  printf 'erp_backend_vendor'
}

# Does the vendor this checkout would run against match its composer.lock? (see vendor-check.php)
erp_check_vendor() {
  ERP_RUN_OPTS="-v $ERP_ROOT/docker/backend/vendor-check.php:/tmp/vendor-check.php:ro" erp_run php /tmp/vendor-check.php >/dev/null 2>"$ERP_LOCK_DIR/vendor-check.$$" && { rm -f "$ERP_LOCK_DIR/vendor-check.$$"; return 0; }
  _rc=$?
  if [ "$_rc" -eq 1 ]; then
    erp_note "refused: this checkout's composer.lock does not match the shared vendor, so tests would run against the wrong dependencies:"
    sed 's/^/  /' "$ERP_LOCK_DIR/vendor-check.$$" >&2
    erp_note "Give this workspace its own: docker/dev/worktree.sh vendor $(erp_workspace_or_main)"
  fi
  rm -f "$ERP_LOCK_DIR/vendor-check.$$"
  return "$_rc"
}
erp_workspace_or_main() { _w=$(erp_workspace); printf '%s' "${_w:-main}"; }
erp_psql() { erp_dc exec -T postgres-test psql -U erp -d postgres -v ON_ERROR_STOP=1 -tA "$@"; }

erp_tmpfs_use() { erp_dc exec -T postgres-test df -P /var/lib/postgresql/data | awk 'NR==2 {gsub("%","",$5); print $5}'; }
