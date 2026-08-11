# SchoolPilot API — production image.
#
# php-fpm rather than `artisan serve`: nginx (see docker/nginx/conf.d) terminates
# HTTP and speaks FastCGI to this container on 9000.
#
# Extensions are not arbitrary — each one is load-bearing:
#   pdo_pgsql  Postgres, the production database
#   gd         dompdf report cards and bacon/bacon-qr-code attendance QR codes
#   zip        dompdf font caching and composer archive installs
#   bcmath     money arithmetic that must not go through floats
#   redis      cache, queue and session driver (config/database.php uses phpredis)
#   opcache    without it PHP re-parses the whole framework on every request

FROM php:8.2-fpm AS base

RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libzip-dev \
        libxml2-dev \
        unzip \
        git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql gd zip bcmath opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get purge -y git \
    && apt-get autoremove -y \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/backend

# ---------------------------------------------------------------------------
# Dependency layer. Copied before the application source so that a code-only
# change does not invalidate the (slow) composer install layer.
# ---------------------------------------------------------------------------
FROM base AS vendor

COPY composer.json composer.lock ./

# --no-scripts because artisan is not present yet; package discovery runs below
# once the full source has been copied in.
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader

# ---------------------------------------------------------------------------
# Runtime.
# ---------------------------------------------------------------------------
FROM base AS runtime

COPY --from=vendor /var/www/backend/vendor ./vendor
COPY . .

RUN composer dump-autoload --no-dev --optimize \
    && php artisan package:discover --ansi

# php-fpm runs as www-data; only these two trees are written to at runtime.
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rw storage bootstrap/cache

COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

EXPOSE 9000

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]
