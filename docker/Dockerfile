# Contexte de build : racine du projet (docker compose s'en charge)

# 1. Interface React (Vite)
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY tsconfig.json vite.config.ts ./
COPY resources ./resources
RUN npm run build

# 2. Dépendances PHP (sans les outils de développement)
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-scripts --no-autoloader --ignore-platform-req=ext-*
COPY . .
RUN composer dump-autoload --optimize --no-dev --no-scripts

# 3. Image finale : Apache + PHP 8.3
FROM php:8.3-apache
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libzip-dev libicu-dev \
    && docker-php-ext-install pdo_pgsql zip intl opcache \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf \
    && echo 'upload_max_filesize=25M\npost_max_size=26M\nexpose_php=Off' > /usr/local/etc/php/conf.d/gedflow.ini \
    && echo 'ServerTokens Prod\nServerSignature Off' >> /etc/apache2/conf-enabled/security.conf

WORKDIR /var/www/html
COPY --from=vendor /app /var/www/html
COPY --from=assets /app/public/build /var/www/html/public/build
RUN rm -rf node_modules tests && chown -R www-data:www-data storage bootstrap/cache
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

ENTRYPOINT ["entrypoint"]
CMD ["apache2-foreground"]
