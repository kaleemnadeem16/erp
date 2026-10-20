#!/bin/sh
# Read-only report for the integrator, BEFORE merging a feature branch into develop. It changes no ref, file or worktree.
#
#   docker/dev/integrate-check.sh phase4/sales-quotes
#
# Shows what the branch would bring (commits, files, the hotspots that cause conflicts), whether it is behind develop, whether the
# worktree that owns it has uncommitted work, and whether `git merge-tree` finds textual conflicts. Exit 0 = nothing needs attention,
# 1 = read the "ATTENTION" lines. It never decides that a business feature is complete or approved: that is the owner's call.
set -e
b="${1:?usage: integrate-check.sh <branch>}"
COMMON=$(git rev-parse --path-format=absolute --git-common-dir); MAIN=$(cd "$COMMON/.." && pwd -P)
cd "$MAIN"
git rev-parse --verify --quiet "refs/heads/$b" >/dev/null || { echo "no local branch $b"; exit 2; }
attn=0; warn() { echo "ATTENTION: $*"; attn=1; }
base=$(git merge-base develop "$b")
echo "branch $b @ $(git rev-parse --short "$b")   develop @ $(git rev-parse --short develop)   merge-base $(git rev-parse --short "$base")"
echo "ahead of develop: $(git rev-list --count develop.."$b") commit(s); behind: $(git rev-list --count "$b"..develop)"
[ "$(git rev-list --count "$b"..develop)" -gt 0 ] && warn "the branch is behind develop: have the agent run 'git merge develop' and re-verify first"
echo; echo "commits:"; git log --format='  %h %an  %s' develop.."$b" | cut -c1-150
echo; echo "files changed: $(git diff --name-only "$base" "$b" | wc -l)"
git diff --name-only "$base" "$b" | awk -F/ '{k=$1"/"$2; c[k]++} END {for (k in c) printf "  %-40s %d\n", k, c[k]}' | sort
echo; echo "hotspots:"
mig=$(git diff --name-only --diff-filter=AM "$base" "$b" -- backend/database/migrations)
if [ -n "$mig" ]; then
  echo "$mig" | sed 's/^/  migration: /'
  newest=$(git ls-tree --name-only develop backend/database/migrations/ | sed 's#.*/##' | sort | tail -1)
  for m in $mig; do [ "$(basename "$m")" \< "$newest" ] && warn "$(basename "$m") sorts BEFORE develop's newest migration ($newest): check it against the tables develop's later migrations changed"; done
  echo "  -> run docker/backend/migrate-cycle.sh in the branch's worktree after merging develop"
fi
gen=$(git diff --name-only "$base" "$b" -- frontend/packages/core-api/src/generated | wc -l)
if [ "$gen" -gt 0 ]; then
  src=$(git diff --name-only "$base" "$b" -- 'backend/app/**/Http/**' backend/routes | wc -l)
  echo "  generated API client: $gen file(s); API source files changed: $src"
  [ "$src" -eq 0 ] && warn "generated client changed with no Resource/Request/Controller/route change: do not merge by hand; regenerate with codegen.sh"
  echo "  -> after merging, run docker/backend/codegen.sh --check on develop"
fi
for h in docs/STATUS.md docs/STATUS-LOG.md docs/decisions/README.md docs/OPEN-QUESTIONS.md docker-compose.yml AGENTS.md; do
  git diff --quiet "$base" "$b" -- "$h" || echo "  shared file touched: $h"
done
git diff --name-only "$base" "$b" -- docker/ | grep -q . && warn "docker/ scripts changed: shared infrastructure must be reviewed on its own and announced"
wt=$(git worktree list --porcelain | awk -v b="refs/heads/$b" '/^worktree /{p=$2} $1=="branch" && $2==b {print p}')
if [ -n "$wt" ]; then
  d=$(git -C "$wt" status --porcelain | wc -l); echo; echo "worktree $wt: $d uncommitted path(s)"
  [ "$d" -gt 0 ] && warn "the owning worktree has uncommitted work: it is not what will be merged"
fi
echo; if out=$(git merge-tree --write-tree --name-only develop "$b" 2>&1); then echo "merge dry-run: no textual conflicts"; else warn "merge dry-run reports conflicts:"; echo "$out" | sed 's/^/  /' | head -15; fi
echo; if [ "$attn" -eq 0 ]; then echo "nothing needs attention (this is not an approval of the feature)"; else echo "see ATTENTION lines above"; fi
exit "$attn"
