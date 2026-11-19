#!/usr/bin/env bash

export DEBIAN_FRONTEND=noninteractive
export NEEDRESTART_MODE=a

default_color='\e[39m'
red_color='\e[31m'
green_color='\e[32m'
yellow_color='\e[33m'

die() {
    exit_code=$?
    # A second signal must not cut a rollback short.
    trap '' HUP INT TERM
    if [[ ${exit_code} -ne 0 ]]; then
        echo -e "${yellow_color}${BASH_COMMAND} ${red_color}command failed with exit code ${yellow_color}${exit_code}${default_color}"
    fi
    ! declare -F rollback_engine_update >/dev/null || rollback_engine_update "$exit_code" || true
    ! declare -F resume_core_background >/dev/null || resume_core_background
    ! declare -F start_core_tenant_network >/dev/null || start_core_tenant_network
    ! declare -F resume_broker_rotation >/dev/null || resume_broker_rotation
    ! declare -F record_run_outcome >/dev/null || record_run_outcome "$exit_code" || true
    send_update_status "$exit_code" || true
}

set -e
trap 'last_command=$current_command; current_command=$BASH_COMMAND' DEBUG
trap die EXIT
# Killed without these, the exit trap would see status 0 and take the run for a success.
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

# A caller's umask 077 (panelalpha-installer.sh sets one) would leave the whole tree
# root-only, and php-fpm (www-data) then answers every request "File not found".
umask 022

random-string() {
    cat /dev/urandom | tr -dc 'a-zA-Z0-9' | fold -w ${1:-32} | head -n 1
}

# Empty means "not passed": resolved from PANELALPHA_ENGINE_VERSION env or defaults.
PANELALPHA_ENGINE_VERSION="${PANELALPHA_ENGINE_VERSION:-}"
# Accepted from get.* for ABI compatibility; unused (engine installs from git only).
PACKAGE_HOST=''
MONITORING_HOST="${PANELALPHA_MONITORING_HOST:-monitoring.panelalpha.com}"
STARTED_AT=$(date +%s || echo 0)
REF=""
# Git URL for the engine tree. Default is the public GitHub mirror. Credentials may
# be embedded (https://user:token@host/...). Empty is invalid — use get.panelalpha.com/engine.
if [ "${PANELALPHA_ENGINE_REPO+x}" = x ]; then
    ENGINE_REPO="${PANELALPHA_ENGINE_REPO}"
else
    ENGINE_REPO='https://github.com/panelalpha/engine.git'
fi
# A repository clone carries no vendor directory; composer install fills it.
COMPOSER_IMAGE='ghcr.io/panelalpha/engine-composer:v2.2.0-20261028'

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
NO_DISK_SPACE_CHECK=0
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
# Filesystem quota on /home; PANELALPHA_QUOTA=0 in the environment also opts out.
QUOTA="${PANELALPHA_QUOTA:-}"
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
# 1 when project:create --update-existing found the project already deploying --repo and
# deployed it again instead of creating another (a second run of the same install).
DEPLOY_UPDATED=0
# Short reason from a failed project:create (stderr/stdout), for logs + outro.
DEPLOY_ERROR=''
# Site password for the --repo project. Empty + DEPLOY_NO_PASSWORD=0 → generate.
DEPLOY_SITE_PASSWORD=''
DEPLOY_NO_PASSWORD=0
# 1 after project:set-password succeeded (outro shows the password).
DEPLOY_PASSWORD_SET=0
# 1 when --full-privileges: the --repo project is trusted with this engine, like the panel.
DEPLOY_FULL_PRIVILEGES=0
# --env-var KEY=VALUE pairs for the --repo project; values may be secrets, never printed.
DEPLOY_ENV_VARS=()
# With --full-privileges the project is the panel: --email is its admin, --hub-code its Hub code.
PANEL_ADMIN_EMAIL=''
PANEL_ADMIN_PASSWORD=''
PANEL_HUB_CODE=''
# 1 when --update-engine: with --repo on an existing host, update the engine
# and deploy without asking.
UPDATE_ENGINE=0
# 1 when --deploy-only: with --repo on an existing host, skip the engine update.
FORCE_DEPLOY_ONLY=0
# One-time code from Connect engine in PanelAlpha Connect (--hub-code). It is a
# credential while it lives: never log it or send it to monitoring.
HUB_CODE=''
# 1 when an engine is already here and --hub-code is all there is to do.
HUB_ONLY=0
HUB_FAILED=0
HUB_ERROR=''
HUB_ACCOUNT=''
# An update installs only a newer release than the running one; these let it
# install an older one, or the running one again.
ALLOW_DOWNGRADE="${PANELALPHA_ALLOW_DOWNGRADE:-0}"
REINSTALL="${PANELALPHA_REINSTALL:-0}"
# 1 once the files an update replaces are saved: a failure after that puts them back.
ENGINE_SNAPSHOT_ARMED=0
# What a rollback after a failure achieved, for the run's record.
ENGINE_ROLLBACK=''
ENGINE_ROLLBACK_RESULT=''
# Set when an update stays on the running version but moves to another commit of it, for the run's record.
UPDATE_NOTE=''

usage() {
    cat <<'USAGE'
Usage: bash installer.sh [options]

  -v, --version REF        git ref (default: main)
      --reinstall          on an update, install the target even when it is
                           the running release (or PANELALPHA_REINSTALL=1)
      --allow-downgrade    on an update, install the target even when it is
                           older than the running release (or
                           PANELALPHA_ALLOW_DOWNGRADE=1). Without either an
                           update of an engine already at the target changes
                           nothing, and an older target is refused.
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
      --ref CODE           Optional reference code
      --repo REPO          deploy a repository once the engine is up: a clone
                           URL, host/owner/repo, or a bare owner/repo, which
                           means GitHub unless the engine's default_git_host
                           setting says otherwise. The project name and the
                           domain are generated. Where an engine is already
                           installed, a newer product version prompts whether
                           to update the engine and deploy, or only deploy;
                           when already current, only the project is created.
      --branch REF         branch, tag or commit for --repo
      --git-token TOKEN    HTTPS access token for a private --repo
      --password PASS      site password for the --repo project (default: generate
                           an alphanumeric password and print it with the URL)
      --no-password        do not set a site password on the --repo project
      --full-privileges    trust the --repo project with this engine: an Engine
                           API token for every project, the server's own name
                           and public ports, and once it is live a system
                           project: hidden from every other API token, and
                           protected (for a panel that manages the engine).
                           The project is taken to be the PanelAlpha panel:
                           --email becomes its admin, with a generated password
                           printed at the end, --hub-code goes to the panel
                           (not to the engine), and no site password is set
                           unless --password is given
      --env-var KEY=VALUE  environment variable for the --repo project
                           (repeatable); the value is never printed
      --update-engine      with --repo on an existing install, update the engine
                           and deploy without prompting (non-interactive too)
      --deploy-only        with --repo on an existing install, create the project
                           only (skip the engine update; set by the TUI preflight)
      --hub-code CODE      connect the engine to PanelAlpha Connect once it is up,
                           with the code from Connect engine in Connect. Where an
                           engine is already installed it is only connected; add
                           --update-engine to update it first.
  -p, --package-host HOST  accepted for get.* compatibility; ignored
      --monitoring-host H  monitoring host for install status (default: monitoring.panelalpha.com)
  -d, --debug              set -x
      --no-local-ip        resolve the public IP when the default route is private
      --enable-nat         build the NAT mapping after migrating
      --configure          TUI preflight only (exit 0); used by the wrapper
                           before the update-vs-deploy prompt (probes --repo)
      --run-dir DIR        write progress/step files for the TUI wrapper

Environment:
  PANELALPHA_ENGINE_REPO      git clone URL (default: https://github.com/panelalpha/engine.git)
  PANELALPHA_ENGINE_VERSION   branch/tag/commit (default: main when using git)
  PANELALPHA_MONITORING_HOST  monitoring hostname (same as --monitoring-host)
  PANELALPHA_HUB              PanelAlpha Connect URL for --hub-code (default: https://hub.panelalpha.com)

Installing into a container (CI, dev):

      --in-container       shorthand for a host that is itself a container:
                           --no-sysbox --no-hardening --dind-runtime privileged
                           --mtu 1400. Individual flags still win.
      --no-sysbox          do not install the Sysbox runtime
      --no-hardening       skip sysctl, monit and the firewall (ufw, fail2ban)
      --no-upgrade         skip 'apt-get upgrade' and 'apt-get autoremove'
      --no-quota           leave filesystem quota off; project disk and inode
                           limits are then recorded but not enforced
      --no-disk-space-check  skip the free-disk check (20G install, 15G update)
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
    --ref)
        REF="$2"
        shift
        shift
        ;;
    --ref=*)
        REF="${1#*=}"
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
    --password)
        DEPLOY_SITE_PASSWORD="$2"
        shift
        shift
        ;;
    --password=*)
        DEPLOY_SITE_PASSWORD="${1#*=}"
        shift
        ;;
    --no-password)
        DEPLOY_NO_PASSWORD=1
        shift
        ;;
    --full-privileges)
        DEPLOY_FULL_PRIVILEGES=1
        shift
        ;;
    --env-var)
        DEPLOY_ENV_VARS+=("$2")
        shift
        shift
        ;;
    --env-var=*)
        DEPLOY_ENV_VARS+=("${1#*=}")
        shift
        ;;
    --update-engine)
        UPDATE_ENGINE=1
        shift
        ;;
    --deploy-only)
        FORCE_DEPLOY_ONLY=1
        shift
        ;;
    --hub-code)
        HUB_CODE="$2"
        shift
        shift
        ;;
    --hub-code=*)
        HUB_CODE="${1#*=}"
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
    --no-quota)
        QUOTA=0
        shift
        ;;
    --no-disk-space-check)
        NO_DISK_SPACE_CHECK=1
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
    --reinstall)
        REINSTALL=1
        shift
        ;;
    --allow-downgrade)
        ALLOW_DOWNGRADE=1
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

if [ "$DEPLOY_NO_PASSWORD" = 1 ] && [ -n "$DEPLOY_SITE_PASSWORD" ]; then
    echo "Cannot use --password and --no-password together." >&2
    trap - EXIT
    exit 1
fi

if [ "$DEPLOY_FULL_PRIVILEGES" = 1 ] && [ -z "$DEPLOY_REPO" ]; then
    echo "--full-privileges is for the --repo project; give --repo too." >&2
    trap - EXIT
    exit 1
fi

# Checked before anything is installed: a typo found after a ten-minute install
# costs the code, which lives only ten minutes.
if [ -n "$HUB_CODE" ] && ! printf '%s' "$HUB_CODE" | grep -Eq '^[A-Za-z0-9 -]{1,16}$'; then
    echo "--hub-code is not a connect code. Copy the command from Connect engine in PanelAlpha Connect." >&2
    trap - EXIT
    exit 1
fi

if [ "${#DEPLOY_ENV_VARS[@]}" -gt 0 ]; then
    if [ -z "$DEPLOY_REPO" ]; then
        echo "--env-var is for the --repo project; give --repo too." >&2
        trap - EXIT
        exit 1
    fi
    for pair in "${DEPLOY_ENV_VARS[@]}"; do
        if ! printf '%s' "${pair%%=*}" | grep -Eq '^[A-Za-z_][A-Za-z0-9_]*$' || [ "${pair#*=}" = "$pair" ]; then
            echo "--env-var takes KEY=VALUE with KEY a variable name, not '${pair%%=*}'." >&2
            trap - EXIT
            exit 1
        fi
    done
fi

# The panel, not the engine, pairs with the Hub; its admin comes from --email.
if [ "$DEPLOY_FULL_PRIVILEGES" = 1 ]; then
    if [ -n "$HUB_CODE" ] && [ -z "$INSTALL_EMAIL" ]; then
        echo "--hub-code with --full-privileges needs --email: the panel's admin, whose PanelAlpha account made the code." >&2
        trap - EXIT
        exit 1
    fi
    PANEL_HUB_CODE="$HUB_CODE"
    HUB_CODE=''
    PANEL_ADMIN_EMAIL="$INSTALL_EMAIL"
    # A site password would put HTTP auth in front of the panel's own login.
    [ -n "$DEPLOY_SITE_PASSWORD" ] || DEPLOY_NO_PASSWORD=1
fi

# Sysbox needs host daemons and cannot nest, so accounts run privileged; nested
# daemons need the lower MTU when ICMP is filtered. See docs/internal/engine-container.md.
if [ "$IN_CONTAINER" = 1 ]; then
    : "${INSTALL_SYSBOX:=0}"
    : "${HARDEN:=0}"
    : "${QUOTA:=0}"
    : "${DIND_RUNTIME:=privileged}"
    : "${DOCKER_NETWORK_MTU:=1400}"
fi
: "${INSTALL_SYSBOX:=1}"
: "${HARDEN:=1}"
: "${QUOTA:=1}"
: "${UPGRADE:=1}"
: "${DOCKER_NETWORK_MTU:=1500}"

if [ "$DEBUG_MODE" = 1 ]; then
    set -x
fi

# Plain message for monitoring / failure email when echo_error exits.
INSTALLER_ERROR=""

