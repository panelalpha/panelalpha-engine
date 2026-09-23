#!/usr/bin/env bash

export DEBIAN_FRONTEND=noninteractive
export NEEDRESTART_MODE=a
default_color='\e[39m'
red_color='\e[31m'
green_color='\e[32m'
yellow_color='\e[33m'

die() {
    exit_code=$?
    if [[ ${exit_code} -ne 0 && (${exit_code} -lt 100 || ${exit_code} -gt 125) ]]; then
        echo -e "${yellow_color}${BASH_COMMAND} ${red_color}command failed with exit code ${yellow_color}${exit_code}${default_color}"
    fi
    send_update_status "$exit_code" || true
}

set -e
trap 'last_command=$current_command; current_command=$BASH_COMMAND' DEBUG
trap die EXIT

# Same as installer.sh: a caller's umask 077 would leave the tree root-only.
umask 022

PANELALPHA_ENGINE_VERSION='master'
PACKAGE_HOST='connect.panelalpha.com'
MONITORING_HOST="${PANELALPHA_MONITORING_HOST:-monitoring.panelalpha.com}"
BACKGROUND=0
DEBUG_MODE=0
FORCE_MODE=false
RUN_DIR=''
CONFIGURE_MODE=0

while [[ $# -gt 0 ]]; do
    case "$1" in
    --version=*)
        PANELALPHA_ENGINE_VERSION="${1#*=}"
        shift
        ;;
    --version)
        PANELALPHA_ENGINE_VERSION="$2"
        shift 2
        ;;
    --debug)
        DEBUG_MODE=1
        shift
        ;;
    --force)
        FORCE_MODE=true
        shift
        ;;
    --package-host=*)
        PACKAGE_HOST="${1#*=}"
        shift
        ;;
    --package-host)
        PACKAGE_HOST="$2"
        shift 2
        ;;
    --monitoring-host=*)
        MONITORING_HOST="${1#*=}"
        shift
        ;;
    --monitoring-host)
        MONITORING_HOST="$2"
        shift 2
        ;;
    --background)
        BACKGROUND=1
        shift
        ;;
    --configure)
        CONFIGURE_MODE=1
        shift
        ;;
    --run-dir=*)
        RUN_DIR="${1#*=}"
        shift
        ;;
    --run-dir)
        RUN_DIR="$2"
        shift 2
        ;;
    *)
        shift
        ;;
    esac
done

echo_info() { echo -e ">>> $green_color$1$default_color"; }
echo_warning() { echo -e ">>> $yellow_color$1$default_color"; }
echo_error() {
    echo -e ">>> $red_color$1$default_color"
    exit $2
}

update_progress() {
    if [ -n "$RUN_DIR" ] && [ -d "$RUN_DIR" ]; then
        echo "$1" >"$RUN_DIR/progress"
        echo "$2" >"$RUN_DIR/step"
    fi
}

log_file() {
    while IFS= read -r line; do
        if [ "$DEBUG_MODE" = 1 ]; then
            echo "$line"
        fi

        echo "$(date) $line" >>$LOG_FILE
    done
}

log_file_echo() {
    while IFS= read -r line; do
        echo $line
        echo "$(date) $line" >>$LOG_FILE
    done
}

define_variables() {
    LOG_DIR="/opt/panelalpha/log"
    mkdir -p $LOG_DIR
    LOG_FILE="${LOG_DIR}/engine-updater_$(date +"%Y-%m-%d_%H-%M-%S").log"
    ORIGINAL_USERNAME=$(whoami)
    ORIGINAL_HOME_DIR=$(getent passwd "$ORIGINAL_USERNAME" | cut -d: -f6)
    DOWNLOAD_STATUS=''
    TOKEN=''
    ERROR=''
    MSG=''
    PANELALPHA_DIR="/opt/panelalpha"
    PACKAGE_URL="https://${PACKAGE_HOST}/api/engine/download/zip/"
    INSTALL_DIR='/opt/panelalpha/tmp/engine'
    STARTED_AT=$(date +%s)
}

