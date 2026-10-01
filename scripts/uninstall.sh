#!/usr/bin/env bash
#
# Remove the PanelAlpha engine from this host (not the panel).
#
# Hosting accounts are removed first through the engine's own deletion path
# when core is running; otherwise a manual fallback cleans compose stacks,
# /home/$user and the OS account. --keep-projects leaves those behind on
# purpose (and warns that a later install may reuse the same UIDs).
#
#   bash uninstall.sh                  # remove the engine and every project
#   bash uninstall.sh --keep-projects  # leave the accounts and their data
#   bash uninstall.sh --yes            # do not ask
#
set -e

export DEBIAN_FRONTEND=noninteractive

default_color='\e[39m'
red_color='\e[31m'
green_color='\e[32m'
yellow_color='\e[33m'
reset_color='\e[0m'
b_green_color='\e[1;32m'

echo_info() { echo -e ">>> $green_color$1$default_color"; }
echo_warning() { echo -e ">>> $yellow_color$1$default_color"; }
echo_error() {
    echo -e ">>> $red_color$1$default_color"
    exit "${2:-199}"
}

PANELALPHA_DIR="/opt/panelalpha"
ENGINE_DIR="$PANELALPHA_DIR/shared-hosting"
APP_LITE_DIR="$PANELALPHA_DIR/app-lite"
APP_DIR="$PANELALPHA_DIR/app"
ENGINE_TMP_DIR="$PANELALPHA_DIR/tmp/engine"
DOCKER_NETWORK_NAME="pash-default-network"
TENANT_NETWORK_NAME="pash-tenants"

DEBUG_MODE=0
ASSUME_YES=0
KEEP_PROJECTS=0
REMOVE_DOCKER=0
REMOVE_SYSBOX=0
REMOVE_CSF=0
RESTORE_SYSTEMD_RESOLVED=0
PURGE_IMAGES=0

