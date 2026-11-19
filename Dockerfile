FROM php:8.4-fpm-alpine AS base

ENV APP_ENV=prod SYMFONY_ENV=prod APP_DEBUG=0

RUN apk add --no-cache nginx supervisor rabbitmq-c libpq \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS rabbitmq-c-dev postgresql-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql pdo_pgsql opcache \
    && pecl install amqp-2.2.0 \
    && docker-php-ext-enable amqp \
    && apk del .build-deps

WORKDIR /var/www/html

FROM base AS build

RUN apk add --no-cache git unzip
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --no-autoloader

COPY . .
RUN composer dump-autoload --no-dev --optimize --no-scripts

FROM base AS app

COPY --from=build /var/www/html .
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-banditore.conf
COPY docker/supervisord.conf /etc/supervisord.conf

RUN mkdir -p var/cache var/log var/sessions \
    && chown -R www-data:www-data var

EXPOSE 80
STOPSIGNAL SIGTERM
HEALTHCHECK --interval=10s --timeout=5s --start-period=10s \
    CMD wget -q --spider http://127.0.0.1/ || exit 1
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
