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
    && rm -rf /var/lib/apt/lists/*

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
    && chown -R appuser:appuser /app
USER appuser

# Expose port
EXPOSE 10000

# Run migrations then start Laravel
CMD ["sh", "-c", "php artisan migrate --force && php artisan serve --host=0.0.0.0 --port=${PORT:-10000}"]
