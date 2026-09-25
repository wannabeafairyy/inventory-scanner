# Laravel + Apache, port 8000 (default Koyeb).
FROM php:8.4-apache

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public \
    COMPOSER_ALLOW_SUPERUSER=1

# System deps: GD (jpeg/webp), zip, intl, sqlite + Node 20 untuk build Vite.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev libjpeg-dev libwebp-dev libzip-dev libicu-dev libonig-dev libsqlite3-dev \
        unzip curl git ca-certificates \
    && curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) gd pdo_sqlite mbstring zip bcmath intl exif pcntl \
    && a2dismod mpm_event mpm_worker 2>/dev/null || true \
    && a2enmod mpm_prefork rewrite \
    && sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/000-default.conf \
    && sed -ri -e 's!Listen 80!Listen 8000!g' /etc/apache2/ports.conf \
    && sed -ri -e 's!<VirtualHost \*:80>!<VirtualHost *:8000>!g' /etc/apache2/sites-available/000-default.conf \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Install PHP deps dulu (manfaatkan layer cache).
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

# Build frontend.
COPY package.json package-lock.json vite.config.js ./
COPY resources/ resources/
COPY public/ public/
RUN npm ci --ignore-scripts && npm run build && npm cache clean --force

# Salin sisa aplikasi.
COPY . .
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh \
    && composer dump-autoload --optimize \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && php artisan config:clear

EXPOSE 8000

# Entrypoint: samakan Apache ke $PORT, siapkan sqlite, migrate, jalan Apache.
CMD ["/usr/local/bin/docker-entrypoint.sh"]
