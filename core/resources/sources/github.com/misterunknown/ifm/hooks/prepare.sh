#!/bin/bash
set -e
# Created before the bind mount so Docker does not create it as root.
mkdir -p "${HOME}/.panelalpha/ifm"
chmod 700 "${HOME}/.panelalpha/ifm"
