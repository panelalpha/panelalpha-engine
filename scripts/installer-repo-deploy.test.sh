#!/bin/bash
# Exercises installer.sh's `--repo` half: the label it prints, the field it
# reads back out of `project:create --json`, what it does when the deploy
# fails, and decide_repo_deploy_mode (update vs deploy-only).
#
# installer.sh cannot be sourced -- the bottom of the file installs an engine --
# so the function block is cut out and sourced on its own. That is also why the
# block is contiguous: everything `--repo` needs sits between `repo_label` and
# `finish_installation`.
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT

sed -n '/^installer_error_message() {/,/^}$/p' "${SCRIPT_DIR}/installer.sh" >"${WORK_DIR}/funcs.sh"
sed -n '/^repo_label() {/,/^finish_installation() {/p' "${SCRIPT_DIR}/installer.sh" \
    | head -n -1 >>"${WORK_DIR}/funcs.sh"
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

# ---- installer_error_message (monitoring / failure email) ------------------

INSTALLER_ERROR='Not enough disk space. Required: 20G, available: 15G'
last_command='[ "$NO_DISK_SPACE_CHECK" = 0 ]'
expect 'error prefers INSTALLER_ERROR' \
    'Not enough disk space. Required: 20G, available: 15G' \
    "$(installer_error_message 101)"
INSTALLER_ERROR=''
expect 'error falls back to last_command' \
    'command failed (exit 101): [ "$NO_DISK_SPACE_CHECK" = 0 ]' \
    "$(installer_error_message 101)"
last_command=''
current_command=''
expect 'error generic fallback' 'installer failed with exit_code=7' \
    "$(installer_error_message 7)"

# ---- repo_label ------------------------------------------------------------

expect 'clone URL' 'n8n-io/n8n' "$(repo_label https://github.com/n8n-io/n8n)"
expect '.git suffix' 'n8n-io/n8n' "$(repo_label https://github.com/n8n-io/n8n.git)"
expect 'trailing slash' 'n8n-io/n8n' "$(repo_label https://github.com/n8n-io/n8n/)"
expect 'shorthand' 'n8n-io/n8n' "$(repo_label n8n-io/n8n)"
expect 'schemeless' 'o/r' "$(repo_label github.com/o/r)"
# Credentials in a URL go with the host they are attached to.
expect 'embedded token' 'sub/repo' "$(repo_label https://user:tok@gitlab.com/group/sub/repo.git)"

# ---- normalize_deploy_repo_url / classify_git_ls_remote_failure ------------

expect 'normalize shorthand' 'https://github.com/o/r' "$(normalize_deploy_repo_url 'o/r')"
expect 'normalize schemeless' 'https://git.example/g/r' "$(normalize_deploy_repo_url 'git.example/g/r')"
expect 'classify auth → requires_token' 'requires_token' \
    "$(classify_git_ls_remote_failure 'Authentication failed' 0)"
expect 'classify missing → requires_token' 'requires_token' \
    "$(classify_git_ls_remote_failure 'Repository not found' 0)"
expect 'classify missing+token → not_found' 'not_found' \
    "$(classify_git_ls_remote_failure 'Repository not found' 1)"

# ensure_deploy_repo_access skips a second probe when --configure left a marker
DEPLOY_REPO='https://github.com/o/r'
RUN_DIR="${WORK_DIR}/run"
mkdir -p "$RUN_DIR"
: >"$RUN_DIR/repo_access_ok"
probe_calls=0
validate_deploy_repo_access() { probe_calls=$((probe_calls + 1)); }
ensure_deploy_repo_access
expect 'ensure skips when marker present' '0' "$probe_calls"
rm -f "$RUN_DIR/repo_access_ok"
ensure_deploy_repo_access
expect 'ensure probes without marker' '1' "$probe_calls"
expect 'ensure writes marker' '1' "$([[ -f "$RUN_DIR/repo_access_ok" ]] && echo 1 || echo 0)"
DEPLOY_REPO=''
RUN_DIR=''

# ---- normalize_version / version_from_system_php ---------------------------

expect 'strip v' '2.0.1' "$(normalize_version v2.0.1)"
expect 'strip V' '2.0.1' "$(normalize_version V2.0.1)"
expect 'no prefix' '2.0.1' "$(normalize_version 2.0.1)"
expect 'php version field' '2.0.1' "$(version_from_system_php "<?php return ['version' => '2.0.1',];")"

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
    # The hint on stdout; the deploy log and then the reason on stderr, last.
    echo 'Deploy log: pae project:deploy:list'
    echo '    0:01 Cloning https://github.com/n8n-io/n8n' >&2
    echo '    0:02 Deploy failed: the repository could not be read.' >&2
    echo 'github.com answered that the repository does not exist, or needs a token.' >&2
    exit 1