echo_info() { echo -e ">>> $green_color$1$default_color"; }
echo_warning() { echo -e ">>> $yellow_color$1$default_color"; }
echo_error() {
    echo -e ">>> $red_color$1$default_color"
    INSTALLER_ERROR="$1"
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

# Human-readable failure for monitoring. Prefer echo_error text over DEBUG
# last_command / apt stderr noise.
installer_error_message() { # exit_code
    local exit_code=${1:-1}
    if [ -n "${INSTALLER_ERROR:-}" ]; then
        printf '%s' "$INSTALLER_ERROR"
        return 0
    fi
    local last_cmd="${last_command:-${current_command:-}}"
    if [ -n "$last_cmd" ]; then
        printf 'command failed (exit %s): %s' "$exit_code" "$last_cmd"
        return 0
    fi
    printf 'installer failed with exit_code=%s' "$exit_code"
}

normalize_ref() {
    local raw=${1:-}
    raw=$(printf '%s' "$raw" | tr -d '\r\n' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
    if [ -z "$raw" ] || [ "${#raw}" -gt 64 ]; then
        printf ''
        return 0
    fi
    case "$raw" in
    *[!A-Za-z0-9._-]*) printf '' ;;
    *) printf '%s' "$raw" ;;
    esac
}

redact_repo_url() {
    printf '%s' "$1" | sed -E 's#(https?://)[^/@]+@#\1***@#'
}

# Report install/update outcome to monitoring (Engine emails / probes).
# --repo's deploy_result: deployed, updated, failed or not_run; empty without --repo.
install_event_deploy_result() {
    [ -n "${DEPLOY_REPO:-}" ] || return 0
    if [ "${DEPLOY_FAILED:-0}" = 1 ]; then
        echo failed
    elif [ "${DEPLOY_UPDATED:-0}" = 1 ]; then
        echo updated
    elif [ -n "${DEPLOY_PROJECT_NAME:-}" ]; then
        echo deployed
    else
        echo not_run
    fi
}

send_update_status() {
    local exit_code=${1:-0}
    local finished_at started_at software_op email error_msg event_type ref repo software deploy_result
    started_at=${STARTED_AT:-$(date +%s || echo 0)}
    finished_at=$(date +%s || echo 0)
    software_op="${ENGINE_OP:-install}"
    if [ "$software_op" != "update" ]; then
        software_op="install"
    fi
    if [ "$software_op" = "update" ]; then
        event_type="engine.update"
    else
        event_type="engine.install"
    fi
    email="${INSTALL_EMAIL:-}"
    error_msg=""
    if [ "$exit_code" != "0" ]; then
        error_msg=$(installer_error_message "$exit_code")
    fi
    ref=$(normalize_ref "${REF:-}")
    repo=""
    if [ -n "${DEPLOY_REPO:-}" ]; then
        repo=$(redact_repo_url "$DEPLOY_REPO")
    fi
    # The engine with the panel as its trusted project.
    software="engine"
    if [ "${DEPLOY_FULL_PRIVILEGES:-0}" = 1 ] && [ -n "${DEPLOY_REPO:-}" ]; then
        software="single-server"
    fi
    # What became of --repo: the engine's own exit code stays 0 when only the project failed.
    deploy_result=$(install_event_deploy_result)
    {
        jq -n \
            --arg type "$event_type" \
            --arg occurred_at "$(date -u +%Y-%m-%dT%H:%M:%SZ 2>/dev/null || echo "")" \
            --arg started_at "$started_at" \
            --arg finished_at "$finished_at" \
            --arg exit_code "$exit_code" \
            --arg software "$software" \
            --arg software_op "$software_op" \
            --arg email "$email" \
            --arg error "$error_msg" \
            --arg to_version "${PANELALPHA_ENGINE_VERSION:-}" \
            --arg ref "$ref" \
            --arg repo "$repo" \
            --arg deploy_result "$deploy_result" \
            '{events:[{
              type:$type,
              occurred_at:$occurred_at,
              payload:(
                {
                  started_at:$started_at,
                  finished_at:$finished_at,
                  exit_code:$exit_code,
                  software:$software,
                  software_op:$software_op,
                  email:$email,
                  error:$error,
                  to_version:$to_version
                }
                + (if $ref != "" then {ref:$ref} else {} end)
                + (if $repo != "" then {repo:$repo} else {} end)
                + (if $deploy_result != "" then {deploy_result:$deploy_result} else {} end)
              )
            }]}' \
        | curl -4 -sS -X POST "https://${MONITORING_HOST}/api/v1/events" \
            -H "Content-Type: application/json" \
            -H "Accept: application/json" \
            -H "User-Agent: PanelAlpha-Engine/installer" \
            ${APP_UID:+-H} ${APP_UID:+"X-Engine-App-UID: ${APP_UID}"} \
            -d @- >/dev/null 2>&1 || true
    } || true
}

# engine | deploy — TUI two-step chrome for --repo install/update.
INSTALLER_PHASE=engine

# One field of this run's record in --run-dir, which core reads back as latest_update.
record_run() { # <name> <value>
    [ -n "${RUN_DIR:-}" ] && [ -d "$RUN_DIR" ] || return 0
    printf '%s' "$2" >"$RUN_DIR/$1"
}

# What the run did, beside the exit code the wrapper writes: installed, updated, rolled_back or failed, and why.
record_run_outcome() { # <exit code>
    if [ "$1" = 0 ]; then
        [ "$ENGINE_OP" = update ] && record_run result updated || record_run result installed
        [ -z "${UPDATE_NOTE:-}" ] || record_run reason "$UPDATE_NOTE"
        return 0
    fi
    record_run result "${ENGINE_ROLLBACK_RESULT:-failed}"
    record_run reason "$(installer_error_message "$1")${ENGINE_ROLLBACK:+; $ENGINE_ROLLBACK}"
}

set_installer_phase() {
    INSTALLER_PHASE="$1"
    if [ -n "${RUN_DIR:-}" ] && [ -d "$RUN_DIR" ]; then
        printf '%s\n' "$1" >"$RUN_DIR/phase"
    fi
}

update_progress() {
    local raw="$1" msg="$2" pct="$1"
    # With --repo on a full install/update, compress engine work into 0–50% and
    # leave 50–100% for deploy so the bar matches the two-step TUI.
    if [ -n "$DEPLOY_REPO" ] && [ "${DEPLOY_ONLY:-0}" != 1 ]; then
        case "${INSTALLER_PHASE:-engine}" in
        deploy)
            if [ "$raw" -lt 50 ]; then
                pct=$((50 + raw / 2))
            else
                pct="$raw"
            fi
            ;;
        *)
            pct=$((raw * 50 / 100))
            if [ "$pct" -lt 1 ] && [ "$raw" -gt 0 ]; then
                pct=1
            fi
            ;;
        esac
    fi
    if [ -n "$RUN_DIR" ] && [ -d "$RUN_DIR" ]; then
        echo "$pct" >"$RUN_DIR/progress"
        echo "$msg" >"$RUN_DIR/step"
    fi
}

# Resolve the git ref to install.
resolve_engine_version() {
    if [ -n "$PANELALPHA_ENGINE_VERSION" ]; then
        return
    fi

    if [ -z "$ENGINE_REPO" ]; then
        echo_error "No engine source configured. Set PANELALPHA_ENGINE_REPO (or use: curl --proto '=https' -fsSL https://get.panelalpha.com/engine | sh)"
    fi

    PANELALPHA_ENGINE_VERSION=main
    echo_info "Installing PanelAlpha Engine ${PANELALPHA_ENGINE_VERSION} from $(redact_repo_url "$ENGINE_REPO")"
}

