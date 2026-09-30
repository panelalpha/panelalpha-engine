#!/bin/bash
set -e
cd ~/project

# Runs before detection, which is the only moment either of these two jobs can
# be done: RuntimeSidecars reads the checkout's compose files right after the
# strategy is chosen, and ProjectEnvironment/EnvSidecars read .env a moment
# later. {@see AppConfigBootstrap} -- "everything the project's app config does
# to the checkout, before anything is detected".

# 1. The two workstation compose files, moved out of the root.
#
#    S-Cart ships a laptop stack: an `app` php-fpm built from docker/php, an
#    nginx `webserver` publishing 8000:80 and talking FastCGI to app:9000, a
#    `queue` and a `scheduler` on the same image, a profile-gated `mysql-local`,
#    and a `node` running `npm install && vite --host` as a permanent process.
#
#    App, queue and scheduler are recognised as the application and dropped.
#    `webserver` and `node` are not, so they were kept as runtime sidecars and
#    merged into the generated compose. Measured on the deploy that produced the
#    `serving-unknown` verdict this recipe fixes: the containers that came up
#    were `scart-nginx` and `scart-node`, the port probe on 8000 answered
#    `Recv failure: Connection reset by peer` -- nginx, alive, proxying FastCGI
#    to an `app:9000` that does not exist in the generated stack, where the
#    application is Apache on 8000 in the shared PHP base image -- and the
#    domain answered 502. `node` meanwhile would run `npm install` at container
#    start and hold a Vite dev server open, on an account capped at 1800 MB.
#
#    Both files have to go, not just the first. The primary scan looks at
#    docker-compose.yml; with that gone the fallback scan globs
#    `docker-compose.*.yml` in the root, which finds docker-compose.prod.yml --
#    the same six services again. The glob does not descend, so `docker/` is far
#    enough. Moved rather than deleted: it is the customer's repository and the
#    file is how they run it on their own machine.
mkdir -p docker
for f in docker-compose.yml docker-compose.prod.yml; do
    if [ -f "$f" ]; then
        mv "$f" "docker/${f%.yml}.workstation.yml"
        echo "[s-cart] moved $f out of the project root (workstation stack, not this deploy's)"
    fi
done

# 2. .env, written before anything reads the checkout.
#
#    The secrets are generated once into ~/.panelalpha/scart and reused:
#    ~/project, .env included, is emptied on every deploy, while the MariaDB
#    volume keeps the password it was created with and APP_KEY /
#    GP247_ENCRYPTION_KEY decrypt the sessions and encrypted columns.
STORE="${HOME}/.panelalpha/scart"
# uploads/ is bind-mounted over storage/app/public by the compose override;
# created here so it is the account's, not root's.
mkdir -p "${STORE}/uploads"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -s "${STORE}/secrets.env" ]; then
    # base64:<32 raw bytes> is the only shape Laravel's Encrypter accepts for
    # config/app.php's AES-256-CBC.
    #
    # Generated here, on the host, and NOT by `artisan key:generate` in the
    # install stage -- by then it is too late. The generated service loads .env
    # through `env_file:`, and Compose reads it when the container is
    # *created*: an `APP_KEY=` line makes APP_KEY a real, empty environment
    # variable, and Laravel's Dotenv is immutable, so it never overwrites one.
    # Measured on Winter CMS: key:generate wrote a good key into .env and every
    # request still answered MissingAppKeyException, with `printenv APP_KEY`
    # printing an empty line and the file printing the key. This recipe states
    # the whole manifest rather than `extends: laravel` precisely so that the
    # platform's `key-generate` command is not inherited.
    #
    # GP247_ENCRYPTION_KEY is GP247's own key for data at rest -- SMTP
    # passwords, OAuth secrets, licences. Upstream ships it empty and
    # `gp247:doctor` warns: with no value those columns fall back to APP_KEY.
    #
    # The password is hex, so there is no character in it that docker compose
    # interpolates out of .env, and none that needs quoting in a URL or a shell.
    (
        umask 077
        {
            printf 'SCART_APP_KEY=base64:%s\n' "$(openssl rand -base64 32)"
            printf 'SCART_GP247_KEY=base64:%s\n' "$(openssl rand -base64 32)"
            printf 'SCART_DB_PASSWORD=%s\n' "$(openssl rand -hex 16)"
        } > "${STORE}/secrets.env.tmp"
        mv "${STORE}/secrets.env.tmp" "${STORE}/secrets.env"
    )
