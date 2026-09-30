#!/bin/sh
# audioserve exits at start without a shared secret (the login).
if [ -z "${AUDIOSERVE_SHARED_SECRET:-}" ]; then
    echo "audioserve: missing project environment variable: AUDIOSERVE_SHARED_SECRET. Set it to the shared secret used to log in (14+ characters, writing any \$ as \$\$), then redeploy." >&2
    exit 1
fi
echo "audioserve: shared secret set"
