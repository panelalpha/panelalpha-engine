#!/usr/bin/env bash
# Bring the engine up from an already-uploaded source tree, without the full
# installer. Does what install_panelalpha_engine() does — env files, config
# seeding, TLS, network, vendor, compose, migrations — and nothing else: no
# apt upgrade, no firewall, no monit, no sysctl, no Let's Encrypt, no telemetry.
#
# Idempotent: every step is guarded, so re-running it after another upload is
# the redeploy path.
#
#   bash scripts/bootstrap-from-source.sh [--ip ADDR] [--no-sysbox]
#                                         [--no-docker-install] [--composer]
#                                         [--services "core nginx ..."]
#                                         [--profiles "hosting,mail" | --core-only]
#                                         [--dind-runtime privileged] [--mtu 1400]
#                                         [--domain panel.example.com]
#                                         [--no-quota]

set -euo pipefail

ENGINE_DIR="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/.." && pwd)"
COMPOSER_IMAGE='ghcr.io/panelalpha/engine-composer:v2.0.1'

# The composer image, built from dockerfiles/Dockerfile-composer when no tag is
# published or the published one's PHP is older than core's composer.json asks
# for: `composer install` would then abort and leave no vendor/ at all.
resolve_composer_image() {
    local want image_php
    want=$(sed -n 's/.*"php"[[:space:]]*:[[:space:]]*"[^0-9]*\([0-9][0-9]*\.[0-9][0-9]*\).*/\1/p' "$1/composer.json" | head -1)

    docker image inspect "$COMPOSER_IMAGE" >/dev/null 2>&1 || docker pull "$COMPOSER_IMAGE" || true

    if [ -n "$want" ] && docker image inspect "$COMPOSER_IMAGE" >/dev/null 2>&1; then
        image_php=$(docker run --rm "$COMPOSER_IMAGE" php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;' 2>/dev/null || echo 0)
        if [ "$(lowest_version "$want" "$image_php")" != "$want" ]; then
            echo ">>> $COMPOSER_IMAGE ships PHP ${image_php}, core requires >= ${want} -- building from dockerfiles/Dockerfile-composer" >&2
            COMPOSER_IMAGE='panelalpha/engine-composer:local'
            docker build --tag "$COMPOSER_IMAGE" - <"$2/dockerfiles/Dockerfile-composer"
            return
        fi
    fi

    docker image inspect "$COMPOSER_IMAGE" >/dev/null 2>&1 ||
        docker build --tag "$COMPOSER_IMAGE" - <"$2/dockerfiles/Dockerfile-composer"
}

lowest_version() { printf '%s\n%s\n' "$1" "$2" | sort -V | head -1; }

PUBLIC_IP=''
CERT_DOMAIN=''
INSTALL_SYSBOX=1
INSTALL_DOCKER=1
FORCE_COMPOSER=0
KEEP_RESOLVED=0
HARDEN=1
QUOTA="${PANELALPHA_QUOTA:-1}"
SERVICES=''
PROFILES=''
SET_PROFILES=0
DIND_RUNTIME=''
DOCKER_NETWORK_MTU=1500

parse_args() {
    while [ $# -gt 0 ]; do
        case "$1" in
        --ip) PUBLIC_IP="$2"; shift 2 ;;
        --domain) CERT_DOMAIN="$2"; shift 2 ;;
        --no-sysbox) INSTALL_SYSBOX=0; shift ;;
        --no-docker-install) INSTALL_DOCKER=0; shift ;;
        --composer) FORCE_COMPOSER=1; shift ;;
        --keep-resolved) KEEP_RESOLVED=1; shift ;;
        --no-hardening) HARDEN=0; shift ;;
        --no-quota) QUOTA=0; shift ;;
        --services) SERVICES="$2"; shift 2 ;;
        --profiles) PROFILES="$2"; SET_PROFILES=1; shift 2 ;;
        --core-only) PROFILES=''; SET_PROFILES=1; shift ;;
        --dind-runtime) DIND_RUNTIME="$2"; shift 2 ;;
        --mtu) DOCKER_NETWORK_MTU="$2"; shift 2 ;;
        -h | --help) sed -n '2,15p' "$0"; exit 0 ;;
        *) echo "Unknown option: $1" >&2; exit 1 ;;
        esac
    done
}

