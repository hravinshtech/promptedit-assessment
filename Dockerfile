FROM php:8.3-cli-alpine

# Install system dependencies, PHP extensions, Node.js and npm
RUN apk add --no-cache \
    curl \
    git \
    unzip \
    sqlite \
    sqlite-dev \
    libpng-dev \
    libzip-dev \
    icu-dev \
    nodejs \
    npm \
    && docker-php-ext-install bcmath pdo_sqlite intl opcache

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy source code
COPY . .

# 1. Install PHP dependencies (creates vendor/tightenco/ziggy needed by Vite)
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN composer install --optimize-autoloader --no-interaction

# 2. Install Node dependencies and compile frontend assets
RUN npm ci && npm run build && rm -rf node_modules

# 3. Setup directories and permissions
RUN mkdir -p storage/framework/sessions \
    storage/framework/views \
    storage/framework/cache \
    storage/logs \
    bootstrap/cache \
    && chmod -R 777 storage bootstrap/cache database

# Entrypoint script
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 8080
ENV PORT=8080

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
