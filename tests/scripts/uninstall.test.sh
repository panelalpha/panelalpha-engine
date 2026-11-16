#!/bin/bash
# shellcheck disable=SC2034 # the extracted functions read the settings this file declares
#
# uninstall.sh removes the accounts through the engine before the runtime that
# would stop core, and leaves behind none of the files, networks and settings
# the installer wrote. Everything runs in a temporary root with fake docker,
# systemctl, nft and user tools.
#
#   bash tests/scripts/uninstall.test.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/../../scripts" && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

STUB_DIR="$TMP/stub"
mkdir -p "$STUB_DIR"
export STUB_DIR

# uninstall.sh uninstalls when sourced, so cut out the functions. The settings
# they read are the script's defaults, pointed at this temporary root.
for fn in has_panel compose_down uninstall_firewall uninstall_sysbox_if_requested engine_core_running collect_engine_usernames \
    delete_engine_user_via_artisan delete_engine_user_manually uninstall_engine_users deconfigure_engine \
    uninstall_engine_stack collect_engine_images clean_engine_sidecars remove_shared_installer_files remove_empty_backup_stores remove_engine_state remove_docker_network \
    remove_boot_units engine_image_refs purge_engine_images_if_requested remove_engine_daemon_json warn_leftovers; do
    sed -n "/^${fn}() {/,/^}\$/p" "$SCRIPT_DIR/uninstall.sh" >>"$TMP/funcs.sh"
done

export PANELALPHA_DIR="$TMP/opt/panelalpha"
export ENGINE_DIR="$PANELALPHA_DIR/shared-hosting"
export APP_LITE_DIR="$PANELALPHA_DIR/app-lite"
export APP_DIR="$PANELALPHA_DIR/app"
export ENGINE_TMP_DIR="$PANELALPHA_DIR/tmp/engine"
export HOME_DIR="$TMP/home"
export CACHE_ROOT="$TMP/var/cache/panelalpha"
export COMPOSER_CACHE_ROOT="$TMP/var/cache/pa-composer"
export DOCKER_DAEMON_JSON="$TMP/etc/docker/daemon.json"
export SYSTEMD_UNIT_DIR="$TMP/etc/systemd/system"
export CRON_DIR="$TMP/etc/cron.d"
export LOCAL_BIN_DIR="$TMP/usr/local/bin"
export ENGINE_CONF_DIR="$TMP/etc/panelalpha"
DOCKER_NETWORK_NAME="pash-default-network"
TENANT_NETWORK_NAME="pash-tenants"
BUILD_NETWORK_NAME="panelalpha-build"
REGISTRY_MIRROR_URL="http://127.0.0.1:5001"
ENGINE_IMAGE_PATTERN='^(ghcr\.io/panelalpha/|panelalpha/|127\.0\.0\.1:5000/panelalpha/|moby/buildkit:|ghcr\.io/regclient/regctl:|certbot/certbot:)'
ENGINE_IMAGES=()
# shellcheck source=/dev/null
source "$TMP/funcs.sh"

PASS=0
FAIL=0
check() {
    local what="$1" expected="$2" actual="$3"
    if [[ "$actual" == "$expected" ]]; then
        printf '  ok    %s\n' "$what"
        PASS=$((PASS + 1))
    else
        printf '  FAIL  %s\n        expected: %s\n        actual:   %s\n' "$what" "$expected" "$actual"
        FAIL=$((FAIL + 1))
    fi
}

echo_info() { echo "info:$1"; }
echo_warning() { echo "warn:$1"; }
echo_error() {
    echo "error:$1"
    exit 101
}

BIN="$TMP/bin"
mkdir -p "$BIN"
cat >"$BIN/docker" <<'EOF'
#!/bin/bash
printf 'docker %s\n' "$*" >>"$STUB_DIR/calls"
args="$*"
case "$args" in
"compose "*" ps --status running --services")
    [ -f "$STUB_DIR/core-running" ] && echo core
    ;;
"compose "*" exec -T core "*)
    [ -f "$STUB_DIR/core-delete-fails" ] && exit 1
    ;;
"network inspect "*)
    grep -qxF -- "${args#network inspect }" "$STUB_DIR/networks" 2>/dev/null || exit 1
    ;;
"network rm "*)
    sed -i "\#^${args#network rm }\$#d" "$STUB_DIR/networks"
    ;;
"images --format "*)
    cat "$STUB_DIR/images" 2>/dev/null
    ;;
"ps -a "*)
    cat "$STUB_DIR/containers" 2>/dev/null
    ;;
"volume ls "*)
    cat "$STUB_DIR/volumes" 2>/dev/null
    ;;
