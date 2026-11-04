# syntax=docker/dockerfile:1
# Build context: ریشه‌ی همین ریپو (famo-api)

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist --optimize-autoloader

FROM famo-php-base:8.4 AS runtime

COPY docker/custom.ini "$PHP_INI_DIR/conf.d/zz-custom.ini"
COPY docker/remoteip.conf /etc/apache2/conf-available/remoteip.conf
COPY docker/apache-tune.conf /etc/apache2/conf-available/apache-tune.conf
RUN a2enconf remoteip apache-tune

RUN sed -ri "s!/var/www/html!/var/www/html/public!g" \
    /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf
RUN sed -ri "s/AllowOverride None/AllowOverride All/g" /etc/apache2/apache2.conf

COPY . /var/www/html/
COPY --from=vendor /app/vendor/ /var/www/html/vendor/
RUN mkdir -p /var/www/html/uploads /var/www/html/storage/cache /var/www/html/storage/logs \
    && chown -R www-data:www-data /var/www/html

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl -fsS http://127.0.0.1/health || exit 1

EXPOSE 80