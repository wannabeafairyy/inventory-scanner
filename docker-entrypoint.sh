#!/bin/sh
set -e

# Render/Railway/Koyeb menyuntik $PORT. Default 8000 agar tetap jalan lokal.
PORT=${PORT:-8000}

sed -ri "s!Listen [0-9]+!Listen ${PORT}!g" /etc/apache2/ports.conf
sed -ri "s!<VirtualHost \*:[0-9]+>!<VirtualHost *:${PORT}>!g" /etc/apache2/sites-available/000-default.conf

touch database/database.sqlite
php artisan migrate --force

exec apache2-foreground
