#!/usr/bin/env bash
# PanelAlpha Sysbox Installation Script
# Installs Sysbox runtime for enhanced Docker-in-Docker security isolation

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Oldest release without the host-file escape through the inner Docker's volumes.
SYSBOX_MIN_VERSION="0.7.2"
SYSBOX_VERSION="${SYSBOX_VERSION:-$SYSBOX_MIN_VERSION}"
SYSBOX_ARCH="${SYSBOX_ARCH:-$(dpkg --print-architecture 2>/dev/null || echo amd64)}"
SYSBOX_RELEASES="${SYSBOX_RELEASES:-https://api.github.com/repos/nestybox/sysbox/releases}"
SYSBOX_DEB_URL="${SYSBOX_DEB_URL:-https://github.com/nestybox/sysbox/releases/download/v${SYSBOX_VERSION}/sysbox-ce_${SYSBOX_VERSION}.linux_${SYSBOX_ARCH}.deb}"
SYSBOX_DEB_SHA256="${SYSBOX_DEB_SHA256:-}"
DAEMON_JSON="${DAEMON_JSON:-/etc/docker/daemon.json}"
SYSTEMD_UNIT_DIR="${SYSTEMD_UNIT_DIR:-/etc/systemd/system}"
USER_HOME_DIR="${USER_HOME_DIR:-/home}"
RECOVER_SCRIPT="${RECOVER_SCRIPT:-/usr/local/sbin/recover-sysbox-dind.sh}"
SYSBOX_USER="${SYSBOX_USER:-sysbox}"

# Functions
log_info() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

log_warn() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
    exit 1
}

# Check dependencies
check_dependencies() {
    if ! command -v curl &> /dev/null; then
        log_error "curl is required but not installed"
    fi

    if ! command -v apt-get &> /dev/null; then
        log_error "apt-get is required but not available"
    fi

    if ! command -v docker &> /dev/null; then
        log_error "Docker is required but not installed"
    fi
}

# Ensure Docker daemon has 'time-namespaces' disabled to keep Sysbox working
ensure_time_namespaces_disabled() {
    TMP_JSON="$(mktemp)"
    DOCKER_DAEMON_MODIFIED=0

    if ! command -v jq >/dev/null 2>&1; then
        log_info "Installing 'jq' for JSON manipulation..."
        apt-get update -qq
        if ! apt-get install -y jq; then
            log_error "Failed to install 'jq' package"
        fi
    fi

    if [ ! -f "$DAEMON_JSON" ] || [ ! -s "$DAEMON_JSON" ]; then
        echo "{}" | tee "$DAEMON_JSON" >/dev/null
    fi

    if ! jq empty "$DAEMON_JSON" 2>/dev/null; then
        log_warn "Invalid JSON detected in $DAEMON_JSON, backing up and resetting"
        cp "$DAEMON_JSON" "${DAEMON_JSON}.bak.$(date +%s)" 2>/dev/null || true
        echo "{}" | tee "$DAEMON_JSON" >/dev/null
    fi

    current=$(jq -r '(.features // {})["time-namespaces"] // "unset"' "$DAEMON_JSON")
    if [ "$current" = "false" ]; then
        log_info "Docker daemon 'features.time-namespaces' already set to false"
        rm -f "$TMP_JSON" 2>/dev/null || true
        DOCKER_DAEMON_MODIFIED=0
        return 0
    fi

    jq '.features = (.features // {}) | .features["time-namespaces"] = false' "$DAEMON_JSON" > "$TMP_JSON" 2>/dev/null || {
        log_error "Failed to generate updated docker daemon json"
    }

    if cmp -s "$TMP_JSON" "$DAEMON_JSON" 2>/dev/null; then
        log_info "No changes required to $DAEMON_JSON"
        rm -f "$TMP_JSON"
        DOCKER_DAEMON_MODIFIED=0
    else
        cp "$DAEMON_JSON" "${DAEMON_JSON}.bak.$(date +%s)" 2>/dev/null || true
        mv "$TMP_JSON" "$DAEMON_JSON"
        log_info "Set Docker daemon 'features.time-namespaces' to false in $DAEMON_JSON"
        DOCKER_DAEMON_MODIFIED=1
    fi
}

