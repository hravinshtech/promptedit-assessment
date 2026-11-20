#!/bin/sh
set -e

# Ensure SQLite file exists
if [ ! -f "database/database.sqlite" ]; then
    touch database/database.sqlite
fi

chmod 777 database/database.sqlite database

# Generate key if not set
if [ -z "$APP_KEY" ]; then
    php artisan key:generate --force
fi

# Run migrations and seeders if tables don't exist
php artisan migrate --force --seed

# Clear any cached configuration to read cloud env vars
php artisan config:clear
php artisan route:clear
php artisan view:clear

PORT="${PORT:-8080}"
echo "🚀 Starting PromptEdit Pixelforge Server on port $PORT..."
exec php artisan serve --host=0.0.0.0 --port="$PORT"
