#!/bin/sh
# WP-CLI's database commands run mysql, mysqldump and mysqlcheck. The MariaDB
# 11.4+ client in wordpress:cli verifies the server's TLS certificate by
# default, and MySQL 8 presents a self-signed one, so every call fails with
# ERROR 2026. WordPress itself does not verify it either. WP-CLI passes
# --no-defaults, which must stay the first option, and runs a probe of its own
# that drops extra options, so the flag is added here rather than in WP-CLI.
# A dump skips tablespaces: MySQL 8 lists them only to a user with PROCESS,
# which the wordpress user does not have, and WordPress has none.
extra=--skip-ssl-verify-server-cert
case $(basename "$0") in
    mysqldump) client=mariadb-dump extra="$extra --no-tablespaces" ;;
    mysqlcheck) client=mariadb-check ;;
    *) client=mariadb ;;
esac
case ${1:-} in
    --no-defaults | --defaults-file=* | --defaults-extra-file=*)
        first=$1
        shift
        exec "/usr/bin/$client" "$first" $extra "$@"
        ;;
esac
exec "/usr/bin/$client" $extra "$@"
