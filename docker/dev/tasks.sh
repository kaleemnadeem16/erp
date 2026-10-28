#!/usr/bin/env bash
# The agents' shared task register: GitHub Issues, driven through `gh` so the conventions are the same for everyone.
# Permanent decisions live in docs/ and ADRs; this is only for work in flight. The repository is PUBLIC: never put secrets, credentials,
# customer data or production hostnames in an issue; link to docs instead of pasting them.
#
#   tasks.sh board                         every open task: status, priority, owner, workstream, dependencies (unmet ones flagged), branch
#   tasks.sh mine | inbox [--seen]         your open tasks / what changed for you since you last said --seen
#   tasks.sh show N | deps N
#   tasks.sh new --title T --ws W [--prio p1] [--depends "#1,#2"] [--specs …] [--hotspots …] [--needs-auth] [--needs-decision]   (coordinator)
#   tasks.sh claim N [--branch B]          take a Ready task: refused if owned, gated, unmet dependencies, or the workstream is already active
#   tasks.sh release N | status N <ready|in-progress|blocked|in-review|integrated> [-m text] | block N --on M | unblock N
#   tasks.sh report --title T --ws W --against "…" --expected "…" --actual "…" --evidence "…" [--blocks N] [--follow-up]
#   tasks.sh triage N --owner Y [--investigator Z] [-m text]       (coordinator) route a reported issue to its owner
#   tasks.sh outcome N <defect|missing-contract|misunderstanding|duplicate|owner-decision> -m text          (investigator)
#   tasks.sh submit N --branch B --tests "…" [--cross-module yes|no] [--notes …]   (owner) evidence + read-only readiness report → In Review
#   tasks.sh approve N SHA --ref "owner message"   (coordinator) records the owner's approval of exactly that commit
#   tasks.sh reassign N --to Y --reason "…"        (coordinator) | tasks.sh close N -m text | tasks.sh init   (create the labels, idempotent)
#
# Identity: --as NAME, else $ERP_AGENT, else the .erp-agent file of your checkout. Roles are by convention, not security: the coordinator is
# the name in $ERP_COORDINATOR (default "coordinator"). Status is a `status:*` label (Closed is the closed state); there is no polling service —
# agents look at the board and their inbox at the checkpoints in docs/architecture/agent-coordination.md.
set -euo pipefail
CALLER_TOP=$(git rev-parse --show-toplevel 2>/dev/null || pwd)
COMMON=$(git rev-parse --path-format=absolute --git-common-dir); MAIN=$(cd "$COMMON/.." && pwd -P)
cd "$MAIN"
LOCKDIR="${ERP_TEST_LOCK_DIR:-/tmp/erp-test-locks}"; mkdir -p "$LOCKDIR/checkpoints"
COORD="${ERP_COORDINATOR:-coordinator}"
STATUSES="ready in-progress blocked in-review integrated"
die() { echo "tasks: $*" >&2; exit 1; }
# GitHub's API drops a connection now and then ("tls: bad record MAC", resets, 5xx). Reads and idempotent edits are retried; creating an issue or
# a comment is NOT (the first attempt may have succeeded), so for those a failure prints the error and you check the issue before repeating.
gh() {
  local i rc err sub="$1 ${2:-}"; err=$(mktemp)
  case "$sub" in "issue view"|"issue list"|"issue edit"|"issue close"|"label create"|"run list"|"label list") ;; *) command gh "$@" 2>"$err" && { rm -f "$err"; return 0; } || rc=$?; cat "$err" >&2; rm -f "$err"; return "$rc" ;; esac
  for i in 1 2 3 4; do
    command gh "$@" 2>"$err" && { rm -f "$err"; return 0; } || rc=$?
    grep -qiE 'tls|connection reset|timeout|EOF|50[234]|temporarily|unexpected end' "$err" || break
    sleep $((i * 2))
  done
  cat "$err" >&2; rm -f "$err"; return "$rc"
}