check_version() {
    if [ ! -f /opt/panelalpha/shared-hosting/docker-compose.yml ]; then
        echo_error "Cannot detect PanelAlpha Engine. It is not installed on this server or the installation is broken." 100
    fi

    SYSTEM_VERSION=$(docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T core php artisan system:version 2>/dev/null | tr -d '\r\n')
    if [[ "$BACKGROUND" -eq 1 && ( -z "$SYSTEM_VERSION" || "$SYSTEM_VERSION" == "unknown" ) ]]; then
        echo_error "Cannot detect current version. Manual update required" 107
    fi
    REQUIRED_VERSION="1.0.13"
    if [[ "$BACKGROUND" -eq 1 && -n "$SYSTEM_VERSION" && "$SYSTEM_VERSION" != "unknown" && "$(printf '%s\n' "$SYSTEM_VERSION" "$REQUIRED_VERSION" | sort -V | head -n1)" != "$REQUIRED_VERSION" ]]; then
        echo_error "Current version ($SYSTEM_VERSION) not compatible with background updater. Manual update required" 108
    fi

    # shared-hosting/version is optional (legacy / package fingerprint). Prefer it for
    # from_version logging; otherwise fall back to artisan system:version.
    mkdir -p /opt/panelalpha/log/engine-updates/latest
    if [ -f /opt/panelalpha/shared-hosting/version ]; then
        cp /opt/panelalpha/shared-hosting/version /opt/panelalpha/log/engine-updates/latest/from_version || true
    elif [ -n "$SYSTEM_VERSION" ]; then
        printf '%s\n' "$SYSTEM_VERSION" >/opt/panelalpha/log/engine-updates/latest/from_version || true
    else
        : >/opt/panelalpha/log/engine-updates/latest/from_version || true
    fi
    (echo "$PANELALPHA_ENGINE_VERSION" >/opt/panelalpha/log/engine-updates/latest/to_version || true)

    if [ "$FORCE_MODE" = true ]; then
        return
    fi
}

request_download_token() {
    # Connect resolves the caller by IP and returns a short-lived download token.
    CURL_RESULTS=$(curl --http1.1 -H "X-Engine-App-UID: ${APP_UID}" \
        "https://${PACKAGE_HOST}/api/verify/request-download")

    TOKEN=$(echo "$CURL_RESULTS" | jq -r '.["license"].download_token // empty')
    DOWNLOAD_STATUS=$(echo "$CURL_RESULTS" | jq -r '.["license"].status // empty')
    ERROR=$(echo "$CURL_RESULTS" | jq -r '.error // empty')
    MSG=$(echo "$CURL_RESULTS" | jq -r '.msg // empty')

    if [ "$ERROR" == true ]; then
        echo_error "Could not obtain a download token. $MSG" 102
    fi

    if [ "$DOWNLOAD_STATUS" != "Reissued" ] && [ "$DOWNLOAD_STATUS" != "Created" ] && [ "$DOWNLOAD_STATUS" != "Active" ]; then
        echo_error "Invalid download status: $DOWNLOAD_STATUS." 103
    fi
}

download_panelalpha_engine() {
    echo_warning "Please wait, package is downloading..."
    mkdir -p $INSTALL_DIR
    PACKAGE_URL+=$PANELALPHA_ENGINE_VERSION

    while true; do
        STATUS=$(cd ''$INSTALL_DIR'' && curl --http1.1 -o app.zip -w '%{http_code}' ''$PACKAGE_URL'' --header 'Download-Token:'$TOKEN'' --header "X-Engine-App-UID: ${APP_UID}")
        if [ $STATUS -eq 200 ]; then
            echo_info "Success! Package has been downloaded"
            break
        else
            PACKAGE_PATH="$INSTALL_DIR"/app.zip
            CURL_PACKAGE_RESULTS=$(cat "$PACKAGE_PATH")

            STATUS=$(echo $CURL_PACKAGE_RESULTS | jq '.status' --raw-output)
            MESSAGE=$(echo $CURL_PACKAGE_RESULTS | jq '.message' --raw-output)

            if [ $STATUS == 'error' ]; then
                echo_error "Error has been occurred. $MESSAGE" 104
            fi
        fi
        sleep 5
    done

    if [ ! -f "$INSTALL_DIR"/app.zip ]; then
        echo_error "Error has been occurred. Cannot find package. It should be in path: $INSTALL_DIR/app.zip" 105
    fi
}

unzip_panelalpha_engine() {
    echo_info "Unpacking engine files to $INSTALL_DIR/app"
    unzip -o "$INSTALL_DIR"/app.zip -d "$INSTALL_DIR/app" | log_file
}

# Every artisan call talks to the core database, and it is not always ready when
# we ask: the core container is still starting up right after 'up -d', and any
# Docker restart takes the whole stack down with it. Bounded, so a stack that
# never comes up fails the run instead of hanging on it forever.
wait_for_database() {
    local timeout=${1:-600}
    local waited=0
    while ! docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T core php artisan system:database:test 2>/dev/null | grep -q "Test successful"; do
        if [ "$waited" -ge "$timeout" ]; then
            echo_error "The core database did not become reachable within ${timeout}s" 106
        fi
        echo "Waiting for the core database..."
        sleep 5
        waited=$((waited + 5))
    done
}

