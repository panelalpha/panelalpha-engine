#!/bin/bash
# Account shell, after the clone and before detection. overrides/ has already
# replaced the repository's docker-compose.yml and files/ has already put
# panelalpha/ beside the application (AppConfigBootstrap::run()).
#
# WeTTY is a terminal on a web page. Everything this script does is about the
# one question that asks: who is allowed to reach the shell, and which shell is
# it. Four things:
#
#   1. one generated password, kept where a redeploy cannot reach it;
#   2. the nginx Basic-auth file and the account name, derived from it;
#   3. the account's name and uid, for the sshd container that cannot know them;
#   4. the compose override carrying the uid, which also cannot be known until
#      now.
set -e
cd ~/project

DATA_HOME="${HOME}/.panelalpha/wetty"
AUTH_DIR="${DATA_HOME}/auth"
PW_STORE="${DATA_HOME}/terminal-password"

say() { echo "[panelalpha] wetty: $*"; }

if [ ! -f src/server/command/address.ts ] || [ ! -f containers/wetty/Dockerfile ]; then
    echo "[panelalpha] wetty: no src/server/command/address.ts -- this is not a wetty checkout" >&2
    exit 1
fi

ACCOUNT="$(id -un)"
ACCOUNT_UID="$(id -u)"
ACCOUNT_GID="$(id -g)"

# ~ itself is chown root:root on every rebuild (Project.php:813) and ~/project
# is cleared and re-cloned (engine#173), so ~/.panelalpha is the only place a
# generated file both survives a redeploy and belongs to the account
# (Project.php:814).
mkdir -p "${AUTH_DIR}"
chmod 700 "${DATA_HOME}" "${AUTH_DIR}"

# ---------------------------------------------------------------------------
# The password
# ---------------------------------------------------------------------------
# One secret, two gates: nginx Basic in front of the socket, and the unix
# password sshd asks for once the terminal opens. Two gates with one secret is
# one factor, not two, and it is written down here rather than implied: the
# Basic layer is there to stop an anonymous visitor from spawning ptys and ssh
# processes, not to be an independent authenticator.
#
# Generated once and kept. A redeploy clears ~/project and leaves ~/.panelalpha
# alone, so regenerating here would hand the customer a new password on every
# deploy while the one they wrote down stopped working.
#
# 24 bytes of openssl randomness with the awkward base64 characters dropped:
# still ~120 bits, and typeable. Nothing about this is a default, a shared
# value or derived from the account name -- the repository's own answer to the
# same question is `term:term` in containers/ssh/Dockerfile.
if [ ! -f "${PW_STORE}" ]; then
    ( umask 077; openssl rand -base64 24 | tr -d '/+=' > "${PW_STORE}" )
    say "generated a terminal password"
else
    say "kept the terminal password already in ${PW_STORE}"
fi
chmod 600 "${PW_STORE}"

# ---------------------------------------------------------------------------
# What nginx needs, derived from it
# ---------------------------------------------------------------------------
# Rewritten every deploy rather than created once, so that the password file is
# the single place a customer has to change anything. apr1 rather than bcrypt
# because the account image has openssl and no htpasswd, and apr1 is the format
# nginx's auth_basic has always read. nginx re-reads this file on every
# request, so a change takes effect without restarting anything.
( umask 077
  printf '%s:%s\n' "${ACCOUNT}" "$(openssl passwd -apr1 -stdin < "${PW_STORE}")" \
      > "${AUTH_DIR}/htpasswd" )
chmod 600 "${AUTH_DIR}/htpasswd"

# The ssh username, pinned. Included inside a `map` block in
# panelalpha/wetty-auth.conf, which is what overwrites the `remote-user` header
# a visitor might send (src/server/command/address.ts:13).
( umask 077; printf 'default "%s";\n' "${ACCOUNT}" > "${AUTH_DIR}/account.map" )
chmod 600 "${AUTH_DIR}/account.map"