AS=""
args=(); while [ $# -gt 0 ]; do case "$1" in --as) AS="$2"; shift 2 ;; *) args+=("$1"); shift ;; esac; done
set -- "${args[@]+"${args[@]}"}"
ME="${AS:-${ERP_AGENT:-$( [ -f "$CALLER_TOP/.erp-agent" ] && head -n 1 "$CALLER_TOP/.erp-agent" || true )}}"
need_me() { [ -n "$ME" ] || die "who are you? pass --as NAME, set ERP_AGENT, or run from a worktree that has .erp-agent"; }
need_coord() { need_me; [ "$ME" = "$COORD" ] || die "only the coordinator ($COORD) does this; you are '$ME' (set --as $COORD if that is you)"; }

num() { printf '%s' "${1#\#}" | grep -Eq '^[0-9]+$' || die "not an issue number: $1"; printf '%s' "${1#\#}"; }
labels() { gh issue view "$1" --json labels --jq '[.labels[].name] | join(" ")'; }
has() { case " $(labels "$1") " in *" $2 "*) return 0 ;; *) return 1 ;; esac; }
field() { gh issue view "$1" --json body --jq '.body' | grep -m1 -i "^\*\*$2:\*\*" | sed -E "s/^\*\*[^:]*:\*\*[[:space:]]*//" || true; }
owner_of() { labels "$1" | tr ' ' '\n' | grep '^owner:' | sed 's/^owner://' | head -n1 || true; }
ws_of() { labels "$1" | tr ' ' '\n' | grep '^ws:' | head -n1 || true; }
# Comments and creations are retried only after checking the first attempt did not already land (no duplicates).
say() { # N body
  local i; for i in 1 2 3 4; do
    if command gh issue comment "$1" --body "$2" >/dev/null 2>"$LOCKDIR/err.$$"; then rm -f "$LOCKDIR/err.$$"; return 0; fi
    sleep $((i * 2))
    gh issue view "$1" --json comments --jq '[.comments[-3:][].body] | any(. == '"$(printf '%s' "$2" | python3 -c 'import json,sys; print(json.dumps(sys.stdin.read()))')"')' 2>/dev/null | grep -qx true && { rm -f "$LOCKDIR/err.$$"; return 0; }
  done
  cat "$LOCKDIR/err.$$" >&2; rm -f "$LOCKDIR/err.$$"; die "could not post the comment on #$1 (GitHub kept failing); check the issue and repeat"
}
create_issue() { # title body label... -> prints the issue URL
  local title="$1" body="$2" i url; shift 2; local largs=(); for l in "$@"; do largs+=(--label "$l"); done
  for i in 1 2 3 4; do
    if url=$(command gh issue create --title "$title" --body "$body" "${largs[@]}" 2>"$LOCKDIR/err.$$"); then rm -f "$LOCKDIR/err.$$"; echo "$url"; return 0; fi
    sleep $((i * 2))
    url=$(gh issue list --state open --limit 5 --search "\"$title\" in:title" --json url,title --jq ".[] | select(.title == $(printf '%s' "$title" | python3 -c 'import json,sys; print(json.dumps(sys.stdin.read()))')) | .url" 2>/dev/null | head -n1 || true)
    [ -z "$url" ] || { rm -f "$LOCKDIR/err.$$"; echo "$url"; return 0; }
  done
  cat "$LOCKDIR/err.$$" >&2; rm -f "$LOCKDIR/err.$$"; die "could not create the issue (GitHub kept failing); check the board before repeating"
}
ensure_label() { gh label create "$1" --color "${2:-ededed}" --description "${3:-}" --force >/dev/null 2>&1 || true; }
set_status() { # N status
  local other="" x; for x in $STATUSES; do [ "$x" = "$2" ] || other="$other,status:$x"; done
  ensure_label "status:$2" 0e8a16 "Task status"
  gh issue edit "$1" --add-label "status:$2" --remove-label "${other#,}" >/dev/null 2>&1 || gh issue edit "$1" --add-label "status:$2" >/dev/null
}
set_field() { # N field value   (rewrites the **field:** line in the body, adds it when missing)
  local body; body=$(gh issue view "$1" --json body --jq '.body')
  if printf '%s\n' "$body" | grep -qi "^\*\*$2:\*\*"; then
    body=$(printf '%s\n' "$body" | sed -E "s|^(\*\*$2:\*\*).*|\1 $3|I")
  else body=$(printf '**%s:** %s\n%s' "$2" "$3" "$body"); fi
  printf '%s\n' "$body" > "$LOCKDIR/body.$$"; gh issue edit "$1" --body-file "$LOCKDIR/body.$$" >/dev/null; rm -f "$LOCKDIR/body.$$"
}
add_dep() { # N M : append #M to N's "Depends on" line
  local cur; cur=$(field "$1" "Depends on")
  case "$cur" in ""|none*|None*) cur="#$2" ;; *) printf '%s' "$cur" | grep -q "#$2\b" || cur="$cur, #$2" ;; esac
  set_field "$1" "Depends on" "$cur"
}
# unmet dependencies of N, one per line "#M (state/status)"; empty when all are integrated or closed
unmet() {
  local deps m st stat; deps=$(field "$1" "Depends on" | grep -oE '#[0-9]+' || true)
  for d in $deps; do m=${d#\#}
    st=$(gh issue view "$m" --json state --jq .state 2>/dev/null || echo MISSING); stat=$(labels "$m" 2>/dev/null | tr ' ' '\n' | grep '^status:' | head -n1 || true)
    [ "$st" = CLOSED ] && continue; [ "$stat" = "status:integrated" ] && continue
    echo "#$m ($st ${stat:-no status})"
  done
}

cmd="${1:-}"; [ $# -gt 0 ] && shift || true
case "$cmd" in
init)
  for s in ready:0e8a16 in-progress:1d76db blocked:b60205 in-review:fbca04 integrated:5319e7; do ensure_label "status:${s%%:*}" "${s##*:}" "Task status"; done
  for p in p0:b60205:"blocks others / urgent" p1:d93f0b:normal p2:fef2c0:"nice to have / follow-up"; do IFS=: read -r n c d <<<"$p"; ensure_label "prio:$n" "$c" "$d"; done
  for t in task defect question follow-up; do ensure_label "type:$t" c5def5 "Kind of issue"; done
  for g in "needs:authorisation|e99695|Owner has not authorised this work yet" "needs:owner-decision|e99695|A business decision is required" "needs:triage|fbca04|Coordinator must route this" "approved|0e8a16|Owner approved the commit recorded in the APPROVED comment" "test-fixture|ededed|Temporary issue used to validate the tooling" \
           "outcome:defect|d73a4a|Investigation: genuine defect" "outcome:missing-contract|d73a4a|Investigation: missing contract" "outcome:misunderstanding|cfd3d7|Investigation: misunderstanding" "outcome:duplicate|cfd3d7|Investigation: duplicate"; do IFS='|' read -r n c d <<<"$g"; ensure_label "$n" "$c" "$d"; done
  echo "labels ready" ;;
board)
  sel='select(any(.labels[]; .name=="test-fixture") | not)'; [ -z "${TASKS_SHOW_FIXTURES:-}" ] || sel='select(true)'
  gh issue list --state all --limit 500 --json number,state,title,labels,body --jq ".[] | $sel"'
    | [ .number, .state,
        ((first(.labels[].name | select(startswith("status:"))) // "-") | sub("status:";"")),
        ((first(.labels[].name | select(startswith("prio:"))) // "-") | sub("prio:";"")),
        ((first(.labels[].name | select(startswith("owner:"))) // "-") | sub("owner:";"")),
        ((first(.labels[].name | select(startswith("ws:"))) // "-") | sub("ws:";"")),
        ([.labels[].name | select(startswith("needs:") or . == "approved") ] | join("+")),
        ((.body // "") | capture("(?i)Depends on:\\*\\*[ \\t]*(?<d>[^\\r\\n]*)").d // ""),
        ((.body // "") | capture("(?i)Branch / workspace:\\*\\*[ \\t]*(?<b>[^\\r\\n]*)").b // ""),
        .title ] | @tsv' > "$LOCKDIR/board.$$"
  awk -F'\t' 'BEGIN{OFS="\t"} {st[$1]=$2; ss[$1]=$3; row[NR]=$0; n=NR}
    END { order["ready"]=2; order["in-progress"]=1; order["blocked"]=0; order["in-review"]=3; order["integrated"]=4;
      printf "%-5s %-12s %-4s %-12s %-14s %-24s %s\n","#","STATUS","PRIO","OWNER","WORKSTREAM","DEPENDS ON","TITLE / GATES / BRANCH";
      for (o=0;o<=5;o++) for (i=1;i<=n;i++) { split(row[i],f,"\t"); if (f[2]!="OPEN") continue; k=(f[3] in order)?order[f[3]]:5; if (k!=o) continue;
        deps=""; m=split(f[8],d,/[^0-9]+/); for (j=1;j<=m;j++) if (d[j]!="") { x=d[j]; ok=(x in st) && (st[x]=="CLOSED" || ss[x]=="integrated"); deps=deps "#" x (ok?"✓ ":"✗ ") }
        gates=(f[7]!="")?" ["f[7]"]":""; br=(f[9]!="" && f[9] !~ /^\(/)?"  ⎇ "f[9]:"";
        printf "%-5s %-12s %-4s %-12s %-14s %-24s %s%s%s\n","#"f[1],f[3],f[4],f[5],f[6],(deps==""?"-":deps),f[10],gates,br } }' "$LOCKDIR/board.$$"
  rm -f "$LOCKDIR/board.$$"; echo "(✓ = dependency integrated or closed, ✗ = still open)" ;;
mine) need_me; gh issue list --label "owner:$ME" --state open --json number,title,labels --jq '.[] | "#\(.number) [\(first(.labels[].name | select(startswith("status:"))) // "-")] \(.title)"' ;;
inbox)
  need_me; cp="$LOCKDIR/checkpoints/$ME"; since=$( [ -f "$cp" ] && cat "$cp" || echo "1970-01-01T00:00:00Z" ); now=$(date -u +%FT%TZ)
  echo "since $since (you are $ME)"
  for l in "owner:$ME" "investigator:$ME" "reporter:$ME"; do
    gh issue list --label "$l" --state all --search "updated:>$since" --json number,state,title,updatedAt --jq ".[] | \"#\(.number) \(.state) \(.updatedAt) [$l] \(.title)\""
  done | sort -u
  if [ "${1:-}" = "--seen" ]; then echo "$now" > "$cp"; echo "(checkpoint set to $now)"; fi ;;
deps) n=$(num "${1:?deps N}"); u=$(unmet "$n"); if [ -n "$u" ]; then echo "unmet dependencies of #$n:"; echo "$u"; exit 1; fi; echo "all dependencies of #$n are integrated or closed" ;;
show) n=$(num "${1:?show N}") # --json avoids the default view's projectCards GraphQL call, which GitHub is sunsetting
  gh issue view "$n" --json number,title,state,labels,body,comments --jq '"#\(.number) [\(.state)] \(.title)\nlabels: \([.labels[].name] | join(", "))\n\n\(.body)\n" + (.comments | map("\n--- \(.author.login) \(.createdAt)\n\(.body)") | join("\n"))' ;;
new)
  need_coord; title=""; ws=""; prio="p1"; deps="none"; specs=""; hot=""; gate=()
  while [ $# -gt 0 ]; do case "$1" in --title) title="$2"; shift 2 ;; --ws) ws="$2"; shift 2 ;; --prio) prio="$2"; shift 2 ;; --depends) deps="$2"; shift 2 ;; --specs) specs="$2"; shift 2 ;; --hotspots) hot="$2"; shift 2 ;;
    --needs-auth) gate+=(--label needs:authorisation); shift ;; --needs-decision) gate+=(--label needs:owner-decision); shift ;; *) die "unknown option $1" ;; esac; done
  [ -n "$title" ] && [ -n "$ws" ] || die "new needs --title and --ws"
  ensure_label "ws:$ws" bfdadc "Workstream"
  body=$(printf '**Workstream:** %s\n**Depends on:** %s\n**Branch / workspace:** (set when claimed)\n**Specs / ADRs:** %s\n**Shared hotspots touched:** %s\n\n## Scope\n(fill in)\n\n## Out of scope\n(fill in)\n\n## Evidence required to submit\nSee the test matrix in docs/architecture/agent-coordination.md.\n' "$ws" "$deps" "${specs:-}" "${hot:-}")
  gl=(); for g in ${gate[@]+"${gate[@]}"}; do [ "$g" = --label ] || gl+=("$g"); done
  create_issue "$title" "$body" type:task "ws:$ws" "prio:$prio" status:ready ${gl[@]+"${gl[@]}"} ;;
claim)
  need_me; n=$(num "${1:?claim N}"); shift; br=""; while [ $# -gt 0 ]; do case "$1" in --branch) br="$2"; shift 2 ;; *) die "unknown option $1" ;; esac; done
  [ "$(gh issue view "$n" --json state --jq .state)" = OPEN ] || die "#$n is closed"
  L=" $(labels "$n") "
  case "$L" in *" needs:authorisation "*|*" needs:owner-decision "*|*" needs:triage "*) die "#$n is gated ($L): the owner or coordinator must clear it first" ;; esac
  case "$L" in *" status:ready "*) ;; *) die "#$n is not Ready (labels:$L)" ;; esac
  # A task the coordinator pre-assigned to YOU (an owner: label equal to your name) is yours to claim; anyone else's is not.
  o=$(owner_of "$n"); { [ -z "$o" ] || [ "$o" = "$ME" ]; } || die "#$n already has an owner: $o"
  u=$(unmet "$n"); [ -z "$u" ] || die "#$n has unmet dependencies: $(echo "$u" | tr '\n' ' ')"
  w=$(ws_of "$n"); [ -n "$w" ] || die "#$n has no ws: label; ask the coordinator"
  if case "$L" in *" type:task "*) true ;; *) false ;; esac; then
    clash=$(gh issue list --label "$w" --label type:task --state open --json number,labels --jq ".[] | select(.number != $n) | select(any(.labels[]; .name==\"status:in-progress\" or .name==\"status:in-review\" or .name==\"status:blocked\")) | select(any(.labels[]; .name | startswith(\"owner:\") and . != \"owner:$ME\")) | \"#\(.number) (\(first(.labels[].name | select(startswith(\"owner:\")))))\"")
    [ -z "$clash" ] || die "workstream $w is already active under another owner: $clash — one accountable owner per workstream; ask the coordinator"
  fi
  [ -n "$br" ] || br=$(git -C "$CALLER_TOP" rev-parse --abbrev-ref HEAD 2>/dev/null || true)
  ensure_label "owner:$ME" fef2c0 "Accountable owner"
  gh issue edit "$n" --add-label "owner:$ME" >/dev/null; set_status "$n" in-progress
  [ "$(labels "$n" | tr ' ' '\n' | grep -c '^owner:')" -eq 1 ] || { gh issue edit "$n" --remove-label "owner:$ME" >/dev/null; die "someone claimed #$n at the same moment; yours was withdrawn. Check the board"; }
  set_field "$n" "Branch / workspace" "$br  $CALLER_TOP"
  say "$n" "Claimed by **$ME**. Branch \`$br\`, workspace \`$CALLER_TOP\`."; echo "claimed #$n as $ME on $br" ;;
release) need_me; n=$(num "${1:?release N}"); [ "$(owner_of "$n")" = "$ME" ] || [ "$ME" = "$COORD" ] || die "only the owner or the coordinator can release #$n"
  o=$(owner_of "$n"); [ -z "$o" ] || gh issue edit "$n" --remove-label "owner:$o" >/dev/null; set_status "$n" ready; say "$n" "Released by **$ME** (was owned by ${o:-nobody}). Back to Ready."; echo "released #$n" ;;
