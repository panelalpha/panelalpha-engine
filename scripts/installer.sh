#!/usr/bin/env bash

export DEBIAN_FRONTEND=noninteractive
export NEEDRESTART_MODE=a

default_color='\e[39m'
red_color='\e[31m'
green_color='\e[32m'
yellow_color='\e[33m'

die() {
    exit_code=$?
    if [[ ${exit_code} -ne 0 ]]; then
        echo -e "${yellow_color}${BASH_COMMAND} ${red_color}command failed with exit code ${yellow_color}${exit_code}${default_color}"
    fi
    send_update_status "$exit_code" || true
}

set -e
trap 'last_command=$current_command; current_command=$BASH_COMMAND' DEBUG
trap die EXIT

# A caller's umask 077 (panelalpha-installer.sh sets one) would leave the whole tree
# root-only, and php-fpm (www-data) then answers every request "File not found".
umask 022

random-string() {
    cat /dev/urandom | tr -dc 'a-zA-Z0-9' | fold -w ${1:-32} | head -n 1
}

# Empty means "not passed": resolved from PANELALPHA_ENGINE_VERSION env or defaults.
PANELALPHA_ENGINE_VERSION="${PANELALPHA_ENGINE_VERSION:-}"
PACKAGE_HOST='connect.panelalpha.com'
MONITORING_HOST="${PANELALPHA_MONITORING_HOST:-monitoring.panelalpha.com}"
STARTED_AT=$(date +%s || echo 0)
# Installed when the version lookup fails (legacy Connect path only). Bump at release.
FALLBACK_ENGINE_VERSION='2.0.1'
# Git URL for the engine tree. Default is the public GitHub mirror. Credentials may
# be embedded (https://user:token@host/...). Empty string forces the legacy Connect package path.
# "unset" vs empty: get.sh always exports a default; clearing the var opts into Connect.
if [ "${PANELALPHA_ENGINE_REPO+x}" = x ]; then
    ENGINE_REPO="${PANELALPHA_ENGINE_REPO}"
else
    ENGINE_REPO='https://github.com/panelalpha/engine.git'
fi
# Legacy REPO_* kept only for older callers; unused when ENGINE_REPO is set.
REPO_HOST="${PANELALPHA_REPO_HOST:-git.modulesgarden.tech}"
REPO_PROJECT="${PANELALPHA_REPO_PROJECT:-panelalpha/engine}"
REPO_REF="${PANELALPHA_REPO_REF:-development-2.0.0}"
REPO_TOKEN="${PANELALPHA_REPO_TOKEN:-}"
# A repository clone carries no vendor directory; a release package does.
COMPOSER_IMAGE='ghcr.io/panelalpha/engine-composer:v2.0.1'

# Tagged with the engine version, not a build date, so the tag moves whenever
# core/composer.json's PHP constraint does -- an unpublished tag does not pull
# and Dockerfile-composer is built instead. This stays as the second guard, for
# a published tag whose PHP is older than core asks for: `composer install`
# would abort on every platform requirement and leave no vendor/ at all. The
# pull is still preferred, but only kept when its PHP satisfies the constraint.
resolve_composer_image() {
    local want image_php
    want=$(sed -n 's/.*"php"[[:space:]]*:[[:space:]]*"[^0-9]*\([0-9][0-9]*\.[0-9][0-9]*\).*/\1/p' "$1/composer.json" | head -1)

    docker image inspect "$COMPOSER_IMAGE" >/dev/null 2>&1 || docker pull "$COMPOSER_IMAGE" || true

    if [ -n "$want" ] && docker image inspect "$COMPOSER_IMAGE" >/dev/null 2>&1; then
        image_php=$(docker run --rm "$COMPOSER_IMAGE" php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;' 2>/dev/null || echo 0)
        # sort -V puts the lower version first; if that is not $want the image is older.
        if [ "$(printf '%s\n%s\n' "$want" "$image_php" | sort -V | head -1)" != "$want" ]; then
            echo ">>> $COMPOSER_IMAGE ships PHP ${image_php}, core requires >= ${want} -- building from dockerfiles/Dockerfile-composer" >&2
            COMPOSER_IMAGE='panelalpha/engine-composer:local'
            docker build --tag "$COMPOSER_IMAGE" - <"$2/dockerfiles/Dockerfile-composer"
            return
        fi
    fi

    docker image inspect "$COMPOSER_IMAGE" >/dev/null 2>&1 ||
        docker build --tag "$COMPOSER_IMAGE" - <"$2/dockerfiles/Dockerfile-composer"
}
DEBUG_MODE=0
NO_LOCAL_IP=0
ENABLE_NAT=0
# The name the engine is served on, and the name its certificate is issued for.
# Falls back to --hostname when that is a DNS name. Empty means the engine is
# served on its public IP address instead.
DOMAIN=''
CERT_EMAIL=''
# Monitoring / contact email from the TUI wrapper (--email). Not Let's Encrypt.
INSTALL_EMAIL=''
# Obtain nothing from Let's Encrypt during the install. --domain is still
# recorded, so `pae-artisan ssl:engine-cert:request` picks it up later.
NO_CERT_REQUEST=0
# Filled by request_certificates with the name the certificate was issued for.
ENGINE_CERT_DOMAIN=''
# The address the served certificate is for, when it is the IP one.
ENGINE_CERT_IP=''
# Empty means "not passed", so --in-container can fill only the gaps below,
# regardless of flag order.
INSTALL_SYSBOX=''
HARDEN=''
UPGRADE=''
DIND_RUNTIME=''
DOCKER_NETWORK_MTU=''
IN_CONTAINER=0
# Populated by the TUI wrapper (panelalpha-installer.sh). Empty = no progress UI.
RUN_DIR=''
CONFIGURE_MODE=0
# install | update — set from --software-op or detected from an existing tree.
ENGINE_OP=''
# The repository the one-liner was asked to put on this host (--repo), and how
# to clone it. Empty means an engine install with nothing deployed into it.
DEPLOY_REPO=''
DEPLOY_BRANCH=''
DEPLOY_GIT_TOKEN=''
# owner/repo, for the lines an operator reads. Filled from DEPLOY_REPO.
DEPLOY_REPO_LABEL=''
# 1 when an engine is already here: then --repo is a project to create through
# the CLI, not a reason to install the whole engine over the top of itself.
DEPLOY_ONLY=0
# Filled by deploy_repository from what `pae project:create` reports back.
DEPLOY_PROJECT_NAME=''
DEPLOY_PROJECT_URL=''
DEPLOY_FAILED=0

usage() {
    cat <<'USAGE'
Usage: bash installer.sh [options]

  -v, --version REF        git ref / release (default: main with ENGINE_REPO, else newest Connect release)
  -host, --hostname HOST   hostname to install under
      --domain FQDN        serve the engine on your own name. Point an A
                           record at this host first; the certificate is
                           requested for it during the install. Without one
                           the engine is served on its public IP address.
                           (--hostname counts when it is a DNS name.)
      --no-cert-request    obtain nothing from Let's Encrypt during the
                           install and stay on the self-signed certificate.
                           --domain is still recorded, so
                           `pae-artisan ssl:engine-cert:request` requests it
                           later -- for when DNS is not pointing here yet.
      --cert-email ADDR    Let's Encrypt account email (expiry warnings)
      --email ADDR         Contact/monitoring email (settings:set email)
      --repo REPO          deploy a repository once the engine is up: a clone
                           URL, host/owner/repo, or a bare owner/repo, which
                           means GitHub unless the engine's default_git_host
                           setting says otherwise. The project name and the
                           domain are generated. Where an engine is already
                           installed, only the project is created -- the engine
                           is left alone.
      --branch REF         branch, tag or commit for --repo
      --git-token TOKEN    HTTPS access token for a private --repo
  -p, --package-host HOST  package host (legacy Connect path only)
      --monitoring-host H  monitoring host for install status (default: monitoring.panelalpha.com)
  -d, --debug              set -x
      --no-local-ip        resolve the public IP when the default route is private
      --enable-nat         build the NAT mapping after migrating
      --configure          TUI preflight only (exit 0); used by the wrapper
      --run-dir DIR        write progress/step files for the TUI wrapper

Environment:
  PANELALPHA_ENGINE_REPO      git clone URL (default: https://github.com/panelalpha/engine.git)
  PANELALPHA_ENGINE_VERSION   branch/tag/commit (default: main when using git)
  PANELALPHA_MONITORING_HOST  monitoring hostname (same as --monitoring-host)

Installing into a container (CI, dev):

      --in-container       shorthand for a host that is itself a container:
                           --no-sysbox --no-hardening --dind-runtime privileged
                           --mtu 1400. Individual flags still win.
      --no-sysbox          do not install the Sysbox runtime
      --no-hardening       skip sysctl, monit and CSF
      --no-upgrade         skip 'apt-get upgrade' and 'apt-get autoremove'
      --dind-runtime VALUE DIND_RUNTIME for .env-core: sysbox-runc or privileged
      --mtu VALUE          MTU for pash-default-network (default 1500)
USAGE
    exit 0
}

while true; do
    case "$1" in
    -v | --version)
        PANELALPHA_ENGINE_VERSION="$2"
        shift
        shift
        ;;
    --version=*)
        PANELALPHA_ENGINE_VERSION="${1#*=}"
        shift
        ;;
    -d | --debug)
        DEBUG_MODE=1
        shift
        ;;
    -host | --hostname)
        PANELALPHA_HOST="$2"
        shift
        shift
        ;;
    -p | --package-host)
        PACKAGE_HOST="$2"
        shift
        shift
        ;;
    --package-host=*)
        PACKAGE_HOST="${1#*=}"
        shift
        ;;
    --monitoring-host)
        MONITORING_HOST="$2"
        shift
        shift
        ;;
    --monitoring-host=*)
        MONITORING_HOST="${1#*=}"
        shift
        ;;
    # --cert-domain was the old spelling, from when the name was only about
    # the certificate. It still answers, so nothing scripted against it breaks.
    --domain | --cert-domain)
        DOMAIN="$2"
        shift
        shift
        ;;
    --cert-email)
        CERT_EMAIL="$2"
        shift
        shift
        ;;
    --cert-email=*)
        CERT_EMAIL="${1#*=}"
        shift
        ;;
    --email)
        INSTALL_EMAIL="$2"
        shift
        shift
        ;;
    --email=*)
        INSTALL_EMAIL="${1#*=}"
        shift
        ;;
    --repo)
        DEPLOY_REPO="$2"
        shift
        shift
        ;;
    --repo=*)
        DEPLOY_REPO="${1#*=}"
        shift
        ;;
    --branch)
        DEPLOY_BRANCH="$2"
        shift
        shift
        ;;
    --branch=*)
        DEPLOY_BRANCH="${1#*=}"
        shift
        ;;
    --git-token)
        DEPLOY_GIT_TOKEN="$2"
        shift
        shift
        ;;
    --git-token=*)
        DEPLOY_GIT_TOKEN="${1#*=}"
        shift
        ;;
    --no-cert-request)
        NO_CERT_REQUEST=1
        shift
        ;;
    --no-local-ip)
        NO_LOCAL_IP=1
        shift
        ;;
    --enable-nat)
        ENABLE_NAT=1
        shift
        ;;
    --in-container)
        IN_CONTAINER=1
        shift
        ;;
    --no-sysbox)
        INSTALL_SYSBOX=0
        shift
        ;;
    --no-hardening)
        HARDEN=0
        shift
        ;;
    --no-upgrade)
        UPGRADE=0
        shift
        ;;
    --dind-runtime)
        DIND_RUNTIME="$2"
        shift
        shift
        ;;
    --mtu)
        DOCKER_NETWORK_MTU="$2"
        shift
        shift
        ;;
    --configure)
        CONFIGURE_MODE=1
        shift
        ;;
    --run-dir)
        RUN_DIR="$2"
        shift
        shift
        ;;
    --run-dir=*)
        RUN_DIR="${1#*=}"
        shift
        ;;
    --software-op)
        ENGINE_OP="$2"
        shift
        shift
        ;;
    --software-op=*)
        ENGINE_OP="${1#*=}"
        shift
        ;;
    # Flags forwarded by the TUI wrapper — accept and ignore.
    --software | --entry | --self-update-url | --get-base)
        shift
        shift
        ;;
    --software=* | --entry=* | --self-update-url=* | --get-base=* | --background | --no-tui | --no-self-update | --no-script-update | --attach | --ask-email)
        shift
        ;;
    -h | --help)
        usage
        ;;
    --)
        shift
        break
        ;;
    "") break ;;
    -*)
        echo "Unknown option: $1" >&2
        trap - EXIT  # the EXIT trap would also report the failing command
        exit 1
        ;;
    *) break ;;
    esac
