#!/usr/bin/env bash

install_csf() {
    apt-get install -y libwww-perl liblwp-protocol-https-perl libgd-graph-perl

    # Download and install CSF
    cd /usr/src
    rm -fv csf.tgz
    cp /opt/panelalpha/shared-hosting/third-party/csf.tgz ./csf.tgz
    tar -xzf csf.tgz
    cd csf
    sh install.sh

    # Enable CSF and Docker support
    sed -i 's/TESTING = "1"/TESTING = "0"/' /etc/csf/csf.conf
    sed -i 's/DOCKER = "0"/DOCKER = "1"/' /etc/csf/csf.conf

    # Detect and apply Docker bridge device/network
    docker_network_name="pash-default-network"
    docker_network_id=$(docker network inspect -f '{{.Id}}' "$docker_network_name" 2>/dev/null | cut -c1-12)
    if [ -n "$docker_network_id" ]; then
        docker_device="br-$docker_network_id"
    else
        docker_device=$(ip -o link show | awk -F': ' '{print $2}' | grep '^br-' | head -n 1)
    fi
    sed -i "s#^DOCKER_DEVICE = \".*\"#DOCKER_DEVICE = \"$docker_device\"#" /etc/csf/csf.conf
    
    docker_network=$(ip addr show "$docker_device" | grep "inet " | awk '{print $2}')
    sed -i "s#^DOCKER_NETWORK4 = \".*\"#DOCKER_NETWORK4 = \"$docker_network\"#" /etc/csf/csf.conf

    # docker_network6=$(ip addr show "$docker_device" | grep "inet6 " | awk '{print $2}' | grep -v fe80 | head -n 1)
    # if [ -n "$docker_network6" ]; then
    #     # normalize host (::1) to network (::)
    #     docker_network6=$(echo "$docker_network6" | sed 's/::1\//::\//')
    #     sudo sed -i "s#^DOCKER_NETWORK6 = \".*\"#DOCKER_NETWORK6 = \"$docker_network6\"#" /etc/csf/csf.conf
    #     echo "Detected Docker IPv6 network: $docker_network6"
    # fi

    # Allow Docker network in CSF. A reinstall can put the network on another
    # subnet; the entry an earlier install wrote would otherwise stay allowed.
    echo "Allowing Docker network: $docker_network"
    drop_stale_docker_networks /etc/csf/csf.allow "$docker_network"
    if ! grep -q "^$docker_network" /etc/csf/csf.allow; then
        echo "$docker_network # docker internal network" >>/etc/csf/csf.allow
    fi

    # Allow required ports
    sed -i 's/^TCP_IN =.*/TCP_IN = "22,80,443,2011,21,2222,30000:30009,8000,3306,5432"/' /etc/csf/csf.conf
    sed -i 's/^TCP_OUT =.*/TCP_OUT = "22,80,443,2011,21,2222,30000:30009,8000,3306,5432"/' /etc/csf/csf.conf
    sed -i 's/^UDP_IN =.*/UDP_IN = "53,67,68,30000:30009"/' /etc/csf/csf.conf
    sed -i 's/^UDP_OUT =.*/UDP_OUT = "53,67,68,30000:30009"/' /etc/csf/csf.conf

    # Ensure outbound connections are allowed. Appending blindly grows the file
    # by a line on every update, and int-updater runs this on each one.
    if grep -q '^ALLOWOUT' /etc/csf/csf.conf; then
        sed -i 's/^ALLOWOUT = ".*/ALLOWOUT = "1"/' /etc/csf/csf.conf
    else
        echo 'ALLOWOUT = "1"' >>/etc/csf/csf.conf
    fi

    # configure ui
    # generate ui password if not set
    CSF_UI_PASSWORD=$(grep ^CSF_UI_PASSWORD= /opt/panelalpha/shared-hosting/.env | cut -d '=' -f2-)
    if [ -z "${CSF_UI_PASSWORD}" ]; then
        CSF_UI_PASSWORD=$(random-string 12)
        if grep -q ^CSF_UI_PASSWORD= /opt/panelalpha/shared-hosting/.env; then
            sed -i "s|^CSF_UI_PASSWORD=.*|CSF_UI_PASSWORD=${CSF_UI_PASSWORD}|" /opt/panelalpha/shared-hosting/.env
        else
            echo "" >>/opt/panelalpha/shared-hosting/.env
            echo "CSF_UI_PASSWORD=${CSF_UI_PASSWORD}" >>/opt/panelalpha/shared-hosting/.env
        fi
    fi
    sed -i 's/^UI = ".*/UI = "1"/' /etc/csf/csf.conf
    sed -i 's/^UI_PORT = ".*/UI_PORT = "2012"/' /etc/csf/csf.conf
    sed -i 's/^UI_USER = ".*/UI_USER = "panelalpha"/' /etc/csf/csf.conf
    sed -i "s/^UI_PASS = \".*/UI_PASS = \"$CSF_UI_PASSWORD\"/" /etc/csf/csf.conf
    drop_stale_docker_networks /etc/csf/ui/ui.allow "$docker_network"
    if ! grep -q "^$docker_network" /etc/csf/ui/ui.allow; then
        echo "$docker_network # docker internal network" >>/etc/csf/ui/ui.allow
    fi

    install_docker_build_rules

    # CSF's own installer enables the units but never starts them, and the
    # TESTING = 0 above only takes effect once the ruleset is applied — so an
    # install used to leave the host with no firewall until its next reboot.
    #
    # Not a plain restart: with FASTSTART (CSF's default) stopping saves the live
    # ruleset and `csf --initup` restores that snapshot instead of reading the
    # config. On a host where CSF already ran, every edit above was ignored --
    # measured: a reinstall kept the previous install's docker network and not
    # the current one, so the proxy could reach an app only on 80 and 8000
    # (engine#271). Dropping the snapshot between stop and start makes start
    # build from the config; the unit still ends up active.
    if [ -f /etc/csf/csf.disable ]; then
        echo "csf: /etc/csf/csf.disable is present, leaving the firewall down"
    else
        systemctl stop csf || true
        rm -f /var/lib/csf/csf.4.saved /var/lib/csf/csf.6.saved /var/lib/csf/csf.4.ipsets /var/lib/csf/csf.6.ipsets
        systemctl start csf || csf -r || true
        systemctl restart lfd || service lfd restart || true
    fi
}

