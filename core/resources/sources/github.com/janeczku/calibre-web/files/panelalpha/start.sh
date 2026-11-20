#!/bin/sh
# Entrypoint of the app service: installs the login script as a LinuxServer
# custom init script (it has to be executable, and files/ are copied without
# modes), then runs the image's own /init.
set -e
mkdir -p /custom-cont-init.d
install -m 0755 /panelalpha/calibre-web-login.sh /custom-cont-init.d/50-panelalpha-login
exec /init "$@"
