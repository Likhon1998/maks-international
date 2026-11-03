#!/usr/bin/env bash
# Deploy / update Maks International on cPanel shared hosting.
# Usage: bash ~/repositories/maks-international/scripts/deploy.sh
set -euo pipefail

APP_DIR="$HOME/repositories/maks-international"
DOC_ROOT="$HOME/trade.maksgadget.com"
APP_URL="https://trade.maksgadget.com"
DB_NAME="maksxkbj_maks-international"
DB_USER="maksxkbj_maks-international"
BRANCH="main"

step() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33mWARN: %s\033[0m\n' "$*"; }
fail() { printf '\n\033[1;31mERROR: %s\033[0m\n' "$*" >&2; exit 1; }

cd "$APP_DIR" || fail "App directory not found: $APP_DIR"

step "Selecting PHP 8.2+ CLI"
PHP=""
for c in php \
  /opt/alt/php84/usr/bin/php /opt/alt/php83/usr/bin/php /opt/alt/php82/usr/bin/php \
  /opt/cpanel/ea-php84/root/usr/bin/php /opt/cpanel/ea-php83/root/usr/bin/php /opt/cpanel/ea-php82/root/usr/bin/php; do
  if command -v "$c" >/dev/null 2>&1 && "$c" -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' >/dev/null 2>&1; then
    PHP="$(command -v "$c")"
    break
  fi
done
[ -n "$PHP" ] || fail "No PHP >= 8.2 CLI found. Set PHP 8.2+ in cPanel > Select PHP Version, then re-run."
echo "Using $PHP ($("$PHP" -r 'echo PHP_VERSION;'))"
PHPX=("$PHP" -d display_errors=stderr -d memory_limit=-1)

step "Checking PHP extensions"
missing=()
for ext in ctype curl dom fileinfo mbstring openssl pdo_mysql tokenizer xml; do
  "$PHP" -m | grep -qix "$ext" || missing+=("$ext")
done
[ ${#missing[@]} -eq 0 ] || fail "Missing PHP extensions: ${missing[*]}. Enable them in cPanel > Select PHP Version > Extensions."
echo "All required extensions present."

step "Pulling latest code ($BRANCH)"
git pull --ff-only origin "$BRANCH"

step "Preparing storage directories"
mkdir -p storage/app/public storage/framework/{cache/data,sessions,views,testing} storage/logs bootstrap/cache
chmod -R 775 storage bootstrap/cache

step "Configuring .env"
# Must exist before composer install — artisan package:discover boots the app and
# Laravel defaults to sqlite when DB_CONNECTION is missing.
if [ ! -f .env ]; then
  read -rsp "MySQL password for user $DB_USER: " DB_PASS; echo
  case "$DB_PASS" in *"'"*) fail "Password contains a single quote ('); change it in cPanel and re-run." ;; esac
  cat > .env <<EOF
APP_NAME="Maks International"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=$APP_URL
APP_TIMEZONE=Asia/Dhaka

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US
APP_MAINTENANCE_DRIVER=file
BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=error

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=$DB_NAME
DB_USERNAME=$DB_USER
DB_PASSWORD='$DB_PASS'

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=
SESSION_SECURE_COOKIE=true

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database
CACHE_STORE=database

MAIL_MAILER=log
MAIL_FROM_ADDRESS="hello@maksgadget.com"
MAIL_FROM_NAME="\${APP_NAME}"

VITE_APP_NAME="\${APP_NAME}"

WHATSAPP_DRIVER=log
WHATSAPP_FROM_NAME="Maks International"
WHATSAPP_DEFAULT_COUNTRY=880
EOF
  chmod 600 .env
  echo ".env created."
else
  echo ".env already exists, keeping it."
fi

step "Installing Composer (if needed)"
COMPOSER="$HOME/bin/composer"
if [ ! -f "$COMPOSER" ]; then
  mkdir -p "$HOME/bin"
  curl -sS https://getcomposer.org/installer | "$PHP" -- --install-dir="$HOME/bin" --filename=composer
fi
"${PHPX[@]}" "$COMPOSER" --version

step "Installing PHP dependencies"
COMPOSER_MEMORY_LIMIT=-1 "${PHPX[@]}" "$COMPOSER" install --no-dev --optimize-autoloader --no-interaction --prefer-dist

grep -q '^APP_KEY=base64:' .env || "${PHPX[@]}" artisan key:generate --force

step "Clearing stale caches"
rm -f bootstrap/cache/config.php bootstrap/cache/routes-*.php
"${PHPX[@]}" artisan config:clear
"${PHPX[@]}" artisan route:clear
"${PHPX[@]}" artisan view:clear

step "Running migrations"
"${PHPX[@]}" artisan migrate --force
"${PHPX[@]}" artisan cache:clear

SEED_MARKER="storage/app/.rbac_seeded"
if [ ! -f "$SEED_MARKER" ]; then
  step "Seeding roles + Super Admin (first deploy only)"
  "${PHPX[@]}" artisan db:seed --class=RbacSeeder --force
  date > "$SEED_MARKER"
fi

step "Linking public storage"
if [ ! -e public/storage ]; then
  "${PHPX[@]}" artisan storage:link || ln -s "$APP_DIR/storage/app/public" public/storage
fi

step "Pointing $DOC_ROOT -> $APP_DIR/public"
if [ -L "$DOC_ROOT" ]; then
  echo "Symlink already in place: $(readlink "$DOC_ROOT")"
else
  if [ -e "$DOC_ROOT" ]; then
    BAK="${DOC_ROOT}.bak.$(date +%Y%m%d%H%M%S)"
    mv "$DOC_ROOT" "$BAK"
    echo "Old document root moved to $BAK"
    if [ -f "$BAK/.htaccess" ] && grep -q "cPanel-generated handler" "$BAK/.htaccess"; then
      warn "Old .htaccess had a MultiPHP handler. Re-select PHP 8.2+ for trade.maksgadget.com in MultiPHP Manager."
    fi
  fi
  ln -s "$APP_DIR/public" "$DOC_ROOT"
fi

step "Granting web server read access to public files"
chmod o+x "$HOME/repositories" "$APP_DIR" "$APP_DIR/storage" "$APP_DIR/storage/app"
chmod -R o+rX "$APP_DIR/public" "$APP_DIR/storage/app/public"

step "Building production caches"
"${PHPX[@]}" artisan config:cache
"${PHPX[@]}" artisan route:cache
"${PHPX[@]}" artisan view:cache

step "Smoke test"
code="$(curl -sk -o /dev/null -w '%{http_code}' "$APP_URL" || true)"
echo "$APP_URL -> HTTP $code"
if [ "$code" != "200" ] && [ "$code" != "302" ]; then
  warn "Unexpected status. Last log lines:"
  tail -n 30 storage/logs/laravel.log 2>/dev/null || true
fi

printf '\n\033[1;32mDeploy finished.\033[0m\n'
printf 'Admin login: https://trade.maksgadget.com/admin/login\n'
printf 'Email: admin@maksinternational.com  |  Password: 12345678\n'
