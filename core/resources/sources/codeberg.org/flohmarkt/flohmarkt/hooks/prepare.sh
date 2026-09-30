#!/bin/bash
# Generate the CouchDB admin password once; the database volume keeps using it.
set -e
DATA="${HOME}/.panelalpha/flohmarkt"
mkdir -p "$DATA"
chmod 700 "$DATA"
if [ ! -f "$DATA/db.env" ]; then
    pw="$(openssl rand -hex 24)"
    (umask 077; printf 'COUCHDB_PASSWORD=%s\nFLOHMARKT_DB_PASSWORD=%s\n' "$pw" "$pw" > "$DATA/db.env")
fi
