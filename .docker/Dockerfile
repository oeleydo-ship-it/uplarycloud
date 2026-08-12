FROM node:22-alpine AS frontend
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY resources ./resources
COPY vite.config.js ./
RUN npm run build

FROM php:8.5-fpm-alpine AS application
RUN apk add --no-cache icu-dev libzip-dev linux-headers oniguruma-dev \
    && docker-php-ext-install bcmath intl opcache pcntl pdo_mysql zip \
    && pecl install redis \
    && docker-php-ext-enable redis
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist --optimize-autoloader
COPY . .
COPY --from=frontend /app/public/build ./public/build
RUN composer dump-autoload --no-dev --classmap-authoritative \
    && chown -R www-data:www-data storage bootstrap/cache
USER www-data
EXPOSE 9000
CMD ["php-fpm"]
