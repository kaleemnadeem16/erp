#!/bin/sh
# Set up and look after one Git worktree per implementation agent. Run it from any checkout.
#
#   docker/dev/worktree.sh add <topic> [--agent claude] [--base develop]   # new worktree ../erp-<topic> on branch <agent>/<topic>
#   docker/dev/worktree.sh list                                             # every worktree: branch, dirty files, ahead/behind, test database
#   docker/dev/worktree.sh vendor <topic>                                   # give a worktree its own vendor/ (its composer.lock differs)
#   docker/dev/worktree.sh deps <topic>                                     # re-sync its frontend node_modules (copy from main, or npm ci if its lock differs)
#   docker/dev/worktree.sh next <topic> <new-branch> [--retain] [--allow-unpushed]   # reuse a persistent worktree for its next task on a fresh branch
#   docker/dev/worktree.sh cleanup-report                                   # what could be deleted (branches, databases, volumes); deletes nothing
#   docker/dev/worktree.sh remove <topic>                                   # only if clean and merged into develop; drops its test database
#
# What `add` prepares (details: docs/architecture/multi-agent-workspaces.md):
#   - its own files and branch; nothing is shared with other checkouts except Git history;
#   - backend/.env copied from the main checkout (untracked, per checkout);
#   - frontend/node_modules COPIED (not linked: npm workspace links are relative, so the copy resolves @erp/* to THIS checkout);
#   - .erp-agent, so the test wrappers know who you are and name your test database erp_testing_<agent>_<topic>;
#   - a root .env with a COMPOSE_PROJECT_NAME that matches no running project, so `docker compose exec app …` run here fails loudly
#     instead of silently acting on the main checkout. The wrappers (docker/backend/run*.sh) select the right project themselves.
# It never moves, resets or touches an existing checkout or branch.
set -e
COMMON=$(git rev-parse --path-format=absolute --git-common-dir)
MAIN=$(cd "$COMMON/.." && pwd -P)
PARENT=$(dirname "$MAIN")
note() { printf '%s\n' "$*" >&2; }
die() { note "$*"; exit 1; }
valid() { printf '%s' "$1" | grep -Eq '^[a-z][a-z0-9-]{0,23}$'; }
# Every node_modules of the main checkout's npm workspace (the root one and the nested ones npm leaves in packages/* and apps/*), as
# paths relative to frontend/. Copied, not linked: the workspace links inside are relative, so a copy resolves @erp/* to ITS checkout.
node_module_dirs() { (cd "$MAIN/frontend" && find . -maxdepth 3 -type d -name node_modules -prune -print); }
copy_node_modules() { # <worktree dir>
  node_module_dirs | while read -r d; do
    rm -rf "$1/frontend/$d"
    mkdir -p "$(dirname "$1/frontend/$d")"
    cp -a "$MAIN/frontend/$d" "$1/frontend/$d"
  done
}

cmd="${1:-}"; shift || true
case "$cmd" in
  add)
    topic="${1:-}"; shift || true
    agent="${ERP_AGENT:-agent}"; base=develop
    while [ $# -gt 0 ]; do
      case "$1" in
        --agent) agent="$2"; shift 2 ;;
        --base) base="$2"; shift 2 ;;
        *) die "unknown option $1" ;;
      esac
    done
    valid "$topic" || die "topic must be lowercase letters, digits and dashes (at most 24): add <topic>"
    valid "$agent" || die "agent must be lowercase letters, digits and dashes (at most 24)"
    dir="$PARENT/erp-$topic"; branch="$agent/$topic"
    [ -e "$dir" ] && die "$dir already exists"
    git -C "$MAIN" show-ref --verify --quiet "refs/heads/$branch" && die "branch $branch already exists"
    git -C "$MAIN" rev-parse --verify --quiet "$base^{commit}" >/dev/null || die "base $base does not exist"
    [ -f "$MAIN/backend/.env" ] || die "$MAIN/backend/.env is missing; the main checkout is not set up"
    git -C "$MAIN" worktree add -b "$branch" "$dir" "$base" >&2
    cp "$MAIN/backend/.env" "$dir/backend/.env"
    printf '%s\n' "$agent" > "$dir/.erp-agent"
    printf 'COMPOSE_PROJECT_NAME=erp_wt_%s\n' "$(printf '%s' "$topic" | tr -c 'a-z0-9\n' '_')" > "$dir/.env"
    mkdir -p "$dir/backend/vendor" "$dir/backend/bootstrap/cache" "$dir/backend/storage/framework/phpstan" "$dir/backend/storage/api-docs" "$dir/backend/storage/logs"
    if [ -d "$MAIN/frontend/node_modules" ]; then
      note "copying the frontend node_modules (root and workspace-nested, about $(node_module_dirs | (cd "$MAIN/frontend" && xargs du -sm) | awk '{s+=$1} END {print s}') MB)…"
      copy_node_modules "$dir"
    fi
    cat >&2 <<DONE