# Runs while the stack is down, on purpose: csf.sh rebuilds the whole iptables
# ruleset, which drops the chains the Docker daemon installs at start, so the
# daemon has to be restarted afterwards. With the stack up that restart takes
# every container with it, and the artisan calls that follow hit a database
# that is still coming back.
harden_host() {
    echo_info "Hardening the host (sysctl, monit, CSF)"
    bash /opt/panelalpha/shared-hosting/scripts/configure-sysctl.sh
    bash /opt/panelalpha/shared-hosting/scripts/configure-monit.sh
    bash /opt/panelalpha/shared-hosting/scripts/csf.sh --install
    service docker restart
}

set_default_ip() {
    wait_for_database
    if docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T core php artisan settings:exists default_ipv4; then
        echo "Default IPv4 already set"
    else
        IPV4=$(ip route get 8.8.8.8 | sed -n '/src/{s/.*src *\([^ ]*\).*/\1/p;q}')
        if ipcalc $IPV4 | grep -q 'Private Internet'; then
            IPV4=$(curl -s4 icanhazip.com)
        fi
        docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T core php artisan settings:set default_ipv4 "${IPV4}"
    fi

    if docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T core php artisan settings:exists default_ipv6; then
        echo "Default IPv6 already set"
    else
        IPV6=$(ip route get to 2001:db8:: 2>/dev/null | grep -m 1 -o 'src [0-9a-f:]*' | cut -d ' ' -f 2)
        docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T core php artisan settings:set default_ipv6 "${IPV6}"
    fi
}

prepare_webserver_compose_file() {
    DEFAULT_COMPOSE=/opt/panelalpha/shared-hosting/docker-compose.yml-nginx-proxy
    TARGET=/opt/panelalpha/shared-hosting/docker-compose.yml-webserver
    # If TARGET is a regular file (not a symlink), replace it
    if [ -f "$TARGET" ] && [ ! -L "$TARGET" ]; then
        rm -f "$TARGET"
        ln -sfn "$DEFAULT_COMPOSE" "$TARGET"
    fi
    # If it doesn’t exist at all, create fresh symlink
    if [ ! -e "$TARGET" ]; then
        ln -sfn "$DEFAULT_COMPOSE" "$TARGET"
    fi
}

prepare_config_files() {
    # copy config files from templates if not exist
    mkdir -p /opt/panelalpha/shared-hosting/webserver-config
    cp -Rn /opt/panelalpha/shared-hosting/templates/webserver-config/. /opt/panelalpha/shared-hosting/webserver-config/.
    mkdir -p /opt/panelalpha/shared-hosting/webserver-config/litespeed-admin
    mkdir -p /opt/panelalpha/shared-hosting/webserver-config/openlitespeed-admin
    mkdir -p /opt/panelalpha/shared-hosting/webserver-config/apache/vhosts
    mkdir -p /opt/panelalpha/shared-hosting/webserver-config/nginx/vhosts
    mkdir -p /opt/panelalpha/shared-hosting/webserver-config/nginx-proxy/vhosts
    mkdir -p /opt/panelalpha/shared-hosting/logs/litespeed
    mkdir -p /opt/panelalpha/shared-hosting/webserver-logs/litespeed
    mkdir -p /opt/panelalpha/shared-hosting/webserver-logs/openlitespeed
    mkdir -p /opt/panelalpha/shared-hosting/webserver-logs/apache
    mkdir -p /opt/panelalpha/shared-hosting/webserver-logs/nginx
    mkdir -p /opt/panelalpha/shared-hosting/webserver-logs/nginx-proxy
    prepare_webserver_compose_file
    mkdir -p /opt/panelalpha/shared-hosting/config/pure-ftpd
    cp -R /opt/panelalpha/shared-hosting/templates/config/pure-ftpd/. /opt/panelalpha/shared-hosting/config/pure-ftpd/.
    chmod +x /opt/panelalpha/shared-hosting/config/pure-ftpd/entrypoint.sh
    mkdir -p /opt/panelalpha/shared-hosting/config/sftp
    cp -Rn /opt/panelalpha/shared-hosting/templates/config/sftp/. /opt/panelalpha/shared-hosting/config/sftp/.
    if [ ! -f /opt/panelalpha/shared-hosting/config/sftp/ssh_host_ed25519_key ]; then
        ssh-keygen -t ed25519 -N "" -f /opt/panelalpha/shared-hosting/config/sftp/ssh_host_ed25519_key < /dev/null
    fi
    if [ ! -f /opt/panelalpha/shared-hosting/config/sftp/ssh_host_rsa_key ]; then
        ssh-keygen -t rsa -b 4096 -N "" -f /opt/panelalpha/shared-hosting/config/sftp/ssh_host_rsa_key < /dev/null
    fi
    mkdir -p /opt/panelalpha/shared-hosting/config/logrotate
    cp -R /opt/panelalpha/shared-hosting/templates/config/logrotate/. /opt/panelalpha/shared-hosting/config/logrotate/.
    mkdir -p /opt/panelalpha/shared-hosting/config/exim
    mkdir -p /opt/panelalpha/shared-hosting/logs/exim
    cp -Rn /opt/panelalpha/shared-hosting/templates/config/exim/. /opt/panelalpha/shared-hosting/config/exim/.
    mkdir -p /opt/panelalpha/shared-hosting/config/modsecurity
    mkdir -p /opt/panelalpha/shared-hosting/logs/modsecurity
    cp -Rn /opt/panelalpha/shared-hosting/templates/config/modsecurity/. /opt/panelalpha/shared-hosting/config/modsecurity/.
}

