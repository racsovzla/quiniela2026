#!/bin/sh
set -e

echo "[entrypoint] Running database migrations..."
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

echo "[entrypoint] Starting Apache..."
exec apache2-foreground
