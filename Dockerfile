FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
    bash \
    git \
    unzip \
    icu-dev \
    icu-libs \
    libzip-dev \
    && docker-php-ext-install \
        pdo \
        pdo_mysql \
        intl \
        opcache \
        zip

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/app.ini

WORKDIR /var/www/html

EXPOSE 9000
CMD ["php-fpm"]
