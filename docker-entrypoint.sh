#!/bin/sh
set -e

# 1. Ensure .env file exists
if [ ! -f ".env" ]; then
    if [ -f ".env.example" ]; then
        cp .env.example .env
    else
        touch .env
    fi
fi

# 2. Inject cloud environment variables into .env to ensure Laravel dotenv reads them
if [ -n "$PIXELFORGE_API_KEY" ]; then
    sed -i '/PIXELFORGE_API_KEY/d' .env 2>/dev/null || true
    echo "PIXELFORGE_API_KEY=$PIXELFORGE_API_KEY" >> .env
fi

if [ -n "$PIXELFORGE_BASE_URL" ]; then
    sed -i '/PIXELFORGE_BASE_URL/d' .env 2>/dev/null || true
    echo "PIXELFORGE_BASE_URL=$PIXELFORGE_BASE_URL" >> .env
fi

if [ -n "$PIXELFORGE_WEBHOOK_SECRET" ]; then
    sed -i '/PIXELFORGE_WEBHOOK_SECRET/d' .env 2>/dev/null || true
    echo "PIXELFORGE_WEBHOOK_SECRET=$PIXELFORGE_WEBHOOK_SECRET" >> .env
fi

# 3. Ensure SQLite database file exists
if [ ! -f "database/database.sqlite" ]; then
    touch database/database.sqlite
fi

chmod -R 777 storage bootstrap/cache database .env

# 4. Generate APP_KEY if not already set
if [ -z "$APP_KEY" ]; then
    php artisan key:generate --force
fi

# 5. Run migrations and seed demo accounts
php artisan migrate --force --seed

# 6. Clear caches so dynamic cloud env variables take effect
php artisan config:clear
php artisan route:clear
php artisan view:clear

PORT="${PORT:-8080}"
echo "🚀 Starting PromptEdit Pixelforge Server on port $PORT..."
exec php artisan serve --host=0.0.0.0 --port="$PORT"
