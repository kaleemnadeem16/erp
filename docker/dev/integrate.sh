#!/usr/bin/env bash
# The coordinator's integration steps. Never pushes, never rewrites history, never merges anything the owner has not approved by exact commit.
#
#   ERP_AGENT=coordinator docker/dev/integrate.sh merge <branch> <issue> [--accept-attention]
#       from the main checkout, clean, on develop: requires the issue to carry `approved` for EXACTLY the branch tip, all its dependencies
#       integrated, and a quiet integrate-check (or --accept-attention after you have read it); then `git merge --no-ff` with the task and
#       source commit in the message. Conflicts abort the merge and change nothing.
#   ERP_AGENT=coordinator docker/dev/integrate.sh record <issue> [--commit SHA] [--dry-run]
#       after develop was pushed: finds the integration commit, reads its CI run, and writes the trace to the issue
#       (task -> owner -> source commit -> integration commit -> CI run). Marks it Integrated only when CI passed.
# Closing the issue is separate (tasks.sh close) and waits for the post-integration checks and the affected agents' confirmation.
set -euo pipefail
COMMON=$(git rev-parse --path-format=absolute --git-common-dir); MAIN=$(cd "$COMMON/.." && pwd -P); cd "$MAIN"
T="$MAIN/docker/dev/tasks.sh"; die() { echo "integrate: $*" >&2; exit 1; }
[ "${ERP_AGENT:-}" = "${ERP_COORDINATOR:-coordinator}" ] || die "run as the coordinator: ERP_AGENT=${ERP_COORDINATOR:-coordinator} $0 …"
cmd="${1:-}"; [ $# -gt 0 ] && shift || true
case "$cmd" in
merge)
  br="${1:?merge <branch> <issue>}"; n="${2:?issue number}"; n=${n#\#}; shift 2; accept=0; [ "${1:-}" = "--accept-attention" ] && accept=1
  [ "$(git rev-parse --git-dir)" = "$(git rev-parse --git-common-dir)" ] || die "run from the main checkout, not a worktree"
  [ "$(git rev-parse --abbrev-ref HEAD)" = develop ] || die "the main checkout must be on develop"
  [ -z "$(git status --porcelain)" ] || die "the main checkout is not clean:$(printf '\n'; git status --short | head -8)"
  git fetch origin --quiet; [ "$(git rev-list --count develop..origin/develop)" = 0 ] || die "develop is behind origin/develop: fast-forward it first (git merge --ff-only origin/develop)"
  git rev-parse --verify --quiet "refs/heads/$br" >/dev/null || die "no local branch $br"
  tip=$(git rev-parse "$br")
  appr=$(gh issue view "$n" --json comments --jq '[.comments[].body | select(startswith("APPROVED "))] | last // ""' | awk '{print $2}')
  gh issue view "$n" --json labels --jq '.labels[].name' | grep -qx approved || die "#$n is not approved"
  [ "$appr" = "$tip" ] || die "the owner approved ${appr:-nothing}, but $br is at $tip: re-submit and get that commit approved"
  "$T" deps "$n" || die "dependencies are not integrated yet"
  if ! "$MAIN/docker/dev/integrate-check.sh" "$br"; then [ "$accept" = 1 ] || die "integrate-check needs attention (read it above); fix it, or re-run with --accept-attention if you have judged each line"; fi
  title=$(gh issue view "$n" --json title --jq .title); owner=$(gh issue view "$n" --json labels --jq '[.labels[].name | select(startswith("owner:"))] | first // "owner:?"' | sed 's/owner://')
  if ! git merge --no-ff -m "Merge $br (#$n)" -m "Task: #$n $title
Owner: $owner
Source: $tip
Approved: recorded on the issue as APPROVED $tip" "$br"; then git merge --abort; die "conflicts: the merge was aborted and nothing changed. Ask the owner to 'git merge develop' in the branch and re-submit"; fi
  m=$(git rev-parse HEAD); "$T" status "$n" in-review -m "Merged locally into develop as $(git rev-parse --short "$m") (source $(git rev-parse --short "$tip")). Not pushed yet." >/dev/null
  echo "merged $br into develop as $(git rev-parse --short "$m"). NOT pushed. Next: push develop (needs the owner's authorisation), then 'integrate.sh record $n'." ;;
record)
  n="${1:?record <issue>}"; n=${n#\#}; shift; sha=""; dry=0; while [ $# -gt 0 ]; do case "$1" in --commit) sha="$2"; shift 2 ;; --dry-run) dry=1; shift ;; *) die "unknown option $1" ;; esac; done
  git fetch origin --quiet
  [ -n "$sha" ] || sha=$(git log --first-parent --merges --grep="(#$n)" --format=%H -n1 origin/develop)
  [ -n "$sha" ] || die "no merge commit mentioning (#$n) on origin/develop: has develop been pushed?"
  git merge-base --is-ancestor "$sha" origin/develop || die "$sha is not on origin/develop yet"
  src=$(git rev-parse "$sha^2" 2>/dev/null || echo "?"); owner=$(gh issue view "$n" --json labels --jq '[.labels[].name | select(startswith("owner:"))] | first // "owner:?"' | sed 's/owner://')
  run=$(gh run list --commit "$sha" --limit 5 --json databaseId,status,conclusion,url,workflowName --jq '.[0] // empty | "\(.url)\t\(.status)\t\(.conclusion)"')
  if [ -z "$run" ]; then ci="no CI run found for this commit (it may not have been pushed, or CI has not started)"; st=none; url="-"; conc=""
  else url=$(echo "$run" | cut -f1); st=$(echo "$run" | cut -f2); conc=$(echo "$run" | cut -f3); ci="$st ${conc:-}"; fi
  rec="$(printf '**Integration record**\n- Task: #%s (owner **%s**)\n- Source commit: `%s`\n- Integration commit: `%s` on develop\n- CI: %s — %s\n' "$n" "$owner" "$src" "$sha" "$ci" "$url")"
  echo "$rec"
  [ "$dry" = 0 ] || { echo "(dry run: nothing written)"; exit 0; }
  gh issue comment "$n" --body "$rec" >/dev/null
  if [ "$st" = completed ] && [ "$conc" = success ]; then "$T" status "$n" integrated -m "CI passed on the integration commit." >/dev/null; echo "#$n marked Integrated. Remaining: post-integration checks, tell the affected agents, then tasks.sh close."
  else echo "#$n stays In Review: CI is not a completed success yet. Re-run 'record' when it is."; fi ;;
*) sed -n '2,13p' "$0" | sed 's/^# \{0,1\}//'; [ -z "$cmd" ] || exit 2 ;;
esac