define_variables() {
    LOG_DIR="/opt/panelalpha/log"
    mkdir -p $LOG_DIR
    PANELALPHA_DIR="/opt/panelalpha"
    INSTALL_DIR='/opt/panelalpha/tmp/engine'
    ENGINE_SNAPSHOT_DIR='/opt/panelalpha/tmp/engine-previous'
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

# Installed product version (artisan → config/system.php). Empty if unknown.
# The shared-hosting/version file holds a git commit hash, not a semver — do not
# use it here (see detect_installed_engine_commit).
detect_installed_engine_version() {
    local compose="${PANELALPHA_DIR}/shared-hosting/docker-compose.yml"
    local php_cfg="${PANELALPHA_DIR}/shared-hosting/core/config/system.php"
    local ver=""

    if [[ -f "$compose" ]]; then
        ver=$(docker compose -f "$compose" exec -T core php artisan system:version 2>/dev/null | tr -d '\r\n' || true)
    fi
    if [[ -z "$ver" || "$ver" == "unknown" ]] && [[ -f "$php_cfg" ]]; then
        ver=$(sed -nE "s/.*'version'[[:space:]]*=>[[:space:]]*'([^']+)'.*/\1/p" "$php_cfg" | head -n1 || true)
    fi
    if [[ "$ver" == "unknown" ]]; then
        ver=""
    fi
    printf '%s' "$ver"
}

# Short commit of the installed engine tree (first 8 chars of shared-hosting/version).
# Empty when the file is missing — callers treat that as "unknown" / always newer.
detect_installed_engine_commit() {
    local ver_file="${PANELALPHA_DIR}/shared-hosting/version"
    local raw=""
    if [[ -f "$ver_file" ]]; then
        raw=$(tr -d '\r\n' <"$ver_file" || true)
    fi
    if [[ -z "$raw" || "$raw" == "unknown" ]]; then
        printf '%s' ""
        return 0
    fi
    printf '%.8s' "$raw"
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
    echo -e ">>> ${yellow_color}  wget --https-only --max-redirect=0 -N -P /opt/panelalpha https://license.panelalpha.com/engine-updater.sh${default_color}"
    echo -e ">>> ${yellow_color}  bash /opt/panelalpha/engine-updater.sh --key 'YOUR_LICENSE_KEY'${default_color}"
    echo -e ">>> ${red_color}The get.panelalpha.com/engine command is for fresh installs and hosts already on Engine 2.x.${default_color}"
    echo -e ">>> ${red_color}See product documentation for Engine 1.0 and 2.0 install paths.${default_color}"
    echo_error "Refusing Engine 1.0 → 2.0 in-place upgrade"
}

ensure_packages() {
    local pkg install_packages=()
    for pkg in "$@"; do
        if ! dpkg -s "$pkg" >/dev/null 2>&1; then
            install_packages+=("$pkg")
        fi
    done
    if (( ${#install_packages[@]} )); then
        apt-get -o DPkg::Lock::Timeout=300 install -y "${install_packages[@]}"
    fi
}

check_disk_space() {
    local required_gb=15
    if [ "$ENGINE_OP" != update ]; then
        required_gb=20
    fi
    local required_bytes=$((required_gb * 1024 * 1024 * 1024))
    local available_bytes
    available_bytes=$(df -B1 / 2>/dev/null | awk 'NR==2 {print $4}')
    if [[ -z "$available_bytes" ]]; then
        echo_warning "Unable to determine available disk space."
        return 0
    fi
    if (( available_bytes < required_bytes )); then
        echo_error "Not enough disk space. Required: ${required_gb}G, available: $((available_bytes / 1024 / 1024 / 1024))G"
    fi
    echo_info "Disk space check passed (${required_gb}G required)."
}

before_install() {
    check_root
    echo_info "Updating repositories"

    apt-get -o DPkg::Lock::Timeout=300 update -y
    if [ "$ENGINE_OP" != update ] && [ "$UPGRADE" = 1 ]; then
        apt-get -o DPkg::Lock::Timeout=300 upgrade -y
        apt-get -o DPkg::Lock::Timeout=300 autoremove -y
    elif [ "$UPGRADE" = 0 ]; then
        echo_warning "Skipping apt-get upgrade (--no-upgrade)"
    fi
    apt-get -o DPkg::Lock::Timeout=300 update --fix-missing -y
    ensure_packages jq unzip lsb-release apt-transport-https ca-certificates curl ipcalc quota at
    # nft binds each port of the accounts' network to its container.
    # The package's own nftables.service ships disabled and must stay so: its
    # default config flushes every rule on the host, Docker's and ufw's too.
    ensure_packages nftables

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
}

get_hostname() {
    if [ -z "$PANELALPHA_HOST" ]; then
        PANELALPHA_HOST=$(hostname -I | cut -d' ' -f1)
        if [ "$PANELALPHA_HOST" = "" ]; then
            PANELALPHA_HOST=$(hostname 2>/dev/null)
            if [ "$PANELALPHA_HOST" = "" ]; then
                PANELALPHA_HOST=$(curl --proto '=https' --http1.1 -s https://icanhazip.com)
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

install_docker_engine() {
    local pkg missing=0
    for pkg in docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin; do
        if ! dpkg -s "$pkg" >/dev/null 2>&1; then
            missing=1
            break
        fi
    done
    if [ "$missing" = 0 ]; then
        echo_info "Docker is already installed."
        return 0
    fi
    apt-get -o DPkg::Lock::Timeout=300 update -y
    ensure_packages ca-certificates curl gnupg
    mkdir -m 0755 -p /etc/apt/keyrings
    curl --proto '=https' --http1.1 -fsSL https://download.docker.com/linux/$SYSTEM/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg --yes
    echo \
        "deb [arch="$(dpkg --print-architecture)" signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/$SYSTEM \
    "$(. /etc/os-release && echo "$VERSION_CODENAME")" stable" |
        tee /etc/apt/sources.list.d/docker.list >/dev/null
    apt-get -o DPkg::Lock::Timeout=300 update -y
    ensure_packages docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
}

# Git URL for the engine tree. Prefer PANELALPHA_ENGINE_REPO (credentials may be
# embedded).
download_engine_from_repository() {
    local safe
    safe=$(redact_repo_url "$ENGINE_REPO")
    echo_warning "Please wait, cloning ${safe} (ref ${PANELALPHA_ENGINE_VERSION})..."
    mkdir -p "$INSTALL_DIR"
    rm -rf "$INSTALL_DIR/src"

    command -v git >/dev/null 2>&1 || apt-get -o DPkg::Lock::Timeout=300 install git -y

    export GIT_TERMINAL_PROMPT=0
    # The whole history, without old file contents: list_dropped_engine_files reads it.
    if ! git clone --filter=blob:none --single-branch --branch "$PANELALPHA_ENGINE_VERSION" \
        "$ENGINE_REPO" "$INSTALL_DIR/src" >/dev/null 2>&1; then
        echo_error "Could not clone ${safe} (ref ${PANELALPHA_ENGINE_VERSION})"
    fi
    # Persist the tip commit for update comparisons (v1 zip packages shipped this
    # file; git installs must recreate it before .git is removed).
    local tip_sha
    tip_sha=$(git -C "$INSTALL_DIR/src" rev-parse HEAD 2>/dev/null | tr -d '\r\n' || true)
    list_dropped_engine_files "$INSTALL_DIR/src" "$INSTALL_DIR/dropped-files"
    list_shipped_modsecurity_rules "$INSTALL_DIR/src" "$INSTALL_DIR/modsecurity-shipped"
    rm -rf "$INSTALL_DIR/src/.git"
    if [ -n "$tip_sha" ]; then
        printf '%s\n' "$tip_sha" >"$INSTALL_DIR/src/version"
    fi
    echo_info "Success! Package has been downloaded"
}

# What a release package used to ship prebuilt and a git clone does not.
install_composer_dependencies() {
    resolve_composer_image "$PANELALPHA_DIR/shared-hosting/core" "$PANELALPHA_DIR/shared-hosting"
    docker run --rm -v "$PANELALPHA_DIR/shared-hosting/core:/app" -w /app \
        "$COMPOSER_IMAGE" composer install --no-interaction --no-progress
}

download_panelalpha_engine() {
    if [ -z "$ENGINE_REPO" ]; then
        echo_error "No engine source configured. Set PANELALPHA_ENGINE_REPO (or use: curl --proto '=https' -fsSL https://get.panelalpha.com/engine | sh)"
    fi
    download_engine_from_repository
}

# Traditional PHP hosting runs git on the host, pinned to the address the engine
# checked with http.curloptResolve, which git before 2.37 ignores. The engine then
# refuses such a pull unless the host is in EGRESS_ALLOW_HOSTS; the package
# source is the operator's, so this only says so.
warn_old_host_git() {
    local version
    version=$(git --version 2>/dev/null | sed -n 's/^git version \([0-9][0-9]*\.[0-9][0-9]*\).*/\1/p') || version=''
    if [ -n "$version" ] && [ "$(printf '%s\n' 2.37 "$version" | sort -V | head -1)" != 2.37 ]; then
        echo_warning "git ${version} is older than 2.37: on traditional PHP hosting the engine refuses a git pull from any host name not in EGRESS_ALLOW_HOSTS until git on this server is upgraded."
    fi
}

# Host state: never deleted by an update, whatever an older release shipped at
# that path. The same paths deploy-from-source.sh keeps out of its upload.
ENGINE_HOST_STATE=(
    .git .idea .aider-desk .aider.input.history .github .claude
    .env .env-core version crt
    core/.env core/.env.backup core/.env.pae-backup
    users logs webserver-logs webserver-config
    nginx-proxy-conf.d nginx-proxy-logs
    pureftpd data awstats-config awstats-data litespeed-config build
    scripts/monit.conf scripts/monit-script.sh
    docker-compose.yml-webserver docker-compose.override.yml
    config/logrotate config/exim config/modsecurity config/sftp config/pure-ftpd
    config/tenant-api-access
    core/config/logrotate core/config/exim core/config/modsecurity
    core/config/sftp core/config/pure-ftpd
    core/vendor core/storage core/bootstrap/cache
    tests/coverage
    tests/api/node_modules tests/api/.playwright tests/api/test-results tests/api/playwright-report
    tests/api/env/.env 'tests/api/env/.env.*'
    scripts/tools/dind-test/vendor
)

engine_host_state() { # <path relative to the engine dir>
    local s
    for s in "${ENGINE_HOST_STATE[@]}"; do
        # shellcheck disable=SC2053 # a pattern, as rsync reads it
        [[ $1 == $s || $1 == $s/* ]] && return 0
    done
    return 1
}

# Every path this release's history tracked that the release itself does not,
# with each version of it the history holds ("<mode> <blob id> <path>", NUL
# ended): what an earlier release can have left on the host, and what its copy
# looks like. Needs the clone's .git, but only commits and trees, no contents.
list_dropped_engine_files() { # <clone> <list to write>
    local meta path side
    local -a f
    local -A current=() seen=()
    rm -f "$2"
    # -m: a merge can drop a file only its second parent had.
    if [ "$(git -C "$1" rev-parse --is-shallow-repository 2>/dev/null)" != false ] ||
        ! git -C "$1" ls-tree -r -z --name-only HEAD >"$2.current" 2>/dev/null ||
        ! git -C "$1" rev-list HEAD >"$2.revs" 2>/dev/null ||
        ! git -C "$1" diff-tree --stdin -r -m --root -z --raw --no-abbrev --no-commit-id \
            <"$2.revs" >"$2.raw" 2>/dev/null; then
        rm -f "$2.current" "$2.revs" "$2.raw"
        echo_warning "Could not read the engine's history; files earlier releases shipped and this one does not stay in place"
        return 0
    fi
    while IFS= read -r -d '' path; do current[$path]=1; done <"$2.current"
    # Each change is ":<old mode> <new mode> <old id> <new id> <status>", then the path.
    while IFS= read -r -d '' meta && IFS= read -r -d '' path; do
        [ -n "$path" ] && [ -z "${current[$path]+x}" ] || continue
        f=(${meta#:})
        for side in "${f[0]} ${f[2]}" "${f[1]} ${f[3]}"; do
            case "$side" in 000000* | 160000* | *" 0000000000000000000000000000000000000000") continue ;; esac
            [ -z "${seen[$side $path]+x}" ] || continue
            seen[$side $path]=1
            printf '%s\0' "$side $path"
        done
    done <"$2.raw" >"$2"
    rm -f "$2.current" "$2.revs" "$2.raw"
}

# "<blob id> <path>" for every version of each ModSecurity ruleset file in the clone's history.
list_shipped_modsecurity_rules() { # <clone> <list to write>
    local rulesets=templates/config/modsecurity/rulesets meta path side
    local -a f
    rm -f "$2"
    if [ "$(git -C "$1" rev-parse --is-shallow-repository 2>/dev/null)" != false ] ||
        ! git -C "$1" rev-list HEAD >"$2.revs" 2>/dev/null ||
        ! git -C "$1" diff-tree --stdin -r -m --root -z --raw --no-abbrev --no-commit-id \
            -- "$rulesets" <"$2.revs" >"$2.raw" 2>/dev/null; then
        rm -f "$2.revs" "$2.raw"
        echo_warning "Could not read the engine's history; ModSecurity rule files an earlier release shipped are not brought up to date"
        return 0
    fi
    while IFS= read -r -d '' meta && IFS= read -r -d '' path; do
        f=(${meta#:})
        for side in "${f[0]} ${f[2]}" "${f[1]} ${f[3]}"; do
            case "$side" in 100644\ * | 100755\ *) printf '%s %s\n' "${side#* }" "${path#"$rulesets"/}" ;; esac
        done
    done <"$2.raw" | LC_ALL=C sort -u >"$2"
    rm -f "$2.revs" "$2.raw"
}

# Copying a release over the old tree keeps every file the release dropped, and
# dropped code keeps running (api:call did). Removes those files, and folders
# they leave empty, but only a file that is still a copy some release shipped:
# one an operator wrote or changed at that path stays, and so does host state
# and anything whose folder is reached through a link.
remove_dropped_engine_files() { # <list> <engine dir>
    local root rec mode oid path dir real have removed=0 kept=0
    local -a order=()
    local -A shipped=()
    [ -s "$1" ] && [ -d "$2" ] || return 0
    root=$(cd "$2" && pwd -P)
    while IFS= read -r -d '' rec; do
        mode=${rec%% *} rec=${rec#* }
        oid=${rec%% *} path=${rec#* }
        [ -n "${shipped[$path]+x}" ] || order+=("$path")
        shipped[$path]+=" $mode:$oid"
    done <"$1"
    for path in "${order[@]}"; do
        case "/$path/" in */../* | */./* | *//*) continue ;; esac
        engine_host_state "$path" && continue
        [ -f "$root/$path" ] || [ -L "$root/$path" ] || continue
        # ${path%/*}, not $(dirname): a command substitution drops a name's last newline.
        dir=.
        [ "${path#*/}" = "$path" ] || dir=${path%/*}
        real=$(cd "$root/$dir" 2>/dev/null && pwd -P && echo x) || continue
        real=${real%$'\n'x}
        [ "$dir" = . ] && have=$root || have=$root/$dir
        if [ "$real" != "$have" ]; then
            echo "Kept $path: its folder is reached through a link"
            kept=$((kept + 1))
            continue
        fi
        # GIT_DIR: never the repository of the directory the installer runs from;
        # a broken worktree or a sha256 one there would fail or miss every hash.
        if [ -L "$root/$path" ]; then
            have=$(printf '%s' "$(readlink "$root/$path")" | GIT_DIR=/nonexistent git hash-object --stdin 2>/dev/null) &&
                have="120000:$have" || have=''
        else
            have=$(GIT_DIR=/nonexistent git hash-object --no-filters "$root/$path" 2>/dev/null) || have=''
            case "${shipped[$path]} " in *" 100755:$have "*) have="100755:$have" ;; *) have="100644:$have" ;; esac
            [ "$have" != 100644: ] || have=''
        fi
        if [ -z "$have" ]; then
            echo "Kept $path: could not hash it"
            kept=$((kept + 1))
            continue
        fi
        case "${shipped[$path]} " in
        *" $have "*) ;;
        *)
            echo "Kept $path: not a copy any release shipped"
            kept=$((kept + 1))
            continue
            ;;
        esac
        rm -f -- "$root/$path" || continue
        echo "Removed $path"
        removed=$((removed + 1))
        while [ "$dir" != . ] && rmdir -- "$root/$dir" 2>/dev/null; do
            [ "${dir#*/}" = "$dir" ] && dir=. || dir=${dir%/*}
        done
    done
    [ "$removed" = 0 ] || echo_info "Removed ${removed} file(s) that earlier releases shipped and this one does not"
    [ "$kept" = 0 ] || echo_info "Kept ${kept} file(s) at paths earlier releases used, for the reasons above"
}

unzip_panelalpha_engine() {
    if [ ! -d "$INSTALL_DIR/src" ]; then
        echo_error "Engine tree missing under ${INSTALL_DIR}/src (expected a git clone)"
    fi
    # The repository's agent setup is for people working on the engine, not for
    # a host, and a .claude/skills folder an older release left cannot take the
    # link this one has. The host's own .claude is host state and stays.
    rm -rf "$INSTALL_DIR/src/.claude"
    if [ "${ENGINE_OP:-}" = update ]; then
        snapshot_engine_tree "$INSTALL_DIR/src" "$PANELALPHA_DIR/shared-hosting" "$INSTALL_DIR/dropped-files" "$ENGINE_SNAPSHOT_DIR" ||
            echo_error "Could not save the files the update replaces; the engine was not changed"
        ENGINE_SNAPSHOT_ARMED=1
    fi
    # Before the copy: a file that became a folder, or the other way round,
    # would stop cp.
    remove_dropped_engine_files "$INSTALL_DIR/dropped-files" "$PANELALPHA_DIR/shared-hosting"
    replace_running_updaters "$INSTALL_DIR/src" "$PANELALPHA_DIR/shared-hosting"
    # --preserve=mode also repairs files an earlier run left with the wrong mode;
    # a plain cp keeps the existing file's mode.
    cp -Rf --preserve=mode "$INSTALL_DIR/src/." "$PANELALPHA_DIR/shared-hosting/"
    hand_engine_files_to_root "$PANELALPHA_DIR"
}

# The root broker refuses engine files anyone but root can write, and runs its own code from dockerfiles/; links are
# skipped, chown follows them.
hand_engine_files_to_root() { # <panelalpha dir>
    local engine="$1/shared-hosting"
    {
        chown root:root "$1" "$engine" && chmod go-w "$1" "$engine" &&
            find "$engine/scripts" ! -type l -exec chown root:root {} + -exec chmod go-w {} + &&
            { [ ! -d "$engine/dockerfiles" ] ||
                find "$engine/dockerfiles" ! -type l -exec chown root:root {} + -exec chmod go-w {} +; } &&
            find "$engine" -mindepth 1 -maxdepth 1 -type f \( -name '*.sh' -o -name '*compose*.y*ml*' -o -name .env \) \
                -exec chown root:root {} + -exec chmod go-w {} + &&
            hand_account_compose_files "$engine/users"
    } || echo_warning "Could not leave the engine's scripts and compose files in ${engine} writable by root alone; the root broker refuses them until it is"
}

hand_account_compose_files() { # <users dir>
    [ -d "$1" ] || return 0
    find "$1" -maxdepth 1 -type d -exec chmod go-w {} + &&
        find "$1" -mindepth 2 -maxdepth 2 -type f -name docker-compose.yml -exec chmod go-w {} +
}

# bash reads the running updater as it goes, so it is renamed in, never rewritten.
replace_running_updaters() { # <src> <engine dir>
    local f
    for f in updater.sh scripts/updater.sh; do
        [ -f "$1/$f" ] && [ -f "$2/$f" ] || continue
        cp --preserve=mode "$1/$f" "$2/$f.new" && mv -f "$2/$f.new" "$2/$f"
    done
}

# Rewritten by composer after the copy rather than by it, so kept whole.
ENGINE_REBUILT_DIRS=(core/vendor core/bootstrap/cache)

# Saves what copying <src> over <engine dir> replaces, and no more: every file the copy overwrites or the
# dropped-file pass may remove, the files and folders the copy adds, and the folders composer rewrites.
# Host state is not in a release, so storage, users and logs are never copied.
snapshot_engine_tree() { # <src> <engine dir> <dropped list> <snapshot dir>
    local path rec dir
    local -a prune=()
    rm -rf "$4"
    mkdir -p "$4/dirs" || return 1
    : >"$4/replaced"
    : >"$4/added"
    : >"$4/added-dirs"
    for dir in "${ENGINE_REBUILT_DIRS[@]}"; do prune+=(-path "./$dir" -prune -o); done
    (cd "$1" && find . -mindepth 1 "${prune[@]}" -print0) | while IFS= read -r -d '' path; do
        path=${path#./}
        if [ -d "$1/$path" ] && [ ! -L "$1/$path" ]; then
            [ -e "$2/$path" ] || [ -L "$2/$path" ] || printf '%s\0' "$path" >>"$4/added-dirs"
        elif [ -f "$2/$path" ] || [ -L "$2/$path" ]; then
            printf '%s\0' "$path" >>"$4/replaced"
        elif [ ! -e "$2/$path" ]; then
            printf '%s\0' "$path" >>"$4/added"
        fi
    done
    if [ -s "$3" ]; then
        while IFS= read -r -d '' rec; do
            path=${rec#* } path=${path#* }
            case "/$path/" in */../* | */./* | *//*) continue ;; esac
            engine_host_state "$path" && continue
            if [ -f "$2/$path" ] || [ -L "$2/$path" ]; then printf '%s\0' "$path" >>"$4/replaced"; fi
        done <"$3"
    fi
    tar -C "$2" --null -T "$4/replaced" -cf "$4/files.tar" || return 1
    for dir in "${ENGINE_REBUILT_DIRS[@]}"; do
        [ -d "$2/$dir" ] || continue
        mkdir -p "$4/dirs/$(dirname "$dir")" && cp -a "$2/$dir" "$4/dirs/$dir" || return 1
    done
    touch "$4/armed"
}

# Undoes the copy a snapshot was taken for: what it added goes, and what it replaced or removed comes back.
restore_engine_tree() { # <engine dir> <snapshot dir>
    local path dir
    [ -f "$2/armed" ] || return 0
    while IFS= read -r -d '' path; do
        rm -f -- "$1/$path"
    done <"$2/added"
    sort -zr "$2/added-dirs" | while IFS= read -r -d '' path; do
        rmdir -- "$1/$path" 2>/dev/null || true
    done
    tar -C "$1" -xpf "$2/files.tar" || return 1
    for dir in "${ENGINE_REBUILT_DIRS[@]}"; do
        [ -d "$2/dirs/$dir" ] || continue
        rm -rf -- "${1:?}/$dir" && mv -- "$2/dirs/$dir" "$1/$dir" || return 1
    done
    rm -rf -- "$2"
}

# From here a rollback restarts the stack on the restored release as well.
mark_engine_stack_touched() {
    [ "$ENGINE_SNAPSHOT_ARMED" = 1 ] || return 0
    touch "$ENGINE_SNAPSHOT_DIR/stack-touched"
}

# Puts the release a snapshot holds back in place, and its stack once the new release's had started.
restore_previous_release() {
    local touched=0
    [ ! -f "$ENGINE_SNAPSHOT_DIR/stack-touched" ] || touched=1
    restore_engine_tree "$PANELALPHA_DIR/shared-hosting" "$ENGINE_SNAPSHOT_DIR" || return 1
    [ "$touched" = 0 ] || start_engine_stack
}

# A failure after the copy leaves the previous release running, not half of each.
rollback_engine_update() { # <exit code>
    [ "$1" != 0 ] && [ "$ENGINE_SNAPSHOT_ARMED" = 1 ] || return 0
    ENGINE_SNAPSHOT_ARMED=0
    echo_warning "The update failed after replacing the engine's files; putting the previous release back"
    if restore_previous_release; then
        ENGINE_ROLLBACK='the previous release was restored'
        ENGINE_ROLLBACK_RESULT=rolled_back
    else
        ENGINE_ROLLBACK="restoring the previous release failed as well; its files stay in ${ENGINE_SNAPSHOT_DIR} and the next update puts them back first"
    fi
    echo_warning "$ENGINE_ROLLBACK"
}

# An update killed after its copy leaves its snapshot behind, armed: the next one restores it before anything else.
restore_interrupted_update() {
    [ -f "$ENGINE_SNAPSHOT_DIR/armed" ] || return 0
    echo_warning "An earlier update stopped after replacing the engine's files; putting its previous release back first"
    restore_previous_release ||
        echo_error "Could not put back the release an earlier update replaced; its files are in ${ENGINE_SNAPSHOT_DIR}"
}

# The new release runs and has migrated: a later failure leaves it in place rather than roll the code back under it.
discard_engine_snapshot() {
    ENGINE_SNAPSHOT_ARMED=0
    rm -rf "$ENGINE_SNAPSHOT_DIR"
}

generate_ssl_cert() {
    mkdir -p /opt/panelalpha/shared-hosting/crt
    if [[ -f /opt/panelalpha/shared-hosting/crt/server.cert && -f /opt/panelalpha/shared-hosting/crt/server.key ]]; then
        echo "SSL certificate already exists."
        return 0
    fi
    CERT_IP=$(ip route get 8.8.8.8 | sed -n '/src/{s/.*src *\([^ ]*\).*/\1/p;q}')
    if ipcalc "$CERT_IP" | grep -q 'Private Internet' && [ "$NO_LOCAL_IP" = 1 ]; then
        CERT_IP=$(curl --proto '=https' -s4 https://icanhazip.com)
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
            IPV4=$(curl --proto '=https' -s4 https://icanhazip.com)
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
    bash /opt/panelalpha/shared-hosting/scripts/refresh-webserver-image.sh /opt/panelalpha/shared-hosting ||
        echo_warning "Could not bring the webserver image tag up to date"
    mkdir -p /opt/panelalpha/shared-hosting/config/pure-ftpd
    cp -Rn /opt/panelalpha/shared-hosting/templates/config/pure-ftpd/. /opt/panelalpha/shared-hosting/config/pure-ftpd/.
    chmod +x /opt/panelalpha/shared-hosting/config/pure-ftpd/entrypoint.sh
    # Until the first FTP account there is no password database, and pure-ftpd
    # answers every login 421 without logging it, so fail2ban's ftp jail sees
    # nothing. The container's entrypoint builds the database from this file.
    mkdir -p /opt/panelalpha/shared-hosting/pureftpd
    touch /opt/panelalpha/shared-hosting/pureftpd/pureftpd.passwd
    mkdir -p /opt/panelalpha/shared-hosting/config/sftp
    cp -Rn /opt/panelalpha/shared-hosting/templates/config/sftp/. /opt/panelalpha/shared-hosting/config/sftp/.
    # Scripts are engine code, not host state: -n would keep the installed copy.
    cp /opt/panelalpha/shared-hosting/templates/config/sftp/{entrypoint.sh,sync-logins.sh} /opt/panelalpha/shared-hosting/config/sftp/
    bash /opt/panelalpha/shared-hosting/scripts/refresh-sftp-entrypoint.sh /opt/panelalpha/shared-hosting ||
        echo_warning "Could not recreate the sftp container for a changed entry point; SFTP keeps serving the old one"
    if [ ! -f /opt/panelalpha/shared-hosting/config/sftp/ssh_host_ed25519_key ]; then
        ssh-keygen -t ed25519 -N "" -f /opt/panelalpha/shared-hosting/config/sftp/ssh_host_ed25519_key < /dev/null
    fi
    if [ ! -f /opt/panelalpha/shared-hosting/config/sftp/ssh_host_rsa_key ]; then
        ssh-keygen -t rsa -b 4096 -N "" -f /opt/panelalpha/shared-hosting/config/sftp/ssh_host_rsa_key < /dev/null
    fi
    mkdir -p /opt/panelalpha/shared-hosting/config/logrotate
    cp -Rn /opt/panelalpha/shared-hosting/templates/config/logrotate/. /opt/panelalpha/shared-hosting/config/logrotate/.
    # Their postrotate reopens the webserver's logs: engine code, not host state.
    cp /opt/panelalpha/shared-hosting/templates/config/logrotate/{apache,apache-users,nginx,nginx-proxy,php}.conf /opt/panelalpha/shared-hosting/config/logrotate/
    mkdir -p /opt/panelalpha/shared-hosting/config/exim
    mkdir -p /opt/panelalpha/shared-hosting/logs/exim
    cp -Rn /opt/panelalpha/shared-hosting/templates/config/exim/. /opt/panelalpha/shared-hosting/config/exim/.
    bash /opt/panelalpha/shared-hosting/scripts/seed-modsecurity.sh /opt/panelalpha/shared-hosting "$INSTALL_DIR/modsecurity-shipped"
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

# Host hardening, and the reason it runs before the stack does: on a host
# still running CSF, firewall.sh moves its rules to ufw and uninstalls it, and
# CSF's uninstaller flushes the whole iptables ruleset, Docker's chains with it,
# so the daemon has to be restarted afterwards. Doing that with the stack up
# takes every container down with it — which is how the install used to fail,
# on the first artisan call after the restart. It needs .env, the compose
# bridge and docker0 in place, so the earliest safe point is right before 'up'.
harden_host() {
    if [ "$HARDEN" != 1 ]; then
        echo_warning "Skipping sysctl, monit and the firewall (--no-hardening)"
        return
    fi

    bash /opt/panelalpha/shared-hosting/scripts/configure-sysctl.sh
    bash /opt/panelalpha/shared-hosting/scripts/configure-monit.sh
    bash /opt/panelalpha/shared-hosting/scripts/firewall.sh --install ||
        echo_warning "Could not set up the firewall; the firewall: lines above say what protects this host now"
    restart_docker_without_its_chains
    # Host builds run on panelalpha-build. Made here, while
    # Docker's chains are fresh: after a firewall flush the engine cannot create it.
    bash /opt/panelalpha/shared-hosting/scripts/build-network-firewall.sh --create panelalpha-build || true
    # The accounts' network, likewise; the stack names it.
    apply_tenant_network_firewall
}

# Only the root broker binds pash-tenants; an empty or missing network is made and closed here.
apply_tenant_network_firewall() {
    local compose=/opt/panelalpha/shared-hosting/docker-compose.yml
    # A missing network prints an empty line.
    local on_network
    on_network=$(docker network inspect -f '{{len .Containers}}' pash-tenants 2>/dev/null | tr -d '[:space:]') || true
    if [ "${on_network:-0}" = 0 ]; then
        DOCKER_NETWORK_MTU="${DOCKER_NETWORK_MTU}" bash /opt/panelalpha/shared-hosting/scripts/tenant-network-firewall.sh --create --restart-docker || true
    elif [ -z "$(docker compose -f "$compose" ps -q --status running root-broker 2>/dev/null)" ] ||
        ! docker compose -f "$compose" exec -T root-broker pa-broker --host host.applyTenantNetworkFirewall -- </dev/null; then
        echo_info "The accounts' network keeps the firewall in force until the new root broker applies it"
    fi
}

# Core's scheduler and queue workers stay down from the copy until migrate has run.
CORE_BACKGROUND_PAUSED=0
core_background() { # <s6-svc flag>
    docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T core sh -c \
        'for s in /run/service/scheduler /run/service/queue-*; do [ -d "$s" ] && s6-svc '"$1"' "$s"; done; true' \
        >/dev/null 2>&1
}

pause_core_background() {
    core_background -d && CORE_BACKGROUND_PAUSED=1 && echo_info "Core's scheduler and queue workers wait for the migrations"
    return 0
}

resume_core_background() {
    [ "${CORE_BACKGROUND_PAUSED:-0}" = 1 ] || return 0
    core_background -u || true
    CORE_BACKGROUND_PAUSED=0
}

# A fresh install, and an update on a server with no store, gets a local store to back up into.
ensure_default_backup_store() {
    docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T core \
        php artisan backup:container:ensure-default ||
        echo_warning "Could not create the default backup store; 'pae backup:container:ensure-default' retries"
}

# A pre-broker core loops re-applying the tenant firewall; its process group stops before the copy.
CORE_TENANT_NETWORK_STOPPED=0
core_tenant_network() { # <script run in core's container>
    docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T core sh -c "$1" >/dev/null 2>&1
}

stop_core_tenant_network() {
    core_tenant_network 'cd /run/service/tenant-network || exit 1
        pid=$(s6-svstat -p .); s6-svc -d .
        [ "$pid" -gt 0 ] 2>/dev/null && kill -TERM "-$pid" 2>/dev/null; true' &&
        CORE_TENANT_NETWORK_STOPPED=1 && echo_info "Core's tenant firewall loop is stopped; the new root broker takes it over"
    return 0
}

start_core_tenant_network() {
    [ "${CORE_TENANT_NETWORK_STOPPED:-0}" = 1 ] || return 0
    core_tenant_network 's6-svc -u /run/service/tenant-network' || true
    CORE_TENANT_NETWORK_STOPPED=0
}

# The old broker's logrotate finishes its pass and starts no other until the new broker is up.
BROKER_ROTATION_PAUSED=0
broker_rotation() { # <script run in the broker's container>
    docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T root-broker sh -c "$1" >/dev/null 2>&1
}

pause_broker_rotation() {
    broker_rotation 'cd /run/service/logrotate && s6-svc -O . && for _ in $(seq 1 120); do
        pid=$(s6-svstat -p .); [ "$pid" -gt 0 ] 2>/dev/null || exit 0
        [ "$(cat /proc/$pid/comm 2>/dev/null)" = sleep ] && exit 0
        sleep 1
    done' && BROKER_ROTATION_PAUSED=1 && echo_info "The broker's log rotation waits for the new broker"
    return 0
}

resume_broker_rotation() {
    [ "${BROKER_ROTATION_PAUSED:-0}" = 1 ] || return 0
    broker_rotation 's6-svc -u /run/service/logrotate' || true
    BROKER_ROTATION_PAUSED=0
}

# Only a restart restores Docker's chains after a full ruleset flush, such as CSF's uninstaller.
restart_docker_without_its_chains() {
    if iptables -w -n -L DOCKER >/dev/null 2>&1 && iptables -w -t nat -n -L DOCKER >/dev/null 2>&1; then
        echo_info "Docker's iptables chains are in place; Docker keeps running"
        return
    fi
    # docker.service allows three quick restarts; an install's fourth would hit start-limit-hit.
    systemctl reset-failed docker.service 2>/dev/null || true
    service docker restart
}

# Without it every setquota the engine runs is a no-op. Never fatal: a
# host that cannot have quota still gets an engine, and is told why.
configure_quota() {
    if [ "$QUOTA" != 1 ]; then
        echo_warning "Skipping filesystem quota (--no-quota): project disk limits will not be enforced"
        return
    fi
    bash /opt/panelalpha/shared-hosting/scripts/configure-quota.sh ||
        echo_warning "Could not turn on filesystem quota; project disk limits will not be enforced"
}

# nosuid,nodev on the account homes, where a tenant's root container leaves host-root files. Never fatal.
configure_home_mounts() {
    bash /opt/panelalpha/shared-hosting/scripts/harden-home-mounts.sh ||
        echo_warning "Could not mount the account homes nosuid,nodev"
}

# lxcfs gives each account its own /proc/meminfo, loadavg and CPUs. Never
# fatal: without it accounts start as before and see the host's.
configure_lxcfs() {
    bash /opt/panelalpha/shared-hosting/scripts/configure-lxcfs.sh ||
        echo_warning "lxcfs is not running; accounts will see the host's memory, CPUs and load in /proc"
}

# Host AppArmor profiles that attach by path also confine tenant binaries at
# that path. Run on every install and update, so profiles a new
# release ships are caught. Never fatal.
configure_apparmor() {
    bash /opt/panelalpha/shared-hosting/scripts/configure-apparmor.sh ||
        echo_warning "Could not disable the host's path-attached AppArmor profiles; tenant binaries at those paths stay confined"
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

# A container whose start failed on its network (a host port held elsewhere)
# stays Created, and Docker later "starts" it with no network at all: compose
# says Started and exits 0. Remove those so `up` creates them afresh.
remove_unstarted_containers() {
    local id
    for id in $(docker ps -aq --filter "label=com.docker.compose.project=shared-hosting" 2>/dev/null || true); do
        if docker inspect -f '{{.State.Status}} {{len .NetworkSettings.Networks}}' "$id" 2>/dev/null |
            grep -qE '^created |^running 0$'; then
            echo_info "Removing $(docker inspect -f '{{.Name}}' "$id" 2>/dev/null): its last start failed, so it would run without a network"
            docker rm -f "$id" >/dev/null 2>&1 || true
        fi
    done
}

# Every enabled service must run attached to a network; `up -d` exiting 0 does
# not say so (see above). Waits a minute for a restarting one.
check_engine_stack() {
    local compose=/opt/panelalpha/shared-hosting/docker-compose.yml
    local waited=0 services svc id state down
    services=$(docker compose -f "$compose" config --services)
    while :; do
        down=''
        for svc in $services; do
            id=$(docker ps -aq --filter "label=com.docker.compose.project=shared-hosting" \
                --filter "label=com.docker.compose.service=${svc}" \
                --filter "label=com.docker.compose.oneoff=False" 2>/dev/null | head -n1)
            state=$(docker inspect -f '{{.State.Status}} {{len .NetworkSettings.Networks}}' "$id" 2>/dev/null || true)
            case "$state" in
            '') down="${down} ${svc}(missing)" ;;
            'running 0') down="${down} ${svc}(no network)" ;;
            running\ *) ;;
            *) down="${down} ${svc}(${state% *})" ;;
            esac
        done
        [ -z "$down" ] && return 0
        [ "$waited" -ge 60 ] && break
        sleep 5
        waited=$((waited + 5))
    done
    echo_error "These engine services are not running:${down}"
}

# --remove-orphans frees ports dropped services hold; sites-http is recreated last, on its own.
start_engine_stack() {
    local compose=/opt/panelalpha/shared-hosting/docker-compose.yml services others
    services=$(docker compose -f "$compose" config --services)
    mapfile -t others < <(grep -vx sites-http <<<"$services")
    docker compose -f "$compose" up -d --remove-orphans "${others[@]}"
    if grep -qx sites-http <<<"$services"; then
        docker compose -f "$compose" up -d sites-http
    fi
}

# The build context paths a Dockerfile's COPY and ADD read, skipping COPY --from and here-documents, which are text in
# the Dockerfile itself.
engine_image_inputs() { # <dockerfile>
    awk '
        heredoc != "" { if ($0 == heredoc) heredoc = ""; next }
        /\\$/ { text = text substr($0, 1, length($0) - 1) " "; next }
        {
            $0 = text $0
            text = ""
            if (toupper($1) != "COPY" && toupper($1) != "ADD") next
            for (i = 2; i <= NF; i++) if ($i ~ /^--from=/) next
            for (i = 2; i < NF; i++) {
                if ($i ~ /^<</) { heredoc = $i; sub(/^<<-?/, "", heredoc); gsub(/["\047]/, "", heredoc); continue }
                if ($i !~ /^--/) print $i
            }
        }' "$1"
}

# Hash of an engine image's Dockerfile and context inputs, as its panelalpha.sources.sha256 label holds.
engine_image_sources() { # <context> <dockerfile>
    local -a inputs
    mapfile -t inputs < <(cd "$1" && engine_image_inputs "$2")
    (cd "$1" && set -o pipefail && find "$2" "${inputs[@]}" -name __pycache__ -prune -o -type f ! -name '*.pyc' -print |
        LC_ALL=C sort -u | xargs sha256sum | sha256sum | cut -d' ' -f1)
}

engine_image_built_from() { # <image> <sources hash>
    [ "$(docker image inspect --format '{{ index .Config.Labels "panelalpha.sources.sha256" }}' "$1" 2>/dev/null)" = "$2" ]
}

# Every image the compose project builds, once each: service, image, build context and Dockerfile, tab-separated.
engine_built_images() { # <compose command...>
    local config
    config=$("$@" config --format json) || return
    jq -er '[.services | to_entries[] | select(.value.build)] | unique_by(.value.image)[]
        | [.key, .value.image, .value.build.context, (.value.build.dockerfile // "Dockerfile")] | @tsv' <<<"$config"
}

# The published image's source hash; empty when it is not published, carries none, or the registry cannot be asked.
engine_published_sources() { # <image>
    docker buildx imagetools inspect --format '{{ index .Image.Config.Labels "panelalpha.sources.sha256" }}' "$1" 2>/dev/null
}

# Another tag of the image's repository on the host built from these sources, e.g. the previous release's, tagged as it.
engine_retag_built_from() { # <image> <sources hash>
    local other
    [ -n "$2" ] || return 1
    other=$(docker images --filter "label=panelalpha.sources.sha256=$2" --format '{{.Repository}}:{{.Tag}}' "${1%:*}" 2>/dev/null |
        grep -vx -- "$1" | head -n1)
    [ -n "$other" ] && docker tag "$other" "$1"
}

# A published image built from these sources is pulled, so a rebuild of them reaches the host; one built from other
# sources is never pulled; then the host's image counts only when built from these sources, else it is built here.
ensure_engine_image() { # <service> <image> <sources> <compose command...>
    local service="$1" image="$2" sources="$3" published
    shift 3
    published=$(engine_published_sources "$image")
    if [ "$published" = "$sources" ] || { [ -z "$published" ] && ! engine_image_built_from "$image" "$sources"; }; then
        "$@" pull --quiet --ignore-pull-failures "$service" >/dev/null 2>&1 || true
    fi
    engine_image_built_from "$image" "$sources" && return 0
    engine_retag_built_from "$image" "$sources" && return 0
    "$@" build --build-arg "PANELALPHA_SOURCES=${sources}" "$service"
}

# Before the copy: every image the release builds counts only when its label holds this release's source hash.
prepare_engine_images() {
    local src="$INSTALL_DIR/src" host="$PANELALPHA_DIR/shared-hosting" images service image context dockerfile sources webserver
    local compose=(docker compose --project-directory "$src" -f "$src/docker-compose.yml")
    [ -f "$host/.env" ] && compose+=(--env-file "$host/.env")
    # docker-compose.yml includes it, and it is host state, not in the clone.
    webserver="$host/docker-compose.yml-webserver"
    [ -f "$webserver" ] || webserver="$src/docker-compose.yml-nginx-proxy"
    cp "$webserver" "$src/docker-compose.yml-webserver"
    images=$(engine_built_images "${compose[@]}") ||
        echo_error "Could not read the engine images from the new docker-compose.yml"
    while IFS=$'\t' read -r service image context dockerfile; do
        sources=$(engine_image_sources "$context" "$dockerfile") ||
            echo_error "Could not read the ${service} image's sources from the new release"
        ensure_engine_image "$service" "$image" "$sources" "${compose[@]}" ||
            echo_error "Could not build the ${service} image; the engine was not changed"
    done <<<"$images"
    rm -f "$src/docker-compose.yml-webserver"
}

install_panelalpha_engine() {

    PUBLIC_HOST=$(ip route get 8.8.8.8 | sed -n '/src/{s/.*src *\([^ ]*\).*/\1/p;q}')
    if ipcalc "$PUBLIC_HOST" | grep -q 'Private Internet' && [ "$NO_LOCAL_IP" = 1 ]; then
        PUBLIC_HOST=$(curl --proto '=https' -s4 https://icanhazip.com)
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
    # 0640 root:www-data before any secret goes in; sed -i keeps both.
    bash /opt/panelalpha/shared-hosting/scripts/secure-env-core.sh /opt/panelalpha/shared-hosting/.env-core
    # retired settings
    sed -i '/^PRIVILEGED_\(BROKER_AREAS\|TRANSPORT\)=/d' /opt/panelalpha/shared-hosting/.env-core

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
    bash /opt/panelalpha/shared-hosting/scripts/ensure-docker-network.sh "${DOCKER_NETWORK_MTU}"
    # The accounts' network; sites-db and the registries join it.
    apply_tenant_network_firewall
    # Closed before every Docker start until the root broker binds it.
    bash /opt/panelalpha/shared-hosting/scripts/tenant-network-firewall.sh --install-units ||
        echo_warning "Could not install the tenant network's boot unit; after a reboot accounts run unfiltered until the root broker starts"

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
    mark_engine_stack_touched
    remove_renamed_containers
    bash /opt/panelalpha/shared-hosting/scripts/retire-dockerhub-mirror.sh /opt/panelalpha/shared-hosting/.env

    # sites-db and the registries join pash-tenants: without it the
    # stack cannot start, so stop here and say why rather than at compose.
    docker network inspect pash-tenants >/dev/null 2>&1 ||
        echo_error "The accounts' network pash-tenants could not be created; the tenant-network-firewall messages above say why"

    remove_unstarted_containers
    start_engine_stack
    check_engine_stack
    resume_broker_rotation

    # run database migrations
    wait_for_database
    # A re-run keeps core/bootstrap/cache; the app cache stays, it holds scheduler locks.
    docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T core php artisan optimize:clear --except=cache
    docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T core php artisan migrate --force
    resume_core_background
    ensure_default_backup_store
    # Accounts from before it leave core's network for pash-tenants,
    # live. Already-moved and stopped ones are left alone, and one that cannot
    # move keeps working where it is, so it never fails the update.
    docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T core php artisan project:network:move --all || true
    # PHP errors reach a project's own file only once its ini and pool carry the
    # directives; accounts from before that are rewritten here.
    docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T core php artisan projects:php-config:refresh --all || true
    # Puts each account's stored CPU limit on its compose file and container, live.
    docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T core php artisan project:cpu-limit:apply --all || true
    docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T core php artisan projects:domain-config:refresh --all ||
        echo_warning "Could not rewrite the domain vhosts of every PHP hosting project; 'pae projects:domain-config:refresh --all' retries"
    docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T core php artisan projects:wp-config:restrict --all ||
        echo_warning "Could not restrict the wp-config.php of every PHP hosting project; 'pae projects:wp-config:restrict --all' retries"
    # Applies on each container's next recreate; no site restarts.
    docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T core php artisan projects:config-mounts:restrict --all ||
        echo_warning "Could not make the config mounts of every PHP hosting project read-only; 'pae projects:config-mounts:restrict --all' retries"
    # Exim listens on docker0's gateway, which differs per host (bip, or a
    # 172.17 clash), and on pash-tenants' gateway, the PA_SMTP_HOST container
    # projects get, and relays for pash-tenants; all are read from Docker.
    docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T core php artisan system:exim:rebuild --networks || true
    # Stored NAT maps for docker0 and the engine's bridges go, whether or not NAT is rebuilt.
    docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T core php artisan system:nat:prune || true
    if [ "$ENABLE_NAT" = 1 ]; then
        docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T core php artisan system:nat:build --replace-default-ipv4 || true
    fi
}

clean_installer() {
    rm -rf "$INSTALL_DIR"
}

# The engine's served certificate, crt/server.cert on :2011.
# A name (--domain or the recorded cert_domain) gets Let's Encrypt, no name the IP certificate.
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

    local cert_name="$DOMAIN"
    if [ -z "$cert_name" ]; then
        cert_name=$(docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T core php artisan settings:get cert_domain 2>/dev/null | tr -d '\r' | tail -n 1)
    fi

    if [ "$NO_CERT_REQUEST" = 1 ]; then
        if [ -n "$cert_name" ]; then
            echo_info "Not requesting a certificate (--no-cert-request). Point ${cert_name} at this host, then:"
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
            IPV4=$(curl --proto '=https' -s4 --max-time 10 https://icanhazip.com || true)
            [ -n "$IPV4" ] && ! ipcalc "$IPV4" | grep -q 'Private Internet' && private=0
        fi
    fi
    if [ "$private" = 1 ] && [ -z "$cert_name" ]; then
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
    if [ -z "$cert_name" ]; then
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
            echo_warning "Could not obtain a Let's Encrypt certificate for ${cert_name}"
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

# A hash of sites-http's config directory; fails when the webserver is unknown.
webserver_config_state() {
    local host="$PANELALPHA_DIR/shared-hosting" variant
    variant=$(sed -n 's/.*com\.panelalpha\.webserver=\([a-z-]*\).*/\1/p' "$host/docker-compose.yml-webserver" 2>/dev/null | head -n1)
    [ -n "$variant" ] && [ -d "$host/webserver-config/$variant" ] || return 1
    (cd "$host/webserver-config/$variant" && set -o pipefail && find . -type f -print0 | LC_ALL=C sort -z | xargs -0r sha256sum | sha256sum | cut -d' ' -f1)
}

webserver_running() {
    docker compose -f "$PANELALPHA_DIR/shared-hosting/docker-compose.yml" ps --services --filter status=running 2>/dev/null | grep -qx sites-http
}

# A restart, as a reload cannot rebind nginx off `listen 80`; skipped on an update that changed nothing.
render_webserver_config() {
    local before
    before=$(webserver_config_state) || before=''
    docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T core php artisan system:domain:rebuild \
        || echo_warning "Could not rebuild the webserver configuration"
    if [ "$ENGINE_OP" = update ] && [ -n "$before" ] && [ "$(webserver_config_state)" = "$before" ] && webserver_running; then
        echo_info "The webserver configuration did not change; sites-http keeps running"
        return 0
    fi
    docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml restart sites-http \
        || echo_warning "Could not restart the webserver"
}

post_install_config() {
    # Hardening ran before the stack came up, in harden_host.
    set_default_ip
    configure_quota
    configure_home_mounts
    configure_lxcfs
    configure_apparmor
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

# Expand owner/repo / schemeless hosts like engine GitRepoInput.
normalize_deploy_repo_url() {
    local raw="$1"
    raw="${raw#"${raw%%[![:space:]]*}"}"
    raw="${raw%"${raw##*[![:space:]]}"}"
    case "$raw" in
    '' | ssh://* | git@*)
        printf '%s' "$raw"
        return 0
        ;;
    esac
    if [[ "$raw" =~ ^[a-zA-Z][a-zA-Z0-9+.-]*:// ]]; then
        printf '%s' "$raw"
        return 0
    fi
    if [[ "$raw" =~ ^[^/\ .]+/[^/\ ]+$ ]]; then
        printf 'https://github.com/%s' "$raw"
        return 0
    fi
    printf 'https://%s' "${raw#/}"
}

# Classify git ls-remote stderr (GitRemoteProbe signatures).
classify_git_ls_remote_failure() { # stderr_text has_token(0|1)
    local stderr="$1" has_token="${2:-0}"
    local low
    low=$(printf '%s' "$stderr" | tr '[:upper:]' '[:lower:]')

    case "$low" in
    *'could not resolve host'* | *'could not resolve proxy'* | *'failed to connect'* | \
        *'connection refused'* | *'connection timed out'* | *'network is unreachable'* | \
        *'ssl certificate problem'* | *'gnutls_handshake'* | *'empty reply from server'*)
        printf '%s' 'unreachable'
        return 0
        ;;
    esac

    case "$low" in
    *'could not read username'* | *'authentication failed'* | *'invalid username or password'* | \
        *'terminal prompts disabled'* | *'http basic: access denied'* | *'403 forbidden'* | \
        *'401 unauthorized'*)
        if [ "$has_token" = 1 ]; then
            printf '%s' 'rejected'
        else
            printf '%s' 'requires_token'
        fi
        return 0
        ;;
    esac

    case "$low" in
    *'repository not found'* | *'not found: did you run git update-server-info'* | \
        *'the project you were looking for could not be found'* | \
        *'does not appear to be a git repository'*)
        if [ "$has_token" != 1 ]; then
            printf '%s' 'requires_token'
        else
            printf '%s' 'not_found'
        fi
        return 0
        ;;
    esac

    printf '%s' 'unreadable'
}

# Fail before install/update when --repo cannot be read (private without token).
validate_deploy_repo_access() {
    [ -n "$DEPLOY_REPO" ] || return 0

    local url token has_token=0 err outcome host askpass="" workspace="" rc
    url=$(normalize_deploy_repo_url "$DEPLOY_REPO")
    token="$DEPLOY_GIT_TOKEN"

    case "$url" in
    ssh://* | git@*)
        echo_error "SSH repository URLs are not supported. Use an HTTPS URL and pass --git-token for private repositories."
        ;;
    esac

    if [[ "$url" =~ ^[a-zA-Z][a-zA-Z0-9+.-]*://[^/@]+@ ]]; then
        has_token=1
    fi
    if [ -n "$token" ]; then
        has_token=1
    fi

    if ! command -v git >/dev/null 2>&1; then
        echo_warning "git is not installed; skipping early --repo reachability check."
        return 0
    fi

    workspace=$(mktemp -d 2>/dev/null || true)
    if [ -n "$token" ] && [ -n "$workspace" ]; then
        askpass="$workspace/askpass.sh"
        {
            printf '#!/bin/sh\n'
            printf "printf '%%s' '"
            printf '%s' "$token" | sed "s/'/'\\\\''/g"
            printf "'\n"
        } >"$askpass"
        chmod 700 "$askpass" || askpass=""
    fi

    set +e
    if [ -n "$askpass" ]; then
        if command -v timeout >/dev/null 2>&1; then
            err=$(timeout 10 env GIT_TERMINAL_PROMPT=0 GIT_ASKPASS="$askpass" SSH_ASKPASS="$askpass" \
                LC_ALL=C LANG=C git -c credential.helper= -c core.askpass= ls-remote --heads -- "$url" 2>&1 >/dev/null)
        else
            err=$(env GIT_TERMINAL_PROMPT=0 GIT_ASKPASS="$askpass" SSH_ASKPASS="$askpass" \
                LC_ALL=C LANG=C git -c credential.helper= -c core.askpass= ls-remote --heads -- "$url" 2>&1 >/dev/null)
        fi
    else
        if command -v timeout >/dev/null 2>&1; then
            err=$(timeout 10 env GIT_TERMINAL_PROMPT=0 GIT_ASKPASS=/bin/false SSH_ASKPASS=/bin/false \
                LC_ALL=C LANG=C git -c credential.helper= -c core.askpass= ls-remote --heads -- "$url" 2>&1 >/dev/null)
        else
            err=$(env GIT_TERMINAL_PROMPT=0 GIT_ASKPASS=/bin/false SSH_ASKPASS=/bin/false \
                LC_ALL=C LANG=C git -c credential.helper= -c core.askpass= ls-remote --heads -- "$url" 2>&1 >/dev/null)
        fi
    fi
    rc=$?
    set -e
    [ -n "$workspace" ] && rm -rf "$workspace"

    [ "$rc" -eq 0 ] && return 0

    if [ "$rc" -eq 124 ]; then
        host=$(printf '%s' "$url" | sed -E 's#^[a-zA-Z][a-zA-Z0-9+.-]*://([^/@]+@)?([^/]+).*#\2#')
        echo_error "${host:-The repository host} did not answer within 10s. Check the address and network, then retry."
    fi

    outcome=$(classify_git_ls_remote_failure "$err" "$has_token")
    host=$(printf '%s' "$url" | sed -E 's#^[a-zA-Z][a-zA-Z0-9+.-]*://([^/@]+@)?([^/]+).*#\2#')
    : "${host:=the repository host}"

    case "$outcome" in
    requires_token)
        echo_error "This repository is private, or does not exist. Pass a read token as --git-token, or check the address."
        ;;
    rejected)
        echo_error "The token was refused by ${host}. Check it has not expired and that it grants read access to this repository."
        ;;
    not_found)
        echo_error "No such repository on ${host}, or the token cannot see it."
        ;;
    unreachable)
        echo_error "Could not reach ${host}. This host must be able to open an HTTPS connection to it."
        ;;
    *)
        local first
        first=$(printf '%s\n' "$err" | sed '/^[[:space:]]*$/d' | head -n1 | cut -c1-300)
        echo_error "The repository could not be read: ${first:-no output from git}"
        ;;
    esac
}

# TUI --configure writes this so the background worker does not ls-remote again.
mark_deploy_repo_access_ok() {
    [ -n "${RUN_DIR:-}" ] && [ -d "$RUN_DIR" ] || return 0
    : >"$RUN_DIR/repo_access_ok"
}

# Probe once: --configure for TUI, or main path when --no-tui / direct installer.
ensure_deploy_repo_access() {
    [ -n "$DEPLOY_REPO" ] || return 0
    if [ -n "${RUN_DIR:-}" ] && [ -f "$RUN_DIR/repo_access_ok" ]; then
        return 0
    fi
    validate_deploy_repo_access
    mark_deploy_repo_access_ok
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

# The reason project:create gave: its first unindented line (the deploy log it
# streams is indented, and the reason follows it on stderr), else the log's
# "Deploy failed: …", else the last non-empty line. Never the "Deploy log:"
# hint, nor the "In <file> line <n>:" header artisan puts above an exception.
deploy_error_reason() {
    local out="$1" line reason="" failed=""
    while IFS= read -r line; do
        case "$line" in
        '' | [[:space:]]* | 'Deploy log:'* | 'In '*' line '*':') ;;
        *)
            [ -n "$reason" ] || reason=$(printf '%s' "$line" | sed -E 's/[[:space:]]+$//')
            ;;
        esac
        case "$line" in
        *'Deploy failed:'*)
            failed=$(printf '%s' "$line" | sed -E 's/.*Deploy failed:[[:space:]]*//; s/[[:space:]]+$//')
            ;;
        esac
    done <<EOF
$out
EOF
    [ -n "$reason" ] || reason="$failed"
    if [ -z "$reason" ]; then
        reason=$(printf '%s\n' "$out" | sed '/^[[:space:]]*$/d' | tail -n1 | sed -E 's/^[[:space:]]+//; s/[[:space:]]+$//')
    fi
    printf '%s' "$reason"
}

# The repository the one-liner asked for, deployed into a project of its own.
# Everything the project needs beyond the repository -- the account name, the
# domain, the template -- is generated by the API, so nothing is invented here.
#
# A failed deploy is reported, not fatal: on an install the engine itself is up
# and usable, and saying so beats rolling it back over a bad repository URL.
# Whether the running core's project:create knows --update-existing (Engine 2.3.0 and later).
core_create_updates_existing() {
    docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T core \
        php artisan project:create --help 2>/dev/null | grep -q -- '--update-existing'
}

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
    if [ "${DEPLOY_FULL_PRIVILEGES:-0}" = 1 ]; then
        args+=(--full-privileges)
        # The panel: run again, the install updates the one it made rather than adding a second. A deploy-only run
        # talks to the core already running, which may be older than this script and not know the option.
        if core_create_updates_existing; then
            args+=(--update-existing)
        fi
    fi
    local pair
    for pair in "${DEPLOY_ENV_VARS[@]+"${DEPLOY_ENV_VARS[@]}"}"; do
        args+=("--env-var=${pair}")
    done
    if [ -n "${PANEL_ADMIN_EMAIL:-}" ]; then
        [ -n "$PANEL_ADMIN_PASSWORD" ] || PANEL_ADMIN_PASSWORD=$(generate_panel_admin_password)
        args+=("--env-var=PANELALPHA_USER_EMAIL=${PANEL_ADMIN_EMAIL}" "--env-var=PANELALPHA_USER_PASSWORD=${PANEL_ADMIN_PASSWORD}")
    fi
    if [ -n "${PANEL_HUB_CODE:-}" ]; then
        args+=("--env-var=PANELALPHA_HUB_CODE=${PANEL_HUB_CODE}")
    fi

    # --json: JSON on stdout; the live deploy log, then any failure reason, on
    # stderr. stderr is streamed to the caller's stdout (TUI tails it) via fd 3.
    # A pipeline rather than >(tee …): bash waits for tee, so the reason -- the
    # last line written -- is in $err_file before it is read.
    local out_file err_file result ec=0
    out_file=$(mktemp)
    err_file=$(mktemp)

    exec 3>&1
    {
        docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T core \
            php artisan "${args[@]}" 2>&1 >"$out_file" | tee "$err_file" >&3
        ec=${PIPESTATUS[0]}
    } || true
    exec 3>&-

    result=$(
        cat "$out_file" 2>/dev/null
        printf '\n'
        cat "$err_file" 2>/dev/null
    )
    rm -f "$out_file" "$err_file"

    if [ "$ec" -ne 0 ]; then
        DEPLOY_FAILED=1
        DEPLOY_ERROR=$(deploy_error_reason "$result")
        echo_warning "Could not deploy ${DEPLOY_REPO_LABEL}:"
        if [ -n "$DEPLOY_ERROR" ]; then
            echo_warning "  ${DEPLOY_ERROR}"
        else
            printf '%s\n' "$result" >&2
        fi
        return 0
    fi

    # The JSON line out of whatever else the deploy wrote to the terminal.
    local json domain
    json=$(printf '%s\n' "$result" | grep -E '^\{"data":' | tail -n1 || true)
    DEPLOY_PROJECT_NAME=$(json_field username "$json")
    domain=$(json_field domain "$json")
    if [ -z "$DEPLOY_PROJECT_NAME" ] || [ -z "$domain" ]; then
        DEPLOY_FAILED=1
        DEPLOY_ERROR=$(deploy_error_reason "$result")
        [ -n "$DEPLOY_ERROR" ] || DEPLOY_ERROR='project:create returned no project JSON'
        echo_warning "Deployed ${DEPLOY_REPO_LABEL}, but could not read the project it was deployed into:"
        echo_warning "  ${DEPLOY_ERROR}"
        return 0
    fi
    DEPLOY_PROJECT_URL="https://${domain}"
    if printf '%s' "$json" | grep -q '"updated":true'; then
        # Its admin, environment and Hub pairing stay: what this run generated for a new one was not applied.
        DEPLOY_UPDATED=1
        PANEL_ADMIN_PASSWORD=''
        PANEL_HUB_CODE=''
        return 0
    fi
    apply_deploy_site_password
}

# Alphanumeric only — safe to copy/paste from a terminal without escaping.
generate_deploy_site_password() {
    local pw=''
    # Keep drawing until we have 16 chars (tr can yield short reads).
    while [ "${#pw}" -lt 16 ]; do
        pw=$(tr -dc 'A-Za-z0-9' </dev/urandom 2>/dev/null | head -c 16 || true)
        [ -n "$pw" ] || pw=$(openssl rand -base64 24 2>/dev/null | tr -dc 'A-Za-z0-9' | head -c 16 || true)
        [ "${#pw}" -ge 16 ] && break
        sleep 0.05
    done
    printf '%.16s' "$pw"
}

# Default after --repo: generate + project:set-password. --no-password skips;
# --password uses the given value. Failure warns; the project stays deployed.
apply_deploy_site_password() {
    DEPLOY_PASSWORD_SET=0
    [ "$DEPLOY_NO_PASSWORD" = 1 ] && return 0
    [ -n "$DEPLOY_PROJECT_NAME" ] || return 0

    local password="$DEPLOY_SITE_PASSWORD"
    if [ -z "$password" ]; then
        password=$(generate_deploy_site_password)
    fi
    if [ -z "$password" ]; then
        echo_warning "Could not generate a site password for ${DEPLOY_PROJECT_NAME}"
        return 0
    fi

    if docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T core \
        php artisan project:set-password \
        "--project=${DEPLOY_PROJECT_NAME}" \
        "--password=${password}" \
        --force >/dev/null 2>&1; then
        DEPLOY_SITE_PASSWORD="$password"
        DEPLOY_PASSWORD_SET=1
        echo_info "Site password set for ${DEPLOY_PROJECT_NAME}"
    else
        echo_warning "Could not set site password for ${DEPLOY_PROJECT_NAME} (try: pae project:set-password --project ${DEPLOY_PROJECT_NAME})"
        DEPLOY_SITE_PASSWORD=''
    fi
}

# Two 8-character halves joined by a dash, drawn until both cases and a digit are in.
generate_panel_admin_password() {
    local pw=''
    while :; do
        pw=$(generate_deploy_site_password)
        case "$pw" in *[A-Z]*) ;; *) continue ;; esac
        case "$pw" in *[a-z]*) ;; *) continue ;; esac
        case "$pw" in *[0-9]*) ;; *) continue ;; esac
        break
    done
    printf '%s-%s' "${pw:0:8}" "${pw:8:8}"
}

