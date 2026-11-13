#!/bin/bash
#
# installer.sh's update counts every image the release builds only when built from the clone's sources.
#
#   bash tests/scripts/installer-engine-images.test.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/../../scripts" && pwd)"
REPO_DIR="$(dirname "$SCRIPT_DIR")"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

for fn in engine_image_inputs engine_image_sources engine_image_built_from engine_built_images \
    engine_published_sources engine_retag_built_from ensure_engine_image prepare_engine_images; do
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

# The services the stub compose file builds: service <TAB> Dockerfile. sites-db and core-db share one image.
SERVICES=$'core\tDockerfile-core\nroot-broker\tDockerfile-root-broker\nmail\tDockerfile-exim\nsites-db\tDockerfile-database\ncore-db\tDockerfile-database'
image_of() { case "$1" in sites-db | core-db) echo image-database ;; *) echo "image-$1" ;; esac; }

# Stub state in $TMP: local/<image>, registry/<image>, unbuildable and unreadable (the registry cannot be asked).
docker() {
    local args=("$@") service image
    case "$1" in
    compose)
        echo "${args[*]}" >>"$TMP/calls"
        [ -f "$SRC/docker-compose.yml-webserver" ] || return 1
        service="${!#}"
        image=$(image_of "$service")
        case " ${args[*]} " in
        *" config --format json "*)
            while IFS=$'\t' read -r s f; do
                printf '"%s":{"image":"%s","build":{"context":"%s","dockerfile":"%s"}}\n' "$s" "$(image_of "$s")" "$SRC/dockerfiles" "$f"
            done <<<"$SERVICES" | paste -sd, | sed 's/^/{"name":"shared-hosting","services":{"sites-dns-plain":{"image":"x"},/; s/$/}}/'
            ;;
        *" pull "*) [ -f "$TMP/registry/$image" ] && cp "$TMP/registry/$image" "$TMP/local/$image"; true ;;
        *" build "*)
            grep -qx "$service" "$TMP/unbuildable" && return 1
            printf '%s' "${args[*]}" | sed -n 's/.* --build-arg PANELALPHA_SOURCES=\([^ ]*\) .*/\1/p' >"$TMP/local/$image"
            ;;
        esac
        ;;
    buildx)
        [ "$2 $3 $4 $5" = 'imagetools inspect --format {{ index .Image.Config.Labels "panelalpha.sources.sha256" }}' ] || return 2
        [ -f "$TMP/unreadable" ] && return 1
        [ -f "$TMP/registry/${!#}" ] && cat "$TMP/registry/${!#}"
        ;;
    image)
        [ "$2 $3 $4" = 'inspect --format {{ index .Config.Labels "panelalpha.sources.sha256" }}' ] || return 2
        [ -f "$TMP/local/${!#}" ] && cat "$TMP/local/${!#}"
        ;;
    images)
        local label="${3#label=panelalpha.sources.sha256=}" f
        for f in "$TMP"/old/"${!#}":*; do
            [ -f "$f" ] && [ "$(cat "$f")" = "$label" ] && basename "$f"
        done
        true
        ;;
    tag)
        echo "tag $2 $3" >>"$TMP/calls"
        cp "$TMP/old/$2" "$TMP/local/$3"
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
MAIL=$(engine_image_sources "$SRC/dockerfiles" Dockerfile-exim)
DB=$(engine_image_sources "$SRC/dockerfiles" Dockerfile-database)

host() {
    rm -rf "$PANELALPHA_DIR" "$TMP/local" "$TMP/registry" "$TMP/old" "$TMP/unreadable"
    mkdir -p "$PANELALPHA_DIR/shared-hosting" "$TMP/local" "$TMP/registry" "$TMP/old"
    echo 'apache' >"$PANELALPHA_DIR/shared-hosting/docker-compose.yml-webserver"
    echo 'COMPOSE_PROFILES=full' >"$PANELALPHA_DIR/shared-hosting/.env"
    : >"$TMP/unbuildable"
    : >"$TMP/calls"
}
local_image() { printf '%s' "$2" >"$TMP/local/$(image_of "$1")"; }
published() { printf '%s' "$2" >"$TMP/registry/$(image_of "$1")"; }
all_local() { local_image core "$CORE"; local_image root-broker "$BROKER"; local_image mail "$MAIL"; local_image sites-db "$DB"; }
all_published() { published core "$CORE"; published root-broker "$BROKER"; published mail "$MAIL"; published sites-db "$DB"; }
old_tag() { printf '%s' "$3" >"$TMP/old/$(image_of "$1"):$2"; }
label() { cat "$TMP/local/$(image_of "$1")" 2>/dev/null; }
tags() { grep -o '^tag .*' "$TMP/calls" | tr '\n' ' ' | sed 's/ $//'; }
builds() { grep -o ' build .*' "$TMP/calls" | sed 's/ --build-arg [^ ]*//; s/^ build //' | tr '\n' ' ' | sed 's/ $//'; }
pulls() { grep -o ' pull .*' "$TMP/calls" | sed 's/^ pull --quiet --ignore-pull-failures //' | tr '\n' ' ' | sed 's/ $//'; }

