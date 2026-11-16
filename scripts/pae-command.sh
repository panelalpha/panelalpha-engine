#!/usr/bin/env bash

set -e

ENGINE_DIR="${ENGINE_DIR:-/opt/panelalpha/shared-hosting}"
ARTISAN_PATH="${ARTISAN_PATH:-/usr/local/bin/pae-artisan}"
ALIAS_PATH="${ALIAS_PATH:-/usr/local/bin/pae}"
BROKER_PATH="${BROKER_PATH:-/usr/local/bin/pae-broker}"

default_color='\e[39m'
red_color='\e[31m'
green_color='\e[32m'
yellow_color='\e[33m'

echo_info()    { echo -e "${green_color}${1}${default_color}"; }
echo_warning() { echo -e "${yellow_color}${1}${default_color}"; }
echo_error()   { echo -e "${red_color}${1}${default_color}" >&2; }

usage() {
    echo "Usage: $0 {register|unregister}"
    echo
    echo "  register    Install the 'pae-artisan' command to ${ARTISAN_PATH} and 'pae-broker' to ${BROKER_PATH}"
    echo "  unregister  Remove the command from PATH"
    exit 1
}

cmd_register() {
    if [[ $EUID -ne 0 ]]; then
        echo_error "Root privileges are required to install the command."
        exit 1
    fi
    if [[ ! -f "${ENGINE_DIR}/scripts/pae.sh" ]]; then
        echo_error "Wrapper not found: ${ENGINE_DIR}/scripts/pae.sh"
        exit 1
    fi
    chmod +x "${ENGINE_DIR}/scripts/pae.sh"
    ln -sfn "${ENGINE_DIR}/scripts/pae.sh" "${ARTISAN_PATH}"
    ln -sfn "${ENGINE_DIR}/scripts/pae.sh" "${ALIAS_PATH}"
    if [[ -f "${ENGINE_DIR}/scripts/pae-broker.sh" ]]; then
        chmod +x "${ENGINE_DIR}/scripts/pae-broker.sh"
        ln -sfn "${ENGINE_DIR}/scripts/pae-broker.sh" "${BROKER_PATH}"
    fi
    # The Go CLI this replaces installed a binary under the same name; a stale
    # one would shadow nothing but would still confuse `which paengine`.
    rm -f /usr/local/bin/paengine /etc/panelalpha/paengine.yaml
    echo_info "'pae-artisan' command installed at ${ARTISAN_PATH} (alias: ${ALIAS_PATH}), 'pae-broker' at ${BROKER_PATH}."
}

cmd_unregister() {
    if [[ $EUID -ne 0 ]]; then
        echo_error "Root privileges are required to remove the command."
        exit 1
    fi
    rm -f "${ARTISAN_PATH}" "${ALIAS_PATH}" "${BROKER_PATH}" /usr/local/bin/paengine
    echo_info "Command removed from /usr/local/bin."
}

case "${1:-}" in
    register)   cmd_register ;;
    unregister) cmd_unregister ;;
    *)          usage ;;
esac