# ---------------------------------------------------------------------------
# What the sshd container needs
# ---------------------------------------------------------------------------
# Read by panelalpha/wetty-sshd-entrypoint.sh, which creates exactly one login:
# this account, at this uid, with /account (the home) as its home directory,
# and by the app container for SSHUSER. No password here -- that is read from
# the 0600 file at start, so it is never in an environment variable, a compose
# file or `docker inspect`.
#
# SSHUSER is WeTTY's own idea of the username, for the one case the proxy
# cannot cover: a request that reaches the app container directly from inside
# the account. Without it src/server/command/address.ts:28 prompts for a
# username in the terminal and lets the caller name anyone.
( umask 077; cat > "${DATA_HOME}/identity.env" <<ENVEOF
PA_ACCOUNT=${ACCOUNT}
PA_UID=${ACCOUNT_UID}
PA_GID=${ACCOUNT_GID}
SSHUSER=${ACCOUNT}
ENVEOF
)
chmod 600 "${DATA_HOME}/identity.env"

# An /etc/passwd for the app container, because it runs as the account and the
# account is nobody inside the wetty image. OpenSSH calls getpwuid() on its own
# uid before it does anything else and refuses outright when there is no entry:
# measured, every session died at `No user exists for uid 1002` before a
# password was ever asked for. The alternative is running the web-facing Node
# process as root, which is a worse answer to a smaller problem.
#
# Two lines, no shadow file, no secrets: it replaces the image's /etc/passwd
# and nothing in the image reads it for anything else. 0644 on purpose -- this
# is a passwd file, and the container reads it as the account.
( umask 022; cat > "${DATA_HOME}/passwd" <<PASSWDEOF
root:x:0:0:root:/root:/bin/sh
${ACCOUNT}:x:${ACCOUNT_UID}:${ACCOUNT_GID}:${ACCOUNT}:/tmp:/bin/sh
PASSWDEOF
)
chmod 644 "${DATA_HOME}/passwd"

# Where a public key would go, if the customer prefers one to the password.
# sshd is pointed at this exact path (wetty-sshd.Dockerfile, AuthorizedKeysFile)
# rather than ~/.ssh, because ~/.ssh on a hosting account is also the SFTP
# service's business and these two should not share a file.
if [ ! -f "${DATA_HOME}/authorized_keys" ]; then
    ( umask 077; : > "${DATA_HOME}/authorized_keys" )
fi
chmod 600 "${DATA_HOME}/authorized_keys"

# ---------------------------------------------------------------------------
# The compose override
# ---------------------------------------------------------------------------
# Written here rather than shipped as overrides/docker-compose.override.yml
# because the account's uid is not knowable when a recipe is written. The
# engine passes both files to compose explicitly (Paths::composeFiles()), so
# this is merged over the one overrides/ just wrote, and it is written after
# that file and before the engine hardens and reads it.
cat > docker-compose.override.yml <<OVERRIDEEOF
# Written by PanelAlpha's WeTTY recipe (hooks/prepare.sh). Regenerated on every
# deploy -- edits here do not survive one.
services:
  app:
    # WeTTY as the account rather than as root. src/server/command.ts:63 takes
    # a shortcut when the process is uid 0 and the ssh host is localhost: it
    # skips ssh entirely and runs \`login\` in its own container. The host here
    # is \`shell\`, so that branch is unreachable either way, but a web-facing
    # Node process has no business being root.
    user: "${ACCOUNT_UID}:${ACCOUNT_GID}"

  auth:
    # nginx-unprivileged drops its workers to uid 101 by default, which cannot
    # read a 0600 htpasswd in a 0700 directory the account owns -- every
    # authenticated request would answer 500 while anonymous ones worked. Group
    # 0 because this image makes /etc/nginx and /var/cache/nginx group-writable
    # for gid 0 precisely so it can run as an arbitrary uid.
    user: "${ACCOUNT_UID}:0"
OVERRIDEEOF
say "wrote docker-compose.override.yml (app and auth as ${ACCOUNT_UID})"

# ---------------------------------------------------------------------------
# .dockerignore
# ---------------------------------------------------------------------------
# containers/wetty/Dockerfile:9 is `COPY . /usr/src/app` in the base stage, so
# every layer under it -- two pnpm installs, the node-pty rebuild and the
# esbuild run, 89s of the 106s control deploy -- is thrown away by anything in
# the checkout that differs between two deploys. Two things do: .git, which is
# never identical across two clones of the same commit, and the files the
# platform and this recipe put in ~/project beside the application. None of
# them is read by the build.
#
# Guarded by its own marker so a redeploy does not keep growing the file.
if ! grep -q '^# >>> PanelAlpha' .dockerignore 2>/dev/null; then
    cat >> .dockerignore <<'IGNOREEOF'