Worktree ready: $dir   branch: $branch (from $base)   agent: $agent
  cd $dir
  docker/backend/run-tests-tmpfs.sh --filter=…     # tests of THIS checkout, database $(printf 'erp_testing_%s_%s' "$agent" "$topic" | tr -c 'a-z0-9_\n' '_')
  docker/backend/run.sh vendor/bin/pint --test <paths>
  docker/backend/run.sh vendor/bin/phpstan analyse --memory-limit=1G
Browser specs run only from the main checkout (the dev stack serves it): see docs/architecture/multi-agent-workspaces.md.
DONE
    ;;
  list)
    printf '%-26s %-28s %-6s %-12s %s\n' WORKTREE BRANCH DIRTY "AHEAD/BEHIND" "TEST DATABASE"
    git -C "$MAIN" worktree list --porcelain | awk '/^worktree /{p=$2} /^branch /{b=$2; sub("refs/heads/","",b); print p "\t" b}' | while IFS="$(printf '\t')" read -r p b; do
      [ -d "$p" ] || continue
      name=$(basename "$p")
      dirty=$(git -C "$p" status --porcelain | wc -l)
      ab=$(git -C "$p" rev-list --left-right --count "$b...develop" 2>/dev/null | awk '{print $1"/"$2}')
      agent=$( [ -f "$p/.erp-agent" ] && head -n 1 "$p/.erp-agent" || true )
      ws=${name#erp-}; [ "$name" = erp ] && ws=""
      db=$(printf 'erp_testing_%s_%s' "$agent" "$ws" | tr -c 'a-z0-9_\n' '_' | sed 's/__*/_/g; s/_$//' | cut -c1-53)
      [ -z "$agent" ] && db="(set ERP_AGENT) erp_testing_$(printf '%s' "${ws:-main}" | tr -c 'a-z0-9\n' '_')"
      printf '%-26s %-28s %-6s %-12s %s\n' "$name" "$b" "$dirty" "$ab" "$db"
    done
    ;;
  deps)
    topic="${1:-}"; valid "$topic" || die "usage: deps <topic>"
    dir="$PARENT/erp-$topic"; [ -d "$dir" ] || die "$dir does not exist"
    if cmp -s "$MAIN/frontend/package-lock.json" "$dir/frontend/package-lock.json"; then
      note "package-lock.json matches main: re-copying main's node_modules into $dir/frontend …"
      copy_node_modules "$dir"
    else
      note "package-lock.json differs from main: running npm ci in $dir/frontend for this branch's own dependencies …"
      (cd "$dir/frontend" && npm ci --no-audit --no-fund)
    fi
    note "done."
    ;;
  vendor)
    topic="${1:-}"; valid "$topic" || die "usage: vendor <topic>"
    dir="$PARENT/erp-$topic"; [ -d "$dir" ] || die "$dir does not exist"
    vol="erp_vendor_$topic"
    docker volume inspect "$vol" >/dev/null 2>&1 && die "volume $vol already exists (this worktree already has its own vendor)"
    note "creating $vol from the shared vendor, then syncing it to $dir/backend/composer.lock …"
    docker volume create "$vol" >/dev/null
    docker run --rm -v erp_backend_vendor:/from:ro -v "$vol:/to" alpine sh -c 'cp -a /from/. /to/ && chown -R 1000:1000 /to'
    (cd "$dir" && docker compose -p erp run --rm --no-deps -T -u "$(id -u):$(id -g)" -e HOME=/tmp -v "$vol:/var/www/html/vendor" --entrypoint env app composer install --no-scripts --no-interaction --no-progress)
    note "done: runs from $dir now use $vol automatically."
    ;;
  next)
    topic="${1:-}"; nb="${2:-}"; [ $# -ge 2 ] && shift 2 || true; retain=0; unpushed=0
    while [ $# -gt 0 ]; do case "$1" in --retain) retain=1; shift ;; --allow-unpushed) unpushed=1; shift ;; *) die "unknown option $1" ;; esac; done
    valid "$topic" && [ -n "$nb" ] || die "usage: next <topic> <new-branch> [--retain] [--allow-unpushed]"
    printf '%s' "$nb" | grep -Eq '^[a-z0-9][a-z0-9/_-]{2,60}$' || die "the new branch name must be 3-61 characters of lowercase letters, digits, / _ -"
    dir="$PARENT/erp-$topic"; [ -d "$dir" ] || die "$dir does not exist"
    git -C "$MAIN" worktree list --porcelain | grep -qxF "worktree $dir" || die "$dir is not a worktree of this repository"
    cur=$(git -C "$dir" symbolic-ref --quiet --short HEAD) || die "refused: $dir is on a detached HEAD"
    [ "$cur" != "$nb" ] || die "refused: the new branch is the current branch"
    git -C "$MAIN" show-ref --verify --quiet "refs/heads/$nb" && die "refused: branch $nb already exists (branches are never reused; pick a new name)"
    gd=$(git -C "$dir" rev-parse --absolute-git-dir)
    for f in MERGE_HEAD REBASE_HEAD CHERRY_PICK_HEAD REVERT_HEAD BISECT_LOG rebase-merge rebase-apply; do [ ! -e "$gd/$f" ] || die "refused: an unfinished git operation is in progress in $dir ($f)"; done
    st=$(git -C "$dir" status --porcelain)
    if [ -n "$st" ]; then note "refused: $dir has uncommitted or untracked work that could be lost:"; printf '%s\n' "$st" | head -10 | sed 's/^/  /' >&2; [ "$(printf '%s\n' "$st" | wc -l)" -le 10 ] || note "  …"; exit 1; fi
    git -C "$MAIN" fetch origin --quiet
    [ "$(git -C "$MAIN" rev-list --count develop..origin/develop)" = 0 ] || die "refused: develop is behind origin/develop; the coordinator must fast-forward the main checkout first (git merge --ff-only origin/develop)"
    ahead=$(git -C "$MAIN" rev-list --count origin/develop..develop)
    [ "$ahead" = 0 ] || [ "$unpushed" = 1 ] || die "refused: develop holds $ahead commit(s) that are not published yet; the baseline for new work should be the published one (use --allow-unpushed only if the coordinator says so)"
    if git -C "$MAIN" merge-base --is-ancestor "$cur" develop; then state="integrated into develop"
    else
      cnt=$(git -C "$MAIN" rev-list --count "develop..$cur")
      [ "$retain" = 1 ] || die "refused: $cur has $cnt commit(s) that are not in develop. Get it integrated first, or pass --retain to leave it exactly as it is (it is never deleted; 'git switch $cur' in this worktree, or 'git worktree add' elsewhere, brings it back)"
      state="RETAINED with $cnt commit(s) not in develop"
    fi
    old=$(git -C "$dir" rev-parse HEAD)
    git -C "$dir" switch -q -c "$nb" develop
    new=$(git -C "$dir" rev-parse HEAD)
    printf '%s %s -> %s  from develop@%s  (%s)\n' "$(date -u +%FT%TZ)" "$cur" "$nb" "$(git -C "$dir" rev-parse --short HEAD)" "$state" >> "$dir/.erp-transitions"
    note "worktree erp-$topic: $cur ($state) -> $nb, based on develop@$(git -C "$dir" rev-parse --short HEAD)"
    note "  kept: path, .erp-agent ($(head -n1 "$dir/.erp-agent" 2>/dev/null || echo unset)), backend/.env, node_modules, private vendor, test database; nothing was deleted"
    mig=$(git -C "$dir" diff --name-only --diff-filter=A "$old" "$new" -- backend/database/migrations | wc -l)
    [ "$mig" -eq 0 ] || note "  migrations new since your previous base: $mig -> run docker/backend/migrate-cycle.sh when you touch the schema"
    git -C "$dir" diff --quiet "$old" "$new" -- frontend/packages/core-api/src/generated || note "  the generated API client changed between the two bases -> re-run docker/backend/codegen.sh --check before depending on it"
    git -C "$dir" diff --quiet "$old" "$new" -- docker docker-compose.yml || note "  shared docker/ scripts changed: they are the new versions now"
    if ! git -C "$dir" diff --quiet "$old" "$new" -- frontend/package-lock.json; then note "  frontend/package-lock.json changed -> run: docker/dev/worktree.sh deps $topic"; fi
    if ! (cd "$dir" && docker/backend/run.sh php -v >/dev/null 2>"$dir/.erp-vendor-check.out"); then note "  PHP dependency check FAILED for the new base:"; grep -v '^ Container' "$dir/.erp-vendor-check.out" | sed 's/^/    /' >&2; fi; rm -f "$dir/.erp-vendor-check.out"
    note "  record this on the task: tasks.sh claim <N> --branch $nb (run from $dir)" ;;
  cleanup-report)
    echo "== local branches already merged into develop (review, then delete yourself with 'git branch -d'; nothing was deleted):"
    used=$(git -C "$MAIN" worktree list --porcelain | awk '/^branch /{sub("refs/heads/","",$2); print $2}')
    git -C "$MAIN" branch --merged develop --format '%(refname:short)|%(committerdate:short)' | while IFS='|' read -r b d; do
      case "$b" in develop|main) continue ;; esac
      if printf '%s\n' "$used" | grep -qxF "$b"; then echo "  $b   ($d)   in use by a worktree: keep"; else echo "  $b   ($d)   git branch -d $b"; fi
    done
    echo "== test databases on the throwaway server (a database whose worktree is gone can be dropped with docker/backend/test-db.sh drop <name>):"
    "$MAIN/docker/backend/test-db.sh" list 2>/dev/null | sed 's/^/  /' || echo "  (test server not running)"
    echo "== private vendor volumes:"; docker volume ls -q -f name=erp_vendor_ 2>/dev/null | sed 's/^/  /'; echo "  (none listed = none)" ;;
  remove)
    topic="${1:-}"; valid "$topic" || die "usage: remove <topic>"
    dir="$PARENT/erp-$topic"; [ -d "$dir" ] || die "$dir does not exist"
    branch=$(git -C "$dir" rev-parse --abbrev-ref HEAD)
    [ -z "$(git -C "$dir" status --porcelain)" ] || die "refused: $dir has uncommitted work; nothing was removed"
    git -C "$MAIN" merge-base --is-ancestor "$branch" develop || die "refused: branch $branch has commits that are not in develop; nothing was removed"
    agent=$( [ -f "$dir/.erp-agent" ] && head -n 1 "$dir/.erp-agent" || true )
    db=$(printf 'erp_testing_%s_%s' "$agent" "$topic" | tr -c 'a-z0-9_\n' '_' | sed 's/__*/_/g; s/_$//' | cut -c1-53)
    TEST_DB_NAME="$db" "$MAIN/docker/backend/test-db.sh" drop >&2 || note "(test database $db was not dropped)"
    docker volume rm "erp_vendor_$topic" >/dev/null 2>&1 || true
    git -C "$MAIN" worktree remove "$dir"
    git -C "$MAIN" branch -d "$branch" >&2 || true
    note "removed $dir and branch $branch."
    ;;
  *) die "usage: worktree.sh add <topic> [--agent <name>] [--base <ref>] | list | vendor <topic> | deps <topic> | next <topic> <new-branch> | cleanup-report | remove <topic>" ;;
esac