fi
chmod 600 "${STORE}/secrets.env"

#    Guarded on the file existing: a deploy that did not empty ~/project keeps
#    an operator's own edits. ProjectEnvironment copies .env.example to .env
#    only when there is no .env, so writing one here is not a race with it.
if [ ! -f .env ]; then
    SCART_APP_KEY=$(sed -n 's/^SCART_APP_KEY=//p' "${STORE}/secrets.env")
    SCART_GP247_KEY=$(sed -n 's/^SCART_GP247_KEY=//p' "${STORE}/secrets.env")
    SCART_DB_PASSWORD=$(sed -n 's/^SCART_DB_PASSWORD=//p' "${STORE}/secrets.env")

    sed -e 's|^APP_ENV=.*|APP_ENV=production|' \
        -e 's|^APP_DEBUG=.*|APP_DEBUG=false|' \
        -e "s|^APP_KEY=.*|APP_KEY=${SCART_APP_KEY}|" \
        -e "s|^GP247_ENCRYPTION_KEY=.*|GP247_ENCRYPTION_KEY=${SCART_GP247_KEY}|" \
        -e 's|^LOG_LEVEL=.*|LOG_LEVEL=error|' \
        -e 's|^DB_CONNECTION=.*|DB_CONNECTION=mysql|' \
        -e 's|^DB_HOST=.*|DB_HOST=127.0.0.1|' \
        -e 's|^DB_DATABASE=.*|DB_DATABASE=scart|' \
        -e 's|^DB_USERNAME=.*|DB_USERNAME=scart|' \
        -e "s|^DB_PASSWORD=.*|DB_PASSWORD=${SCART_DB_PASSWORD}|" \
        -e 's|^COMPOSE_PROFILES=.*|COMPOSE_PROFILES=|' \
        .env.example > .env
    unset SCART_APP_KEY SCART_GP247_KEY SCART_DB_PASSWORD

    # `DB_HOST=127.0.0.1` above is what asks for the database, and it has to be
    # a *local* address to do it: EnvSidecars reads .env.example and then .env,
    # and turns DB_CONNECTION=mysql into a mariadb:11 companion only when
    # DB_HOST names a local host. Upstream's `mysql-local` is not one -- it is
    # the name of the service in the compose file that was just moved aside --
    # so left alone this deploy would have had a Laravel app configured for
    # MySQL and no MySQL anywhere. The sidecar's own MYSQL_USER, MYSQL_PASSWORD
    # and MYSQL_DATABASE are taken from these same three lines, and the app's
    # real DB_HOST is then written into the generated `environment:` as the
    # sidecar's service name.
    #
    # SQLite is not an option here, and that was measured rather than assumed:
    # config/database.php takes DB_DATABASE verbatim, `gp247:install` migrates
    # and seeds onto SQLite without complaint, and the storefront serves -- but
    # the admin dashboard, the page every admin login lands on, is
    # GP247\Shop\Admin\Models\AdminOrder's statistics, four raw queries built
    # out of DATE_FORMAT(), DATE_SUB() and CURRENT_DATE():
    #
    #   SQLSTATE[HY000]: General error: 1 near "(": syntax error
    #   (View: vendor/gp247/shop/src/Views/admin/component/order_month.blade.php)
    #
    # HTTP 500 on /gp247_admin for every administrator, against a storefront
    # that looks fine. On MariaDB the same page is a 200. A shop is not worth
    # 512 MB less if the half of it the owner uses does not load.
    #
    # COMPOSE_PROFILES is emptied for the same reason the compose files moved:
    # it is a docker compose built-in, read out of this very .env by the CLI,
    # and it named a profile (`db-local`) in a file that is no longer here.
    #
    # 644, the mode ProjectEnvironment gives the .env it writes itself. Not 600:
    # EnvSidecars reads this file from the engine's own process, as a different
    # user, and a .env it cannot open is a .env with no database in it.
    chmod 644 .env
    echo "[s-cart] wrote .env: production, APP_KEY, GP247_ENCRYPTION_KEY and the MySQL password from ~/.panelalpha/scart"
fi
