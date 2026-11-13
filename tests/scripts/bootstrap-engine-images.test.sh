#!/bin/bash
#
# bootstrap-from-source.sh counts every image the tree builds only when built from the uploaded tree.
#
#   bash tests/scripts/bootstrap-engine-images.test.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/../../scripts" && pwd)"
REPO_DIR="$(dirname "$SCRIPT_DIR")"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

for fn in import_engine_image_functions ensure_engine_images; do
    sed -n "/^${fn}() {/,/^}\$/p" "$SCRIPT_DIR/bootstrap-from-source.sh" >>"$TMP/funcs.sh"
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

step() { echo "step:$1"; }
warn() { echo "warn:$1"; }

# The uploaded tree, entered as the bootstrap enters it.
TREE="$TMP/tree"
mkdir -p "$TREE/scripts"
cp -R "$REPO_DIR/dockerfiles" "$TREE/"
cp "$SCRIPT_DIR/installer.sh" "$TREE/scripts/"
cd "$TREE" || exit 1

SERVICES=$'core\tDockerfile-core\nroot-broker\tDockerfile-root-broker\nmail\tDockerfile-exim'

# Stub state in $TMP: local/image-<service>, registry/image-<service>, unbuildable and unreadable.
docker() {
    local args=("$@") service
    case "$1" in
    compose)
        echo "${args[*]}" >>"$TMP/calls"
        service="${!#}"
        case " ${args[*]} " in
        *" config --format json "*)
            while IFS=$'\t' read -r s f; do
                printf '"%s":{"image":"image-%s","build":{"context":"%s","dockerfile":"%s"}}\n' "$s" "$s" "$TREE/dockerfiles" "$f"
            done <<<"$SERVICES" | paste -sd, | sed 's/^/{"name":"shared-hosting","services":{/; s/$/}}/'
            ;;
        *" pull "*) [ -f "$TMP/registry/image-$service" ] && cp "$TMP/registry/image-$service" "$TMP/local/image-$service"; true ;;
        *" build "*)
            grep -qx "$service" "$TMP/unbuildable" && return 1
            printf '%s' "${args[*]}" | sed -n 's/.* --build-arg PANELALPHA_SOURCES=\([^ ]*\) .*/\1/p' >"$TMP/local/image-$service"
            ;;
        esac
        ;;
    buildx)
        [ -f "$TMP/unreadable" ] && return 1
        [ -f "$TMP/registry/${!#}" ] && cat "$TMP/registry/${!#}"
        ;;
    image)
        [ "$2 $3 $4" = 'inspect --format {{ index .Config.Labels "panelalpha.sources.sha256" }}' ] || return 2
        [ -f "$TMP/local/${!#}" ] && cat "$TMP/local/${!#}"
        ;;
    images) true ;;
    esac
}

host() {
    rm -rf "$TMP/local" "$TMP/registry" "$TMP/unreadable"
    mkdir -p "$TMP/local" "$TMP/registry"
    : >"$TMP/unbuildable"
    : >"$TMP/calls"
}
local_image() { printf '%s' "$2" >"$TMP/local/image-$1"; }
published() { printf '%s' "$2" >"$TMP/registry/image-$1"; }
label() { cat "$TMP/local/image-$1" 2>/dev/null; }
builds() { grep -o ' build .*' "$TMP/calls" | sed 's/ --build-arg [^ ]*//; s/^ build //' | tr '\n' ' ' | sed 's/ $//'; }
pulls() { grep -o ' pull .*' "$TMP/calls" | sed 's/^ pull --quiet --ignore-pull-failures //' | tr '\n' ' ' | sed 's/ $//'; }

import_engine_image_functions
for fn in engine_image_inputs engine_image_sources engine_image_built_from engine_built_images engine_published_sources \
    engine_retag_built_from ensure_engine_image; do
    check "$fn is installer.sh's own" "$(sed -n "/^${fn}() {/,/^}\$/p" "$SCRIPT_DIR/installer.sh" | bash -c 'source /dev/stdin; declare -f '"$fn")" \
        "$(declare -f "$fn")"
done
CORE=$(engine_image_sources dockerfiles Dockerfile-core)
BROKER=$(engine_image_sources dockerfiles Dockerfile-root-broker)
MAIL=$(engine_image_sources dockerfiles Dockerfile-exim)

host
local_image core "$CORE"; local_image root-broker "$BROKER"; local_image mail "$MAIL"
touch "$TMP/unreadable"
out=$(ensure_engine_images)
check "images built from this tree, the registry out of reach, are neither pulled nor built" "0||" "$?|$(pulls)|$(builds)"

host
out=$(ensure_engine_images)
check "a host with no image and nothing published: done" "0" "$?"
check "every image the tree builds is looked for in the registry" "core mail root-broker" "$(pulls)"
check "and built" "core mail root-broker" "$(builds)"
check "each labelled with the hash of its sources" "$CORE $BROKER $MAIL" "$(label core) $(label root-broker) $(label mail)"
check "by bare compose, so an operator's override applies" \
    "compose build --build-arg PANELALPHA_SOURCES=$BROKER root-broker" "$(grep ' build ' "$TMP/calls" | tail -n1)"

host
local_image core 'an older tree'; local_image root-broker "$BROKER"; local_image mail "$MAIL"
published core 'a release'
out=$(ensure_engine_images)
check "a released image from other sources is not pulled; the older one is rebuilt" "|core|$CORE" "$(pulls)|$(builds)|$(label core)"

host
published core "$CORE"; published root-broker "$BROKER"; published mail "$MAIL"
out=$(ensure_engine_images)
check "published images built from this tree are pulled, nothing built" "0|core mail root-broker|" "$?|$(pulls)|$(builds)"

host
echo mail >"$TMP/unbuildable"
out=$(ensure_engine_images 2>&1)
check "a failed build stops the deploy and says which" "1|Could not build the mail image from this tree." "$?|$(printf '%s\n' "$out" | tail -n1)"

host
rm "$TREE/dockerfiles/root-broker/pa-root-owned"
out=$(ensure_engine_images 2>&1)
check "a tree missing a file the Dockerfile copies stops the deploy" "1|Could not read the root-broker image's sources from this tree." \
    "$?|$(printf '%s\n' "$out" | tail -n1)"
cp "$REPO_DIR/dockerfiles/root-broker/pa-root-owned" "$TREE/dockerfiles/root-broker/"

check "the bootstrap ensures the images this way" "1" "$(grep -c '^ensure_engine_images$' "$SCRIPT_DIR/bootstrap-from-source.sh")"
check "with jq, which installer.sh's image lookup needs, among the tools it installs" "1" \
    "$(grep -c 'command -v jq >/dev/null || MISSING+=(jq)' "$SCRIPT_DIR/bootstrap-from-source.sh")"

echo
echo "$PASS passed, $FAIL failed"
[[ $FAIL -eq 0 ]]