# Remove `# docker internal network` lines naming any network but $2.
drop_stale_docker_networks() {
    local file="$1" keep="$2"
    [ -f "$file" ] || return 0
    awk -v keep="$keep" '
        / # docker internal network$/ && $1 != keep { next }
        { print }
    ' "$file" >"$file.tmp" && cat "$file.tmp" >"$file" && rm -f "$file.tmp"
}

# CSF is told about the compose bridge above (DOCKER_DEVICE / DOCKER_NETWORK4)
# and writes FORWARD and MASQUERADE rules for that bridge alone. Nothing covers
# docker0, and `docker build` containers live on docker0 — so with FORWARD
# defaulting to DROP and no MASQUERADE for its subnet, every host image build
# has no network at all.
#
# It does not look like a firewall problem from inside the build: apt reports
# `Temporary failure resolving 'deb.debian.org'`, which reads as DNS. What it
# actually breaks is the shared PHP base image, which is built on the host and
# then silently falls back to compiling the whole extension set inside every
# account — about 146s added to every PHP deploy, with no error anywhere.
#
# csfpost.sh runs after CSF has applied its own rules, and again after `csf -r`
# and a reboot, which is the only way these survive.
install_docker_build_rules() {
    local bridge subnet post
    bridge="docker0"
    ip link show "$bridge" >/dev/null 2>&1 || return 0

    # The daemon's bip, not a guess: it is configurable in daemon.json and is
    # 172.20.0.1/16 on these hosts rather than Docker's 172.17 default.
    subnet=$(ip -4 -o addr show "$bridge" 2>/dev/null | awk '{print $4}' | head -n 1)
    if [ -z "$subnet" ]; then
        echo "csf: could not read $bridge subnet; skipping docker build rules" >&2
        return 0
    fi
    subnet=$(echo "$subnet" | sed 's#\.[0-9]*/#.0/#')

    post="/usr/local/csf/bin/csfpost.sh"
    mkdir -p "$(dirname "$post")"
    if [ ! -f "$post" ]; then
        printf '#!/bin/sh\n' >"$post"
    fi

    if grep -q "panelalpha-docker-build" "$post" 2>/dev/null; then
        echo "csf: docker build rules already present in csfpost.sh"
    else
        cat >>"$post" <<EOF

# panelalpha-docker-build: give host image builds ($bridge) egress. CSF only
# covers the compose bridge, and without these every host build is offline.
iptables -C FORWARD -i $bridge ! -o $bridge -j ACCEPT 2>/dev/null || \
  iptables -I FORWARD -i $bridge ! -o $bridge -j ACCEPT
iptables -C FORWARD -o $bridge -m conntrack --ctstate RELATED,ESTABLISHED -j ACCEPT 2>/dev/null || \
  iptables -I FORWARD -o $bridge -m conntrack --ctstate RELATED,ESTABLISHED -j ACCEPT
iptables -t nat -C POSTROUTING -s $subnet ! -o $bridge -j MASQUERADE 2>/dev/null || \
  iptables -t nat -A POSTROUTING -s $subnet ! -o $bridge -j MASQUERADE
EOF
        echo "csf: added docker build rules for $bridge ($subnet) to csfpost.sh"
    fi
    chmod 700 "$post"

    # Apply now as well, so an install does not have to wait for a restart.
    sh "$post" || true
}

uninstall_csf() {
    /bin/bash /usr/src/csf/uninstall.sh
}

random-string() {
    cat /dev/urandom | tr -dc 'a-zA-Z0-9' | fold -w ${1:-32} | head -n 1
}

#####
case "$1" in
--install)
    install_csf
    ;;
--uninstall)
    uninstall_csf
    ;;
*)
    echo "Usage: $0 [--install|--uninstall]"
    exit 1
    ;;
esac