# The command an operator retries a failed --repo deploy with.
deploy_retry_command() {
    local cmd="pae project:create --repo ${DEPLOY_REPO_LABEL}"
    [ "${DEPLOY_FULL_PRIVILEGES:-0}" = 1 ] && cmd="${cmd} --full-privileges"
    printf '%s' "$cmd"
}

# Where the repository ended up — stdout for logs, and the same lines in the
# TUI's ready screen. The outro writers live inside finish_installation, so
# this is called from there and nowhere else.
report_deployed_project() {
    if [ -z "$DEPLOY_REPO" ]; then
        return 0
    fi

    if [ "$DEPLOY_FAILED" = 1 ]; then
        echo_warning "${DEPLOY_REPO_LABEL} was not deployed."
        if [ -n "$DEPLOY_ERROR" ]; then
            echo_warning "  ${DEPLOY_ERROR}"
            outro_say 221 "${DEPLOY_REPO_LABEL} was not deployed:"
            outro_say 196 "  ${DEPLOY_ERROR}"
        else
            echo_warning "Try again with:"
            echo_warning "  $(deploy_retry_command)"
            outro_say 221 "${DEPLOY_REPO_LABEL} was not deployed. Try again with:"
            outro_c 15 "  $(deploy_retry_command)"
            outro_nl
        fi
        echo_warning "Try again with: $(deploy_retry_command)"
        outro_nl
        outro_c 247 'Try again: '
        outro_c 15 "$(deploy_retry_command)"
        outro_nl
        outro_nl
        return 0
    fi

    if [ "${DEPLOY_UPDATED:-0}" = 1 ]; then
        echo_info "${DEPLOY_REPO_LABEL} was already deployed as ${DEPLOY_PROJECT_NAME}: updated it, its admin and settings unchanged"
    fi
    echo_info "${DEPLOY_REPO_LABEL} available at: ${DEPLOY_PROJECT_URL}"
    echo_info "Project: ${DEPLOY_PROJECT_NAME}    logs: pae project:deploy:log ${DEPLOY_PROJECT_NAME}"
    if [ "$DEPLOY_PASSWORD_SET" = 1 ] && [ -n "$DEPLOY_SITE_PASSWORD" ]; then
        echo_info "Password: ${DEPLOY_SITE_PASSWORD}"
    fi
    if [ -n "${PANEL_ADMIN_PASSWORD:-}" ]; then
        echo_info "Log in as: ${PANEL_ADMIN_EMAIL}    password: ${PANEL_ADMIN_PASSWORD}"
    fi
    if [ -n "${PANEL_HUB_CODE:-}" ]; then
        echo_info "The panel connects to PanelAlpha Hub on its first start; watch the Hub page you copied the command from."
    fi
    echo_info ""
    if [ "${DEPLOY_UPDATED:-0}" = 1 ]; then
        outro_say 247 "Updated ${DEPLOY_PROJECT_NAME}, which already ran it; its admin and settings are unchanged."
    fi
    outro_c 15 "${DEPLOY_REPO_LABEL}"
    outro_c 247 ' available at: '
    outro_c 39 "${DEPLOY_PROJECT_URL}"
    outro_nl
    if [ "$DEPLOY_PASSWORD_SET" = 1 ] && [ -n "$DEPLOY_SITE_PASSWORD" ]; then
        outro_c 247 'Password: '
        outro_c 15 "${DEPLOY_SITE_PASSWORD}"
        outro_nl
    fi
    if [ -n "${PANEL_ADMIN_PASSWORD:-}" ]; then
        outro_c 247 'Log in as: '
        outro_c 15 "${PANEL_ADMIN_EMAIL}"
        outro_c 247 '  password: '
        outro_c 15 "${PANEL_ADMIN_PASSWORD}"
        outro_nl
    fi
    if [ -n "${PANEL_HUB_CODE:-}" ]; then
        outro_c 247 'Connects to PanelAlpha Hub on its first start; watch the Hub page.'
        outro_nl
    fi
    outro_nl
}

