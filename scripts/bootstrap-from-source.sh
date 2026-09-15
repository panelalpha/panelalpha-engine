#!/usr/bin/env bash
# Bring the engine up from an already-uploaded source tree, without the full
# installer. Does what install_panelalpha_engine() does — env files, config
# seeding, TLS, network, vendor, compose, migrations — and nothing else: no
# apt upgrade, no CSF, no monit, no sysctl, no Let's Encrypt, no telemetry.
#
# Idempotent: every step is guarded, so re-running it after another upload is
# the redeploy path.
#
#   bash scripts/bootstrap-from-source.sh [--ip ADDR] [--no-sysbox]
#                                         [--no-docker-install] [--composer]
#                                         [--services "core nginx ..."]
#                                         [--profiles "hosting,mail" | --core-only]
#                                         [--dind-runtime privileged] [--mtu 1400]

set -euo pipefail

ENGINE_DIR="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/.." && pwd)"
COMPOSER_IMAGE='ghcr.io/panelalpha/engine-composer:20260908'

PUBLIC_IP=''
INSTALL_SYSBOX=1
INSTALL_DOCKER=1
FORCE_COMPOSER=0
KEEP_RESOLVED=0
HARDEN=1
SERVICES=''
PROFILES=''
SET_PROFILES=0
DIND_RUNTIME=''
DOCKER_NETWORK_MTU=1500

while [ $# -gt 0 ]; do
    case "$1" in
    --ip) PUBLIC_IP="$2"; shift 2 ;;
    --no-sysbox) INSTALL_SYSBOX=0; shift ;;
    --no-docker-install) INSTALL_DOCKER=0; shift ;;
    --composer) FORCE_COMPOSER=1; shift ;;
    --keep-resolved) KEEP_RESOLVED=1; shift ;;
    --no-hardening) HARDEN=0; shift ;;
    --services) SERVICES="$2"; shift 2 ;;
    --profiles) PROFILES="$2"; SET_PROFILES=1; shift 2 ;;
    --core-only) PROFILES=''; SET_PROFILES=1; shift ;;
    --dind-runtime) DIND_RUNTIME="$2"; shift 2 ;;
    --mtu) DOCKER_NETWORK_MTU="$2"; shift 2 ;;
    -h | --help) sed -n '2,12p' "$0"; exit 0 ;;
    *) echo "Unknown option: $1" >&2; exit 1 ;;
    esac
done

green='\e[32m'; yellow='\e[33m'; plain='\e[39m'
step() { echo -e "${green}>>> $1${plain}"; }
warn() { echo -e "${yellow}>>> $1${plain}"; }

[ "$EUID" -eq 0 ] || { echo "Run as root." >&2; exit 1; }
cd "$ENGINE_DIR"

rand() { tr -dc 'a-zA-Z0-9' </dev/urandom | head -c "${1:-12}"; }

# ---------------------------------------------------------------- prerequisites
step "Checking prerequisites"
. /etc/os-release
case "${ID:-}" in
debian | ubuntu) : ;;
*) warn "Untested distribution '${ID:-unknown}' — the stack expects Debian 12/13 or Ubuntu 22.04/24.04" ;;
esac

