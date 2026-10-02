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
    # 2222 as published: the host firewall matches a published port after the DNAT.
    # Logs go to the host's journal as local5, so fail2ban can tell SFTP
    # logins from the host's own SSH; without the socket, to the container log.
    if [ -S /dev/log ]; then
        exec /usr/sbin/sshd -D -p 2222 -o SyslogFacility=LOCAL5
    fi
    exec /usr/sbin/sshd -D -e -p 2222
else
    log "Executing $*"
    exec "$@"
fi
