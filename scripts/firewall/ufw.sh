#!/usr/bin/env bash
# ufw as the engine host's firewall; scripts/firewall.sh runs it.
#
#   ufw.sh install         packages, CSF migration, defaults, the engine's ports,
#                          the Docker hook, fail2ban; then enable. Idempotent.
#   ufw.sh apply           the engine's route rules and the Docker hook (core runs
#                          it when it starts)
#   ufw.sh fail2ban        rewrite fail2ban's jails and reload them, bans kept
#                          (trusted list changed)
#   ufw.sh uninstall       disable ufw and remove the engine's hooks
#   ufw.sh refused         the stored rules iptables would not load, one per line
#   ufw.sh published-on|published-off   the hook, called by ufw itself
#
# ufw reloads only its own chains (MANAGE_BUILTINS=no), so Docker's chains and
# the engine's tenant and build chains survive every ufw change -- unlike CSF,
# whose every restart flushed the whole ruleset and needed csfpost.sh to put
# them back.
#
# ufw does not filter Docker on its own. A port Docker publishes (2011, 21,
# 2222, the FTP passive range) is DNATed in PREROUTING and forwarded to the
# container, so it never reaches INPUT, where ufw's rules are: without more,
# every published port is open to the world whatever ufw says. Forwarded
# traffic is what ufw's route rules are for, so the hook sends every new
# connection Docker DNATed from outside through them (ufw-user-forward) from
# DOCKER-USER, after the tenant network's rules, and drops what they do not
# allow. A published port is open only while a route rule allows it
# (`ufw route allow proto tcp to any port 8080`); the host's own rules
# (`ufw allow 22`) never open one. By then the destination is the container's,
# so a route rule names the container's port and address. The engine's own
# published ports get managed route rules, and are the same on both sides for
# that reason (SFTP listens on 2222 inside its container too). A fail2ban ban
# is written as both kinds of rule.
set -u

ENGINE_DIR=${PA_ENGINE_DIR:-/opt/panelalpha/shared-hosting}
UFW_DIR=${PA_UFW_DIR:-/etc/ufw}
UFW_DEFAULTS=${PA_UFW_DEFAULTS:-/etc/default/ufw}
F2B_DIR=${PA_FAIL2BAN_DIR:-/etc/fail2ban}
CSF_DIR=${PA_CSF_DIR:-/etc/csf}
# Where CSF's installer unpacked itself; its uninstaller is there too.
CSF_SRC=${PA_CSF_SRC:-/usr/src/csf}
# What CSF's uninstaller leaves: the archive CSF was unpacked from, and its
# daily version check, which recreates /var/lib/configserver every day. The
# check also serves ConfigServer's other products, which all need cPanel or
# DirectAdmin, so none runs on an engine host.
CSF_LEFTOVERS=("$CSF_SRC.tgz" "${PA_CSF_CRON:-/etc/cron.daily/csget}" "${PA_CSF_VAR:-/var/lib/configserver}")
BACKUP_DIR=${PA_BACKUP_DIR:-/var/backups}
SELF="$ENGINE_DIR/scripts/firewall/ufw.sh"
CHAIN=PA-PUBLISHED
PUBLISHED_JUMP=(-m conntrack --ctstate NEW -m conntrack --ctstate DNAT -j "$CHAIN")
MANAGED="panelalpha:"
# ufw rewrites user.rules whole on every call, so two writers at once lose or
# resurrect each other's rules. Every ufw write takes this lock: this script's,
# core's (App\System\Firewall\Ufw\UfwFirewall::LOCK) and fail2ban's ban action.
UFW_LOCK=${PA_UFW_LOCK:-$ENGINE_DIR/data/ufw.lock}
UFW_LOCK_WAIT=${PA_UFW_LOCK_WAIT:-30}
# The trusted list's lock, shared with core's
# App\System\Firewall\Ufw\UfwFirewall::TRUSTED_LOCK: both read-modify-write it.
TRUSTED_LOCK=${PA_UFW_TRUSTED_LOCK:-$ENGINE_DIR/data/ufw-trusted.lock}

say() { echo "firewall: $*"; }
trim() { printf '%s' "$1" | sed 's/^[[:space:]]*//; s/[[:space:]]*$//'; }
warn() { echo "firewall: $*" >&2; }
# iptables-legacy gives up at once ("Another app is currently holding the
# xtables lock") while Docker, ufw or fail2ban is changing rules, which left
# the Docker hook half built; -w waits for it, for at most 30 s so that ufw's
# start at boot cannot hang on it. iptables-nft has no lock and ignores -w.
iptables() { command iptables -w 30 "$@"; }
ip6tables() { command ip6tables -w 30 "$@"; }

# Runs "${@:3}" holding lock file $1, or says why not and fails; $2 names
# the kind of change in that message. The lock file stays readable for core,
# which opens it as www-data.
with_lock() {
    local lock_file=$1 lock_what=$2
    shift 2
    mkdir -p "$(dirname "$lock_file")"
    (
        flock -w "$UFW_LOCK_WAIT" 9 || {
            warn "another $lock_what change held $lock_file for over ${UFW_LOCK_WAIT}s; nothing was changed"
            exit 1
        }
        "$@"
    ) 9>>"$lock_file"
    local rc=$?
    chmod 0644 "$lock_file" 2>/dev/null
    return $rc
}

# Runs "$@" holding the ufw lock, or says why not and fails. Never around
# fail2ban-client: fail2ban's ban action waits for the same lock.
with_ufw_lock() {
    if [ -n "${UFW_LOCKED:-}" ]; then
        "$@"
        return
    fi
    with_lock "$UFW_LOCK" ufw ufw_locked "$@"
}
ufw_locked() {
    UFW_LOCKED=1
    "$@"
}

# The trusted write reloads fail2ban, so it takes its own lock, not the ufw one.
with_trusted_lock() { with_lock "$TRUSTED_LOCK" trusted-list "$@"; }

# Every port sshd is configured for, is listening on, or this session came in
# on: enabling ufw without the right one locks the operator out.
ssh_ports() {
    {
        sshd -T 2>/dev/null | awk '$1 == "port" { print $2 }'
        ss -Hltnp 2>/dev/null | awk '/"sshd"/ { n = split($4, a, ":"); print a[n] }'
        [ -n "${SSH_CONNECTION:-}" ] && echo "${SSH_CONNECTION##* }"
        echo 22
    } | grep -E '^[0-9]+$' | sort -un | tr '\n' ' '
}

# What the engine serves: <port> <proto> <label>. The API lists these as managed
# and does not let them be changed or removed.
managed_rules() {
    local p
    for p in $(ssh_ports); do echo "$p tcp ssh"; done
    echo "80 tcp http"
    echo "443 tcp https"
    echo "2011 tcp engine api"
    echo "21 tcp ftp"
    echo "30000:30009 tcp ftp passive"
    echo "2222 tcp sftp"
}

# The ports Docker publishes for the engine, as route rules: <port> <proto> <label>.
managed_route_rules() {
    echo "2011 tcp engine api"
    echo "21 tcp ftp"
    echo "30000:30009 tcp ftp passive"
    echo "2222 tcp sftp"
}

