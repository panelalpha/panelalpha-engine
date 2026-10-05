#!/usr/bin/env bash
# Run a whole engine inside one container, for CI and local development.
#
# The engine normally owns a machine. This puts that machine in a container:
# a systemd container on the sysbox runtime, running its own Docker daemon,
# with the engine installed into it exactly as it would be installed onto a
# host. Hosting accounts still get their own nested Docker daemon; they just
# ask for privilege instead of sysbox, because sysbox cannot nest.
#
#   bash scripts/tools/engine-container.sh up          # build, start, install
#   bash scripts/tools/engine-container.sh up --installer   # via installer.sh, not bootstrap
#   bash scripts/tools/engine-container.sh up -p 80:80 -p 443:443   # publish tenant ports
#   bash scripts/tools/engine-container.sh shell       # a shell inside it
#   bash scripts/tools/engine-container.sh test        # run scripts/tools/ci-deploy-test.sh in it
#   bash scripts/tools/engine-container.sh logs        # follow the install/journal
#   bash scripts/tools/engine-container.sh down        # remove it, keep the image
#   bash scripts/tools/engine-container.sh destroy     # remove it and its volumes
#
# `up` is idempotent: run it again after editing the tree and it redeploys.
#
# Options for `up`:
#   --installer        install with installer.sh instead of
#                      bootstrap-from-source.sh, against the same mounted tree.
#                      The licensing and package-download steps are patched out
#                      the way dev-installer.sh does it, so the tree in front of
#                      you is the source rather than a downloaded zip.
#   -p, --publish H:C  publish a container port on the host; repeatable. The API
#                      (2011) is always published. Ports are fixed when the
#                      container is created, so add --recreate to change them.
#   --recreate         remove and recreate the container, keeping its volumes.
#
# Requires Sysbox on the real host (scripts/install-sysbox.sh). Everything
# after `--` is passed through to the installer being used.

set -euo pipefail

ENGINE_DIR="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/../.." && pwd)"
NAME="${ENGINE_CONTAINER_NAME:-engine-container}"
IMAGE="${ENGINE_CONTAINER_IMAGE:-panelalpha/engine-container:local}"
HOME_VOLUME="${NAME}-home"
STORAGE_VOLUME="${NAME}-storage"
CACHE_VOLUME="${NAME}-bootstrap-cache"
SEED_DIR='/seed' 
GUEST_DIR='/opt/panelalpha/shared-hosting'
API_PORT="${ENGINE_CONTAINER_API_PORT:-2011}"
# Fixed address on a dedicated network: nginx.conf, tenant vhosts, APP_URL and
# the certificate SANs are all keyed to it and written into the tree, where
# `cp -n` never refreshes them. A changed address leaves nginx crash-looping on
# `bind() ... Cannot assign requested address`.
NETWORK="${ENGINE_CONTAINER_NETWORK:-${NAME}-net}"
SUBNET="${ENGINE_CONTAINER_SUBNET:-172.31.66.0/24}"
STATIC_IP="${ENGINE_CONTAINER_IP:-172.31.66.10}"
BOOTSTRAP_ARGS=()
PUBLISH=()
PUBLISH_ARGS=()
INSTALL_MODE='bootstrap'
RECREATE=0

green='\033[0;32m'; yellow='\033[1;33m'; red='\033[0;31m'; plain='\033[0m'
step() { echo -e "${green}>>> $1${plain}"; }
warn() { echo -e "${yellow}>>> $1${plain}"; }
die()  { echo -e "${red}>>> $1${plain}" >&2; exit 1; }