green='\e[32m'; yellow='\e[33m'; plain='\e[39m'
step() { echo -e "${green}>>> $1${plain}"; }
warn() { echo -e "${yellow}>>> $1${plain}"; }

rand() { tr -dc 'a-zA-Z0-9' </dev/urandom | head -c "${1:-12}"; }

core_artisan() { docker compose exec -T core php artisan "$@"; }

# nftables as in installer.sh (its service stays disabled); `at` queues the
# engine's background webserver reloads.
install_missing_tools() {
    MISSING=()
    command -v openssl >/dev/null || MISSING+=(openssl)
    command -v ssh-keygen >/dev/null || MISSING+=(openssh-client)
    command -v curl >/dev/null || MISSING+=(curl)
    command -v rsync >/dev/null || MISSING+=(rsync)
    command -v ipcalc >/dev/null || MISSING+=(ipcalc)
    command -v nft >/dev/null || MISSING+=(nftables)
    command -v at >/dev/null || MISSING+=(at)
    [ "$QUOTA" != 1 ] || command -v setquota >/dev/null || MISSING+=(quota)
    if [ ${#MISSING[@]} -gt 0 ]; then
        step "Installing ${MISSING[*]}"
        DEBIAN_FRONTEND=noninteractive apt-get update -y
        DEBIAN_FRONTEND=noninteractive apt-get install -y "${MISSING[@]}"
    fi
}

install_docker() {
    command -v docker >/dev/null && return 0
    [ "$INSTALL_DOCKER" = 1 ] || { echo "Docker is not installed (and --no-docker-install was given)." >&2; exit 1; }
    step "Installing Docker engine"
    export DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a
    apt-get install -y ca-certificates curl gnupg
    mkdir -m 0755 -p /etc/apt/keyrings
    curl --proto '=https' --http1.1 -fsSL "https://download.docker.com/linux/${ID}/gpg" |
        gpg --dearmor -o /etc/apt/keyrings/docker.gpg --yes
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/${ID} ${VERSION_CODENAME:-} stable" \
        >/etc/apt/sources.list.d/docker.list
    apt-get update -y
    apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
}

resolve_public_ip() {
    if [ -z "$PUBLIC_IP" ]; then
        PUBLIC_IP=$(ip route get 8.8.8.8 | sed -n '/src/{s/.*src *\([^ ]*\).*/\1/p;q}')
    fi
    [ -n "$PUBLIC_IP" ] || { echo "Could not determine the host IP — pass --ip ADDR." >&2; exit 1; }
    step "Using host address ${PUBLIC_IP}"
}

check_prerequisites() {
    step "Checking prerequisites"
    . /etc/os-release
    case "${ID:-}" in
    debian | ubuntu) : ;;
    *) warn "Untested distribution '${ID:-unknown}' — the stack expects Debian 12/13 or Ubuntu 22.04/24.04" ;;
    esac
    install_missing_tools
    install_docker
    docker compose version >/dev/null 2>&1 || { echo "The docker compose plugin is missing." >&2; exit 1; }
    resolve_public_ip
}

# A line appended to <file> starts a line of its own.
append_env_line() { # <file> <line>
    [ -z "$(tail -c1 "$1")" ] || echo "" >>"$1"
    echo "$2" >>"$1"
}

# <key>=<value> in <file>, replacing the key's line or appending one; <delim> is
# the sed delimiter.
set_env_value() { # <file> <key> <value> <delim>
    if grep -q "^$2=" "$1"; then
        sed -i "s$4^$2=.*$4$2=$3$4" "$1"
    else
        append_env_line "$1" "$2=$3"
    fi
}

# APP_URL is appended when absent: Laravel would fall back to http://localhost,
# and the engine report its own certificate as domain_mismatch.
set_app_url() {
    grep -q '^APP_URL=.\+' .env-core ||
        set_env_value .env-core APP_URL "https://${PUBLIC_IP}:2011" '#'
}

# Before the stack starts: queue workers keep the APP_KEY they booted with.
set_app_key() {
    grep -q '^APP_KEY=.\+' .env-core && return 0
    APP_KEY="base64:$(head -c 32 /dev/urandom | base64 | tr -d '\n')"
    set_env_value .env-core APP_KEY "$APP_KEY" '|'
}