reassign) need_coord; n=$(num "${1:?reassign N --to Y --reason …}"); shift; to=""; why=""; while [ $# -gt 0 ]; do case "$1" in --to) to="$2"; shift 2 ;; --reason) why="$2"; shift 2 ;; *) die "unknown option $1" ;; esac; done
  [ -n "$to" ] && [ -n "$why" ] || die "reassign needs --to and --reason"; o=$(owner_of "$n"); [ -z "$o" ] || gh issue edit "$n" --remove-label "owner:$o" >/dev/null
  ensure_label "owner:$to" fef2c0 "Accountable owner"; gh issue edit "$n" --add-label "owner:$to" >/dev/null; say "$n" "Ownership changed by **$ME**: ${o:-nobody} → **$to**. Reason: $why"; echo "#$n now owned by $to" ;;
status)
  need_me; n=$(num "${1:?status N <status>}"); s="${2:?status}"; shift 2; msg=""; [ "${1:-}" = "-m" ] && msg="${2:-}"
  case " $STATUSES " in *" $s "*) ;; *) die "status must be one of: $STATUSES" ;; esac
  [ "$ME" = "$COORD" ] || [ "$(owner_of "$n")" = "$ME" ] || die "only the owner ($(owner_of "$n")) or the coordinator changes #$n"
  [ "$s" != blocked ] || [ -n "$msg" ] || die "blocked needs -m \"what blocks it\" (or use: tasks.sh block N --on M)"
  set_status "$n" "$s"; say "$n" "Status → **$s** by $ME.${msg:+ $msg}"; echo "#$n → $s" ;;
