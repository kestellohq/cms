# The latest stable PHP (8.5.x). The minor version is pinned; rebuilding picks up its patch releases.
FROM php:8.5-fpm

ARG DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libwebp-dev \
    libavif-dev \
    libmagickwand-dev \
    libzip-dev \
    libicu-dev \
    libonig-dev \
    libpq-dev \
    ghostscript \
    jpegoptim \
    optipng \
    unzip \
    curl \
    git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp --with-avif \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        pdo_pgsql \
        mbstring \
        gd \
        zip \
        intl \
        bcmath \
    && pecl install xdebug apcu imagick \
    && docker-php-ext-enable xdebug apcu imagick \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY php/php.ini /usr/local/etc/php/conf.d/custom.ini

WORKDIR /var/www/html

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 9000

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