# Docker, containerd and Sysbox are Go programs, which by default let their heap
# reach twice what is live before collecting; GOGC=50 collects at 1.5x.
# Written as systemd drop-ins, picked up at each daemon's next start: a fresh
# install restarts them here; on an existing host the next reboot does.
# Restarting Docker only for this would restart every account.
PANELALPHA_GOGC="${PANELALPHA_GOGC:-50}"
ensure_go_gc() {
    local unit dir file changed=0
    for unit in docker containerd sysbox-mgr sysbox-fs; do
        dir="${SYSTEMD_UNIT_DIR}/${unit}.service.d"
        file="${dir}/panelalpha-gogc.conf"
        if [ "$(cat "$file" 2>/dev/null)" != "$(printf '[Service]\nEnvironment=GOGC=%s' "$PANELALPHA_GOGC")" ]; then
            mkdir -p "$dir"
            printf '[Service]\nEnvironment=GOGC=%s\n' "$PANELALPHA_GOGC" > "$file"
            changed=1
        fi
    done
    if [ "$changed" -eq 1 ]; then
        systemctl daemon-reload 2>/dev/null || true
        log_info "Set GOGC=${PANELALPHA_GOGC} for Docker, containerd and Sysbox; it applies from their next start"
    else
        log_info "GOGC=${PANELALPHA_GOGC} already set for Docker, containerd and Sysbox"
    fi
}

# Send the host daemon's Docker Hub pulls through registry-proxy, like every
# account daemon's: prewarm, shared base builds and host compiles then pull under
# the proxy's REGISTRY_PROXY_USERNAME login, not anonymously from the host IP.
# dockerd reloads registry-mirrors on SIGHUP, so nothing restarts, and with the
# proxy down it falls straight through to Docker Hub. Empty stops adding it but
# does not remove one already set: a reload keeps the running value until the
# file says "registry-mirrors": [] or dockerd restarts.
PANELALPHA_REGISTRY_MIRROR="${PANELALPHA_REGISTRY_MIRROR-http://127.0.0.1:5001}"

ensure_registry_mirror() {
    local daemon_json="$DAEMON_JSON" tmp
    [ -n "$PANELALPHA_REGISTRY_MIRROR" ] || { log_info "Registry mirror disabled (PANELALPHA_REGISTRY_MIRROR is empty)"; return 0; }
    command -v jq >/dev/null 2>&1 || { log_warn "jq is missing; not setting the registry mirror"; return 0; }
    [ -s "$daemon_json" ] || echo "{}" > "$daemon_json"
    if ! jq empty "$daemon_json" 2>/dev/null; then
        log_warn "$daemon_json is not valid JSON; not setting the registry mirror"
        return 0
    fi

    tmp="$(mktemp)"
    jq --arg m "$PANELALPHA_REGISTRY_MIRROR" \
        '.["registry-mirrors"] = ((.["registry-mirrors"] // []) | if index($m) then . else [$m] + . end)' \
        "$daemon_json" > "$tmp" || { rm -f "$tmp"; log_warn "Could not update $daemon_json"; return 0; }
    if cmp -s "$tmp" "$daemon_json"; then
        rm -f "$tmp"
        log_info "Docker already mirrors Docker Hub through $PANELALPHA_REGISTRY_MIRROR"
        return 0
    fi
    cp "$daemon_json" "${daemon_json}.bak.$(date +%s)" 2>/dev/null || true
    cat "$tmp" > "$daemon_json" && rm -f "$tmp"

    # Not pidof: on a sysbox host that also lists every account's inner dockerd.
    systemctl reload docker 2>/dev/null || kill -HUP "$(cat /var/run/docker.pid 2>/dev/null)" 2>/dev/null || true
    sleep 2
    if docker info --format '{{json .RegistryConfig.Mirrors}}' 2>/dev/null | grep -qF "${PANELALPHA_REGISTRY_MIRROR%/}"; then
        log_info "Docker Hub pulls on this host now go through $PANELALPHA_REGISTRY_MIRROR"
    else
        log_warn "Added $PANELALPHA_REGISTRY_MIRROR to $daemon_json, but dockerd has not picked it up yet; it will on its next restart"
    fi
}

