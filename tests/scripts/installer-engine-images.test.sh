#!/bin/bash
#
# installer.sh's update counts only core and root-broker images built from the clone's sources.
#
#   bash tests/scripts/installer-engine-images.test.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/../../scripts" && pwd)"
REPO_DIR="$(dirname "$SCRIPT_DIR")"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

for fn in engine_image_dockerfile engine_image_inputs engine_image_sources engine_image_built_from engine_service_image prepare_engine_images; do
    sed -n "/^${fn}() {/,/^}\$/p" "$SCRIPT_DIR/installer.sh" >>"$TMP/funcs.sh"
done
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

echo_error() {
    echo "error:$1"
    exit 101
}

INSTALL_DIR="$TMP/install"
PANELALPHA_DIR="$TMP/opt"
SRC="$INSTALL_DIR/src"

# Stub state in $TMP: local/<image>, registry/<service> and unbuildable.
selected() { [ "$1" = core ] && echo root-broker; echo "$1"; }
docker() {
    local args=("$@") service
    case "$1" in
    compose)
        echo "${args[*]}" >>"$TMP/calls"
        [ -f "$SRC/docker-compose.yml-webserver" ] || return 1
        service="${!#}"
        case " ${args[*]} " in
        *" pull "*) [ -f "$TMP/registry/$service" ] && cp "$TMP/registry/$service" "$TMP/local/image-$service" ;;
        *" config --images "*) for s in $(selected "$service"); do echo "image-$s"; done ;;
        *" config --format json "*)
            for s in $(selected "$service"); do printf '"%s":{"image":"image-%s"}\n' "$s" "$s"; done |
                paste -sd, | sed 's/^/{"name":"shared-hosting","services":{/; s/$/}}/'
            ;;
        *" build "*)
            grep -qx "$service" "$TMP/unbuildable" && return 1
            printf '%s' "${args[*]}" | sed -n 's/.* --build-arg PANELALPHA_SOURCES=\([^ ]*\) .*/\1/p' >"$TMP/local/image-$service"
            ;;
        esac
        ;;
    image)
        [ "$2 $3 $4" = 'inspect --format {{ index .Config.Labels "panelalpha.sources.sha256" }}' ] || return 2
        [ -f "$TMP/local/${!#}" ] && cat "$TMP/local/${!#}"
        ;;
    esac
}

release() {
    rm -rf "$SRC"
    mkdir -p "$SRC"
    cp -R "$REPO_DIR/dockerfiles" "$SRC/"
    echo 'include: [docker-compose.yml-webserver]' >"$SRC/docker-compose.yml"
    echo 'nginx-proxy' >"$SRC/docker-compose.yml-nginx-proxy"
}
release
CORE=$(engine_image_sources "$SRC/dockerfiles" Dockerfile-core)
BROKER=$(engine_image_sources "$SRC/dockerfiles" Dockerfile-root-broker)

host() {
    rm -rf "$PANELALPHA_DIR" "$TMP/local" "$TMP/registry"
    mkdir -p "$PANELALPHA_DIR/shared-hosting" "$TMP/local" "$TMP/registry"
    echo 'apache' >"$PANELALPHA_DIR/shared-hosting/docker-compose.yml-webserver"
    echo 'COMPOSE_PROFILES=full' >"$PANELALPHA_DIR/shared-hosting/.env"
    : >"$TMP/unbuildable"
    : >"$TMP/calls"
}
local_image() { printf '%s' "$2" >"$TMP/local/image-$1"; }
published() { printf '%s' "$2" >"$TMP/registry/$1"; }
label() { cat "$TMP/local/image-$1" 2>/dev/null; }
builds() { grep -o ' build .*' "$TMP/calls" | sed 's/ --build-arg [^ ]*//; s/^ build //' | tr '\n' ' ' | sed 's/ $//'; }
pulls() { grep -o ' pull .*' "$TMP/calls" | sed 's/^ pull --ignore-pull-failures //' | tr '\n' ' ' | sed 's/ $//'; }

check "the two images hash differently" "yes" "$([ -n "$CORE" ] && [ -n "$BROKER" ] && [ "$CORE" != "$BROKER" ] && echo yes)"

