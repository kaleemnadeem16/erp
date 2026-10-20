#!/bin/sh
# Set up and look after one Git worktree per implementation agent. Run it from any checkout.
#
#   docker/dev/worktree.sh add <topic> [--agent claude] [--base develop]   # new worktree ../erp-<topic> on branch <agent>/<topic>
#   docker/dev/worktree.sh list                                             # every worktree: branch, dirty files, ahead/behind, test database
#   docker/dev/worktree.sh vendor <topic>                                   # give a worktree its own vendor/ (its composer.lock differs)
#   docker/dev/worktree.sh deps <topic>                                     # re-sync its frontend node_modules (copy from main, or npm ci if its lock differs)
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
  *) die "usage: worktree.sh add <topic> [--agent <name>] [--base <ref>] | list | vendor <topic> | deps <topic> | remove <topic>" ;;
esac
