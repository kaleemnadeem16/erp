#!/bin/sh
set -e

# Install vendor into the named volume on first run (or after volume wipe).
# The named volume 'backend_vendor' in docker-compose.yml keeps vendor/ in
# Docker's OverlayFS rather than the bind-mounted repo.
if [ ! -f /var/www/html/vendor/autoload.php ]; then
    echo ">>> [entrypoint] vendor missing — running composer install..."
    composer install --no-scripts --no-interaction
    echo ">>> [entrypoint] Done."
fi

# -----------------------------------------------------------------------
# Ensure the test database exists and has the latest schema.
# postgres-init.sql creates erp_testing on first volume start, but if the
# volume already existed it won't re-run. This catches that case.
# -----------------------------------------------------------------------
echo ">>> [entrypoint] Ensuring erp_testing database exists..."

php -r "
\$maxRetries = 10;
for (\$i = 0; \$i < \$maxRetries; \$i++) {
    try {
        \$pdo = new PDO(
            'pgsql:host=' . getenv('DB_HOST') . ';port=' . getenv('DB_PORT') . ';dbname=postgres',
            getenv('DB_USERNAME'),
            getenv('DB_PASSWORD')
        );
        \$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        \$exists = \$pdo->query(\"SELECT 1 FROM pg_database WHERE datname = 'erp_testing'\")->fetchColumn();
        if (!\$exists) {
            \$pdo->exec('CREATE DATABASE erp_testing');
            echo 'Created erp_testing database.' . PHP_EOL;
        } else {
            echo 'erp_testing already exists.' . PHP_EOL;
        }
        break;
    } catch (PDOException \$e) {
        if (\$i === \$maxRetries - 1) {
            echo 'Failed to connect to postgres: ' . \$e->getMessage() . PHP_EOL;
            exit(1);
        }
        sleep(1);
    }
}
"

# Run pending migrations on the test database using the hardcoded 'testing' connection.
# The 'testing' connection in config/database.php always points to erp_testing
# regardless of env vars, so this is safe even if APP_ENV is 'local'.
echo ">>> [entrypoint] Running migrations on test database..."
php artisan migrate --database=testing --force --no-interaction 2>&1 || true

echo ">>> [entrypoint] Test database schema up to date."

# Ensure public/storage symlink exists (lost on fresh container start)
php artisan storage:link --force 2>/dev/null || true

exec "$@"
