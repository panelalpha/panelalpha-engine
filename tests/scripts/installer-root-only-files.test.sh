#!/bin/bash
#
# installer.sh's copy turns a 775/664 source-deploy tree into one every root broker check accepts.
#
#   bash tests/scripts/installer-root-only-files.test.sh

set -uo pipefail

ROOT="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/../.." && pwd)"
SCRIPT_DIR="$ROOT/scripts"
W="$(mktemp -d)"
trap 'rm -rf "$W"' EXIT

for fn in replace_running_updaters unzip_panelalpha_engine hand_engine_files_to_root hand_account_compose_files; do
    sed -n "/^${fn}() {/,/^}\$/p" "$SCRIPT_DIR/installer.sh" >>"$W/funcs.sh"
done
# shellcheck source=/dev/null
source "$W/funcs.sh"

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

remove_dropped_engine_files() { :; }
echo_warning() { echo "warning:$1" >>"$W/warnings"; }

# Not run as root: each path chown is asked about, one per line.
mkdir -p "$W/bin"
cat >"$W/bin/chown" <<EOF
#!/bin/bash
[ "\$1" = root:root ] || exit 1
shift
printf '%s\n' "\$@" >>"$W/chowned"
EOF
chmod +x "$W/bin/chown"
PATH="$W/bin:$PATH"
touch "$W/chowned"

INSTALL_DIR="$W/install"
PANELALPHA_DIR="$W/opt"
ENGINE="$PANELALPHA_DIR/shared-hosting"
SCRIPTS=(scripts/tenant-network-firewall.sh scripts/build-network-firewall.sh scripts/prune-project-caches.sh
    scripts/letsencrypt-request-cert.sh scripts/updater.sh scripts/webserver.sh scripts/firewall.sh
    scripts/firewall/ufw.sh updater.sh webserver.sh installer.sh)
COMPOSE=(docker-compose.yml docker-compose.yml-nginx-proxy docker-compose.yml-apache)
BROKER_CODE=(dockerfiles/root-broker/pa-root-broker dockerfiles/root-broker/rootbroker/server.py dockerfiles/entrypoint-root-broker.sh)

# The release, as a clone by root leaves it.
(
    umask 022
    mkdir -p "$INSTALL_DIR/src/scripts/firewall" "$INSTALL_DIR/src/users" "$INSTALL_DIR/src/dockerfiles/root-broker/rootbroker"
    for f in "${SCRIPTS[@]}" "${BROKER_CODE[@]}"; do printf '#!/bin/sh\n' >"$INSTALL_DIR/src/$f" && chmod 0755 "$INSTALL_DIR/src/$f"; done
    for f in "${COMPOSE[@]}"; do echo 'services: {}' >"$INSTALL_DIR/src/$f"; done
    touch "$INSTALL_DIR/src/users/.gitkeep"
)
# The host: the same paths at 775 and 664, and its own state beside them.
(
    umask 002
    mkdir -p "$ENGINE/scripts/firewall" "$ENGINE/users/alice" "$ENGINE/dockerfiles/root-broker/rootbroker"
    for f in "${SCRIPTS[@]}" "${BROKER_CODE[@]}"; do printf '#!/bin/sh\n# old\n' >"$ENGINE/$f" && chmod 0775 "$ENGINE/$f"; done
    for f in "${COMPOSE[@]}" docker-compose.override.yml users/alice/docker-compose.yml; do
        echo 'services: {}' >"$ENGINE/$f" && chmod 0664 "$ENGINE/$f"
    done
    printf 'FIREWALL_PROVIDER=ufw\n' >"$ENGINE/.env" && chmod 0664 "$ENGINE/.env"
    printf 'DIND_RUNTIME=privileged\n' >"$ENGINE/.env-core" && chmod 0640 "$ENGINE/.env-core"
    echo 'host' >"$ENGINE/scripts/monit.conf" && chmod 0666 "$ENGINE/scripts/monit.conf"
    ln -s "$ENGINE/docker-compose.yml-nginx-proxy" "$ENGINE/docker-compose.yml-webserver"
)
chmod 0775 "$PANELALPHA_DIR" "$ENGINE" "$ENGINE/scripts" "$ENGINE/scripts/firewall" "$ENGINE/users" "$ENGINE/users/alice" \
    "$ENGINE/dockerfiles" "$ENGINE/dockerfiles/root-broker" "$ENGINE/dockerfiles/root-broker/rootbroker"
echo 'outside' >"$W/outside" && chmod 0666 "$W/outside"
ln -s "$W/outside" "$ENGINE/scripts/link"