# A sysbox container that will not stop is wedged; core runs on runc, so it is up to recover it.
recover_with_the_engine() {
    docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T core \
        php artisan project:dind:recover --project="$1" --wipe-inner </dev/null
}

sysbox_installed_version() {
    if ! command -v sysbox-runc >/dev/null 2>&1; then
        echo ""
        return
    fi
    # Output contains a "version:" line like "version: 0.7.0"
    sysbox-runc --version 2>/dev/null | awk -F: '/version:/ {gsub(/^[ \t]+|[ \t]+$/,"",$2); print $2; exit}'
}

version_lt() {
    # return 0 if $1 < $2 (semver-ish)
    dpkg --compare-versions "$1" lt "$2" 2>/dev/null
}

ensure_fuse3() {
    if ! dpkg -l fuse3 2>/dev/null | grep -q '^ii'; then
        log_warn "fuse3 is missing - installing fuse3..."
        apt-get update -qq
        if ! apt-get install -y fuse3; then
            log_error "Failed to install fuse3 package"
        fi
    fi
}

# Ubuntu 25.10+ ships an AppArmor profile for fusermount3 (fuse3 3.17) that
# confines FUSE mounts to $HOME, /mnt, /media, /tmp and a few Flatpak paths.
# sysbox-fs mounts its per-container FUSE tree at /var/lib/sysboxfs/<id>/,
# which the profile rejects ("failed mntpnt match", EACCES), so every
# sysbox-runc container dies at "failed to pre-register with sysbox-fs".
# Allow that path through the profile's local override and reload it.
# No-op on hosts without the profile (Debian, Ubuntu <= 24.04).
ensure_fusermount_apparmor() {
    local profile=/etc/apparmor.d/fusermount3
    local override=/etc/apparmor.d/local/fusermount3

    if [ ! -f "$profile" ] || ! command -v apparmor_parser >/dev/null 2>&1; then
        return 0
    fi

    if [ -f "$override" ] && grep -q '/var/lib/sysboxfs/' "$override"; then
        log_info "fusermount3 AppArmor profile already allows sysbox-fs mounts"
        return 0
    fi

    mkdir -p /etc/apparmor.d/local
    cat >> "$override" <<'EOF'
# PanelAlpha: sysbox-fs mounts a FUSE filesystem per container under /var/lib/sysboxfs.
mount fstype=fuse options=(rw,nosuid,nodev) sysboxfs -> /var/lib/sysboxfs/**/,
umount /var/lib/sysboxfs/**/,
EOF

    # Ubuntu's profile ends with `include if exists <local/fusermount3>`; add
    # the include ourselves if a packaged profile ever drops it.
    if ! grep -q 'local/fusermount3' "$profile"; then
        cp "$profile" "${profile}.bak.$(date +%s)"
        sed -i '$ s/^}$/  include if exists <local\/fusermount3>\n}/' "$profile"
    fi

    if apparmor_parser -r "$profile"; then
        log_info "Allowed sysbox-fs FUSE mounts in the fusermount3 AppArmor profile"
    else
        log_warn "Could not reload $profile - sysbox containers will fail to start (check dmesg for apparmor DENIED fusermount3)"
    fi
}

# The sha256 GitHub publishes for the release's package, unless SYSBOX_DEB_SHA256 names one.
sysbox_package_sha256() {
    if [ -n "$SYSBOX_DEB_SHA256" ]; then
        echo "$SYSBOX_DEB_SHA256"
        return 0
    fi
    curl --proto '=https' -sSfL "${SYSBOX_RELEASES}/tags/v${SYSBOX_VERSION}" 2>/dev/null |
        jq -r --arg name "sysbox-ce_${SYSBOX_VERSION}.linux_${SYSBOX_ARCH}.deb" \
            '.assets[] | select(.name == $name) | .digest // empty | ltrimstr("sha256:")' 2>/dev/null
}

