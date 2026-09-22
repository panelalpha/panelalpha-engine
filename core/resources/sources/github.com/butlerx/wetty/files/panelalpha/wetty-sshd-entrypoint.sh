#!/bin/sh
# Start of the sshd WeTTY logs into. Runs as root inside its own container --
# sshd has to, to change uid -- and everything it creates belongs to the
# account.
set -eu

fail() { echo "[panelalpha] wetty-sshd: $*" >&2; exit 1; }

: "${PA_ACCOUNT:?identity.env did not set PA_ACCOUNT}"
: "${PA_UID:?identity.env did not set PA_UID}"
: "${PA_GID:?identity.env did not set PA_GID}"

[ -d /account ] || fail "the account's home is not mounted at /account"

SECRET=/account/.panelalpha/wetty/terminal-password
[ -f "$SECRET" ] || fail "no password at ${SECRET#/account/} -- hooks/prepare.sh writes it"

# The one login. Created at the account's own uid and gid, so everything the
# customer touches in this terminal is owned by them exactly as it is over
# SFTP, and so nothing here can write a file their own account cannot read.
addgroup -g "$PA_GID" "$PA_ACCOUNT" 2>/dev/null || true
adduser -D -H -u "$PA_UID" -G "$PA_ACCOUNT" -h /account -s /bin/bash "$PA_ACCOUNT" 2>/dev/null || true

# The password, from the file, on every start. Never baked into a layer, never
# an environment variable, never in a compose file -- so `docker inspect`,
# `docker history` and the deploy log all have nothing to leak. A customer who
# edits that file gets the new password on the next `docker compose restart
# shell`.
PW=$(head -n 1 "$SECRET")
[ -n "$PW" ] || fail "the password file is empty; refusing to start a shell with no password"
printf '%s:%s\n' "$PA_ACCOUNT" "$PW" | chpasswd

# Only this account may log in. Belt and braces over PermitRootLogin no: the
# image has no other user with a password, and a locked account cannot
# authenticate, but an AllowUsers line is the statement of intent.
grep -q '^AllowUsers ' /etc/ssh/sshd_config || \
    printf 'AllowUsers %s\n' "$PA_ACCOUNT" >> /etc/ssh/sshd_config

# Fresh host keys each start. They are not pinned by anything: WeTTY passes
# UserKnownHostsFile=/dev/null and StrictHostKeyChecking=no when KNOWNHOSTS is
# unset (src/server/command/ssh.ts:17,29-31), which is safe here only because
# the far end is one hop across a bridge with no gateway -- there is nowhere
# for a man in the middle to stand.
ssh-keygen -A >/dev/null

echo "[panelalpha] wetty-sshd: ready for ${PA_ACCOUNT} (uid ${PA_UID})" >&2
exec /usr/sbin/sshd -D -e