done

# Sysbox needs host daemons and cannot nest, so accounts run privileged; nested
# daemons need the lower MTU when ICMP is filtered. See docs/internal/engine-container.md.
if [ "$IN_CONTAINER" = 1 ]; then
    : "${INSTALL_SYSBOX:=0}"
    : "${HARDEN:=0}"
    : "${DIND_RUNTIME:=privileged}"
    : "${DOCKER_NETWORK_MTU:=1400}"
fi
: "${INSTALL_SYSBOX:=1}"
: "${HARDEN:=1}"
: "${UPGRADE:=1}"
: "${DOCKER_NETWORK_MTU:=1500}"

if [ "$DEBUG_MODE" = 1 ]; then
    set -x
fi

echo_info() { echo -e ">>> $green_color$1$default_color"; }
echo_warning() { echo -e ">>> $yellow_color$1$default_color"; }
echo_error() {
    echo -e ">>> $red_color$1$default_color"
    exit 101
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
        echo_error "APP_UID must be 1-128 characters from A-Z a-z 0-9 . _ : - (got '${bad}')"
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

# Report install/update outcome to monitoring (Engine emails / probes).
send_update_status() {
    local exit_code=${1:-0}
    local finished_at started_at software_op email error_msg last_cmd event_type
    started_at=${STARTED_AT:-$(date +%s || echo 0)}
    finished_at=$(date +%s || echo 0)
    software_op="${ENGINE_OP:-install}"
    if [ "$software_op" != "update" ]; then
        software_op="install"
    fi
    if [ "$software_op" = "update" ]; then
        event_type="panel.update"
    else
        event_type="panel.install"
    fi
    email="${INSTALL_EMAIL:-}"
    error_msg=""
    if [ "$exit_code" != "0" ]; then
        last_cmd="${last_command:-${current_command:-}}"
        if [ -n "$last_cmd" ]; then
            error_msg="command failed (exit ${exit_code}): ${last_cmd}"
        else
            error_msg="installer failed with exit_code=${exit_code}"
        fi
        if [ -n "$RUN_DIR" ] && [ -f "$RUN_DIR/stderr" ]; then
            error_msg="${error_msg}; $(tail -n 5 "$RUN_DIR/stderr" 2>/dev/null | tr '\n' ' ' | head -c 500)"
        fi
    fi
    {
        jq -n \
            --arg type "$event_type" \
            --arg occurred_at "$(date -u +%Y-%m-%dT%H:%M:%SZ 2>/dev/null || echo "")" \
            --arg started_at "$started_at" \
            --arg finished_at "$finished_at" \
            --arg exit_code "$exit_code" \
            --arg software "engine" \
            --arg software_op "$software_op" \
            --arg email "$email" \
            --arg error "$error_msg" \
            --arg to_version "${PANELALPHA_ENGINE_VERSION:-}" \
            '{events:[{
              type:$type,
              occurred_at:$occurred_at,
              payload:{
                started_at:$started_at,
                finished_at:$finished_at,
                exit_code:$exit_code,
                software:$software,
                software_op:$software_op,
                email:$email,
                error:$error,
                to_version:$to_version
              }
            }]}' \
        | curl -4 -sS -X POST "https://${MONITORING_HOST}/api/v1/events" \
            -H "Content-Type: application/json" \
            -H "Accept: application/json" \
            -H "User-Agent: PanelAlpha-Engine/installer" \
            ${APP_UID:+-H} ${APP_UID:+"X-Engine-App-UID: ${APP_UID}"} \
            -d @- >/dev/null 2>&1 || true
    } || true
}

