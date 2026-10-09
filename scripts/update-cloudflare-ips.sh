#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")"

OUT_NGINX="../webserver-config/nginx/cloudflare-realip.conf"
OUT_NGINX_PROXY="../webserver-config/nginx-proxy/cloudflare-realip.conf"
OUT_APACHE="../webserver-config/apache/cloudflare-realip.conf"
OUT_OPENLITESPEED="../webserver-config/openlitespeed/cloudflare-realip.conf"
OUT_LITESPEED="../webserver-config/litespeed/cloudflare-realip.xml"

TMP_IPS="$(mktemp)"
TMP_OUT="$(mktemp)"

cleanup() {
    rm -f "$TMP_IPS" "$TMP_OUT"
}
trap cleanup EXIT

{
    curl --proto '=https' -fsSL https://www.cloudflare.com/ips-v4
    echo
    curl --proto '=https' -fsSL https://www.cloudflare.com/ips-v6
} > "$TMP_IPS"

{
    echo "# Cloudflare IP ranges - auto-generated"
    echo

    while read -r ip; do
        echo "set_real_ip_from $ip;"
    done < "$TMP_IPS"

    echo
    echo "real_ip_header CF-Connecting-IP;"
} > "$TMP_OUT"

for target in "$OUT_NGINX" "$OUT_NGINX_PROXY"; do
    if ! cmp -s "$TMP_OUT" "$target"; then
        cp -f "$TMP_OUT" "$target"
    fi
done

{
    echo "# Cloudflare IP ranges - auto-generated"
    echo

    while read -r ip; do
        echo "RemoteIPTrustedProxy $ip"
    done < "$TMP_IPS"

    echo
    echo "RemoteIPHeader CF-Connecting-IP"
} > "$TMP_OUT"

if ! cmp -s "$TMP_OUT" "$OUT_APACHE"; then
    cp -f "$TMP_OUT" "$OUT_APACHE"
fi

{
    echo "# Cloudflare IP ranges - auto-generated"
    echo "# panelalpha-cloudflare-realip-begin"
    echo "server {"
    echo "  useIpInProxy 2"
    echo "}"
    echo "accessControl {"
    echo -n "  allow ALL"
    while read -r ip; do
        echo -n ", ${ip}T"
    done < "$TMP_IPS"
    echo
    echo "}"
    echo "# panelalpha-cloudflare-realip-end"
} > "$TMP_OUT"

mkdir -p "$(dirname "$OUT_OPENLITESPEED")"
if ! cmp -s "$TMP_OUT" "$OUT_OPENLITESPEED" 2>/dev/null; then
    cp -f "$TMP_OUT" "$OUT_OPENLITESPEED"
fi

{
    echo "<!-- panelalpha-cloudflare-realip-begin -->"
    echo "<useIpInProxy>2</useIpInProxy>"
    echo "<accessControl>"
    echo "  <allow>ALL</allow>"
    while read -r ip; do
        echo "  <allow>${ip}T</allow>"
    done < "$TMP_IPS"
    echo "</accessControl>"
    echo "<!-- panelalpha-cloudflare-realip-end -->"
} > "$TMP_OUT"

mkdir -p "$(dirname "$OUT_LITESPEED")"
if ! cmp -s "$TMP_OUT" "$OUT_LITESPEED" 2>/dev/null; then
    cp -f "$TMP_OUT" "$OUT_LITESPEED"
fi