disable_systemd_resolved() {
    if systemctl list-unit-files systemd-resolved.service >/dev/null 2>&1; then
        if systemctl is-enabled systemd-resolved >/dev/null 2>&1; then
            echo "Disabling systemd-resolved (DNS proxy requires port 53)..."
            systemctl stop systemd-resolved || true
            systemctl disable systemd-resolved || true
            systemctl mask systemd-resolved || true
        fi
        if [ -L /etc/resolv.conf ] || grep -q 127.0.0.53 /etc/resolv.conf 2>/dev/null; then
            cp -a /etc/resolv.conf /etc/resolv.conf.backup
            rm -f /etc/resolv.conf
            cat >/etc/resolv.conf <<EOF
nameserver 1.1.1.1
nameserver 8.8.8.8
EOF
        fi
    fi
}

# A host still running core-db predates the sqlite migration and has real
# data in it; a fresh install never had core-db to begin with. Must run
# before cp -Rf replaces docker-compose.yml with the new release's copy,
# which is the last point the old file (and the service it did or didn't
# define) can still be read. Sets LEGACY_CORE_DB for the rest of
# update_files(), including backup_database().
detect_legacy_core_db() {
    if [ -f /opt/panelalpha/shared-hosting/docker-compose.yml ] &&
        grep -q '^  core-db:' /opt/panelalpha/shared-hosting/docker-compose.yml; then
        LEGACY_CORE_DB=1
    else
        LEGACY_CORE_DB=0
    fi
}

# Backfills what a MySQL-backed core needs: the CORE_DB_* overrides
# docker-compose.yml's core/metrics services read (config/database.php's
# mysql connection takes it from there), and the legacy-mysql-core profile
# so `up -d` actually manages core-db instead of leaving it running
# unmanaged under its old image. Two ways in: LEGACY_CORE_DB=1 (an existing
# core-db host, detected above) backfills the profile itself; an operator
# who has already put legacy-mysql-core in COMPOSE_PROFILES by hand (a
# fresh install choosing MySQL on purpose) only needs the connection vars --
# the profile is already exactly what they asked for. Runs after the
# COMPOSE_PROFILES=full backfill below so it only ever appends to an
# already-decided list -- never decides between 'full' and this host's own
# trimmed list itself. See docs/internal/core-db.md.
keep_legacy_core_db_on_mysql() {
    local profiles profile_wants_mysql=0
    profiles=$(grep '^COMPOSE_PROFILES=' /opt/panelalpha/shared-hosting/.env | cut -d '=' -f2-)
    case ",${profiles}," in
    *,legacy-mysql-core,*) profile_wants_mysql=1 ;;
    esac

    [ "$LEGACY_CORE_DB" = "1" ] || [ "$profile_wants_mysql" = "1" ] || return 0

    if ! grep -q '^CORE_DB_CONNECTION=' /opt/panelalpha/shared-hosting/.env; then
        echo_info "Core database is MySQL; backfilling CORE_DB_* in .env"
        if [ -n "$(tail -c1 /opt/panelalpha/shared-hosting/.env)" ]; then
            echo "" >>/opt/panelalpha/shared-hosting/.env
        fi
        cat >>/opt/panelalpha/shared-hosting/.env <<'EOF'
