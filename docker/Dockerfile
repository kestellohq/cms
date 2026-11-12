# The latest stable PHP (8.5.x). The minor version is pinned; rebuilding picks up its patch releases.
#
#   docker build docker                         the development image (Xdebug, errors shown): the default
#   docker build --target production docker     for a live site: no Xdebug, errors logged, not shown
FROM php:8.5-fpm AS base

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
    && pecl install apcu imagick \
    && docker-php-ext-enable apcu imagick \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 9000

# Healthy when PHP-FPM answers /up with 200: PHP runs and the database answers (see Up.php).
COPY php/healthcheck.php /usr/local/bin/kestello-healthcheck.php
HEALTHCHECK --interval=30s --timeout=15s --start-period=60s --retries=3 \
    CMD ["php", "/usr/local/bin/kestello-healthcheck.php"]

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]

FROM base AS production
COPY php/production.ini /usr/local/etc/php/conf.d/custom.ini

# The last stage is what a plain build makes: development, with Xdebug.
FROM base AS development
RUN pecl install xdebug && docker-php-ext-enable xdebug
COPY php/php.ini /usr/local/etc/php/conf.d/custom.ini
