#!/usr/bin/env bash
# HealthPass — Raspberry Pi dependency installer.
# Installs PHP 8.2+, Composer, MariaDB, Node.js, nginx on Raspberry Pi OS
# (Bookworm, 64-bit). Run once on a fresh Pi. See docs/deployment-pi.md.
#
# Usage:  sudo bash scripts/pi/install-deps.sh
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
  echo "Please run with sudo: sudo bash scripts/pi/install-deps.sh" >&2
  exit 1
fi

echo "==> Updating package lists"
apt-get update
apt-get upgrade -y

# ── PHP 8.2+ and the extensions Laravel 12 needs ─────────────────────────────
# Raspberry Pi OS Bookworm ships PHP 8.2 in its default repos, which satisfies
# Laravel 12's "php": "^8.2". If you are on an older Pi OS, add the
# ondrej/php-style repo or upgrade the OS instead of pinning an old PHP.
echo "==> Installing PHP and extensions"
apt-get install -y \
  php php-cli php-fpm \
  php-mbstring php-xml php-curl php-zip php-bcmath php-intl \
  php-mysql php-sqlite3 php-gd

php -v

# ── Composer (PHP dependency manager) ────────────────────────────────────────
echo "==> Installing Composer"
if ! command -v composer >/dev/null 2>&1; then
  EXPECTED_SIG="$(curl -fsSL https://composer.github.io/installer.sig)"
  curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
  ACTUAL_SIG="$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")"
  if [ "$EXPECTED_SIG" != "$ACTUAL_SIG" ]; then
    echo "Composer installer checksum mismatch — aborting." >&2
    rm -f /tmp/composer-setup.php
    exit 1
  fi
  php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
  rm -f /tmp/composer-setup.php
fi
composer --version

# ── MariaDB (MySQL-compatible; the app targets MySQL) ────────────────────────
echo "==> Installing MariaDB"
apt-get install -y mariadb-server
systemctl enable --now mariadb
echo "    Run 'sudo mysql_secure_installation' afterwards to set the root password."

# ── Node.js (for building front-end assets with 'npm run build') ─────────────
# The Pi only BUILDS assets; it never runs the Vite dev server. Node 20 LTS is
# fine. Using NodeSource keeps us off the older apt Node.
echo "==> Installing Node.js 20 LTS"
if ! command -v node >/dev/null 2>&1; then
  curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
  apt-get install -y nodejs
fi
node -v
npm -v

# ── nginx (recommended production web server; optional if using artisan serve)─
echo "==> Installing nginx"
apt-get install -y nginx

# ── Chromium (kiosk browser) ─────────────────────────────────────────────────
echo "==> Installing Chromium"
apt-get install -y chromium-browser || apt-get install -y chromium

echo
echo "Done. Next: bash scripts/pi/setup-app.sh   (see docs/deployment-pi.md)"
