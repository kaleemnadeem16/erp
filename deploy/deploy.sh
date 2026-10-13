#!/usr/bin/env bash
# Deploy the ERP to production. Run ON mainvm, by hand, after `main` is green and its images are in
# GHCR. GitHub never reaches this machine (ADR 0121); this script is the whole deploy.
#
#   deploy/deploy.sh [git-ref]      git-ref selects which docker-compose.prod.yml to use (default: main)
#
# Needs: docker with compose, curl, write access to $ERP_DIR, and $ERP_DIR/.env (secrets, never in git).
# Optional $ERP_DIR/deploy.env (also never in git) may set:
#   ERP_ADMIN_EMAIL   sign-in that must always hold every registered permission
#   ERP_URL, ERP_API_URL   public origins to health-check after the deploy
set -euo pipefail

ERP_DIR="${ERP_DIR:-/srv/apps/erp}"
REPO="${ERP_REPO:-kaleemnadeem16/erp}"
REF="${1:-main}"

cd "$ERP_DIR"
test -f .env || { echo "missing $ERP_DIR/.env" >&2; exit 1; }
if [ -f deploy.env ]; then set -a; . ./deploy.env; set +a; fi

# The checked-in compose file is the source of truth: fetched fresh every deploy so the server's copy
# can never drift. Written aside first so a failed download cannot leave a half-written file.
curl --fail --silent --show-error --location --max-time 30 \
  "https://raw.githubusercontent.com/${REPO}/${REF}/docker-compose.prod.yml" -o docker-compose.yml.new
mv docker-compose.yml.new docker-compose.yml

docker compose pull
docker compose run --rm app php artisan migrate --force
# --wait: do not continue until the app's healthcheck (GET /up) reports healthy.
docker compose up -d --remove-orphans --wait
docker compose run --rm app php artisan permission:register
# permission:register only creates Permission rows for names a release ships; it grants nobody
# anything. Without this the administrator falls behind every later feature's new permissions.
if [ -n "${ERP_ADMIN_EMAIL:-}" ]; then
  docker compose run --rm app php artisan erp:sync-admin-permissions "$ERP_ADMIN_EMAIL"
else
  echo "warning: ERP_ADMIN_EMAIL not set in deploy.env — skipped syncing the administrator's permissions." >&2
fi

if [ -n "${ERP_URL:-}" ]; then curl --fail --silent --show-error --max-time 20 "$ERP_URL/" >/dev/null && echo "ok: $ERP_URL"; fi
if [ -n "${ERP_API_URL:-}" ]; then curl --fail --silent --show-error --max-time 20 "$ERP_API_URL/up" >/dev/null && echo "ok: $ERP_API_URL/up"; fi

# Housekeeping the old deploy job did: drop images older than a week and cap the build cache.
docker image prune -af --filter "until=168h" --filter "label=app=erp"
docker builder prune -af --keep-storage 2GB
echo "deployed ${REF}"
