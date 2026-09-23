#!/bin/bash
set -e
cd ~/project

# Nothing in this checkout is built. The root Dockerfile is a *repackaging*
# step, not a build: its first stage is `FROM outlinewiki/outline-base` (tag
# `latest`, pinned nowhere), and every COPY in the runner stage takes build/,
# server/, public/ and node_modules/ out of that image. The checkout supplies
# no compiled bytes at all, so building it produces whatever outline-base was
# pushed this morning regardless of the commit that was cloned. The file that
# does build from source is Dockerfile.base, and it sets
# NODE_OPTIONS=--max-old-space-size=24000 for the vite pass -- a 24 GB heap
# inside a 2500 MB account.
#
# So the stack runs the project's published image, and the tag is what carries
# the checkout's identity: package.json "version" is the release this tree
# belongs to. MAJOR.MINOR rather than the patch, so a redeploy picks up a patch
# release without ever crossing a minor; the exact version is the fallback for
# a tree whose minor line has not been pushed as a floating tag yet.
OUTLINE_TAG=1.10
VERSION=$(sed -n 's/^[[:space:]]*"version"[[:space:]]*:[[:space:]]*"\([0-9][0-9.]*\)".*/\1/p' package.json | head -1)
if [ -n "${VERSION}" ]; then
    SERIES=$(printf '%s' "${VERSION}" | cut -d. -f1-2)
    for candidate in "${SERIES}" "${VERSION}"; do
        if curl -fsS -o /dev/null --max-time 20 \
            "https://hub.docker.com/v2/repositories/outlinewiki/outline/tags/${candidate}" 2>/dev/null; then
            OUTLINE_TAG="${candidate}"
            break
        fi
    done
fi

# Written once and only once. The postgres volume outlives the checkout, so a
# regenerated POSTGRES_PASSWORD would lock Outline out of its own database;
# SECRET_KEY encrypts stored data and its own file says "do not change this
# value once set or your users will be unable to login".
#
# SECRET_KEY has a format requirement and it is checked, not assumed:
# server/env.ts carries @IsHexadecimal() @Length(64, 64) on it, and a value of
# any other shape makes the process print "Environment configuration is
# invalid" and exit 1 on the next tick -- a restart loop, not a warning.
# UTILS_SECRET is only @IsNotEmpty(), and the same length costs nothing.
# Hex for the database password too: it ends up inside DATABASE_URL, where a
# base64 `/` or `+` would have to be percent-encoded.
#
# Appended key by key rather than written as a block: ProjectEnvironment merges
# the account's own env_vars into this same .env, and a deploy that found one
# already here must add what is missing instead of replacing the file.
touch .env
keep() { grep -q "^$1=" .env || printf '%s=%s\n' "$1" "$2" >> .env; }
keep SECRET_KEY "$(openssl rand -hex 32)"
keep UTILS_SECRET "$(openssl rand -hex 32)"
keep POSTGRES_PASSWORD "$(openssl rand -hex 16)"
chmod 600 .env

# The one line rewritten on every deploy, so a redeploy after an upstream
# release actually moves. The secrets above never do.
sed -i '/^OUTLINE_IMAGE=/d' .env
printf 'OUTLINE_IMAGE=%s\n' "outlinewiki/outline:${OUTLINE_TAG}" >> .env

# Outline issues no password, for anybody, ever: the first-run form asks for a
# workspace name, an admin name and an admin email, and signs that admin in on
# the spot (server/routes/api/installation/installation.ts). So the file where
# a person looks for the credential says what to do instead of holding one.
cat > .panelalpha-admin-password <<'EOF'
Outline has no password login and this deploy generated no admin password.

Open the site. Because no authentication provider is configured, it shows a
"Create workspace" form: fill in the workspace name, your name and your email
and you are signed in as the workspace admin.

Do that BEFORE sharing the address -- the form is open to whoever reaches it
first, and it works exactly once (installation.create refuses a second call).

Then, immediately, give yourself a way back in. The session cookie is the only
one you have until you do:
  * Settings -> Profile -> add a passkey (works with no extra services), or
  * set SMTP_HOST / SMTP_PORT / SMTP_USERNAME / SMTP_PASSWORD /
    SMTP_FROM_EMAIL through the account's environment variables, which turns
    on email sign-in links, or
  * set OIDC_CLIENT_ID / OIDC_CLIENT_SECRET / OIDC_AUTH_URI / OIDC_TOKEN_URI /
    OIDC_USERINFO_URI for a real identity provider.
EOF
chmod 600 .panelalpha-admin-password