block) need_me; n=$(num "${1:?block N --on M}"); shift; [ "${1:-}" = "--on" ] || die "usage: block N --on M"; m=$(num "${2:?}")
  add_dep "$n" "$m"; set_status "$n" blocked; say "$n" "Blocked by #$m (reported by $ME)."; say "$m" "#$n is blocked on this issue."; echo "#$n blocked on #$m" ;;
unblock) need_me; n=$(num "${1:?unblock N}"); u=$(unmet "$n"); [ -z "$u" ] || die "still blocked: $(echo "$u" | tr '\n' ' ')"
  if [ -n "$(owner_of "$n")" ]; then set_status "$n" in-progress; else set_status "$n" ready; fi; say "$n" "Unblocked by $ME: all dependencies are integrated or closed."; echo "#$n unblocked" ;;
report)
  need_me; title=""; ws=""; against=""; exp=""; act=""; ev=""; blocks=""; kind="defect"; prio="p1"
  while [ $# -gt 0 ]; do case "$1" in --title) title="$2"; shift 2 ;; --ws) ws="$2"; shift 2 ;; --against) against="$2"; shift 2 ;; --expected) exp="$2"; shift 2 ;; --actual) act="$2"; shift 2 ;; --evidence) ev="$2"; shift 2 ;;
    --blocks) blocks=$(num "$2"); shift 2 ;; --follow-up) kind="follow-up"; prio="p2"; shift ;; --question) kind="question"; shift ;; *) die "unknown option $1" ;; esac; done
  [ -n "$title" ] && [ -n "$ws" ] && [ -n "$exp" ] && [ -n "$act" ] && [ -n "$ev" ] || die "report needs --title --ws --expected --actual --evidence (reproduction evidence is mandatory)"
  ensure_label "ws:$ws" bfdadc "Workstream"; ensure_label "reporter:$ME" ededed "Reported by"
  btxt="${blocks:+#$blocks}"; btxt="${btxt:-none}"
  body=$(printf '**Reported by:** %s\n**Against:** %s\n**Blocks:** %s\n**Depends on:** none\n\n## Expected\n%s\n\n## Actual\n%s\n\n## Evidence\n%s\n\n## Investigation outcome\n(pending)\n\n## Resolution and regression coverage\n(pending)\n' "$ME" "$against" "$btxt" "$exp" "$act" "$ev")
  url=$(create_issue "$title" "$body" "type:$kind" "ws:$ws" "prio:$prio" status:ready needs:triage "reporter:$ME"); echo "$url"
  new=${url##*/}
  if [ -n "$blocks" ]; then add_dep "$blocks" "$new"; set_status "$blocks" blocked; say "$blocks" "Blocked by #$new (reported by $ME)."; echo "#$blocks is now blocked on #$new"; fi ;;
triage)
  need_coord; n=$(num "${1:?triage N --owner Y}"); shift; own=""; inv=""; msg=""; while [ $# -gt 0 ]; do case "$1" in --owner) own="$2"; shift 2 ;; --investigator) inv="$2"; shift 2 ;; -m) msg="$2"; shift 2 ;; *) die "unknown option $1" ;; esac; done
  [ -n "$own" ] || die "triage needs --owner (who is accountable for this area)"
  ensure_label "owner:$own" fef2c0 "Accountable owner"; gh issue edit "$n" --add-label "owner:$own" --remove-label needs:triage >/dev/null
  if [ -n "$inv" ]; then ensure_label "investigator:$inv" ededed "Investigates before any change"; gh issue edit "$n" --add-label "investigator:$inv" >/dev/null; fi
  say "$n" "Triaged by **$ME**: accountable owner **$own**${inv:+, investigator **$inv**}. Investigate first (genuine defect / missing contract / misunderstanding) with \`tasks.sh outcome\`; do not change another owner's module without this outcome.${msg:+ $msg}"; echo "#$n → owner $own${inv:+, investigator $inv}" ;;
outcome)
  need_me; n=$(num "${1:?outcome N <kind> -m text}"); k="${2:?kind}"; shift 2; [ "${1:-}" = "-m" ] || die "outcome needs -m \"findings and proposed resolution\""; msg="${2:?}"
  case "$k" in defect|missing-contract|misunderstanding|duplicate|owner-decision) ;; *) die "kind: defect|missing-contract|misunderstanding|duplicate|owner-decision" ;; esac
  if [ "$k" = owner-decision ]; then gh issue edit "$n" --add-label needs:owner-decision >/dev/null; else ensure_label "outcome:$k" d73a4a "Investigation outcome"; gh issue edit "$n" --add-label "outcome:$k" >/dev/null; fi
  say "$n" "**Investigation outcome (by $ME): $k.** $msg"
  [ "$k" = owner-decision ] && echo "#$n needs an owner decision: the coordinator must ask the owner (an accepted ADR or a business rule is involved)" || echo "#$n outcome recorded: $k" ;;
