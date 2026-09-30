#!/bin/bash
# Magento's install is a CLI ritual, and every step of it needs more memory
# than the image's CLI default of 128M. Runs inside the app container, from
# the install and upgrade stages.
set -euo pipefail

MODE="${1:-install}"
cd /app

# -1 rather than a number: setup:install peaks while autoloading 904 modules,
# and the number that is enough moves with every Magento release.
magento() {
  php -d memory_limit=-1 bin/magento "$@"
}

# The database is a separate container on some accounts and the account's own
# MySQL server on others. Either way the install stage can start before it is
# accepting connections, and setup:install's failure there is a half-written
# database rather than a clean error.
wait_for_database() {
  local dsn="mysql:host=${DB_HOST:-127.0.0.1};port=${DB_PORT:-3306}"
  for _ in $(seq 1 60); do
    if php -r 'new PDO($argv[1], $argv[2], $argv[3]);' "$dsn" "${DB_USERNAME:-}" "${DB_PASSWORD:-}" 2>/dev/null; then
      return 0
    fi
    sleep 2
  done
  echo "panelalpha: the database at ${DB_HOST:-127.0.0.1} never accepted a connection" >&2
  return 1
}

# Without this every request is
#   ReflectionException: Class "Magento\Framework\App\Http\Interceptor" does not exist
# and the store answers 500 on its own front page.
compile() {
  magento setup:di:compile
  magento cache:flush
}

if [ "$MODE" = upgrade ]; then
  # Nothing to upgrade until something is installed. Not an error: the upgrade
  # stage runs on every deploy, including the one before the first install.
  # env.php is a link into /pa-data (hooks/prepare.sh), so it outlives ~/project.
  if [ ! -f app/etc/env.php ]; then
    echo "panelalpha: no app/etc/env.php (~/.panelalpha/magento/etc); nothing to upgrade" >&2
    exit 0
  fi
  wait_for_database
  magento setup:upgrade --keep-generated
  compile
  exit 0
fi

# An install over a live store would drop its data. env.php is written by
# setup:install and by nothing else, so its presence is the store existing.
if [ -f app/etc/env.php ]; then
  exit 0
fi

wait_for_database

# APP_URL is what the engine resolved for this project; Magento stores it as
# base_url and generates every link from it, so a wrong value here is a
# redirect loop rather than a cosmetic error.
BASE_URL="${APP_URL:-http://localhost}"
case "$BASE_URL" in
  */) ;;
  *) BASE_URL="$BASE_URL/" ;;
esac

magento setup:install \
  --base-url="$BASE_URL" \
  --db-host="${DB_HOST:-127.0.0.1}:${DB_PORT:-3306}" \
  --db-name="${DB_DATABASE:?the magento recipe declares database: mysql, so this is set}" \
  --db-user="${DB_USERNAME:-}" \
  --db-password="${DB_PASSWORD:-}" \
  --search-engine=opensearch \
  --opensearch-host="${MAGENTO_SEARCH_HOST:-opensearch}" \
  --opensearch-port="${MAGENTO_SEARCH_PORT:-9200}" \
  --backend-frontname="${MAGENTO_ADMIN_URI:-admin}" \
  --admin-firstname=Store \
  --admin-lastname=Owner \
  --admin-email="${MAGENTO_ADMIN_EMAIL:?written by the prepare hook}" \
  --admin-user="${MAGENTO_ADMIN_USER:?written by the prepare hook}" \
  --admin-password="${MAGENTO_ADMIN_PASSWORD:?written by the prepare hook}" \
  --language=en_US \
  --currency=USD \
  --timezone=UTC \
  --use-rewrites=1 \
  --no-interaction

compile

echo "panelalpha: Magento admin is at ${BASE_URL}${MAGENTO_ADMIN_URI:-admin}"