# Connect the engine to PanelAlpha Connect with --hub-code. Like a failed
# deploy, a failed connect is reported, not fatal: the engine is up, and the
# operator can run the same command again with a new code.
connect_hub() {
    [ -n "$HUB_CODE" ] || return 0

    local out ec=0
    out=$(docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T \
        ${PANELALPHA_HUB:+-e} ${PANELALPHA_HUB:+"PANELALPHA_HUB=${PANELALPHA_HUB}"} core \
        php artisan hub:connect "$HUB_CODE" 2>&1) || ec=$?
    out=$(printf '%s\n' "$out" | tr -d '\r')
    printf '%s\n' "$out"

    if [ "$ec" -ne 0 ]; then
        HUB_FAILED=1
        HUB_ERROR=$(printf '%s\n' "$out" | sed 's/^[[:space:]]*//; s/[[:space:]]*$//' | grep -v '^$' | tail -n1 || true)
        echo_warning "Could not connect to PanelAlpha Connect."
        return 0
    fi
    HUB_ACCOUNT=$(printf '%s\n' "$out" | sed -n 's/^Connected to \(.*\)\.$/\1/p' | tail -n1)
}

# The connect result for stdout and the TUI ready screen; called from
# finish_installation, where the outro writers live.
report_hub_connection() {
    [ -n "$HUB_CODE" ] || return 0

    if [ "$HUB_FAILED" = 1 ]; then
        echo_warning "Not connected to PanelAlpha Connect${HUB_ERROR:+: ${HUB_ERROR}}"
        echo_warning "Get a new code from Connect engine in PanelAlpha Connect and run: pae hub:connect CODE"
        outro_say 221 'Not connected to PanelAlpha Connect:'
        [ -z "$HUB_ERROR" ] || outro_say 196 "  ${HUB_ERROR}"
        outro_c 247 'Get a new code from Connect engine and run: '
        outro_c 15 'pae hub:connect CODE'
        outro_nl
        outro_nl
        return 0
    fi

    echo_info "Connected to PanelAlpha Connect: ${HUB_ACCOUNT:-connected}"
    echo_info "Not your account? Disconnect with: pae hub:disconnect"
    echo_info ""
    outro_c 247 'Connected to PanelAlpha Connect: '
    outro_c 15 "${HUB_ACCOUNT:-connected}"
    outro_nl
    outro_c 247 'Not your account? Disconnect with: '
    outro_c 15 'pae hub:disconnect'
    outro_nl
    outro_nl
}