submit)
  need_me; n=$(num "${1:?submit N --branch B --tests …}"); shift; br=""; tests=""; xm="no"; notes=""
  while [ $# -gt 0 ]; do case "$1" in --branch) br="$2"; shift 2 ;; --tests) tests="$2"; shift 2 ;; --cross-module) xm="$2"; shift 2 ;; --notes) notes="$2"; shift 2 ;; *) die "unknown option $1" ;; esac; done
  [ "$(owner_of "$n")" = "$ME" ] || die "only the owner ($(owner_of "$n")) submits #$n"; [ -n "$br" ] && [ -n "$tests" ] || die "submit needs --branch and --tests (what you ran and the counts)"
  git rev-parse --verify --quiet "refs/heads/$br" >/dev/null || die "no local branch $br"; tip=$(git rev-parse "$br"); short=$(git rev-parse --short "$br")
  ready=$("$MAIN/docker/dev/integrate-check.sh" "$br" 2>&1 | head -45 || true)
  say "$n" "$(printf '**Submitted for review by %s.** Branch `%s` @ `%s`\n\n**Verification run:** %s\n**Cross-module regression needed:** %s\n%s\n\n**Commits:**\n```\n%s\n```\n<details><summary>Read-only readiness report (integrate-check)</summary>\n\n```\n%s\n```\n</details>' "$ME" "$br" "$short" "$tests" "$xm" "${notes:+**Notes / risks:** $notes}" "$(git log --format='%h %s' develop.."$br" | head -30)" "$ready")"
  gh issue edit "$n" --remove-label approved >/dev/null 2>&1 || true; set_field "$n" "Branch / workspace" "$br @ $short"; set_status "$n" in-review; echo "#$n submitted: $br @ $short → In Review" ;;
approve)
  need_coord; n=$(num "${1:?approve N SHA --ref …}"); sha="${2:?SHA}"; shift 2; [ "${1:-}" = "--ref" ] || die "approve needs --ref \"where the owner approved this\""; ref="${2:?}"
  has "$n" status:in-review || die "#$n is not In Review"; br=$(field "$n" "Branch / workspace" | awk '{print $1}')
  tip=$(git rev-parse "$br" 2>/dev/null) || die "cannot find the branch recorded on #$n ($br)"; full=$(git rev-parse "$sha") || die "unknown commit $sha"
  [ "$tip" = "$full" ] || die "the owner's approval must name the branch tip. $br is at $(git rev-parse --short "$tip"), not $(git rev-parse --short "$full")"
  gh issue edit "$n" --add-label approved >/dev/null; say "$n" "APPROVED $full — owner authorisation: $ref (recorded by $ME). Valid only for this exact commit."; echo "#$n approved at $(git rev-parse --short "$full")" ;;
close) need_me; n=$(num "${1:?close N -m text}"); shift; [ "${1:-}" = "-m" ] || die "close needs -m \"clear outcome\""; msg="${2:?}"
  gh issue close "$n" --comment "Closed by $ME. Outcome: $msg" >/dev/null; echo "closed #$n" ;;
*) sed -n '2,24p' "$0" | sed 's/^# \{0,1\}//'; [ -z "$cmd" ] || exit 2 ;;
esac
