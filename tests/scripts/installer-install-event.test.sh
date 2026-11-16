#!/bin/bash
#
# installer.sh's install event: `software` single-server for the panel, `deploy_result` for --repo.
#
#   bash tests/scripts/installer-install-event.test.sh
#
# shellcheck disable=SC2034 # the sourced send_update_status reads the DEPLOY_* globals set here

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/../../scripts" && pwd)"
W="$(mktemp -d)"
trap 'rm -rf "$W"' EXIT

for fn in normalize_ref redact_repo_url installer_error_message install_event_deploy_result send_update_status; do
    sed -n "/^${fn}() {/,/^}\$/p" "$SCRIPT_DIR/installer.sh" >>"$W/funcs.sh"
done
# shellcheck source=/dev/null
source "$W/funcs.sh"

# curl keeps the event it was sent.
mkdir -p "$W/bin"
cat >"$W/bin/curl" <<STUB
#!/bin/bash
cat >"$W/event.json"
STUB
chmod +x "$W/bin/curl"
PATH="$W/bin:$PATH"

# The installer's own globals.
MONITORING_HOST=monitoring.example.test
STARTED_AT=0
INSTALL_EMAIL=''
REF=''
APP_UID=''

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

# The event a run sends, as "<software> <deploy_result or -> <type>".
sent() {
    rm -f "$W/event.json"
    send_update_status "${1:-0}"
    jq -r '.events[0] | "\(.payload.software) \(.payload.deploy_result // "-") \(.type)"' "$W/event.json"
}

reset() {
    DEPLOY_REPO='' DEPLOY_FULL_PRIVILEGES=0 DEPLOY_FAILED=0 DEPLOY_UPDATED=0 DEPLOY_PROJECT_NAME='' ENGINE_OP=install
}

reset
check "an engine alone" "engine - engine.install" "$(sent)"

reset
DEPLOY_REPO='https://github.com/n8n-io/n8n' DEPLOY_PROJECT_NAME='n8n3163'
check "an engine with a project" "engine deployed engine.install" "$(sent)"

reset
DEPLOY_REPO='https://x-access-token:secret@github.com/panelalpha/panelalpha-single-server.git'
DEPLOY_FULL_PRIVILEGES=1 DEPLOY_PROJECT_NAME='panelalphasingl'
check "the panel" "single-server deployed engine.install" "$(sent)"
check "its repository without the token" 'https://***@github.com/panelalpha/panelalpha-single-server.git' \
    "$(jq -r '.events[0].payload.repo' "$W/event.json")"

DEPLOY_UPDATED=1 ENGINE_OP=update
check "the panel run again" "single-server updated engine.update" "$(sent)"

reset
DEPLOY_REPO='https://github.com/panelalpha/panelalpha-single-server' DEPLOY_FULL_PRIVILEGES=1 DEPLOY_FAILED=1
check "a panel that failed to deploy, on an engine that installed" "single-server failed engine.install" "$(sent 0)"

reset
DEPLOY_REPO='https://github.com/panelalpha/panelalpha-single-server' DEPLOY_FULL_PRIVILEGES=1
check "an engine install that stopped before the panel" "single-server not_run engine.install" "$(sent 101)"

reset
DEPLOY_FULL_PRIVILEGES=1
check "--full-privileges without --repo is no panel" "engine - engine.install" "$(sent)"

echo
echo "$PASS passed, $FAIL failed"
[[ $FAIL -eq 0 ]]
