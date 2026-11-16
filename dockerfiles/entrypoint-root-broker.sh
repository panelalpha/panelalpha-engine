#!/bin/bash
set -e
if [ ! -x /usr/local/lib/panelalpha/pa-root-broker ]; then
    echo "root-broker: no pa-root-broker at /usr/local/lib/panelalpha; mount the engine's dockerfiles/root-broker there" >&2
    exit 1
fi

# Re-apply the DOCKER-USER hook in case Docker came up after ufw, only from scripts root alone can change; what stops
# it is logged and kept in firewall_failed (run_host_script). In the background, so the broker starts whatever happens.
firewall_script=/opt/panelalpha/shared-hosting/scripts/firewall.sh
firewall_failed=/opt/panelalpha/log/host-firewall.failed
apply_host_firewall() {
    . /etc/s6/root-broker/.lib/host-script.sh &&
        run_host_script root-broker "the host firewall's Docker hook" \
            "the ports Docker publishes may not be filtered as the firewall's rules say" "$firewall_failed" \
            "$firewall_script ${firewall_script%.sh} ${firewall_script%.sh}/*.sh" timeout 30 bash "$firewall_script" --apply
}
(set +e; apply_host_firewall) &

rm -rf /run/service
mkdir -p /run/service
cp -a /etc/s6/root-broker/. /run/service/
exec s6-svscan /run/service
