FROM php:8.4-apache

ARG APP_DIR=/var/www/html
ENV APACHE_DOCUMENT_ROOT=${APP_DIR}/public
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV APP_ENV=prod
ENV APP_DEBUG=0

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libicu-dev libzip-dev libpq-dev \
    && docker-php-ext-install intl pdo pdo_mysql pdo_pgsql zip opcache \
    && a2enmod rewrite headers \
    && sed -ri -e "s!/var/www/html!${APACHE_DOCUMENT_ROOT}!g" /etc/apache2/sites-available/*.conf \
    && sed -ri -e "s!/var/www/!${APP_DIR}/!g" /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && echo 'ServerName localhost' > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR ${APP_DIR}

COPY . ${APP_DIR}

RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader

RUN php bin/console asset-map:compile --env=prod

RUN mkdir -p var/cache var/log var/share \
    && chown -R www-data:www-data var

EXPOSE 80

CMD ["apache2-foreground"]