CORE_DB_CONNECTION=mysql
CORE_DB_HOST=database-core.shared-hosting.palocal
CORE_DB_DATABASE=core
CORE_DB_USERNAME=core
EOF
    fi

    # A LEGACY_CORE_DB=1 host already has a real CORE_MYSQL_PASSWORD from
    # its original install -- core-db is already running with it, so this
    # must never overwrite a non-empty value. Only a host switching to
    # legacy-mysql-core for the first time (no core-db provisioned yet)
    # needs one generated.
    if ! grep -q '^CORE_MYSQL_PASSWORD=.\+' /opt/panelalpha/shared-hosting/.env; then
        local core_mysql_password
        core_mysql_password=$(cat /dev/urandom | tr -dc 'a-zA-Z0-9' | fold -w 32 | head -n 1)
        if grep -q '^CORE_MYSQL_PASSWORD=' /opt/panelalpha/shared-hosting/.env; then
            sed -i "s/^CORE_MYSQL_PASSWORD=.*/CORE_MYSQL_PASSWORD=${core_mysql_password}/" /opt/panelalpha/shared-hosting/.env
        else
            if [ -n "$(tail -c1 /opt/panelalpha/shared-hosting/.env)" ]; then
                echo "" >>/opt/panelalpha/shared-hosting/.env
            fi
            echo "CORE_MYSQL_PASSWORD=${core_mysql_password}" >>/opt/panelalpha/shared-hosting/.env
        fi
    fi

    if [ "$profile_wants_mysql" = "0" ]; then
        if [ -z "$profiles" ]; then
            sed -i 's/^COMPOSE_PROFILES=.*/COMPOSE_PROFILES=legacy-mysql-core/' /opt/panelalpha/shared-hosting/.env
        else
            sed -i "s/^COMPOSE_PROFILES=.*/COMPOSE_PROFILES=${profiles},legacy-mysql-core/" /opt/panelalpha/shared-hosting/.env
        fi
    fi
}

update_files() {

    echo_info "Updating files..."
    detect_legacy_core_db

    # --preserve=mode repairs files a previous install left root-only.
    cp -Rf --preserve=mode /opt/panelalpha/tmp/engine/app/. /opt/panelalpha/shared-hosting/.

    if ! bash /opt/panelalpha/shared-hosting/scripts/docker-daemon-dns.sh --exists 8.8.8.8 8.8.4.4; then
      echo_info "Updating Docker DNS and restarting daemon..."
      bash /opt/panelalpha/shared-hosting/scripts/docker-daemon-dns.sh --add 8.8.8.8 8.8.4.4 >/dev/null || true
      service docker restart
    else
      echo_info "Docker DNS already set."
    fi

    bash /opt/panelalpha/shared-hosting/scripts/update-cloudflare-ips.sh || true

    prepare_config_files

    # make sure systemd-resolved is disabled / no conflicts with sites-dns
    disable_systemd_resolved || true

    # Optional services now sit behind compose profiles. An .env written before
    # profiles existed has no COMPOSE_PROFILES, and compose would then start the
    # control plane alone -- sites-http, mail, ftp and the rest would stay down
    # after this update. Backfill 'full', which is exactly the pre-profile stack.
    # An operator who has already trimmed the list keeps their value.
    if [ -f /opt/panelalpha/shared-hosting/.env ] &&
        ! grep -q '^COMPOSE_PROFILES=' /opt/panelalpha/shared-hosting/.env; then
        echo_info "Backfilling COMPOSE_PROFILES=full into .env"
        if [ -n "$(tail -c1 /opt/panelalpha/shared-hosting/.env)" ]; then
            echo "" >>/opt/panelalpha/shared-hosting/.env
        fi
        echo "COMPOSE_PROFILES=full" >>/opt/panelalpha/shared-hosting/.env
    fi

    keep_legacy_core_db_on_mysql

    # This also clears the containers left by the compose service rename (nginx ->
    # core-http, webserver -> sites-http, ...): their service key is gone from the
    # file, so compose sees them as project orphans and --remove-orphans drops them.
    # core-db is not among them for a host keep_legacy_core_db_on_mysql just
    # opted in above -- its profile makes it a defined-but-filtered service,
    # not an orphan, so down leaves it running untouched either way.
    docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml down --remove-orphans
    docker image prune -af || true
    docker builder prune -af || true
    harden_host
    docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml up -d
    # run database migrations
    wait_for_database
    backup_database
    record_pending_migrations
    docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T core php artisan migrate --force
    docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T core php artisan settings:set package_host "${PACKAGE_HOST}"
    docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T core php artisan settings:set package_version "${PANELALPHA_ENGINE_VERSION}"
    docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T core php artisan system:modsec:rebuild || true
    # ADR-0001: move every DinD account onto the reserved run-file layout
    # before the rebuild below regenerates anything from it. Idempotent and
    # never restarts a container, so it is safe to run on every update.
    docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T core php artisan users:migrate-engine-artifacts --all
    docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T core php artisan users:rebuild --all --wipe-vhosts-dir
    docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T core php artisan users:add-missing-www-domain-aliases --all
    docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T core php artisan users:fix-file-permissions --all
    restart_webserver_if_config_loads
}

