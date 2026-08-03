FROM php:8.2-cli

RUN apt-get update && apt-get install -y \
        libsodium-dev \
        libcurl4-openssl-dev \
        unzip git \
    && docker-php-ext-install pdo pdo_mysql sodium curl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Docker builds always run as root, and there's no composer.lock committed
# yet (see README/commit note) — without these two env vars, Composer
# either aborts dependency-resolution features meant for interactive use
# or hits an OOM kill on memory-constrained free-tier build machines
# while resolving the full dependency graph from scratch. Both are common,
# hard-to-diagnose causes of a bare "exit code: 2" with no clear message.
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV COMPOSER_MEMORY_LIMIT=-1

# Single install step (removed the earlier two-step "install deps, then
# copy code, then install again" layer-caching pattern — that pattern
# only pays off with a committed composer.lock making both steps
# deterministic; without one, it just resolves against Packagist twice,
# doubling the odds of hitting a transient network hiccup mid-build).
COPY . .
RUN composer install --no-dev --no-interaction --prefer-dist -vvv

# Render (and most PaaS hosts) inject a PORT env var and expect the
# process to bind to it rather than a fixed port — default to 80 for
# plain `docker run` / docker-compose use where PORT isn't set.
ENV PORT=80
EXPOSE 80

CMD ["sh", "-c", "php -S 0.0.0.0:${PORT} -t public"]
