#!/bin/bash
# Command of the app service, run by the image's /opt/glpi/entrypoint.sh after
# it has installed or updated the database, then execs the image's own command
# (supervisord). A new GLPI database has four built-in accounts with published
# passwords: glpi/glpi (Super-Admin), tech/tech, normal/normal and
# post-only/postonly. While glpi still has its default password it gets the
# engine's username and password; tech, normal and post-only are disabled while
# they still have theirs. An account whose password was changed in GLPI is
# never touched.
cd /var/www/glpi
if [ -n "${GLPI_ADMIN_PASSWORD:-}" ]; then
    NAME="${GLPI_ADMIN_USER:-glpi}"
    for user in $(php /panelalpha/glpi-default-logins.php); do
        if [ "$user" = glpi ]; then
            # GLPI's own password reset, then the login name.
            if bin/console user:reset_password glpi --password="$GLPI_ADMIN_PASSWORD" --no-interaction --quiet \
                && php /panelalpha/glpi-default-logins.php rename glpi "$NAME"; then
                echo "[panelalpha] glpi: the Super-Admin's login is the one the engine generated"
            else
                echo "[panelalpha] glpi: could not set the Super-Admin's login" >&2
            fi
        else
            bin/console user:disable "$user" --no-interaction --quiet \
                && echo "[panelalpha] glpi: disabled the built-in account $user (default password)" \
                || echo "[panelalpha] glpi: could not disable the built-in account $user" >&2
        fi
    done
fi
exec "$@"