COMMAND=''
while [ $# -gt 0 ]; do
    case "$1" in
    --) shift; BOOTSTRAP_ARGS=("$@"); break ;;
    --installer) INSTALL_MODE='installer'; shift ;;
    --recreate) RECREATE=1; shift ;;
    -p | --publish)
        [ $# -ge 2 ] || die "$1 needs a HOST:CONTAINER argument"
        case "$2" in
        *:*) PUBLISH+=("$2") ;;
        *) die "--publish expects HOST:CONTAINER (e.g. 80:80), got '$2'" ;;
        esac
        shift 2
        ;;
    -h | --help) sed -n '2,34p' "$0"; exit 0 ;;
    -*) die "Unknown option: $1" ;;
    *) [ -z "$COMMAND" ] && COMMAND="$1" && shift || die "Unexpected argument: $1" ;;
    esac
done
COMMAND="${COMMAND:-up}"

running() { [ "$(docker inspect -f '{{.State.Running}}' "$NAME" 2>/dev/null)" = true ]; }
exists()  { docker inspect "$NAME" >/dev/null 2>&1; }

require_sysbox() {
    docker info --format '{{json .Runtimes}}' 2>/dev/null | grep -q 'sysbox-runc' \
        || die "Sysbox is not registered with the host's Docker. Install it first: bash scripts/install-sysbox.sh"
}

build_image() {
    step "Building ${IMAGE}"
    docker build -t "$IMAGE" -f "${ENGINE_DIR}/dockerfiles/Dockerfile-engine-container" "${ENGINE_DIR}/dockerfiles"
}

