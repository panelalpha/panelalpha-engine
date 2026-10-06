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
BACKUP_DIR=${PA_BACKUP_DIR:-/var/backups}
SELF="$ENGINE_DIR/scripts/firewall/ufw.sh"
CHAIN=PA-PUBLISHED
MANAGED="panelalpha:"
# ufw rewrites user.rules whole on every call, so two writers at once lose or
# resurrect each other's rules. Every ufw write takes this lock: this script's,
# core's (App\System\Firewall\Ufw\UfwFirewall::LOCK) and fail2ban's ban action.
UFW_LOCK=${PA_UFW_LOCK:-$ENGINE_DIR/data/ufw.lock}
UFW_LOCK_WAIT=${PA_UFW_LOCK_WAIT:-30}

say() { echo "firewall: $*"; }
trim() { printf '%s' "$1" | sed 's/^[[:space:]]*//; s/[[:space:]]*$//'; }
warn() { echo "firewall: $*" >&2; }
# iptables-legacy gives up at once ("Another app is currently holding the
# xtables lock") while Docker, ufw or fail2ban is changing rules, which left
# the Docker hook half built; -w waits for it, for at most 30 s so that ufw's
# start at boot cannot hang on it. iptables-nft has no lock and ignores -w.
iptables() { command iptables -w 30 "$@"; }
ip6tables() { command ip6tables -w 30 "$@"; }

# Runs "$@" holding the ufw lock, or says why not and fails. Never around
# fail2ban-client: fail2ban's ban action waits for the same lock.
with_ufw_lock() {
    if [ -n "${UFW_LOCKED:-}" ]; then
        "$@"
        return
    fi
    mkdir -p "$(dirname "$UFW_LOCK")"
    (
        flock -w "$UFW_LOCK_WAIT" 9 || {
            warn "another ufw change held $UFW_LOCK for over ${UFW_LOCK_WAIT}s; nothing was changed"
            exit 1
        }
        UFW_LOCKED=1
        "$@"
    ) 9>>"$UFW_LOCK"
    local rc=$?
    # core opens it as www-data.
    chmod 0644 "$UFW_LOCK" 2>/dev/null
    return $rc
}

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

is_address() {
    echo "$1" | grep -qE '^([0-9]{1,3}\.){3}[0-9]{1,3}(/[0-9]{1,2})?$|^[0-9A-Fa-f]*:[0-9A-Fa-f:.]*(/[0-9]{1,3})?$'
}