redact_repo_url() {
    printf '%s' "$1" | sed -E 's#(https?://)[^/@]+@#\1***@#'
}

update_progress() {
    if [ -n "$RUN_DIR" ] && [ -d "$RUN_DIR" ]; then
        echo "$1" >"$RUN_DIR/progress"
        echo "$2" >"$RUN_DIR/step"
    fi
}

# Resolve the git ref / release to install.
resolve_engine_version() {
    if [ -n "$PANELALPHA_ENGINE_VERSION" ]; then
        return
    fi

    if [ -n "$ENGINE_REPO" ]; then
        PANELALPHA_ENGINE_VERSION=main
        echo_info "Installing PanelAlpha Engine ${PANELALPHA_ENGINE_VERSION} from $(redact_repo_url "$ENGINE_REPO")"
        return
    fi

    # Legacy Connect path (ENGINE_REPO explicitly cleared).
    if [ -n "$REPO_TOKEN" ]; then
        PANELALPHA_ENGINE_VERSION="$REPO_REF"
        echo_info "Installing PanelAlpha Engine ${PANELALPHA_ENGINE_VERSION} from ${REPO_PROJECT}"
        return
    fi

    echo_info "Resolving the latest release"
    PANELALPHA_ENGINE_VERSION=$(curl --http1.1 -fsSL --max-time 15 \
        -H "X-Engine-App-UID: ${APP_UID}" "https://${PACKAGE_HOST}/engine-latest" 2>/dev/null | tr -d ' \t\r\n' || true)

    case "$PANELALPHA_ENGINE_VERSION" in
    '' | *[!0-9A-Za-z.-]*)
        echo_warning "Could not resolve the latest release from https://${PACKAGE_HOST}/engine-latest"
        PANELALPHA_ENGINE_VERSION="$FALLBACK_ENGINE_VERSION"
        ;;
    esac

    echo_info "Installing PanelAlpha Engine ${PANELALPHA_ENGINE_VERSION}"
}

define_variables() {
    LOG_DIR="/opt/panelalpha/log"
    mkdir -p $LOG_DIR
    DOWNLOAD_STATUS=''
    TOKEN=''
    ERROR=''
    MSG=''
    PANELALPHA_DIR="/opt/panelalpha"
    PACKAGE_URL="https://${PACKAGE_HOST}/api/engine/download/zip/"
    INSTALL_DIR='/opt/panelalpha/tmp/engine'
}

detect_distro() {
    if [ -f /etc/os-release ]; then
        . /etc/os-release
        OS=$NAME
        VER=$VERSION_ID
    elif type lsb_release >/dev/null 2>&1; then
        OS=$(lsb_release -si)
        VER=$(lsb_release -sr)
    elif [ -f /etc/lsb-release ]; then
        . /etc/lsb-release
        OS=$DISTRIB_ID
        VER=$DISTRIB_RELEASE
    elif [ -f /etc/debian_version ]; then
        OS=Debian
        VER=$(cat /etc/debian_version)
    else
        OS=$(uname -s)
        VER=$(uname -r)
    fi
}

check_root() {
    if [ "$EUID" -ne 0 ]; then
        echo_error "Please run as root!"
    fi
}

# Installed product version (artisan → version file → config/system.php). Empty if unknown.
detect_installed_engine_version() {
    local compose="${PANELALPHA_DIR}/shared-hosting/docker-compose.yml"
    local ver_file="${PANELALPHA_DIR}/shared-hosting/version"
    local php_cfg="${PANELALPHA_DIR}/shared-hosting/core/config/system.php"
    local ver=""

    if [[ -f "$compose" ]]; then
        ver=$(docker compose -f "$compose" exec -T core php artisan system:version 2>/dev/null | tr -d '\r\n' || true)
    fi
    if [[ -z "$ver" || "$ver" == "unknown" ]] && [[ -f "$ver_file" ]]; then
        ver=$(tr -d '\r\n' <"$ver_file" || true)
    fi
    if [[ -z "$ver" || "$ver" == "unknown" ]] && [[ -f "$php_cfg" ]]; then
        ver=$(sed -nE "s/.*'version'[[:space:]]*=>[[:space:]]*'([^']+)'.*/\1/p" "$php_cfg" | head -n1 || true)
    fi
    if [[ "$ver" == "unknown" ]]; then
        ver=""
    fi
    printf '%s' "$ver"
}

# Temporary: get.* / this installer must not in-place upgrade Engine 1.0.x → 2.x.
refuse_engine_v1_to_v2_upgrade() {
    if [[ ! -f "${PANELALPHA_DIR}/shared-hosting/docker-compose.yml" ]]; then
        return 0
    fi

    local ver major
    ver=$(detect_installed_engine_version)
    major="${ver%%.*}"

    if [[ -n "$ver" && "$major" =~ ^[0-9]+$ && "$major" -ge 2 ]]; then
        return 0
    fi

    echo -e ">>> ${red_color}In-place upgrade from PanelAlpha Engine 1.0 to 2.0 is not supported.${default_color}"
    if [[ -n "$ver" ]]; then
        echo -e ">>> ${red_color}Detected installed version: ${ver}${default_color}"
    else
        echo -e ">>> ${red_color}Could not determine the installed Engine version.${default_color}"
        echo -e ">>> ${red_color}If this host is already on Engine 2.x, start the containers and retry.${default_color}"
    fi
    echo -e ">>> ${red_color}Keep Engine 1.0 on the legacy updater from license.panelalpha.com:${default_color}"
    echo -e ">>> ${yellow_color}  wget -N -P /opt/panelalpha https://license.panelalpha.com/engine-updater.sh${default_color}"
    echo -e ">>> ${yellow_color}  bash /opt/panelalpha/engine-updater.sh --key 'YOUR_LICENSE_KEY'${default_color}"
    echo -e ">>> ${red_color}The get.panelalpha.com/engine command is for fresh installs and hosts already on Engine 2.x.${default_color}"
    echo -e ">>> ${red_color}See product documentation for Engine 1.0 and 2.0 install paths.${default_color}"
    echo_error "Refusing Engine 1.0 → 2.0 in-place upgrade"
}

before_install() {
    echo_info "Updating repositories"

    apt-get -o DPkg::Lock::Timeout=300 update -y
    if [ "$UPGRADE" = 1 ]; then
        apt-get -o DPkg::Lock::Timeout=300 upgrade -y
        apt-get -o DPkg::Lock::Timeout=300 autoremove -y
    else
        echo_warning "Skipping apt-get upgrade (--no-upgrade)"
    fi
    apt-get -o DPkg::Lock::Timeout=300 update --fix-missing -y
    apt-get -o DPkg::Lock::Timeout=300 install jq unzip lsb-release apt-transport-https lsb-release ca-certificates curl ipcalc quota at -y

    detect_distro

    echo_info "Detecting Linux Distribution"

    case $OS' '$VER in
    *"Debian GNU/Linux 12"*)
        echo_warning "Debian distribution detected: $OS $VER"
        SYSTEM='debian'
        ;;
    *"Debian GNU/Linux 13"*)
        echo_warning "Debian distribution detected: $OS $VER"
        SYSTEM='debian'
        ;;
    *"Ubuntu 22.04"*)
        echo_warning "Ubuntu distribution detected: $OS $VER"
        SYSTEM='ubuntu'
        ;;
    *"Ubuntu 24.04"*)
        echo_warning "Ubuntu distribution detected: $OS $VER"
        SYSTEM='ubuntu'
        ;;
    *"Ubuntu 26.04"*)
        echo_warning "Ubuntu distribution detected: $OS $VER"
        SYSTEM='ubuntu'
        ;;
    *)
        echo_error "Unsupported operating system. Supported systems: Debian GNU/Linux 12/13, Ubuntu 22.04/24.04/26.04. Detected system: $OS $VER"
        ;;
    esac

    echo_info "Preparing Installation Script"

    check_root
}

