FROM php:8.2-cli

RUN apt-get update && apt-get install -y \
        libsodium-dev \
        libcurl4-openssl-dev \
        unzip git \
    && docker-php-ext-install pdo pdo_mysql sodium curl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json ./
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist || true

COPY . .
RUN composer install --no-dev --no-interaction --prefer-dist

EXPOSE 80

CMD ["php", "-S", "0.0.0.0:80", "-t", "public"]
