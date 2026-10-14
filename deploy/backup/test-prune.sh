#!/usr/bin/env bash
# Tests for prune.sh: run `deploy/backup/test-prune.sh`. Uses a temporary directory only.
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
T="$(mktemp -d)"; trap 'rm -rf "${T:?}"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }

mk() { : > "$T/erp-$1T213000Z.dump"; : > "$T/erp-$1T213000Z.dump.sha256"; : > "$T/erp-$1T213000Z.json"; }
# 400 consecutive days ending 2026-10-14
for i in $(seq 0 399); do mk "$(date -u -d "2026-10-14 - $i days" +%Y%m%d)"; done
[ "$(ls "$T"/*.dump | wc -l)" -eq 400 ] || fail "fixture"

# 1. dry run deletes nothing
"$HERE/prune.sh" "$T" --dry-run >/dev/null
[ "$(ls "$T"/*.dump | wc -l)" -eq 400 ] || fail "dry run removed files"

# 2. real run keeps 14 daily + 8 weekly + 12 monthly at most, newest first, companions follow
KEEP_DAILY=14 KEEP_WEEKLY=8 KEEP_MONTHLY=12 "$HERE/prune.sh" "$T" >/dev/null
n=$(ls "$T"/*.dump | wc -l)
[ "$n" -ge 14 ] && [ "$n" -le 34 ] || fail "kept $n dumps, expected 14..34"
[ -e "$T/erp-20261014T213000Z.dump" ] || fail "newest dump was deleted"
[ -e "$T/erp-20261001T213000Z.dump" ] || fail "a daily within 14 days was deleted"
[ ! -e "$T/erp-20260101T213000Z.dump" ] || fail "an old non-monthly dump survived"
[ "$(ls "$T"/*.dump.sha256 | wc -l)" -eq "$n" ] || fail "checksum companions out of step"
[ "$(ls "$T"/*.json | wc -l)" -eq "$n" ] || fail "manifest companions out of step"
ls "$T"/erp-202512*.dump >/dev/null 2>&1 || fail "no monthly keeper for 2025-12"

# 3. a single dump is never deleted, even with every limit at zero
S="$(mktemp -d)"; : > "$S/erp-20261014T213000Z.dump"
KEEP_DAILY=0 KEEP_WEEKLY=0 KEEP_MONTHLY=0 "$HERE/prune.sh" "$S" >/dev/null
[ -e "$S/erp-20261014T213000Z.dump" ] || fail "the only dump was deleted"
rm -rf "${S:?}"

# 4. an empty directory and unrelated files are left alone
E2="$(mktemp -d)"; : > "$E2/notes.txt"; "$HERE/prune.sh" "$E2" >/dev/null; [ -e "$E2/notes.txt" ] || fail "unrelated file touched"; rm -rf "${E2:?}"
echo "prune.sh: all tests passed"