get_hostname() {
    if [ -z "$PANELALPHA_HOST" ]; then
        PANELALPHA_HOST=$(hostname -I | cut -d' ' -f1)
        if [ "$PANELALPHA_HOST" = "" ]; then
            PANELALPHA_HOST=$(hostname 2>/dev/null)
            if [ "$PANELALPHA_HOST" = "" ]; then
                PANELALPHA_HOST=$(curl --http1.1 -s icanhazip.com)
            fi
        fi
    fi

    if [ "$PANELALPHA_HOST" = "" -o "$PANELALPHA_HOST" = "(none)" ]; then
        echo_error "Unable to determine the hostname of your system!. Please consult the documentation for your system."
    fi

    echo_info "Found hostname: $PANELALPHA_HOST"
    if [ -z "$DOMAIN" ] && [[ "$PANELALPHA_HOST" == *.* ]] && ! echo "$PANELALPHA_HOST" | grep -qE '^[0-9a-fA-F:.]+$'; then
        DOMAIN="$PANELALPHA_HOST"
        echo_info "The Let's Encrypt certificate will be requested for ${DOMAIN}"
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
        echo_error "Could not obtain a download token. $MSG"
    fi

    if [ "$DOWNLOAD_STATUS" != "Reissued" ] && [ "$DOWNLOAD_STATUS" != "Created" ] && [ "$DOWNLOAD_STATUS" != "Active" ]; then
        echo_error "Invalid download status: $DOWNLOAD_STATUS."
    fi
}

install_docker_engine() {
    # install docker engine
    # https://docs.docker.com/engine/install/debian/#install-docker-engine
    apt-get -o DPkg::Lock::Timeout=300 update -y
    apt-get -o DPkg::Lock::Timeout=300 install ca-certificates curl gnupg -y
    mkdir -m 0755 -p /etc/apt/keyrings
    curl --http1.1 -fsSL https://download.docker.com/linux/$SYSTEM/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg --yes
    # chmod a+r /etc/apt/keyrings/docker.gpg
    echo \
        "deb [arch="$(dpkg --print-architecture)" signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/$SYSTEM \
    "$(. /etc/os-release && echo "$VERSION_CODENAME")" stable" |
        tee /etc/apt/sources.list.d/docker.list >/dev/null
    apt-get -o DPkg::Lock::Timeout=300 update -y
    apt-get -o DPkg::Lock::Timeout=300 install docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin -y
}

# Git URL for the engine tree. Prefer PANELALPHA_ENGINE_REPO (credentials may be
# embedded). Empty string forces the legacy Connect package path.
download_engine_from_repository() {
    local safe
    safe=$(redact_repo_url "$ENGINE_REPO")
    echo_warning "Please wait, cloning ${safe} (ref ${PANELALPHA_ENGINE_VERSION})..."
    mkdir -p "$INSTALL_DIR"
    rm -rf "$INSTALL_DIR/src"

    command -v git >/dev/null 2>&1 || apt-get -o DPkg::Lock::Timeout=300 install git -y

    export GIT_TERMINAL_PROMPT=0
    if ! git clone --depth 1 --branch "$PANELALPHA_ENGINE_VERSION" \
        "$ENGINE_REPO" "$INSTALL_DIR/src" >/dev/null 2>&1; then
        echo_error "Could not clone ${safe} (ref ${PANELALPHA_ENGINE_VERSION})"
    fi
    rm -rf "$INSTALL_DIR/src/.git"
    echo_info "Success! Package has been downloaded"
}

# What the release package ships prebuilt and a repository archive does not.
install_composer_dependencies() {
    resolve_composer_image "$PANELALPHA_DIR/shared-hosting/core" "$PANELALPHA_DIR/shared-hosting"
    docker run --rm -v "$PANELALPHA_DIR/shared-hosting/core:/app" -w /app \
        "$COMPOSER_IMAGE" composer install --no-interaction --no-progress
}