install_or_upgrade_sysbox_package() {
    local deb sha256
    if version_lt "$SYSBOX_VERSION" "$SYSBOX_MIN_VERSION"; then
        log_error "Sysbox ${SYSBOX_VERSION} is older than ${SYSBOX_MIN_VERSION}, the oldest release this engine runs on"
    fi
    sha256="$(sysbox_package_sha256)"
    if [ -z "$sha256" ]; then
        log_error "No sha256 for Sysbox ${SYSBOX_VERSION} (${SYSBOX_ARCH}) from ${SYSBOX_RELEASES}; set SYSBOX_DEB_SHA256 to install it"
    fi
    deb="$(mktemp --suffix=.deb)"
    log_info "Downloading Sysbox ${SYSBOX_VERSION}..."
    if ! curl --proto '=https' -sSfL "$SYSBOX_DEB_URL" -o "$deb"; then
        rm -f "$deb"
        log_error "Failed to download Sysbox from $SYSBOX_DEB_URL"
    fi
    if ! echo "$sha256  $deb" | sha256sum -c --status -; then
        rm -f "$deb"
        log_error "The Sysbox package from $SYSBOX_DEB_URL does not match its checksum"
    fi
    if ! apt-get install -y "$deb"; then
        rm -f "$deb"
        log_error "Failed to install Sysbox package"
    fi
    rm -f "$deb"
}

# Names, not ids: an account the engine recovers comes back as a new container.
running_sysbox_containers() {
    local ids
    ids="$(docker ps -q 2>/dev/null)"
    [ -n "$ids" ] || return 0
    # shellcheck disable=SC2086
    docker inspect --format '{{.Name}} {{.HostConfig.Runtime}}' $ids 2>/dev/null |
        awk '$2 == "sysbox-runc" { sub(/^\//, "", $1); print $1 }'
}

