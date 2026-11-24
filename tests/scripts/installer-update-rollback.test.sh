#!/bin/bash
#
# installer.sh's update against a fake engine dir: only a newer release than
# the running one is installed, a failure after the copy puts the previous
# release back byte for byte without the snapshot holding host state, an update
# killed after its copy is put back by the next one, and the run's record says
# what happened.
#
#   bash tests/scripts/installer-update-rollback.test.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/../../scripts" && pwd)"
W="$(mktemp -d)"
trap 'rm -rf "$W"' EXIT

# installer.sh installs an engine when sourced, so cut out what the update runs.
sed -n '/^ENGINE_HOST_STATE=(/,/^)$/p' "$SCRIPT_DIR/installer.sh" >"$W/funcs.sh"
sed -n '/^ENGINE_REBUILT_DIRS=/p' "$SCRIPT_DIR/installer.sh" >>"$W/funcs.sh"
for fn in die installer_error_message record_run record_run_outcome engine_host_state running_bind_sources place_mounted_engine_files remove_dropped_engine_files replace_links_shipped_as_files \
    unzip_panelalpha_engine replace_running_updaters snapshot_engine_tree restore_engine_tree mark_engine_stack_touched \
    restore_previous_release rollback_engine_update restore_interrupted_update discard_engine_snapshot \
    normalize_version version_from_system_php release_order stop_update check_update_target; do
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
there_link() { [ -L "$1" ] && echo yes || echo no; }
yesno() { "$@" && echo yes || echo no; }

red_color='' default_color=''
echo_info() { echo "info:$1"; }
echo_warning() { echo "warn:$1"; }
echo_error() {
    INSTALLER_ERROR="$1"
    echo "error:$1"
    exit 101
}
hand_engine_files_to_root() { :; }
clean_installer() { rm -rf "$INSTALL_DIR"; }
start_engine_stack() { echo started >>"$W/stack"; }

PANELALPHA_DIR="$W/pa"
LIVE="$PANELALPHA_DIR/shared-hosting"
INSTALL_DIR="$PANELALPHA_DIR/tmp/engine"
ENGINE_SNAPSHOT_DIR="$PANELALPHA_DIR/tmp/engine-previous"
put() { mkdir -p "$(dirname "$1")" && printf '%s\n' "$2" >"$1"; }

# The running release, with host state beside it, and the next release's clone.
fresh_trees() {
    rm -rf "$PANELALPHA_DIR" "$W/before" "$W/stack" "$W/run"
    mkdir -p "$W/run"
    put "$LIVE/core/app/Old.php" 'old v1'
    put "$LIVE/core/app/Same.php" 'same'
    put "$LIVE/core/config/system.php" "<?php return ['version' => '2.3.0'];"
    put "$LIVE/scripts/updater.sh" 'updater v1'
    put "$LIVE/scripts/dropped.sh" 'dropped v1'
    chmod 755 "$LIVE/scripts/dropped.sh"
    ln -s ../core/app/Old.php "$LIVE/scripts/link"
    put "$LIVE/core/vendor/pkg/a.php" 'vendor v1'
    put "$LIVE/core/vendor/autoload.php" 'autoload v1'
    put "$LIVE/core/bootstrap/cache/packages.php" 'packages v1'
    put "$LIVE/core/bootstrap/cache/.gitignore" '*'
    put "$LIVE/users/alice/docker-compose.yml" 'alice'
    put "$LIVE/logs/engine.log" 'log'
    put "$LIVE/core/storage/big.bin" 'runtime data'
    put "$LIVE/.env-core" 'APP_KEY=x'
    put "$INSTALL_DIR/src/core/app/Old.php" 'old v2'
    put "$INSTALL_DIR/src/core/app/Same.php" 'same'
    put "$INSTALL_DIR/src/core/app/New.php" 'new v2'
    put "$INSTALL_DIR/src/core/newdir/deep/file.php" 'deep v2'
    put "$INSTALL_DIR/src/core/config/system.php" "<?php return ['version' => '2.4.0'];"
    put "$INSTALL_DIR/src/scripts/updater.sh" 'updater v2'
    put "$INSTALL_DIR/src/scripts/link" 'a file now'
    put "$INSTALL_DIR/src/core/bootstrap/cache/.gitignore" '*'
    put "$INSTALL_DIR/src/.claude/skills/x.md" 'agent setup'
    printf '%s\0' "100755 $(printf 'dropped v1\n' | git hash-object --stdin) scripts/dropped.sh" >"$INSTALL_DIR/dropped-files"
    cp -a "$LIVE" "$W/before"
    ENGINE_SNAPSHOT_ARMED=0 ENGINE_ROLLBACK='' ENGINE_ROLLBACK_RESULT='' ENGINE_OP=update INSTALLER_ERROR=''
}
# What composer does after the copy.
composer_runs() {
    put "$LIVE/core/vendor/autoload.php" 'autoload v2'
    put "$LIVE/core/vendor/pkg2/b.php" 'vendor v2'
    put "$LIVE/core/bootstrap/cache/packages.php" 'packages v2'
}
same_as_before() { diff -r --no-dereference "$W/before" "$LIVE" >"$W/diff" 2>&1 && echo same || { cat "$W/diff"; echo differs; }; }