host
local_image core "$CORE"
local_image root-broker "$BROKER"
touch "$SRC/docker-compose.yml-webserver"
check "core's image is read alone, not with the one it depends on" "image-core|image-root-broker" \
    "$(engine_service_image core docker compose)|$(engine_service_image root-broker docker compose)"
rm "$SRC/docker-compose.yml-webserver"
out=$(prepare_engine_images)
check "so a second identical update neither pulls nor builds" "0||" "$?|$(pulls)|$(builds)"

host
out=$(prepare_engine_images)
check "a host with neither image and nothing published: done" "0" "$?"
check "both are looked for in the registry" "core root-broker" "$(pulls)"
check "and built" "core root-broker" "$(builds)"
check "each labelled with the hash of its sources" "$CORE $BROKER" "$(label core) $(label root-broker)"
check "from the clone's compose file, with the host's .env" \
    "compose --project-directory $SRC -f $SRC/docker-compose.yml --env-file $PANELALPHA_DIR/shared-hosting/.env build --build-arg PANELALPHA_SOURCES=$BROKER root-broker" \
    "$(grep ' build ' "$TMP/calls" | tail -n1)"
check "the webserver include leaves the clone again" "no" "$([ -e "$SRC/docker-compose.yml-webserver" ] && echo yes || echo no)"

host
published core "$CORE"
published root-broker "$BROKER"
out=$(prepare_engine_images)
check "published images built from these sources are pulled" "0|core root-broker" "$?|$(pulls)"
check "and nothing is built" "" "$(builds)"

host
local_image core 'an older release'
local_image root-broker 'an older release'
out=$(prepare_engine_images)
check "a host holding older images under the same tags rebuilds both" "core root-broker" "$(builds)"
check "which now carry this release's hashes" "$CORE $BROKER" "$(label core) $(label root-broker)"

host
local_image core ''
local_image root-broker ''
out=$(prepare_engine_images)
check "an image with no label, as an unlabelled build leaves it, is rebuilt" "core root-broker" "$(builds)"

host
local_image root-broker 'an older release'
published root-broker 'another release'
local_image core "$CORE"
out=$(prepare_engine_images)
check "an image already built from these sources is neither pulled nor built" "root-broker" "$(pulls)"
check "a published one built from other sources is pulled, then built over" "root-broker|$BROKER" "$(builds)|$(label root-broker)"

host
echo root-broker >"$TMP/unbuildable"
out=$(prepare_engine_images)
check "a failed build stops the update" "101" "$?"
check "and says which" "error:Could not build the root-broker image; the engine was not changed" "$out"

host
published core "$CORE"
published root-broker "$BROKER"
rm "$PANELALPHA_DIR/shared-hosting/docker-compose.yml-webserver"
out=$(prepare_engine_images)
check "a host without its webserver file uses the default one" "0" "$?"

host
rm "$SRC/dockerfiles/root-broker/pa-root-owned"
out=$(prepare_engine_images 2>/dev/null)
check "a release missing a file the Dockerfile copies stops the update" "101|error:Could not read the root-broker image's sources from the new release" "$?|$out"
release

moved() { # <file under dockerfiles> -- whether each image's hash moves when the file changes, "<core> <broker>"
    local core broker
    echo '# changed' >>"$SRC/dockerfiles/$1"
    core=$(engine_image_sources "$SRC/dockerfiles" Dockerfile-core)
    broker=$(engine_image_sources "$SRC/dockerfiles" Dockerfile-root-broker)
    release
    printf '%s %s' "$([ "$core" = "$CORE" ] && echo same || echo moved)" "$([ "$broker" = "$BROKER" ] && echo same || echo moved)"
}
check "a change to the broker's code moves the broker's hash alone" "same moved" "$(moved root-broker/rootbroker/server.py)"
check "a change to the client both images carry moves both" "moved moved" "$(moved root-broker/rootbroker/client.py)"
check "so does a change to a broker program, for the broker" "same moved" "$(moved root-broker/pa-root-broker)"
check "and to pa-broker, which only the broker carries" "same moved" "$(moved root-broker/pa-broker)"
check "and a change to a Dockerfile, for its own image" "moved same" "$(moved Dockerfile-core)"
check "a file neither image copies moves neither" "same same" "$(moved root-broker/tests/support.py)"
mkdir -p "$SRC/dockerfiles/root-broker/rootbroker/actions/new"
echo 'x = 1' >"$SRC/dockerfiles/root-broker/rootbroker/actions/new/module.py"
check "a new file in a copied folder moves the hash" "yes" \
    "$([ "$(engine_image_sources "$SRC/dockerfiles" Dockerfile-root-broker)" != "$BROKER" ] && echo yes)"
