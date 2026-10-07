#!/bin/bash
#
# installer.sh's update against a git history: what earlier releases shipped
# and this one does not leaves the engine dir, while host state, files nobody
# tracked and anything outside the dir stay byte for byte.
#
#   bash scripts/installer-dropped-files.test.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")" && pwd)"
W="$(mktemp -d)"
trap 'rm -rf "$W"' EXIT

# installer.sh installs an engine when sourced, so cut out what the update's
# download and unzip steps run.
sed -n '/^ENGINE_HOST_STATE=(/,/^)$/p' "$SCRIPT_DIR/installer.sh" >"$W/funcs.sh"
for fn in download_engine_from_repository engine_host_state list_dropped_engine_files \
    remove_dropped_engine_files unzip_panelalpha_engine; do
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
there() { [ -e "$1" ] || [ -L "$1" ] && echo yes || echo no; }

echo_info() { echo "info:$1"; }
echo_warning() { echo "warn:$1"; }
echo_error() {
    echo "error:$1"
    exit 101
}
redact_repo_url() { printf '%s' "$1"; }

# The engine's repository: release 1, a side branch, release 2 dropping files,
# a merge dropping the side branch's file, release 3 bringing one back.
REPO="$W/repo"
g() {
    git -C "$REPO" -c user.name=t -c user.email=t@example.com -c commit.gpgsign=false "$@" >/dev/null 2>&1 || {
        echo "setup failed: git $*"
        exit 1
    }
}
put() { mkdir -p "$(dirname "$REPO/$1")" && printf '%s\n' "${2:-$1}" >"$REPO/$1"; }
NL=$'\n'
mkdir -p "$W/outside" "$W/outside2"
echo 'not the engine' >"$W/outside/target.txt"
echo 'not the engine either' >"$W/outside/x.md"
# The engine's own content, outside the engine dir: only the link check keeps it.
echo f >"$W/outside2/f.txt"
git init -q -b main "$REPO"
# Served as GitHub serves it: the update's clone gets no old file contents.
git -C "$REPO" config uploadpack.allowFilter true
for f in core/artisan core/public/index.php core/app/Console/Kernel.php core/app/Console/Commands/Keep.php \
    core/app/Console/Commands/Api/Call.php core/app/Console/Commands/Concerns/DispatchesApiRoute.php \
    core/config/horizon.php scripts/old.sh scripts/became-dir scripts/became-file/run.sh docs/escape/x.md; do
    put "$f"
done
put core/public/report/.htaccess 'Options +Indexes'
# Dropped later; on the host an operator's file, a changed copy, an older copy.
put scripts/operator.py 'engine copy'
put core/app/Modified.php 'engine copy'
put core/app/Versioned.php 'version 1'
put docker-compose.override.yml 'engine copy'
# Dropped later; on the host reached through a link (into host state, out of the dir).
put tools/x/report.log
mkdir -p "$REPO/nl$NL" && echo f >"$REPO/nl$NL/f.txt"
# Tracked once in a host-state folder; the host's copy is its own.
put config/sftp/entrypoint.sh
# The repository's agent skills, a folder until a release made it a link.
put .claude/skills/deploy/SKILL.md
mkdir -p "$REPO/core/resources" && ln -s "$W/outside/target.txt" "$REPO/core/resources/link"
g add -A && g commit -m 'release 1'
g checkout -b side && put core/app/Side.php && g add -A && g commit -m side && g checkout main
g rm core/app/Console/Commands/Api/Call.php core/app/Console/Commands/Concerns/DispatchesApiRoute.php \
    core/config/horizon.php core/public/report/.htaccess scripts/old.sh scripts/became-dir \
    scripts/became-file/run.sh config/sftp/entrypoint.sh docs/escape/x.md core/resources/link
