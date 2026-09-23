#!/usr/bin/env bash
set -e

ENGINE_DIR="/opt/panelalpha/shared-hosting"
ENV_FILE="$ENGINE_DIR/.env"
VAR_NAME="CORE_MYSQL_ROOT_PASSWORD"
CONTAINER_NAME="core-db"
VOLUME_NAME="shared-hosting_database-core-data"
IMAGE="ghcr.io/panelalpha/app-database:20260525"
if ! docker image inspect "$IMAGE" >/dev/null 2>&1; then
  if ! docker pull "$IMAGE"; then
    docker build --tag "$IMAGE" - <"$ENGINE_DIR/dockerfiles/Dockerfile-database"
  fi
fi

FORCE_RESET=0
if [[ "${1:-}" == "--force-reset" ]]; then
  FORCE_RESET=1
fi

# Load .env if present
if [[ -f "$ENV_FILE" ]]; then
  set -a
  source "$ENV_FILE"
  set +a
fi

# If password already exists and not forcing reset
if [[ $FORCE_RESET -eq 0 && -n "${CORE_MYSQL_ROOT_PASSWORD:-}" ]]; then
  echo "$CORE_MYSQL_ROOT_PASSWORD"
  exit 0
fi

echo "Resetting MariaDB root password (maintenance required)..."

NEW_PASSWORD=$(cat /dev/urandom | tr -dc 'a-zA-Z0-9' | fold -w 12 | head -n 1)

# Stop running DB
echo "Stopping $CONTAINER_NAME"
# docker stop "$CONTAINER_NAME" >/dev/null
docker compose -f "$ENGINE_DIR/docker-compose.yml" stop "$CONTAINER_NAME"

# Start temporary server with auth bypass
echo "Starting recovery server"
RECOVERY_CONTAINER_ID=$(
  docker run -d --rm \
    --name "${CONTAINER_NAME}-recovery" \
    -v "${VOLUME_NAME}:/var/lib/mysql" \
    "$IMAGE" \
    mariadbd --skip-grant-tables --skip-networking
)

# Ensure cleanup on failure
cleanup() {
  docker stop "${CONTAINER_NAME}-recovery" >/dev/null 2>&1 || true
  docker compose -f "$ENGINE_DIR/docker-compose.yml" up -d
}
trap cleanup EXIT

# Wait for socket
echo "Waiting for MariaDB socket"
for i in {1..30}; do
  if docker exec "$RECOVERY_CONTAINER_ID" mariadb -uroot -e "SELECT 1" >/dev/null 2>&1; then
    break
  fi
  sleep 1
done

# Reset root password
echo "Setting new root password"
docker exec "$RECOVERY_CONTAINER_ID" mariadb -uroot -e "FLUSH PRIVILEGES; ALTER USER 'root'@'localhost' IDENTIFIED BY '${NEW_PASSWORD}';"

# Stop recovery server
echo "Stopping recovery server"
docker stop "$RECOVERY_CONTAINER_ID" >/dev/null
trap - EXIT

# Restart normal DB
echo "Starting $CONTAINER_NAME"
docker compose -f "$ENGINE_DIR/docker-compose.yml" up -d

# Persist password to .env
echo "Saving password to $ENV_FILE"
if grep -q "^${VAR_NAME}=" "$ENV_FILE" 2>/dev/null; then
  sed -i.bak "s/^${VAR_NAME}=.*/${VAR_NAME}=${NEW_PASSWORD}/" "$ENV_FILE"
else
  echo "" >> "$ENV_FILE"
  echo "${VAR_NAME}=${NEW_PASSWORD}" >> "$ENV_FILE"
fi

echo
echo "Root password:"
echo "$NEW_PASSWORD"