release
mkdir -p "$SRC/dockerfiles/root-broker/rootbroker/__pycache__" "$SRC/dockerfiles/root-broker/rootbroker/actions/__pycache__"
echo bytecode >"$SRC/dockerfiles/root-broker/rootbroker/__pycache__/server.cpython-312.pyc"
echo bytecode >"$SRC/dockerfiles/root-broker/rootbroker/actions/__pycache__/files.cpython-314.pyc"
echo bytecode >"$SRC/dockerfiles/root-broker/rootbroker/stray.pyc"
check "Python bytecode in a copied folder, as a test run leaves it, moves neither hash" "$CORE $BROKER" \
    "$(engine_image_sources "$SRC/dockerfiles" Dockerfile-core) $(engine_image_sources "$SRC/dockerfiles" Dockerfile-root-broker)"
release
check "and the build context leaves it out of the images too" "**/__pycache__ **/*.pyc" \
    "$(tr '\n' ' ' <"$REPO_DIR/dockerfiles/.dockerignore" | sed 's/ $//')"

for f in Dockerfile-core Dockerfile-root-broker; do
    check "$f takes the hash as a build argument into the label read" 'LABEL panelalpha.sources.sha256=$PANELALPHA_SOURCES' \
        "$(grep -A1 '^ARG PANELALPHA_SOURCES=$' "$REPO_DIR/dockerfiles/$f" | sed -n 2p)"
done
check "core's label is in the stage docker-compose.yml builds" "php_nginx_prod|1" \
    "$(awk '/^FROM /{stage=$NF} /^ARG PANELALPHA_SOURCES=$/{print stage}' "$REPO_DIR/dockerfiles/Dockerfile-core")|$(grep -c '^ *target: php_nginx_prod$' "$REPO_DIR/docker-compose.yml")"
check "the Dockerfiles named are the ones docker-compose.yml builds" "Dockerfile-core Dockerfile-root-broker" \
    "$(grep -E '^ +dockerfile: Dockerfile-(core|root-broker)$' "$REPO_DIR/docker-compose.yml" | sed 's/.*: //' | tr '\n' ' ' | sed 's/ $//')"
check "and both build from dockerfiles/" "2" \
    "$(grep -B1 -E '^ +dockerfile: Dockerfile-(core|root-broker)$' "$REPO_DIR/docker-compose.yml" | grep -c 'context: ./dockerfiles$')"
check "the update's up recreates a container whose image changed" "0" \
    "$(grep -c 'up -d.*--no-recreate' "$SCRIPT_DIR/installer.sh")"

check "only an update prepares images first" \
    "$(printf '%s\n' 'if [ "$ENGINE_OP" = update ]; then' '    update_progress 30 "Preparing PanelAlpha engine images"' '    echo_info "Preparing PanelAlpha engine images"' '    prepare_engine_images' '    pause_core_background' '    stop_core_tenant_network' '    pause_broker_rotation' 'fi')" \
    "$(grep -B4 -A4 '^    prepare_engine_images$' "$SCRIPT_DIR/installer.sh" | sed -n '2,9p')"
check "before the copy" "prepare_engine_images unzip_panelalpha_engine" \
    "$(grep -oE '^ *(prepare_engine_images|unzip_panelalpha_engine)$' "$SCRIPT_DIR/installer.sh" | tr -d ' ' | tr '\n' ' ' | sed 's/ $//')"

echo
echo "$PASS passed, $FAIL failed"
[[ $FAIL -eq 0 ]]
