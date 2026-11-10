FROM php:8.4-fpm-alpine

# System packages needed to build the PHP extensions below.
RUN apk add --no-cache \
      git curl unzip icu-dev oniguruma-dev libzip-dev \
      $PHPIZE_DEPS linux-headers

# pdo_mysql for the database, redis for cache/queue/session (phpredis, matching
# REDIS_CLIENT=phpredis), bcmath for precise money arithmetic, intl for the
# number formatting used throughout the analysis output.
RUN docker-php-ext-install -j"$(nproc)" pdo_mysql bcmath intl zip opcache \
    && pecl install redis \
    && docker-php-ext-enable redis

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# Install dependencies first so a source-only change does not invalidate the
# (slow) composer layer.
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-scripts --no-autoloader --prefer-dist

COPY . .
RUN composer dump-autoload --optimize \
    && mkdir -p storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

# opcache settings suited to a long-running FPM process.
RUN { \
      echo 'opcache.enable=1'; \
      echo 'opcache.memory_consumption=128'; \
      echo 'opcache.max_accelerated_files=10000'; \
      echo 'opcache.validate_timestamps=1'; \
      echo 'opcache.revalidate_freq=2'; \
      echo 'memory_limit=512M'; \
      echo 'max_execution_time=300'; \
    } > /usr/local/etc/php/conf.d/zz-app.ini

EXPOSE 9000
CMD ["php-fpm"]
