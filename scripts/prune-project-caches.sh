#!/bin/sh
#
# Delete host build caches for projects that have not deployed recently.
#
# These caches exist only to make the *next* deploy fast: `git clone` wipes
# ~/project every deploy, so vendor/ and node_modules are rebuilt every time and
# these are what keep that off packagist and the npm registry. Nothing mounts
# them into a running application, so a project that has stopped deploying holds
# them for exactly as long as it never reads them -- ~306MB for a Laravel
# skeleton with a Vite front end.
#
# Runs in the host's mount namespace, entered by the caller: /var/cache/panelalpha
# is not a core-container volume, which is why this cannot be done from PHP.
#
# Usage: prune-project-caches.sh <max-age-seconds> [--dry-run] [--skip a,b,c]
#
#   --skip   accounts to leave alone whatever their age; the deploy pipeline
#            passes the ones with a deploy in flight, whose caches are about to
#            be read.
#
# Output is one TAB-separated record per stale cache, for the caller to report:
#
#   <path>\t<bytes>\t<age-seconds>\t<deleted|would-delete>
#
# PA_CACHE_ROOTS overrides the directories scanned. It exists so the test suite
# can run this against a temporary tree; nothing in production sets it.

set -u

MAX_AGE="${1:-}"
shift 2>/dev/null || true

DRY_RUN=0
SKIP=""

while [ "$#" -gt 0 ]; do
    case "$1" in
        --dry-run) DRY_RUN=1 ;;
        --skip) shift; SKIP="${1:-}" ;;
        --skip=*) SKIP="${1#--skip=}" ;;
        *) echo "prune-project-caches: unknown argument $1" >&2; exit 2 ;;
    esac
    shift 2>/dev/null || break
done

case "$MAX_AGE" in
    ''|*[!0-9]*) echo "prune-project-caches: max-age must be whole seconds" >&2; exit 2 ;;
esac

# The new project-scoped root first, then the two pre-project-scoped ones.
# The legacy roots have to be scanned as well: a cache is worth reclaiming
# precisely when its project stopped deploying, and a project that stopped
# deploying never runs the migration that would move it to the new root.
ROOTS="${PA_CACHE_ROOTS:-/var/cache/panelalpha/projects /var/cache/panelalpha/js /var/cache/pa-composer}"

NOW=$(date +%s)

# An account name, and nothing else: this string is about to reach `rm -rf`.
# A name of nothing but dots resolves outside its own directory, so it is
# refused along with anything carrying a slash or a shell character.
is_safe_name() {
    case "$1" in
        ''|.|..) return 1 ;;
        *[!a-zA-Z0-9_.-]*) return 1 ;;
    esac
    # All dots, any number of them.
    case "$1" in
        *[!.]*) return 0 ;;
        *) return 1 ;;
    esac
}

in_skip_list() {
    _name="$1"
    _rest="${SKIP},"
    [ -z "$SKIP" ] && return 1
    while [ -n "$_rest" ]; do
        _head="${_rest%%,*}"
        _rest="${_rest#*,}"
        [ "$_head" = "$_name" ] && return 0
        [ "$_rest" = "$_head" ] && break
    done
    return 1
}

for root in $ROOTS; do
    [ -d "$root" ] || continue

    for dir in "$root"/*; do
        [ -d "$dir" ] || continue

        name=$(basename "$dir")
        is_safe_name "$name" || continue
        in_skip_list "$name" && continue

        # Never the root itself, whatever the glob did.
        [ "$dir" = "$root" ] && continue

        mtime=$(stat -c %Y "$dir" 2>/dev/null) || continue
        case "$mtime" in
            ''|*[!0-9]*) continue ;;
        esac

        age=$((NOW - mtime))
        # A directory stamped in the future -- a clock that jumped, a restored
        # backup -- is not evidence that nobody is using it.
        [ "$age" -le "$MAX_AGE" ] && continue

        kb=$(du -sk "$dir" 2>/dev/null | cut -f1)
        case "$kb" in
            ''|*[!0-9]*) kb=0 ;;
        esac
        bytes=$((kb * 1024))

        if [ "$DRY_RUN" -eq 1 ]; then
            printf '%s\t%s\t%s\t%s\n' "$dir" "$bytes" "$age" "would-delete"
            continue
        fi

        if rm -rf "$dir" 2>/dev/null; then
            printf '%s\t%s\t%s\t%s\n' "$dir" "$bytes" "$age" "deleted"
        else
            printf '%s\t%s\t%s\t%s\n' "$dir" "$bytes" "$age" "failed"
        fi
    done
done

exit 0