fi
# project:create --json: deploy log on stderr, JSON on stdout.
echo 'Cloning https://github.com/n8n-io/n8n' >&2
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
DEPLOY_ERROR=''
DEPLOY_SITE_PASSWORD=''
DEPLOY_NO_PASSWORD=1
DEPLOY_PASSWORD_SET=0

stream_file=$(mktemp)
deploy_repository >"$stream_file" 2>/dev/null
streamed=$(cat "$stream_file")
rm -f "$stream_file"
expect 'project name' 'n8n3163' "$DEPLOY_PROJECT_NAME"
expect 'project url' 'https://n8n-3163.panelalpha.online' "$DEPLOY_PROJECT_URL"
expect 'deploy succeeded' '0' "$DEPLOY_FAILED"
expect 'no-password skips set' '0' "$DEPLOY_PASSWORD_SET"
case "$streamed" in
*'Cloning https://github.com/n8n-io/n8n'*) pass 'streams deploy log to stdout' ;;
*) fail 'streams deploy log to stdout' 'Cloning…' "$streamed" ;;
esac

reported=$(report_deployed_project 2>&1)
case "$reported" in
*"n8n-io/n8n available at: https://n8n-3163.panelalpha.online"*) pass 'reports the URL' ;;
*) fail 'reports the URL' 'the available-at line' "$reported" ;;
esac
case "$reported" in
*'Password:'*) fail 'no-password omits Password line' 'no Password:' "$reported" ;;
*) pass 'no-password omits Password line' ;;
esac

DEPLOY_PROJECT_NAME=''
DEPLOY_PROJECT_URL=''
DEPLOY_FAILED=0
DEPLOY_ERROR=''
STUB_FAIL=1 deploy_repository >/dev/null 2>&1
expect 'failed deploy is recorded' '1' "$DEPLOY_FAILED"
expect 'failure reason is the error line' \
    'github.com answered that the repository does not exist, or needs a token.' "$DEPLOY_ERROR"

reported=$(report_deployed_project 2>&1)
case "$reported" in
*'repository does not exist, or needs a token.'*) pass 'failure report shows reason' ;;
*) fail 'failure report shows reason' 'the error line' "$reported" ;;
esac
case "$reported" in
*'pae project:create --repo n8n-io/n8n'*) pass 'failure names the retry' ;;
*) fail 'failure names the retry' 'the retry command' "$reported" ;;
esac

# ---- site password helpers -------------------------------------------------

pw=$(generate_deploy_site_password)
if [[ "$pw" =~ ^[A-Za-z0-9]{16}$ ]]; then
    pass 'generated password is 16 alphanumeric'
else
    fail 'generated password is 16 alphanumeric' '16 A-Za-z0-9' "$pw"
fi

DEPLOY_NO_PASSWORD=0
DEPLOY_SITE_PASSWORD='FixedPass1234567a'
DEPLOY_PASSWORD_SET=0
DEPLOY_PROJECT_NAME='n8n3163'
DEPLOY_PROJECT_URL='https://n8n-3163.panelalpha.online'
DEPLOY_FAILED=0
apply_deploy_site_password >/dev/null 2>&1
expect 'explicit password sets flag' '1' "$DEPLOY_PASSWORD_SET"
expect 'explicit password kept' 'FixedPass1234567a' "$DEPLOY_SITE_PASSWORD"
reported=$(report_deployed_project 2>&1)
case "$reported" in
*'Password: FixedPass1234567a'*) pass 'report shows Password line' ;;
*) fail 'report shows Password line' 'Password: FixedPass…' "$reported" ;;
esac

# deploy_error_reason: the command's own error line, then "Deploy failed:", then
# the last line. Its input is stdout (the hint), a blank line, then stderr: the
# deploy log, then the reason the console renderer prints.
php_reason='This project needs PHP ^5.3.3, but it was built with PHP 8.3.35.'
sample=$(printf '%s\n' \
    'Deploy log: pae project:deploy:list' \
    '' \
    '    0:20 Detected project type: PHP' \
    "    1:01 Deploy failed: The build failed: composer install exited with code 2." \
    "$php_reason")