# APP_UID identifies this install to Connect and monitoring: kept once set, and
# an exported APP_UID is used instead of a generated one.
set_app_uid() {
    grep -q '^APP_UID=[^"'"'"' ]' .env-core && return 0
    APP_UID="${APP_UID:-$(cat /proc/sys/kernel/random/uuid)}"
    [[ "$APP_UID" =~ ^[A-Za-z0-9._:-]{1,128}$ ]] || { echo "APP_UID must be 1-128 of A-Z a-z 0-9 . _ : -" >&2; exit 1; }
    set_env_value .env-core APP_UID "$APP_UID" '|'
}

# Optional services sit behind compose profiles (.env.example lists them):
# --core-only leaves just core, and an untouched .env gets the whole stack.
set_compose_profiles() {
    if [ "$SET_PROFILES" = 1 ]; then
        set_env_value .env COMPOSE_PROFILES "$PROFILES" '#'
        step "Compose profiles: ${PROFILES:-<none> (control plane only)}"
    elif ! grep -q '^COMPOSE_PROFILES=' .env; then
        append_env_line .env "COMPOSE_PROFILES=full"
    fi
}

# How account containers are isolated: empty keeps the default (sysbox);
# 'privileged' is for an engine itself inside sysbox, which cannot nest.
set_dind_runtime() {
    [ -n "$DIND_RUNTIME" ] || return 0
    set_env_value .env-core DIND_RUNTIME "$DIND_RUNTIME" '#'
    step "Account containers will use DIND_RUNTIME=${DIND_RUNTIME}"
}

prepare_env_files() {
    step "Preparing .env and .env-core"
    cp -n .env.example .env
    cp -n .env-core.example .env-core
    bash scripts/secure-env-core.sh "$PWD/.env-core"
    grep -q '^USERS_MYSQL_ROOT_PASSWORD=.\+' .env ||
        sed -i "s/^USERS_MYSQL_ROOT_PASSWORD=.*/USERS_MYSQL_ROOT_PASSWORD=$(rand)/" .env
    set_app_url
    set_app_key
    set_app_uid
    set_compose_profiles
    set_dind_runtime
}

# The upload leaves out core/storage and core/bootstrap/cache, and composer's
# package:discover aborts without them. Owned by www-data (uid 33 in the core
# image), which runs artisan there, before the first `php artisan`.
create_storage_tree() {
    step "Creating the Laravel storage tree"
    mkdir -p core/storage/framework/{sessions,views,cache} core/storage/logs \
        core/bootstrap/cache
    chown -R 33:33 core/storage core/bootstrap/cache 2>/dev/null ||
        warn "Could not chown core/storage and core/bootstrap/cache to www-data"
}

# docker-compose.yml includes the gitignored docker-compose.yml-webserver, so
# compose cannot parse the project without it.
seed_compose_include() {
    cp -n docker-compose.yml-nginx-proxy docker-compose.yml-webserver
    bash scripts/refresh-webserver-image.sh "$PWD" || warn "Could not bring the webserver image tag up to date"
}

# Host state is copied without overwriting (-n); the scripts and logrotate
# files are engine code and always copied. pure-ftpd logs no failed login
# without a password database.
seed_config_dirs() {
    mkdir -p webserver-config/{apache,nginx,nginx-proxy}/vhosts \
        webserver-config/{litespeed,openlitespeed}-admin \
        webserver-logs/{apache,nginx,nginx-proxy,litespeed,openlitespeed} \
        logs/{litespeed,exim} \
        config/{pure-ftpd,sftp,logrotate,exim}
    cp -Rn templates/webserver-config/. webserver-config/
    cp -Rn templates/config/pure-ftpd/. config/pure-ftpd/
    chmod +x config/pure-ftpd/entrypoint.sh
    mkdir -p pureftpd
    touch pureftpd/pureftpd.passwd
    cp -Rn templates/config/sftp/. config/sftp/
    cp templates/config/sftp/{entrypoint.sh,sync-logins.sh} config/sftp/
    cp -Rn templates/config/logrotate/. config/logrotate/
    cp templates/config/logrotate/{apache,nginx,nginx-proxy,php}.conf config/logrotate/
    cp -Rn templates/config/exim/. config/exim/
    bash scripts/seed-modsecurity.sh "$PWD"
}

