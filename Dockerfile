FROM php:8.2-cli-alpine

RUN apk add --no-cache \
    postgresql-dev \
    postgresql-client \
    curl \
    zip \
    unzip \
    git \
    bash

RUN docker-php-ext-install pdo_pgsql

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json ./
RUN composer install --optimize-autoloader --no-interaction || true

COPY . .

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/index.php"]