check "the images hash differently" "yes" \
    "$([ -n "$CORE" ] && [ "$CORE" != "$BROKER" ] && [ "$MAIL" != "$CORE" ] && [ "$DB" != "$MAIL" ] && echo yes)"

host
touch "$SRC/docker-compose.yml-webserver"
check "every service with a build section, once per image, sorted by image" \
    "core image-core $SRC/dockerfiles Dockerfile-core|sites-db image-database $SRC/dockerfiles Dockerfile-database|mail image-mail $SRC/dockerfiles Dockerfile-exim|root-broker image-root-broker $SRC/dockerfiles Dockerfile-root-broker" \
    "$(engine_built_images docker compose | tr '\t' ' ' | paste -sd'|')"
rm "$SRC/docker-compose.yml-webserver"

host
all_local
touch "$TMP/unreadable"
out=$(prepare_engine_images)
check "a host holding every image, with the registry out of reach, neither pulls nor builds" "0||" "$?|$(pulls)|$(builds)"

host
all_local
all_published
out=$(prepare_engine_images)
check "published images from these sources are pulled even when the host has them, to take a rebuild" \
    "0|core sites-db mail root-broker|" "$?|$(pulls)|$(builds)"

host
all_local
published core 'a release'
published mail 'a release'
out=$(prepare_engine_images)
check "published images from other sources are not pulled over the host's, as on a develop host" "0||" "$?|$(pulls)|$(builds)"

host
out=$(prepare_engine_images)
check "a host with no image and nothing published: done" "0" "$?"
check "each image is looked for in the registry" "core sites-db mail root-broker" "$(pulls)"
check "and built" "core sites-db mail root-broker" "$(builds)"
check "each labelled with the hash of its sources" "$CORE $BROKER $MAIL $DB" "$(label core) $(label root-broker) $(label mail) $(label core-db)"
check "from the clone's compose file, with the host's .env" \
    "compose --project-directory $SRC -f $SRC/docker-compose.yml --env-file $PANELALPHA_DIR/shared-hosting/.env build --build-arg PANELALPHA_SOURCES=$BROKER root-broker" \
    "$(grep ' build ' "$TMP/calls" | tail -n1)"
check "the webserver include leaves the clone again" "no" "$([ -e "$SRC/docker-compose.yml-webserver" ] && echo yes || echo no)"

host
all_published
out=$(prepare_engine_images)
check "published images from these sources are pulled" "0|core sites-db mail root-broker" "$?|$(pulls)"
check "and nothing is built" "" "$(builds)"

host
published mail 'another release'
local_image mail 'an older release'
local_image core "$CORE"; local_image root-broker "$BROKER"; local_image sites-db "$DB"
out=$(prepare_engine_images)
check "an image published from other sources is not pulled, the host's older one is built over" "|mail|$MAIL" "$(pulls)|$(builds)|$(label mail)"

host
all_local
local_image mail ''
touch "$TMP/unreadable"
out=$(prepare_engine_images)
check "with the registry out of reach, an unlabelled image is looked for, then built" "mail|mail" "$(pulls)|$(builds)"

host
old_tag core v2.3.0-20261105 "$CORE"
old_tag root-broker v2.3.0-20261105 'an older release'
local_image mail "$MAIL"; local_image sites-db "$DB"
touch "$TMP/unreadable"
out=$(prepare_engine_images)
check "the previous release's image from the same sources is tagged, not built; one from other sources is not" \
    "tag image-core:v2.3.0-20261105 image-core|root-broker|$CORE" "$(tags)|$(builds)|$(label core)"

host
echo mail >"$TMP/unbuildable"
out=$(prepare_engine_images)
check "a failed build stops the update" "101" "$?"
check "and says which" "error:Could not build the mail image; the engine was not changed" "$out"

host
all_published
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

for f in $(sed -n 's/^ *dockerfile: //p' "$REPO_DIR"/docker-compose.yml "$REPO_DIR"/docker-compose.yml-* | sort -u) ../templates/config/pure-ftpd/build/Dockerfile; do
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