echo "a failure after the copy"
fresh_trees
unzip_panelalpha_engine >/dev/null
check "the copy is armed for a rollback" "1" "$ENGINE_SNAPSHOT_ARMED"
check "the new release is in place" "old v2" "$(cat "$LIVE/core/app/Old.php")"
check "the dropped file is gone" "no" "$(there "$LIVE/scripts/dropped.sh")"
check "a link the release ships as a file is that file, not written through" "no|a file now" \
    "$(there_link "$LIVE/scripts/link")|$(cat "$LIVE/scripts/link")"
composer_runs
listing=$(tar -tf "$ENGINE_SNAPSHOT_DIR/files.tar" | LC_ALL=C sort | tr '\n' ' ')
check "the snapshot holds only what the copy replaced or removed" \
    "core/app/Old.php core/app/Same.php core/config/system.php scripts/dropped.sh scripts/link scripts/updater.sh " "$listing"
check "and no host state" "no" "$(yesno grep -rqs 'runtime data\|alice' "$ENGINE_SNAPSHOT_DIR")"
check "the folders composer rewrites are kept whole" "vendor v1" "$(cat "$ENGINE_SNAPSHOT_DIR/dirs/core/vendor/pkg/a.php")"
rollback_engine_update 1 >/dev/null
check "the previous release is back, byte for byte" "same" "$(same_as_before)"
check "the link is a link again" "../core/app/Old.php" "$(readlink "$LIVE/scripts/link")"
check "folders the copy added are gone" "no" "$(there "$LIVE/core/newdir")"
check "the dropped file is back with its mode" "755" "$(stat -c %a "$LIVE/scripts/dropped.sh")"
check "the stack was not restarted: the new one never started" "no" "$(there "$W/stack")"
check "the record says it was rolled back" "rolled_back" "$ENGINE_ROLLBACK_RESULT"
check "the snapshot is gone" "no" "$(there "$ENGINE_SNAPSHOT_DIR")"
check "and nothing is armed" "0" "$ENGINE_SNAPSHOT_ARMED"

echo "a failure after the new stack started"
fresh_trees
unzip_panelalpha_engine >/dev/null
composer_runs
mark_engine_stack_touched
rollback_engine_update 1 >/dev/null
check "the previous release is back" "same" "$(same_as_before)"
check "and its stack is started again" "started" "$(cat "$W/stack" 2>/dev/null)"

echo "a success"
fresh_trees
unzip_panelalpha_engine >/dev/null
composer_runs
rollback_engine_update 0 >/dev/null
check "an exit 0 rolls nothing back" "old v2" "$(cat "$LIVE/core/app/Old.php")"
discard_engine_snapshot
check "a finished update leaves no snapshot" "no" "$(there "$ENGINE_SNAPSHOT_DIR")"
check "and nothing armed" "0" "$ENGINE_SNAPSHOT_ARMED"

echo "an install"
fresh_trees
ENGINE_OP=install
unzip_panelalpha_engine >/dev/null
check "an install keeps no snapshot" "no" "$(there "$ENGINE_SNAPSHOT_DIR")"
check "and arms nothing" "0" "$ENGINE_SNAPSHOT_ARMED"

echo "an update killed after its copy"
fresh_trees
unzip_panelalpha_engine >/dev/null
composer_runs
mark_engine_stack_touched
ENGINE_SNAPSHOT_ARMED=0
out=$(restore_interrupted_update)
check "the next update puts the previous release back first" "same" "$(same_as_before)"
check "and says so" "yes" "$(yesno grep -q 'An earlier update stopped after replacing' <<<"$out")"
check "and restarts its stack" "started" "$(cat "$W/stack" 2>/dev/null)"
check "and keeps no snapshot" "no" "$(there "$ENGINE_SNAPSHOT_DIR")"
rm -f "$W/stack"
restore_interrupted_update >/dev/null
check "a second run finds nothing to restore" "no" "$(there "$W/stack")"

