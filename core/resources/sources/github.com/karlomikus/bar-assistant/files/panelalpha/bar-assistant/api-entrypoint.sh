#!/bin/bash
# Derives APP_URL=<origin>/bar from PA_PUBLIC_URL (which ComposePlaceholders
# rewrote to the account's https origin), then hands off to the image's own
# entrypoint chain. APP_URL must carry the /bar subpath because the server
# builds upload/image URLs as APP_URL . '/uploads' (config/filesystems.php),
# and the subpath cannot ride in on the placeholder (isLocalPublicUrl anchors
# after host[:port]). A compose `entrypoint:` clears the image CMD, so the
# chain (ENTRYPOINT docker-php-serversideup-entrypoint + CMD /init) is
# reproduced here explicitly.
set -e

ORIGIN="${PA_PUBLIC_URL:-http://localhost}"
export APP_URL="${ORIGIN%/}/bar"
echo "[panelalpha] APP_URL=${APP_URL}"

exec docker-php-serversideup-entrypoint /init
