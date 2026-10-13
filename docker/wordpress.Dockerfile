FROM wordpress:php8.3-fpm

# The official image ships mysqli (what WordPress core itself uses) but not
# pdo_mysql, which our own src/ code uses via WpdbConnection. Also add
# redis so public/front.php's cache layer isn't silently disabled.
RUN docker-php-ext-install pdo_mysql \
    && pecl install redis \
    && docker-php-ext-enable redis
