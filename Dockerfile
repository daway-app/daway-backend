# ---- Frontend build stage ----
FROM node:22 AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY public ./public
COPY resources ./resources
COPY vite.config.js ./
RUN npm run build

# ---- Runtime stage ----
# Use the official PHP image
FROM php:8.4-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    curl \
    libzip-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    && docker-php-ext-configure gd \
    --with-freetype \
    --with-jpeg \
    && docker-php-ext-install \
    pdo_mysql \
    zip \
    gd \
    opcache \
    && rm -rf /var/lib/apt/lists/*

# Enable opcache for the CLI server (artisan serve)
RUN { \
        echo 'opcache.enable_cli=1'; \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.interned_strings_buffer=16'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=0'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

# Set working directory
WORKDIR /app

# Copy project files
COPY . .

# Copy the pre-built frontend assets from the frontend stage
COPY --from=frontend /app/public/build ./public/build

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- \
    --install-dir=/usr/local/bin \
    --filename=composer

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader

# Run as non-root user
RUN useradd -m -u 1000 appuser \
    && chown -R appuser:appuser /app \
    && mkdir -p /app/storage/framework/views \
    && mkdir -p /app/storage/framework/sessions \
    && mkdir -p /app/storage/framework/cache/data \
    && mkdir -p /app/storage/logs \
    && chown -R appuser:appuser /app/storage
USER appuser

# Expose port
EXPOSE 10000

# Cache config/routes at startup (env vars are already available at runtime),
# run migrations, keep the app AND the Aiven DB awake (free tiers sleep after inactivity)
# and start Laravel
CMD ["sh", "-c", "php artisan config:cache && php artisan route:cache && php artisan migrate --force && { while true; do curl -s -o /dev/null http://127.0.0.1:${PORT:-10000}/api/medicines; php artisan migrate:status > /dev/null 2>&1; sleep 240; done & } && exec php artisan serve --host=0.0.0.0 --port=${PORT:-10000}"]
