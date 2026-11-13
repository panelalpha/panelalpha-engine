#!/bin/bash
#
# Every image the engine builds is tagged with the engine's version, so an install pulls the images of its release.
#
#   bash tests/scripts/engine-image-tags.test.sh

set -uo pipefail

REPO_DIR="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/../.." && pwd)"

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

VERSION="v$(sed -n "s/^ *'version' => '\([^']*\)',.*/\1/p" "$REPO_DIR/core/config/system.php")"
check "the engine has a version" "yes" "$([[ "$VERSION" =~ ^v[0-9]+\.[0-9]+ ]] && echo yes)"

# The image of every service with a build section, as "<file>: <image>".
built_images() {
    local file
    for file in "$REPO_DIR"/docker-compose.yml "$REPO_DIR"/docker-compose.yml-*; do
        awk -v file="$(basename "$file")" '
            /^  [a-z][a-z0-9-]*:$/ { build = 0; image = "" }
            /^    build:/ { build = 1 }
            /^    image: / { image = $2 }
            build && image != "" { print file ": " image; build = 0; image = "" }
        ' "$file"
    done
}

images=$(built_images)
check "the compose files build images" "yes" "$([ "$(printf '%s\n' "$images" | grep -c .)" -ge 10 ] && echo yes)"
while read -r file image; do
    check "$file $image is tagged $VERSION" "$VERSION" "${image##*:}"
done <<<"$images"

check "the core database helper runs the release's database image" \
    "ghcr.io/panelalpha/app-database:$VERSION" \
    "$(sed -n 's/^IMAGE="\(.*\)"$/\1/p' "$REPO_DIR/scripts/get-database-core-root-password.sh")"
check "every build context is in the repository" "" \
    "$(for f in "$REPO_DIR"/docker-compose.yml "$REPO_DIR"/docker-compose.yml-*; do
        sed -n 's/^ *context: \.\/\(.*\)$/\1/p' "$f"; done | sort -u | while read -r c; do
        git -C "$REPO_DIR" ls-files --error-unmatch "$c" >/dev/null 2>&1 || echo "$c"; done)"

echo
echo "$PASS passed, $FAIL failed"
[[ $FAIL -eq 0 ]]
