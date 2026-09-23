#!/bin/bash
# Entrypoint wrapper for the manticore service. Backgrounds the authentication
# bootstrap and then hands off to the image's own entrypoint unchanged.
#
# Why the bootstrap is here rather than in a service of its own: AuthBootstrap()
# reads pid_file, sends SIGUSR1 to that pid and waits for a reply on a named
# FIFO (src/auth/auth_bootstrap.cpp, src/daemon/daemon_ipc.cpp). That needs the
# daemon's pid namespace and its /run, which a sibling container does not have.
#
# Nothing is open while this runs. `auth = 1` is in the generated config before
# searchd starts, and with it every HTTP request without an Authorization
# header is refused with 401 whether or not a user exists yet -- CheckAuth() in
# src/auth/auth_proto_http.cpp is called for every path and has no exemptions.
# The window between "listening" and "bootstrapped" is closed, not open.
set -eo pipefail

# Compose runs this as root; the image's entrypoint is what drops to the
# manticore user, and it has not run yet. The bootstrap must be that user
# already: it writes auth.json, and a root-owned 0600 auth.json is one the
# daemon cannot read back. Measured on 29.9.0 -- the bootstrap reports
# "authentication settings successfully created and reloaded" only when the
# reload can re-read the file, and otherwise leaves the daemon refusing the
# credential it just wrote until the container is restarted.
if [ "$(id -u)" = "0" ]; then
    gosu manticore /pa/bootstrap.sh &
else
    /pa/bootstrap.sh &
fi

exec docker-entrypoint.sh "$@"
