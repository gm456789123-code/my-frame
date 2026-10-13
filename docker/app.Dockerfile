FROM php:8.3-cli

# mysqli is required to run WordPress core code (wp-load.php etc.) directly
# from this container, e.g. bin/console wp:install -- WordPress's own $wpdb
# uses mysqli, not PDO.
RUN docker-php-ext-install pdo_mysql mysqli \
    && pecl install redis \
    && docker-php-ext-enable redis

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/app