start_container() {
    if [ "$RECREATE" = 1 ] && exists; then
        step "Recreating ${NAME} (volumes are kept)"
        docker rm -f "$NAME" >/dev/null
    fi
    if running || exists; then
        # Published ports and mounts are fixed at creation, so a --publish that
        # arrives now cannot take effect. Say so rather than appearing to work.
        if [ ${#PUBLISH[@]} -gt 0 ] && ! ports_already_published; then
            warn "${NAME} already exists — published ports cannot change on a running container."
            warn "Re-run with --recreate to apply: ${PUBLISH[*]}"
        fi
    fi
    if running; then
        step "${NAME} is already running"
        return
    fi
    if exists; then
        step "Starting the existing ${NAME}"
        docker start "$NAME" >/dev/null
        return
    fi

    # /home must be a volume: an account's data-root lands there and overlayfs
    # cannot mount on overlayfs. The daemon starts and pulls fine, then every
    # `docker run` fails with `invalid argument`.
    local spec
    PUBLISH_ARGS=()
    for spec in "${PUBLISH[@]+"${PUBLISH[@]}"}"; do
        PUBLISH_ARGS+=(-p "$spec")
    done

    docker network inspect "$NETWORK" >/dev/null 2>&1 || {
        step "Creating network ${NETWORK} (${SUBNET})"
        docker network create --subnet "$SUBNET" "$NETWORK" >/dev/null
    }

    step "Creating ${NAME} (runtime=sysbox-runc, ${STATIC_IP})"
    [ ${#PUBLISH[@]} -eq 0 ] || step "Publishing ${PUBLISH[*]} in addition to the API on ${API_PORT}"
    docker volume create "$HOME_VOLUME" >/dev/null
    docker volume create "$STORAGE_VOLUME" >/dev/null
    docker volume create "$CACHE_VOLUME" >/dev/null
    # The tree is bind-mounted for live edits, but core/storage and
    # core/bootstrap/cache are volumes: the engine chowns them to www-data,
    # which stops the developer running the unit suite. Mounted again read-only
    # at $SEED_DIR to seed them.
    docker run -d \
        --runtime=sysbox-runc \
        --name "$NAME" \
        --hostname "$NAME" \
        --restart unless-stopped \
        --network "$NETWORK" \
        --ip "$STATIC_IP" \
        -v "${ENGINE_DIR}:${GUEST_DIR}" \
        -v "${ENGINE_DIR}:${SEED_DIR}:ro" \
        -v "${STORAGE_VOLUME}:${GUEST_DIR}/core/storage" \
        -v "${CACHE_VOLUME}:${GUEST_DIR}/core/bootstrap/cache" \
        -v "${HOME_VOLUME}:/home" \
        -p "${API_PORT}:2011" \
        "${PUBLISH_ARGS[@]+"${PUBLISH_ARGS[@]}"}" \
        "$IMAGE" >/dev/null
}

# Whether every --publish the caller asked for is already bound on the running
# container, so the "cannot change" warning only fires when it is true.
ports_already_published() {
    local spec bound
    bound=$(docker inspect -f '{{range $p, $c := .NetworkSettings.Ports}}{{range $c}}{{.HostPort}}:{{$p}} {{end}}{{end}}' "$NAME" 2>/dev/null)
    for spec in "${PUBLISH[@]}"; do
        case "$bound" in
        *"${spec%%:*}:${spec##*:}/"*) : ;;
        *) return 1 ;;
        esac
    done
    return 0
}

seed_storage() {
    # A new volume is empty; Laravel needs the storage skeleton. Copy it once.
    local pair target source
    for pair in "core/storage:${STORAGE_VOLUME}" "core/bootstrap/cache:${CACHE_VOLUME}"; do
        target="${GUEST_DIR}/${pair%%:*}"
        source="${SEED_DIR}/${pair%%:*}"
        if docker exec "$NAME" sh -c "[ -n \"\$(ls -A '${target}' 2>/dev/null)\" ]"; then
            continue
        fi
        step "Seeding ${pair%%:*} from the working tree"
        docker exec "$NAME" sh -c "cp -a '${source}/.' '${target}/' 2>/dev/null || true"
    done
    docker exec "$NAME" sh -c "chown -R www-data:www-data '${GUEST_DIR}/core/storage' '${GUEST_DIR}/core/bootstrap/cache' 2>/dev/null || true"
}

wait_for_docker() {
    step "Waiting for systemd and Docker inside ${NAME}"
    for _ in $(seq 1 90); do
        if docker exec "$NAME" docker info >/dev/null 2>&1; then
            local driver
            driver=$(docker exec "$NAME" docker info --format '{{.Driver}}')
            step "Inner Docker is up (storage driver: ${driver})"
            return 0
        fi
        sleep 2
    done
    docker exec "$NAME" systemctl status docker --no-pager 2>&1 | head -20 || true
    die "The inner Docker daemon did not come up"
}

assert_home_is_a_volume() {
    # Cheap, and the failure it catches is silent and expensive.
    local fstype
    fstype=$(docker exec "$NAME" findmnt -no FSTYPE /home 2>/dev/null || echo '')
    case "$fstype" in
    '' | overlay)
        die "/home inside ${NAME} is '${fstype:-the container overlay}', not a real filesystem.
    Account Docker daemons will start, pull images, and then fail every
    container start with 'invalid argument'. Recreate with a volume at /home:
    bash scripts/tools/engine-container.sh destroy && bash scripts/tools/engine-container.sh up"
        ;;
    *) step "/home is backed by ${fstype} — account daemons can mount overlays" ;;
    esac
}

install_engine() {
    if [ "$INSTALL_MODE" = installer ]; then
        install_engine_with_installer
        return
    fi
    step "Installing the engine inside ${NAME} (bootstrap-from-source.sh)"
    local args=(--no-sysbox --no-hardening --dind-runtime privileged --mtu 1400 --no-docker-install)
    docker exec -w "$GUEST_DIR" "$NAME" \
        bash "${GUEST_DIR}/scripts/bootstrap-from-source.sh" "${args[@]}" "${BOOTSTRAP_ARGS[@]+"${BOOTSTRAP_ARGS[@]}"}"
}