fresh_trees
mkdir -p "$ENGINE_SNAPSHOT_DIR"
: >"$ENGINE_SNAPSHOT_DIR/added"
restore_interrupted_update >/dev/null
check "a snapshot killed before it was complete is not restored" "same" "$(same_as_before)"

echo "the version guard"
detect_installed_engine_version() { printf '%s' "$RUNNING"; }
detect_installed_engine_commit() { printf '%s' "$RUNNING_COMMIT"; }
# Prints the guard's exit code, then "continued" when the update would go on.
guard() { # <target version> [target commit, as the clone's version file holds it]
    rm -rf "$W/run" "$INSTALL_DIR"
    mkdir -p "$W/run"
    put "$INSTALL_DIR/src/core/config/system.php" "<?php return [
    'version' => '$1',
];"
    [ -z "${2:-}" ] || put "$INSTALL_DIR/src/version" "$2"
    (
        check_update_target >"$W/guard-out" 2>&1
        echo continued
    )
    echo "exit:$?"
}
field() { cat "$W/run/$1" 2>/dev/null; }
RUN_DIR="$W/run" PANELALPHA_ENGINE_VERSION=main ENGINE_OP=update RUNNING=2.3.0 ALLOW_DOWNGRADE=0 REINSTALL=0 UPDATE_ENGINE=0
RUNNING_COMMIT=aaaaaaaa SAME=aaaaaaaa11112222333344445555666677778888 OTHER=bbbbbbbb99990000aaaabbbbccccddddeeeeffff
UPDATE_NOTE=''

check "an older release is refused" "exit:101" "$(guard 2.2.0)"
check "with a reason that says why" "yes" "$(yesno grep -q 'Refusing to install 2.2.0 (main) over the running 2.3.0: it is an older release' "$W/run/reason")"
check "as refused" "refused" "$(field result)"
check "with both versions" "2.3.0 2.2.0" "$(field from_version) $(field to_version)"
check "and the clone is gone" "no" "$(there "$INSTALL_DIR")"
check "an older release is refused whatever its commit" "exit:101" "$(guard 2.2.0 "$SAME")"
check "the running commit changes nothing" "exit:0" "$(guard 2.3.0 "$SAME")"
check "and is up to date" "up_to_date" "$(field result)"
check "with a reason that says so" "yes" "$(yesno grep -q '^Already up to date: 2.3.0 (aaaaaaaa) is running and main is the same commit' "$W/run/reason")"
check "with both commits recorded" "aaaaaaaa aaaaaaaa" "$(field from_commit) $(field to_commit)"
check "a leading v is the same release" "exit:0" "$(guard v2.3.0 "$SAME")"
check "another commit of the running release goes on" "continued exit:0" "$(guard 2.3.0 "$OTHER" | tr '\n' ' ' | sed 's/ $//')"
check "and says it updates within the release" "yes" "$(yesno grep -q 'Updating within 2.3.0: aaaaaaaa → bbbbbbbb (main)' "$W/guard-out")"
check "with the version and both commits recorded" "2.3.0 aaaaaaaa bbbbbbbb" "$(field to_version) $(field from_commit) $(field to_commit)"
check "a target commit that cannot be read goes on" "continued exit:0" "$(guard 2.3.0 | tr '\n' ' ' | sed 's/ $//')"
check "an unknown running commit goes on" "continued exit:0" "$(RUNNING_COMMIT='' guard 2.3.0 "$SAME" | tr '\n' ' ' | sed 's/ $//')"
check "a newer release goes on" "continued exit:0" "$(guard 2.4.0 | tr '\n' ' ' | sed 's/ $//')"
check "with both versions recorded" "2.3.0 2.4.0" "$(field from_version) $(field to_version)"
check "and no result yet" "" "$(field result)"
check "versions compare as versions, not text" "continued exit:0" "$(RUNNING=2.9.0 guard 2.10.0 | tr '\n' ' ' | sed 's/ $//')"
check "--allow-downgrade lets an older one through" "continued exit:0" "$(ALLOW_DOWNGRADE=1 guard 2.2.0 | tr '\n' ' ' | sed 's/ $//')"
check "--reinstall lets the running commit through" "continued exit:0" "$(REINSTALL=1 guard 2.3.0 "$SAME" | tr '\n' ' ' | sed 's/ $//')"
check "--reinstall does not let an older one through" "exit:101" "$(REINSTALL=1 guard 2.2.0)"
check "--update-engine with --repo reinstalls the running commit" "continued exit:0" "$(UPDATE_ENGINE=1 guard 2.3.0 "$SAME" | tr '\n' ' ' | sed 's/ $//')"
check "--update-engine does not let an older one through" "exit:101" "$(UPDATE_ENGINE=1 guard 2.2.0)"
check "an unreadable target is refused" "exit:101" "$(guard '')"
check "an unknown running version does not block a repair" "continued exit:0" "$(RUNNING='' guard 2.2.0 | tr '\n' ' ' | sed 's/ $//')"
check "an install records the version it installs" "continued exit:0" "$(ENGINE_OP=install guard 2.2.0 | tr '\n' ' ' | sed 's/ $//')"
check "as to_version" "2.2.0" "$(field to_version)"

