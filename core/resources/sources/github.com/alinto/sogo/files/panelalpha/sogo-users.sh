#!/bin/bash
# The user directory is a SQL table, so user management is SQL. Called by
# overrides/app.sh through `docker compose exec`, and usable by hand.
set -euo pipefail

DB_HOST="${SOGO_DB_HOST:-db}"
DB_PORT="${SOGO_DB_PORT:-3306}"
DB_NAME="${MARIADB_DATABASE:-sogo}"
DB_USER="${MARIADB_USER:-sogo}"
DB_PASS="${MARIADB_PASSWORD:-}"

q() { mariadb --protocol=TCP -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" "$@"; }
esc() { printf %s "$1" | sed "s/'/''/g"; }

case "${1:-}" in
  list)
    q -N -B -e "SELECT c_uid, COALESCE(mail,''), COALESCE(c_cn,'') FROM sogo_users ORDER BY c_uid"
    ;;
  add)   # add <uid> <email> <password> [cn]
    uid=$(esc "$2"); mail=$(esc "$3"); cn=$(esc "${5:-$2}")
    hash=$(openssl passwd -6 "$4")
    q -e "INSERT INTO sogo_users (c_uid, c_name, c_password, c_cn, mail)
          VALUES ('$uid','$uid','$hash','$cn','$mail')"
    echo "$2"
    ;;
  passwd)  # passwd <uid> <password>
    uid=$(esc "$2"); hash=$(openssl passwd -6 "$3")
    q -e "UPDATE sogo_users SET c_password='$hash' WHERE c_uid='$uid'"
    ;;
  delete)  # delete <uid>  -- the directory row only; sogo-tool remove clears the data
    uid=$(esc "$2")
    /usr/local/sbin/sogo-tool remove "$2" >/dev/null 2>&1 || true
    q -e "DELETE FROM sogo_users WHERE c_uid='$uid'"
    ;;
  *)
    echo "usage: sogo-users list|add|passwd|delete ..." >&2; exit 2
    ;;
esac