generate_sftp_host_keys() {
    [ -f config/sftp/ssh_host_ed25519_key ] ||
        ssh-keygen -t ed25519 -N '' -f config/sftp/ssh_host_ed25519_key </dev/null
    [ -f config/sftp/ssh_host_rsa_key ] ||
        ssh-keygen -t rsa -b 4096 -N '' -f config/sftp/ssh_host_rsa_key </dev/null
}

seed_config_files() {
    step "Seeding config files from templates/"
    seed_compose_include
    seed_config_dirs
    generate_sftp_host_keys
}

# The address goes in the SAN list, not only the CN: Node and Go reject a
# CN-only certificate.
generate_self_signed_cert() {
    [ ! -f crt/server.cert ] || return 0
    step "Generating a self-signed certificate for ${PUBLIC_IP}"
    mkdir -p crt
    openssl req -new -x509 -days 365 -nodes \
        -out crt/server.cert -keyout crt/server.key \
        -subj "/C=US/ST=ST/L=L/O=O/OU=Org/CN=${PUBLIC_IP}" \
        -addext "subjectAltName=IP:${PUBLIC_IP},IP:127.0.0.1,DNS:localhost"
}

# The Cloudflare list is nginx's trusted proxies (harmless offline);
# pash-tenants is the accounts' network, closed from boot until core binds it.
prepare_networks() {
    bash scripts/update-cloudflare-ips.sh || warn "Could not refresh the Cloudflare IP list"

    docker network inspect pash-default-network >/dev/null 2>&1 || {
        step "Creating pash-default-network"
        bash scripts/ensure-docker-network.sh "${DOCKER_NETWORK_MTU}"
    }
    DOCKER_NETWORK_MTU="${DOCKER_NETWORK_MTU}" bash scripts/tenant-network-firewall.sh --create --restart-docker \
        || warn "Could not create the tenant network"
    bash scripts/tenant-network-firewall.sh --install-units \
        || warn "Could not install the tenant network's boot units"
}

# The upload excludes core/vendor, so a redeploy that brings a new composer.lock
# must install again. The stamp records the lock the vendor tree was built from.
COMPOSER_LOCK_STAMP=core/vendor/.pa-composer-lock
composer_needed() {
    [ "$FORCE_COMPOSER" = 1 ] || [ ! -d core/vendor ] ||
        [ "$(cat "$COMPOSER_LOCK_STAMP" 2>/dev/null)" != "$(sha256sum core/composer.lock | cut -d' ' -f1)" ]
}

install_composer_dependencies() {
    composer_needed || return 0
    step "Installing composer dependencies"
    resolve_composer_image "${ENGINE_DIR}/core" "${ENGINE_DIR}"
    docker run --rm -v "${ENGINE_DIR}/core:/app" -w /app "$COMPOSER_IMAGE" composer install
    sha256sum core/composer.lock | cut -d' ' -f1 >"$COMPOSER_LOCK_STAMP"
}

dns_proxy_enabled() {
    grep -q '^COMPOSE_PROFILES=' .env &&
        grep -qE '^COMPOSE_PROFILES=(.*,)?(hosting|full)(,.*)?$' .env
}

# Whether /etc/resolv.conf leads to systemd-resolved: it names the stub, or is a
# link left dangling by stopping it, or one into /run/systemd/resolve/, which
# the next boot removes. Any other resolver an operator set up is left alone.
resolv_conf_uses_resolved() {
    grep -q '^[[:space:]]*nameserver[[:space:]]\+127\.0\.0\.53' /etc/resolv.conf 2>/dev/null ||
        { [ -L /etc/resolv.conf ] && [ ! -e /etc/resolv.conf ]; } ||
        { [ -L /etc/resolv.conf ] && readlink -f /etc/resolv.conf | grep -q '^/run/systemd/resolve/'; }
}

# With systemd-resolved stopped, a resolv.conf that leads to it resolves nothing,
# for the host and every container that inherited the file.
repoint_resolv_conf() {
    resolv_conf_uses_resolved || return 0
    step "Repointing /etc/resolv.conf away from the resolved stub"
    cp -a /etc/resolv.conf /etc/resolv.conf.backup
    rm -f /etc/resolv.conf
    printf 'nameserver 1.1.1.1\nnameserver 8.8.8.8\n' >/etc/resolv.conf
}

