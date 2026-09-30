#!/bin/bash
set -e
cd ~/project

# The image tag comes from the checkout rather than from a constant, because
# linkace/linkace publishes a tag per major branch (1.x, 2.x) and a clone of
# 1.x must not get 2.x code against a 1.x database. package.json is the only
# file in the repository carrying the product version ("version": "2.6.2");
# config/app.php has an api_version and no app version at all. Anything
# unreadable falls back to 2.x -- the repository's default branch -- rather
# than to :latest, which would silently move a checkout across a major.
LINKACE_MAJOR=$(sed -n 's/^[[:space:]]*"version"[[:space:]]*:[[:space:]]*"\([0-9]\{1,\}\)\..*/\1/p' package.json | head -1)
case "${LINKACE_MAJOR}" in
    ''|*[!0-9]*) LINKACE_MAJOR=2 ;;
esac

# The secrets live in ~/.panelalpha/linkace, written once: ~/project (and its
# .env) is emptied on every deploy, while the MariaDB volume keeps the password
# it was created with and APP_KEY encrypts the sessions and stored OIDC secrets.
# APP_KEY is base64:<32 raw bytes>, the only shape Laravel's Encrypter accepts
# for AES-256-CBC; a wrong-length key is a 500 on every page with a session.
STORE="${HOME}/.panelalpha/linkace"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -s "${STORE}/secrets.env" ]; then
    (
        umask 077
        {
            printf 'APP_KEY=base64:%s\n' "$(openssl rand -base64 32)"
            printf 'DB_PASSWORD=%s\n' "$(openssl rand -hex 16)"
            printf 'DB_ROOT_PASSWORD=%s\n' "$(openssl rand -hex 16)"
        } > "${STORE}/secrets.env.tmp"
        mv "${STORE}/secrets.env.tmp" "${STORE}/secrets.env"
    )
fi
chmod 600 "${STORE}/secrets.env"

# overrides/docker-compose.yml interpolates these, so .env is rebuilt from the
# store on every deploy, before compose reads it.
{
    printf 'LINKACE_IMAGE=linkace/linkace:%s.x\n' "${LINKACE_MAJOR}"
    cat "${STORE}/secrets.env"
} > .env
