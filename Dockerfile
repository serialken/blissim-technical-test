# Image PHP + Apache pour l'application Symfony
FROM php:8.5.12-apache

# Dépendances système et extensions PHP
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libicu-dev libzip-dev \
    && docker-php-ext-install pdo_mysql intl zip opcache \
    && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Apache : racine web sur public/, toutes les requêtes passent par index.php
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
        /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && printf '<Directory /var/www/html/public>\n    AllowOverride None\n    Require all granted\n    FallbackResource /index.php\n</Directory>\n' \
        > /etc/apache2/conf-available/symfony.conf \
    && a2enconf symfony \
    && a2enmod rewrite

WORKDIR /var/www/html

# Environnement de production dans le conteneur
ENV APP_ENV=prod \
    APP_DEBUG=0

# Dépendances PHP (sans les paquets de dev), puis le code
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --no-progress

COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && composer run-script --no-dev post-install-cmd \
    && chown -R www-data:www-data var

EXPOSE 80