# >>> PanelAlpha: keep `COPY . /usr/src/app` cacheable across redeploys, and
# keep the platform's own files out of the image.
.git
docker-compose.yml
docker-compose.override.yml
.env
panelalpha/
IGNOREEOF
    say "excluded .git and the platform's files from the build context"
fi

# ---------------------------------------------------------------------------
# The page the customer reads next
# ---------------------------------------------------------------------------
( umask 077; cat > "${DATA_HOME}/README.panelalpha.md" <<'MDEOF'
# A terminal on your domain

    https://<your domain>/panelalpha-login

Username: your account name. Password: the contents of `terminal-password`
beside this file.

You will be asked for it twice. The first is HTTP Basic, served by the `auth`
container, and it is what lets you open a session at all. The second is the
prompt inside the terminal, and that one is `sshd`'s -- it is the one that
actually opens the shell.

## What the shell is

A container of its own, running as your account's own uid, with your home
directory mounted at `/account` and nothing else. `id` will show your account
name and uid; `ls ~` will show `project/`, `.panelalpha/` and your domain
directory, the same files you see over SFTP.

It is **not** the container your application runs in, and it has **no network
access at all** -- no internet, no other containers, not even the rest of your
own stack. That is on purpose: a shell that a stranger is invited to try a
password against should not be able to open connections. `git`, an editor and
the usual file tools are installed; anything that needs to download will not
work.

If you want it on the network, add to a compose file of your own:

    services:
      shell:
        networks: [default, shell]

and understand what you are doing: from an ordinary container an account can
open TCP connections to other machines on the host's networks.

## Changing the password

    echo 'your new password' > ~/.panelalpha/wetty/terminal-password
    printf '%s:%s\n' "$(id -un)" \
      "$(openssl passwd -apr1 -stdin < ~/.panelalpha/wetty/terminal-password)" \
      > ~/.panelalpha/wetty/auth/htpasswd
    cd ~/project && docker compose restart shell

nginx re-reads `htpasswd` on every request; `sshd` reads the password file when
its container starts, which is what the restart is for.

## A key instead of a password

Put one line per key in `~/.panelalpha/wetty/authorized_keys` and restart the
`shell` container. The HTTP Basic gate still applies -- a browser cannot offer
an ssh key -- so this replaces the prompt inside the terminal, not the one in
front of it.

## What lives where

    ~/.panelalpha/wetty/
      terminal-password      the generated password, plain text, 0600
      authorized_keys        ssh keys, if you would rather not type a password
      identity.env           your account name and uid, for the sshd container
      auth/htpasswd          the same password, hashed, for nginx
      auth/account.map       the ssh username nginx pins on every request
      README.panelalpha.md   this file

`~/project` is deleted and re-cloned on every deploy. Nothing you want to keep
belongs in it.

## Things worth knowing

  * WeTTY authenticates nobody. It reads `remote-user` out of the request and
    uses it as the ssh username, and it will take a `?pass=` out of the page
    URL and log in with it. The `auth` container overwrites the first and
    removes the second on every request. If you replace
    `panelalpha/wetty-auth.conf`, keep the `proxy_set_header remote-user` and
    `proxy_set_header Referer ""` lines in both proxy blocks.
  * `/metrics` is served by WeTTY to anyone who asks. The `auth` container
    answers 404 there instead.
  * Closing the browser tab ends the session: the pty is killed when the socket
    disconnects. There is no `screen` or `tmux` in the image; add one to
    `panelalpha/wetty-sshd.Dockerfile` if you want sessions to survive.
  * The terminal runs over long-polling rather than a websocket on
    `*.panelalpha.online` names, because that edge strips the `Upgrade` header.
    It works either way; it is a little less immediate.
MDEOF
)
chmod 600 "${DATA_HOME}/README.panelalpha.md"

say "prepared; password in ${PW_STORE}, notes in ${DATA_HOME}/README.panelalpha.md"
