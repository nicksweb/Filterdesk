#!/bin/sh
set -eu
mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs "${VIEW_COMPILED_PATH:-bootstrap/cache/views}"
php artisan config:cache
php artisan view:cache
exec "$@"
