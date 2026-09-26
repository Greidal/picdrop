#!/bin/sh
set -e

# Apply pending database migrations (and create the bootstrap admin) before serving traffic.
if [ "${SKIP_MIGRATIONS:-0}" != "1" ]; then
    php /var/www/html/lib/migrate.php
fi

exec docker-php-entrypoint "$@"
