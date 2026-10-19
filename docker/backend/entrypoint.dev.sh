#!/bin/sh
set -e

# php-fpm's pool workers drop from root to the baked-in www-data (82:82 on Alpine), but
# storage/, bootstrap/cache/, etc. are bind-mounted from the host and owned by whatever
# uid/gid owns the repo on the host (varies per developer — 1000:1000 under WSL2 here).
# Without this, www-data can't write compiled views/cache through the mount: tempnam()
# silently falls back to the system temp dir with a warning, which Laravel promotes to a
# fatal ErrorException. No `usermod`/`groupmod` in this image (no shadow package), so edit
# the two passwd/group lines directly — cheap and safe to redo on every container start.
HOST_UID=$(stat -c '%u' /var/www/html)
HOST_GID=$(stat -c '%g' /var/www/html)
if [ "$HOST_UID" != "0" ] && [ "$(id -u www-data)" != "$HOST_UID" ]; then
    sed -i "s/^www-data:\([^:]*\):[0-9]*:[0-9]*:/www-data:\1:${HOST_UID}:${HOST_GID}:/" /etc/passwd
    sed -i "s/^www-data:\([^:]*\):[0-9]*:/www-data:\1:${HOST_GID}:/" /etc/group
fi

# app/worker/scheduler all run this entrypoint from the same bind-mounted repo and the
# same shared 'backend_vendor' volume, and docker compose starts them concurrently. Without
# serializing, concurrent `composer install`s corrupt the shared vendor/ (two processes
# extracting into it at once) and concurrent `storage:link --force` calls race the same
# symlink (one deletes it out from under another mid-recreate). Hold an exclusive flock
# across the whole setup block so only one container does it per `docker compose up`; the
# others block here and then fall through fast once vendor/autoload.php already exists.
LOCK_FILE=/var/www/html/storage/framework/entrypoint.lock

(
    flock -x 200

    # Install vendor into the named volume on first run (or after volume wipe).
    # The named volume 'backend_vendor' in docker-compose.yml keeps vendor/ in
    # Docker's OverlayFS rather than the bind-mounted repo.
    if [ ! -f /var/www/html/vendor/autoload.php ]; then
        echo ">>> [entrypoint] vendor missing — running composer install..."
        composer install --no-scripts --no-interaction
        echo ">>> [entrypoint] Done."
    fi

    # This entrypoint runs as root (php-fpm itself drops its pool workers to
    # www-data, but the composer install above runs before that happens), so a
    # fresh 'backend_vendor' volume ends up root-owned — silently breaking
    # `docker exec --user www-data ... composer ...` for anything afterwards
    # (require, update). Cheap and idempotent, so it runs every start rather
    # than only when vendor/ was just installed.
    chown -R www-data:www-data /var/www/html/vendor

    # The shared `erp_testing` database is deliberately NOT created or migrated here any more. Every agent used to run
    # against it and two runs dropped each other's tables (47 deadlocks in the test server's log). Backend tests run through
    # docker/backend/run-tests-tmpfs.sh, which builds a private database per agent on the throwaway `postgres-test` server.

    # Ensure public/storage symlink exists (lost on fresh container start)
    php artisan storage:link --force 2>/dev/null || true
) 200>"$LOCK_FILE"

exec "$@"
