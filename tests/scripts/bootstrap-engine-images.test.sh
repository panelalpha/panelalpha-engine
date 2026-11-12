#!/bin/bash
#
# bootstrap-from-source.sh uses core and root-broker images only when built from the uploaded tree.
#
#   bash tests/scripts/bootstrap-engine-images.test.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/../../scripts" && pwd)"
REPO_DIR="$(dirname "$SCRIPT_DIR")"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

for fn in import_engine_image_functions ensure_service_image ensure_core_images; do
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

# Stub state in $TMP: services, local/image-<service>, registry/<service> and unbuildable.
selected() { [ "$1" = core ] && echo root-broker; echo "$1"; }
docker() {
    local args=("$@") service
    case "$1" in
    compose)
        echo "${args[*]}" >>"$TMP/calls"
        service="${!#}"
        case " ${args[*]} " in
        *" config --services "*) cat "$TMP/services" ;;
        *" config --format json "*)
            for s in $(selected "$service"); do printf '"%s":{"image":"image-%s"}\n' "$s" "$s"; done |
                paste -sd, | sed 's/^/{"name":"shared-hosting","services":{/; s/$/}}/'
            ;;
        *" pull "*) [ -f "$TMP/registry/$service" ] && cp "$TMP/registry/$service" "$TMP/local/image-$service"; true ;;
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

host() {
    rm -rf "$TMP/local" "$TMP/registry"
    mkdir -p "$TMP/local" "$TMP/registry"
    printf 'core\nroot-broker\nsites-db\n' >"$TMP/services"
    : >"$TMP/unbuildable"
    : >"$TMP/calls"
}
local_image() { printf '%s' "$2" >"$TMP/local/image-$1"; }
published() { printf '%s' "$2" >"$TMP/registry/$1"; }
label() { cat "$TMP/local/image-$1" 2>/dev/null; }
builds() { grep -o ' build .*' "$TMP/calls" | sed 's/ --build-arg [^ ]*//; s/^ build //' | tr '\n' ' ' | sed 's/ $//'; }
pulls() { grep -o ' pull .*' "$TMP/calls" | sed 's/^ pull --quiet --ignore-pull-failures //' | tr '\n' ' ' | sed 's/ $//'; }

import_engine_image_functions
for fn in engine_image_dockerfile engine_image_inputs engine_image_sources engine_image_built_from engine_service_image; do
    check "$fn is installer.sh's own" "$(sed -n "/^${fn}() {/,/^}\$/p" "$SCRIPT_DIR/installer.sh" | bash -c 'source /dev/stdin; declare -f '"$fn")" \
        "$(declare -f "$fn")"
done
CORE=$(engine_image_sources dockerfiles Dockerfile-core)
BROKER=$(engine_image_sources dockerfiles Dockerfile-root-broker)
check "the two images hash differently" "yes" "$([ -n "$CORE" ] && [ -n "$BROKER" ] && [ "$CORE" != "$BROKER" ] && echo yes)"

host
local_image core "$CORE"
local_image root-broker "$BROKER"
out=$(ensure_core_images)
check "images built from this tree are neither pulled nor built" "0||" "$?|$(pulls)|$(builds)"

host
out=$(ensure_core_images)
check "a host with neither image and nothing published: done" "0" "$?"
check "both are looked for in the registry" "core root-broker" "$(pulls)"
check "and built" "core root-broker" "$(builds)"
check "each labelled with the hash of its sources" "$CORE $BROKER" "$(label core) $(label root-broker)"
check "by bare compose, so an operator's override applies" \
    "compose build --build-arg PANELALPHA_SOURCES=$BROKER root-broker" "$(grep ' build ' "$TMP/calls" | tail -n1)"
check "and says so" "1" "$(printf '%s\n' "$out" | grep -c "^warn:No 'root-broker' image built from this tree — building it$")"

host
local_image core 'an older tree'
local_image root-broker 'an older tree'
out=$(ensure_core_images)
check "older images under the same tags are rebuilt, though compose's pull skips a tag it has" "core root-broker" "$(builds)"
check "and carry this tree's hashes" "$CORE $BROKER" "$(label core) $(label root-broker)"

host
local_image core ''
local_image root-broker ''
out=$(ensure_core_images)
check "an image with no label, as earlier source deploys left it, is rebuilt" "core root-broker" "$(builds)"

host
published core "$CORE"
published root-broker "$BROKER"
out=$(ensure_core_images)
check "published images built from this tree are pulled" "0|core root-broker" "$?|$(pulls)"
check "and nothing is built" "" "$(builds)"

host
local_image core "$CORE"
local_image root-broker 'an older tree'
echo root-broker >"$TMP/unbuildable"
out=$(ensure_core_images 2>&1)
check "a failed build stops the deploy" "1" "$?"
check "and says which" "Could not build the root-broker image from this tree." "$(printf '%s\n' "$out" | tail -n1)"

host
local_image core 'an older tree'
echo core >"$TMP/unbuildable"
out=$(ensure_core_images 2>&1)
check "a failed core build stops before the broker's" "1|core" "$?|$(builds)"

host
printf 'core\nsites-db\n' >"$TMP/services"
local_image core "$CORE"
out=$(ensure_core_images)
check "a service the compose file does not have is skipped" "0|1" \
    "$?|$(printf '%s\n' "$out" | grep -c "^warn:No compose service 'root-broker' — skipping its image$")"

host
local_image core "$CORE"
rm "$TREE/dockerfiles/root-broker/pa-root-owned"
out=$(ensure_core_images 2>&1)
check "a tree missing a file the Dockerfile copies stops the deploy" "1|Could not read the root-broker image or its sources from this tree." \
    "$?|$(printf '%s\n' "$out" | tail -n1)"
cp "$REPO_DIR/dockerfiles/root-broker/pa-root-owned" "$TREE/dockerfiles/root-broker/"

check "the bootstrap reads the images this way" "1" "$(grep -c '^    import_engine_image_functions$' "$SCRIPT_DIR/bootstrap-from-source.sh")"
check "with jq, which installer.sh's image lookup needs, among the tools it installs" "1" \
    "$(grep -c 'command -v jq >/dev/null || MISSING+=(jq)' "$SCRIPT_DIR/bootstrap-from-source.sh")"

echo
echo "$PASS passed, $FAIL failed"
[[ $FAIL -eq 0 ]]
