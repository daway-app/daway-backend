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
    bcmath \
    && rm -rf /var/lib/apt/lists/*

# C-2: `bcmath` is NOT part of the official php:8.4-cli image, yet the money
# paths call bccomp() directly (RefundService, AccountingLedger, Sale).
# Without it every refund / customer payment dies with
# "Call to undefined function bccomp()" → HTTP 500 «حدث خطأ داخلي».
# It is also not declared in composer.json, so nothing fails at build time.

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

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- \
    --install-dir=/usr/local/bin \
    --filename=composer

# Install PHP dependencies
# Retry up to 3 times: GitHub API sometimes returns HTTP 504 during builds
RUN composer install --no-dev --optimize-autoloader \
    || (sleep 10 && composer install --no-dev --optimize-autoloader) \
    || (sleep 30 && composer install --no-dev --optimize-autoloader)

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

# C-1: سيرفر PHP CLI يعمل بمعامل واحد افتراضياً — عمال متعددون يمنعون حجب
# الطلبات. 4 عمال على 512MB (Render Free) يستهلكون الذاكرة كلها تحت التزامن
# (OOM → 500 متقطعة/تعلق) → 2 عمال + memory_limit مضبوط.
ENV PHP_CLI_SERVER_WORKERS=2

# Cache config/routes at startup (env vars are already available at runtime),
# ensure the public storage symlink exists (H2), keep the app
# AND the Aiven DB awake (free tiers sleep after inactivity) and start Laravel
# C-1: تسخين /api/medicines مرة واحدة قبل الحلقة (يبني كاش الأدوية)، والحلقة تضرب
# /healthz فقط (يلمس الـ DB بـ SELECT 1 خفيف — بلا boot لـ Laravel كاملاً
# ولا منافسة على ذاكرة العمال) حتى لا تستهلك عمال الـ CLI server
#
# ── Phase F1: الحاوية تُشغّل الـAPI فقط ──────────────────────────────
# أُخرجت `migrate --force` والـseeders الخمسة من CMD نهائيًا:
#  · كل إعادة تشغيل كانت تشغّل هجرات وseeders — عملية صيانة لا يجب أن
#    تتكرر مع كل boot (ولا تصلح لـ zero-downtime: نسخة قديمة تعمل بينما
#    الهجرة تغيّر المخطط).
#  · فشل هجرة واحدة كان يمنع إقلاع الخدمة كلها (سلسلة &&).
#  · الـseeders كانت تكتب بيانات عرض/كلمات مرور افتراضية في الإنتاج.
# الهجرات تُنفَّذ الآن كخطوة نشر مستقلة ومراقبة (انظر docs/PRODUCTION-DEPLOY.md).
# Phase F1.1.1: critical preparation commands fail immediately. An existing
# storage link is accepted only when it resolves to the expected directory.
# Validate again after storage:link: Artisan may report a conflict but exit 0.
CMD ["sh", "-c", "set -eu; valid_storage_link() { [ -L public/storage ] && [ -d storage/app/public ] && link_target=$(readlink -f public/storage) && storage_target=$(readlink -f storage/app/public) && [ \"$link_target\" = \"$storage_target\" ]; }; php artisan config:cache; php artisan route:cache; if ! valid_storage_link; then php artisan storage:link; if ! valid_storage_link; then echo 'Invalid public/storage symlink' >&2; exit 1; fi; fi; { curl -s -o /dev/null http://127.0.0.1:${PORT:-10000}/api/medicines; while true; do curl -s -o /dev/null http://127.0.0.1:${PORT:-10000}/healthz; sleep 240; done & } && exec php artisan serve --host=0.0.0.0 --port=${PORT:-10000}"]
