# syntax=docker/dockerfile:1

# --- Composer dependencies (no dev tools in the final image) -----------------
FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-scripts \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --classmap-authoritative \
    --ignore-platform-reqs

# --- Runtime -----------------------------------------------------------------
FROM php:8.5-apache

SHELL ["/bin/bash", "-o", "pipefail", "-c"]

RUN set -eux; \
    savedAptMark="$(apt-mark showmanual)"; \
    apt-get update; \
    apt-get install -y --no-install-recommends libjpeg62-turbo-dev libpng-dev libwebp-dev libzip-dev; \
    docker-php-ext-configure gd --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(nproc)" exif gd mysqli zip; \
    # keep only the shared libraries the extensions actually link against
    apt-mark auto '.*' > /dev/null; \
    apt-mark manual $savedAptMark; \
    find /usr/local -type f -executable -exec ldd '{}' ';' \
        | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) { next }; gsub("^/(usr/)?", "", so); printf "*%s\n", so }' \
        | sort -u | xargs -r dpkg-query --search | grep -v '^diversion' \
        | cut -d: -f1 | tr ',' '\n' | tr -d ' ' | sort -u | xargs -r apt-mark manual; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /var/lib/apt/lists/*; \
    mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"; \
    a2enmod headers

COPY docker/php.ini "$PHP_INI_DIR/conf.d/99-picdrop.ini"
COPY docker/apache.conf /etc/apache2/conf-enabled/zz-picdrop.conf
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/picdrop-entrypoint

# Everything outside /var/www/html is not reachable via HTTP.
COPY --from=vendor /app/vendor /var/www/vendor
COPY db/migrations /var/www/db/migrations
COPY src/ /var/www/html/

RUN mkdir -p /var/www/html/uploads \
    && chown -R www-data:www-data /var/www/html/uploads

VOLUME ["/var/www/html/uploads"]
EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl -fsS -o /dev/null http://localhost/healthz.php || exit 1

ENTRYPOINT ["picdrop-entrypoint"]
CMD ["apache2-foreground"]
