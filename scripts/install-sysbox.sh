#!/usr/bin/env bash
# PanelAlpha Sysbox Installation Script
# Installs Sysbox runtime for enhanced Docker-in-Docker security isolation

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

SYSBOX_VERSION="0.7.1"
# Package filename keeps the "-0" revision suffix used by Nestybox downloads.
SYSBOX_DEB_URL="https://downloads.nestybox.com/sysbox/releases/v${SYSBOX_VERSION}/sysbox-ce_${SYSBOX_VERSION}-0.linux_amd64.deb"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

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
    DAEMON_JSON="/etc/docker/daemon.json"
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

# Send the host daemon's Docker Hub pulls through registry-proxy, like every
# account daemon's: prewarm, shared base builds and host compiles then pull under
# the proxy's REGISTRY_PROXY_USERNAME login, not anonymously from the host IP.
# dockerd reloads registry-mirrors on SIGHUP, so nothing restarts, and with the
# proxy down it falls straight through to Docker Hub. Empty stops adding it but
# does not remove one already set: a reload keeps the running value until the
# file says "registry-mirrors": [] or dockerd restarts.
PANELALPHA_REGISTRY_MIRROR="${PANELALPHA_REGISTRY_MIRROR-http://127.0.0.1:5001}"

ensure_registry_mirror() {
    local daemon_json="/etc/docker/daemon.json" tmp
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

install_helper_script() {
    local name="$1"
    local dest="$2"
    local src="${SCRIPT_DIR}/${name}"
    if [ ! -f "$src" ]; then
        src="/opt/panelalpha/shared-hosting/scripts/${name}"
    fi
    if [ -f "$src" ]; then
        install -m 0755 "$src" "$dest"
        log_info "Installed ${dest}"
        return 0
    fi
    log_warn "${name} not found next to installer — skip"
    return 1
}

install_recovery_helpers() {
    install_helper_script "recover-sysbox-dind.sh" /usr/local/sbin/recover-sysbox-dind.sh || true
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

install_or_upgrade_sysbox_package() {
    log_info "Downloading Sysbox ${SYSBOX_VERSION}..."
    curl -sSfL "$SYSBOX_DEB_URL" -o /tmp/sysbox.deb
    if ! apt-get install -y /tmp/sysbox.deb; then
        rm -f /tmp/sysbox.deb
        log_error "Failed to install Sysbox package"
    fi
    rm -f /tmp/sysbox.deb
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
    install_recovery_helpers

    local current
    current="$(sysbox_installed_version)"

    if [ -n "$current" ]; then
        if version_lt "$current" "$SYSBOX_VERSION"; then
            log_warn "Sysbox ${current} is older than required ${SYSBOX_VERSION} (fixes FUSE deadlock #998)"
            log_info "Upgrading Sysbox — temporarily stopping sysbox-runc containers..."
            # Only stop sysbox containers; leave engine/app stacks running if possible.
            for id in $(docker ps -q); do
                rt=$(docker inspect -f '{{.HostConfig.Runtime}}' "$id" 2>/dev/null || true)
                if [ "$rt" = "sysbox-runc" ]; then
                    docker update --restart=no "$id" >/dev/null 2>&1 || true
                    timeout 60 docker stop "$id" >/dev/null 2>&1 || \
                        /usr/local/sbin/recover-sysbox-dind.sh --username="$(docker inspect -f '{{.Name}}' "$id" | sed 's#^/##')" --wipe-inner || true
                fi
            done
            install_or_upgrade_sysbox_package
            systemctl restart sysbox 2>/dev/null || service sysbox restart 2>/dev/null || true
            ensure_time_namespaces_disabled
            if [ "${DOCKER_DAEMON_MODIFIED:-0}" -eq 1 ]; then
                systemctl restart docker 2>/dev/null || service docker restart 2>/dev/null || true
            fi
            restart_panelalpha_stacks
            log_info "Sysbox upgraded to ${SYSBOX_VERSION}"
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

# Uninstall Sysbox
uninstall_sysbox() {
    log_info "Uninstalling Sysbox runtime..."

    if ! command -v sysbox-runc >/dev/null 2>&1; then
        log_info "Sysbox is not installed"
        return 0
    fi

    log_info "Stopping all Docker containers..."
    docker stop $(docker ps -aq) 2>/dev/null || true
    docker rm $(docker ps -aq) 2>/dev/null || true

    log_info "Removing Sysbox package..."
    if ! apt-get purge sysbox-ce -y; then
        log_error "Failed to purge Sysbox package"
    fi

    rm -f /usr/local/sbin/recover-sysbox-dind.sh

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
    echo "  bash install-sysbox.sh                # Install or upgrade Sysbox to ${SYSBOX_VERSION}"
    echo "  bash install-sysbox.sh --uninstall    # Uninstall Sysbox"
    echo "  bash install-sysbox.sh --help         # Show this help"
    echo ""
    echo "This script installs or uninstalls Sysbox runtime for enhanced Docker-in-Docker security."
    echo "Supported systems: Debian, Ubuntu"
    echo ""
    echo "Also installs:"
    echo "  /usr/local/sbin/recover-sysbox-dind.sh  # recover wedged DinD without host reboot"
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
        "")
            check_dependencies
            install_sysbox
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