# An IPv4 or IPv6 address or CIDR range as the API takes one
# (FirewallRule::isAddress): no leading zero in an octet, which iptables reads
# as octal, and a prefix no longer than the address.
is_address() {
    local ip=${1%%/*} prefix=${1#*/} bits=32 octet
    if [[ $ip =~ ^(0|[1-9][0-9]{0,2})(\.(0|[1-9][0-9]{0,2})){3}$ ]]; then
        for octet in ${ip//./ }; do [ "$octet" -le 255 ] || return 1; done
    elif is_ipv6 "$ip"; then
        bits=128
    else
        return 1
    fi
    [[ $1 != */* ]] || { [[ $prefix =~ ^[0-9]{1,3}$ ]] && [ "$prefix" -le $bits ]; }
}
# ufw is python3, so python3 is there; without it, the shape alone.
is_ipv6() {
    if command -v python3 >/dev/null 2>&1; then
        python3 -c 'import ipaddress, sys; ipaddress.IPv6Address(sys.argv[1])' "$1" 2>/dev/null
    else
        [[ $1 =~ ^[0-9A-Fa-f:.]+$ && $1 == *:*:* && $1 != *:::* && ${1/::/} != *::* ]]
    fi
}
# A range wider than the API's trusted list takes (TrustedAddress::widthProblem).
too_wide() {
    [[ $1 == */* ]] || return 1
    if [[ $1 == *:* ]]; then [ "${1#*/}" -lt 16 ]; else [ "${1#*/}" -lt 8 ]; fi
}

# A comment without the bytes ufw drops, those that are not UTF-8, as python3,
# ufw's runtime, drops them.
utf8_only() {
    if command -v python3 >/dev/null 2>&1; then
        python3 -c 'import os, sys; sys.stdout.buffer.write(os.fsencode(sys.argv[1]).decode("utf-8", "ignore").encode())' "$1" ||
            printf '%s' "$1"
    elif command -v iconv >/dev/null 2>&1; then
        utf8_only_by_iconv "$1"
    else
        printf '%s' "$1"
    fi
}
# utf8_only without python3: iconv is made to match it, as glibc keeps code
# points above U+10FFFF and musl cuts the end off.
utf8_only_by_iconv() {
    local s
    s=$(
        export LC_ALL=C
        s=${1//$'\xf4'[$'\x90'-$'\xbf']/$'\xff\xff'}
        printf '%sx' "${s//[$'\xf5'-$'\xfe']/$'\xff'}" | iconv -c -f UTF-8 -t UTF-8 2>/dev/null
    )
    case "$s" in *x) printf '%s' "${s%x}" ;; *) printf '%s' "$1" ;; esac
}

# A comment changed as little as ufw (ufw/parser.py) and the API
# (FirewallRule::commentProblem()) need; prints it, then why, a line each.
# Each step below edits c and adds its reason to why, in this subshell.
safe_comment() ( # <comment> <host|route>
    export LC_ALL=C
    drop_non_utf8 "$1"
    blank_control_characters "$1"
    replace_apostrophes
    quote_reserved_prefixes
    quote_rule_keyword
    [ "$2" != route ] || quote_route_words
    printf '%s\n' "$c" "${why[@]}"
)

# The first step, which starts c and why; first, or "\xffin" would be stored as "in".
drop_non_utf8() { # <comment>
    why=()
    c=$(utf8_only "$1" && printf x)
    c=${c%x}
    [ "$c" = "$1" ] || why+=("bytes that are not UTF-8 are dropped, as ufw drops them")
}

# C0, DEL, and C1 as UTF-8 become spaces; a comment changed so far is trimmed.
blank_control_characters() { # <comment as given>
    local u=$c
    c=${c//[[:cntrl:]]/ }
    c=${c//$'\xc2'[$'\x80'-$'\x9f']/ }
    [ "$c" = "$u" ] || why+=("control characters become spaces")
    if [ "$c" != "$1" ]; then
        c=${c#"${c%%[! ]*}"}
        c=${c%"${c##*[! ]}"}
    fi
}

replace_apostrophes() {
    case "$c" in *\'*)
        c=${c//\'/$'\xe2\x80\x99'}
        why+=("ufw refuses ' in a comment")
        ;;
    esac
}

# A prefix ufw or the engine reads is quoted to the end of its word:
# "--rootdir=/srv" old.
quote_reserved_prefixes() {
    local p rest word
    for p in --rootdir= --datadir= "$MANAGED" 'by Fail2Ban'; do
        case "$c" in "$p"*)
            rest=${c#"$p"}
            word=${rest%%' '*}
            c="\"$p$word\"${rest#"$word"}"
            case "$p" in
            --*) why+=("ufw would take it for its own $p option") ;;
            "$MANAGED") why+=("the engine's own rules start with $MANAGED") ;;
            *) why+=("fail2ban's bans start with $p") ;;
            esac
            ;;
        esac
    done
}

quote_rule_keyword() {
    case "$c" in in | out | log | log-all)
        c="\"$c\""
        why+=("ufw would read it as part of the rule")
        ;;
    esac
}

# Whether ufw's route parser would take words of <comment> for the rule's: a
# bare "delete", both "in on" and "out on", or an "in" or "out" that is not part
# of "in on", "out on", "app in" or "app out".
misread_as_route() { # <comment>
    local w=" $1"
    [ "$1" = delete ] || [[ $w == *' in on '* && $w == *' out on '* ]] ||
        { [[ $w == *' in '* || $w == *' out '* ]] &&
            ! [[ $w == *' in on '* || $w == *' out on '* || $w == *' app in '* || $w == *' app out '* ]]; }
}

quote_route_words() {
    local w=" $c"
    misread_as_route "$c" || return 0
    [ "$c" = delete ] && w=' "delete"'
    while [[ $w == *' in '* || $w == *' out '* ]]; do
        w=${w//' in '/' "in" '}
        w=${w//' out '/' "out" '}
    done
    c=${w# }
    why+=("ufw would read it as part of a route rule")
}

# A comment for a log line: in single quotes, which a written one never holds,
# or as $'...' with control characters or bytes that are not UTF-8.
shown() {
    local u
    u=$(utf8_only "$1" && printf x)
    if [[ $1 == *[[:cntrl:]]* ]] || [ "${u%x}" != "$1" ]; then printf '%q' "$1"; else printf "'%s'" "$1"; fi
}

# UFW_COMMENT: what ufw stores of an operator's comment; a change is said.
UFW_COMMENT=
ufw_comment() { # <comment> <host|route> <the rule, for the log>
    local out why
    out=$(safe_comment "$1" "$2")
    UFW_COMMENT=${out%%$'\n'*}
    [ "$UFW_COMMENT" != "$1" ] || return 0
    why=${out#*$'\n'}
    why=${why//$'\n'/; }
    if [ -n "$UFW_COMMENT" ]; then
        say "$3: the comment $(shown "$1") is written as $(shown "$UFW_COMMENT"): $why"
    else
        say "$3: the comment $(shown "$1") is left out: $why"
    fi
}

# ufw <words...> with UFW_COMMENT, else without it: 0 as asked, 1 without the
# comment, 2 not written. UFW_ERROR: ufw's ERROR line alone.
UFW_ERROR=
ufw_write() {
    local out
    UFW_ERROR=
    if [ -n "$UFW_COMMENT" ]; then
        out=$(ufw "$@" comment "$UFW_COMMENT" </dev/null 2>&1) && ! ufw_refused "$out" && return 0
        UFW_ERROR=$(ufw_error "$out")
    fi
    if out=$(ufw "$@" </dev/null 2>&1) && ! ufw_refused "$out"; then
        [ -z "$UFW_COMMENT" ] && return 0
        return 1
    fi
    UFW_ERROR=$(ufw_error "$out")
    return 2
}
# Some refusals exit 0.
ufw_refused() { printf '%s\n' "$1" | grep -qi '^error'; }
ufw_error() { printf '%s\n' "$1" | grep -i -m1 '^error' || printf '%s\n' "$1" | sed -n '/./{p;q;}'; }

# One csf.allow / csf.deny line into UFW_ARGS (the words after `ufw prepend`),
# and for a bare address, which CSF applied both ways, UFW_ARGS_OUT as well:
# the API lists the two as one rule in both directions. Its comment, as ufw
# can store it, into UFW_COMMENT; an inbound deny's is fit for a route rule as
# well, as convert_published carries it over to published ports and the API
# lists the two as one rule only when their comments match.
# Fails for comments, blank lines, the entries the engine itself wrote, lfd's
# own automatic blocks (fail2ban takes over from lfd and starts empty) and
# anything ufw cannot express, which is reported.
UFW_ARGS=()
UFW_ARGS_OUT=()
csf_line_to_ufw() { # <allow|deny> <line>
    local action=$1 line=${2%$'\r'} rule comment="" kind=host
    UFW_ARGS=()
    UFW_ARGS_OUT=()
    UFW_COMMENT=
    rule=$(trim "${line%%#*}")
    case "$line" in *'#'*) comment=$(trim "${line#*#}") ;; esac
    csf_line_movable "$rule" "$comment" "$line" || return 1
    if [ "${rule#*|}" = "$rule" ]; then
        csf_address_to_ufw "$action" "$rule" "$line" || return 1
    else
        csf_filter_to_ufw "$action" "$rule" "$line" || return 1
    fi
    [ "$action" = deny ] && [ "${UFW_ARGS[1]}" = in ] && kind=route
    ufw_comment "$comment" "$kind" "$rule from csf.$action"
    return 0
}

# Whether a CSF line is a rule to move; an include, which ufw has no
# equivalent for, is reported.
csf_line_movable() { # <rule> <comment> <line>
    [ -n "$1" ] || return 1
    case "$2" in *'docker internal network'* | lfd:* | 'lfd '*) return 1 ;; esac
    case "$1" in
    [Ii]nclude*)
        warn "not moved, ufw has no include: $3"
        return 1
        ;;
    esac
}

csf_address_to_ufw() { # <allow|deny> <address> <line>
    if ! is_address "$2"; then
        warn "not moved, not an address: $3"
        return 1
    fi
    UFW_ARGS=("$1" in from "$2" to any)
    UFW_ARGS_OUT=("$1" out from any to "$2")
}

# A filter rule, proto|dir|s=or d=port|s=or d=address, into UFW_ARGS. A port
# with zeros in front is moved without them, and that is said.
csf_filter_to_ufw() { # <allow|deny> <rule> <line>
    local proto dir pspec aspec extra
    IFS='|' read -r proto dir pspec aspec extra <<<"$2"
    if ! csf_filter_fields_known "$proto" "$dir" "$extra"; then
        warn "not moved, unrecognised rule: $3"
        return 1
    fi
    if ! csf_port "${pspec#?=}"; then
        warn "not moved, not a port ufw can hold (1-65535, a range low to high): $3"
        return 1
    fi
    [ "$CSF_PORT" = "$(echo "${pspec#?=}" | tr '_' ':')" ] ||
        say "$2: port ${pspec#?=} is moved as $CSF_PORT: a leading zero is dropped, which iptables refuses (08080) or reads as octal (0443)"
    csf_filter_sides_known "$aspec" "$pspec" "$3" || return 1
    ufw_filter_args "$1" "$dir" "$proto" "$aspec" "$pspec" "$CSF_PORT"
}

csf_filter_fields_known() { # <proto> <dir> <extra fields>
    [ -z "$3" ] && echo "$1" | grep -qE '^(tcp|udp)$' && echo "$2" | grep -qE '^(in|out)$'
}

# Whether ufw can match a filter rule's address field (s= or d=; not a user
# id, u=) and port field (s= or d=); what it cannot is reported.
csf_filter_sides_known() { # <address field> <port field> <line>
    case "$1" in
    u=*)
        warn "not moved, ufw cannot match a user id: $3"
        return 1
        ;;
    s=* | d=*) ;;
    *)
        warn "not moved, unrecognised address: $3"
        return 1
        ;;
    esac
    is_address "${1#?=}" || {
        warn "not moved, not an address: $3"
        return 1
    }
    case "$2" in
    s=* | d=*) ;;
    *)
        warn "not moved, unrecognised port: $3"
        return 1
        ;;
    esac
}

