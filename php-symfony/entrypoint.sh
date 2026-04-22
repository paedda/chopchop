#!/bin/sh
set -e

echo "Warming up Symfony cache..."
php bin/console cache:warmup --env=prod --no-debug

echo "Starting PHP built-in server on :8000..."
exec php -S 0.0.0.0:8000 -t public public/index.php
