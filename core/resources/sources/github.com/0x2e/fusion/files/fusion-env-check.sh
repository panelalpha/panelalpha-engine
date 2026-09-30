#!/bin/sh
# Fusion refuses to start without a login password (or OIDC).
if [ -z "${FUSION_PASSWORD:-}" ]; then
    echo "fusion: missing project environment variable FUSION_PASSWORD (the login password; write any \$ as \$\$). Set it, then redeploy." >&2
    exit 1
fi
echo "fusion: login password set"