MISSING=()
command -v openssl >/dev/null || MISSING+=(openssl)
command -v ssh-keygen >/dev/null || MISSING+=(openssh-client)
command -v curl >/dev/null || MISSING+=(curl)
command -v rsync >/dev/null || MISSING+=(rsync)
command -v ipcalc >/dev/null || MISSING+=(ipcalc)
if [ ${#MISSING[@]} -gt 0 ]; then
    step "Installing ${MISSING[*]}"
    DEBIAN_FRONTEND=noninteractive apt-get update -y
    DEBIAN_FRONTEND=noninteractive apt-get install -y "${MISSING[@]}"
fi

if ! command -v docker >/dev/null; then
    [ "$INSTALL_DOCKER" = 1 ] || { echo "Docker is not installed (and --no-docker-install was given)." >&2; exit 1; }
    step "Installing Docker engine"
    export DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a
    apt-get install -y ca-certificates curl gnupg
    mkdir -m 0755 -p /etc/apt/keyrings
    curl --http1.1 -fsSL "https://download.docker.com/linux/${ID}/gpg" |
        gpg --dearmor -o /etc/apt/keyrings/docker.gpg --yes
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/${ID} ${VERSION_CODENAME:-} stable" \
        >/etc/apt/sources.list.d/docker.list
    apt-get update -y
    apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
fi
docker compose version >/dev/null 2>&1 || { echo "The docker compose plugin is missing." >&2; exit 1; }

if [ -z "$PUBLIC_IP" ]; then
    PUBLIC_IP=$(ip route get 8.8.8.8 | sed -n '/src/{s/.*src *\([^ ]*\).*/\1/p;q}')
fi
[ -n "$PUBLIC_IP" ] || { echo "Could not determine the host IP — pass --ip ADDR." >&2; exit 1; }
step "Using host address ${PUBLIC_IP}"

# ------------------------------------------------------------------- env files
step "Preparing .env and .env-core"
cp -n .env.example .env
cp -n .env-core.example .env-core
grep -q '^CORE_MYSQL_PASSWORD=.\+' .env ||
    sed -i "s/^CORE_MYSQL_PASSWORD=.*/CORE_MYSQL_PASSWORD=$(rand)/" .env
grep -q '^USERS_MYSQL_ROOT_PASSWORD=.\+' .env ||
    sed -i "s/^USERS_MYSQL_ROOT_PASSWORD=.*/USERS_MYSQL_ROOT_PASSWORD=$(rand)/" .env
# Append when the line is absent rather than only rewriting one that exists:
# `sed s#^APP_URL=.*#...#` matches nothing on a .env-core without the key and
# fails silently, which leaves APP_URL unset. Laravel then falls back to
# http://localhost, and the engine reports its own certificate as
# domain_mismatch -- the certificate is fine, the name it is compared against
# is not. Same shape as DIND_RUNTIME below.
if grep -q '^APP_URL=' .env-core; then
    grep -q '^APP_URL=.\+' .env-core ||
        sed -i "s#^APP_URL=.*#APP_URL=https://${PUBLIC_IP}:2011#" .env-core
else
    [ -z "$(tail -c1 .env-core)" ] || echo "" >>.env-core
    echo "APP_URL=https://${PUBLIC_IP}:2011" >>.env-core
fi
# Before the stack starts: queue workers keep whatever APP_KEY they booted with, so a
# key written after `up` never reaches them until core restarts.
if ! grep -q '^APP_KEY=.\+' .env-core; then
    APP_KEY="base64:$(head -c 32 /dev/urandom | base64 | tr -d '\n')"
    if grep -q '^APP_KEY=' .env-core; then
        sed -i "s|^APP_KEY=.*|APP_KEY=${APP_KEY}|" .env-core
    else
        [ -z "$(tail -c1 .env-core)" ] || echo "" >>.env-core
        echo "APP_KEY=${APP_KEY}" >>.env-core
    fi
fi

# Optional services sit behind compose profiles; see .env.example for the list.
# --core-only (empty) leaves just the control plane: core, core-db and
# core-http. Untouched, .env.example's default of 'full' gives the whole stack.
if [ "$SET_PROFILES" = 1 ]; then
    if grep -q '^COMPOSE_PROFILES=' .env; then
        sed -i "s#^COMPOSE_PROFILES=.*#COMPOSE_PROFILES=${PROFILES}#" .env
    else
        [ -z "$(tail -c1 .env)" ] || echo "" >>.env
        echo "COMPOSE_PROFILES=${PROFILES}" >>.env
    fi
    step "Compose profiles: ${PROFILES:-<none> (control plane only)}"
elif ! grep -q '^COMPOSE_PROFILES=' .env; then
    [ -z "$(tail -c1 .env)" ] || echo "" >>.env
    echo "COMPOSE_PROFILES=full" >>.env
fi
# How account containers are isolated. Empty leaves the application default
# (sysbox); 'privileged' is for an engine that is itself inside a sysbox
# container, where sysbox-in-sysbox is unsupported.
if [ -n "$DIND_RUNTIME" ]; then
    if grep -q '^DIND_RUNTIME=' .env-core; then
        sed -i "s#^DIND_RUNTIME=.*#DIND_RUNTIME=${DIND_RUNTIME}#" .env-core
    else
        [ -z "$(tail -c1 .env-core)" ] || echo "" >>.env-core
        echo "DIND_RUNTIME=${DIND_RUNTIME}" >>.env-core
    fi
    step "Account containers will use DIND_RUNTIME=${DIND_RUNTIME}"
fi

# --------------------------------------------------------- writable app storage
# Both of these are excluded from the upload: core/storage is host state, and
# core/bootstrap/cache is a cache the engine rewrites. On a fresh host neither
# exists, and the first thing that needs them is composer install below — its
# post-autoload-dump hook runs `artisan package:discover`, which aborts with
# "The /app/bootstrap/cache directory must be present and writable". That
# happened under `set -e`, so the bootstrap died before the stack ever started
# and left a host with Docker, a vendor tree and no engine. entrypoint-core.sh
# creates the storage subdirectories inside the container, but never
# bootstrap/cache, and it runs long after composer does. Create both here, and
# the subdirectories entrypoint-core.sh expects while we are at it.
step "Creating the Laravel storage tree"
mkdir -p core/storage/framework/{sessions,views,cache} core/storage/logs \
    core/bootstrap/cache
# Owned by www-data, which is uid 33 in the core image, because the host bind
# mount preserves these numeric owners into the container and it is www-data —
# not root — that runs artisan there. A root-owned cache is writable by the
# chown at the end of this script, but that comes after the migrations, which
# is too late for the very first `php artisan` to succeed.
chown -R 33:33 core/storage core/bootstrap/cache 2>/dev/null ||
    warn "Could not chown core/storage and core/bootstrap/cache to www-data"

# ------------------------------------------------- compose include + config dirs
# docker-compose.yml opens with `include: docker-compose.yml-webserver`, and that
# file is gitignored — without it compose cannot even parse the project.
step "Seeding config files from templates/"
cp -n docker-compose.yml-nginx-proxy docker-compose.yml-webserver

mkdir -p webserver-config/{apache,nginx,nginx-proxy}/vhosts \
    webserver-config/{litespeed,openlitespeed}-admin \
    webserver-logs/{apache,nginx,nginx-proxy,litespeed,openlitespeed} \
    logs/{litespeed,exim,modsecurity} \
    config/{pure-ftpd,sftp,logrotate,exim,modsecurity}
cp -Rn templates/webserver-config/. webserver-config/
cp -Rn templates/config/pure-ftpd/. config/pure-ftpd/
chmod +x config/pure-ftpd/entrypoint.sh
cp -Rn templates/config/sftp/. config/sftp/
cp -Rn templates/config/logrotate/. config/logrotate/
cp -Rn templates/config/exim/. config/exim/
cp -Rn templates/config/modsecurity/. config/modsecurity/
[ -f config/sftp/ssh_host_ed25519_key ] ||
    ssh-keygen -t ed25519 -N '' -f config/sftp/ssh_host_ed25519_key </dev/null
[ -f config/sftp/ssh_host_rsa_key ] ||
    ssh-keygen -t rsa -b 4096 -N '' -f config/sftp/ssh_host_rsa_key </dev/null

# -------------------------------------------------------------------------- TLS
# The address has to appear in the SAN list, not just the CN: Node and Go both
# verify the URL host against the SAN and reject a CN-only certificate.
if [ ! -f crt/server.cert ]; then
    step "Generating a self-signed certificate for ${PUBLIC_IP}"
    mkdir -p crt
    openssl req -new -x509 -days 365 -nodes \
        -out crt/server.cert -keyout crt/server.key \
        -subj "/C=US/ST=ST/L=L/O=O/OU=Org/CN=${PUBLIC_IP}" \
        -addext "subjectAltName=IP:${PUBLIC_IP},IP:127.0.0.1,DNS:localhost"
fi

# ---------------------------------------------------------------------- network
# Trusted-proxy list for the nginx real-IP config; harmless offline.
bash scripts/update-cloudflare-ips.sh || warn "Could not refresh the Cloudflare IP list"

docker network inspect pash-default-network >/dev/null 2>&1 || {
    step "Creating pash-default-network"
    docker network create pash-default-network --opt com.docker.network.driver.mtu="${DOCKER_NETWORK_MTU}"
}

# ----------------------------------------------------------------------- vendor
if [ "$FORCE_COMPOSER" = 1 ] || [ ! -d core/vendor ]; then
    step "Installing composer dependencies"
    docker image inspect "$COMPOSER_IMAGE" >/dev/null 2>&1 ||
        docker pull "$COMPOSER_IMAGE" ||
        docker build --tag "$COMPOSER_IMAGE" - <dockerfiles/Dockerfile-composer
    docker run --rm -v "${ENGINE_DIR}/core:/app" -w /app "$COMPOSER_IMAGE" composer install
fi

# --------------------------------------------------------------------- the stack
# Bare `docker compose` (no -f) so a docker-compose.override.yml applies, the
# same way scripts/pae.sh sees it.
#
# dns-proxy publishes 127.0.0.54:53/udp, which is precisely where
# systemd-resolved's proxy stub listens (systemd >= 247), so the port has to be
# free before `up`. This used to decide whether to free it by asking
# `docker compose config --services` for a service named dns-proxy. Two things
# are wrong with that:
#
#   * The service is `sites-dns` and always has been inside docker-compose.yml;
#     `dns-proxy` is its container hostname. Nothing matched, so the branch that
#     disables systemd-resolved was dead code and the stack came up with
#     resolved still holding 127.0.0.54:53.
#   * `docker compose config` is not a read-only probe. Compose logs "network X
#     was found but has incorrect label ... declared as external" and creates
#     the missing external networks, and on a fresh host it has to pull the
#     images' metadata first. Run before the DNS check with nothing else on the
#     host, that is slow enough that the check reads a timeout/empty output as
#     "no dns-proxy here" — which is the one case where the resolver must be
#     disabled, and the warning it printed in that case said the opposite:
#     "dns-proxy is not in the enabled compose profiles".
#
# installer.sh takes the other route and disables resolved unconditionally
# (disable_systemd_resolved, before harden_host and before `up`, for the same
# reason). Do the same, minus the profile question it does not have to ask.
if grep -q '^COMPOSE_PROFILES=' .env &&
    grep -qE '^COMPOSE_PROFILES=(.*,)?(hosting|full)(,.*)?$' .env; then
    if [ "$KEEP_RESOLVED" = 1 ]; then
        warn "--keep-resolved given — leaving systemd-resolved on 127.0.0.54:53; dns-proxy will not start"
    elif ! systemctl list-unit-files systemd-resolved.service >/dev/null 2>&1; then
        step "No systemd-resolved — nothing to release on 127.0.0.54:53"
    elif ! systemctl is-enabled systemd-resolved >/dev/null 2>&1 &&
        ! systemctl is-active systemd-resolved >/dev/null 2>&1; then
        step "systemd-resolved already disabled"
    else
        # Mask rather than stop: stopping alone loses the race, because
        # systemd-resolved binds 127.0.0.54 through its proxy stub and a socket
        # activation or a socket unit can bring it back between here and `up`.
        step "Disabling systemd-resolved (the DNS proxy needs 127.0.0.54:53)"
        systemctl stop systemd-resolved || true
        systemctl disable systemd-resolved || true
        systemctl mask systemd-resolved || true

        # Only here, and only for the stub. This repoint is the second half of
        # stopping systemd-resolved: 127.0.0.53 is the address that just went
        # away, so leaving resolv.conf pointed at it would leave the host — and
        # every container that inherited the file — resolving nothing.
        #
        # It belongs inside this branch rather than beside the chain. Run after
        # `--keep-resolved`, after "no systemd-resolved" and after "already
        # disabled", it rewrites a resolver this script just promised not to
        # touch, on a host that may never have had systemd-resolved at all.
        #
        # And the test has to name the stub. `[ -L /etc/resolv.conf ]` is true
        # of *any* symlink: resolvconf, NetworkManager and netplan all ship one,
        # and a host pointed at an internal or split-horizon resolver would have
        # been handed 1.1.1.1 and lost every internal name.
        #
        # Two shapes count, and only these two: the file still *says*
        # 127.0.0.53 (grep follows the symlink, so this covers the usual
        # `/etc/resolv.conf -> ../run/systemd/resolve/stub-resolv.conf`), or the
        # symlink now dangles because stopping resolved took its target away --
        # `-L` is true of the link while `-e` follows it, so the pair is exactly
        # "a link pointing at nothing".
        if grep -q '^[[:space:]]*nameserver[[:space:]]\+127\.0\.0\.53' /etc/resolv.conf 2>/dev/null ||
            { [ -L /etc/resolv.conf ] && [ ! -e /etc/resolv.conf ]; }; then
            step "Repointing /etc/resolv.conf away from the resolved stub"
            cp -a /etc/resolv.conf /etc/resolv.conf.backup
            rm -f /etc/resolv.conf
            printf 'nameserver 1.1.1.1\nnameserver 8.8.8.8\n' >/etc/resolv.conf
        fi
    fi
    # And if something else holds the port anyway (a leftover dns-proxy
    # container, or a second resolver), say so before `up` rather than letting
    # it surface as a bind failure in the middle of the container roll-out.
    if ss -H -lun 2>/dev/null | awk '{print $4}' | grep -q '^127\.0\.0\.54:53$'; then
        HOLDER=$(ss -H -lunp 2>/dev/null | grep '127\.0\.0\.54:53' | head -1)
        case "$HOLDER" in
        *docker*) step "127.0.0.54:53 is held by a running dns-proxy container — fine" ;;
        *) warn "Something still holds 127.0.0.54:53 — dns-proxy may fail to start: ${HOLDER}" ;;
        esac
    fi
