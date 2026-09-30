#!/bin/bash
# config.json, created once from upstream's default-config.json (README's Docker
# example) in ~/.panelalpha: ~/project is emptied on every deploy.
set -e
STORE="${HOME}/.panelalpha/hound"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -s "${STORE}/config.json" ]; then
    cp "${HOME}/project/default-config.json" "${STORE}/config.json"
fi
chmod 644 "${STORE}/config.json"