g rm -r scripts/operator.py core/app/Modified.php docker-compose.override.yml tools "nl$NL"
put core/app/Versioned.php 'version 2'
put scripts/became-dir/run.sh && put scripts/became-file && put core/app/New.php && put docs/other.md
g add -A && g commit -m 'release 2'
g merge --no-ff --no-commit side && g rm -f core/app/Side.php && g commit -m 'merge side without Side.php'
g rm -r .claude/skills && mkdir -p "$REPO/.claude" && ln -s agent-skills/engine/skills "$REPO/.claude/skills"
put scripts/old.sh 'back in release 3' && g rm core/app/Versioned.php && g add -A && g commit -m 'release 3'
TIP=$(git -C "$REPO" rev-parse HEAD)

# A host installed from the side branch's tree, with host state and files of its own.
PANELALPHA_DIR="$W/opt/panelalpha"
INSTALL_DIR="$PANELALPHA_DIR/tmp/engine"
ENGINE_REPO="file://$REPO"
PANELALPHA_ENGINE_VERSION=main
ROOT="$PANELALPHA_DIR/shared-hosting"
mkdir -p "$ROOT" "$PANELALPHA_DIR/other"
git -C "$REPO" archive side | tar -x -C "$ROOT"
HOST_FILES=(.env .env-core .claude/settings.local.json crt/server.key core/.env core/vendor/autoload.php core/storage/logs/laravel.log
    core/bootstrap/cache/config.php users/acct/home.txt logs/update.log webserver-config/nginx/site.conf
    config/sftp/ssh_host_ed25519_key config/sftp/entrypoint.sh tests/api/env/.env data/ufw.lock
    scripts/monit.conf docker-compose.yml-webserver core/app/Custom/Mine.php notes.txt
    docker-compose.override.yml scripts/operator.py core/app/Modified.php core/storage/logs/report.log)
for f in "${HOST_FILES[@]}"; do
    case "$f" in config/sftp/entrypoint.sh | core/app/Modified.php | core/storage/logs/report.log) continue ;; esac
    mkdir -p "$(dirname "$ROOT/$f")" && echo "host's own $f $RANDOM" >"$ROOT/$f"
done
echo 0000000 >"$ROOT/version"
echo 'beside the engine' >"$PANELALPHA_DIR/other/keep.txt"
# A folder the history tracked, now a link to somewhere else on the host.
rm -rf "$ROOT/docs/escape" && ln -s "$W/outside" "$ROOT/docs/escape"
# The engine's copy, changed by the operator.
echo 'changed by the operator' >>"$ROOT/core/app/Modified.php"
# A folder the history tracked, now a link into host state that holds a file
# with the engine copy's very content.
rm -rf "$ROOT/tools/x" && mkdir -p "$ROOT/core/storage/logs" && ln -s ../core/storage/logs "$ROOT/tools/x"
printf 'tools/x/report.log\n' >"$ROOT/core/storage/logs/report.log"
# A folder name ending in a newline, a link out of the dir; a real "nl" beside it.
rm -rf "$ROOT/nl$NL" && ln -s "$W/outside2" "$ROOT/nl$NL" && mkdir -p "$ROOT/nl"
sums() {
    (cd "$ROOT" && sha256sum "${HOST_FILES[@]}")
    (cd "$W/outside" && sha256sum target.txt x.md)
    (cd "$W/outside2" && sha256sum f.txt)
    sha256sum "$PANELALPHA_DIR/other/keep.txt"
}
before=$(sums)

out=$(download_engine_from_repository && unzip_panelalpha_engine)
check "the update ran" "0" "$?"
check "Call.php is gone" "no" "$(there "$ROOT/core/app/Console/Commands/Api/Call.php")"
check "and the folder it left empty" "no" "$(there "$ROOT/core/app/Console/Commands/Api")"
check "DispatchesApiRoute.php and its folder are gone" "no|no" \
    "$(there "$ROOT/core/app/Console/Commands/Concerns/DispatchesApiRoute.php")|$(there "$ROOT/core/app/Console/Commands/Concerns")"
check "a dropped config file is gone" "no" "$(there "$ROOT/core/config/horizon.php")"
check "a dropped .htaccess and its folder are gone, public/ stays" "no|no|yes" \
    "$(there "$ROOT/core/public/report/.htaccess")|$(there "$ROOT/core/public/report")|$(there "$ROOT/core/public/index.php")"
