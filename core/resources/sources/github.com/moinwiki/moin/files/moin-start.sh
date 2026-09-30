#!/bin/sh
# Installs the moin release into a venv on a volume (once per version), creates
# the wiki instance on first boot, then serves it with hypercorn like contrib/docker.
set -eu
MOIN_VERSION=2.0.0b5
VENV=/opt/moin-venv

if [ ! -f "$VENV/.moin-$MOIN_VERSION" ]; then
    python -m venv "$VENV"
    # hypercorn pinned as in upstream's contrib/docker/Dockerfile
    "$VENV/bin/pip" install --no-cache-dir "moin==$MOIN_VERSION" "hypercorn==0.17.3"
    touch "$VENV/.moin-$MOIN_VERSION"
fi

cd /srv/moin
if [ ! -f wikiconfig.py ]; then
    "$VENV/bin/moin" create-instance
    # The shipped wikiconfig signs cookies with a fixed placeholder; give this wiki its own key.
    key=$(python -c 'import secrets; print(secrets.token_hex(32))')
    sed -i "s|^SECRET_KEY = .*|SECRET_KEY = \"$key\"|" wikiconfig.py
fi
if [ ! -f .instance-built ]; then
    # --full builds the search index and loads the help and welcome pages.
    "$VENV/bin/moin" create-instance --full
    touch .instance-built
fi

exec "$VENV/bin/hypercorn" --config /etc/moin/config.toml "wsgi:moin.app:create_app()"
