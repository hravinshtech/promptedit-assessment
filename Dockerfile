# Multi-stage build for PromptEdit Pixelforge Assessment

# Stage 1: Build Frontend Assets
FROM node:20-alpine AS frontend
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY resources resources
COPY vite.config.js tailwind.config.js postcss.config.js jsconfig.json ./
RUN npm run build

# Stage 2: Production PHP Web Server
FROM php:8.3-cli-alpine

# Install system dependencies & PHP extensions (bcmath, pdo_sqlite)
RUN apk add --no-cache \
    curl \
    git \
    unzip \
    sqlite \
    sqlite-dev \
    libpng-dev \
    libzip-dev \
    icu-dev \
    && docker-php-ext-install bcmath pdo_sqlite intl opcache

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy application source
COPY . .

# Copy compiled frontend assets from Stage 1
COPY --from=frontend /app/public/build public/build

# Install production PHP dependencies
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Set up storage and cache permissions
RUN mkdir -p storage/framework/sessions \
    storage/framework/views \
    storage/framework/cache \
    storage/logs \
    bootstrap/cache \
    && chmod -R 777 storage bootstrap/cache database

# Entrypoint script: ensure database exists, migrate, seed, and serve
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 8080
ENV PORT=8080

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
