#!/bin/bash
# Database password and AUTH_SECRET generated once into ~/.panelalpha (survives
# redeploys; ~/project does not) and reused: the password is baked into the
# postgres volume, AUTH_SECRET signs sessions.
set -e
DIR="$HOME/.panelalpha/didacta"
mkdir -p "$DIR"
chmod 700 "$HOME/.panelalpha" "$DIR" 2>/dev/null || true
umask 077
S="$DIR/secrets.env"
if [ ! -s "$S" ]; then
    printf 'DB_PW=%s\nAUTH=%s\n' "$(openssl rand -hex 24)" "$(openssl rand -hex 32)" > "$S"
fi
. "$S"
printf 'POSTGRES_PASSWORD=%s\n' "$DB_PW" > "$DIR/db.env"
printf 'ADMIN_DATABASE_URL=postgresql://didacta:%s@postgres:5432/didacta?schema=public\nAUTH_SECRET=%s\n' "$DB_PW" "$AUTH" > "$DIR/app.env"
chmod 600 "$DIR"/*