# Masked, not only stopped: socket activation could bring it back before `up`.
release_resolved_stub() {
    if [ "$KEEP_RESOLVED" = 1 ]; then
        warn "--keep-resolved given — leaving systemd-resolved on 127.0.0.54:53; dns-proxy will not start"
    elif ! systemctl list-unit-files systemd-resolved.service >/dev/null 2>&1; then
        step "No systemd-resolved — nothing to release on 127.0.0.54:53"
    elif ! systemctl is-enabled systemd-resolved >/dev/null 2>&1 &&
        ! systemctl is-active systemd-resolved >/dev/null 2>&1; then
        step "systemd-resolved already disabled"
    else
        step "Disabling systemd-resolved (the DNS proxy needs 127.0.0.54:53)"
        systemctl stop systemd-resolved || true
        systemctl disable systemd-resolved || true
        systemctl mask systemd-resolved || true
        repoint_resolv_conf
    fi
}

# The listener on 127.0.0.54:53 as `ss -lunp` shows it, or nothing. One snapshot,
# so a holder that exits mid-check reads as no holder instead of failing the pipe.
dns_port_holder() {
    ss -H -lunp 2>/dev/null | awk '$4 == "127.0.0.54:53" && !seen { print; seen = 1 }'
}

# Anything else on the port (a leftover dns-proxy container, a second resolver)
# is said before `up`, not left to surface as a bind failure mid-rollout.
report_dns_port_holder() {
    local holder
    holder=$(dns_port_holder) || return 0
    [ -n "$holder" ] || return 0
    case "$holder" in
    *docker*) step "127.0.0.54:53 is held by a running dns-proxy container — fine" ;;
    *) warn "Something still holds 127.0.0.54:53 — dns-proxy may fail to start: ${holder}" ;;
    esac
}

# dns-proxy (the sites-dns service) publishes 127.0.0.54:53/udp, where
# systemd-resolved's proxy stub listens, so the port is freed before `up`, as
# installer.sh does. Bare `docker compose`, so an override file applies.
free_dns_proxy_port() {
    if ! dns_proxy_enabled; then
        step "dns-proxy is not in the enabled compose profiles — leaving the host resolver alone"
        return 0
    fi
    release_resolved_stub
    report_dns_port_holder
}

# A prefetch that keeps "not found" noise out of the startup step, as core's tag
# is pushed only at release; `up` builds on its own, so nothing here is fatal.
ensure_service_image() { # <service>
    docker compose config --services 2>/dev/null | grep -qx "$1" || {
        warn "No compose service '$1' — skipping its image"
        return 0
    }
    docker compose pull --quiet "$1" >/dev/null 2>&1 && return 0
    warn "No published image for '$1' — building from dockerfiles/Dockerfile-core"
    docker compose build "$1" || warn "Could not build '$1'; 'up' will try again"
}

ensure_core_images() {
    step "Ensuring the core images exist"
    ensure_service_image core
}

# Installing Sysbox restarts Docker and brings the stack up, so it runs once the
# images exist and the DNS port is free. Every tenant deploy needs it, unless the
# engine itself runs inside sysbox: DIND_RUNTIME=privileged there.
install_sysbox() {
    if [ "$INSTALL_SYSBOX" = 1 ]; then
        step "Installing/checking the Sysbox runtime"
        bash scripts/install-sysbox.sh || warn "Sysbox install failed — tenant projects will not start"
    elif grep -q '^DIND_RUNTIME=privileged' .env-core 2>/dev/null; then
        warn "Skipping Sysbox — DIND_RUNTIME=privileged, so accounts run privileged instead"
    else
        warn "Skipping Sysbox — tenant projects will not start (set DIND_RUNTIME=privileged if the engine is itself inside sysbox)"
    fi
}

# installer.sh's harden_host, on by default: the API tests exercise the firewall.
# Before the stack: moving a host off CSF flushes Docker's chains, and the Docker
# restart that puts them back would take every container down with it. The build
# network is created then, while Docker's chains are fresh.
harden_host() {
    [ "$HARDEN" = 1 ] || return 0
    step "Applying host configuration (sysctl, monit, firewall)"
    bash scripts/configure-sysctl.sh || warn "sysctl configuration failed"
    bash scripts/configure-monit.sh || warn "monit configuration failed"
    bash scripts/firewall.sh --install || warn "Firewall setup failed"
    service docker restart || warn "Could not restart Docker"
    bash scripts/build-network-firewall.sh --create panelalpha-build || warn "Could not create the build network"
    bash scripts/tenant-network-firewall.sh --create --restart-docker || warn "Could not apply the tenant network firewall"
}

