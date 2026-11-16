#!/bin/bash
#
# docker-compose.yml-webserver is a `cp -n` copy of one variant, so operator edits survive updates.
# It takes the variant's `image:` (same repository only) and a missing `ulimits:` block, nothing else.
# A symlink (what webserver.sh leaves) already follows the shipped file.
#
#   bash scripts/refresh-webserver-image.sh /opt/panelalpha/shared-hosting

set -euo pipefail

dir="${1:?usage: refresh-webserver-image.sh <engine dir>}"
copy="$dir/docker-compose.yml-webserver"
{ [ -f "$copy" ] && [ ! -L "$copy" ]; } || exit 0

variant=$(sed -n 's/.*com\.panelalpha\.webserver=\([a-z-]*\).*/\1/p' "$copy" | head -1)
shipped="$dir/docker-compose.yml-$variant"
{ [ -n "$variant" ] && [ -f "$shipped" ]; } || exit 0

refresh_image() {
    local image current
    image=$(sed -n 's/^    image: *//p' "$shipped" | head -1)
    current=$(sed -n 's/^    image: *//p' "$copy" | head -1)
    { [ -n "$image" ] && [ -n "$current" ] && [ "$current" != "$image" ]; } || return 0
    [ "${current%:*}" = "${image%:*}" ] || return 0

    sed -i "s#^    image: .*#    image: ${image}#" "$copy"
    echo "Webserver image ${current} -> ${image}"
}

# The shipped block and its comment, after the copy's `restart:` line, else its `image:` line.
add_ulimits() {
    grep -q '^    ulimits:' "$copy" && return 0
    local block anchor
    block=$(awk '
        /^    #/ { comment = comment $0 "\n"; next }
        /^    ulimits:/ { printf "%s%s\n", comment, $0; inside = 1; comment = ""; next }
        inside && /^      / { print; next }
        { inside = 0; comment = "" }
    ' "$shipped")
    [ -n "$block" ] || return 0
    anchor='^    restart:'
    grep -q "$anchor" "$copy" || anchor='^    image:'
    grep -q "$anchor" "$copy" || return 0

    local tmp
    tmp=$(mktemp)
    BLOCK="$block" awk -v anchor="$anchor" '
        { print }
        !done && $0 ~ anchor { print ENVIRON["BLOCK"]; done = 1 }
    ' "$copy" >"$tmp"
    # Written over, not moved: the copy keeps its owner and mode.
    cat "$tmp" >"$copy"
    rm -f "$tmp"
    echo "Webserver open-files limit added from docker-compose.yml-${variant}"
}

refresh_image
add_ulimits