# The rebuild above rewrote every vhost, and sites-http runs `restart: always`:
# a container started on a config nginx refuses exits and loops, taking every
# site on the host down with it, while the container that is up right now is
# still serving. Only nginx's own verdict holds the restart back -- a container
# that is down, crash-looping or running something other than nginx cannot give
# one, and those are the cases a restart is there to fix.
restart_webserver_if_config_loads() {
    local out
    out=$(docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T sites-http nginx -t 2>&1 || true)
    case "$out" in
    *"[emerg]"* | *"test failed"*)
        echo_warning "Not restarting the webserver: the rendered configuration does not pass nginx -t"
        echo_warning "$out"
        return 0
        ;;
    esac

    docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml restart sites-http
}

# Dump the core database before any migration touches it.
#
# `migrate --force` is the one step of an update that cannot be undone by
# putting the old files back: the tree is replaced by a copy, the containers
# are recreated from images, and only the schema keeps moving in one
# direction. A migration that fails halfway leaves a database that neither the
# old code nor the new one can read, and until this ran there was nothing to go
# back to.
#
# Never fatal. An update that has already replaced the files cannot be
# abandoned because a dump failed, and a warned-about missing backup is better
# than a refusal to finish -- but it is warned about, loudly, because the
# operator's next decision depends on whether it exists.
backup_database() {
    local target="/opt/panelalpha/backups"

    mkdir -p "$target"

    # LEGACY_CORE_DB is set by detect_legacy_core_db(), earlier in the same
    # update_files() call -- a host int-updater.sh kept on MySQL still has
    # its data in core-db, not in core.sqlite.
    if [ "$LEGACY_CORE_DB" = "1" ]; then
        local file="$target/core-db-$(date +%Y%m%d-%H%M%S).sql"
        # mysqldump is not in the image; the MariaDB client ships `mariadb-dump`,
        # and the old name fails to a 0-byte file that looks like a backup.
        if docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T core-db \
            bash -lc 'mariadb-dump -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" --single-transaction --routines "$MYSQL_DATABASE"' \
            >"$file" 2>/dev/null && [ -s "$file" ]; then
            echo_info "Database backed up to $file" | log_file_echo
            ls -1t "$target"/core-db-*.sql 2>/dev/null | tail -n +3 | xargs -r rm -f
        else
            rm -f "$file"
            echo_warning "Could not back up the database; continuing without one" | log_file_echo
        fi
        return
    fi

    local file="$target/core-db-$(date +%Y%m%d-%H%M%S).sqlite"
    # core's data is core.sqlite on core-storage. `.backup` runs inside the
    # core container so it snapshots the live WAL-mode file instead of
    # copying it mid-write, and core mounts /opt/panelalpha 1:1 with the
    # host, so it can write $file directly.
    if docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T core \
        sqlite3 /var/www/html/storage/database/core.sqlite ".backup '$file'" \
        2>/dev/null && [ -s "$file" ]; then
        echo_info "Database backed up to $file" | log_file_echo
        # Two is enough to cover "the last update broke it" without turning
        # /opt into an archive nobody prunes.
        ls -1t "$target"/core-db-*.sqlite 2>/dev/null | tail -n +3 | xargs -r rm -f
    else
        rm -f "$file"
        echo_warning "Could not back up the database; continuing without one" | log_file_echo
    fi
}

# Name the schema changes in the log before they are applied.
#
# An update that says only "migrating" leaves nobody able to answer "what
# changed" afterwards, which is the first question asked when an install starts
# 500ing on a column. The names cost one command and are the difference between
# a recoverable morning and a bisect.
record_pending_migrations() {
    local pending
    pending=$(docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T core \
        php artisan migrate:status 2>/dev/null | grep -i "Pending" || true)

    if [ -z "$pending" ]; then
        echo_info "No pending migrations" | log_file_echo
        return
    fi

    echo_info "Applying $(echo "$pending" | wc -l) pending migration(s):" | log_file_echo
    echo "$pending" | log_file_echo
}

