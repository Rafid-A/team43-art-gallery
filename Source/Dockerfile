FROM php:8.3-apache@sha256:11093ce0630a495ce9436647d2939b5ca44b411d2a04917afe8b2d051ae2d2ed
RUN apt-get update \
    && apt-get install -y --no-install-recommends libjpeg62-turbo-dev libpng-dev libwebp-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd exif pdo_mysql mysqli \
    && rm -rf /var/lib/apt/lists/*
COPY docker/php-gallery.ini /usr/local/etc/php/conf.d/gallery.ini
COPY artwork/ /var/www/html/artwork/
# Uploaded images live in a named volume mounted here (compose.yaml); a new
# volume takes this ownership, so Apache can write to it.
RUN chown www-data:www-data /var/www/html/artwork/images/uploads
COPY docker/index.php /var/www/html/index.php
COPY docker/health.php /var/www/html/health.php
