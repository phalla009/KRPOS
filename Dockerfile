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
    libzip-dev \
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

RUN composer dump-autoload --optimize \
    && (php artisan package:discover --ansi || true)

# Nginx config
COPY nginx.conf /etc/nginx/sites-available/default

# Startup script (fix Windows line endings just in case)
COPY start.sh /usr/local/bin/start.sh
RUN sed -i 's/\r$//' /usr/local/bin/start.sh && chmod +x /usr/local/bin/start.sh

# Permissions
RUN mkdir -p storage/framework/cache storage/framework/sessions