esac
exit 0
EOF
cat >"$BIN/systemctl" <<'EOF'
#!/bin/bash
printf 'systemctl %s\n' "$*" >>"$STUB_DIR/calls"
exit 0
EOF
cat >"$BIN/nft" <<'EOF'
#!/bin/bash
printf 'nft %s\n' "$*" >>"$STUB_DIR/calls"
exit 0
EOF
cat >"$BIN/id" <<'EOF'
#!/bin/bash
[ "$1" = "-u" ] && exit 0
grep -qxF -- "$1" "$STUB_DIR/users" 2>/dev/null
EOF
cat >"$BIN/userdel" <<'EOF'
#!/bin/bash
printf 'userdel %s\n' "$*" >>"$STUB_DIR/calls"
sed -i "\#^$1\$#d" "$STUB_DIR/users"
EOF
chmod +x "$BIN"/*
PATH="$BIN:$PATH"

calls() { sed "s#$TMP#ROOT#g" "$STUB_DIR/calls"; }
logged() { grep -qxF -- "$1" <<<"$(calls)" && echo yes || echo no; }
there() { [ -e "$1" ] || [ -L "$1" ] && echo yes || echo no; }
reset() {
    : >"$STUB_DIR/calls"
    : >"$STUB_DIR/users"
    : >"$STUB_DIR/networks"
    : >"$STUB_DIR/images"
    : >"$STUB_DIR/containers"
    : >"$STUB_DIR/volumes"
    rm -f "$STUB_DIR/core-running" "$STUB_DIR/core-delete-fails"
}

# An engine tree with one account, the two scripts an uninstall runs, and core up.
stack() {
    mkdir -p "$ENGINE_DIR/scripts" "$ENGINE_DIR/users/alice"
    printf 'services: {}\n' >"$ENGINE_DIR/docker-compose.yml"
    printf 'services: {}\n' >"$ENGINE_DIR/users/alice/docker-compose.yml"
    printf '#!/bin/bash\nprintf "firewall %%s\\n" "$*" >>"$STUB_DIR/calls"\n' >"$ENGINE_DIR/scripts/firewall.sh"
    printf '#!/bin/bash\nprintf "sysbox %%s\\n" "$*" >>"$STUB_DIR/calls"\n' >"$ENGINE_DIR/scripts/install-sysbox.sh"
    mkdir -p "$HOME_DIR/alice"
    echo alice >>"$STUB_DIR/users"
    touch "$STUB_DIR/core-running"
}

reset
stack
REMOVE_SYSBOX=1
KEEP_PROJECTS=0
REMOVE_FIREWALL=0
uninstall_engine_stack >/dev/null
check "the accounts go through the engine, before the runtime that stops every container" \
    "firewall --unhook|docker compose -f ROOT/opt/panelalpha/shared-hosting/docker-compose.yml ps --status running --services|docker compose -f ROOT/opt/panelalpha/shared-hosting/docker-compose.yml exec -T core php artisan project:delete --all --force|docker compose -f ROOT/opt/panelalpha/shared-hosting/docker-compose.yml down -v --remove-orphans|sysbox --uninstall" \
    "$(paste -sd'|' <<<"$(calls)")"
check "the engine directory is gone" "no" "$(there "$ENGINE_DIR")"

reset
stack
rm -f "$STUB_DIR/core-running"
uninstall_engine_stack >/dev/null 2>&1
check "without core the account is cleaned by hand instead" "yes" \
    "$(logged "docker compose -f ROOT/opt/panelalpha/shared-hosting/users/alice/docker-compose.yml down -v --remove-orphans")"
check "the home and the OS account go with it" "no/yes" "$(there "$HOME_DIR/alice")/$(logged 'userdel alice')"
check "nothing asks the engine to delete anything" "0" "$(calls | grep -c 'project:delete')"

reset
mkdir -p "$PANELALPHA_DIR/log/engine-installs" "$PANELALPHA_DIR/log/letsencrypt" "$PANELALPHA_DIR/log/change-webserver" \
    "$PANELALPHA_DIR/log/prewarm" "$PANELALPHA_DIR/tmp/engine" "$PANELALPHA_DIR/backups/local" "$PANELALPHA_DIR/app-lite"
touch "$PANELALPHA_DIR/engine-installer.sh" "$PANELALPHA_DIR/panelalpha-installer.sh" "$PANELALPHA_DIR/installer-origin" \
    "$PANELALPHA_DIR/lock" "$PANELALPHA_DIR/.panelalpha-install-id" "$PANELALPHA_DIR/log/letsencrypt/renew.log" \
    "$PANELALPHA_DIR/log/prewarm/latest"
printf 'services: {}\n' >"$PANELALPHA_DIR/app-lite/docker-compose.yml"
mkdir -p "$HOME_DIR/.panelalpha-unzip-stage" "$HOME_DIR/.panelalpha-write-stage" "$HOME_DIR/.panelalpha-restore-stage" \
    "$HOME_DIR/alice" "$CACHE_ROOT/projects/alice" "$COMPOSER_CACHE_ROOT/alice" "$CRON_DIR" "$LOCAL_BIN_DIR" "$ENGINE_CONF_DIR"
touch "$CRON_DIR/panelalpha-letsencrypt" "$LOCAL_BIN_DIR/pae" "$LOCAL_BIN_DIR/pae-artisan" "$ENGINE_CONF_DIR/paengine.yaml"
out=$(clean_engine_sidecars 2>&1)
check "the engine's installer goes" "no" "$(there "$PANELALPHA_DIR/engine-installer.sh")"
check "the files the panel's installer reads stay while the panel is installed" "yes yes yes yes" \
    "$(there "$PANELALPHA_DIR/panelalpha-installer.sh") $(there "$PANELALPHA_DIR/installer-origin") $(there "$PANELALPHA_DIR/lock") $(there "$PANELALPHA_DIR/.panelalpha-install-id")"
check "its log directories go" "no no no no" \
    "$(there "$PANELALPHA_DIR/log/letsencrypt") $(there "$PANELALPHA_DIR/log/change-webserver") $(there "$PANELALPHA_DIR/log/prewarm") $(there "$PANELALPHA_DIR/log/engine-installs")"
check "the empty default store goes with the backups directory, unreported" "no no" \
    "$(there "$PANELALPHA_DIR/backups") $(grep -qF 'backups are left' <<<"$out" && echo yes || echo no)"
check "the staging trees and build caches go" "no no no no no" \
    "$(there "$HOME_DIR/.panelalpha-unzip-stage") $(there "$HOME_DIR/.panelalpha-write-stage") $(there "$HOME_DIR/.panelalpha-restore-stage") $(there "$CACHE_ROOT") $(there "$COMPOSER_CACHE_ROOT")"
check "the cron job, the wrapper binaries and their config go" "no no no no" \
    "$(there "$CRON_DIR/panelalpha-letsencrypt") $(there "$LOCAL_BIN_DIR/pae") $(there "$LOCAL_BIN_DIR/pae-artisan") $(there "$ENGINE_CONF_DIR/paengine.yaml")"
check "project homes and the panel stay" "yes yes" "$(there "$HOME_DIR/alice") $(there "$PANELALPHA_DIR/app-lite/docker-compose.yml")"

rm -rf "$PANELALPHA_DIR/app-lite"
clean_engine_sidecars >/dev/null
check "without a panel the installer's shared files go too" "no no no no" \
    "$(there "$PANELALPHA_DIR/panelalpha-installer.sh") $(there "$PANELALPHA_DIR/installer-origin") $(there "$PANELALPHA_DIR/lock") $(there "$PANELALPHA_DIR/.panelalpha-install-id")"

mkdir -p "$PANELALPHA_DIR/backups/local/alice" "$PANELALPHA_DIR/backups/offsite"
printf 'x\n' >"$PANELALPHA_DIR/backups/engine-abc-20260101.tgz"
printf 'x\n' >"$PANELALPHA_DIR/backups/local/alice/b1.tar.gz"
out=$(clean_engine_sidecars 2>&1)
check "engine and project backups stay and are reported" "yes yes yes" \
    "$(grep -qF 'backups are left' <<<"$out" && echo yes || echo no) $(there "$PANELALPHA_DIR/backups/engine-abc-20260101.tgz") $(there "$PANELALPHA_DIR/backups/local/alice/b1.tar.gz")"
check "an empty store beside them goes" "no" "$(there "$PANELALPHA_DIR/backups/offsite")"

reset
printf '%s\n' pash-tenants pash-default-network panelalpha-build >"$STUB_DIR/networks"
remove_docker_network >/dev/null
check "every network the engine made goes" "no no no" \
    "$(grep -qxF pash-tenants "$STUB_DIR/networks" && echo yes || echo no) $(grep -qxF pash-default-network "$STUB_DIR/networks" && echo yes || echo no) $(grep -qxF panelalpha-build "$STUB_DIR/networks" && echo yes || echo no)"
check "and the tenant guard's nft table with them" "yes" "$(logged 'nft delete table bridge pa_tenants')"

reset
cat >"$STUB_DIR/images" <<'EOF'
ghcr.io/panelalpha/engine-core:v2.3.0
panelalpha/php:8.3-apache-bookworm-pa20261007
panelalpha/ruby:3.3-slim-bookworm-pa0c53db49
127.0.0.1:5000/panelalpha/php:8.3-apache-bookworm-pa20261007
moby/buildkit:v0.34.1
ghcr.io/regclient/regctl:v0.11.7
certbot/certbot:latest
nginx:alpine
python:3.13-slim
EOF
ENGINE_IMAGES=(registry:3.1.1)
PURGE_IMAGES=0
out=$(purge_engine_images_if_requested 2>&1)
check "without --purge-images the images are listed" "yes" "$(grep -qF 'panelalpha/ruby:3.3-slim-bookworm-pa0c53db49' <<<"$out" && echo yes || echo no)"
check "and none is removed" "0" "$(grep -c '^docker rmi' "$STUB_DIR/calls")"

reset
cat >"$STUB_DIR/images" <<'EOF'
ghcr.io/panelalpha/engine-core:v2.3.0
panelalpha/php:8.3-apache-bookworm-pa20261007
panelalpha/ruby:3.3-slim-bookworm-pa0c53db49
127.0.0.1:5000/panelalpha/php:8.3-apache-bookworm-pa20261007
moby/buildkit:v0.34.1
ghcr.io/regclient/regctl:v0.11.7
certbot/certbot:latest
nginx:alpine
python:3.13-slim
EOF
ENGINE_IMAGES=(registry:3.1.1)
PURGE_IMAGES=1
purge_engine_images_if_requested >/dev/null
check "with --purge-images every image the engine built, cached or pulled goes" \
    "$(printf '%s\n' 127.0.0.1:5000/panelalpha/php:8.3-apache-bookworm-pa20261007 certbot/certbot:latest ghcr.io/panelalpha/engine-core:v2.3.0 ghcr.io/regclient/regctl:v0.11.7 moby/buildkit:v0.34.1 panelalpha/php:8.3-apache-bookworm-pa20261007 panelalpha/ruby:3.3-slim-bookworm-pa0c53db49 registry:3.1.1 | LC_ALL=C sort | paste -sd' ')" \
    "$(sed -n 's/^docker rmi -f //p' "$STUB_DIR/calls" | tr ' ' '\n' | LC_ALL=C sort | paste -sd' ')"
check "images from elsewhere on the host stay" "no no" \
    "$(grep -q nginx:alpine "$STUB_DIR/calls" && echo yes || echo no) $(grep -q python:3.13-slim "$STUB_DIR/calls" && echo yes || echo no)"

if command -v jq >/dev/null 2>&1; then
    reset
    mkdir -p "$(dirname "$DOCKER_DAEMON_JSON")"
    cat >"$DOCKER_DAEMON_JSON" <<'EOF'
{
  "bip": "172.20.0.1/16",
  "features": {"time-namespaces": false},
  "registry-mirrors": ["http://127.0.0.1:5001", "https://mirror.example"]
}
EOF
    remove_engine_daemon_json >/dev/null
    check "the installer's mirror entry goes" "https://mirror.example" \
        "$(jq -r '.["registry-mirrors"] | join(",")' "$DOCKER_DAEMON_JSON")"
    check "everything else in daemon.json is kept" "172.20.0.1/16 false" \
        "$(jq -r '.bip, .features["time-namespaces"]' "$DOCKER_DAEMON_JSON" | paste -sd' ')"
    check "the daemon is reloaded" "yes" "$(logged 'systemctl reload docker')"

    cat >"$DOCKER_DAEMON_JSON" <<'EOF'
{"registry-mirrors": ["http://127.0.0.1:5001"]}
EOF
    remove_engine_daemon_json >/dev/null
    check "a list holding only that entry loses it rather than staying empty" "{}" \
        "$(jq -cS . "$DOCKER_DAEMON_JSON")"

    printf '{"registry-mirrors": ["https://mirror.example"]}\n' >"$DOCKER_DAEMON_JSON"
    cp "$DOCKER_DAEMON_JSON" "$TMP/daemon.before"
    remove_engine_daemon_json >/dev/null
    check "a daemon.json without it is not touched" "yes" "$(cmp -s "$DOCKER_DAEMON_JSON" "$TMP/daemon.before" && echo yes || echo no)"
fi

reset
printf 'dind-alice\n' >"$STUB_DIR/containers"
printf 'abc123def456\n' >"$STUB_DIR/volumes"
mkdir -p "$HOME_DIR/alice"
KEEP_PROJECTS=0
out=$(warn_leftovers 2>&1)
check "a container or volume left behind is named" "yes yes" \
    "$(grep -qF dind-alice <<<"$out" && echo yes || echo no) $(grep -qF abc123def456 <<<"$out" && echo yes || echo no)"
check "so is a home directory" "yes" "$(grep -qF "$HOME_DIR/alice" <<<"$out" && echo yes || echo no)"
KEEP_PROJECTS=1
check "kept projects are not reported as leftovers" "no" "$(warn_leftovers 2>&1 | grep -qF 'Home directories' && echo yes || echo no)"

echo
echo "$PASS passed, $FAIL failed"
[[ $FAIL -eq 0 ]]
