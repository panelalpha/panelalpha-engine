#!/bin/bash
set -Eeo pipefail

# shellcheck disable=2154
trap 's=$?; echo "$0: Error on line "$LINENO": $BASH_COMMAND"; exit $s' ERR

function log() {
    echo "[$0] $*" >&2
}

# Allow running other programs, e.g. bash
if [[ -z "$1" || "$1" =~ $reArgsMaybe ]]; then
    startSshd=true
else
    startSshd=false
fi

# Generate unique ssh keys for this container, if needed
if [ ! -f /etc/ssh/ssh_host_ed25519_key ]; then
    ssh-keygen -t ed25519 -f /etc/ssh/ssh_host_ed25519_key -N ''
fi
if [ ! -f /etc/ssh/ssh_host_rsa_key ]; then
    ssh-keygen -t rsa -b 4096 -f /etc/ssh/ssh_host_rsa_key -N ''
fi

# Restrict access from other users
chmod 600 /etc/ssh/ssh_host_ed25519_key || true
chmod 600 /etc/ssh/ssh_host_rsa_key || true

bash /etc/sftp/sync-logins.sh

if $startSshd; then
    log "Executing sshd"
    exec /usr/sbin/sshd -D -e
else
    log "Executing $*"
    exec "$@"
fi