else
    step "dns-proxy is not in the enabled compose profiles — leaving the host resolver alone"
fi

# core and cron carry image tags that are not always published to ghcr — the tag is bumped
# in-repo when the runtime changes (babc60c's PHP 8.1 -> 8.3 move) and only pushed at
# release. `up` does fall back to the build section on a failed pull, but doing it here
# keeps the "not found" noise out of the startup step. The layer cache makes re-runs cheap.
step "Ensuring the core images exist"
# Only services that exist: naming one that does not made `docker compose
# build` exit "no such service", and under `set -e` that aborted the whole bootstrap here --
# skipping the stack start, the migrations and pae-artisan, while the rsync
# above had already put the new source on the host. An engine left in that
# state runs new code against an unmigrated database and never rereads
# .env-core, which is what made it report APP_URL as http://localhost.
#
# Warn rather than abort if a service is missing or unbuildable: this step is
# a prefetch that keeps "not found" noise out of the startup step, and `up`
# falls back to the build section on its own. It is not worth the whole
# deploy.
for svc in core; do
    docker compose config --services 2>/dev/null | grep -qx "$svc" || {
        warn "No compose service '$svc' — skipping its image"
        continue
    }
    docker compose pull --quiet "$svc" >/dev/null 2>&1 && continue
    warn "No published image for '$svc' — building from dockerfiles/Dockerfile-core"
    docker compose build "$svc" || warn "Could not build '$svc'; 'up' will try again"