download_panelalpha_engine() {
    if [ -n "$ENGINE_REPO" ]; then
        download_engine_from_repository
        return
    fi

    # Legacy: REPO_TOKEN + GitLab API archive (older callers).
    if [ -n "$REPO_TOKEN" ]; then
        echo_warning "Please wait, package is downloading..."
        mkdir -p "$INSTALL_DIR"
        rm -rf "$INSTALL_DIR/src"
        local encoded=${REPO_PROJECT//\//%2F}
        local url="https://${REPO_HOST}/api/v4/projects/${encoded}/repository/archive.zip?sha=${PANELALPHA_ENGINE_VERSION}"
        local status
        status=$(curl --http1.1 -sS -o "$INSTALL_DIR/app.zip" -w '%{http_code}' \
            --header "PRIVATE-TOKEN: ${REPO_TOKEN}" "$url" || echo 000)
        if [ "$status" -eq 200 ]; then
            echo_info "Success! Package has been downloaded"
            return
        fi
        rm -f "$INSTALL_DIR/app.zip"
        echo_warning "Archive download failed (HTTP ${status}); cloning ${REPO_PROJECT} instead"
        command -v git >/dev/null 2>&1 || apt-get -o DPkg::Lock::Timeout=300 install git -y
        GIT_TERMINAL_PROMPT=0 \
            GIT_CONFIG_COUNT=1 \
            GIT_CONFIG_KEY_0="http.https://${REPO_HOST}/.extraheader" \
            GIT_CONFIG_VALUE_0="Authorization: Basic $(printf 'oauth2:%s' "$REPO_TOKEN" | base64 | tr -d '\n')" \
            git clone --depth 1 --branch "$PANELALPHA_ENGINE_VERSION" \
            "https://${REPO_HOST}/${REPO_PROJECT}.git" "$INSTALL_DIR/src" ||
            echo_error "Could not get ${REPO_PROJECT} at ${PANELALPHA_ENGINE_VERSION} from ${REPO_HOST}"
        rm -rf "$INSTALL_DIR/src/.git"
        echo_info "Success! Package has been downloaded"
        return
    fi

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
            echo $CURL_PACKAGE_RESULTS
            STATUS=$(echo $CURL_PACKAGE_RESULTS | jq '.status' --raw-output)
            MESSAGE=$(echo $CURL_PACKAGE_RESULTS | jq '.message' --raw-output)

            if [ $STATUS == 'error' ]; then
                echo_error "Error has been occurred. $MESSAGE"
            fi
        fi
        sleep 5
    done

    if [ ! -f "$INSTALL_DIR"/app.zip ]; then
        echo_error "Error has been occurred. Cannot find package. It should be in path: $INSTALL_DIR/app.zip"
    fi
}

unzip_panelalpha_engine() {
    # A clone from PANELALPHA_ENGINE_REPO (or REPO_TOKEN clone fallback).
    # --preserve=mode also repairs files an earlier run left with the wrong mode;
    # a plain cp keeps the existing file's mode.
    if [ -d "$INSTALL_DIR/src" ]; then
        cp -Rf --preserve=mode "$INSTALL_DIR/src/." "$PANELALPHA_DIR/shared-hosting/"
        return
    fi

    if [ -n "$REPO_TOKEN" ] && [ -z "$ENGINE_REPO" ]; then
        # A repository archive wraps the tree in one commit-named directory; the
        # install expects the tree itself.
        unzip -o -q "$INSTALL_DIR"/app.zip -d "$INSTALL_DIR/src"
        local top
        top=$(find "$INSTALL_DIR/src" -mindepth 1 -maxdepth 1 -type d | head -n 1)
        [ -n "$top" ] || echo_error "The downloaded archive is empty"
        cp -Rf --preserve=mode "$top/." "$PANELALPHA_DIR/shared-hosting/"
        return
    fi

    unzip -o "$INSTALL_DIR"/app.zip -d "$PANELALPHA_DIR/shared-hosting" >/dev/null
}

generate_ssl_cert() {
    mkdir -p /opt/panelalpha/shared-hosting/crt
    CERT_IP=$(ip route get 8.8.8.8 | sed -n '/src/{s/.*src *\([^ ]*\).*/\1/p;q}')
    if ipcalc "$CERT_IP" | grep -q 'Private Internet' && [ "$NO_LOCAL_IP" = 1 ]; then
        CERT_IP=$(curl -s4 icanhazip.com)
    fi
    # The address has to appear in the SAN list, not just the CN: Node (so Claude
    # Code) and Go both verify the URL host against the SAN and reject a cert
    # that names it only in the CN. Without this, no client can reach the engine
    # over the self-signed cert except by disabling verification entirely.
    CERT_SAN="IP:${CERT_IP},IP:127.0.0.1,DNS:localhost"
    if [ ! -z "${PANELALPHA_HOST}" ] && ! echo "${PANELALPHA_HOST}" | grep -qE '^[0-9a-fA-F:.]+$'; then
        CERT_SAN="${CERT_SAN},DNS:${PANELALPHA_HOST}"
    fi
    openssl req -new -x509 -days 365 -nodes -out /opt/panelalpha/shared-hosting/crt/server.cert -keyout /opt/panelalpha/shared-hosting/crt/server.key -subj "/C=US/ST=ST/L=L/O=O/OU=Org/CN=${CERT_IP}" -addext "subjectAltName=${CERT_SAN}"
}

# Every artisan call talks to the core database, and it is not always ready when
# we ask: MySQL is still running initdb right after 'up -d', and any Docker
# restart takes the whole stack down with it. Bounded, so a stack that never
# comes up fails the run instead of hanging on it forever.
wait_for_database() {
    local timeout=${1:-600}
    local waited=0
    while ! docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T core php artisan system:database:test 2>/dev/null | grep -q "Test successful"; do
        if [ "$waited" -ge "$timeout" ]; then
            echo_error "The core database did not become reachable within ${timeout}s"
        fi
        echo "Waiting for the core database..."
        sleep 5
        waited=$((waited + 5))
    done
}

set_default_ip() {
    wait_for_database
    if docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T core php artisan settings:exists default_ipv4; then
        echo "Default IPv4 already set"
    else
        IPV4=$(ip route get 8.8.8.8 | sed -n '/src/{s/.*src *\([^ ]*\).*/\1/p;q}')
        if ipcalc $IPV4 | grep -q 'Private Internet' && [ "$NO_LOCAL_IP" = 1 ]; then
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
    cp -n /opt/panelalpha/shared-hosting/docker-compose.yml-nginx-proxy /opt/panelalpha/shared-hosting/docker-compose.yml-webserver
    mkdir -p /opt/panelalpha/shared-hosting/config/pure-ftpd
    cp -Rn /opt/panelalpha/shared-hosting/templates/config/pure-ftpd/. /opt/panelalpha/shared-hosting/config/pure-ftpd/.
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
    cp -Rn /opt/panelalpha/shared-hosting/templates/config/logrotate/. /opt/panelalpha/shared-hosting/config/logrotate/.
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

# Host hardening, and the reason it runs before the stack does: csf.sh rebuilds
# the whole iptables ruleset, which drops the chains the Docker daemon installs
# at start, so the daemon has to be restarted afterwards. Doing that with the
# stack up takes every container down with it — which is how the install used to
# fail, on the first artisan call after the restart. It needs .env, the compose
# bridge and docker0 in place, so the earliest safe point is right before 'up'.
harden_host() {
    if [ "$HARDEN" != 1 ]; then
        echo_warning "Skipping sysctl, monit and CSF (--no-hardening)"
        return
    fi

    bash /opt/panelalpha/shared-hosting/scripts/configure-sysctl.sh
    bash /opt/panelalpha/shared-hosting/scripts/configure-monit.sh
    bash /opt/panelalpha/shared-hosting/scripts/csf.sh --install
    # This is the fourth Docker restart in a minute or so (install, sysbox,
    # daemon DNS, CSF); docker.service allows three, and on a fast host the
    # fourth fails with start-limit-hit although the daemon stopped cleanly.
    systemctl reset-failed docker.service 2>/dev/null || true
    service docker restart
}

remove_renamed_containers() {
    for old in nginx cron database-core webserver database-users phpmyadmin-users dns-proxy exim pure-ftpd redis core-redis queue-worker core-queue core-cron core-http; do
        ids=$(docker ps -aq \
            --filter "label=com.docker.compose.project=shared-hosting" \
            --filter "label=com.docker.compose.service=${old}" 2>/dev/null || true)
        if [ -n "$ids" ]; then
            echo_info "Removing container from the pre-rename stack: ${old}"
            # shellcheck disable=SC2086
            docker rm -f $ids >/dev/null 2>&1 || true
        fi
    done
}

install_panelalpha_engine() {

    PUBLIC_HOST=$(ip route get 8.8.8.8 | sed -n '/src/{s/.*src *\([^ ]*\).*/\1/p;q}')
    if ipcalc "$PUBLIC_HOST" | grep -q 'Private Internet' && [ "$NO_LOCAL_IP" = 1 ]; then
        PUBLIC_HOST=$(curl -s4 icanhazip.com)
    fi
    CORE_URL="https://${PUBLIC_HOST}:2011"
    API_URL="${CORE_URL}/api"
    # The MCP server is mounted at the root, not under /api - see routes/mcp.php.
    MCP_URL="${CORE_URL}/mcp"

    # create .env file based on example, only if not exists
    cp -n /opt/panelalpha/shared-hosting/.env.example /opt/panelalpha/shared-hosting/.env

    # Optional services sit behind compose profiles, so an .env without
    # COMPOSE_PROFILES would bring up the control plane alone. Backfill 'full'
    # on any .env written before profiles existed -- that is the pre-profile
    # stack, unchanged. An operator who has deliberately trimmed the list keeps
    # their value.
    if ! grep -q '^COMPOSE_PROFILES=' /opt/panelalpha/shared-hosting/.env; then
        if [ -n "$(tail -c1 /opt/panelalpha/shared-hosting/.env)" ]; then
            echo "" >>/opt/panelalpha/shared-hosting/.env
        fi
        echo "COMPOSE_PROFILES=full" >>/opt/panelalpha/shared-hosting/.env
    fi

    # core's own data lives in core.sqlite by default. An operator who put
    # legacy-mysql-core in COMPOSE_PROFILES above (hand-edited .env before
    # running this installer) gets core-db instead -- same profile
    # int-updater.sh uses to keep an existing host on MySQL, so this is the
    # one place both paths land the connection vars docker-compose.yml's
    # core/metrics services read. See docs/internal/core-db.md.
    CORE_PROFILES=$(grep '^COMPOSE_PROFILES=' /opt/panelalpha/shared-hosting/.env | cut -d '=' -f2-)
    case ",${CORE_PROFILES}," in
    *,legacy-mysql-core,*)
        if ! grep -q '^CORE_DB_CONNECTION=' /opt/panelalpha/shared-hosting/.env; then
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

        # core-db's own password (MYSQL_PASSWORD in its compose environment,
        # unrelated to USERS_MYSQL_ROOT_PASSWORD below). An existing legacy
        # host already has one from before this profile existed; a fresh
        # install choosing this profile needs one generated, the same way
        # this always worked before core.sqlite existed.
        CORE_MYSQL_PASSWORD=$(grep ^CORE_MYSQL_PASSWORD= /opt/panelalpha/shared-hosting/.env | cut -d '=' -f2-)
        if [ -z "${CORE_MYSQL_PASSWORD}" ]; then
            CORE_MYSQL_PASSWORD=$(random-string 12)
            sed -i 's/CORE_MYSQL_PASSWORD=/CORE_MYSQL_PASSWORD='$CORE_MYSQL_PASSWORD'/' /opt/panelalpha/shared-hosting/.env
        fi
        ;;
    esac

    # generate users mysql root password if not set
    USERS_MYSQL_ROOT_PASSWORD=$(grep ^USERS_MYSQL_ROOT_PASSWORD= /opt/panelalpha/shared-hosting/.env | cut -d '=' -f2-)
    if [ -z "${USERS_MYSQL_ROOT_PASSWORD}" ]; then
        USERS_MYSQL_ROOT_PASSWORD=$(random-string 12)
        sed -i 's/USERS_MYSQL_ROOT_PASSWORD=/USERS_MYSQL_ROOT_PASSWORD='$USERS_MYSQL_ROOT_PASSWORD'/' /opt/panelalpha/shared-hosting/.env
    fi

    # create .env-core files based on example, only if not exists
    cp -n /opt/panelalpha/shared-hosting/.env-core.example /opt/panelalpha/shared-hosting/.env-core

    # set APP_URL in .env-core if not set
    CORE_APP_URL=$(grep ^APP_URL= /opt/panelalpha/shared-hosting/.env-core | cut -d '=' -f2-)
    if [ -z "${CORE_APP_URL}" ]; then
        sed -i 's#APP_URL=#APP_URL='$CORE_URL'#' /opt/panelalpha/shared-hosting/.env-core
    fi

    # Generate the Laravel key before the stack starts. Queue workers read the
    # empty APP_KEY at boot and keep it, so a key written after
    # `up -d` never reaches them until core restarts.
    if ! grep -q '^APP_KEY=.\+' /opt/panelalpha/shared-hosting/.env-core; then
        APP_KEY="base64:$(head -c 32 /dev/urandom | base64 | tr -d '\n')"
        if grep -q '^APP_KEY=' /opt/panelalpha/shared-hosting/.env-core; then
            sed -i "s|^APP_KEY=.*|APP_KEY=${APP_KEY}|" /opt/panelalpha/shared-hosting/.env-core
        else
            if [ -n "$(tail -c1 /opt/panelalpha/shared-hosting/.env-core)" ]; then
                echo "" >>/opt/panelalpha/shared-hosting/.env-core
            fi
            echo "APP_KEY=${APP_KEY}" >>/opt/panelalpha/shared-hosting/.env-core
        fi
    fi

    persist_app_uid

    # how account containers are isolated; empty leaves the app default (sysbox)
    if [ -n "$DIND_RUNTIME" ]; then
        if grep -q '^DIND_RUNTIME=' /opt/panelalpha/shared-hosting/.env-core; then
            sed -i "s#^DIND_RUNTIME=.*#DIND_RUNTIME=${DIND_RUNTIME}#" /opt/panelalpha/shared-hosting/.env-core
        else
            # a hand-edited .env-core may not end in a newline
            if [ -n "$(tail -c1 /opt/panelalpha/shared-hosting/.env-core)" ]; then
                echo "" >>/opt/panelalpha/shared-hosting/.env-core
            fi
            echo "DIND_RUNTIME=${DIND_RUNTIME}" >>/opt/panelalpha/shared-hosting/.env-core
        fi
        echo_warning "Account containers will use DIND_RUNTIME=${DIND_RUNTIME}"
    fi

    prepare_config_files

    bash /opt/panelalpha/shared-hosting/scripts/update-cloudflare-ips.sh || true

    # create docker network if not exists
    docker network ls | grep pash-default-network || docker network create pash-default-network --opt com.docker.network.driver.mtu=${DOCKER_NETWORK_MTU}

    # make sure systemd-resolved is disabled / no conflicts with sites-dns
    disable_systemd_resolved || true

    # firewall the host before anything listens on it
    harden_host

    # Compose service keys were renamed (nginx -> core-http, webserver ->
    # sites-http, cron -> core-cron, and so on). Containers created under the old
    # keys are orphans of this project: `docker compose down --remove-orphans`
    # clears them, which is what scripts/int-updater.sh does -- but installer.sh
    # never runs `down`, so on a re-run over an existing install the old
    # host-networked `webserver` would still hold :80/:443 against the new
    # sites-http. Drop the old containers by service label before starting.
    remove_renamed_containers

    # run docker stack
    docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml up -d

    # run database migrations
    wait_for_database
    docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T core php artisan migrate --force
    if [ "$ENABLE_NAT" = 1 ]; then
        docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T core php artisan system:nat:build --replace-default-ipv4 || true
    fi
}

clean_installer() {
    rm -rf "$INSTALL_DIR"
}

# The engine's served certificate, crt/server.cert on :2011.
#
# With --domain it is a Let's Encrypt certificate for that name, and the
# short-lived IP lineage is kept beside it unserved as the fallback. Without
# one it is a Let's Encrypt certificate for the host's public address, and no
# name is requested at all -- see docs/internal/engine-tls.md. --no-cert-request asks
# for neither.
#
# The name is a core setting either way, so `pae-artisan ssl:engine-cert:request`
# and the renewal cron keep using it.
request_certificates() {
    if [ -n "$DOMAIN" ]; then
        docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T core php artisan settings:set cert_domain "$DOMAIN" >/dev/null \
            || echo_warning "Could not record the cert_domain setting"
    fi
    if [ -n "$CERT_EMAIL" ]; then
        docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T core php artisan settings:set cert_email "$CERT_EMAIL" >/dev/null \
            || echo_warning "Could not record the cert_email setting"
    fi

    if [ "$NO_CERT_REQUEST" = 1 ]; then
        if [ -n "$DOMAIN" ]; then
            echo_info "Not requesting a certificate (--no-cert-request). Point ${DOMAIN} at this host, then:"
            echo_info "  pae-artisan ssl:engine-cert:request"
        else
            echo_info "Not requesting a certificate (--no-cert-request); staying on the self-signed one."
            echo_info "  pae-artisan ssl:engine-cert:request --domain panel.example.com"
        fi

        return
    fi

    # Resolve the address here: set_default_ip is the only other writer of
    # $IPV4, and an unset one tests as public. A private address means Let's
    # Encrypt cannot reach :80 -- unless --no-local-ip says the public one
    # forwards here, or the administrator named a domain that does.
    IPV4=$(ip route get 8.8.8.8 | sed -n '/src/{s/.*src *\([^ ]*\).*/\1/p;q}')
    local private=0
    if [ -z "$IPV4" ] || ipcalc "$IPV4" | grep -q 'Private Internet'; then
        private=1
        if [ "$NO_LOCAL_IP" = 1 ]; then
            IPV4=$(curl -s4 --max-time 10 icanhazip.com || true)
            [ -n "$IPV4" ] && ! ipcalc "$IPV4" | grep -q 'Private Internet' && private=0
        fi
    fi
    if [ "$private" = 1 ] && [ -z "$DOMAIN" ]; then
        echo_warning "Skipping Let's Encrypt for ${IPV4:-an unknown address}: not a public address, and no --domain"
        return
    fi

    # No name was asked for, so the address is the name. A certificate for the
    # bare IP is issued under no registered domain at all, which is the point:
    # the .panelalpha.direct default spends an allowance of 50 new
    # certificates per week that every engine in the fleet draws on, and it
    # spends it on an installation that has not yet been told what it is
    # called. An administrator who wants a name requests one, and that is when
    # the domain certificate is worth issuing.
    #
    # The cost is the lifetime: Let's Encrypt issues IP certificates only
    # under the short-lived profile, about six days, so the six-hourly renewal
    # is not optional the way it is for a 90-day certificate.
    if [ -z "$DOMAIN" ]; then
        if [ "$private" = 0 ]; then
            if bash /opt/panelalpha/shared-hosting/scripts/letsencrypt-request-ip-cert.sh "$IPV4" --install; then
                ENGINE_CERT_IP="$IPV4"
            else
                echo_warning "Could not obtain the Let's Encrypt IP certificate; staying on the self-signed one"
            fi
        fi
    else
        # An administrator named the engine, so the certificate is for that
        # name. The IP certificate is still obtained and kept beside it,
        # unserved, as the fallback when the name cannot be validated.
        if [ "$private" = 0 ]; then
            bash /opt/panelalpha/shared-hosting/scripts/letsencrypt-request-ip-cert.sh "$IPV4" \
                || echo_warning "Could not obtain the Let's Encrypt IP certificate"
        fi

        # The script detects the public address itself when none is passed.
        local ip_arg=''
        [ "$private" = 0 ] && ip_arg="--ip=${IPV4}"
        if bash /opt/panelalpha/shared-hosting/scripts/letsencrypt-request-cert.sh $ip_arg; then
            ENGINE_CERT_DOMAIN=$(docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T core php artisan settings:get cert_domain 2>/dev/null | tr -d '\r' | tail -n 1)
        else
            echo_warning "Could not obtain a Let's Encrypt certificate for ${DOMAIN}"
            if [ "$private" = 0 ] && [ -f /etc/letsencrypt/live/panelalpha-engine-ip-cert/fullchain.pem ]; then
                bash /opt/panelalpha/shared-hosting/scripts/letsencrypt-request-ip-cert.sh "$IPV4" --install \
                    && ENGINE_CERT_IP="$IPV4" \
                    || echo_warning "Staying on the self-signed certificate"
            else
                echo_warning "Staying on the self-signed certificate"
            fi
        fi
    fi

    # The script moved APP_URL onto the certificate's name; the URLs printed
    # at the end follow it too.
    local served="${ENGINE_CERT_DOMAIN:-$ENGINE_CERT_IP}"
    if [ -n "$served" ]; then
        CORE_URL="https://${served}:2011"
        API_URL="${CORE_URL}/api"
        MCP_URL="${CORE_URL}/mcp"
    fi
}

render_webserver_config() {
    # The engine's own addresses are known now, so render the real webserver
    # config while nothing is hosted yet. Left to the first project, this render
    # swaps the shipped wildcard `listen 80` for `listen <ip>:80` underneath a
    # running nginx -- which cannot rebind on a reload (EADDRINUSE against its
    # own wildcard socket), keeps serving the config it booted with, and reports
    # success while no vhost ever loads.
    docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T core php artisan system:domain:rebuild \
        || echo_warning "Could not rebuild the webserver configuration"
    # Restart, not reload: nginx is already holding the wildcard sockets, and
    # only a restart can move it onto the address-specific ones. Free here --
    # nothing is being served yet.
    docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml restart sites-http \
        || echo_warning "Could not restart the webserver"
}

post_install_config() {
    # Hardening ran before the stack came up, in harden_host.
    set_default_ip
    # Before request_certificates: an ACME HTTP-01 challenge is answered through
    # this webserver, so take its restart before any challenge is in flight.
    render_webserver_config
    # After set_default_ip: the certificate name is a core setting, so the
    # database has to answer first.
    request_certificates
    bash /opt/panelalpha/shared-hosting/scripts/pae-command.sh register || echo_warning "Could not install the pae command"
    if [ -n "$INSTALL_EMAIL" ]; then
        docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T core php artisan settings:set email "$INSTALL_EMAIL" >/dev/null \
            || echo_warning "Could not record the email setting"
    fi
    # Register notification email + probe URL with monitoring (best-effort).
    docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T core php artisan telemetry:enable >/dev/null 2>&1 \
        || echo_warning "Could not sync telemetry preferences to monitoring"
    # Build the shared PHP base images now, in the background: without this the
    # ~150s per PHP minor is paid by whichever customer deploys that minor first.
    bash /opt/panelalpha/shared-hosting/scripts/prewarm-images.sh || echo_warning "Could not start image prewarm"
}

# owner/repo out of whatever spelling was passed, for the lines an operator
# reads. Credentials in a URL are dropped with the rest of it.
repo_label() {
    printf '%s' "$1" |
        sed -E 's#^[a-z][a-z0-9+.-]*://##; s#^[^/@]+@##; s#\.git$##; s#/+$##' |
        awk -F/ '{ if (NF >= 2) printf "%s/%s", $(NF-1), $NF; else printf "%s", $0 }'
}

# One field out of `project:create --json`. jq where the host has it (the
# installer installs it), a narrow sed where a deploy-only run on an older
# host does not.
json_field() { # json_field <name> <json>
    if command -v jq >/dev/null 2>&1; then
        printf '%s' "$2" | jq -r --arg k "$1" '.data[$k] // empty' 2>/dev/null
        return 0
    fi
    printf '%s' "$2" | sed -n "s/.*\"$1\":\"\\([^\"]*\\)\".*/\\1/p" | head -n1
}

# The repository the one-liner asked for, deployed into a project of its own.
# Everything the project needs beyond the repository -- the account name, the
# domain, the template -- is generated by the API, so nothing is invented here.
#
# A failed deploy is reported, not fatal: on an install the engine itself is up
# and usable, and saying so beats rolling it back over a bad repository URL.
deploy_repository() {
    local args=(project:create "--repo=${DEPLOY_REPO}" --json)
    if [ -n "$DEPLOY_BRANCH" ]; then
        args+=("--branch=${DEPLOY_BRANCH}")
    fi
    if [ -n "$DEPLOY_GIT_TOKEN" ]; then
        args+=("--git-token=${DEPLOY_GIT_TOKEN}")
    fi
    if [ -n "$INSTALL_EMAIL" ]; then
        args+=("--email=${INSTALL_EMAIL}")
    fi

    # stdout only: `project:create --json` keeps the JSON there and puts the
    # deploy log on stderr, so the operator watches the deploy happen while
    # this still captures something parseable.
    local result=''
    if ! result=$(docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T core \
        php artisan "${args[@]}"); then
        DEPLOY_FAILED=1
        echo_warning "Could not deploy ${DEPLOY_REPO_LABEL}:"
        printf '%s\n' "$result" >&2
        return 0
    fi

    # The JSON line out of whatever else the deploy wrote to the terminal.
    local json domain
    json=$(printf '%s\n' "$result" | grep -E '^\{"data":' | tail -n1 || true)
    DEPLOY_PROJECT_NAME=$(json_field username "$json")
    domain=$(json_field domain "$json")
    if [ -z "$DEPLOY_PROJECT_NAME" ] || [ -z "$domain" ]; then
        DEPLOY_FAILED=1
        echo_warning "Deployed ${DEPLOY_REPO_LABEL}, but could not read the project it was deployed into:"
        printf '%s\n' "$result" >&2
        return 0
    fi
    DEPLOY_PROJECT_URL="https://${domain}"
}

# Where the repository ended up — stdout for logs, and the same lines in the
# TUI's ready screen. The outro writers live inside finish_installation, so
# this is called from there and nowhere else.
report_deployed_project() {
    if [ -z "$DEPLOY_REPO" ]; then
        return 0
    fi

    if [ "$DEPLOY_FAILED" = 1 ]; then
        echo_warning "${DEPLOY_REPO_LABEL} was not deployed. Try again with:"
        echo_warning "  pae project:create --repo ${DEPLOY_REPO_LABEL}"
        outro_say 221 "${DEPLOY_REPO_LABEL} was not deployed. Try again with:"
        outro_c 15 "  pae project:create --repo ${DEPLOY_REPO_LABEL}"
        outro_nl
        outro_nl
        return 0
    fi

    echo_info "${DEPLOY_REPO_LABEL} available at: ${DEPLOY_PROJECT_URL}"
    echo_info "Project: ${DEPLOY_PROJECT_NAME}    logs: pae project:deploy:log ${DEPLOY_PROJECT_NAME}"
    echo_info ""
    outro_c 15 "${DEPLOY_REPO_LABEL}"
    outro_c 247 ' available at: '
    outro_c 39 "${DEPLOY_PROJECT_URL}"
    outro_nl
    outro_nl
}

finish_installation() {
    # Stdout: verbose for --no-tui / logs / legacy sed fallback.
    # $RUN_DIR/outro: mockup-shaped ANSI body for the TUI ready screen only
    # (wrapper already draws logo, success line, and 100% bar).
    local outro_tmp=""
    if [ -n "$RUN_DIR" ] && [ -d "$RUN_DIR" ]; then
        outro_tmp="$RUN_DIR/outro.tmp"
        : >"$outro_tmp"
    fi

    outro_c() { # outro_c <256-color> <text>  — no newline
        [ -n "$outro_tmp" ] || return 0
        printf '\033[38;5;%sm%s\033[0m' "$1" "$2" >>"$outro_tmp"
    }
    outro_nl() {
        [ -n "$outro_tmp" ] || return 0
        printf '\n' >>"$outro_tmp"
    }
    outro_say() { # outro_say <color> <text>
        outro_c "$1" "$2"
        outro_nl
    }
    finish_commit() {
        if [ -n "$outro_tmp" ] && [ -f "$outro_tmp" ]; then
            mv -f "$outro_tmp" "$RUN_DIR/outro"
        fi
    }

    echo_info ""
    # Nothing was installed on this run, so the project is the whole report.
    if [ "$DEPLOY_ONLY" = 1 ]; then
        report_deployed_project
        finish_commit
        return
    fi

    if [ "$ENGINE_OP" = update ]; then
        echo_info "PanelAlpha engine has been successfully updated!"
    else
        echo_info "PanelAlpha engine has been successfully installed!"
    fi
    echo_info ""

    report_deployed_project

    echo_info "API URL: ${API_URL}    new token: pae api:token:create default"
    echo_info "MCP URL: ${MCP_URL}    new token: pae mcp:token:create default"
    if [ -n "$ENGINE_CERT_IP" ]; then
        echo_info "TLS: Let's Encrypt for ${ENGINE_CERT_IP}, renewed automatically"
        echo_info "     For a name of your own: pae ssl:engine-cert:request --domain panel.example.com"
    elif [ -n "$ENGINE_CERT_DOMAIN" ]; then
        echo_info "TLS: Let's Encrypt for ${ENGINE_CERT_DOMAIN}, renewed automatically"
        echo_info "     To use your own domain: pae settings:set cert_domain panel.example.com && pae ssl:engine-cert:request"
    else
        echo_info "TLS: Self-signed (crt/server.cert)"
        echo_info "     To request a Let's Encrypt one later: pae ssl:engine-cert:request [--domain panel.example.com]"
    fi
    echo_info "Docs: https://www.panelalpha.com/documentation"
    echo_info ""

    if [ "$ENGINE_OP" = update ]; then
        # Non-empty file so the wrapper skips the legacy sed fallback; chrome only.
        outro_nl
        finish_commit
        return
    fi

    echo_info "AI agents connect: pae connect"
    echo_info ""

    # One MCP token for Claude Code, minted now so the last lines are commands to paste.
    # Keep each command on its own line: `&&` fails in Windows PowerShell 5.1, and
    # joining with a space would turn two commands into one broken argv.
    local connect
    connect=$(docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T core \
        php artisan mcp:connect:claude --bare 2>/dev/null \
        | tr -d '\r' | sed 's/[[:space:]]*$//; /^$/d') || connect=''

    if [ -z "$connect" ]; then
        echo_info "Could not create the Claude Code token. Run this on the server to try again:"
        echo_info "  pae mcp:connect:claude"
        echo_info ""
        outro_say 247 'Could not create the Claude Code token. Run this on the server to try again:'
        outro_say 255 '  pae mcp:connect:claude'
        outro_nl
        finish_commit
        return
    fi

    echo_info "Connect Claude Code — paste this into a terminal on your own computer (contains a token, keep it private):"
    if [ -z "$ENGINE_CERT_IP$ENGINE_CERT_DOMAIN" ]; then
        echo_info "Self-signed certificate: copy crt/server.cert to your computer and start Claude Code"
        echo_info "with NODE_EXTRA_CA_CERTS=/path/to/server.cert, or it will not connect."
    fi
    printf '%s\n' "$connect"
    echo_info ""

    # Mockup Screen 3 body (INSTALLER-SPEC / installer-ui.sh).
    outro_say 255 'Run this in your Claude Code:'
    outro_nl
    outro_c 215 "$connect"
    outro_nl
    outro_nl
    outro_c 221 '! this command contains a token, keep it private. Use '
    outro_c 15 'pae reconnect'
    outro_nl
    outro_say 221 '  to revoke and generate new'
    outro_nl
    outro_c 250 'Want to connect different AI agent? Type: '
    outro_c 15 'pae connect'
    outro_nl
    outro_nl
    finish_commit
}

if [ -n "$RUN_DIR" ] && [ -d "$RUN_DIR" ]; then
    touch "$RUN_DIR/step" "$RUN_DIR/progress"
fi

# TUI wrapper preflight — nothing interactive required for engine today.
if [ "$CONFIGURE_MODE" = 1 ]; then
    trap - EXIT
    exit 0
fi

define_variables
resolve_app_uid

case "$ENGINE_OP" in
install | update) ;;
*)
    if [ -f /opt/panelalpha/shared-hosting/docker-compose.yml ]; then
        ENGINE_OP=update
    else
        ENGINE_OP=install
    fi
    ;;