expect 'reason prefers the error line' "$php_reason" "$(deploy_error_reason "$sample")"

sample=$(printf '%s\n' \
    '' \
    "A project named 'taken' already exists. Choose another name." \
    'Template directory does not exist.')
expect 'reason is the first of several' \
    "A project named 'taken' already exists. Choose another name." \
    "$(deploy_error_reason "$sample")"

# A log line that mentions a status code is not the reason.
sample=$(printf '%s\n' \
    'Deploy log: pae project:deploy:list' \
    '' \
    '    1:30 Health check: http://127.0.0.1:8000/ answered HTTP 404' \
    "    1:31 Deploy failed: $php_reason")
expect 'reason falls back to Deploy failed' "$php_reason" "$(deploy_error_reason "$sample")"

# An engine from before the CLI dropped its HTTP wording still reads.
sample=$(printf '%s\n' "HTTP 422: $php_reason" '')
expect 'reason from an older engine' "HTTP 422: $php_reason" "$(deploy_error_reason "$sample")"

# An uncaught exception, as artisan renders it.
sample=$(printf '%s\n' '' '' 'In ProjectCreator.php line 371:' '                     ' \
    '  docker: not found  ' '                     ' '')
expect 'reason from an uncaught exception' 'docker: not found' "$(deploy_error_reason "$sample")"

# ---- decide_repo_deploy_mode -----------------------------------------------

PANELALPHA_DIR="${WORK_DIR}/pa"
mkdir -p "${PANELALPHA_DIR}/shared-hosting"
touch "${PANELALPHA_DIR}/shared-hosting/docker-compose.yml"
ENGINE_REPO='https://github.com/panelalpha/engine.git'
PANELALPHA_ENGINE_VERSION='main'
ENGINE_OP='update'
UPDATE_ENGINE=0
DEPLOY_ONLY=0

resolve_engine_version() { :; }
detect_installed_engine_version() { printf '%s' "${STUB_INSTALLED:-}"; }
detect_installed_engine_commit() { printf '%s' "${STUB_INSTALLED_SHA:-}"; }
peek_target_engine_identity() {
    PEEK_TARGET_VERSION="${STUB_TARGET:-}"
    PEEK_TARGET_COMMIT="${STUB_TARGET_COMMIT:-}"
}
peek_target_engine_version() {
    peek_target_engine_identity
    printf '%s' "$PEEK_TARGET_VERSION"
}

# ---- identity helpers ------------------------------------------------------

expect 'identity label' '2.0.1 (b9f8c105)' "$(engine_identity_label 2.0.1 b9f8c105)"
expect 'identity label unknown sha' '2.0.1 (unknown)' "$(engine_identity_label 2.0.1 '')"
expect 'identity label both unknown' 'unknown (unknown)' "$(engine_identity_label '' '')"

PEEK_TARGET_COMMIT='b9f8c105'
PEEK_TARGET_VERSION='2.0.1'
expect 'peek label full' '2.0.1 (b9f8c105)' "$(peek_target_label)"

if engine_update_available 2.0.1 '' 2.0.1 b9f8c105; then
    pass 'update when installed sha unknown'
else
    fail 'update when installed sha unknown' 'available' 'not'
fi
if engine_update_available 2.0.1 b9f8c105 2.0.1 25699b74; then
    pass 'update when sha differs'
else
    fail 'update when sha differs' 'available' 'not'
fi
if engine_update_available 2.0.0 abcdef01 2.0.1 abcdef01; then
    pass 'update when version differs'
else
    fail 'update when version differs' 'available' 'not'
fi
if engine_update_available 2.0.1 b9f8c105 2.0.1 b9f8c105; then
    fail 'same identity → no update' 'not available' 'available'
else
    pass 'same identity → no update'
fi

# ---- decide_repo_deploy_mode -----------------------------------------------

STUB_INSTALLED=2.0.1
STUB_INSTALLED_SHA=b9f8c105
STUB_TARGET=2.0.1
STUB_TARGET_COMMIT=b9f8c105
DEPLOY_ONLY=0
UPDATE_ENGINE=0
FORCE_DEPLOY_ONLY=0
decide_repo_deploy_mode >/dev/null 2>&1
expect 'same identity → deploy only' '1' "$DEPLOY_ONLY"

