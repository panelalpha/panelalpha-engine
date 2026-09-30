#!/bin/bash
set -e
# Created before the bind mount so Docker does not create it as root.
mkdir -p "${HOME}/.panelalpha/opodsync"
chmod 700 "${HOME}/.panelalpha/opodsync"