done

# Installing Sysbox restarts Docker, and install-sysbox.sh brings the stack back up
# through container-manager.sh as it does so. It therefore has to run *after* the
# images are built and the DNS port is free, or that implicit `up` fails on both.
# Only tenant projects need it (the dind account template asks for sysbox by
# default), but without it every deploy fails, so it is on by default. The one
# case where skipping it is correct is an engine that itself runs inside a
# sysbox container, where sysbox-in-sysbox is unsupported — set
# DIND_RUNTIME=privileged in .env-core there so accounts ask for privilege
# instead.
if [ "$INSTALL_SYSBOX" = 1 ]; then
    step "Installing/checking the Sysbox runtime"
    bash scripts/install-sysbox.sh || warn "Sysbox install failed — tenant projects will not start"
else
    if grep -q '^DIND_RUNTIME=privileged' .env-core 2>/dev/null; then
        warn "Skipping Sysbox — DIND_RUNTIME=privileged, so accounts run privileged instead"
    else
        warn "Skipping Sysbox — tenant projects will not start (set DIND_RUNTIME=privileged if the engine is itself inside sysbox)"
    fi
fi

# ------------------------------------------------------------------- hardening
# installer.sh's harden_host. On by default so a source install reaches the same end
# state as a packaged one — the API test suite exercises CSF, so an engine without it
# is not a complete engine. --no-hardening skips the lot.
#
# Before the stack, not after: csf.sh rebuilds the whole iptables ruleset, dropping
# the chains the Docker daemon installs at start, so the daemon has to be restarted
# — and with the stack up that takes every container down with it. It needs .env,
# the compose bridge and docker0, so this is the earliest point it can run.
if [ "$HARDEN" = 1 ]; then
    step "Applying host configuration (sysctl, monit, CSF)"
    bash scripts/configure-sysctl.sh || warn "sysctl configuration failed"
    bash scripts/configure-monit.sh || warn "monit configuration failed"
    bash scripts/csf.sh --install || warn "CSF install failed"
    service docker restart || warn "Could not restart Docker"