install_engine_with_installer() {
    # Run the real installer.sh against the mounted tree, so CI exercises the
    # script customers run rather than the from-source shortcut.
    #
    # Three of its steps have to go, and only three. license_verify wants a
    # license server. download_panelalpha_engine and unzip_panelalpha_engine
    # would fetch a release zip and unpack it over /opt/panelalpha/shared-hosting
    # — which is the bind mount, i.e. the working tree on the host. That is not
    # a slow no-op, it would overwrite the very source being tested.
    #
    # The patching is dev-installer.sh's trick: each of those is called on a
    # line of its own, while its definition is followed by `()`, so replacing
    # "<name>\n" hits the call site and leaves the function defined. Bash
    # replaces the first match only, which is the call in every case here.
    step "Installing the engine inside ${NAME} (installer.sh, mounted tree)"
    local args=(--in-container)
    docker exec "$NAME" mkdir -p /opt/panelalpha/tmp
        # -i, or docker exec does not forward stdin and `bash -s` silently reads
    # nothing: the installer appears to run and does absolutely nothing.
    docker exec -i -w "$GUEST_DIR" \
        -e INSTALLER_ARGS="${args[*]} ${BOOTSTRAP_ARGS[*]+${BOOTSTRAP_ARGS[*]}}" \
        "$NAME" bash -s <<'RUNNER'
# `set -e` only, deliberately. installer.sh runs under plain `set -e` and
# relies on it: `set -u` would trip its option loop, which reads "$1" after
# the last shift, and `pipefail` would turn tolerated pipelines into fatal
# ones — `ip route get to 2001:db8:: | grep | cut` finding no IPv6 route is
# the normal case, and under pipefail it aborts the install after the stack
# is already up but before pae-artisan is registered.
set -e
SRC=/opt/panelalpha/shared-hosting/scripts/installer.sh
[ -f "$SRC" ] || { echo "scripts/installer.sh is not in the mounted tree" >&2; exit 1; }

SCRIPT=$(cat "$SRC")
for call in license_verify download_panelalpha_engine unzip_panelalpha_engine; do
    case "$SCRIPT" in
    *"
${call}
"*) ;;
    *) echo "scripts/installer.sh no longer calls ${call} on its own line — the patch is stale" >&2; exit 1 ;;
    esac
    SCRIPT=${SCRIPT/$'\n'"${call}"$'\n'/$'\n'"echo_warning \"skipped ${call} — installing from the mounted tree\""$'\n'}
done

# The banners around those calls describe work that no longer happens.
while IFS= read -r line; do
    [ -n "$line" ] || continue
    SCRIPT=${SCRIPT/$'\n'"${line}"$'\n'/$'\n'}
done <<'NARRATION'
echo_info "Requesting the download token"
echo_info "Download PanelAlpha engine package"
echo_info "Unzip PanelAlpha engine package"
NARRATION

# installer.sh parses "$@", so the arguments have to be positional parameters
# before the eval, not words appended after it — appending would glue them to
# the script's last line instead.
# shellcheck disable=SC2086
set -- ${INSTALLER_ARGS}
eval "$SCRIPT"
RUNNER
}

ensure_webserver_bound() {
    # nginx cannot rebind a wildcard listener to `listen <ip>:80` on a reload,
    # so it keeps the old config and tenant vhosts 502 with an empty error log.
    # Domain::create() handles this now; this stays as a backstop after install.
    local ip
    ip=$(docker exec "$NAME" sh -c "cd ${GUEST_DIR} && docker compose exec -T core php artisan settings:get default_ipv4 2>/dev/null" | tr -d '\r\n[:space:]')
    if [ -z "$ip" ]; then
        warn "Could not read default_ipv4 — skipping the webserver check"
        return 0
    fi
    if docker exec "$NAME" sh -c "ss -lnt 2>/dev/null | grep -q '${ip}:80'"; then
        step "Webserver is bound to ${ip}:80"
        return 0
    fi
    step "Restarting the webserver so it binds ${ip} (a reload cannot rebind)"
    docker exec -w "$GUEST_DIR" "$NAME" docker compose restart sites-http >/dev/null 2>&1 || true
    for _ in $(seq 1 15); do
        if docker exec "$NAME" sh -c "ss -lnt 2>/dev/null | grep -q '${ip}:80'"; then
            step "Webserver is bound to ${ip}:80"
            return 0
        fi
        sleep 2
    done
    warn "The webserver is not bound to ${ip}:80 — tenant URLs will answer 502"
}