clean_installer() {
    echo_info "Cleaning installation files"
    rm -rf "$INSTALL_DIR"
}

post_install_config() {
    # Hardening ran in update_files, while the stack was down.
    set_default_ip
    # Two lineages: the bare IP (short-lived, kept but not served) and the
    # served domain certificate -- the cert_domain setting, or the default
    # derived from the address. Neither touches the CA when it is not due,
    # so this is idempotent across updates; a failure keeps what is served.
    if ! ipcalc "$IPV4" | grep -q 'Private Internet'; then
        bash /opt/panelalpha/shared-hosting/scripts/letsencrypt-request-ip-cert.sh "$IPV4" || echo_warning "Could not obtain the Let's Encrypt IP certificate"
    fi
    bash /opt/panelalpha/shared-hosting/scripts/letsencrypt-request-cert.sh --ip "$IPV4" || echo_warning "Could not obtain the Let's Encrypt domain certificate; the served certificate is unchanged"
    apt-get install quota at -y
    bash /opt/panelalpha/shared-hosting/scripts/pae-command.sh register || echo_warning "Could not install the pae command"
    # Build the shared PHP base images now, in the background: without this the
    # ~150s per PHP minor is paid by whichever customer deploys that minor first.
    bash /opt/panelalpha/shared-hosting/scripts/prewarm-images.sh || echo_warning "Could not start image prewarm"
}

# APP_UID identifies this install to Connect and to monitoring (X-Engine-App-UID).
# A value already in .env-core wins, then one a provisioning script exported as
# APP_UID; only when neither exists is one generated. Never overwritten.
read_app_uid() {
    [ -f "$1" ] || return 0
    { grep '^APP_UID=' "$1" || true; } | tail -n1 | cut -d '=' -f2- | tr -d "\"' \r"
}

resolve_app_uid() {
    local existing
    existing=$(read_app_uid /opt/panelalpha/shared-hosting/.env-core)
    if [ -n "$existing" ]; then
        APP_UID="$existing"
    elif [ -z "${APP_UID:-}" ]; then
        APP_UID=$(cat /proc/sys/kernel/random/uuid)
    fi
    if ! [[ "$APP_UID" =~ ^[A-Za-z0-9._:-]{1,128}$ ]]; then
        # cleared first: the exit trap reports with this header
        local bad="$APP_UID"
        APP_UID=''
        echo_error "APP_UID must be 1-128 characters from A-Z a-z 0-9 . _ : - (got '${bad}')" 109
    fi
}

persist_app_uid() {
    local env_core=/opt/panelalpha/shared-hosting/.env-core
    [ -n "$(read_app_uid "$env_core")" ] && return 0
    if grep -q '^APP_UID=' "$env_core"; then
        sed -i "s|^APP_UID=.*|APP_UID=${APP_UID}|" "$env_core"
    else
        [ -z "$(tail -c1 "$env_core")" ] || echo "" >>"$env_core"
        echo "APP_UID=${APP_UID}" >>"$env_core"
    fi
}

