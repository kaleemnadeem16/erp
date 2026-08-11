# syntax=docker/dockerfile:1
#
# Base digest pinned to match invoiceflow-backend / crm_backend exactly (Alpine 3.23.4,
# PHP 8.4.21) so this image shares base layers with them on mainvm — see
# docs/architecture/environments.md#image-layer-sharing. Pinned by INDEX digest, which
# resolves per-platform, so this same line builds correctly on amd64 (dev) and arm64 (mainvm).
# Never replace with a platform digest or a floating tag — see docs/architecture/deployment.md.
FROM php:8.4.21-fpm-alpine3.23@sha256:2e8d5b74437b02cbc3c632903d20a10fdcc956ba56d25bff951cc2b610767c9a AS base

# install-php-extensions (not PECL directly) — PECL's channel service is unreliable from
# CI/build environments; this pulls prebuilt/verified binaries and manages its own apk
# build deps per extension. https://github.com/mlocati/docker-php-extension-installer
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/bin/
RUN install-php-extensions \
        pdo_pgsql \
        zip \
        bcmath \
        opcache \
        pcntl \
        intl \
        redis

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# ---------------------------------------------------------------------------
# dev — php-fpm only; a separate `nginx` compose service fastcgi_passes to it.
# vendor/ is installed into a named volume by entrypoint.dev.sh, not baked in.
FROM base AS dev

COPY docker/backend/php-dev.ini /usr/local/etc/php/conf.d/99-dev.ini
COPY docker/backend/entrypoint.dev.sh /usr/local/bin/entrypoint.dev.sh
RUN chmod +x /usr/local/bin/entrypoint.dev.sh

EXPOSE 9000
ENTRYPOINT ["entrypoint.dev.sh"]
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# vendor — isolated stage so composer install is cached independently of app code changes.
FROM base AS vendor

COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

# ---------------------------------------------------------------------------
# production — bundles nginx + php-fpm via supervisord (one HTTP port for Traefik),
# matching the invoiceflow-backend pattern so the deploy/Traefik setup stays consistent.
FROM base AS production

RUN apk add --no-cache nginx supervisor

COPY --from=vendor /var/www/html/vendor ./vendor
COPY backend/. .

RUN composer run-script post-autoload-dump --no-dev --no-interaction \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 storage bootstrap/cache

COPY docker/backend/nginx.conf /etc/nginx/nginx.conf
COPY docker/backend/php-prod.ini /usr/local/etc/php/conf.d/php-prod.ini
COPY docker/backend/supervisord.conf /etc/supervisord.conf

RUN ln -sf /dev/stdout /var/log/nginx/access.log \
    && ln -sf /dev/stderr /var/log/nginx/error.log \
    && printf '#!/bin/sh\nphp /var/www/html/artisan storage:link --force 2>/dev/null || true\nexec /usr/bin/supervisord -c /etc/supervisord.conf\n' > /start.sh \
    && chmod +x /start.sh

EXPOSE 80
CMD ["/start.sh"]
