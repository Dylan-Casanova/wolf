#!/bin/bash
set -e

cd /opt/wolf

echo "=== Pulling latest code ==="
git pull

echo "=== Building and starting containers ==="
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build

echo "=== Building frontend assets ==="
# Read VITE_* vars from .env
set -a
source <(grep '^VITE_' .env)
set +a

docker run --rm \
  -v /opt/wolf:/var/www \
  -w /var/www \
  -e VITE_REVERB_APP_KEY="$VITE_REVERB_APP_KEY" \
  -e VITE_REVERB_HOST="$VITE_REVERB_HOST" \
  -e VITE_REVERB_PORT="$VITE_REVERB_PORT" \
  -e VITE_REVERB_SCHEME="$VITE_REVERB_SCHEME" \
  -e VITE_APP_NAME="$VITE_APP_NAME" \
  node:20-alpine sh -c "npm ci; npx vite build"

echo "=== Cleaning up ==="
rm -f /opt/wolf/public/hot
docker compose exec app php artisan optimize:clear

echo "=== Deploy complete ==="
