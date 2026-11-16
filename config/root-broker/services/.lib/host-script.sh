# Sourced by the broker's entrypoint and services; s6-svscan skips dot directories.
ROOT_OWNED=/usr/local/lib/panelalpha/pa-root-owned
REPEAT_SECONDS=3600
REINSTALL="An engine update or a source deploy puts the engine's scripts back, writable by root alone."

# run_host_script's check alone, for a caller with nothing to run yet.
check_host_script() {
    local tag="$1" subject="$2" consequence="$3" failed="$4" checked="$5"
    host_script_allowed
}

host_script_allowed() { # the caller's tag, subject, consequence, failed file and checked paths
    local script=${checked%% *} why
    if [ ! -f "$script" ]; then
        host_script_failed "$script is missing" "$REINSTALL"
    elif ! why=$("$ROOT_OWNED" $checked 2>&1); then
        host_script_failed "$why" "$(host_script_refusal_fix "$why")"
    else
        return 0
    fi
    return 1
}

# check_host_script for a script another runs, and a refusal it recorded cleared once the paths pass.
recheck_host_script() {
    local tag="$1" subject="$2" consequence="$3" failed="$4" checked="$5"
    host_script_allowed && host_script_ran
}

# <tag> <subject> <consequence> <failed file> <root-only paths, script first, globbed> <host command...>
run_host_script() {
    local tag="$1" subject="$2" consequence="$3" failed="$4" checked="$5" out
    shift 5
    if ! host_script_allowed; then
        return 0
    elif ! out=$(nsenter --target 1 --all "$@" 2>&1); then
        host_script_failed "$(printf '%s\n' "$out" | grep . | tail -n 1)" "Run $* on the host for its whole output."
    else
        host_script_ran
    fi
}

host_script_refusal_fix() { # <pa-root-owned's message>
    local path
    path=$(printf '%s\n' "$1" | sed -n "s/^pa-root-owned: '\(.*\)' could be written by someone other than root\$/\1/p")
    [ -n "$path" ] || { echo "$REINSTALL"; return; }
    echo "$REINSTALL By hand, once '$path' is known to be the engine's own: chown root:root '$path' && chmod go-w '$path'."
}

host_script_failed() { # <reason> <fix>
    local line
    line="$tag: $subject was not applied ($1), so $consequence. $2"
    [ "$line" = "$(cat "$failed" 2>/dev/null)" ] && ! host_script_stale && return 0
    echo "$line" >&2
    host_script_record "$line"
}

host_script_stale() {
    [ $(($(date +%s) - $(stat -c %Y "$failed" 2>/dev/null || echo 0))) -ge "$REPEAT_SECONDS" ]
}

host_script_record() { # <line>
    local tmp
    mkdir -p "${failed%/*}" && tmp=$(mktemp "$failed.XXXXXX") || return 0
    { printf '%s\n' "$1" >"$tmp" && chmod 0644 "$tmp" && mv -f "$tmp" "$failed"; } || rm -f "$tmp"
}

host_script_ran() {
    [ -e "$failed" ] || return 0
    rm -f "$failed"
    echo "$tag: $subject applies again" >&2
}