clean_generated_state() {
    # Generated, gitignored and keyed to the engine's address; the config
    # seeding uses `cp -n`, so leftovers are reused by the next install. Runs as
    # root because the install created these as root. users/ is emptied rather
    # than removed, since users/.gitkeep is tracked.
    local body='
rm -rf webserver-config crt logs webserver-logs .env .env-core docker-compose.yml-webserver
[ -d users ] && find users -mindepth 1 -maxdepth 1 ! -name .gitkeep -exec rm -rf {} + 2>/dev/null
exit 0'
    step "Removing the generated config from the working tree"
    if exists; then
        docker exec "$NAME" sh -c "cd '${GUEST_DIR}' 2>/dev/null || exit 0 ${body}" 2>/dev/null && return 0
    fi
    if docker image inspect "$IMAGE" >/dev/null 2>&1; then
        docker run --rm -v "${ENGINE_DIR}:/tree" "$IMAGE" \
            sh -c "cd /tree 2>/dev/null || exit 0 ${body}" 2>/dev/null && return 0
    fi
    warn "Could not remove the generated config — remove it as root if the next install misbehaves"
}

report() {
    local ip
    ip=$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' "$NAME" 2>/dev/null)
    echo
    step "Engine is up in ${NAME}"
    echo "  API           https://127.0.0.1:${API_PORT}  (self-signed)"
    echo "  container IP  ${ip}"
    echo "  a shell       bash scripts/tools/engine-container.sh shell"
    echo "  artisan       docker exec -w ${GUEST_DIR} ${NAME} docker compose exec -T core php artisan list"
    echo "  a deploy test bash scripts/tools/engine-container.sh test"
    echo
    warn "Accounts here run privileged inside this container, not on sysbox."
    warn "Safe for the host, but accounts are not isolated from each other. CI and dev only."
}

case "$COMMAND" in
up)
    require_sysbox
    build_image
    start_container
    wait_for_docker
    assert_home_is_a_volume
    seed_storage
    install_engine
    ensure_webserver_bound
    report
    ;;
shell)
    exists || die "${NAME} does not exist. Run: bash scripts/tools/engine-container.sh up"
    docker exec -it -w "$GUEST_DIR" "$NAME" bash
    ;;
test)
    running || die "${NAME} is not running. Run: bash scripts/tools/engine-container.sh up"
    step "Running scripts/tools/ci-deploy-test.sh inside ${NAME}"
    docker exec -w "$GUEST_DIR" "$NAME" \
        bash "${GUEST_DIR}/scripts/tools/ci-deploy-test.sh" "${BOOTSTRAP_ARGS[@]+"${BOOTSTRAP_ARGS[@]}"}"
    ;;
logs)
    exists || die "${NAME} does not exist"
    docker exec "$NAME" journalctl -f --no-pager
    ;;
status)
    exists || die "${NAME} does not exist"
    docker exec -w "$GUEST_DIR" "$NAME" docker compose ps
    ;;
down)
    exists || { warn "${NAME} does not exist"; exit 0; }
    step "Removing ${NAME} (its volumes are kept)"
    docker rm -f "$NAME" >/dev/null
    ;;
destroy)
    clean_generated_state
    step "Removing ${NAME} and its volumes"
    docker rm -f "$NAME" >/dev/null 2>&1 || true
    docker volume rm "$HOME_VOLUME" "$STORAGE_VOLUME" "$CACHE_VOLUME" >/dev/null 2>&1 || true
    docker network rm "$NETWORK" >/dev/null 2>&1 || true
    ;;
*)
    die "Unknown command: ${COMMAND}. One of: up, shell, test, logs, status, down, destroy"
    ;;
esac
