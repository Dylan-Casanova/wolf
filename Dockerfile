# =============================================================================
# Wolf — Multi-stage Dockerfile
# =============================================================================
# Stages:
#   base      → PHP 8.3-FPM with extensions (shared by dev & prod)
#   dev       → Xdebug, no asset build (code mounted via volume)
#   node      → Build frontend assets for production
#   production→ Optimized image with built assets, no dev deps
# =============================================================================

# ---------------------------------------------------------------------------
# Stage: base
# ---------------------------------------------------------------------------
FROM php:8.4-fpm-alpine AS base

# System dependencies required by PHP extensions and Laravel
RUN apk add --no-cache \
    bash \
    curl \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libzip-dev \
    oniguruma-dev \
    icu-dev \
    linux-headers \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        zip \
        intl \
        sockets \
    && docker-php-ext-enable sockets

# Install Redis extension
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# Custom PHP configuration
COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-wolf.ini

# Copy composer files first for layer caching
COPY composer.json composer.lock ./

# ---------------------------------------------------------------------------
# Stage: dev
# ---------------------------------------------------------------------------
FROM base AS dev

# Install Xdebug for debugging
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && apk del .build-deps

# Install all dependencies (including dev)
RUN composer install --no-scripts --no-interaction

# Copy application code
COPY . .

# Generate optimized autoload (with dev deps)
RUN composer dump-autoload

# Ensure storage and cache directories are writable
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 9000

CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# Stage: node (build frontend assets for production)
# ---------------------------------------------------------------------------
FROM node:20-alpine AS node

WORKDIR /var/www

COPY package.json package-lock.json* ./
RUN npm ci

COPY . .
RUN npm run build

# ---------------------------------------------------------------------------
# Stage: production
# ---------------------------------------------------------------------------
FROM base AS production

# Install production dependencies only
RUN composer install --no-dev --no-scripts --no-interaction --optimize-autoloader

# Copy application code
COPY . .

# Copy built frontend assets from node stage
COPY --from=node /var/www/public/build public/build

# Generate optimized autoload
RUN composer dump-autoload --optimize --no-dev

# Laravel optimizations
RUN php artisan config:cache \
    && php artisan route:cache \
    && php artisan view:cache

# Ensure storage and cache directories are writable
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 9000

CMD ["php-fpm"]
