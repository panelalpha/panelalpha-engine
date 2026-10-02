#!/usr/bin/env bash
# ufw as the engine host's firewall; scripts/firewall.sh runs it.
#
#   ufw.sh install         packages, CSF migration, defaults, the engine's ports,
#                          the Docker hook, fail2ban; then enable. Idempotent.
#   ufw.sh apply           the Docker hook only (core runs it when it starts)
#   ufw.sh fail2ban        rewrite fail2ban's jails and reload it (trusted list changed)
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
# every published port is open to the world whatever ufw says. The hook sends
# every new connection Docker DNATed from outside through ufw's user rules from
# DOCKER-USER, and ends in ufw's default incoming policy. So a published port
# is open only while a ufw rule allows it, and a deny rule -- or a fail2ban
# ban -- covers it too. ufw's rules see the container's port, after the DNAT;
# every port the engine publishes is the same on both sides for that reason
# (SFTP listens on 2222 inside its container too).
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

say() { echo "firewall: $*"; }
trim() { printf '%s' "$1" | sed 's/^[[:space:]]*//; s/[[:space:]]*$//'; }
warn() { echo "firewall: $*" >&2; }

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

# CSF is replaced, not kept beside ufw: its rules move to ufw, its config is
# saved, and it is uninstalled. Its uninstaller flushes the whole ruleset,
# Docker's chains included; the installers restart Docker right after this.
# A host where CSF had been turned off (csf.disable) keeps ufw off as well.
migrate_from_csf() {
    # An old core container still bind-mounting /etc/csf recreates it, empty,
    # when Docker restarts it after the move; the next run clears that.
    [ -d "$CSF_DIR" ] && [ ! -f "$CSF_DIR/csf.conf" ] && rmdir "$CSF_DIR" 2>/dev/null
    [ -f "$CSF_DIR/csf.conf" ] || return 0
    local backup
    say "replacing CSF with ufw"
    mkdir -p "$BACKUP_DIR"
    backup="$BACKUP_DIR/panelalpha-csf-$(date +%Y%m%d%H%M%S).tgz"
    if tar -czf "$backup" -C "$(dirname "$CSF_DIR")" "$(basename "$CSF_DIR")" 2>/dev/null; then
        say "CSF's configuration is saved in $backup"
    fi

    import_csf_file deny "$CSF_DIR/csf.deny"
    import_csf_file allow "$CSF_DIR/csf.allow"
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

# fail2ban bans an address after failed logins on what the engine serves:
#   sshd        the host's SSH, from the journal (ssh.service / sshd.service)
#   sftp        the SFTP container's sshd: it logs to the host's journal as local5
#   ftp         pure-ftpd, which logs to the host's journal through /dev/log
#   engine-api  failed tokens on :2011, from core's api-access.log
# The stock filters match by process name, which would count the SFTP
# container's sshd as the host's; the engine's own filters match the source.
# Trusted addresses (the firewall API, CSF's old allow and ignore lists) are in
# $F2B_DIR/panelalpha-ignoreip, one per line, "# comment" after it.
configure_fail2ban() {
    local ignore="127.0.0.1/8 ::1" api_log="$ENGINE_DIR/logs/core/nginx/api-access.log"
    mkdir -p "$F2B_DIR/jail.d" "$F2B_DIR/filter.d" "$(dirname "$api_log")"
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

    cat >"$F2B_DIR/jail.d/panelalpha.local" <<EOF
# Written by the PanelAlpha engine (scripts/firewall/ufw.sh) on every install
# and update, and when the firewall API changes the trusted addresses. Those
# are in $F2B_DIR/panelalpha-ignoreip, one per line.
#
# Bans are ufw deny rules, so the firewall API lists them and removing one
# lifts the ban. Through the engine's Docker hook they cover the ports Docker
# publishes as well.
[DEFAULT]
banaction = ufw[blocktype=deny]
banaction_allports = ufw[blocktype=deny]
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
    systemctl reload-or-restart fail2ban || warn "fail2ban did not start; see journalctl -u fail2ban"
}

# ufw's DEFAULT_INPUT_POLICY, as the target that ends the hook.
default_policy() {
    case "$(sed -n 's/^DEFAULT_INPUT_POLICY="*\([A-Z]*\).*/\1/p' "$UFW_DEFAULTS" 2>/dev/null)" in
    ACCEPT) echo RETURN ;;
    REJECT) echo REJECT ;;
    *) echo DROP ;;
    esac
}

# The Docker hook. Connections from Docker's own bridges are left alone: those
# are containers reaching a published port on the host's address, which the
# tenant and build chains decide.
published_on() {
    local ipt prefix policy
    policy=$(default_policy)
    for ipt in iptables ip6tables; do
        prefix=ufw
        [ "$ipt" = ip6tables ] && prefix=ufw6
        "$ipt" -n -L "$prefix-user-input" >/dev/null 2>&1 || continue
        if ! "$ipt" -n -L DOCKER-USER >/dev/null 2>&1; then
            # Before Docker starts at boot; Docker keeps a DOCKER-USER it finds.
            [ "$ipt" = iptables ] || continue
            "$ipt" -N DOCKER-USER || continue
        fi
        "$ipt" -N "$CHAIN" 2>/dev/null || "$ipt" -F "$CHAIN"
        "$ipt" -A "$CHAIN" -i docker0 -j RETURN
        "$ipt" -A "$CHAIN" -i br-+ -j RETURN
        "$ipt" -A "$CHAIN" -j "$prefix-user-input"
        # Logged as ufw logs its own default drops ([UFW BLOCK], rate-limited),
        # so the firewall's log shows refused published ports too.
        if [ "$policy" != RETURN ] && "$ipt" -n -L "$prefix-logging-deny" >/dev/null 2>&1; then
            "$ipt" -A "$CHAIN" -j "$prefix-logging-deny"
        fi
        "$ipt" -A "$CHAIN" -j "$policy"
        "$ipt" -C DOCKER-USER -m conntrack --ctstate NEW -m conntrack --ctstate DNAT -j "$CHAIN" 2>/dev/null ||
            "$ipt" -I DOCKER-USER -m conntrack --ctstate NEW -m conntrack --ctstate DNAT -j "$CHAIN"
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
    configure_ufw
    configure_fail2ban
    if [ -f "$UFW_DIR/.panelalpha-keep-disabled" ]; then
        say "CSF was disabled on this host, so ufw is left off: run 'ufw enable' to turn it on"
        rm -f "$UFW_DIR/.panelalpha-keep-disabled"
        return 0
    fi
    # Enabling an active ufw reloads it, which applies the hooks above.
    ufw --force enable >/dev/null
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

uninstall() {
    unhook
    ufw --force disable >/dev/null 2>&1 || true
    rm -f "$F2B_DIR/jail.d/panelalpha.local"
    systemctl restart fail2ban >/dev/null 2>&1 || true
    say "ufw disabled and the engine's fail2ban jail removed"
}

# Sourced by ufw.test.sh for its functions.
[ "${BASH_SOURCE[0]}" = "$0" ] || return 0

case "${1:-}" in
install) install ;;
apply) published_on ;;
published-on) published_on ;;
published-off) published_off ;;
fail2ban) configure_fail2ban ;;
unhook) unhook ;;
uninstall) uninstall ;;
*)
    echo "Usage: $0 install|apply|fail2ban|unhook|uninstall|published-on|published-off" >&2
    exit 1
    ;;
esac