esac

refuse_engine_v1_to_v2_upgrade

if [ -n "$DEPLOY_REPO" ]; then
    DEPLOY_REPO_LABEL=$(repo_label "$DEPLOY_REPO")
    if [ -f /opt/panelalpha/shared-hosting/docker-compose.yml ]; then
        DEPLOY_ONLY=1
    fi
fi

# An engine is already here, so --repo is a project to create through the CLI,
# not a reason to install the engine over the top of itself.
if [ "$DEPLOY_ONLY" = 1 ]; then
    # before_install, which normally asks, is skipped on this path.
    check_root
    echo_info "PanelAlpha engine is already installed on this host"
    update_progress 50 "Deploying ${DEPLOY_REPO_LABEL}"
    echo_info "Deploying ${DEPLOY_REPO_LABEL}"
    deploy_repository
    update_progress 100 "Finishing"
    finish_installation
    # Neither an install nor an update happened, so there is no install status
    # to report; the trap would send one for a run that changed no engine.
    trap - EXIT
    exit "$DEPLOY_FAILED"
fi

resolve_engine_version

if [ "$ENGINE_OP" = update ]; then
    update_progress 5 "Preparing update"
    echo_info "Preparing update"
else
    update_progress 5 "Preparing installation"
    echo_info "Preparing directories"