# Only what was running is started again, with its restart policy as it was:
# an account stopped on purpose stays stopped, and every account still comes
# back after a reboot. The engine stack runs on runc and keeps running.
upgrade_sysbox() {
    local name running=()
    log_warn "Sysbox $1 is older than ${SYSBOX_VERSION}"
    log_info "Upgrading Sysbox - stopping the running sysbox-runc containers..."
    while read -r name; do
        [ -n "$name" ] && running+=("$name")
    done < <(running_sysbox_containers)
    for name in "${running[@]}"; do
        timeout 60 docker stop "$name" >/dev/null 2>&1 || recover_with_the_engine "$name" || true
    done
    install_or_upgrade_sysbox_package
    systemctl restart sysbox 2>/dev/null || service sysbox restart 2>/dev/null || true
    ensure_time_namespaces_disabled
    if [ "${DOCKER_DAEMON_MODIFIED:-0}" -eq 1 ]; then
        systemctl restart docker 2>/dev/null || service docker restart 2>/dev/null || true
    fi
    if [ ${#running[@]} -gt 0 ]; then
        log_info "Starting the ${#running[@]} container(s) that were running..."
        for name in "${running[@]}"; do
            docker start "$name" >/dev/null 2>&1 || log_warn "Could not start $name"
        done
    fi
    log_info "Sysbox upgraded to ${SYSBOX_VERSION}"
}

restart_panelalpha_stacks() {
    if [ -f "/opt/panelalpha/shared-hosting/docker-compose.yml" ]; then
        log_info "Restarting PanelAlpha containers after Docker restart..."
        if [ -f "/opt/panelalpha/shared-hosting/scripts/container-manager.sh" ]; then
            bash /opt/panelalpha/shared-hosting/scripts/container-manager.sh engine start
            bash /opt/panelalpha/shared-hosting/scripts/container-manager.sh users up all
        else
            log_warn "Container manager script not found, starting containers manually..."
            docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml up -d
        fi
    fi
}

# Main installation function
install_sysbox() {
    log_info "Installing Sysbox runtime for enhanced security..."

    ensure_fuse3
    ensure_fusermount_apparmor
    ensure_go_gc

    local current
    current="$(sysbox_installed_version)"

    if [ -n "$current" ]; then
        if version_lt "$current" "$SYSBOX_VERSION"; then
            upgrade_sysbox "$current"
            return 0
        fi

        log_info "Sysbox ${current} and fuse3 are already installed"
        ensure_time_namespaces_disabled
        if [ "${DOCKER_DAEMON_MODIFIED:-0}" -eq 1 ]; then
            log_info "Restarting Docker to apply daemon.json changes..."
            if ! (systemctl restart docker 2>/dev/null || service docker restart 2>/dev/null); then
                log_error "Failed to restart Docker service"
            fi
        else
            log_info "Docker restart not required"
        fi
        return 0
    fi

    # Fresh install — stop all containers first (Sysbox packaging requirement)
    log_info "Stopping Docker containers before Sysbox installation..."
    docker stop $(docker ps -aq) 2>/dev/null || true
    docker rm $(docker ps -aq) 2>/dev/null || true

    log_info "Installing Sysbox ${SYSBOX_VERSION}..."
    install_or_upgrade_sysbox_package

    ensure_time_namespaces_disabled

    log_info "Restarting Docker to register Sysbox runtime..."
    if ! (systemctl restart docker 2>/dev/null || service docker restart 2>/dev/null); then
        log_error "Failed to restart Docker service"
    fi

    restart_panelalpha_stacks
    log_info "Sysbox runtime installed successfully"
}

# sysbox-fs gives itself 10 s to start, and on a busy host (Docker restarting,
# packages installing) it can miss that and stay failed while this script
# reports success; every account then fails with "sysfs.sock: no such file".
ensure_sysbox_running() {
    systemctl is-active --quiet sysbox-mgr && systemctl is-active --quiet sysbox-fs && return 0
    log_warn "Sysbox is not running - starting it"
    # start, not restart: whatever still runs keeps its containers.
    systemctl start sysbox 2>/dev/null || true
    if ! systemctl is-active --quiet sysbox-mgr || ! systemctl is-active --quiet sysbox-fs; then
        log_warn "Sysbox did not start (systemctl status sysbox-fs sysbox-mgr) - accounts will not start"
    fi
}

# The package leaves behind what it did not create through dpkg: the daemon.json
# entries that only make sense with the runtime, the drop-ins that tune it, the
# service user and the recovery script.
remove_sysbox_daemon_json() {
    local tmp
    [ -f "$DAEMON_JSON" ] || return 0
    if ! command -v jq >/dev/null 2>&1; then
        log_warn "jq is missing; $DAEMON_JSON may still name the sysbox-runc runtime"
        return 0
    fi
    if ! jq -e '
        (((.runtimes | type) == "object") and (.runtimes["sysbox-runc"] != null))
        or (((.features | type) == "object") and (.features["time-namespaces"] == false))
    ' "$DAEMON_JSON" >/dev/null 2>&1; then
        return 0
    fi

    tmp="$(mktemp)"
    if ! jq '
        if (((.runtimes | type) == "object") and (.runtimes["sysbox-runc"] != null)) then
            .runtimes |= del(.["sysbox-runc"])
            | if ((.runtimes | length) == 0) then del(.runtimes) else . end
        else . end
        | if (((.features | type) == "object") and (.features["time-namespaces"] == false)) then
            .features |= del(.["time-namespaces"])
            | if ((.features | length) == 0) then del(.features) else . end
        else . end
    ' "$DAEMON_JSON" >"$tmp" 2>/dev/null; then
        rm -f "$tmp"
        log_warn "Could not edit $DAEMON_JSON"
        return 0
    fi
    if cmp -s "$tmp" "$DAEMON_JSON"; then
        rm -f "$tmp"
        return 0
    fi
    cat "$tmp" >"$DAEMON_JSON"
    rm -f "$tmp"
    log_info "Removed the sysbox-runc runtime and the time-namespaces setting from $DAEMON_JSON"
}

remove_sysbox_configuration() {
    local unit dir

    for unit in docker containerd sysbox-mgr sysbox-fs; do
        dir="${SYSTEMD_UNIT_DIR}/${unit}.service.d"
        rm -f "${dir}/panelalpha-gogc.conf"
        rmdir "$dir" 2>/dev/null || true
    done

    if id "$SYSBOX_USER" >/dev/null 2>&1; then
        userdel "$SYSBOX_USER" >/dev/null 2>&1 || log_warn "Could not delete the $SYSBOX_USER user"
        rm -rf "${USER_HOME_DIR:?}/${SYSBOX_USER}"
    fi
    if getent group "$SYSBOX_USER" >/dev/null 2>&1; then
        groupdel "$SYSBOX_USER" >/dev/null 2>&1 || true
    fi

    remove_sysbox_daemon_json
    systemctl daemon-reload 2>/dev/null || true
}

# Prints "<id> <compose service>" for every container that runs under sysbox-runc.
sysbox_containers() {
    local ids
    ids="$(docker ps -aq 2>/dev/null)"
    [ -n "$ids" ] || return 0
    # shellcheck disable=SC2086
    docker inspect --format '{{.Id}} {{.HostConfig.Runtime}} {{index .Config.Labels "com.docker.compose.service"}}' $ids 2>/dev/null |
        awk '$2 == "sysbox-runc" { print $1, $3 }'
}

# Only the containers that cannot run without the runtime go. An engine account
# (the dind service of its compose project) takes its anonymous volumes with it;
# any other keeps them.
remove_sysbox_containers() {
    local id service accounts=() others=()
    while read -r id service; do
        [ -n "$id" ] || continue
        if [ "$service" = "dind" ]; then
            accounts+=("$id")
        else
            others+=("$id")
        fi
    done < <(sysbox_containers)
    [ $((${#accounts[@]} + ${#others[@]})) -gt 0 ] || return 0

    log_info "Stopping the sysbox-runc containers..."
    docker stop "${accounts[@]}" "${others[@]}" >/dev/null 2>&1 || true
    [ ${#accounts[@]} -eq 0 ] || docker rm -v "${accounts[@]}" >/dev/null 2>&1 || true
    [ ${#others[@]} -eq 0 ] || docker rm "${others[@]}" >/dev/null 2>&1 || true
}

# Uninstall Sysbox
uninstall_sysbox() {
    log_info "Uninstalling Sysbox runtime..."

    if ! command -v sysbox-runc >/dev/null 2>&1; then
        log_info "Sysbox is not installed"
        remove_sysbox_configuration
        return 0
    fi

    remove_sysbox_containers

    log_info "Removing Sysbox package..."
    if ! apt-get purge sysbox-ce -y; then
        log_error "Failed to purge Sysbox package"
    fi

    rm -f "$RECOVER_SCRIPT"
    remove_sysbox_configuration

    log_info "Restarting Docker..."
    if ! (systemctl restart docker 2>/dev/null || service docker restart 2>/dev/null); then
        log_error "Failed to restart Docker service"
    fi

    log_info "Sysbox runtime uninstalled successfully"
}

show_usage() {
    echo "PanelAlpha Sysbox Installation Script"
    echo ""
    echo "Usage:"
    echo "  bash install-sysbox.sh                # Install or upgrade Sysbox to ${SYSBOX_VERSION} (SYSBOX_VERSION, at least ${SYSBOX_MIN_VERSION})"
    echo "  bash install-sysbox.sh --uninstall    # Uninstall Sysbox"
    echo "  bash install-sysbox.sh --registry-mirror  # Only point Docker Hub pulls at registry-proxy"
    echo "  bash install-sysbox.sh --help         # Show this help"
    echo ""
    echo "This script installs or uninstalls Sysbox runtime for enhanced Docker-in-Docker security."
    echo "Supported systems: Debian, Ubuntu"
    echo ""
    echo "Requirements:"
    echo "  - curl (for install)"
    echo "  - apt-get"
    echo "  - Docker"
    echo "  - fuse3 (auto-installed as Sysbox dependency)"
}

# Main execution
main() {
    case "$1" in
        --help|-h)
            show_usage
            exit 0
            ;;
        --uninstall)
            uninstall_sysbox
            ;;
        --registry-mirror)
            ensure_registry_mirror
            ;;
        "")
            check_dependencies
            install_sysbox
            ensure_sysbox_running
            ensure_registry_mirror
            ;;
        *)
            echo "Unknown option: $1"
            echo ""
            show_usage
            exit 1
            ;;
    esac
}

# Run main function
main "$@"
