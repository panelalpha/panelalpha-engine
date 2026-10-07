#!/bin/bash
#
# docker-compose.yml-webserver is a copy of one docker-compose.yml-<variant>,
# made once with `cp -n` so an operator's edits survive updates. It also kept
# the image tag of the day it was copied, so a new webserver image never
# reached an installed host. Take the `image:` line from the variant named in
# the copy's com.panelalpha.webserver label; nothing else in the file changes.
# An image from another repository is the operator's choice and stays, and a
# symlink (what webserver.sh leaves) already follows the shipped file.
#
#   bash scripts/refresh-webserver-image.sh /opt/panelalpha/shared-hosting

set -euo pipefail

dir="${1:?usage: refresh-webserver-image.sh <engine dir>}"
copy="$dir/docker-compose.yml-webserver"
{ [ -f "$copy" ] && [ ! -L "$copy" ]; } || exit 0

variant=$(sed -n 's/.*com\.panelalpha\.webserver=\([a-z-]*\).*/\1/p' "$copy" | head -1)
shipped="$dir/docker-compose.yml-$variant"
{ [ -n "$variant" ] && [ -f "$shipped" ]; } || exit 0

image=$(sed -n 's/^    image: *//p' "$shipped" | head -1)
current=$(sed -n 's/^    image: *//p' "$copy" | head -1)
{ [ -n "$image" ] && [ -n "$current" ] && [ "$current" != "$image" ]; } || exit 0
[ "${current%:*}" = "${image%:*}" ] || exit 0

sed -i "s#^    image: .*#    image: ${image}#" "$copy"
echo "Webserver image ${current} -> ${image}"
