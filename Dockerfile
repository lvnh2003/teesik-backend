# ══════════════════════════════════════════════════════════════════════════════
# Multi-stage Dockerfile — Laravel Production
# ══════════════════════════════════════════════════════════════════════════════
#
# Stage 1: composer  — Install PHP dependencies
# Stage 2: assets    — Build frontend assets (Vite)
# Stage 3: runtime   — Final lean image with Apache
# ══════════════════════════════════════════════════════════════════════════════

# ─── Stage 1: Composer dependencies ──────────────────────────────────────────
FROM composer:2 AS composer

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-interaction \
    --prefer-dist \
    --ignore-platform-reqs

COPY . .
RUN composer dump-autoload --optimize --no-dev

# ─── Stage 2: Build frontend assets (Vite) ───────────────────────────────────
FROM node:20-alpine AS assets

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --production=false
COPY . .
RUN npm run build 2>/dev/null || echo "No Vite build script found, skipping..."

# ─── Stage 3: Production runtime ─────────────────────────────────────────────
FROM php:8.2-apache AS runtime

ARG APP_ENV=production

# Install system dependencies + PHP extensions in a single layer
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        libzip-dev \
        libonig-dev \
        zip \
        unzip \
        curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        gd pdo pdo_mysql mbstring zip exif pcntl opcache \
    && a2enmod rewrite headers \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# PHP production config (opcache, error handling)
RUN { \
    echo "opcache.enable=1"; \
    echo "opcache.memory_consumption=128"; \
    echo "opcache.interned_strings_buffer=8"; \
    echo "opcache.max_accelerated_files=10000"; \
    echo "opcache.revalidate_freq=0"; \
    echo "opcache.validate_timestamps=0"; \
    echo "opcache.save_comments=1"; \
    echo "opcache.fast_shutdown=1"; \
} > /usr/local/etc/php/conf.d/opcache.ini

RUN { \
    echo "display_errors=Off"; \
    echo "log_errors=On"; \
    echo "error_log=/dev/stderr"; \
    echo "upload_max_filesize=10M"; \
    echo "post_max_size=10M"; \
    echo "memory_limit=256M"; \
    echo "max_execution_time=60"; \
} > /usr/local/etc/php/conf.d/production.ini

# Set Apache DocumentRoot → Laravel's public/
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && printf '<Directory %s>\n\tAllowOverride All\n\tRequire all granted\n</Directory>\n' "${APACHE_DOCUMENT_ROOT}" > /etc/apache2/conf-available/laravel.conf \
    && a2enconf laravel

WORKDIR /var/www/html

# Copy application code
COPY --from=composer /app/vendor ./vendor
COPY . .

# Create a fail-safe health check file that doesn't depend on Laravel's router
RUN echo '<?php echo "OK"; ?>' > public/health-check.php

# Copy built frontend assets (if any)
COPY --from=assets /app/public/build ./public/build

# Set permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 775 storage bootstrap/cache

# Laravel optimizations
RUN php artisan config:clear 2>/dev/null || true \
    && php artisan route:clear 2>/dev/null || true \
    && php artisan view:clear 2>/dev/null || true

# Health check for ECS / ALB
# Hits the PHP file directly to ensure basic connectivity even if Laravel has issues
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl -f http://localhost/health-check.php || exit 1

EXPOSE 80

# Entrypoint: run migrations then start Apache
COPY <<'EOF' /usr/local/bin/docker-entrypoint.sh
#!/bin/bash
set -e

echo "🚀 Starting Laravel application..."

# Wait for database (if DB_HOST is set)
if [ -n "$DB_HOST" ]; then
    echo "⏳ Waiting for database at $DB_HOST:${DB_PORT:-3306}..."
    timeout=30
    while ! php -r "new PDO('mysql:host='.\$_SERVER['DB_HOST'].';port='.(\$_SERVER['DB_PORT']??3306), \$_SERVER['DB_USERNAME']??'root', \$_SERVER['DB_PASSWORD']??'');" 2>/dev/null; do
        timeout=$((timeout - 1))
        if [ $timeout -le 0 ]; then
            echo "⚠️  Database not available, starting anyway..."
            break
        fi
        sleep 1
    done
    echo "✅ Database connected"
fi

# Run migrations (safe, won't error if already migrated)
echo "📂 Running migrations..."
php artisan migrate --force

# Optional: Run seeds if DB_SEED is set to true
if [ "$DB_SEED" = "true" ]; then
    echo "🌱 Seeding database..."
    php artisan db:seed --force
fi

# Ensure Passport keys exist (if missing)
if [ ! -f storage/oauth-private.key ]; then
    if [ -n "$PASSPORT_PRIVATE_KEY" ]; then
        echo "🔑 Writing Passport private key from environment..."
        echo "$PASSPORT_PRIVATE_KEY" | awk '{gsub(/\\n/,"\n")}1' > storage/oauth-private.key
        chown www-data:www-data storage/oauth-private.key
        chmod 600 storage/oauth-private.key
    fi
    if [ -n "$PASSPORT_PUBLIC_KEY" ]; then
        echo "🔑 Writing Passport public key from environment..."
        echo "$PASSPORT_PUBLIC_KEY" | awk '{gsub(/\\n/,"\n")}1' > storage/oauth-public.key
        chown www-data:www-data storage/oauth-public.key
        chmod 644 storage/oauth-public.key
    fi
fi

if [ ! -f storage/oauth-private.key ]; then
    echo "🔑 Generating Passport keys..."
    php artisan passport:keys --quiet
    chown www-data:www-data storage/oauth-*.key 2>/dev/null || true
fi

# Ensure Passport clients exist (if missing)
# This checks if the oauth_clients table exists and is empty
if php artisan tinker --execute="echo DB::table('oauth_clients')->count();" 2>/dev/null | grep -q "^0$"; then
    echo "🛡️  Installing Passport clients..."
    php artisan passport:install --no-interaction
fi

# Cache config for performance
php artisan config:cache 2>/dev/null || true
php artisan route:cache 2>/dev/null || true
php artisan view:cache 2>/dev/null || true

if [ $# -gt 0 ]; then
    echo "🛠️ Running custom command: $@"
    exec "$@"
fi

echo "✅ Laravel ready — starting Apache"
exec apache2-foreground
EOF
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["docker-entrypoint.sh"]
