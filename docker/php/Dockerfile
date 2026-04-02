# ============================================================
#  Stage 1: production base
# ============================================================
FROM php:8.2-fpm AS prod

LABEL maintainer="currency-converter"

# ── System dependencies ──
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        curl \
        libicu-dev \
        libzip-dev \
        libonig-dev \
        libpng-dev \
    && docker-php-ext-install \
        pdo_mysql \
        bcmath \
        intl \
        zip \
        opcache \
        mbstring \
    && pecl install redis-6.0.2 \
    && docker-php-ext-enable redis \
    && apt-get purge -y --auto-remove libpng-dev \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

# ── OPcache production settings ──
RUN { \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.interned_strings_buffer=16'; \
        echo 'opcache.max_accelerated_files=10000'; \
        echo 'opcache.revalidate_freq=0'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.enable_cli=1'; \
    } > /usr/local/etc/php/conf.d/opcache-prod.ini

# ── PHP production settings ──
RUN { \
        echo 'memory_limit=256M'; \
        echo 'upload_max_filesize=20M'; \
        echo 'post_max_size=25M'; \
        echo 'date.timezone=UTC'; \
        echo 'expose_php=Off'; \
    } > /usr/local/etc/php/conf.d/app.ini

# ── Composer ──
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# ============================================================
#  Stage 2: development (adds Xdebug + relaxed OPcache)
# ============================================================
FROM prod AS dev

RUN pecl install xdebug-3.3.1 \
    && docker-php-ext-enable xdebug \
    && rm -rf /tmp/pear

# ── Xdebug config ──
RUN { \
        echo 'xdebug.mode=debug,coverage'; \
        echo 'xdebug.start_with_request=trigger'; \
        echo 'xdebug.client_host=host.docker.internal'; \
        echo 'xdebug.client_port=9003'; \
        echo 'xdebug.discover_client_host=0'; \
        echo 'xdebug.idekey=PHPSTORM'; \
        echo 'xdebug.log_level=0'; \
    } > /usr/local/etc/php/conf.d/xdebug.ini

# ── Override OPcache for dev: revalidate on every request ──
RUN { \
        echo 'opcache.validate_timestamps=1'; \
        echo 'opcache.revalidate_freq=0'; \
    } > /usr/local/etc/php/conf.d/opcache-dev.ini

# ── Relaxed error reporting for dev ──
RUN { \
        echo 'display_errors=On'; \
        echo 'error_reporting=E_ALL'; \
    } > /usr/local/etc/php/conf.d/error-dev.ini

USER www-data
