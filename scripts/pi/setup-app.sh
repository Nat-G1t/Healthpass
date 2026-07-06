#!/usr/bin/env bash
# HealthPass — clone + configure the app on the Pi.
# Assumes scripts/pi/install-deps.sh already ran. See docs/deployment-pi.md.
#
# Usage:  bash scripts/pi/setup-app.sh [target-dir]
#         target-dir defaults to /var/www/healthpass
set -euo pipefail

REPO_URL="https://github.com/Nat-G1t/Healthpass.git"
APP_DIR="${1:-/var/www/healthpass}"

echo "==> Cloning HealthPass into $APP_DIR"
if [ ! -d "$APP_DIR/.git" ]; then
  sudo mkdir -p "$(dirname "$APP_DIR")"
  sudo git clone "$REPO_URL" "$APP_DIR"
  # Hand ownership to the current user for composer/npm; nginx/php-fpm read as
  # www-data (storage perms are fixed below).
  sudo chown -R "$USER":"$USER" "$APP_DIR"
else
  echo "    $APP_DIR already exists — pulling latest instead."
  git -C "$APP_DIR" pull --ff-only
fi

cd "$APP_DIR"

echo "==> Installing PHP dependencies (production, no dev)"
composer install --no-dev --optimize-autoloader

echo "==> Installing Node dependencies and BUILDING assets"
# The Pi builds assets once. It must NOT run 'npm run dev' (Vite dev server) —
# production serves the compiled files in public/build.
npm ci
npm run build

echo "==> Preparing .env"
if [ ! -f .env ]; then
  cp scripts/pi/pi.env.example .env
  echo "    Copied scripts/pi/pi.env.example -> .env  (edit DB_PASSWORD etc.)"
fi

php artisan key:generate

echo "==> Running migrations"
# --force because APP_ENV=production would otherwise prompt for confirmation.
php artisan migrate --force

echo "==> Caching config/routes/views for production"
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "==> Fixing storage / cache permissions for the web server (www-data)"
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R ug+rwX storage bootstrap/cache

echo
echo "Done. Configure the web server (nginx or artisan serve) and the kiosk"
echo "autostart as described in docs/deployment-pi.md."
