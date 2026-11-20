#!/bin/bash
# shellcheck disable=SC2034 # the extracted functions read the settings this file declares
#
# install-sysbox.sh --uninstall takes the package and everything the install
# added around it: the GOGC drop-ins, the service user, the daemon.json entries
# and the containers that need the runtime, an account with its volumes. Nothing
# else in daemon.json, in a unit's drop-in directory or on Docker is touched.
#
#   bash tests/scripts/install-sysbox.test.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/../../scripts" && pwd)"
. "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/test-network.sh"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

STUB_DIR="$TMP/stub"
mkdir -p "$STUB_DIR"
export STUB_DIR

for fn in remove_sysbox_daemon_json remove_sysbox_configuration sysbox_containers remove_sysbox_containers uninstall_sysbox; do
    sed -n "/^${fn}() {/,/^}\$/p" "$SCRIPT_DIR/install-sysbox.sh" >>"$TMP/funcs.sh"
done

SYSBOX_VERSION="0.7.1"
export DAEMON_JSON="$TMP/etc/docker/daemon.json"
export SYSTEMD_UNIT_DIR="$TMP/etc/systemd/system"
export USER_HOME_DIR="$TMP/home"
export RECOVER_SCRIPT="$TMP/usr/local/sbin/recover-sysbox-dind.sh"
export SYSBOX_USER="sysbox"
# shellcheck source=/dev/null
source "$TMP/funcs.sh"

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

log_info() { echo "info:$1"; }
log_warn() { echo "warn:$1"; }
log_error() {
    echo "error:$1"
    exit 101
}

