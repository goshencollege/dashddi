FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
    bash \
    git \
    openssh-client \
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
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html

EXPOSE 9000
ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
