#!/bin/bash
# Exercises installer.sh's `--repo` half: the label it prints, the field it
# reads back out of `project:create --json`, and what it does when the deploy
# fails.
#
# installer.sh cannot be sourced -- the bottom of the file installs an engine --
# so the function block is cut out and sourced on its own. That is also why the
# block is contiguous: everything `--repo` needs sits between `repo_label` and
# `finish_installation`.
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT

sed -n '/^repo_label() {/,/^finish_installation() {/p' "${SCRIPT_DIR}/installer.sh" \
    | head -n -1 >"${WORK_DIR}/funcs.sh"
# shellcheck source=/dev/null
source "${WORK_DIR}/funcs.sh"

failures=0
pass() { echo "PASS: $1"; }
fail() {
    echo "FAIL: $1 -- expected '$2', got '$3'"
    failures=$((failures + 1))
}
expect() { # expect <label> <expected> <actual>
    if [ "$2" = "$3" ]; then pass "$1"; else fail "$1" "$2" "$3"; fi
}

# The installer's own reporters, reduced to something assertable.
echo_info() { echo "info:$1"; }
echo_warning() { echo "warn:$1"; }
outro_c() { printf 'outro:%s\n' "$2"; }
outro_nl() { :; }
outro_say() { printf 'outro:%s\n' "$2"; }

# ---- repo_label ------------------------------------------------------------

expect 'clone URL' 'n8n-io/n8n' "$(repo_label https://github.com/n8n-io/n8n)"
expect '.git suffix' 'n8n-io/n8n' "$(repo_label https://github.com/n8n-io/n8n.git)"
expect 'trailing slash' 'n8n-io/n8n' "$(repo_label https://github.com/n8n-io/n8n/)"
expect 'shorthand' 'n8n-io/n8n' "$(repo_label n8n-io/n8n)"
expect 'schemeless' 'o/r' "$(repo_label github.com/o/r)"
# Credentials in a URL go with the host they are attached to.
expect 'embedded token' 'sub/repo' "$(repo_label https://user:tok@gitlab.com/group/sub/repo.git)"

# ---- json_field ------------------------------------------------------------

JSON='{"data":{"id":3,"username":"n8n3163","domain":"n8n-3163.panelalpha.online","details":{"template":"dind","domain":{"source":"panelalpha_online"}}}}'
expect 'username' 'n8n3163' "$(json_field username "$JSON")"
expect 'domain' 'n8n-3163.panelalpha.online' "$(json_field domain "$JSON")"
expect 'absent field' '' "$(json_field nothing "$JSON")"

# The same, on a host without jq: an engine installed by an older installer
# still has to be able to read the project back.
NOJQ_BIN="${WORK_DIR}/nojq"
mkdir -p "$NOJQ_BIN"
for tool in sed head grep tail awk cat printf; do
    path=$(command -v "$tool" 2>/dev/null) && ln -sf "$path" "${NOJQ_BIN}/${tool}"
done
expect 'username without jq' 'n8n3163' "$(PATH="$NOJQ_BIN" json_field username "$JSON")"
expect 'domain without jq' 'n8n-3163.panelalpha.online' "$(PATH="$NOJQ_BIN" json_field domain "$JSON")"

# ---- deploy_repository -----------------------------------------------------

STUB_BIN="${WORK_DIR}/bin"
mkdir -p "$STUB_BIN"
cat >"${STUB_BIN}/docker" <<STUB
#!/bin/bash
if [ "\${STUB_FAIL:-0}" = 1 ]; then
    echo 'HTTP 422: git_repo: the repository could not be read' >&2
    exit 1
fi
# A deploy writes to the terminal before the command's own JSON line.
echo 'Cloning https://github.com/n8n-io/n8n'
echo '${JSON}'
STUB
chmod +x "${STUB_BIN}/docker"
export PATH="${STUB_BIN}:${PATH}"

DEPLOY_REPO='https://github.com/n8n-io/n8n'
DEPLOY_BRANCH=''
DEPLOY_GIT_TOKEN=''
INSTALL_EMAIL=''
DEPLOY_REPO_LABEL=$(repo_label "$DEPLOY_REPO")
DEPLOY_PROJECT_NAME=''
DEPLOY_PROJECT_URL=''
DEPLOY_FAILED=0

deploy_repository >/dev/null 2>&1
expect 'project name' 'n8n3163' "$DEPLOY_PROJECT_NAME"
expect 'project url' 'https://n8n-3163.panelalpha.online' "$DEPLOY_PROJECT_URL"
expect 'deploy succeeded' '0' "$DEPLOY_FAILED"

reported=$(report_deployed_project 2>&1)
case "$reported" in
*"n8n-io/n8n available at: https://n8n-3163.panelalpha.online"*) pass 'reports the URL' ;;
*) fail 'reports the URL' 'the available-at line' "$reported" ;;
esac

DEPLOY_PROJECT_NAME=''
DEPLOY_PROJECT_URL=''
DEPLOY_FAILED=0
STUB_FAIL=1 deploy_repository >/dev/null 2>&1
expect 'failed deploy is recorded' '1' "$DEPLOY_FAILED"

reported=$(report_deployed_project 2>&1)
case "$reported" in
*'pae project:create --repo n8n-io/n8n'*) pass 'failure names the retry' ;;
*) fail 'failure names the retry' 'the retry command' "$reported" ;;
esac

echo
if [ "$failures" -eq 0 ]; then
    echo "All tests passed."
    exit 0
fi
echo "${failures} test(s) failed."
exit 1
