#!/bin/bash
# Entrypoint of the db service. MySQL reads MYSQL_PASSWORD and
# MYSQL_ROOT_PASSWORD only when it initialises an empty datadir, so a datadir
# made earlier keeps the passwords it was created with, while the stack is
# handed whatever ~/.panelalpha/wordpress/db.env holds now. On an existing
# datadir this starts mysqld with an init file that sets the database, the
# wordpress user and root to the stored values, so a password file written
# again (an upgrade from a release that kept it in the checkout, a lost file,
# a push that brought another project's) cannot lock the site out of its own
# database. An empty datadir is initialised by the image as before.
set -euo pipefail

sql_string() {
    local value=${1//\\/\\\\}
    printf "'%s'" "${value//\'/\'\'}"
}

sql_identifier() {
    if [[ ! $1 =~ ^[A-Za-z0-9_]+$ ]]; then
        echo "Refusing database name '$1': letters, digits and _ only." >&2
        return 1
    fi
    printf '`%s`' "$1"
}

if [ "${1:-}" = mysqld ] && [ -d /var/lib/mysql/mysql ]; then
    database=$(sql_identifier "${MYSQL_DATABASE}")
    user="$(sql_string "${MYSQL_USER}")@'%'"
    password=$(sql_string "${MYSQL_PASSWORD}")
    root_password=$(sql_string "${MYSQL_ROOT_PASSWORD}")
    rm -f /tmp/panelalpha-db-auth.*
    init_file=$(mktemp /tmp/panelalpha-db-auth.XXXXXX)
    cat > "${init_file}" <<SQL
CREATE DATABASE IF NOT EXISTS ${database};
CREATE USER IF NOT EXISTS ${user} IDENTIFIED WITH mysql_native_password BY ${password};
ALTER USER ${user} IDENTIFIED WITH mysql_native_password BY ${password};
GRANT ALL PRIVILEGES ON ${database}.* TO ${user};
ALTER USER IF EXISTS 'root'@'localhost' IDENTIFIED BY ${root_password};
ALTER USER IF EXISTS 'root'@'%' IDENTIFIED BY ${root_password};
SQL
    chown mysql:mysql "${init_file}"
    set -- "$@" "--init-file=${init_file}"
fi

exec docker-entrypoint.sh "$@"
