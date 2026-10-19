#!/bin/sh
# Run any command against THIS checkout's code in a throwaway container (Pint, PHPStan, artisan, composer-free tools):
#
#   docker/backend/run.sh vendor/bin/pint --test app/Foo.php
#   docker/backend/run.sh vendor/bin/phpstan analyse --memory-limit=1G
#   docker/backend/run.sh php artisan scramble:export --path=storage/api-docs/openapi.json
#
# Why not `docker compose exec app …`: the `app` container always has the MAIN checkout mounted. From a worktree it would lint or
# analyse the wrong files and say nothing. This mounts the checkout the script belongs to (and refuses to run from inside another
# one), uses the shared read-only vendor if it matches this checkout's composer.lock, runs as you, and leaves nothing running.
# No tty (no tinker); it never touches a database unless the command does. Tests go through run-tests-tmpfs.sh instead.
set -e
. "$(dirname "$0")/test-lib.sh"
erp_check_checkout || exit 64
[ $# -gt 0 ] || { erp_note "usage: docker/backend/run.sh <command...>   (e.g. vendor/bin/pint --test app/Foo.php)"; exit 64; }
[ -n "$ERP_SKIP_VENDOR_CHECK" ] || erp_check_vendor || exit 65
exec docker compose -p erp run --rm --no-deps -T -u "$(id -u):$(id -g)" -e HOME=/tmp -e "ERP_CHECKOUT=$ERP_ROOT" \
  -v "$(erp_vendor_volume):/var/www/html/vendor:ro" --entrypoint env app "$@"