check "a file only a merged branch had is gone" "no" "$(there "$ROOT/core/app/Side.php")"
check "a dropped link is gone, not what it pointed at" "no|yes" "$(there "$ROOT/core/resources/link")|$(there "$W/outside/target.txt")"
check "a file that became a folder" "yes" "$([ -f "$ROOT/scripts/became-dir/run.sh" ] && echo yes || echo no)"
check "a folder that became a file" "yes" "$([ -f "$ROOT/scripts/became-file" ] && echo yes || echo no)"
check "an older copy a release shipped is gone too" "no" "$(there "$ROOT/core/app/Versioned.php")"
check "an operator's file at a dropped path stays, and is said" "yes|1" \
    "$(there "$ROOT/scripts/operator.py")|$(grep -cxF 'Kept scripts/operator.py: not a copy any release shipped' <<<"$out")"
check "a changed engine copy stays, and is said" "yes|1" \
    "$(there "$ROOT/core/app/Modified.php")|$(grep -cxF 'Kept core/app/Modified.php: not a copy any release shipped' <<<"$out")"
check "an operator's Compose override stays" "yes" "$(there "$ROOT/docker-compose.override.yml")"
check "nothing is removed through a link into host state" "yes|1" \
    "$(there "$ROOT/core/storage/logs/report.log")|$(grep -cxF 'Kept tools/x/report.log: its folder is reached through a link' <<<"$out")"
check "a folder name ending in a newline does not hide a link" "yes|yes" "$(there "$W/outside2/f.txt")|$(there "$ROOT/nl")"
check "a file dropped and brought back is the new one" "back in release 3" "$(cat "$ROOT/scripts/old.sh")"
check "files still shipped stay" "yes|yes|yes" \
    "$(there "$ROOT/core/app/Console/Commands/Keep.php")|$(there "$ROOT/core/app/New.php")|$(there "$ROOT/docs/other.md")"
check "a tracked file in a host-state folder stays" "yes" "$(there "$ROOT/config/sftp/entrypoint.sh")"
check "nothing is removed through a link out of the engine dir" "$W/outside|yes" \
    "$(readlink "$ROOT/docs/escape")|$(there "$W/outside/x.md")"
check "host state, untracked files and everything outside: byte for byte" "$before" "$(sums)"
check "version names the new release" "$TIP" "$(cat "$ROOT/version")"
check "each removal is listed" "9" "$(grep -c '^Removed ' <<<"$out")"
check "and counted" "1" "$(grep -cxF 'info:Removed 9 file(s) that earlier releases shipped and this one does not' <<<"$out")"
check "so is what stays" "1" "$(grep -cxF 'info:Kept 5 file(s) at paths earlier releases used, for the reasons above' <<<"$out")"
check "the clone's .git is not copied" "no" "$(there "$ROOT/.git")"
check "nor its .claude: the skills folder an older release left is not replaced by a link" "yes|no" \
    "$([ -d "$ROOT/.claude/skills" ] && echo yes || echo no)|$([ -L "$ROOT/.claude/skills" ] && echo yes || echo no)"

out=$(download_engine_from_repository && unzip_panelalpha_engine)
check "a second update removes nothing" "0|0" "$?|$(grep -c 'Removed' <<<"$out")"
check "host state still byte for byte" "$before" "$(sums)"