# As installer.sh: quota enforces project disk limits, lxcfs gives each account
# its own /proc, and path-attached AppArmor profiles would confine tenant binaries.
configure_account_isolation() {
    if [ "$QUOTA" = 1 ]; then
        step "Turning on filesystem quota for /home"
        bash scripts/configure-quota.sh || warn "Could not turn on filesystem quota; project disk limits will not be enforced"
    else
        warn "Skipping filesystem quota (--no-quota): project disk limits will not be enforced"
    fi

    step "Setting up lxcfs"
    bash scripts/configure-lxcfs.sh || warn "lxcfs is not running; accounts will see the host's memory, CPUs and load in /proc"

    step "Disabling the host's path-attached AppArmor profiles"
    bash scripts/configure-apparmor.sh || warn "Could not disable the host's path-attached AppArmor profiles; tenant binaries at those paths stay confined"
}

# sites-db and the registries join pash-tenants; a host that ran the old stack
# still has dockerhub-mirror on registry-proxy's port.
start_stack() {
    step "Starting the stack"
    docker network inspect pash-tenants >/dev/null 2>&1 || {
        echo "The accounts' network pash-tenants could not be created; the tenant-network-firewall messages above say why." >&2
        exit 1
    }
    bash scripts/retire-dockerhub-mirror.sh .env
    # shellcheck disable=SC2086
    docker compose up -d $SERVICES
}

wait_for_database() {
    step "Waiting for the database"
    until core_artisan system:database:test | grep -q "Test successful"; do
        echo "  still waiting..."
        sleep 5
    done
}

# Moving accounts is live, as in installer.sh, and never fails the bootstrap.
update_core_state() {
    step "Running migrations"
    core_artisan migrate --force

    step "Moving accounts onto the tenant network"
    core_artisan project:network:move --all || warn "Some accounts could not be moved onto the tenant network"

    step "Pointing Exim at this host's Docker addresses"
    core_artisan system:exim:rebuild --networks || warn "Exim's listen addresses could not be updated"
}

IP_WAS_SET=0
record_host_addresses() {
    if ! core_artisan settings:exists default_ipv4 >/dev/null 2>&1; then
        core_artisan settings:set default_ipv4 "$PUBLIC_IP"
        IP_WAS_SET=1
    fi
    if ! core_artisan settings:exists default_ipv6 >/dev/null 2>&1; then
        IPV6=$(ip route get to 2001:db8:: 2>/dev/null | grep -m 1 -o 'src [0-9a-f:]*' | cut -d ' ' -f 2 || true)
        core_artisan settings:set default_ipv6 "${IPV6:-}" || true
    fi
}

# A first default_ipv4 moves the vhosts from a wildcard listen to the address,
# which nginx cannot take on a reload: rendered, then restarted. Otherwise
# rendered, as an update can ship a new config, and the reload restarts the
# webserver itself if its listen addresses changed.
render_webserver_config() {
    if [ "$IP_WAS_SET" = 1 ]; then
        step "Rendering the webserver config for ${PUBLIC_IP}"
        core_artisan system:domain:rebuild || warn "Could not rebuild the webserver configuration"
        step "Restarting the webserver to pick up the address-specific vhosts"
        docker compose restart sites-http
    else
        step "Rendering the webserver config"
        core_artisan system:domain:rebuild || warn "Could not rebuild the webserver configuration"
    fi
}

# SFTP-as-root uploads leave root-owned files where php-fpm needs to write.
restore_core_ownership() {
    chown -R www-data:www-data core/bootstrap/cache core/storage 2>/dev/null || true
}

install_pae_command() {
    step "Installing the pae command"
    bash scripts/pae-command.sh register || warn "Could not install the pae command"
}