# One csf.allow / csf.deny line into UFW_ARGS (the words after `ufw prepend`),
# and for a bare address, which CSF applied both ways, UFW_ARGS_OUT as well:
# the API lists the two as one rule in both directions.
# Fails for comments, blank lines, the entries the engine itself wrote, lfd's
# own automatic blocks (fail2ban takes over from lfd and starts empty) and
# anything ufw cannot express, which is reported.
UFW_ARGS=()
UFW_ARGS_OUT=()
csf_line_to_ufw() { # <allow|deny> <line>
    local action=$1 line=${2%$'\r'} rule comment="" proto dir pspec aspec extra
    local from=any to=any sport="" dport="" addr ports
    UFW_ARGS=()
    UFW_ARGS_OUT=()
    rule=$(trim "${line%%#*}")
    case "$line" in *'#'*) comment=$(trim "${line#*#}") ;; esac
    [ -n "$rule" ] || return 1
    case "$comment" in *'docker internal network'* | lfd:* | 'lfd '*) return 1 ;; esac
    case "$rule" in
    [Ii]nclude*)
        warn "not moved, ufw has no include: $line"
        return 1
        ;;
    esac

    if [ "${rule#*|}" = "$rule" ]; then
        if ! is_address "$rule"; then
            warn "not moved, not an address: $line"
            return 1
        fi
        UFW_ARGS=("$action" in from "$rule" to any)
        UFW_ARGS_OUT=("$action" out from any to "$rule")
    else
        IFS='|' read -r proto dir pspec aspec extra <<<"$rule"
        if [ -n "$extra" ] || ! echo "$proto" | grep -qE '^(tcp|udp)$' || ! echo "$dir" | grep -qE '^(in|out)$'; then
            warn "not moved, unrecognised rule: $line"
            return 1
        fi
        addr=${aspec#?=}
        ports=$(echo "${pspec#?=}" | tr '_' ':')
        case "$aspec" in
        u=*)
            warn "not moved, ufw cannot match a user id: $line"
            return 1
            ;;
        s=*) from=$addr ;;
        d=*) to=$addr ;;
        *)
            warn "not moved, unrecognised address: $line"
            return 1
            ;;
        esac
        is_address "$addr" || {
            warn "not moved, not an address: $line"
            return 1
        }
        case "$pspec" in
        s=*) sport=$ports ;;
        d=*) dport=$ports ;;
        *)
            warn "not moved, unrecognised port: $line"
            return 1
            ;;
        esac
        UFW_ARGS=("$action" "$dir" proto "$proto" from "$from")
        [ -n "$sport" ] && UFW_ARGS+=(port "$sport")
        UFW_ARGS+=(to "$to")
        [ -n "$dport" ] && UFW_ARGS+=(port "$dport")
    fi
    if [ -n "$comment" ]; then
        UFW_ARGS+=(comment "$comment")
        [ ${#UFW_ARGS_OUT[@]} -gt 0 ] && UFW_ARGS_OUT+=(comment "$comment")
    fi
    return 0
}

# Each rule goes on top; called for csf.deny first, then csf.allow, so the
# allows end up above the denies and win, as they did under CSF.
import_csf_file() { # <allow|deny> <file>
    local action=$1 file=$2 line moved=0 out
    [ -f "$file" ] || return 0
    while IFS= read -r line || [ -n "$line" ]; do
        csf_line_to_ufw "$action" "$line" || continue
        if out=$(ufw prepend "${UFW_ARGS[@]}" 2>&1) && ! echo "$out" | grep -qi '^error' &&
            { [ ${#UFW_ARGS_OUT[@]} = 0 ] || { out=$(ufw prepend "${UFW_ARGS_OUT[@]}" 2>&1) && ! echo "$out" | grep -qi '^error'; }; }; then
            moved=$((moved + 1))
        else
            warn "not moved: $line ($out)"
        fi
    done <"$file"
    say "moved $moved rule(s) from $(basename "$file")"
}

# Addresses CSF trusted (csf.ignore, and every bare address in csf.allow) are
# kept out of fail2ban's bans, as lfd kept them.
import_csf_ignores() {
    local file line addr
    for file in "$CSF_DIR/csf.ignore" "$CSF_DIR/csf.allow"; do
        [ -f "$file" ] || continue
        while IFS= read -r line || [ -n "$line" ]; do
            case "$line" in *'docker internal network'*) continue ;; esac
            addr=$(trim "${line%%#*}")
            [ -n "$addr" ] && is_address "$addr" && echo "$addr"
        done <"$file"
    done | sort -u >>"$F2B_DIR/panelalpha-ignoreip"
}

# An old core container still bind-mounting /etc/csf recreates it when Docker
# restarts it after the move; core runs apply once it is recreated without
# that mount. An empty one always goes. One with anything in it, csf.conf
# included, goes once the migration has finished (its backup is there) and
# nothing of CSF is installed or running: a csf.conf left then would only make
# every later install migrate again. Otherwise it is kept and the reason said.
clear_csf_leftover() {
    [ -d "$CSF_DIR" ] || return 0
    if rmdir "$CSF_DIR" 2>/dev/null; then
        say "removed the empty $CSF_DIR CSF left behind"
        return 0
    fi
    local why
    if ! compgen -G "$BACKUP_DIR/panelalpha-csf-*.tgz" >/dev/null; then
        why="no $BACKUP_DIR/panelalpha-csf-*.tgz, so the move from CSF never finished"
    elif ! why=$(csf_present); then
        rm -rf "$CSF_DIR"
        say "removed $CSF_DIR, left behind after the move from CSF"
        return 0
    fi
    say "kept $CSF_DIR: $why"
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
migrate_from_csf() {
    clear_csf_leftover
    [ -f "$CSF_DIR/csf.conf" ] || return 0
    local backup
    say "replacing CSF with ufw"
    mkdir -p "$BACKUP_DIR"
    backup="$BACKUP_DIR/panelalpha-csf-$(date +%Y%m%d%H%M%S).tgz"
    if tar -czf "$backup" -C "$(dirname "$CSF_DIR")" "$(basename "$CSF_DIR")" 2>/dev/null; then
        say "CSF's configuration is saved in $backup"
    fi

    with_ufw_lock import_csf_file deny "$CSF_DIR/csf.deny"
    with_ufw_lock import_csf_file allow "$CSF_DIR/csf.allow"
    mkdir -p "$F2B_DIR"
    import_csf_ignores
    [ -f "$CSF_DIR/csf.disable" ] && touch "$UFW_DIR/.panelalpha-keep-disabled"

    if [ -f "$CSF_DIR/uninstall.sh" ]; then
        sh "$CSF_DIR/uninstall.sh" >/dev/null 2>&1 || true
    elif [ -f "$CSF_SRC/uninstall.sh" ]; then
        sh "$CSF_SRC/uninstall.sh" >/dev/null 2>&1 || true
    fi
    systemctl disable --now lfd csf >/dev/null 2>&1 || true
    systemctl reset-failed lfd csf >/dev/null 2>&1 || true
    rm -rf "$CSF_SRC" "$CSF_DIR"
    if [ -f "$ENGINE_DIR/.env" ]; then
        sed -i '/^CSF_UI=/d; /^CSF_UI_PASSWORD=/d' "$ENGINE_DIR/.env"
    fi
    say "CSF uninstalled"
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
    local port proto label
    if [ -f "$UFW_DEFAULTS" ]; then
        sed -i 's/^IPV6=.*/IPV6=yes/; s/^MANAGE_BUILTINS=.*/MANAGE_BUILTINS=no/' "$UFW_DEFAULTS"
    fi
    ufw default deny incoming >/dev/null
    ufw default allow outgoing >/dev/null
    ufw default deny routed >/dev/null
    # The firewall API reads what was blocked from ufw's log; an operator's
    # own level (medium, high) is kept.
    if grep -q '^LOGLEVEL=off' "$UFW_DIR/ufw.conf" 2>/dev/null; then
        ufw logging low >/dev/null
    fi
    while read -r port proto label; do
        ufw allow proto "$proto" from any to any port "$port" comment "$MANAGED $label" >/dev/null ||
            warn "could not open $port/$proto ($label)"
    done < <(managed_rules)
    route_rules

    # ufw runs these on start and stop; see the header.
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
# Bans are mirror_bans' job. Each one is reported.
convert_published() {
    local ports rules action proto dport dst sport src dir hex comment words key
    local -A seen=()
    ports=$(published_container_ports)
    # Read before ufw starts rewriting the files.
    rules=$(tuples)
    while read -r action proto dport dst sport src dir hex; do
        case "$action" in allow | deny | reject) ;; *) continue ;; esac
        [ "$dir" = in ] || continue
        comment=
        [ "$hex" = - ] || comment=$(unhex "$hex")
        case "$comment" in "$MANAGED"* | 'by Fail2Ban'*) continue ;; esac
        if [ "$action" = allow ]; then
            [ -n "$ports" ] && printf '%s\n' "$ports" | covers_published "$proto" "$dport" || continue
        fi
        words=(route)
        [ "$action" = allow ] || words+=(prepend)
        words+=("$action")
        [ "$proto" = any ] || words+=(proto "$proto")
        words+=(from "$(any "$src")")
        [ "$sport" = any ] || words+=(port "$sport")
        words+=(to "$(any "$dst")")
        [ "$dport" = any ] || words+=(port "$dport")
        key="${words[*]}"
        [ -z "${seen[$key]:-}" ] || continue
        seen[$key]=1
        [ -n "$comment" ] && words+=(comment "$comment")
        if ufw "${words[@]}" </dev/null >/dev/null; then
            say "carried over to published ports: ufw ${words[*]}"
        else
            warn "could not carry over to published ports: ufw ${words[*]}"
        fi
    done <<<"$rules"
}

# The engine's own published ports as route rules (see the header), put back
# when missing; the first time they go in, the operator's rules are carried over.
route_rules() {
    local port proto label have=0
    while read -r port proto label; do
        has_route_allow "$port" "$proto" && have=1
    done < <(managed_route_rules)
    [ "$have" = 1 ] || convert_published
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
# The stock filters match by process name, which would count the SFTP
# container's sshd as the host's; the engine's own filters match the source.
# Trusted addresses (the firewall API, CSF's old allow and ignore lists) are in
# $F2B_DIR/panelalpha-ignoreip, one per line, "# comment" after it.
configure_fail2ban() { # [trusted]: only the trusted list changed
    local ignore="127.0.0.1/8 ::1" api_log="$ENGINE_DIR/logs/core/nginx/api-access.log" actions
    mkdir -p "$F2B_DIR/jail.d" "$F2B_DIR/filter.d" "$F2B_DIR/action.d" "$(dirname "$api_log")"
    actions=$(ban_actions)
    touch "$F2B_DIR/panelalpha-ignoreip" "$api_log"
    ignore="$ignore $(awk '!/^[[:space:]]*(#|$)/ { print $1 }' "$F2B_DIR/panelalpha-ignoreip" | sort -u | tr '\n' ' ')"

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

    # The lock is waited for less than fail2ban's 60 s action timeout.
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
ignoreip = ${ignore% }

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

# A client with a stale token retries; it takes more than an SSH guess.
[engine-api]
enabled = true
filter = panelalpha-api
logpath = $api_log
backend = auto
port = 2011
maxretry = 10
EOF
    systemctl enable fail2ban >/dev/null 2>&1 || true
    # A plain reload keeps a running jail but drops its action when the action
    # changed: the sshd jail fail2ban starts with on install (nftables or
    # iptables) was left with none, logging bans it never applied. A new ban
    # action gets a restart, which puts the bans back through it. A changed
    # trusted list alone gets a plain reload, which keeps every ban: a restart
    # lifts them all and puts each back, two ufw writes apiece, for a minute on
    # a busy host. Otherwise the jails are restarted, which also repairs one an
    # earlier reload left bare.
    if ! systemctl is-active --quiet fail2ban || [ "$actions" != "$(ban_actions)" ]; then
        systemctl restart fail2ban || warn "fail2ban did not start; see journalctl -u fail2ban"
    elif [ "${1:-}" = trusted ]; then
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
# the host's address, which the tenant and build chains decide. The chain is
# replaced in one iptables-restore, so a rebuild never leaves published ports
# unfiltered.
published_on() {
    local ipt prefix policy last mine log
    local jump=(-m conntrack --ctstate NEW -m conntrack --ctstate DNAT -j "$CHAIN")
    policy=$(routed_policy)
    for ipt in iptables ip6tables; do
        prefix=ufw
        [ "$ipt" = ip6tables ] && prefix=ufw6
        "$ipt" -n -L "$prefix-user-forward" >/dev/null 2>&1 || continue
        if ! "$ipt" -n -L DOCKER-USER >/dev/null 2>&1; then
            # Before Docker starts at boot; Docker keeps a DOCKER-USER it finds.
            [ "$ipt" = iptables ] || continue
            "$ipt" -N DOCKER-USER || continue
        fi
        # Logged as ufw logs its own default drops ([UFW BLOCK], rate-limited),
        # so the firewall's log shows refused published ports too. Asked here:
        # inside the pipe below, iptables-restore already holds the xtables lock
        # that this call (-w) would wait for.
        log=""
        if "$ipt" -n -L "$prefix-logging-deny" >/dev/null 2>&1; then
            log="$prefix-logging-deny"
        fi
        if ! {
            echo "*filter"
            echo ":$CHAIN - [0:0]"
            echo "-A $CHAIN -i docker0 -j RETURN"
            echo "-A $CHAIN -i br-+ -j RETURN"
            echo "-A $CHAIN -j $prefix-user-forward"
            [ -z "$log" ] || echo "-A $CHAIN -j $log"
            echo "-A $CHAIN -j $policy"
            echo COMMIT
        } | "$ipt-restore" -w 30 --noflush; then
            warn "$ipt: could not rebuild $CHAIN; published ports keep the rules they had"
            "$ipt" -n -L "$CHAIN" >/dev/null 2>&1 || continue
        fi
        # After the tenant network's rules, which judge an account's traffic first.
        read -r last mine < <("$ipt" -S DOCKER-USER 2>/dev/null | awk -v c="$CHAIN" '
            $1 == "-A" { n++ }
            $1 == "-A" && $NF == "PA-TENANT-NET" { last = n }
            $1 == "-A" && $NF == c && !mine { mine = n }
            END { print last + 0, mine + 0 }')
        if ! "$ipt" -C DOCKER-USER "${jump[@]}" 2>/dev/null; then
            "$ipt" -I DOCKER-USER $((last + 1)) "${jump[@]}"
        elif [ "$mine" -lt "$last" ]; then
            # Added first, then the old one goes: never a moment without it.
            "$ipt" -I DOCKER-USER $((last + 1)) "${jump[@]}" && "$ipt" -D DOCKER-USER "${jump[@]}"
        fi
    done
}

published_off() {
    local ipt
    for ipt in iptables ip6tables; do
        while "$ipt" -D DOCKER-USER -m conntrack --ctstate NEW -m conntrack --ctstate DNAT -j "$CHAIN" 2>/dev/null; do :; done
        "$ipt" -F "$CHAIN" 2>/dev/null
        "$ipt" -X "$CHAIN" 2>/dev/null
    done
    return 0
}

install() {
    install_packages || {
        warn "could not install ufw and fail2ban"
        return 1
    }
    migrate_from_csf
    with_ufw_lock configure_ufw || return 1
    configure_fail2ban
    with_ufw_lock mirror_bans || return 1
    if [ -f "$UFW_DIR/.panelalpha-keep-disabled" ]; then
        say "CSF was disabled on this host, so ufw is left off: run 'ufw enable' to turn it on"
        rm -f "$UFW_DIR/.panelalpha-keep-disabled"
        return 0
    fi
    # Enabling an active ufw reloads it, which applies the hooks above.
    with_ufw_lock ufw --force enable >/dev/null
    published_on
    say "ufw is on; open ports: $(managed_rules | awk '{ printf "%s%s/%s", sep, $1, $2; sep = " " }')"
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
apply)
    # Here as well as in install: a host updated without the installer must
    # not lose the engine's ports when the hook moves to route rules.
    clear_csf_leftover
    rc=0
    if ufw_is_ours; then with_ufw_lock route_rules || rc=1; fi
    published_on
    exit $rc
    ;;
published-on) published_on ;;
published-off) published_off ;;
fail2ban) configure_fail2ban trusted ;;
unhook) unhook ;;
uninstall) uninstall ;;
*)
    echo "Usage: $0 install|apply|fail2ban|unhook|uninstall|published-on|published-off" >&2
    exit 1
    ;;
esac
