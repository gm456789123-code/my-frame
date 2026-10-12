FROM php:8.3-cli

RUN docker-php-ext-install pdo_mysql \
    && pecl install redis \
    && docker-php-ext-enable redis

WORKDIR /var/www/app