usage() {
    cat <<EOF
PanelAlpha engine uninstaller (dev/test oriented).

Always removes:
  - $ENGINE_DIR (containers, volumes, directory)
  - engine users / projects (unless --keep-projects)
  - pae / pae-artisan registration, sysctl/monit deconfigure
  - engine-installer.sh, log/engine-*, tmp/engine, letsencrypt cron
  - Docker networks $TENANT_NETWORK_NAME and $DOCKER_NETWORK_NAME (when unused)

Does NOT remove:
  - $APP_LITE_DIR or $APP_DIR (panel)
  - /etc/letsencrypt (reuse on reinstall; rate limits)

Optional flags (off by default):
  --keep-projects              Leave hosting accounts and /home data
  --remove-docker              Purge Docker Engine packages
  --remove-sysbox              Uninstall Sysbox runtime (stops all containers)
  --remove-csf                 Uninstall CSF firewall (if installed by PanelAlpha)
  --restore-systemd-resolved   Restore /etc/resolv.conf and systemd-resolved
  --purge-images               Remove ghcr.io/panelalpha/* Docker images
  -y, --yes                    Skip confirmation prompt
  --debug                      Enable bash trace mode
  --help                       Show this help

Examples:
  bash $0 -y
  bash $0 --remove-sysbox --purge-images -y
  bash $0 --keep-projects -y
EOF
}

while [[ $# -gt 0 ]]; do
    case "$1" in
    --debug)
        DEBUG_MODE=1
        shift
        ;;
    -y | --yes)
        ASSUME_YES=1
        shift
        ;;
    --keep-projects)
        KEEP_PROJECTS=1
        shift
        ;;
    --remove-docker)
        REMOVE_DOCKER=1
        shift
        ;;
    --remove-sysbox)
        REMOVE_SYSBOX=1
        shift
        ;;
    --remove-csf)
        REMOVE_CSF=1
        shift
        ;;
    --restore-systemd-resolved)
        RESTORE_SYSTEMD_RESOLVED=1
        shift
        ;;
    --purge-images)
        PURGE_IMAGES=1
        shift
        ;;
    --help | -h)
        usage
        exit 0
        ;;
    *)
        echo_error "Unknown option: $1 (use --help)"
        ;;
    esac
done

if [ "$DEBUG_MODE" = 1 ]; then
    set -x
fi

has_panel() {
    [[ -f "$APP_LITE_DIR/docker-compose.yml" || -f "$APP_DIR/docker-compose.yml" ]]
}

check_root() {
    if [ "$EUID" -ne 0 ]; then
        echo_error "Please run as root!"
    fi
}

confirm_uninstall() {
    if [ "$ASSUME_YES" = 1 ]; then
        if has_panel; then
            echo_warning "Panel directories detected under $PANELALPHA_DIR — they will NOT be removed."
            echo_warning "Removing the engine will break a panel that depends on this host."
        fi
        return 0
    fi

    echo ""
    echo_warning "This will remove the PanelAlpha engine from this host:"
    echo "  - $ENGINE_DIR"
    if [ "$KEEP_PROJECTS" = 1 ]; then
        echo "  - hosting accounts KEPT (--keep-projects)"
    else
        echo "  - engine users / projects (containers, /home, OS accounts)"
    fi
    echo "  - pae command, sysctl/monit config written by the engine"
    echo "  - engine-installer.sh, log/engine-*, tmp/engine, letsencrypt cron"
    echo "  - Docker networks $TENANT_NETWORK_NAME and $DOCKER_NETWORK_NAME (if unused)"
    if has_panel; then
        echo ""
        echo_warning "Panel detected (app-lite and/or app). It will be LEFT in place."
        echo_warning "Continuing removes the engine underneath that panel."
    fi
    if [ "$REMOVE_SYSBOX" = 1 ]; then
        echo "  - Sysbox runtime"
    fi
    if [ "$REMOVE_CSF" = 1 ]; then
        echo "  - CSF firewall"
    fi
    if [ "$REMOVE_DOCKER" = 1 ]; then
        echo "  - Docker Engine packages"
    fi
    if [ "$RESTORE_SYSTEMD_RESOLVED" = 1 ]; then
        echo "  - restore systemd-resolved configuration"
    fi
    if [ "$PURGE_IMAGES" = 1 ]; then
        echo "  - ghcr.io/panelalpha/* Docker images"
    fi
    echo ""
    read -rp "Continue? [y/N] " answer
    case "${answer,,}" in
    y | yes) ;;
    *)
        echo_info "Uninstall cancelled."
        exit 0
        ;;
    esac
}

compose_down() {
    local compose_file="$1"
    if [ ! -f "$compose_file" ]; then
        echo_warning "Compose file not found: $compose_file"
        return 0
    fi
    if ! command -v docker >/dev/null 2>&1; then
        echo_warning "Docker is not installed, skipping compose down for $compose_file"
        return 0
    fi
    docker compose -f "$compose_file" down -v --remove-orphans || true
}

uninstall_csf_if_requested() {
    if [ "$REMOVE_CSF" != 1 ]; then
        return 0
    fi
    if [ ! -f /etc/csf/version.txt ]; then
        echo_warning "CSF is not installed, skipping."
        return 0
    fi
    if [ -f "$ENGINE_DIR/scripts/csf.sh" ]; then
        echo_info "Uninstalling CSF..."
        bash "$ENGINE_DIR/scripts/csf.sh" --uninstall || true
    elif [ -f /usr/src/csf/uninstall.sh ]; then
        echo_info "Uninstalling CSF..."
        /bin/bash /usr/src/csf/uninstall.sh || true
    else
        echo_warning "CSF uninstall script not found, skipping."
    fi
}

uninstall_sysbox_if_requested() {
    if [ "$REMOVE_SYSBOX" != 1 ]; then
        return 0
    fi
    if [ -f "$ENGINE_DIR/scripts/install-sysbox.sh" ]; then
        echo_info "Uninstalling Sysbox runtime..."
        bash "$ENGINE_DIR/scripts/install-sysbox.sh" --uninstall || true
    elif command -v sysbox-runc >/dev/null 2>&1; then
        echo_warning "Sysbox is installed but engine scripts are missing; skipping Sysbox removal."
    else
        echo_warning "Sysbox is not installed, skipping."
    fi
}

engine_core_running() {
    if [ ! -f "$ENGINE_DIR/docker-compose.yml" ]; then
        return 1
    fi
    if ! command -v docker >/dev/null 2>&1; then
        return 1
    fi
    docker compose -f "$ENGINE_DIR/docker-compose.yml" ps --status running --services 2>/dev/null | grep -qx core
}

collect_engine_usernames() {
    local users_dir="$ENGINE_DIR/users"
    local user_dir username

    if [ ! -d "$users_dir" ]; then
        return 0
    fi

    for user_dir in "$users_dir"/*; do
        [ -d "$user_dir" ] || continue
        username=$(basename "$user_dir")
        [ -n "$username" ] || continue
        echo "$username"
    done
}

delete_engine_user_via_artisan() {
    local username="$1"

    echo_info "Deleting engine user: $username"
    docker compose -f "$ENGINE_DIR/docker-compose.yml" exec -T core \
        php artisan project:delete "$username" --force ||
        echo_warning "Failed to delete engine user via artisan: $username"
}

delete_engine_user_manually() {
    local username="$1"
    local compose_file="$ENGINE_DIR/users/$username/docker-compose.yml"

    echo_info "Manually cleaning engine user: $username"
    compose_down "$compose_file"
    rm -rf "/home/$username"
    if id "$username" >/dev/null 2>&1; then
        userdel "$username" || echo_warning "Failed to delete OS user: $username"
    fi
}

uninstall_engine_users() {
    local username

    if [ "$KEEP_PROJECTS" = 1 ]; then
        echo_warning "Keeping project accounts. Their home directories stay in /home under the UIDs they hold now,"
        echo_warning "and a later install will reuse those UIDs for new accounts — remove them by hand first."
        return 0
    fi

    if [ ! -d "$ENGINE_DIR/users" ]; then
        # Still try bulk delete if core is up (DB may list projects without users/ dirs).
        if engine_core_running; then
            echo_info "Removing hosting accounts via project:delete --all..."
            docker compose -f "$ENGINE_DIR/docker-compose.yml" exec -T core \
                php artisan project:delete --all --force ||
                echo_warning "Some accounts could not be removed via artisan."
        fi
        return 0
    fi

    mapfile -t engine_usernames < <(collect_engine_usernames)
    if [ ${#engine_usernames[@]} -eq 0 ]; then
        if engine_core_running; then
            echo_info "Removing hosting accounts via project:delete --all..."
            docker compose -f "$ENGINE_DIR/docker-compose.yml" exec -T core \
                php artisan project:delete --all --force ||
                echo_warning "Some accounts could not be removed via artisan."
        fi
        return 0
    fi

    echo_info "Removing ${#engine_usernames[@]} engine user(s)..."
    if engine_core_running; then
        # Prefer one bulk call when possible; fall back per-user.
        if ! docker compose -f "$ENGINE_DIR/docker-compose.yml" exec -T core \
            php artisan project:delete --all --force; then
            echo_warning "Bulk project:delete failed; trying per-user..."
            for username in "${engine_usernames[@]}"; do
                delete_engine_user_via_artisan "$username"
            done
        fi
    else
        echo_warning "Engine core is not running, falling back to manual user cleanup."
        for username in "${engine_usernames[@]}"; do
            delete_engine_user_manually "$username"
        done
    fi
}

deconfigure_engine() {
    if [ ! -d "$ENGINE_DIR" ]; then
        return 0
    fi

    if [ -f "$ENGINE_DIR/scripts/pae-command.sh" ]; then
        echo_info "Unregistering pae command..."
        bash "$ENGINE_DIR/scripts/pae-command.sh" unregister || true
    fi

    if [ -f "$ENGINE_DIR/scripts/deconfigure-sysctl.sh" ]; then
        echo_info "Removing sysctl configuration..."
        bash "$ENGINE_DIR/scripts/deconfigure-sysctl.sh" || true
    fi

    if [ -f "$ENGINE_DIR/scripts/deconfigure-monit.sh" ]; then
        echo_info "Removing monit configuration..."
        bash "$ENGINE_DIR/scripts/deconfigure-monit.sh" || true
    fi
}

uninstall_engine_stack() {
    if [ ! -d "$ENGINE_DIR" ]; then
        echo_warning "Engine directory not found: $ENGINE_DIR"
        return 0
    fi

    # CSF/Sysbox scripts live under shared-hosting — run before rm -rf.
    uninstall_csf_if_requested
    uninstall_sysbox_if_requested
    uninstall_engine_users
    deconfigure_engine

    echo_info "Stopping and removing PanelAlpha engine stack..."
    compose_down "$ENGINE_DIR/docker-compose.yml"
    # `down -v` only knows the volumes the current compose file declares.
    if [ -f "$ENGINE_DIR/scripts/retire-dockerhub-mirror.sh" ]; then
        bash "$ENGINE_DIR/scripts/retire-dockerhub-mirror.sh" || true
    fi
    rm -rf "$ENGINE_DIR"
    echo_info "Removed $ENGINE_DIR"
}

clean_engine_sidecars() {
    echo_info "Cleaning engine sidecars under $PANELALPHA_DIR..."
    rm -f "$PANELALPHA_DIR/engine-installer.sh"
    rm -rf "$PANELALPHA_DIR"/log/engine-*
    rm -rf "$ENGINE_TMP_DIR"
    if [ -d "$PANELALPHA_DIR/tmp" ] && [ -z "$(ls -A "$PANELALPHA_DIR/tmp" 2>/dev/null)" ]; then
        rmdir "$PANELALPHA_DIR/tmp" 2>/dev/null || true
    fi
    if [ -d "$PANELALPHA_DIR/log" ] && [ -z "$(ls -A "$PANELALPHA_DIR/log" 2>/dev/null)" ]; then
        rmdir "$PANELALPHA_DIR/log" 2>/dev/null || true
    fi

    # Written by the installer; leave /etc/letsencrypt (rate limits).
    rm -f /etc/cron.d/panelalpha-letsencrypt

    # Orphan binaries if unregister did not run (tree already gone).
    rm -f /usr/local/bin/paengine /usr/local/bin/pae /usr/local/bin/pae-artisan
    rm -f /etc/panelalpha/paengine.yaml
    rmdir /etc/panelalpha 2>/dev/null || true

    # Do not wipe panel or installer-origin. Only remove empty top-level dir.
    if [ -d "$PANELALPHA_DIR" ] && [ -z "$(ls -A "$PANELALPHA_DIR" 2>/dev/null)" ]; then
        rmdir "$PANELALPHA_DIR" 2>/dev/null || true
    fi
}

remove_docker_network() {
    local name
    if ! command -v docker >/dev/null 2>&1; then
        return 0
    fi
    for name in "$TENANT_NETWORK_NAME" "$DOCKER_NETWORK_NAME"; do
        docker network inspect "$name" >/dev/null 2>&1 || continue
        echo_info "Removing Docker network $name..."
        docker network rm "$name" >/dev/null 2>&1 ||
            echo_warning "Could not remove Docker network $name (it may still be in use)."
    done
    # The accounts' port bindings (tenant-network-firewall.sh).
    command -v nft >/dev/null 2>&1 && nft delete table bridge pa_tenants 2>/dev/null || true
}

restore_systemd_resolved_if_requested() {
    if [ "$RESTORE_SYSTEMD_RESOLVED" != 1 ]; then
        return 0
    fi
    if ! systemctl list-unit-files systemd-resolved.service >/dev/null 2>&1; then
        echo_warning "systemd-resolved service not found, skipping."
        return 0
    fi

    echo_info "Restoring systemd-resolved..."
    if [ -f /etc/resolv.conf.backup ]; then
        cp -a /etc/resolv.conf.backup /etc/resolv.conf
    fi
    systemctl unmask systemd-resolved >/dev/null 2>&1 || true
    systemctl enable systemd-resolved >/dev/null 2>&1 || true
    systemctl start systemd-resolved >/dev/null 2>&1 || true
}

purge_panelalpha_images_if_requested() {
    if [ "$PURGE_IMAGES" != 1 ]; then
        return 0
    fi
    if ! command -v docker >/dev/null 2>&1; then
        echo_warning "Docker is not installed, skipping image purge."
        return 0
    fi

    echo_info "Removing ghcr.io/panelalpha/* Docker images..."
    mapfile -t panelalpha_images < <(docker images --format '{{.Repository}}:{{.Tag}}' | grep '^ghcr.io/panelalpha/' || true)
    if [ ${#panelalpha_images[@]} -eq 0 ]; then
        echo_warning "No ghcr.io/panelalpha/* images found."
        return 0
    fi
    docker rmi -f "${panelalpha_images[@]}" >/dev/null 2>&1 ||
        echo_warning "Some PanelAlpha images could not be removed (they may still be referenced)."
}

remove_docker_if_requested() {
    if [ "$REMOVE_DOCKER" != 1 ]; then
        return 0
    fi

    local docker_packages=(
        docker-ce
        docker-ce-cli
        containerd.io
        docker-buildx-plugin
        docker-compose-plugin
    )
    local purge_packages=()
    local pkg

    for pkg in "${docker_packages[@]}"; do
        if dpkg -s "$pkg" >/dev/null 2>&1; then
            purge_packages+=("$pkg")
        fi
    done

    if [ ${#purge_packages[@]} -eq 0 ]; then
        echo_warning "Docker packages are not installed, skipping."
        return 0
    fi

    echo_info "Purging Docker packages..."
    apt-get -o DPkg::Lock::Timeout=300 purge -y "${purge_packages[@]}" || true
    apt-get -o DPkg::Lock::Timeout=300 autoremove -y || true
}

warn_leftovers() {
    local leftovers homes

    if command -v docker >/dev/null 2>&1; then
        # After stack removal, leftover DinD / project containers mean account cleanup failed.
        leftovers=$(docker ps -a --format '{{.Names}}' 2>/dev/null | grep -E '^(dind-|project-)' || true)
        if [ -n "$leftovers" ]; then
            echo_warning "Containers still on this host (possible leftover projects):"
            printf '%s\n' "$leftovers" >&2
        fi
    fi

    if [ "$KEEP_PROJECTS" = 1 ]; then
        return 0
    fi
    homes=$(find /home -mindepth 1 -maxdepth 1 -type d 2>/dev/null || true)
    if [ -n "$homes" ]; then
        echo_warning "Home directories still present:"
        printf '%s\n' "$homes" >&2
        echo_warning "Each belongs to a UID a future install can hand to a different account."
    fi
}

check_root
confirm_uninstall

echo_info "Starting PanelAlpha engine uninstall..."

uninstall_engine_stack
remove_docker_network
clean_engine_sidecars
restore_systemd_resolved_if_requested
purge_panelalpha_images_if_requested
remove_docker_if_requested
warn_leftovers

echo ""
echo -e "${b_green_color}PanelAlpha engine uninstall completed.${reset_color}"