ufw_filter_args() { # <action> <dir> <proto> <address field> <port field> <ports>
    local from=any to=any sport="" dport=""
    case "$4" in s=*) from=${4#?=} ;; d=*) to=${4#?=} ;; esac
    case "$5" in s=*) sport=$6 ;; d=*) dport=$6 ;; esac
    UFW_ARGS=("$1" "$2" proto "$3" from "$from")
    [ -n "$sport" ] && UFW_ARGS+=(port "$sport")
    UFW_ARGS+=(to "$to")
    [ -n "$dport" ] && UFW_ARGS+=(port "$dport")
    return 0
}

# A CSF port (22, 30000_30009, 80,443) as ufw is to store it, into CSF_PORT;
# fails for anything else. CSF hands it to iptables as written, and so would
# ufw, whose iptables-restore then refuses the whole ruleset on a port like
# 08080 and locks the host out. The port meant is decimal: zeros in front go.
CSF_PORT=
csf_port() {
    local item lo hi out=()
    CSF_PORT=
    [[ $1 =~ ^[0-9]+([_:][0-9]+)?(,[0-9]+([_:][0-9]+)?)*$ ]] || return 1
    for item in ${1//,/ }; do
        lo=$(decimal_port "${item%%[_:]*}") || return 1
        hi=$(decimal_port "${item##*[_:]}") || return 1
        [ "$lo" -le "$hi" ] || return 1
        if [ "$lo" = "$hi" ]; then out+=("$lo"); else out+=("$lo:$hi"); fi
    done
    CSF_PORT=$(IFS=,; echo "${out[*]}")
}
decimal_port() {
    local n=${1#"${1%%[!0]*}"}
    [ -n "$n" ] && [ ${#n} -le 5 ] && [ "$n" -le 65535 ] && echo "$n"
}

# Each rule goes on top; called for csf.deny first, then csf.allow, so the
# allows end up above the denies and win, as they did under CSF. Until the
# engine's route rules are there, convert_published carries the denies over.
import_csf_file() { # <allow|deny> <file>
    local action=$1 file=$2 line moved=0 routed=
    [ -f "$file" ] || return 0
    has_engine_routes && routed=1
    while IFS= read -r line || [ -n "$line" ]; do
        csf_line_to_ufw "$action" "$line" || continue
        write_csf_rule "$(trim "${line%%#*}") from $(basename "$file")" "$routed" || continue
        moved=$((moved + 1))
    done <"$file"
    say "moved $moved rule(s) from $(basename "$file")"
}

# Writes what csf_line_to_ufw made; with <routed>, an inbound deny covers
# published ports too, as one made through the API does. Fails when ufw
# refused the rule itself; one written without its comment is said.
write_csf_rule() { # <the rule, for the log> <routed>
    local what=$1 comment=$UFW_COMMENT refused="" rc
    ufw_write prepend "${UFW_ARGS[@]}"
    rc=$?
    [ $rc = 1 ] && refused=$UFW_ERROR UFW_COMMENT=
    if [ $rc != 2 ] && [ ${#UFW_ARGS_OUT[@]} -gt 0 ]; then
        ufw_write prepend "${UFW_ARGS_OUT[@]}"
        rc=$?
        [ $rc = 1 ] && refused=$UFW_ERROR
    fi
    if [ $rc = 2 ]; then
        warn "not moved: $what ($UFW_ERROR)"
        return 1
    fi
    if [ -n "$2" ] && [ "${UFW_ARGS[0]} ${UFW_ARGS[1]}" = "deny in" ]; then
        ufw_write route prepend deny "${UFW_ARGS[@]:2}"
        [ $? != 2 ] || warn "$what: moved, but not to published ports ($UFW_ERROR)"
    fi
    [ -z "$refused" ] || warn "$what: moved without its comment $(shown "$comment"), which ufw refused ($refused)"
    return 0
}

# Each address on its own line, as core compares them (FirewallRule::normalizeAddress):
# one spelling per address, a /32 or /128 the bare address, a network by its
# masked base. ufw is python3, so python3 is there; without it, as written.
canonical_addresses() {
    if command -v python3 >/dev/null 2>&1; then
        python3 -c '
import ipaddress, sys
for a in sys.stdin.read().splitlines():
    try:
        n = ipaddress.ip_network(a, strict=False)
        print(n.network_address if n.prefixlen == n.max_prefixlen else n)
    except ValueError:
        print(a)'
    else
        cat
    fi
}

# Addresses CSF trusted (csf.ignore, and every bare address in csf.allow) are
# kept out of fail2ban's bans, as lfd kept them. Added to the trusted list only
# when it does not hold that address yet, so a second migration adds nothing;
# what is there, comments and order included, stays as it is.
import_csf_ignores() {
    local list="$F2B_DIR/panelalpha-ignoreip" add
    local -a held=() found=()
    mapfile -t held < <(trusted_addresses "$list")
    mapfile -t found < <(csf_trusted_addresses)
    [ ${#found[@]} = 0 ] && return 0
    add=$(addresses_not_held ${#held[@]} "${held[@]}" "${found[@]}")
    [ -n "$add" ] || return 0
    append_lines "$list" "$add"
}

# The addresses on the trusted list <list>, a line each, comments left out.
trusted_addresses() { # <list>
    local line addr
    [ -f "$1" ] || return 0
    while IFS= read -r line || [ -n "$line" ]; do
        addr=$(trim "${line%%#*}")
        [ -n "$addr" ] && printf '%s\n' "$addr"
    done <"$1"
    return 0
}

csf_trusted_addresses() {
    local file line
    for file in "$CSF_DIR/csf.ignore" "$CSF_DIR/csf.allow"; do
        [ -f "$file" ] || continue
        while IFS= read -r line || [ -n "$line" ]; do
            csf_trusted_address "$file" "$line"
        done <"$file"
    done
}

# The address on a line of csf.ignore or csf.allow, if it is one the trusted
# list takes. csf.allow's rules (tcp|in|...) are not addresses, and
# csf_line_to_ufw names a bad one there.
csf_trusted_address() { # <file> <line>
    local addr
    case "$2" in *'docker internal network'*) return 0 ;; esac
    addr=$(trim "${2%%#*}")
    [ -n "$addr" ] || return 0
    if ! is_address "$addr"; then
        [ "$1" != "$CSF_DIR/csf.ignore" ] || warn "not trusted, not an address: $2 (csf.ignore)"
        return 0
    fi
    if too_wide "$addr"; then
        warn "not trusted, a range wider than /8 (IPv4) or /16 (IPv6) would keep much of the internet from ever being banned: $addr ($(basename "$1"))"
        return 0
    fi
    printf '%s\n' "$addr"
}

# Of <held count> held addresses followed by found ones, the found ones not
# held yet in any spelling, sorted.
addresses_not_held() { # <held count> <held...> <found...>
    local held_count=$1 i addr
    shift
    local -a all=("$@") canon=()
    local -A have=()
    mapfile -t canon < <(printf '%s\n' "${all[@]}" | canonical_addresses)
    [ ${#canon[@]} = ${#all[@]} ] || canon=("${all[@]}")
    for ((i = 0; i < held_count; i++)); do have[${canon[i]}]=1; done
    for ((i = held_count; i < ${#all[@]}; i++)); do
        addr=${canon[i]}
        [ -z "${have[$addr]+x}" ] || continue
        have[$addr]=1
        echo "${all[i]}"
    done | sort -u
}

# <lines> on lines of their own, also after a last line with no newline.
append_lines() { # <file> <lines>
    [ ! -s "$1" ] || [ -z "$(tail -c1 "$1")" ] || echo >>"$1"
    printf '%s\n' "$2" >>"$1"
}

# An old core container still bind-mounting /etc/csf recreates it when Docker
# restarts it after the move; core runs apply once it is recreated without
# that mount. An empty one always goes. One with anything in it, csf.conf
# included, goes once the migration has finished (its backup is there) and
# nothing of CSF is installed or running: a csf.conf left then would only make
# every later install migrate again. Otherwise it is kept and the reason said.
# CSF's other leftovers go on the same terms, and only after the move: before
# it they are a working CSF's.
clear_csf_leftover() {
    local left=() path why
    if [ -d "$CSF_DIR" ]; then
        if rmdir "$CSF_DIR" 2>/dev/null; then
            say "removed the empty $CSF_DIR CSF left behind"
        else
            left+=("$CSF_DIR")
        fi
    fi
    if compgen -G "$BACKUP_DIR/panelalpha-csf-*.tgz" >/dev/null; then
        for path in "${CSF_LEFTOVERS[@]}"; do
            [ -e "$path" ] || [ -L "$path" ] && left+=("$path")
        done
        [ ${#left[@]} = 0 ] && return 0
        if ! why=$(csf_present); then
            rm -rf "${left[@]}"
            say "removed ${left[*]}, left behind after the move from CSF"
            return 0
        fi
    else
        why="no $BACKUP_DIR/panelalpha-csf-*.tgz, so the move from CSF never finished"
    fi
    [ ${#left[@]} = 0 ] || say "kept ${left[*]}: $why"
}

# Says what of CSF is still on the host; fails when nothing is.
csf_present() {
    local unit
    for unit in csf lfd; do
        if command -v "$unit" >/dev/null 2>&1; then
            echo "$unit is still installed"
        elif systemctl is-active --quiet "$unit" 2>/dev/null; then
            echo "$unit is still running"
        elif [ "$(systemctl show -p LoadState --value "$unit" 2>/dev/null)" = loaded ]; then
            echo "the $unit service is still installed"
        else
            continue
        fi
        return 0
    done
    return 1
}

# CSF is replaced, not kept beside ufw: its rules move to ufw, its config is
# saved, and it is uninstalled. Its uninstaller flushes the whole ruleset,
# Docker's chains included; the installers restart Docker right after this.
# A host where CSF had been turned off (csf.disable) keeps ufw off as well.
#
# The move is in two halves. This one runs while CSF still protects the host,
# and fails if any rule file could not be read over: CSF is then left as it is.
import_from_csf() {
    clear_csf_leftover
    [ -f "$CSF_DIR/csf.conf" ] || return 0
    say "replacing CSF with ufw"
    with_ufw_lock import_csf_file deny "$CSF_DIR/csf.deny" &&
        with_ufw_lock import_csf_file allow "$CSF_DIR/csf.allow" || return 1
    mkdir -p "$F2B_DIR"
    with_trusted_lock import_csf_ignores
    [ -f "$CSF_DIR/csf.disable" ] && touch "$UFW_DIR/.panelalpha-keep-disabled"
    return 0
}

# The second half, once ufw is ready to take over; install() runs it under the
# same hold of the ufw lock as `ufw enable`, so nothing can come between them.
# The backup is made here: clear_csf_leftover reads it as "the move finished".
remove_csf() {
    [ -f "$CSF_DIR/csf.conf" ] || return 0
    back_up_csf
    uninstall_csf
    if [ -f "$ENGINE_DIR/.env" ]; then
        sed -i '/^CSF_UI=/d; /^CSF_UI_PASSWORD=/d' "$ENGINE_DIR/.env"
    fi
    say "CSF uninstalled"
}

# CSF's configuration into CSF_BACKUP, which stays empty when it could not be saved.
back_up_csf() {
    mkdir -p "$BACKUP_DIR"
    CSF_BACKUP="$BACKUP_DIR/panelalpha-csf-$(date +%Y%m%d%H%M%S).tgz"
    if tar -czf "$CSF_BACKUP" -C "$(dirname "$CSF_DIR")" "$(basename "$CSF_DIR")" 2>/dev/null; then
        say "CSF's configuration is saved in $CSF_BACKUP"
    else
        CSF_BACKUP=
    fi
}

uninstall_csf() {
    if [ -f "$CSF_DIR/uninstall.sh" ]; then
        sh "$CSF_DIR/uninstall.sh" >/dev/null 2>&1 || true
    elif [ -f "$CSF_SRC/uninstall.sh" ]; then
        sh "$CSF_SRC/uninstall.sh" >/dev/null 2>&1 || true
    fi
    systemctl disable --now lfd csf >/dev/null 2>&1 || true
    systemctl reset-failed lfd csf >/dev/null 2>&1 || true
    rm -rf "$CSF_SRC" "$CSF_DIR" "${CSF_LEFTOVERS[@]}"
}

# Whether ufw's live rules hold what its rule files say. A load that failed
# leaves INPUT dropping with ufw-user-input empty, which locks the host out,
# and a start that failed marks ufw off again but leaves its chains behind.
ufw_loaded() {
    local ipt prefix file want have
    for ipt in iptables ip6tables; do
        prefix=ufw file=$UFW_DIR/user.rules
        [ "$ipt" = ip6tables ] && prefix=ufw6 file=$UFW_DIR/user6.rules
        "$ipt" -n -L "$prefix-user-input" >/dev/null 2>&1 || continue
        grep -qs '^ENABLED=yes' "$UFW_DIR/ufw.conf" || return 1
        want=$(cat "$file" 2>/dev/null | grep -c "^-A $prefix-user-input ")
        have=$("$ipt" -S "$prefix-user-input" 2>/dev/null | grep -c "^-A $prefix-user-input ")
        [ "$have" -ge "$want" ] || return 1
    done
    return 0
}

# With the rule files put back after a failed change: loads them, or turns
# ufw off so the host stays reachable, and says so with what iptables said.
ufw_recover() {
    local out=""
    if grep -qs '^ENABLED=yes' "$UFW_DIR/ufw.conf"; then
        out=$(ufw reload 2>&1) && ufw_loaded && return 0
    elif ufw_loaded; then
        return 0
    fi
    ufw --force disable >/dev/null 2>&1
    out=$(printf '%s\n' "$out" | sed '/^$/d' | tr '\n' ' ')
    warn "ufw could not load its rules, so it is turned off to keep this host reachable${out:+: ${out% }}"
    warn "fix the rules in $UFW_DIR/user.rules, then run 'ufw enable'"
    return 1
}

# Runs "$@", which writes ufw's rules, so that a failure cannot lock the host
# out: the rule files go back as they were and are loaded again, or ufw is
# turned off. Fails when "$@" did, or its rules did not load.
ufw_guarded() {
    local saved f rc=0
    saved=$(mktemp -d) || return 1
    for f in user.rules user6.rules ufw.conf; do
        [ ! -e "$UFW_DIR/$f" ] || cp -p "$UFW_DIR/$f" "$saved/" || {
            rm -rf "$saved"
            warn "could not copy $UFW_DIR/$f aside; nothing was changed"
            return 1
        }
    done
    "$@" || rc=1
    if [ $rc = 0 ] && ufw_loaded; then
        rm -rf "$saved"
        return 0
    fi
    for f in user.rules user6.rules ufw.conf; do
        [ ! -e "$saved/$f" ] || cp -p "$saved/$f" "$UFW_DIR/$f"
    done
    rm -rf "$saved"
    ufw_recover
    return 1
}

install_packages() {
    local missing=()
    command -v ufw >/dev/null 2>&1 || missing+=(ufw)
    command -v fail2ban-client >/dev/null 2>&1 || missing+=(fail2ban)
    dpkg -s python3-systemd >/dev/null 2>&1 || missing+=(python3-systemd)
    [ ${#missing[@]} = 0 ] && return 0
    DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout=300 install -y "${missing[@]}"
}

configure_ufw() {
    set_ufw_defaults || return 1
    turn_ufw_logging_on
    open_managed_ports
    route_rules
    write_ufw_hooks
}

# Enabled without the default policies, ufw would let everything in.
set_ufw_defaults() {
    if [ -f "$UFW_DEFAULTS" ]; then
        sed -i 's/^IPV6=.*/IPV6=yes/; s/^MANAGE_BUILTINS=.*/MANAGE_BUILTINS=no/' "$UFW_DEFAULTS"
    fi
    ufw default deny incoming >/dev/null &&
        ufw default allow outgoing >/dev/null &&
        ufw default deny routed >/dev/null || {
        warn "could not set ufw's default policies"
        return 1
    }
}

# The firewall API reads what was blocked from ufw's log; an operator's own
# level (medium, high) is kept.
turn_ufw_logging_on() {
    if grep -q '^LOGLEVEL=off' "$UFW_DIR/ufw.conf" 2>/dev/null; then
        ufw logging low >/dev/null
    fi
}

open_managed_ports() {
    local port proto label
    while read -r port proto label; do
        ufw allow proto "$proto" from any to any port "$port" comment "$MANAGED $label" >/dev/null ||
            warn "could not open $port/$proto ($label)"
    done < <(managed_rules)
}

# The hooks ufw runs on start and stop; see the header.
write_ufw_hooks() {
    cat >"$UFW_DIR/before.init" <<EOF
#!/bin/sh
# Written by the PanelAlpha engine (scripts/firewall/ufw.sh). ufw cannot delete
# its user chains while the engine's DOCKER-USER hook still jumps into them.
[ "\$1" = stop ] && bash $SELF published-off >/dev/null 2>&1
exit 0
EOF
    cat >"$UFW_DIR/after.init" <<EOF
#!/bin/sh
# Written by the PanelAlpha engine (scripts/firewall/ufw.sh).
case "\$1" in
start) bash $SELF published-on >/dev/null 2>&1 ;;
# Stopping ufw sets FORWARD to ACCEPT; Docker's own policy is DROP.
stop) iptables -P FORWARD DROP 2>/dev/null ;;
esac
exit 0
EOF
    chmod 0750 "$UFW_DIR/before.init" "$UFW_DIR/after.init"
}

# ufw's rules as "<action> <proto> <dport> <dst> <sport> <src> <direction>
# <comment as hex, or ->", from both rule files; a route rule's action is
# route:<action>. Rules with an application profile have more fields and are
# left out.
tuples() {
    cat "$UFW_DIR/user.rules" "$UFW_DIR/user6.rules" 2>/dev/null | awk '
        $1 == "###" && $2 == "tuple" && $3 == "###" {
            n = NF; c = "-"
            if ($n ~ /^comment=/) { c = substr($n, 9); n-- }
            if (n == 10) print $4, $5, $6, $7, $8, $9, $10, c
        }'
}

unhex() { printf '%b' "$(printf '%s' "$1" | sed 's/../\\x&/g')"; }
any() { case "$1" in 0.0.0.0/0 | ::/0) echo any ;; *) echo "$1" ;; esac; }

# Whether the engine set this ufw up: its managed rules are there.
ufw_is_ours() {
    grep -q "comment=$(printf '%s' "$MANAGED" | od -An -tx1 | tr -d ' \n')" "$UFW_DIR/user.rules" 2>/dev/null
}

has_route_allow() { # <port> <proto>
    tuples | awk -v p="$1" -v t="$2" '
        function any(a) { return a == "any" || a == "0.0.0.0/0" || a == "::/0" }
        $1 == "route:allow" && $2 == t && $3 == p && any($4) && any($6) && $7 == "in" { f = 1 }
        END { exit !f }'
}

# Ports containers publish now, "<port[-port]> <proto>": the container's side
# of each mapping, which is what route rules match.
published_container_ports() {
    docker ps --format '{{.Ports}}' 2>/dev/null | tr ',' '\n' |
        sed -n 's/.*->\([0-9][0-9-]*\)\/\([a-z]*\).*/\1 \2/p' | sort -u
}

# Whether a rule's port (any, a port, a range, or a list of either) covers a
# published port read from stdin, for its protocol.
covers_published() { # <proto> <dport>
    awk -v proto="$1" -v list="$2" '
        BEGIN { n = split(list, r, ",") }
        NF < 2 || (proto != "any" && $2 != proto) { next }
        list == "any" { hit = 1; exit }
        {
            split($1, c, "-"); lo = c[1] + 0; hi = c[2] == "" ? lo : c[2] + 0
            for (i = 1; i <= n; i++) {
                split(r[i], q, ":"); a = q[1] + 0; b = q[2] == "" ? a : q[2] + 0
                if (a <= hi && lo <= b) { hit = 1; exit }
            }
        }
        END { exit !hit }'
}

# Before route rules, the hook judged published ports on the host's own rules,
# at the container's port. Once, when the engine's route rules first go in: an
# operator's allow that reached a port a container publishes now gets the same
# rule as a route rule, so nothing reachable is cut off; every inbound deny gets
# one too, as a deny made through the API now does, so nothing blocked opens.
# Bans are mirror_bans' job. Each one is reported. The rules are read before
# ufw starts rewriting the files.
convert_published() {
    local ports rules action proto dport dst sport src dir hex comment key
    local -A seen=()
    ports=$(published_container_ports)
    rules=$(tuples)
    while read -r action proto dport dst sport src dir hex; do
        case "$action" in allow | deny | reject) ;; *) continue ;; esac
        [ "$dir" = in ] || continue
        comment=
        [ "$hex" = - ] || comment=$(unhex "$hex")
        reaches_published "$action" "$comment" "$proto" "$dport" "$ports" || continue
        route_args_for "$action" "$proto" "$dport" "$dst" "$sport" "$src"
        key="${ROUTE_ARGS[*]}"
        [ -z "${seen[$key]:-}" ] || continue
        seen[$key]=1
        carry_over "$comment" "$key"
    done <<<"$rules"
}

# Whether an operator's inbound rule needs its route rule: every deny or
# reject, and an allow that covers a port a container publishes now.
reaches_published() { # <action> <comment> <proto> <dport> <published ports>
    case "$2" in "$MANAGED"* | 'by Fail2Ban'*) return 1 ;; esac
    [ "$1" = allow ] || return 0
    [ -n "$5" ] && printf '%s\n' "$5" | covers_published "$3" "$4"
}

# The route rule's words for a host rule into ROUTE_ARGS; one that is not an
# allow goes on top.
ROUTE_ARGS=()
route_args_for() { # <action> <proto> <dport> <dst> <sport> <src>
    ROUTE_ARGS=(route)
    [ "$1" = allow ] || ROUTE_ARGS+=(prepend)
    ROUTE_ARGS+=("$1")
    [ "$2" = any ] || ROUTE_ARGS+=(proto "$2")
    ROUTE_ARGS+=(from "$(any "$6")")
    [ "$5" = any ] || ROUTE_ARGS+=(port "$5")
    ROUTE_ARGS+=(to "$(any "$4")")
    [ "$3" = any ] || ROUTE_ARGS+=(port "$3")
    return 0
}

# Writes ROUTE_ARGS with the host rule's comment, and says how that went.
carry_over() { # <comment> <the rule, for the log>
    ufw_comment "$1" route "ufw $2"
    ufw_write "${ROUTE_ARGS[@]}"
    case $? in
    0) say "carried over to published ports: ufw $2${UFW_COMMENT:+ comment $UFW_COMMENT}" ;;
    1) warn "carried over to published ports without its comment $(shown "$UFW_COMMENT"), which ufw refused ($UFW_ERROR): ufw $2" ;;
    *) warn "could not carry over to published ports: ufw $2 ($UFW_ERROR)" ;;
    esac
}

# Whether any of the engine's own route rules is there.
has_engine_routes() {
    local port proto label
    while read -r port proto label; do
        has_route_allow "$port" "$proto" && return 0
    done < <(managed_route_rules)
    return 1
}

# The engine's own published ports as route rules (see the header), put back
# when missing; the first time they go in, the operator's rules are carried over.
route_rules() {
    local port proto label
    has_engine_routes || convert_published
    while read -r port proto label; do
        has_route_allow "$port" "$proto" ||
            ufw route allow proto "$proto" from any to any port "$port" comment "$MANAGED $label" </dev/null >/dev/null ||
            warn "could not open published port $port/$proto ($label)"
    done < <(managed_route_rules)
}

# A ban made before bans covered published ports, or by an action that only
# writes the host's rule, gets its route rule; lifting the ban removes both.
mirror_bans() {
    local rules action proto dport dst sport src dir hex comment
    local -A routed=()
    rules=$(tuples)
    while read -r action proto dport dst sport src dir hex; do
        [ "$action" = route:deny ] && routed[$src]=1
    done <<<"$rules"
    while read -r action proto dport dst sport src dir hex; do
        [ "$action $proto $dport $sport $dir" = "deny any any any in" ] && [ "$(any "$dst")" = any ] || continue
        [ "$hex" != - ] && [ -z "${routed[$src]:-}" ] || continue
        comment=$(unhex "$hex")
        case "$comment" in 'by Fail2Ban'*) ;; *) continue ;; esac
        routed[$src]=1
        ufw route prepend deny from "$src" to any comment "$comment" </dev/null >/dev/null ||
            warn "could not extend the ban on $src to published ports"
    done <<<"$rules"
}

# fail2ban bans an address after failed logins on what the engine serves:
#   sshd        the host's SSH, from the journal (ssh.service / sshd.service)
#   sftp        the SFTP container's sshd: it logs to the host's journal as local5
#   ftp         pure-ftpd, which logs to the host's journal through /dev/log
#   engine-api  failed tokens on :2011, from core's api-access.log
#   site-password  wrong passwords on protected sites, from sites-http's
#               site-password.log
# The stock filters match by process name, which would count the SFTP
# container's sshd as the host's; the engine's own filters match the source.
# Trusted addresses (the firewall API, CSF's old allow and ignore lists) are in
# $F2B_DIR/panelalpha-ignoreip, one per line, "# comment" after it. Core keeps
# PanelAlpha Connect's caller in panelalpha-api-ignoreip, for engine-api only.
configure_fail2ban() { # [trusted]: only the trusted list changed
    local api_log="$ENGINE_DIR/logs/core/nginx/api-access.log" actions ignore api_ignore containers
    local site_log="$ENGINE_DIR/webserver-logs/nginx-proxy/site-password.log"
    mkdir -p "$F2B_DIR/jail.d" "$F2B_DIR/filter.d" "$F2B_DIR/action.d" "$(dirname "$api_log")" "$(dirname "$site_log")"
    actions=$(ban_actions)
    touch "$F2B_DIR/panelalpha-ignoreip" "$F2B_DIR/panelalpha-api-ignoreip" "$api_log" "$site_log"
    ignore=$(f2b_ignoreip)
    api_ignore="$ignore $(ignore_list "$F2B_DIR/panelalpha-api-ignoreip")"
    containers=$(docker_subnets)
    write_f2b_filters
    write_f2b_ban_action
    write_f2b_jails "$ignore" "$containers" "$api_log" "$site_log" "${api_ignore% }"
    systemctl enable fail2ban >/dev/null 2>&1 || true
    reload_fail2ban "$actions" "${1:-}"
}

# fail2ban's ignoreip: loopback and every address on the trusted list, also
# one on a line written "address#comment".
f2b_ignoreip() {
    local ignore="127.0.0.1/8 ::1"
    ignore="$ignore $(ignore_list "$F2B_DIR/panelalpha-ignoreip")"
    printf '%s' "${ignore% }"
}

# A list file's addresses, space-separated, without their "# comment".
ignore_list() { awk '{ sub(/#.*/, "") } $1 != "" { print $1 }' "$1" | sort -u | tr '\n' ' '; }

# The host's Docker networks. A tunnel's cloudflared reaches a site from its
# account's address on one: banning that would cut the account off the host.
docker_subnets() {
    local containers
    containers=$(docker network inspect -f '{{range .IPAM.Config}}{{.Subnet}} {{end}}' $(docker network ls -q 2>/dev/null) 2>/dev/null |
        tr ' ' '\n' | sed '/^$/d' | sort -u | tr '\n' ' ')
    printf '%s' "${containers% }"
}

write_f2b_filters() {
    cat >"$F2B_DIR/filter.d/panelalpha-sshd.conf" <<'EOF'
# Written by the PanelAlpha engine: the host's own sshd only.
[INCLUDES]
before = sshd.conf
[Definition]
journalmatch = _SYSTEMD_UNIT=ssh.service + _SYSTEMD_UNIT=sshd.service
EOF
    cat >"$F2B_DIR/filter.d/panelalpha-sftp.conf" <<'EOF'
# Written by the PanelAlpha engine: the SFTP container's sshd (facility local5).
[INCLUDES]
before = sshd.conf
[Definition]
journalmatch = SYSLOG_IDENTIFIER=sshd SYSLOG_FACILITY=21
EOF
    cat >"$F2B_DIR/filter.d/panelalpha-ftp.conf" <<'EOF'
# Written by the PanelAlpha engine: the FTP container's pure-ftpd.
[INCLUDES]
before = pure-ftpd.conf
[Init]
journalmatch = SYSLOG_IDENTIFIER=pure-ftpd
EOF
    cat >"$F2B_DIR/filter.d/panelalpha-api.conf" <<'EOF'
# Written by the PanelAlpha engine: a request to the engine API or MCP that
# its token did not get through (401), from core's :2011 access log.
[Definition]
failregex = ^<HOST> \S+ \S+ \[[^\]]*\] "[A-Z]+ /(?:api|mcp)\S* HTTP/[^"]*" 401
ignoreregex =
EOF
    cat >"$F2B_DIR/filter.d/panelalpha-site-password.conf" <<'EOF'
# Written by the PanelAlpha engine: a wrong password on a password-protected
# site, from sites-http's site-password.log, which logs nothing else.
[Definition]
failregex = ^<HOST> \[[^]]*] "[^"]*" site-password 401 "
ignoreregex =
EOF
}

# The ufw lock is waited for less than fail2ban's 60 s action timeout.
write_f2b_ban_action() {
    cat >"$F2B_DIR/action.d/panelalpha-ufw.conf" <<EOF
# Written by the PanelAlpha engine: a ban is a ufw deny rule for the host and a
# route deny rule for the ports Docker publishes; lifting it removes both, each
# pair under the lock every ufw write on the host takes. They are deleted by
# their comment: ufw skips a ban on an address an operator's rule already
# denies, and a delete that names no comment takes that rule instead.
[Definition]
actionstart =
actionstop =
actioncheck =
actionban = <ufwlock>
            ufw prepend deny from <ip> to any comment "<comment>"
            ufw route prepend deny from <ip> to any comment "<comment>"
actionunban = <ufwlock>
              ufw delete deny from <ip> to any comment "<comment>"
              ufw route delete deny from <ip> to any comment "<comment>"

[Init]
comment = by Fail2Ban after <failures> attempts against <name>
ufwlock = exec 9>>"$UFW_LOCK"; flock -w 50 9 || { echo "panelalpha-ufw: no ufw lock ($UFW_LOCK) after 50 s" >&2; exit 1; }
EOF
}

write_f2b_jails() { # <ignoreip> <docker subnets> <api log> <site-password log> <api ignoreip>
    local ignore=$1 containers=$2 api_log=$3 site_log=$4 api_ignore=$5
    cat >"$F2B_DIR/jail.d/panelalpha.local" <<EOF
# Written by the PanelAlpha engine (scripts/firewall/ufw.sh) on every install
# and update, and when the firewall API changes the trusted addresses. Those
# are in $F2B_DIR/panelalpha-ignoreip, one per line.
#
# Bans are ufw deny rules, so the firewall API lists them and removing one
# lifts the ban: one for the host and a route rule for the ports Docker
# publishes (action.d/panelalpha-ufw.conf).
[DEFAULT]
banaction = panelalpha-ufw
banaction_allports = panelalpha-ufw
bantime = 1h
bantime.increment = true
bantime.maxtime = 1w
findtime = 10m
maxretry = 5
ignoreip = ${ignore}

[sshd]
enabled = true
filter = panelalpha-sshd
backend = systemd
# Repeated from the filter: Debian 13's jail.d/defaults-debian.conf sets one on
# [sshd], which would win and count the SFTP container's sshd here as well.
journalmatch = _SYSTEMD_UNIT=ssh.service + _SYSTEMD_UNIT=sshd.service
port = $(ssh_ports | sed 's/ $//; s/ /,/g')

[sftp]
enabled = true
filter = panelalpha-sftp
backend = systemd
port = 2222

[ftp]
enabled = true
filter = panelalpha-ftp
backend = systemd
port = 21

# A client with a stale token retries; it takes more than an SSH guess. No
# increment: after a few bans it bans on one 401, and a client whose token was
# just replaced (PanelAlpha Connect) was then locked out for days.
[engine-api]
enabled = true
filter = panelalpha-api
logpath = $api_log
backend = auto
port = 2011
maxretry = 10
bantime.increment = false
# A jail's own ignoreip replaces [DEFAULT]'s, so it repeats it, then adds
# PanelAlpha Connect's caller: SSH, SFTP and FTP can still ban that address.
ignoreip = ${api_ignore}

# Any site's visitors, often many behind one address, and a ban closes the
# whole host to them. No increment: a fixed hour is enough to stop guessing.
[site-password]
enabled = true
filter = panelalpha-site-password
logpath = $site_log
backend = auto
port = 80,443
maxretry = 10
bantime.increment = false
ignoreip = ${ignore}${containers:+ ${containers}}
EOF
}

# A new ban action needs a restart, which puts the bans back through it: a
# reload would leave a running jail without one. A changed trusted list alone
# reloads and keeps every ban; otherwise the jails restart, repairing a bare one.
reload_fail2ban() { # <ban actions before> <trusted, or "">
    if ! systemctl is-active --quiet fail2ban || [ "$1" != "$(ban_actions)" ]; then
        systemctl restart fail2ban || warn "fail2ban did not start; see journalctl -u fail2ban"
    elif [ "$2" = trusted ]; then
        fail2ban-client reload >/dev/null || warn "fail2ban did not reload; see journalctl -u fail2ban"
    else
        fail2ban-client reload --restart >/dev/null || warn "fail2ban did not reload; see journalctl -u fail2ban"
    fi
}

# What fail2ban bans with: the jail's ban action and the engine's action file.
ban_actions() {
    grep '^banaction' "$F2B_DIR/jail.d/panelalpha.local" 2>/dev/null
    cat "$F2B_DIR/action.d/panelalpha-ufw.conf" 2>/dev/null
}

# What ends the hook: ufw's default for routed traffic, but never an accept --
# only a route rule opens a published port.
routed_policy() {
    case "$(sed -n 's/^DEFAULT_FORWARD_POLICY="*\([A-Z]*\).*/\1/p' "$UFW_DEFAULTS" 2>/dev/null)" in
    REJECT) echo REJECT ;;
    *) echo DROP ;;
    esac
}

# The Docker hook, PA-PUBLISHED in DOCKER-USER for new connections Docker
# DNATed; established ones never enter it. Connections from Docker's own
# bridges are left alone: those are containers reaching a published port on
# the host's address, which the tenant and build chains decide.
published_on() {
    local ipt policy
    policy=$(routed_policy)
    for ipt in iptables ip6tables; do
        hook_published "$ipt" "$policy"
    done
}

# The hook for one of iptables and ip6tables, once ufw's chains are there. An
# IPv4 DOCKER-USER is made before Docker starts at boot; Docker keeps it.
hook_published() { # <iptables|ip6tables> <policy>
    local ipt=$1 prefix=ufw
    [ "$ipt" = ip6tables ] && prefix=ufw6
    "$ipt" -n -L "$prefix-user-forward" >/dev/null 2>&1 || return 0
    if ! "$ipt" -n -L DOCKER-USER >/dev/null 2>&1; then
        [ "$ipt" = iptables ] || return 0
        "$ipt" -N DOCKER-USER || return 0
    fi
    rebuild_published_chain "$ipt" "$prefix" "$2" || return 0
    jump_to_published_chain "$ipt"
}

# PA-PUBLISHED replaced in one iptables-restore, so a rebuild never leaves
# published ports unfiltered. Fails when it could not be built and is not there.
rebuild_published_chain() { # <ipt> <ufw prefix> <policy>
    local log
    log=$(ufw_logging_chain "$1" "$2")
    published_chain_rules "$2" "$log" "$3" | "$1-restore" -w 30 --noflush && return 0
    warn "$1: could not rebuild $CHAIN; published ports keep the rules they had"
    "$1" -n -L "$CHAIN" >/dev/null 2>&1
}

# ufw's chain that logs its own default drops ([UFW BLOCK], rate-limited), so
# refused published ports are logged as well. Asked before iptables-restore,
# which holds the xtables lock this call (-w) would wait for.
ufw_logging_chain() { # <ipt> <ufw prefix>
    "$1" -n -L "$2-logging-deny" >/dev/null 2>&1 && echo "$2-logging-deny"
}

# PA-PUBLISHED as iptables-restore input: Docker's bridges return, then ufw's
# route rules, the logging chain if any, and the policy.
published_chain_rules() { # <ufw prefix> <logging chain, or ""> <policy>
    echo "*filter"
    echo ":$CHAIN - [0:0]"
    echo "-A $CHAIN -i docker0 -j RETURN"
    echo "-A $CHAIN -i br-+ -j RETURN"
    echo "-A $CHAIN -j $1-user-forward"
    [ -z "$2" ] || echo "-A $CHAIN -j $2"
    echo "-A $CHAIN -j $3"
    echo COMMIT
}

# The jump goes after the tenant network's rules, which judge an account's
# traffic first. One found above them is added again below them before the
# old one goes, so the hook is never missing.
jump_to_published_chain() { # <ipt>
    local last mine
    read -r last mine < <("$1" -S DOCKER-USER 2>/dev/null | awk -v c="$CHAIN" '
        $1 == "-A" { n++ }
        $1 == "-A" && $NF == "PA-TENANT-NET" { last = n }
        $1 == "-A" && $NF == c && !mine { mine = n }
        END { print last + 0, mine + 0 }')
    if ! "$1" -C DOCKER-USER "${PUBLISHED_JUMP[@]}" 2>/dev/null; then
        "$1" -I DOCKER-USER $((last + 1)) "${PUBLISHED_JUMP[@]}"
    elif [ "$mine" -lt "$last" ]; then
        "$1" -I DOCKER-USER $((last + 1)) "${PUBLISHED_JUMP[@]}" && "$1" -D DOCKER-USER "${PUBLISHED_JUMP[@]}"
    fi
}

published_off() {
    local ipt
    for ipt in iptables ip6tables; do
        while "$ipt" -D DOCKER-USER "${PUBLISHED_JUMP[@]}" 2>/dev/null; do :; done
        "$ipt" -F "$CHAIN" 2>/dev/null
        "$ipt" -X "$CHAIN" 2>/dev/null
    done
    return 0
}

# Every stored rule iptables would refuse, one per line: its tuple line (or the
# rule file and line, for one outside any rule), a tab, and iptables' error.
# `--test` only parses, which is where a rule ufw stores but cannot load fails
# (port 08080); each refused rule is set aside and the rest tested again.
refused_rules() {
    [ ! -f "$UFW_DIR/user.rules" ] || refused_in iptables "$UFW_DIR/user.rules"
    [ ! -f "$UFW_DIR/user6.rules" ] || refused_in ip6tables "$UFW_DIR/user6.rules"
    return 0
}

# refused_rules for one rule file, through <iptables|ip6tables>-restore.
refused_in() { # <iptables|ip6tables> <file>
    local left error line start tries
    left=$(mktemp) || return 1
    cp "$2" "$left"
    tries=$(grep -c '^### tuple ###' "$left")
    while ! error=$(command "$1-restore" -w 30 --test -n <"$left" 2>&1 >/dev/null); do
        line=$(restore_error_line "$error")
        start=$(tuple_at "$left" "$line")
        if [ -z "$start" ] || [ "$tries" -le 0 ]; then
            printf '%s%s\t%s\n' "$2" "${line:+ line $line}" "$(restore_error "$error")"
            break
        fi
        printf '%s\t%s\n' "$(sed -n "${start}p" "$left")" "$(restore_error "$error")"
        drop_tuple "$left" "$start"
        tries=$((tries - 1))
    done
    rm -f "$left"
}

restore_error_line() { printf '%s\n' "$1" | sed -n 's/^Error occurred at line: \([0-9][0-9]*\).*/\1/p' | head -n 1; }
restore_error() { printf '%s\n' "$1" | sed -n '1{s/^[^(]*([^)]*): //;p;}'; }

# The line of the `### tuple ###` whose iptables lines hold line <n>, or nothing.
tuple_at() { # <file> <n>
    [ -n "$2" ] || return 0
    awk -v n="$2" '/^### tuple ###/ { t = NR; next } !/^-/ { t = "" } NR == n { print t; exit }' "$1"
}

# Takes the rule whose tuple line is line <start>, with its iptables lines, out of <file>.
drop_tuple() { # <file> <start>
    awk -v s="$2" 'NR == s { skip = 1; next } skip && /^-/ { next } { skip = 0; print }' "$1" >"$1.left" &&
        mv "$1.left" "$1"
}

# ufw does not start with a rule iptables refuses, and once CSF's uninstaller has
# run the host would be left with no firewall: says which rules, and fails.
rules_load() {
    local refused rule why
    refused=$(refused_rules)
    [ -z "$refused" ] && return 0
    while IFS=$'\t' read -r rule why; do
        warn "iptables refuses the stored rule ${rule#"### tuple ### "}: $why"
    done <<<"$refused"
    return 1
}

# What is left to do after rules_load failed: delete the refused rules first.
rules_refused() { # <1 when CSF is still installed>
    local delete="delete them ('ufw status numbered', then 'ufw delete <number>')"
    if [ -n "$1" ]; then
        warn "ufw would not start with them, so CSF is left in place: $delete and run the install again"
    else
        warn "ufw would not start with them: $delete, then run 'ufw enable'"
    fi
}

# CSF goes and ufw comes on, under one hold of the ufw lock. The stored rules
# are test-loaded first, so a rule iptables refuses keeps CSF in place. After
# CSF's uninstaller only `ufw enable` is left to fail; ufw is then turned off
# rather than leave the host closed, so it has no firewall at all: that is said
# in so many words. An active ufw loads nothing on enable, so ufw_guarded checks
# that its rules are loaded.
switch_on() {
    local had_csf=
    [ -f "$CSF_DIR/csf.conf" ] && had_csf=1
    rules_load || {
        rules_refused "$had_csf"
        return 1
    }
    CSF_BACKUP=
    remove_csf
    ufw_guarded ufw --force enable >/dev/null && return 0
    if [ -n "$had_csf" ]; then
        local saved=""
        [ -z "$CSF_BACKUP" ] || saved="; CSF's configuration is in $CSF_BACKUP"
        warn "CSF is uninstalled but ufw did not start: this host has NO firewall. Run 'ufw enable'$saved"
    else
        warn "ufw did not start; run 'ufw enable'"
    fi
    return 1
}

not_set_up() {
    if [ -f "$CSF_DIR/csf.conf" ]; then
        warn "ufw could not be set up; CSF is left in place"
    else
        warn "ufw could not be set up"
    fi
}

# Nothing of CSF is touched until ufw is configured and holds its rules; any
# failure before that leaves CSF running as it was.
install() {
    install_packages || {
        warn "could not install ufw and fail2ban"
        return 1
    }
    import_from_csf || {
        warn "CSF's rules could not be moved to ufw; CSF is left in place"
        return 1
    }
    with_ufw_lock ufw_guarded configure_ufw || {
        not_set_up
        return 1
    }
    configure_fail2ban
    with_ufw_lock ufw_guarded mirror_bans || {
        not_set_up
        return 1
    }
    if [ -f "$UFW_DIR/.panelalpha-keep-disabled" ]; then
        keep_ufw_off
        return
    fi
    with_ufw_lock switch_on || return 1
    published_on
    say "ufw is on; open ports: $(managed_rules | awk '{ printf "%s%s/%s", sep, $1, $2; sep = " " }')"
}

# CSF had been turned off on this host (csf.disable), so ufw is left off too.
keep_ufw_off() {
    with_ufw_lock remove_csf || return 1
    say "CSF was disabled on this host, so ufw is left off: run 'ufw enable' to turn it on"
    rm -f "$UFW_DIR/.panelalpha-keep-disabled"
    return 0
}

# What core runs when it starts. The engine's route rules are put back here as
# well as in install: a host updated without the installer must not lose the
# engine's ports when the hook moves to route rules.
apply() {
    local rc=0
    clear_csf_leftover
    if ufw_is_ours; then with_ufw_lock ufw_guarded route_rules || rc=1; fi
    published_on
    return $rc
}

# Without the engine the hooks would call a script that is gone, and ufw cannot
# reload while the Docker hook still jumps into its chains. ufw stays on.
unhook() {
    published_off
    rm -f "$UFW_DIR/before.init" "$UFW_DIR/after.init"
    say "the engine's ufw hooks removed; ufw and its rules are left as they are"
}

ufw_off() { ufw --force disable >/dev/null 2>&1 || true; }

uninstall() {
    unhook
    with_ufw_lock ufw_off
    rm -f "$F2B_DIR/jail.d/panelalpha.local" "$F2B_DIR/action.d/panelalpha-ufw.conf"
    systemctl restart fail2ban >/dev/null 2>&1 || true
    say "ufw disabled and the engine's fail2ban jail removed"
}

# Sourced by ufw.test.sh for its functions.
[ "${BASH_SOURCE[0]}" = "$0" ] || return 0

case "${1:-}" in
install) install ;;
apply) apply ;;
published-on) published_on ;;
published-off) published_off ;;
fail2ban) configure_fail2ban trusted ;;
unhook) unhook ;;
uninstall) uninstall ;;
refused) refused_rules ;;
*)
    echo "Usage: $0 install|apply|fail2ban|unhook|uninstall|refused|published-on|published-off" >&2
    exit 1
    ;;
esac
