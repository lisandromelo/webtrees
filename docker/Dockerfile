FROM php:8.3-fpm-bookworm

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      libfreetype6-dev \
      libjpeg62-turbo-dev \
      libpng-dev \
      libicu-dev \
      libzip-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" gd intl zip pdo_mysql exif \
 && rm -rf /var/lib/apt/lists/*

RUN groupadd --gid 1000 webtrees \
 && useradd --uid 1000 --gid 1000 --no-create-home --shell /usr/sbin/nologin webtrees

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY php-fpm.conf /usr/local/etc/php-fpm.d/zzz-webtrees.conf
COPY php.ini /usr/local/etc/php/conf.d/webtrees.ini

WORKDIR /var/www/webtrees
