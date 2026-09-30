#!/bin/bash
# APP_KEY and database passwords generated once into ~/.panelalpha (survives
# redeploys; ~/project does not) and reused: the DB passwords are baked into
# the mysql volume, APP_KEY encrypts sessions and stored values.
set -e
DIR="$HOME/.panelalpha/soketi-app-manager"
mkdir -p "$DIR"
chmod 700 "$HOME/.panelalpha" "$DIR" 2>/dev/null || true
umask 077
S="$DIR/secrets.env"
if [ ! -s "$S" ]; then
    {
        echo "ROOT_PW=$(openssl rand -hex 24)"
        echo "DB_PW=$(openssl rand -hex 24)"
        echo "APP_KEY=base64:$(openssl rand -base64 32 | tr -d '\n')"
    } > "$S"
fi
. "$S"
printf 'MYSQL_ROOT_PASSWORD=%s\nMYSQL_PASSWORD=%s\n' "$ROOT_PW" "$DB_PW" > "$DIR/mysql.env"
printf 'SOKETI_DB_MYSQL_PASSWORD=%s\n' "$DB_PW" > "$DIR/soketi.env"
printf 'APP_KEY=%s\nDB_PASSWORD=%s\n' "$APP_KEY" "$DB_PW" > "$DIR/app.env"
chmod 600 "$DIR"/*
