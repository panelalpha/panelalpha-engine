#!/bin/sh
# Runs the migrations, then creates the owner with upstream's own
# admin:user:create, feeding it the generated password. A no-op once the
# owner exists.
set -eu
cd /var/www/html

php artisan migrate --force

# secret() only reads a piped stdin when Symfony treats the input as interactive.
out=$(printf '%s\n' "${SOLIDTIME_ADMIN_PASSWORD}" | SHELL_INTERACTIVE=1 \
    php artisan admin:user:create "Admin" "${SOLIDTIME_ADMIN_EMAIL}" --ask-for-password --verify-email 2>&1) && rc=0 || rc=$?
echo "${out}"
if [ "${rc}" -ne 0 ]; then
    case "${out}" in
        *"already exists"*) echo "solidtime owner already exists" ;;
        *) exit "${rc}" ;;
    esac
fi
