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

# Render (and most PaaS hosts) inject a PORT env var and expect the
# process to bind to it rather than a fixed port — default to 80 for
# plain `docker run` / docker-compose use where PORT isn't set.
ENV PORT=80
EXPOSE 80

CMD ["sh", "-c", "php -S 0.0.0.0:${PORT} -t public"]
