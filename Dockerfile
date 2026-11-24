FROM php:8.3-fpm

# System dependencies + Nginx
RUN apt-get update && apt-get install -y \
    build-essential \
    libpng-dev \
    libjpeg-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    curl \
    git \
    vim \
    libzip-dev \
    sqlite3 \
    libsqlite3-dev \
    netcat-openbsd \
    nginx \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Install dependencies first (better build cache)
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --optimize-autoloader --no-scripts

# Copy project files
COPY . .

# Run composer scripts now that the full code is present
RUN composer dump-autoload --optimize \
    && (php artisan package:discover --ansi || true)

# Nginx config
COPY nginx.conf /etc/nginx/sites-available/default

# Wait-for-db script
COPY wait-for-db.sh /usr/local/bin/wait-for-db.sh
RUN chmod +x /usr/local/bin/wait-for-db.sh

# Permissions (after vendor/ exists)
RUN mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

ENV PORT=10000
EXPOSE 10000

CMD ["sh", "-c", "/usr/local/bin/wait-for-db.sh && php artisan config:clear && php artisan migrate --force && php-fpm -D && sed -i \"s/listen 10000;/listen ${PORT};/g\" /etc/nginx/sites-available/default && nginx -g 'daemon off;'"]