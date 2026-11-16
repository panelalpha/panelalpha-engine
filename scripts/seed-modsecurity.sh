#!/bin/bash
#
# Seeds config/modsecurity: copies what is missing, deletes nothing, keeps X.conf.disabled off.
# A ruleset file is overwritten only when it matches <shipped versions>; others are listed, custom never.
#
#   bash scripts/seed-modsecurity.sh /opt/panelalpha/shared-hosting [<shipped versions>]

set -euo pipefail

CUSTOM_RULESET=custom

declare -A SHIPPED=()

toggled_name() { # <path>
    case "$1" in
    *.conf.disabled) printf '%s' "${1%.disabled}" ;;
    *.conf) printf '%s.disabled' "$1" ;;
    *) printf '%s' "$1" ;;
    esac
}

host_copy() { # <host path> -- the host file standing for it, toggled or not
    if [ -e "$1" ]; then
        printf '%s' "$1"
    elif [ -e "$(toggled_name "$1")" ]; then
        toggled_name "$1"
    fi
}

relative_files() { # <dir>
    [ -d "$1" ] || return 0
    (cd "$1" && find . -type f -print | sed 's#^\./##' | LC_ALL=C sort)
}

seed() { # <templates> <config>
    local rel
    while IFS= read -r rel; do
        [ -n "$(host_copy "$2/$rel")" ] && continue
        mkdir -p "$(dirname "$2/$rel")"
        cp "$1/$rel" "$2/$rel"
    done < <(relative_files "$1")
}

load_shipped_versions() { # <list>
    local id rel
    while read -r id rel; do
        SHIPPED["$id $rel"]=1
    done <"$1"
}

# GIT_DIR: never the repository of the directory this runs from.
shipped_version() { # <path below the rulesets> <host file>
    local id
    id=$(GIT_DIR=/nonexistent git hash-object --no-filters "$2" 2>/dev/null) || return 1
    [ -n "${SHIPPED["$id $1"]+x}" ]
}

update_shipped_files() { # <shipped rulesets> <host rulesets>
    local rel
    while IFS= read -r rel; do
        case "$rel" in *.disabled) continue ;; esac
        [ -f "$2/$rel" ] && [ ! -L "$2/$rel" ] || continue
        cmp -s "$1/$rel" "$2/$rel" && continue
        shipped_version "$rel" "$2/$rel" || continue
        cp "$1/$rel" "$2/$rel"
        printf '  updated:     %s\n' "$2/$rel"
    done < <(relative_files "$1")
}

report_updates() { # <templates> <config>
    local updated
    [ "${#SHIPPED[@]}" -gt 0 ] || return 0
    updated=$(update_shipped_files "$1/rulesets" "$2/rulesets")
    [ -n "$updated" ] || return 0
    echo "ModSecurity ruleset files on this host brought up to the shipped version (each was a copy an earlier release shipped):"
    echo "$updated"
}

changed_shipped_files() { # <shipped rulesets> <host rulesets>
    local rel copy
    while IFS= read -r rel; do
        copy=$(host_copy "$2/$rel")
        [ -n "$copy" ] || continue
        cmp -s "$1/$rel" "$copy" || printf '  changed:     %s\n' "$copy"
    done < <(relative_files "$1")
}

unshipped_files() { # <shipped rulesets> <host rulesets>
    local rel
    while IFS= read -r rel; do
        [[ "$rel" == "$CUSTOM_RULESET"/* ]] && continue
        [ -e "$1/$rel" ] || [ -e "$1/$(toggled_name "$rel")" ] && continue
        printf '  not shipped: %s\n' "$2/$rel"
    done < <(relative_files "$2")
}

report_drift() { # <templates> <config> <whether shipped versions were known>
    local drift
    drift=$(changed_shipped_files "$1/rulesets" "$2/rulesets"; unshipped_files "$1/rulesets" "$2/rulesets")
    [ -n "$drift" ] || return 0
    echo "ModSecurity ruleset files on this host that differ from the shipped ones (left as they are):"
    if [ "$3" = no ] && grep -q '^  changed: ' <<<"$drift"; then
        echo "  (without the engine's history this run cannot tell an earlier release's copy from an edit, so none was replaced)"
    fi
    echo "$drift"
}

dir="${1:?usage: seed-modsecurity.sh <engine dir> [<shipped versions>]}"
known=no
if [ -n "${2:-}" ] && [ -r "$2" ]; then
    load_shipped_versions "$2"
    known=yes
fi
mkdir -p "$dir/config/modsecurity" "$dir/logs/modsecurity"
seed "$dir/templates/config/modsecurity" "$dir/config/modsecurity"
report_updates "$dir/templates/config/modsecurity" "$dir/config/modsecurity"
report_drift "$dir/templates/config/modsecurity" "$dir/config/modsecurity" "$known"
