#!/bin/bash
#
# config/modsecurity is host state seeded from templates/config/modsecurity:
# a shipped file the host lacks is copied, nothing on the host is overwritten
# or deleted, and a rule file the operator switched off (X.conf.disabled) is
# not copied back in as X.conf. A ruleset file that differs from what this
# tree ships, or that it does not ship at all, is therefore never corrected
# here; it is listed instead, so a stale or foreign WAF rule is visible. The
# custom ruleset is the operator's and is not compared.
#
#   bash scripts/seed-modsecurity.sh /opt/panelalpha/shared-hosting

set -euo pipefail

CUSTOM_RULESET=custom

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

report_drift() { # <templates> <config>
    local drift
    drift=$(changed_shipped_files "$1/rulesets" "$2/rulesets"; unshipped_files "$1/rulesets" "$2/rulesets")
    [ -n "$drift" ] || return 0
    echo "ModSecurity ruleset files on this host that differ from the shipped ones (left as they are):"
    echo "$drift"
}

dir="${1:?usage: seed-modsecurity.sh <engine dir>}"
mkdir -p "$dir/config/modsecurity" "$dir/logs/modsecurity"
seed "$dir/templates/config/modsecurity" "$dir/config/modsecurity"
report_drift "$dir/templates/config/modsecurity" "$dir/config/modsecurity"