fi
before_install

echo_info "Getting server hostname"
get_hostname

if [ -z "$ENGINE_REPO" ] && [ -z "$REPO_TOKEN" ]; then
    update_progress 10 "Requesting the download token"
    echo_info "Requesting the download token"
    request_download_token
fi

update_progress 15 "Installing docker engine"
echo_info "Installing docker engine"
install_docker_engine

update_progress 25 "Download PanelAlpha engine package"
echo_info "Download PanelAlpha engine package"
download_panelalpha_engine

update_progress 35 "Unzip PanelAlpha engine package"
echo_info "Unzip PanelAlpha engine package"
unzip_panelalpha_engine

if [ -n "$ENGINE_REPO" ] || [ -n "$REPO_TOKEN" ]; then
    update_progress 40 "Installing composer dependencies"
    echo_info "Installing composer dependencies"
    install_composer_dependencies
fi

if [ "$INSTALL_SYSBOX" = 1 ]; then
    update_progress 45 "Installing sysbox runtime"
    echo_info "Installing sysbox runtime"
    bash /opt/panelalpha/shared-hosting/scripts/install-sysbox.sh
elif [ "$DIND_RUNTIME" = "privileged" ]; then
    echo_warning "Skipping sysbox runtime — accounts will run privileged"
else
    echo_warning "Skipping sysbox runtime — accounts will not start without it or --dind-runtime privileged"
fi

update_progress 55 "Generating self-signed SSL certificate"
echo_info "Generating self-signed SSL certificate"
generate_ssl_cert

update_progress 70 "Installing PanelAlpha engine"
if [ "$ENGINE_OP" = update ]; then
    echo_info "Updating PanelAlpha engine"
else
    echo_info "Installing PanelAlpha engine"
fi
install_panelalpha_engine

update_progress 85 "Cleaning installation files"
echo_info "Cleaning installation files"
clean_installer

update_progress 90 "Applying additional configuration"
echo_info "Applying additional configuration"
post_install_config

if [ -n "$DEPLOY_REPO" ]; then
    update_progress 95 "Building ${DEPLOY_REPO_LABEL}"
    echo_info "Deploying ${DEPLOY_REPO_LABEL}"
    deploy_repository
fi

update_progress 100 "Finishing installation"
finish_installation
