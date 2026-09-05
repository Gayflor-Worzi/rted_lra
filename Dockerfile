# syntax=docker/dockerfile:1

# ------------------------------------------------ frontend build -----------
FROM node:22-alpine AS frontend
WORKDIR /app
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci
COPY frontend/ ./
RUN npm run build

# ------------------------------------------------ Laravel runtime ----------
FROM php:8.3-apache AS app
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git unzip \
        libpq-dev libonig-dev libzip-dev \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_pgsql mbstring zip gd opcache \
    && a2enmod rewrite headers

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY . .

# Runtime deps only; Sanctum is a production dependency (auth:sanctum used in
# routes). post-autoload-dump runs `artisan package:discover` so providers work.
RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

# Serve the React SPA from Laravel's public dir (same origin as /api).
COPY --from=frontend /app/dist/ public/

COPY deploy/000-default.conf /etc/apache2/sites-available/000-default.conf

EXPOSE 80

CMD ["sh", "-c", "php artisan storage:link --force || true; php artisan migrate --force --no-interaction || echo 'migrate skipped/failed (continuing)'; apache2-foreground"]