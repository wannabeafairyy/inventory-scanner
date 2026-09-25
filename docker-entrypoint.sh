#!/bin/sh
set -e

# Railway/Render menyuntik $PORT. Default 8000 agar tetap jalan lokal.
PORT=${PORT:-8000}

touch database/database.sqlite
php artisan migrate --force

exec php artisan serve --host=0.0.0.0 --port="${PORT}"