send_update_status() {
    local exit_code=${1:-0}
    local finished_at started_at tail_stdout tail_stderr from_version to_version total_ram cpu_cores os_name virtualization disk_free current_webserver
    started_at=${STARTED_AT:-$(date +%s || echo 0)}
    finished_at=$(date +%s || echo 0)
    local LOGS_DIR="/opt/panelalpha/log/engine-updates/latest"
    from_version=$(cat "$LOGS_DIR/from_version" 2>/dev/null || echo "")
    to_version=$(cat "$LOGS_DIR/to_version" 2>/dev/null || echo "")
    tail_stdout=$( (tail -n 3 "$LOGS_DIR/stdout" 2>/dev/null || true) | base64 -w0 2>/dev/null || echo "")
    tail_stderr=$( (tail -n 3 "$LOGS_DIR/stderr" 2>/dev/null || true) | base64 -w0 2>/dev/null || echo "")
    total_ram=$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo 2>/dev/null || echo 0)
    cpu_cores=$(nproc 2>/dev/null || echo 0)
    os_name=$(grep '^PRETTY_NAME=' /etc/os-release 2>/dev/null | cut -d= -f2 | tr -d '"' || echo unknown)
    virtualization=$(systemd-detect-virt 2>/dev/null || true)
    virtualization=${virtualization:-unknown}
    disk_free=$(df -Pm / 2>/dev/null | awk 'NR==2{print $4}' || echo 0)
    current_webserver=$(docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml ps -a sites-http --format json 2>/dev/null \
        | jq -r '.Labels // empty' 2>/dev/null \
        | tr ',' '\n' \
        | awk -F= '$1=="com.panelalpha.webserver"{print $2}' \
        | head -n1)
    current_webserver=${current_webserver:-unknown}
    {
        jq -n \
            --arg occurred_at "$(date -u +%Y-%m-%dT%H:%M:%SZ 2>/dev/null || echo "")" \
            --arg started_at "$started_at" \
            --arg finished_at "$finished_at" \
            --arg exit_code "$exit_code" \
            --arg tail_stdout "$tail_stdout" \
            --arg tail_stderr "$tail_stderr" \
            --arg from_version "$from_version" \
            --arg to_version "$to_version" \
            --arg background "${BACKGROUND:-0}" \
            --arg total_ram "$total_ram" \
            --arg cpu_cores "$cpu_cores" \
            --arg os_name "$os_name" \
            --arg virtualization "$virtualization" \
            --arg disk_free "$disk_free" \
            --arg webserver "$current_webserver" \
            --arg software "engine" \
            --arg software_op "update" \
            '{events:[{
              type:"panel.update",
              occurred_at:$occurred_at,
              payload:{
                started_at:$started_at,
                finished_at:$finished_at,
                exit_code:$exit_code,
                tail_stdout:$tail_stdout,
                tail_stderr:$tail_stderr,
                from_version:$from_version,
                to_version:$to_version,
                background:$background,
                total_ram:$total_ram,
                cpu_cores:$cpu_cores,
                os_name:$os_name,
                virtualization:$virtualization,
                disk_free:$disk_free,
                webserver:$webserver,
                software:$software,
                software_op:$software_op
              }
            }]}' \
        | curl -4 -sS -X POST "https://${MONITORING_HOST}/api/v1/events" \
            -H "Content-Type: application/json" \
            -H "Accept: application/json" \
            -H "User-Agent: PanelAlpha-Engine/updater" \
            ${APP_UID:+-H} ${APP_UID:+"X-Engine-App-UID: ${APP_UID}"} \
            -d @- >/dev/null 2>&1 || true
    } || true
}

finish_installation() {
    echo_info ""
    echo_info "PanelAlpha engine has been successfully updated!"
    echo_info ""
}

confirm_update() {
    echo_info "Detecting changes from current installation..."

    CURRENT_REVS=$(cat /opt/panelalpha/shared-hosting/version || echo "")
    NEW_REVS=$(cat /opt/panelalpha/tmp/engine/app/version)
    (cp /opt/panelalpha/tmp/engine/app/version /opt/panelalpha/log/engine-updates/latest/to_version || true)

    if [ "$CURRENT_REVS" = "$NEW_REVS" ]; then
        QUESTION="Current installation is up to date. Do you want to override with fresh files anyway? [y/n] "
    else
        QUESTION="Detected newer version. Do you want to proceed with the update? [y/n] "
    fi

    if [[ "$BACKGROUND" -eq 1 ]]; then
        update_files
        clean_installer
        post_install_config
        finish_installation
    else
        while true; do
            read -p "$QUESTION" YN
            case $YN in
            [Yy]*)
                update_files
                clean_installer
                post_install_config
                finish_installation
                break
                ;;
            [Nn]*)
                clean_installer
                echo_info "Update not performed."
                break
                ;;
            *) echo "Invalid response." ;;
            esac
        done
    fi
}

define_variables

if [ -n "$RUN_DIR" ] && [ -d "$RUN_DIR" ]; then
    touch "$RUN_DIR/step" "$RUN_DIR/progress"
fi

if [ "$CONFIGURE_MODE" = 1 ]; then
    trap - EXIT
    exit 0
fi

# When driven by the TUI wrapper we never prompt.
if [ -n "$RUN_DIR" ]; then
    BACKGROUND=1
fi

update_progress 5 "Checking current version"
echo_info "Checking current version"
check_version

# Written now, not with the other files: a run that stops before the restart
# must not mint a second UID next time. Core picks it up on that restart.
resolve_app_uid
persist_app_uid

update_progress 10 "Requesting the download token"
echo_info "Requesting the download token"
request_download_token

update_progress 25 "Installing sysbox runtime"
echo_info "Installing sysbox runtime"
bash /opt/panelalpha/shared-hosting/scripts/install-sysbox.sh

update_progress 40 "Download PanelAlpha engine package"
echo_info "Download PanelAlpha engine package"
download_panelalpha_engine

update_progress 55 "Unzip PanelAlpha engine package"
echo_info "Unzip PanelAlpha engine package"
unzip_panelalpha_engine

update_progress 70 "Applying update"
confirm_update

update_progress 100 "Finishing update"