# As installer.sh: with no name asked for, the address is the name. A domain
# certificate only for --domain or a cert_domain already set: the
# .panelalpha.direct default spends a weekly allowance every engine shares.
request_certificates() {
    if ipcalc "$PUBLIC_IP" | grep -q 'Private Internet'; then
        warn "${PUBLIC_IP} is private — staying on the self-signed certificate"
        return 0
    fi
    [ -n "$CERT_DOMAIN" ] ||
        CERT_DOMAIN=$(core_artisan settings:get cert_domain 2>/dev/null | tr -d '\r' | tail -n 1)
    if [ -z "$CERT_DOMAIN" ]; then
        step "Requesting a Let's Encrypt certificate for ${PUBLIC_IP}"
        bash scripts/letsencrypt-request-ip-cert.sh "$PUBLIC_IP" --install ||
            warn "Let's Encrypt IP request failed — staying on the self-signed cert"
    else
        step "Requesting a Let's Encrypt certificate for ${PUBLIC_IP} (kept as the fallback)"
        bash scripts/letsencrypt-request-ip-cert.sh "$PUBLIC_IP" || warn "Let's Encrypt IP request failed"
        step "Requesting a Let's Encrypt certificate for ${CERT_DOMAIN}"
        bash scripts/letsencrypt-request-cert.sh --domain "$CERT_DOMAIN" --ip "$PUBLIC_IP" ||
            warn "Let's Encrypt request for ${CERT_DOMAIN} failed — staying on the current cert"
    fi
}

# The prewarm builds the shared PHP base images in the background, or the first
# customer to deploy each PHP minor pays ~150 s for it.
certificate_and_prewarm() {
    if [ "$HARDEN" != 1 ]; then
        warn "Skipped sysctl/monit/firewall/prewarm (--no-hardening) — firewall API tests will fail"
        return 0
    fi
    request_certificates
    bash scripts/prewarm-images.sh || warn "Could not start image prewarm"
}

# Tokens survive a redeploy, so count them rather than assume a fresh engine.
# Fails when the list cannot be read; prints 0 for an empty one.
existing_tokens() {
    local list
    list=$(core_artisan api:token:list 2>/dev/null) || return 1
    printf '%s\n' "$list" | tr -d '\r' | grep -cE '^\| +[0-9]+ +\|' || true
}

# From the certificate :2011 serves, not from what this run requested: a re-run
# keeps an earlier Let's Encrypt one, and a request can fail.
served_cert_note() { # <cert file> <address>
    if openssl verify -untrusted "$1" -verify_ip "$2" "$1" >/dev/null 2>&1; then
        echo "The certificate is publicly trusted for $2; clients need nothing extra."
    elif openssl verify -untrusted "$1" "$1" >/dev/null 2>&1; then
        echo "The certificate is publicly trusted, but not for $2; connect by the name it is issued for."
    else
        echo "The certificate is self-signed; clients need it explicitly:"
        echo "  curl --cacert $1 https://$2:2011/api/projects"
    fi
}

print_token_hint() {
    if ! tokens=$(existing_tokens); then
        echo "Mint tokens when you need them ('pae', or 'pae-artisan'):"
    elif [ "$tokens" -gt 0 ]; then
        echo "${tokens} token(s) already exist ('pae api:token:list'); mint another when you need one ('pae', or 'pae-artisan'):"
    else
        echo "No tokens exist yet — mint them when you need them ('pae', or 'pae-artisan'):"
    fi
}

print_summary() {
    echo
    step "Engine is up."
    echo "  API URL: https://${PUBLIC_IP}:2011/api"
    echo "  MCP URL: https://${PUBLIC_IP}:2011/mcp"
    echo
    print_token_hint
    echo "  pae api:token:create default"
    echo "  pae mcp:token:create default   # prints the registration command for every MCP client"
    echo
    served_cert_note "${ENGINE_DIR}/crt/server.cert" "$PUBLIC_IP"
}

parse_args "$@"
[ "$EUID" -eq 0 ] || { echo "Run as root." >&2; exit 1; }
cd "$ENGINE_DIR"

check_prerequisites
prepare_env_files
create_storage_tree
seed_config_files
generate_self_signed_cert
prepare_networks
install_composer_dependencies
free_dns_proxy_port
ensure_core_images
install_sysbox
harden_host
configure_account_isolation
start_stack
wait_for_database
update_core_state
record_host_addresses
render_webserver_config
restore_core_ownership
install_pae_command
certificate_and_prewarm
print_summary
