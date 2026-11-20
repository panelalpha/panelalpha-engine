#!/bin/bash
#
# installer.sh's render_webserver_config() restarts sites-http only where a reload is not enough.
#
#   bash tests/scripts/installer-webserver-render.test.sh
#
# Needs bash and coreutils; no root, no docker.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/../../scripts" && pwd)"
. "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/test-network.sh"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

for fn in webserver_config_state webserver_running render_webserver_config; do
    sed -n "/^${fn}() {/,/^}\$/p" "$SCRIPT_DIR/installer.sh" >>"$TMP/funcs.sh"
done
# shellcheck source=/dev/null
source "$TMP/funcs.sh"
ADDRESS=$(tn_host PUBLIC 7)
SECOND_ADDRESS=$(tn_host PUBLIC 8)

PASS=0
FAIL=0
check() {
    local what="$1" expected="$2" actual="$3"
    if [[ "$actual" == "$expected" ]]; then
        printf '  ok    %s\n' "$what"
        PASS=$((PASS + 1))
    else
        printf '  FAIL  %s\n        expected: %s\n        actual:   %s\n' "$what" "$expected" "$actual"
        FAIL=$((FAIL + 1))
    fi
}

echo_info() { printf 'info:%s\n' "$1"; }
echo_warning() { printf 'warning:%s\n' "$1"; }

PANELALPHA_DIR="$TMP/opt"
HOST="$PANELALPHA_DIR/shared-hosting"

# Stub state in $TMP: render (the rebuild's `sh -c` commands), running and rebuild-fails.
docker() {
    case "$*" in
    *"artisan system:domain:rebuild"*)
        echo rebuild >>"$TMP/calls"
        (cd "$HOST/webserver-config" && sh -c "$(cat "$TMP/render")")
        [ ! -f "$TMP/rebuild-fails" ]
        ;;
    *" ps --services --filter status=running"*) cat "$TMP/running" ;;
    *" restart sites-http"*) echo restart >>"$TMP/calls" ;;
    *) return 1 ;;
    esac
}

host() { # <webserver> <operation>
    rm -rf "$HOST" "$TMP/calls" "$TMP/rebuild-fails"
    mkdir -p "$HOST/webserver-config"/{nginx-proxy/vhosts,apache/vhosts,litespeed/vhosts,litespeed-admin,openlitespeed/vhosts,openlitespeed-admin}
    printf 'services:\n  sites-http:\n    labels:\n      - com.panelalpha.webserver=%s\n' "$1" >"$HOST/docker-compose.yml-webserver"
    echo "listen $ADDRESS:80;" >"$HOST/webserver-config/nginx-proxy/nginx.conf"
    echo 'server_name a.example.com;' >"$HOST/webserver-config/nginx-proxy/vhosts/a.example.com.conf"
    echo "Listen $ADDRESS:80" >"$HOST/webserver-config/apache/httpd.conf"
    echo '<VirtualHost *:80>' >"$HOST/webserver-config/apache/vhosts/a.example.com.conf"
    echo "<address>$ADDRESS:80</address>" >"$HOST/webserver-config/litespeed/httpd_config.xml"
    echo '<vhRoot>a</vhRoot>' >"$HOST/webserver-config/litespeed/vhosts/a.example.com.xml"
    echo "address $ADDRESS:80" >"$HOST/webserver-config/openlitespeed/httpd_config.conf"
    echo 'vhRoot a' >"$HOST/webserver-config/openlitespeed/vhosts/a.example.com.conf"
    echo 'core sites-http' | tr ' ' '\n' >"$TMP/running"
    : >"$TMP/render"
    : >"$TMP/calls"
    ENGINE_OP="$2"
}
renders() { printf '%s\n' "$1" >"$TMP/render"; }
calls() { tr '\n' ' ' <"$TMP/calls" | sed 's/ $//'; }

host nginx-proxy update
renders "echo \"listen $ADDRESS:80;\" >nginx-proxy/nginx.conf; echo \"server_name a.example.com;\" >nginx-proxy/vhosts/a.example.com.conf"
out=$(render_webserver_config)
check "an update whose render changes nothing leaves sites-http running" "rebuild" "$(calls)"
check "and says so" "info:The webserver configuration did not change; sites-http keeps running" "$out"

host nginx-proxy update
renders "echo \"listen $ADDRESS:80; listen $SECOND_ADDRESS:80;\" >nginx-proxy/nginx.conf"
out=$(render_webserver_config)
check "a render that moves the listeners restarts it" "rebuild restart" "$(calls)"

host nginx-proxy update
renders 'echo "server_name b.example.com;" >nginx-proxy/vhosts/b.example.com.conf'
out=$(render_webserver_config)
check "so does one that adds a vhost" "rebuild restart" "$(calls)"

host nginx-proxy update
renders 'rm nginx-proxy/vhosts/a.example.com.conf'
out=$(render_webserver_config)
check "or removes one" "rebuild restart" "$(calls)"

host nginx-proxy update
echo core >"$TMP/running"
out=$(render_webserver_config)
check "a sites-http that is not running is restarted with an unchanged config" "rebuild restart" "$(calls)"

host nginx-proxy install
out=$(render_webserver_config)
check "an install restarts it whatever the render did" "rebuild restart" "$(calls)"

host nginx-proxy update
renders 'echo "listen 80;" >nginx-proxy/nginx.conf'
touch "$TMP/rebuild-fails"
out=$(render_webserver_config)
check "a failed rebuild that changed files still restarts" "rebuild restart" "$(calls)"
check "after a warning" "warning:Could not rebuild the webserver configuration" "$(head -n1 <<<"$out")"

host nginx-proxy update
touch "$TMP/rebuild-fails"
out=$(render_webserver_config)
check "one that changed nothing leaves sites-http alone" "rebuild" "$(calls)"

host nginx-proxy update
rm "$HOST/docker-compose.yml-webserver"
out=$(render_webserver_config)
check "a webserver that cannot be told is restarted" "rebuild restart" "$(calls)"

host nginx-proxy update
renders 'echo "<address>*:80</address>" >litespeed/httpd_config.xml'
out=$(render_webserver_config)
check "only the running webserver's config counts" "rebuild" "$(calls)"

for variant in apache litespeed openlitespeed; do
    host "$variant" update
    out=$(render_webserver_config)
    check "$variant: an unchanged render leaves sites-http running" "rebuild" "$(calls)"
    host "$variant" update
    renders "echo changed >>$variant/vhosts/a.example.com.*"
    out=$(render_webserver_config)
    check "$variant: a changed vhost restarts it" "rebuild restart" "$(calls)"
done

host apache update
renders 'echo "Listen 80" >apache/httpd.conf'
out=$(render_webserver_config)
check "apache: a moved Listen restarts it" "rebuild restart" "$(calls)"

host litespeed update
renders 'echo "admin" >litespeed-admin/htpasswd'
out=$(render_webserver_config)
check "litespeed: its admin console's files do not count" "rebuild" "$(calls)"

echo
echo "$PASS passed, $FAIL failed"
[[ $FAIL -eq 0 ]]