BIN="$TMP/bin"
mkdir -p "$BIN"
cat >"$BIN/sysbox-runc" <<'EOF'
#!/bin/bash
echo "version: 0.7.1"
EOF
cat >"$BIN/docker" <<'EOF'
#!/bin/bash
printf 'docker %s\n' "$*" >>"$STUB_DIR/calls"
[ "$1" = "ps" ] && cut -d' ' -f1 "$STUB_DIR/containers"
[ "$1" = "inspect" ] && cut -d' ' -f1-3 "$STUB_DIR/containers"
exit 0
EOF
cat >"$BIN/systemctl" <<'EOF'
#!/bin/bash
printf 'systemctl %s\n' "$*" >>"$STUB_DIR/calls"
exit 0
EOF
cat >"$BIN/apt-get" <<'EOF'
#!/bin/bash
printf 'apt-get %s\n' "$*" >>"$STUB_DIR/calls"
exit 0
EOF
cat >"$BIN/id" <<'EOF'
#!/bin/bash
[ "$1" = "-u" ] && exit 0
grep -qxF -- "$1" "$STUB_DIR/users" 2>/dev/null
EOF
cat >"$BIN/getent" <<'EOF'
#!/bin/bash
grep -qxF -- "$2" "$STUB_DIR/groups" 2>/dev/null
EOF
cat >"$BIN/userdel" <<'EOF'
#!/bin/bash
printf 'userdel %s\n' "$*" >>"$STUB_DIR/calls"
exit 0
EOF
cat >"$BIN/groupdel" <<'EOF'
#!/bin/bash
printf 'groupdel %s\n' "$*" >>"$STUB_DIR/calls"
exit 0
EOF
chmod +x "$BIN"/*
PATH="$BIN:$PATH"

logged() { grep -qxF -- "$1" "$STUB_DIR/calls" && echo yes || echo no; }
there() { [ -e "$1" ] || [ -L "$1" ] && echo yes || echo no; }

install_sysbox_state() {
    mkdir -p "$SYSTEMD_UNIT_DIR/docker.service.d" "$SYSTEMD_UNIT_DIR/containerd.service.d" \
        "$SYSTEMD_UNIT_DIR/sysbox-fs.service.d" "$SYSTEMD_UNIT_DIR/sysbox-mgr.service.d" \
        "$(dirname "$RECOVER_SCRIPT")" "$(dirname "$DAEMON_JSON")" "$USER_HOME_DIR/sysbox"
    printf '[Service]\nEnvironment=GOGC=50\n' >"$SYSTEMD_UNIT_DIR/docker.service.d/panelalpha-gogc.conf"
    printf '[Service]\nEnvironment=GOGC=50\n' >"$SYSTEMD_UNIT_DIR/containerd.service.d/panelalpha-gogc.conf"
    printf '[Service]\nEnvironment=GOGC=50\n' >"$SYSTEMD_UNIT_DIR/sysbox-fs.service.d/panelalpha-gogc.conf"
    printf '[Service]\nEnvironment=GOGC=50\n' >"$SYSTEMD_UNIT_DIR/sysbox-mgr.service.d/panelalpha-gogc.conf"
    printf '[Service]\nLimitNOFILE=1048576\n' >"$SYSTEMD_UNIT_DIR/docker.service.d/panelalpha-lxcfs.conf"
    printf '#!/bin/bash\n' >"$RECOVER_SCRIPT"
    cat >"$DAEMON_JSON" <<JSON
{
  "bip": "$TN_DOCKER_BRIDGE_GATEWAY/16",
  "runtimes": {"runc": {"path": "runc"}, "sysbox-runc": {"path": "/usr/bin/sysbox-runc"}},
  "features": {"time-namespaces": false},
  "registry-mirrors": ["http://$TN_LOOPBACK:5001"]
}
JSON
    printf 'sysbox\n' >"$STUB_DIR/users"
    printf 'sysbox\n' >"$STUB_DIR/groups"
    printf 'acct sysbox-runc dind\nother sysbox-runc web\npanel runc api\n' >"$STUB_DIR/containers"
    : >"$STUB_DIR/calls"
}

install_sysbox_state
uninstall_sysbox >/dev/null 2>&1
check "the package is purged" "yes" "$(logged 'apt-get purge sysbox-ce -y')"
check "only the sysbox-runc containers are stopped" "docker stop acct other" \
    "$(grep '^docker stop' "$STUB_DIR/calls")"
check "an account loses its anonymous volumes, another sysbox container keeps them" \
    "docker rm -v acct|docker rm other" "$(grep '^docker rm' "$STUB_DIR/calls" | paste -sd'|')"
check "a container on another runtime is left alone" "no" \
    "$(grep -E '^docker (stop|rm)' "$STUB_DIR/calls" | grep -q panel && echo yes || echo no)"
check "the GOGC drop-ins go and other drop-ins stay" "no no no no yes" \
    "$(there "$SYSTEMD_UNIT_DIR/docker.service.d/panelalpha-gogc.conf") $(there "$SYSTEMD_UNIT_DIR/containerd.service.d/panelalpha-gogc.conf") $(there "$SYSTEMD_UNIT_DIR/sysbox-fs.service.d/panelalpha-gogc.conf") $(there "$SYSTEMD_UNIT_DIR/sysbox-mgr.service.d/panelalpha-gogc.conf") $(there "$SYSTEMD_UNIT_DIR/docker.service.d/panelalpha-lxcfs.conf")"
check "the emptied drop-in directories go, docker's stays" "no yes" \
    "$(there "$SYSTEMD_UNIT_DIR/containerd.service.d") $(there "$SYSTEMD_UNIT_DIR/docker.service.d")"
check "the service user, its group and its home go" "yes yes no" \
    "$(logged 'userdel sysbox') $(logged 'groupdel sysbox') $(there "$USER_HOME_DIR/sysbox")"
check "the recovery script goes" "no" "$(there "$RECOVER_SCRIPT")"

if command -v jq >/dev/null 2>&1; then
    check "daemon.json keeps the runtime and the network settings it does not own" "runc|$TN_DOCKER_BRIDGE_GATEWAY/16" \
        "$(jq -r '[(.runtimes | keys | join(",")), .bip] | join("|")' "$DAEMON_JSON")"
    check "the sysbox runtime and the time-namespaces setting are gone" "null null" \
        "$(jq -r '.runtimes["sysbox-runc"], .features["time-namespaces"]' "$DAEMON_JSON" | paste -sd' ')"
    check "the registry mirror stays: uninstall.sh removes it, not a runtime switch" "[\"http://$TN_LOOPBACK:5001\"]" \
        "$(jq -c '.["registry-mirrors"]' "$DAEMON_JSON")"
else
    echo "  skip  daemon.json checks: jq is not installed"
fi

check "docker is restarted so the runtime is gone at once" "yes" "$(logged 'systemctl restart docker')"

install_sysbox_state
printf 'panel runc api\n' >"$STUB_DIR/containers"
uninstall_sysbox >/dev/null 2>&1
check "with no sysbox-runc container nothing is stopped or removed" "0" \
    "$(grep -cE '^docker (stop|rm)' "$STUB_DIR/calls")"

install_sysbox_state
: >"$STUB_DIR/calls"
# A host that has the real binary outside the stub PATH.
command() { [ "$1" = "-v" ] && [ "$2" = "sysbox-runc" ] && return 1; builtin command "$@"; }
uninstall_sysbox >/dev/null 2>&1
unset -f command
check "without the binary nothing is purged" "no" "$(logged 'apt-get purge sysbox-ce -y')"
check "and no container is stopped" "0" "$(grep -c '^docker ' "$STUB_DIR/calls")"
check "but what is left of the install is still cleaned" "yes no" \
    "$(logged 'userdel sysbox') $(there "$SYSTEMD_UNIT_DIR/docker.service.d/panelalpha-gogc.conf")"

install_sysbox_state
touch "$BIN/sysbox-runc"
hash -r
if command -v jq >/dev/null 2>&1; then
    jq 'del(.runtimes["sysbox-runc"], .features, .["registry-mirrors"])' "$DAEMON_JSON" >"$TMP/other.json"
    cp "$TMP/other.json" "$DAEMON_JSON"
    cp "$DAEMON_JSON" "$TMP/daemon.before"
    : >"$STUB_DIR/calls"
    uninstall_sysbox >/dev/null 2>&1
    check "a daemon.json with none of the entries is not rewritten" "yes" \
        "$(cmp -s "$DAEMON_JSON" "$TMP/daemon.before" && echo yes || echo no)"
fi

echo
echo "$PASS passed, $FAIL failed"
[[ $FAIL -eq 0 ]]
