#!/bin/bash
# Writes which address the front proxy trusts for X-Forwarded-For: the
# account's default gateway, where the engine's proxy connects from.
set -e
cd ~/project

gw=$(awk '$1 != "Iface" && $2 == "00000000" { print $3; exit }' /proc/net/route)
if [[ "$gw" =~ ^[0-9A-Fa-f]{8}$ ]]; then
    # /proc/net/route stores it little-endian.
    ip=$(printf '%d.%d.%d.%d' "0x${gw:6:2}" "0x${gw:4:2}" "0x${gw:2:2}" "0x${gw:0:2}")
    printf 'set_real_ip_from %s;\n' "$ip" > plex-trusted-proxy.conf
    echo "plex: front proxy trusts X-Forwarded-For from ${ip}" >&2
else
    # No trusted hop: Plex sees the connecting address, never a forwarded one.
    echo '# no trusted proxy' > plex-trusted-proxy.conf
fi

touch .env
chmod +r plex-front.conf plex-trusted-proxy.conf plex-claim-check.sh
