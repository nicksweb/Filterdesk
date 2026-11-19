#!/bin/sh
set -eu
mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
php artisan config:cache
php artisan view:cache
exec "$@"