# Every check of the broker's rule, by its own code, with this user standing in for root and the engine.
strict_checks() {
    PYTHONPATH="$ROOT/dockerfiles/root-broker" python3 - "$ENGINE" <<'PY'
import os
import sys

from rootbroker import composefiles, composepolicy, ownership, paths
from rootbroker.actions import Policy, firewall, host
from rootbroker.refused import Refused

engine = sys.argv[1]
me = {os.getuid()}
policy = Policy({'engine': engine}, owners=me, script_owners=me, engine_owners=me, host_root='')


def run(name, check):
    try:
        check()
        print(f'ok {name}')
    except Refused as e:
        print(f'refused {name}: {e}')


def scripts():
    for script in (host.TENANT_NETWORK_FIREWALL, host.BUILD_NETWORK_FIREWALL, host.PRUNE_PROJECT_CACHES,
                   host.REQUEST_ENGINE_CERTIFICATE, *host.UPDATER, *host.WEBSERVER):
        host.engine_script(policy, script)


def compose_file(*relative):
    directory = composefiles.walk(engine, list(relative), policy.owners)
    path = '/'.join(relative)
    try:
        os.close(composefiles.owned_file(composefiles.open_file(directory, path), path, policy.owners))
    finally:
        os.close(directory)


def engine_start():
    directory = os.open(engine, paths.DIRECTORY)
    try:
        composefiles.check_engine_start(directory, engine, policy)
    finally:
        os.close(directory)


def privileged_runtime():
    directory = os.open(engine, paths.DIRECTORY)
    try:
        if not composepolicy.privileged_runtime(directory, policy.engine_owners):
            raise Refused('.env-core does not set the privileged runtime as root wrote it')
    finally:
        os.close(directory)


def root_owned():
    above = os.path.dirname(engine)
    for path in (above, engine, engine + '/scripts', engine + '/scripts/tenant-network-firewall.sh',
                 engine + '/scripts/firewall.sh', engine + '/scripts/firewall', engine + '/scripts/firewall/ufw.sh'):
        if os.path.islink(path) or not ownership.written_only_by(os.stat(path), me):
            raise Refused(f"pa-root-owned: '{path}' could be written by someone other than root")


run('engine scripts', scripts)
run('firewall scripts', lambda: firewall.firewall_script(policy))
run('account compose file', lambda: compose_file('users', 'alice'))
run('engine compose file', lambda: compose_file())
run('engine compose start', engine_start)
run('privileged runtime', privileged_runtime)
run('pa-root-owned', root_owned)
PY
}

before=$(strict_checks)
for name in 'engine scripts' 'firewall scripts' 'account compose file' 'engine compose file' 'engine compose start' \
    'pa-root-owned'; do
    check "before the copy: $name is refused" "refused $name" "$(grep -F -- " $name" <<<"$before" | head -n 1 | sed 's/:.*//')"
done
check "before the copy: .env-core is root:www-data 0640, as secure-env-core.sh leaves it" "ok privileged runtime" \
    "$(grep -F -- ' privileged runtime' <<<"$before")"

unzip_panelalpha_engine
after=$(strict_checks)
for name in 'engine scripts' 'firewall scripts' 'account compose file' 'engine compose file' 'engine compose start' \
    'privileged runtime' 'pa-root-owned'; do
    check "after the copy: $name" "ok $name" "$(grep -F -- " $name" <<<"$after" | head -n 1 | sed 's/:.*//')"
done
check "nothing is warned about" "no" "$([ -e "$W/warnings" ] && echo yes || echo no)"

for f in "" scripts scripts/firewall "${SCRIPTS[@]}" scripts/monit.conf "${COMPOSE[@]}" docker-compose.override.yml .env; do
    check "${f:-the engine directory} is handed to root" "1" "$(grep -cxF -- "$ENGINE${f:+/$f}" "$W/chowned")"
done
check "the directory above it is handed to root" "1" "$(grep -cxF -- "$PANELALPHA_DIR" "$W/chowned")"
for f in dockerfiles dockerfiles/root-broker dockerfiles/root-broker/rootbroker "${BROKER_CODE[@]}"; do
    check "$f, which the broker and core run mounted, is handed to root" "1|0" \
        "$(grep -cxF -- "$ENGINE/$f" "$W/chowned")|$(($(stat -c '0%a' "$ENGINE/$f") & 022))"
done
for f in users users/alice users/alice/docker-compose.yml; do
    check "$f keeps its owner, the engine or root" "0" "$(grep -cxF -- "$ENGINE/$f" "$W/chowned")"
    check "$f loses group and other write" "0" "$(($(stat -c '0%a' "$ENGINE/$f") & 022))"
done
check ".env-core is left to secure-env-core.sh" "640 0" "$(stat -c %a "$ENGINE/.env-core") $(grep -c 'env-core' "$W/chowned")"
check "a link is not followed" "0" "$(grep -c -- 'link\|outside\|webserver$' "$W/chowned")"
check "what a link points at keeps its mode" "666" "$(stat -c %a "$W/outside")"

# The new broker starts only in start_engine_stack, after the copy and secure-env-core.sh.
main=$(sed -n '/^resolve_engine_version$/,$p' "$SCRIPT_DIR/installer.sh")
install=$(sed -n '/^install_panelalpha_engine() {/,/^}$/p' "$SCRIPT_DIR/installer.sh")
start=$(sed -n '/^start_engine_stack() {/,/^}$/p' "$SCRIPT_DIR/installer.sh")
check "the update starts containers in one place" "2 2" \
    "$(grep -c 'docker compose .* up -d' "$SCRIPT_DIR/installer.sh") $(grep -c 'docker compose .* up -d' <<<"$start")"
check "and that place runs from install_panelalpha_engine alone" "1 1" \
    "$(grep -cx ' *start_engine_stack' "$SCRIPT_DIR/installer.sh") $(grep -cx ' *start_engine_stack' <<<"$install")"
check "which runs after the copy" "unzip_panelalpha_engine install_panelalpha_engine" \
    "$(grep -oxE ' *(unzip_panelalpha_engine|install_panelalpha_engine)' <<<"$main" | tr -d ' ' | tr '\n' ' ' | sed 's/ $//')"
check "and secures .env-core before it" "secure-env-core.sh start_engine_stack" \
    "$(grep -oE 'secure-env-core\.sh|^ *start_engine_stack$' <<<"$install" | tr -d ' ' | tr '\n' ' ' | sed 's/ $//')"

# chown fails as no one but root: the update goes on and says why it matters.
cat >"$W/bin/chown" <<'EOF'
#!/bin/bash
exit 1
EOF
unzip_panelalpha_engine
check "a failed hand-over is a warning, not the end of the update" "1" "$(grep -c 'refuses them until it is' "$W/warnings" 2>/dev/null)"

echo
echo "$PASS passed, $FAIL failed"
[[ $FAIL -eq 0 ]]