# Strip a leading v so 2.0.1 and v2.0.1 compare equal.
normalize_version() {
    printf '%s' "$1" | sed -E 's/^[vV]//'
}

# older, same or newer: a target release against the running one.
release_order() { # <running> <target>
    if [ "$1" = "$2" ]; then
        echo same
    elif [ "$(printf '%s\n%s\n' "$1" "$2" | sort -V | head -n1)" = "$2" ]; then
        echo older
    else
        echo newer
    fi
}

# Stops an update before anything changed. A deliberate stop, so monitoring hears nothing.
stop_update() { # <exit code> <result> <reason>
    record_run result "$2"
    record_run reason "$3"
    if [ "$1" = 0 ]; then
        record_run outro "$3"
        echo_info "$3"
    else
        echo -e ">>> ${red_color}$3${default_color}"
    fi
    clean_installer
    trap - EXIT
    exit "$1"
}

# Once the clone is in and before the live tree is touched: a newer release, or a newer commit of the running one,
# is installed; an older release only when allowed, and the very same commit only when asked for again.
check_update_target() {
    local running target running_commit target_commit
    running=$(normalize_version "$(detect_installed_engine_version)")
    target=$(normalize_version "$(version_from_system_php "$(cat "$INSTALL_DIR/src/core/config/system.php" 2>/dev/null)")")
    running_commit=$(detect_installed_engine_commit)
    target_commit=$(printf '%.8s' "$(tr -d '\r\n' <"$INSTALL_DIR/src/version" 2>/dev/null)")
    record_run from_version "$running"
    record_run to_version "$target"
    record_run from_commit "$running_commit"
    record_run to_commit "$target_commit"
    [ "$ENGINE_OP" = update ] || return 0
    [ -n "$target" ] || [ "$ALLOW_DOWNGRADE" = 1 ] ||
        stop_update 101 refused "Could not read the release version of ${PANELALPHA_ENGINE_VERSION}. Nothing was changed. Pass --allow-downgrade to install it anyway."
    if [ -z "$running" ]; then
        echo_warning "Could not read the running engine's version; installing ${target:-the target} without comparing"
        return 0
    fi
    case "$(release_order "$running" "${target:-0}")" in
    older)
        [ "$ALLOW_DOWNGRADE" = 1 ] ||
            stop_update 101 refused "Refusing to install ${target} (${PANELALPHA_ENGINE_VERSION}) over the running ${running}: it is an older release. Nothing was changed. Pass --allow-downgrade to install it anyway."
        ;;
    same)
        if [ -z "$running_commit" ] || [ "$running_commit" != "$target_commit" ]; then
            UPDATE_NOTE="Updating within ${running}: ${running_commit:-unknown} → ${target_commit:-unknown} (${PANELALPHA_ENGINE_VERSION})"
            echo_info "$UPDATE_NOTE"
        elif [ "$REINSTALL" != 1 ] && [ "$UPDATE_ENGINE" != 1 ]; then
            stop_update 0 up_to_date "Already up to date: ${running} (${running_commit}) is running and ${PANELALPHA_ENGINE_VERSION} is the same commit. Nothing was changed. Pass --reinstall to install it again."
        fi
        ;;
    esac
}

