FROM php:8.3-apache@sha256:11093ce0630a495ce9436647d2939b5ca44b411d2a04917afe8b2d051ae2d2ed
RUN docker-php-ext-install -j"$(nproc)" pdo_mysql mysqli
COPY artwork/ /var/www/html/artwork/
COPY docker/index.php /var/www/html/index.php
COPY docker/health.php /var/www/html/health.php