# The history is read from commits and trees alone: no old file contents, and
# nothing fetched while reading it.
git clone -q --filter=blob:none --no-checkout "file://$REPO" "$W/blobless" 2>/dev/null
git -C "$W/blobless" remote set-url origin "$W/gone"
out=$(list_dropped_engine_files "$W/blobless" "$W/list")
# Records are "<mode> <blob id> <path>"; the "nl<newline>/f.txt" one spans two lines here.
check "a blobless clone lists every dropped path" ".claude/skills/deploy/SKILL.md
config/sftp/entrypoint.sh
core/app/Console/Commands/Api/Call.php
core/app/Console/Commands/Concerns/DispatchesApiRoute.php
core/app/Modified.php
core/app/Side.php
core/app/Versioned.php
core/config/horizon.php
core/public/report/.htaccess
core/resources/link
docker-compose.override.yml
docs/escape/x.md
scripts/became-dir
scripts/became-file/run.sh
scripts/operator.py
tools/x/report.log" "$(tr '\0' '\n' <"$W/list" | cut -d' ' -f3- | grep -vx -e nl -e /f.txt | LC_ALL=C sort -u)"
check "with every version each one had, as its blob id" "2|$(git -C "$REPO" rev-parse 'HEAD~2:core/app/Versioned.php')" \
    "$(tr '\0' '\n' <"$W/list" | grep -c ' core/app/Versioned.php$')|$(tr '\0' '\n' <"$W/list" | grep -m1 ' core/app/Versioned.php$' | cut -d' ' -f2)"
check "and the mode it had" "120000" "$(tr '\0' '\n' <"$W/list" | grep ' core/resources/link$' | cut -d' ' -f1)"
check "and says nothing" "" "$out"

# Run from inside a git worktree whose .git leads nowhere (one mounted into a
# container): the hashes are the update's own, not that repository's.
mkdir -p "$W/broken" "$W/root2" && echo "gitdir: $W/nowhere/.git/worktrees/x" >"$W/broken/.git"
git -C "$REPO" archive side | tar -x -C "$W/root2"
out=$(cd "$W/broken" && remove_dropped_engine_files "$W/list" "$W/root2")
check "from inside a broken worktree: Call.php is gone all the same" "no|1" \
    "$(there "$W/root2/core/app/Console/Commands/Api/Call.php")|$(grep -cxF 'Removed core/app/Console/Commands/Api/Call.php' <<<"$out")"
# A hash that cannot be made keeps the file, and says so.
mkdir -p "$W/nogit" && printf '#!/bin/sh\nexit 1\n' >"$W/nogit/git" && chmod +x "$W/nogit/git"
git -C "$REPO" archive side | tar -x -C "$W/root2"
out=$(PATH="$W/nogit:$PATH" remove_dropped_engine_files "$W/list" "$W/root2")
check "no hash: kept, and said" "yes|1" \
    "$(there "$W/root2/core/app/Console/Commands/Api/Call.php")|$(grep -cxF 'Kept core/app/Console/Commands/Api/Call.php: could not hash it' <<<"$out")"

# A history it cannot read removes nothing and says why.
git clone -q --depth 1 "file://$REPO" "$W/shallow" 2>/dev/null
out=$(list_dropped_engine_files "$W/shallow" "$W/list")
check "a shallow clone is reported" "warn:Could not read the engine's history; files earlier releases shipped and this one does not stay in place" "$out"
check "and lists nothing" "no" "$(there "$W/list")"
check "so nothing is removed" "" "$(remove_dropped_engine_files "$W/list" "$ROOT")"
check "a first install has nothing to remove" "" "$(list_dropped_engine_files "$W/blobless" "$W/list" && remove_dropped_engine_files "$W/list" "$W/none")"

for p in .env .env-core version crt/server.key core/.env core/storage/logs/x.log config/sftp/entrypoint.sh docker-compose.override.yml \
    tests/api/env/.env.local users/a/b webserver-config; do
    check "host state: $p" "yes" "$(engine_host_state "$p" && echo yes || echo no)"
done
for p in .env.example core/.env.example config/sftpx core/storage-old/x scripts/monit.conf.example core/app/x.php \
    config/core/nginx.conf; do
    check "engine code: $p" "no" "$(engine_host_state "$p" && echo yes || echo no)"
done
missing=$(
    eval "$(sed -n '/^EXCLUDES=(/,/^)$/p' "$SCRIPT_DIR/tools/deploy-from-source.sh")"
    for e in "${EXCLUDES[@]}"; do engine_host_state "$e" || echo "$e"; done
)
check "everything deploy-from-source.sh keeps out of its upload is host state here" "" "$missing"

echo
echo "$PASS passed, $FAIL failed"
[[ $FAIL -eq 0 ]]