fi

step "Starting the stack"
# shellcheck disable=SC2086
docker compose up -d $SERVICES

step "Waiting for the database"
until docker compose exec -T core php artisan system:database:test | grep -q "Test successful"; do
    echo "  still waiting..."
    sleep 5
done

step "Running migrations"
docker compose exec -T core php artisan migrate --force

IP_WAS_SET=0
if ! docker compose exec -T core php artisan settings:exists default_ipv4 >/dev/null 2>&1; then
    docker compose exec -T core php artisan settings:set default_ipv4 "$PUBLIC_IP"
    IP_WAS_SET=1
fi
if ! docker compose exec -T core php artisan settings:exists default_ipv6 >/dev/null 2>&1; then
    IPV6=$(ip route get to 2001:db8:: 2>/dev/null | grep -m 1 -o 'src [0-9a-f:]*' | cut -d ' ' -f 2 || true)
    docker compose exec -T core php artisan settings:set default_ipv6 "${IPV6:-}" || true
fi

# Setting default_ipv4 switches generated vhosts from a wildcard listen to an explicit
# `listen 10.x.x.x:80/443`. nginx cannot make that change on a reload — binding the
# specific address fails with EADDRINUSE against its own wildcard socket — so the
# webserver keeps serving the old config and every tenant vhost 502s until it restarts.
# installer.sh only ever sets the address on a host with no tenant vhosts yet, so it
# never hits this; a re-run over an existing install does.
if [ "$IP_WAS_SET" = 1 ]; then
    step "Restarting the webserver to pick up the address-specific vhosts"
    docker compose restart sites-http