echo "the run's record"
RUN_DIR="$W/run"
rm -rf "$W/run" && mkdir -p "$W/run"
ENGINE_OP=update ENGINE_ROLLBACK='' ENGINE_ROLLBACK_RESULT=''
record_run_outcome 0
check "a finished update is updated" "updated" "$(field result)"
check "with no reason" "" "$(field reason)"
UPDATE_NOTE='Updating within 2.3.0: aaaaaaaa → bbbbbbbb (main)'
record_run_outcome 0
check "an update within the release says so in its record" "$UPDATE_NOTE" "$(field reason)"
UPDATE_NOTE=''
rm -f "$W/run/reason"
ENGINE_OP=install
record_run_outcome 0
check "a finished install is installed" "installed" "$(field result)"
ENGINE_OP=update INSTALLER_ERROR='These engine services are not running: core(exited)'
record_run_outcome 101
check "a failure is failed" "failed" "$(field result)"
check "with the installer's reason" "These engine services are not running: core(exited)" "$(field reason)"
ENGINE_ROLLBACK='the previous release was restored' ENGINE_ROLLBACK_RESULT=rolled_back
record_run_outcome 101
check "a rolled-back failure says so" "rolled_back" "$(field result)"
check "in its reason too" "These engine services are not running: core(exited); the previous release was restored" "$(field reason)"
RUN_DIR=''
record_run result x
check "without --run-dir nothing is written" "rolled_back" "$(field result)"

echo "the exit trap"
cat >"$W/die.sh" <<EOF
source "$W/funcs.sh"
rollback_engine_update() { echo "rollback \$1" >>"$W/die-calls"; }
record_run_outcome() { echo "record \$1" >>"$W/die-calls"; }
send_update_status() { :; }
set -e
trap die EXIT
false
EOF
bash "$W/die.sh" >/dev/null 2>&1
check "a failed run rolls back, then records the outcome" "rollback 1 record 1" "$(tr '\n' ' ' <"$W/die-calls" | sed 's/ $//')"

# The installer's own signal traps, around a long step.
{
    echo 'set -e'
    echo "trap 'echo \$? >\"$W/killed\"' EXIT"
    grep -E "^trap 'exit 1[0-9]{2}' (HUP|INT|TERM)$" "$SCRIPT_DIR/installer.sh"
    echo 'sleep 2'
} >"$W/killed.sh"
bash "$W/killed.sh" &
pid=$!
sleep 0.5
kill -TERM "$pid"
wait "$pid" 2>/dev/null
check "a killed run reaches the exit trap as a failure" "143" "$(cat "$W/killed" 2>/dev/null)"

echo "a link at a host-state path"
fresh_trees
put "$W/elsewhere/alice.yml" 'alice elsewhere'
rm -f "$LIVE/users/alice/docker-compose.yml"
ln -s "$W/elsewhere/alice.yml" "$LIVE/users/alice/docker-compose.yml"
put "$INSTALL_DIR/src/users/alice/docker-compose.yml" 'shipped'
replace_links_shipped_as_files "$INSTALL_DIR/src" "$LIVE" >/dev/null
check "is left as the host has it" "yes|alice elsewhere" \
    "$(there_link "$LIVE/users/alice/docker-compose.yml")|$(cat "$W/elsewhere/alice.yml")"

echo
echo "$PASS passed, $FAIL failed"
[[ $FAIL -eq 0 ]]
