#!/bin/sh
set -e

# 1. Ensure .env exists so Laravel can write the APP_KEY and read configuration
if [ ! -f ".env" ]; then
    if [ -f ".env.example" ]; then
        cp .env.example .env
    else
        touch .env
    fi
fi

# 2. Ensure SQLite database file exists
if [ ! -f "database/database.sqlite" ]; then
    touch database/database.sqlite
fi

chmod -R 777 storage bootstrap/cache database .env

# 3. Generate APP_KEY if not already set in environment
if [ -z "$APP_KEY" ]; then
    php artisan key:generate --force
fi

# 4. Run migrations and seed demo accounts
php artisan migrate --force --seed

# 5. Clear caches so dynamic cloud env variables take effect
php artisan config:clear
php artisan route:clear
php artisan view:clear

PORT="${PORT:-8080}"
echo "🚀 Starting PromptEdit Pixelforge Server on port $PORT..."
exec php artisan serve --host=0.0.0.0 --port="$PORT"