fi

# SFTP-as-root uploads leave root-owned files where php-fpm needs to write.
chown -R www-data:www-data core/bootstrap/cache core/storage 2>/dev/null || true

step "Installing the pae command"
bash scripts/pae-command.sh register || warn "Could not install the pae command"

# ------------------------------------------------------- certificate and prewarm
if [ "$HARDEN" = 1 ]; then
    if ! ipcalc "$PUBLIC_IP" | grep -q 'Private Internet'; then
        step "Requesting a Let's Encrypt certificate for ${PUBLIC_IP}"
        bash scripts/letsencrypt-request-ip-cert.sh "$PUBLIC_IP" || warn "Let's Encrypt IP request failed"
        step "Requesting a Let's Encrypt certificate for the engine's domain"
        bash scripts/letsencrypt-request-cert.sh --ip "$PUBLIC_IP" || warn "Let's Encrypt domain request failed — staying on the self-signed cert"
    else
        warn "${PUBLIC_IP} is private — staying on the self-signed certificate"
    fi

    # Builds the shared PHP base images in the background: without this the ~150s
    # per PHP minor is paid by whichever customer deploys that minor first.
    bash scripts/prewarm-images.sh || warn "Could not start image prewarm"
else
    warn "Skipped sysctl/monit/CSF/prewarm (--no-hardening) — CSF-dependent API tests will fail"
fi

echo
step "Engine is up."
echo "  API URL: https://${PUBLIC_IP}:2011/api"
echo "  MCP URL: https://${PUBLIC_IP}:2011/mcp"
echo
echo "No tokens exist yet — mint them when you need them ('pae', or 'pae-artisan'):"
echo "  pae api:token:create default"
echo "  pae mcp:token:create default   # prints the registration command for every MCP client"
echo
echo "The certificate is self-signed; clients need it explicitly:"
echo "  curl --cacert ${ENGINE_DIR}/crt/server.cert https://${PUBLIC_IP}:2011/api/projects"
