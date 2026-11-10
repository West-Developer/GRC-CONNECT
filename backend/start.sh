#!/bin/sh
set -e

# Replace default port 8080 with Render's assigned PORT
PORT="${PORT:-8080}"
sed -i "s/8080/$PORT/g" /etc/nginx/http.d/default.conf

# Re-cache Laravel configs
php artisan config:cache || true
php artisan route:cache || true

# Start php-fpm in background and nginx in foreground
php-fpm -D
nginx -g "daemon off;"