# Label like "2.0.1 (b9f8c105)" for update messages.
engine_identity_label() { # product_version short_sha
    local ver="${1:-}" sha="${2:-}"
    [ -n "$ver" ] || ver=unknown
    [ -n "$sha" ] || sha=unknown
    printf '%s (%s)' "$ver" "$sha"
}

# Exit 0 when installed tip is unknown or differs from target (ver or sha).
engine_update_available() { # installed_ver installed_sha target_ver target_sha
    local iv="$1" is="$2" tv="$3" ts="$4"
    if [ -z "$is" ]; then
        return 0
    fi
    if [ -n "$iv" ] && [ -n "$tv" ] && [ "$iv" != "$tv" ]; then
        return 0
    fi
    if [ -n "$ts" ] && [ "$is" != "$ts" ]; then
        return 0
    fi
    return 1
}

# Product version from a system.php blob (or empty).
version_from_system_php() {
    printf '%s' "$1" | sed -nE "s/.*'version'[[:space:]]*=>[[:space:]]*'([^']+)'.*/\1/p" | head -n1
}

# Fill PEEK_TARGET_VERSION + PEEK_TARGET_COMMIT for ENGINE_REPO @ PANELALPHA_ENGINE_VERSION.
# Must not be called inside $() — the commit is a side effect for labels.
PEEK_TARGET_VERSION=''
PEEK_TARGET_COMMIT=''
peek_target_engine_identity() {
    local tmp php
    PEEK_TARGET_VERSION=''
    PEEK_TARGET_COMMIT=''
    [ -n "$ENGINE_REPO" ] && [ -n "$PANELALPHA_ENGINE_VERSION" ] || return 0
    command -v git >/dev/null 2>&1 || return 0

    tmp=$(mktemp -d)
    export GIT_TERMINAL_PROMPT=0
    if git clone --depth 1 --filter=blob:none --sparse --branch "$PANELALPHA_ENGINE_VERSION" \
        "$ENGINE_REPO" "$tmp/repo" >/dev/null 2>&1; then
        git -C "$tmp/repo" sparse-checkout set core/config/system.php >/dev/null 2>&1 || true
    elif ! git clone --depth 1 --branch "$PANELALPHA_ENGINE_VERSION" \
        "$ENGINE_REPO" "$tmp/repo" >/dev/null 2>&1; then
        rm -rf "$tmp"
        return 0
    fi

    PEEK_TARGET_COMMIT=$(git -C "$tmp/repo" rev-parse --short HEAD 2>/dev/null | tr -d '\r\n' || true)
    php="$tmp/repo/core/config/system.php"
    if [ -f "$php" ]; then
        PEEK_TARGET_VERSION=$(version_from_system_php "$(cat "$php")")
    fi
    rm -rf "$tmp"
}