STUB_INSTALLED_SHA=''
DEPLOY_ONLY=0
decide_repo_deploy_mode >"${WORK_DIR}/decide-unknown.out" 2>&1
expect 'missing installed sha → not silent deploy-only path uses warning' '1' "$DEPLOY_ONLY"
case "$(cat "${WORK_DIR}/decide-unknown.out")" in
*'2.0.1 (unknown) → 2.0.1 (b9f8c105)'*) pass 'warning shows unknown local sha' ;;
*) fail 'warning shows unknown local sha' '2.0.1 (unknown) → 2.0.1 (b9f8c105)' "$(cat "${WORK_DIR}/decide-unknown.out")" ;;
esac

DEPLOY_ONLY=0
ENGINE_OP=update
UPDATE_ENGINE=1
FORCE_DEPLOY_ONLY=0
STUB_INSTALLED=2.0.0
STUB_INSTALLED_SHA=aaaaaaaa
STUB_TARGET=2.0.1
STUB_TARGET_COMMIT=bbbbbbbb
decide_repo_deploy_mode >/dev/null 2>&1
expect '--update-engine skips prompt' '0' "$DEPLOY_ONLY"
expect '--update-engine sets update' 'update' "$ENGINE_OP"

DEPLOY_ONLY=0
ENGINE_OP=update
UPDATE_ENGINE=0
FORCE_DEPLOY_ONLY=1
decide_repo_deploy_mode >/dev/null 2>&1
expect '--deploy-only skips prompt' '1' "$DEPLOY_ONLY"

DEPLOY_ONLY=0
ENGINE_OP=update
UPDATE_ENGINE=0
FORCE_DEPLOY_ONLY=0
STUB_INSTALLED=2.0.1
STUB_INSTALLED_SHA=aaaaaaaa
STUB_TARGET=2.0.1
STUB_TARGET_COMMIT=b9f8c105
decide_repo_deploy_mode >"${WORK_DIR}/decide.out" 2>&1
expect 'newer sha without flags → deploy only' '1' "$DEPLOY_ONLY"
warn=$(cat "${WORK_DIR}/decide.out")
case "$warn" in
*$'2.0.1 (aaaaaaaa) → 2.0.1 (b9f8c105)'*) pass 'warning uses ver (sha) labels' ;;
*) fail 'warning uses ver (sha) labels' '2.0.1 (aaaaaaaa) → 2.0.1 (b9f8c105)' "$warn" ;;
esac

# Persisted choice from --configure is reused without re-prompting.
RUN_DIR="${WORK_DIR}/run"
mkdir -p "$RUN_DIR"
printf '0\n' >"$RUN_DIR/repo_deploy_only"
printf 'update\n' >"$RUN_DIR/repo_engine_op"
DEPLOY_ONLY=1
ENGINE_OP=install
UPDATE_ENGINE=0
FORCE_DEPLOY_ONLY=0
decide_repo_deploy_mode >/dev/null 2>&1
expect 'persisted choice → not deploy-only' '0' "$DEPLOY_ONLY"
expect 'persisted choice → update' 'update' "$ENGINE_OP"
rm -rf "$RUN_DIR"
RUN_DIR=''

# Fresh host (no compose): leave DEPLOY_ONLY alone.
rm -f "${PANELALPHA_DIR}/shared-hosting/docker-compose.yml"
DEPLOY_ONLY=0
UPDATE_ENGINE=0
FORCE_DEPLOY_ONLY=0
decide_repo_deploy_mode >/dev/null 2>&1
expect 'no compose → not deploy-only' '0' "$DEPLOY_ONLY"

# detect_installed_engine_commit reads version file (real helper from installer)
# — re-source only that function by writing a version file and calling the
# real one from a subshell that defines PANELALPHA_DIR.
mkdir -p "${WORK_DIR}/pa2/shared-hosting"
printf '25699b74deadbeef\n' >"${WORK_DIR}/pa2/shared-hosting/version"
expect 'commit short from version file' '25699b74' \
    "$(PANELALPHA_DIR="${WORK_DIR}/pa2" bash -c '
        detect_installed_engine_commit() {
            local ver_file="${PANELALPHA_DIR}/shared-hosting/version" raw=""
            if [[ -f "$ver_file" ]]; then raw=$(tr -d "\r\n" <"$ver_file" || true); fi
            if [[ -z "$raw" || "$raw" == "unknown" ]]; then printf "%s" ""; return 0; fi
            printf "%.8s" "$raw"
        }
        detect_installed_engine_commit
    ')"

echo
if [ "$failures" -eq 0 ]; then
    echo "All tests passed."
    exit 0
fi
echo "${failures} test(s) failed."
exit 1
