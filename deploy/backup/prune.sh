#!/usr/bin/env bash
# Retention for the ERP database dumps (docs/architecture/backup-and-restore.md).
#
#   prune.sh <backup-dir> [--dry-run]
#
# Keeps, from files named erp-YYYYMMDDTHHMMSSZ.dump:
#   - the newest KEEP_DAILY dumps (default 14),
#   - the newest dump of each of the last KEEP_WEEKLY ISO weeks (default 8),
#   - the newest dump of each of the last KEEP_MONTHLY months (default 12).
# Everything else, with its .sha256 and .json companions, is deleted. The newest dump is never deleted, and
# nothing is deleted unless at least one dump is kept. Prints "keep" / "delete" per file.
set -euo pipefail

DIR="${1:?usage: prune.sh <backup-dir> [--dry-run]}"
DRY="${2:-}"
KEEP_DAILY="${KEEP_DAILY:-14}"
KEEP_WEEKLY="${KEEP_WEEKLY:-8}"
KEEP_MONTHLY="${KEEP_MONTHLY:-12}"

mapfile -t FILES < <(find "$DIR" -maxdepth 1 -type f -name 'erp-[0-9]*T[0-9]*Z.dump' | sort -r)   # newest first
[ "${#FILES[@]}" -gt 0 ] || { echo "no dumps in $DIR"; exit 0; }

declare -A KEEP=()
declare -A SEEN_WEEK=() SEEN_MONTH=()
daily=0 weekly=0 monthly=0
for f in "${FILES[@]}"; do
  base="$(basename "$f")"
  stamp="${base#erp-}"; stamp="${stamp%%T*}"               # YYYYMMDD
  day="${stamp:0:4}-${stamp:4:2}-${stamp:6:2}"
  week="$(date -u -d "$day" +%G-W%V)"
  month="${stamp:0:6}"
  if [ "$daily" -lt "$KEEP_DAILY" ]; then KEEP[$f]=1; daily=$((daily + 1)); fi
  if [ -z "${SEEN_WEEK[$week]:-}" ] && [ "$weekly" -lt "$KEEP_WEEKLY" ]; then SEEN_WEEK[$week]=1; KEEP[$f]=1; weekly=$((weekly + 1)); fi
  if [ -z "${SEEN_MONTH[$month]:-}" ] && [ "$monthly" -lt "$KEEP_MONTHLY" ]; then SEEN_MONTH[$month]=1; KEEP[$f]=1; monthly=$((monthly + 1)); fi
done
KEEP["${FILES[0]}"]=1                                       # the newest is always kept

for f in "${FILES[@]}"; do
  if [ -n "${KEEP[$f]:-}" ]; then echo "keep   $(basename "$f")"; continue; fi
  echo "delete $(basename "$f")"
  [ "$DRY" = "--dry-run" ] || rm -f -- "${f:?}" "${f:?}.sha256" "${f%.dump}.json"
done