# Compatibility wrapper for tests that only need the product version string.
peek_target_engine_version() {
    peek_target_engine_identity
    printf '%s' "$PEEK_TARGET_VERSION"
}

peek_target_label() {
    engine_identity_label \
        "$(normalize_version "${PEEK_TARGET_VERSION:-}")" \
        "${PEEK_TARGET_COMMIT:-}"
}

# When --repo hits a host that already has an engine: update+deploy, or deploy only.
# Interactive choice lives in the TUI wrapper (logo screen); this only honors
# --update-engine / --deploy-only, equal identity, or a no-TTY default.
decide_repo_deploy_mode() {
    DEPLOY_ONLY=0
    [ -n "$DEPLOY_REPO" ] || return 0
    [ -f "${PANELALPHA_DIR}/shared-hosting/docker-compose.yml" ] || return 0

    # Choice already made in --configure (same --run-dir).
    if [ -n "${RUN_DIR:-}" ] && [ -f "$RUN_DIR/repo_deploy_only" ]; then
        DEPLOY_ONLY=$(tr -d '\r\n' <"$RUN_DIR/repo_deploy_only" || true)
        if [ -f "$RUN_DIR/repo_engine_op" ]; then
            ENGINE_OP=$(tr -d '\r\n' <"$RUN_DIR/repo_engine_op" || true)
        fi
        [ "$DEPLOY_ONLY" = 1 ] || DEPLOY_ONLY=0
        return 0
    fi

    resolve_engine_version

    if [ "${UPDATE_ENGINE:-0}" = 1 ]; then
        ENGINE_OP=update
        DEPLOY_ONLY=0
        echo_info "Updating engine and deploying ${DEPLOY_REPO_LABEL}"
        _persist_repo_deploy_choice
        return 0
    fi

    if [ "${FORCE_DEPLOY_ONLY:-0}" = 1 ]; then
        DEPLOY_ONLY=1
        echo_info "Deploying ${DEPLOY_REPO_LABEL} only (--deploy-only)"
        _persist_repo_deploy_choice
        return 0
    fi

    local installed installed_sha target target_sha from_label to_label
    installed=$(normalize_version "$(detect_installed_engine_version)")
    installed_sha=$(detect_installed_engine_commit)
    peek_target_engine_identity
    target=$(normalize_version "$PEEK_TARGET_VERSION")
    target_sha="${PEEK_TARGET_COMMIT:-}"
    from_label=$(engine_identity_label "$installed" "$installed_sha")
    to_label=$(engine_identity_label "$target" "$target_sha")

    if ! engine_update_available "$installed" "$installed_sha" "$target" "$target_sha"; then
        DEPLOY_ONLY=1
        echo_info "Engine is already at ${from_label}; deploying ${DEPLOY_REPO_LABEL} only"
        _persist_repo_deploy_choice
        return 0
    fi

    # No interactive prompt here — the wrapper paints the logo screen. Without
    # a prior flag, deploy the app only and tell the operator how to update.
    DEPLOY_ONLY=1
    echo_warning "Engine update available (${from_label} → ${to_label}); deploying ${DEPLOY_REPO_LABEL} only. Re-run with --update-engine to update the engine too."
    _persist_repo_deploy_choice
}

_persist_repo_deploy_choice() {
    [ -n "${RUN_DIR:-}" ] && [ -d "$RUN_DIR" ] || return 0
    printf '%s\n' "$DEPLOY_ONLY" >"$RUN_DIR/repo_deploy_only"
    printf '%s\n' "$ENGINE_OP" >"$RUN_DIR/repo_engine_op"
}

finish_installation() {
    # Stdout: verbose for --no-tui / logs / legacy sed fallback.
    # $RUN_DIR/outro: mockup-shaped ANSI body for the TUI ready screen only
    # (wrapper already draws logo, success line, and 100% bar).
    # repo_deploy_failed: wrapper drops "and deployed" from the success banner
    # when the engine is up but project:create failed (exit stays 0 on install).
    if [ -n "${RUN_DIR:-}" ] && [ -d "$RUN_DIR" ]; then
        printf '%s\n' "${DEPLOY_FAILED:-0}" >"$RUN_DIR/repo_deploy_failed"
        if [ -n "$HUB_CODE" ]; then
            printf '%s\n' "$HUB_FAILED" >"$RUN_DIR/hub_failed"
        fi
    fi
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
    # Nothing was installed on this run, so the project and the connection are
    # the whole report.
    if [ "$DEPLOY_ONLY" = 1 ] || [ "$HUB_ONLY" = 1 ]; then
        report_deployed_project
        report_hub_connection
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
    report_hub_connection

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

    # The panel's install (--full-privileges): the panel is what its owner uses, so no Claude
    # Code token is minted and no command to paste ends the install.
    if [ "${DEPLOY_FULL_PRIVILEGES:-0}" = 1 ]; then
        outro_nl
        finish_commit
        return
    fi

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

# TUI preflight before the update-vs-deploy prompt: probe --repo, and disk space
# when an engine install/update is certain (not a possible deploy-only choice).
if [ "$CONFIGURE_MODE" = 1 ]; then
    define_variables
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
    if [ -n "$DEPLOY_REPO" ]; then
        DEPLOY_REPO_LABEL=$(repo_label "$DEPLOY_REPO")
        ensure_deploy_repo_access
    fi
    # Fresh install, or CLI --update-engine: fail before the TUI progress screen.
    if [ "$NO_DISK_SPACE_CHECK" = 0 ]; then
        if [ ! -f /opt/panelalpha/shared-hosting/docker-compose.yml ] || [ "$UPDATE_ENGINE" = 1 ]; then
            check_disk_space
        fi
    fi
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

if [ "$ENGINE_OP" = update ]; then
    restore_interrupted_update
fi

refuse_engine_v1_to_v2_upgrade

if [ -n "$DEPLOY_REPO" ]; then
    DEPLOY_REPO_LABEL=$(repo_label "$DEPLOY_REPO")
    ensure_deploy_repo_access
    decide_repo_deploy_mode
fi

# --hub-code alone on a host that already has an engine only connects it;
# --update-engine asks for the update as well. With --repo it follows the
# deploy decision above.
if [ -n "$HUB_CODE" ] && [ -z "$DEPLOY_REPO" ] && [ "$UPDATE_ENGINE" != 1 ] &&
    [ -f /opt/panelalpha/shared-hosting/docker-compose.yml ]; then
    HUB_ONLY=1
fi
if [ -n "$RUN_DIR" ] && [ -d "$RUN_DIR" ] && [ -n "$HUB_CODE" ]; then
    printf '%s\n' "$HUB_ONLY" >"$RUN_DIR/hub_only"
fi

if [ "$HUB_ONLY" = 1 ]; then
    check_root
    echo_info "PanelAlpha engine is already installed on this host"
    update_progress 50 "Connecting to PanelAlpha Connect"
    echo_info "Connecting to PanelAlpha Connect"
    connect_hub
    update_progress 100 "Finishing"
    finish_installation
    # No install or update happened, so there is no install status to report.
    trap - EXIT
    exit "$HUB_FAILED"
fi

# An engine is already here, so --repo is a project to create through the CLI,
# not a reason to install the engine over the top of itself.
if [ "$DEPLOY_ONLY" = 1 ]; then
    # before_install, which normally asks, is skipped on this path.
    check_root
    echo_info "PanelAlpha engine is already installed on this host"
    if [ -n "$HUB_CODE" ]; then
        # Before the deploy: the code expires ten minutes after it was created.
        update_progress 45 "Connecting to PanelAlpha Connect"
        echo_info "Connecting to PanelAlpha Connect"
        connect_hub
    fi
    set_installer_phase deploy
    update_progress 50 "Building ${DEPLOY_REPO_LABEL}"
    echo_info "Deploying ${DEPLOY_REPO_LABEL}"
    deploy_repository
    update_progress 100 "Finishing"
    finish_installation
    # Neither an install nor an update happened, so there is no install status
    # to report; the trap would send one for a run that changed no engine.
    trap - EXIT
    exit $((DEPLOY_FAILED | HUB_FAILED))
fi

resolve_engine_version

set_installer_phase engine
if [ "$ENGINE_OP" = update ]; then
    update_progress 5 "Preparing update"
    echo_info "Preparing update"
else
    update_progress 5 "Preparing installation"
    echo_info "Preparing directories"
fi

if [ "$NO_DISK_SPACE_CHECK" = 0 ]; then
    check_disk_space
fi

before_install

echo_info "Getting server hostname"
get_hostname

if [ -z "$ENGINE_REPO" ]; then
    echo_error "No engine source configured. Set PANELALPHA_ENGINE_REPO (or use: curl --proto '=https' -fsSL https://get.panelalpha.com/engine | sh)"
fi

update_progress 15 "Installing docker engine"
echo_info "Installing docker engine"
install_docker_engine

update_progress 25 "Download PanelAlpha engine package"
echo_info "Download PanelAlpha engine package"
download_panelalpha_engine
warn_old_host_git
check_update_target

if [ "$ENGINE_OP" = update ]; then
    update_progress 30 "Preparing PanelAlpha engine images"
    echo_info "Preparing PanelAlpha engine images"
    prepare_engine_images
    pause_core_background
    stop_core_tenant_network
    pause_broker_rotation
fi

update_progress 35 "Unzip PanelAlpha engine package"
echo_info "Unzip PanelAlpha engine package"
unzip_panelalpha_engine

update_progress 40 "Installing composer dependencies"
echo_info "Installing composer dependencies"
install_composer_dependencies

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
discard_engine_snapshot

update_progress 85 "Cleaning installation files"
echo_info "Cleaning installation files"
clean_installer

update_progress 90 "Applying additional configuration"
echo_info "Applying additional configuration"
post_install_config

# After post_install_config, so the Hub pins the final certificate; before the
# deploy, because the code expires ten minutes after it was created.
if [ -n "$HUB_CODE" ]; then
    update_progress 92 "Connecting to PanelAlpha Connect"
    echo_info "Connecting to PanelAlpha Connect"
    connect_hub
fi

if [ -n "$DEPLOY_REPO" ]; then
    set_installer_phase deploy
    update_progress 55 "Building ${DEPLOY_REPO_LABEL}"
    echo_info "Deploying ${DEPLOY_REPO_LABEL}"
    deploy_repository
fi

# Later steps restart services (sites-http, the certificate's `up -d`); report
# success only for a stack that is still up.
check_engine_stack

update_progress 100 "Finishing installation"
finish_installation
