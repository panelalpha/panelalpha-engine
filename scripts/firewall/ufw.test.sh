#!/bin/bash
# Exercises scripts/firewall/ufw.sh against fake ufw, iptables, apt and
# systemctl: moving CSF's rules over, the ports the engine opens, the Docker
# hook and fail2ban's jail. Run by core's FirewallScriptTest.
set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
W="$(mktemp -d)"
trap 'rm -rf "$W"' EXIT
mkdir -p "$W/bin"

# Every fake appends "<name> <args>" to $W/calls.
for cmd in systemctl ss fail2ban-client; do
    printf '#!/bin/bash\necho "%s $*" >>"%s/calls"\n' "$cmd" "$W" >"$W/bin/$cmd"
done
# systemctl says fail2ban is stopped while $W/f2b-down exists.
# csf and lfd are running while $W/running-<unit> exists, and loaded while $W/loaded-<unit> does.
cat >"$W/bin/systemctl" <<FAKE
#!/bin/bash
echo "systemctl \$*" >>"$W/calls"
case "\$*" in
"is-active --quiet csf" | "is-active --quiet lfd") [ -f "$W/running-\$3" ] ;;
"show -p LoadState --value csf" | "show -p LoadState --value lfd") [ -f "$W/loaded-\$5" ] && echo loaded || echo not-found ;;
*) [ "\$1" != is-active ] || [ ! -f "$W/f2b-down" ] ;;
esac
FAKE
# ufw refuses any command matching the regex in $W/ufw-refuses.
cat >"$W/bin/ufw" <<FAKE
#!/bin/bash
echo "ufw \$*" >>"$W/calls"
if [ -f "$W/ufw-refuses" ] && echo "\$*" | grep -qE "\$(cat "$W/ufw-refuses")"; then
    echo "ERROR: Bad rule"
    exit 1
fi
FAKE
# dpkg knows every package unless $W/dpkg-missing; apt-get fails with $W/apt-fails.
printf '#!/bin/bash\necho "dpkg $*" >>"%s/calls"\n[ ! -f "%s/dpkg-missing" ]\n' "$W" "$W" >"$W/bin/dpkg"
printf '#!/bin/bash\necho "apt-get $*" >>"%s/calls"\n[ ! -f "%s/apt-fails" ]\n' "$W" "$W" >"$W/bin/apt-get"
printf '#!/bin/bash\nprintf "port 22\\nport 2200\\n"\n' >"$W/bin/sshd"
# iptables knows the chains listed in $W/chains; -N adds one. -S prints and -C
# looks in $W/<name>-<chain>, the chain's rules as -S prints them.
cat >"$W/bin/iptables" <<FAKE
#!/bin/bash
# -w 30 (wait for the xtables lock) is recorded apart; a call without it in no-wait.
if [ "\$1 \$2" = "-w 30" ]; then shift 2; else echo "\$(basename "\$0") \$*" >>"$W/no-wait"; fi
echo "\$(basename "\$0") \$*" >>"$W/calls"
rules="$W/\$(basename "\$0")-\$2"
case "\$1 \$2" in
"-n -L") grep -qx "\$3" "$W/chains" 2>/dev/null ;;
"-N "*) echo "\$2" >>"$W/chains" ;;
"-S "*) cat "\$rules" 2>/dev/null ;;
"-C "*) shift; chain=\$1; shift; grep -qxF -- "-A \$chain \$*" "\$rules" 2>/dev/null ;;
"-D "*) exit 1 ;;
esac
FAKE
cp "$W/bin/iptables" "$W/bin/ip6tables"
# iptables-restore keeps its input in $W/restore-<name>, adds the chains it
# declares, and fails with $W/restore-fails.
cat >"$W/bin/iptables-restore" <<FAKE
#!/bin/bash
echo "\$(basename "\$0") \$*" >>"$W/calls"
cat >"$W/restore-\$(basename "\$0" -restore)"
[ ! -f "$W/restore-fails" ] || exit 1
sed -n 's/^:\([^ ]*\) .*/\1/p' "$W/restore-\$(basename "\$0" -restore)" >>"$W/chains"
FAKE
cp "$W/bin/iptables-restore" "$W/bin/ip6tables-restore"
# docker ps prints $W/docker-ports, the Ports column of each container.
printf '#!/bin/bash\necho "docker $*" >>"%s/calls"\ncat "%s/docker-ports" 2>/dev/null || true\n' "$W" "$W" >"$W/bin/docker"
chmod +x "$W/bin/"*

failures=0
expect() { # expect <label> <expected> <actual>
    if [ "$2" = "$3" ]; then
        echo "PASS: $1"
    else
        echo "FAIL: $1 -- expected '$2', got '$3'"
        failures=$((failures + 1))
    fi
}
reset() {
    rm -rf "$W/calls" "$W/chains" "$W/etc" "$W/engine" "$W/backups" "$W/src-csf" \
        "$W/ufw-refuses" "$W/dpkg-missing" "$W/apt-fails" "$W"/restore-* "$W"/iptables-* "$W"/ip6tables-* \
        "$W/docker-ports" "$W/f2b-down" "$W/no-wait" "$W"/running-* "$W"/loaded-* "$W/bin/csf"
    mkdir -p "$W/etc/ufw" "$W/etc/fail2ban" "$W/engine/scripts/firewall" "$W/backups"
    printf 'IPV6=no\nDEFAULT_INPUT_POLICY="DROP"\nMANAGE_BUILTINS=yes\n' >"$W/etc/default-ufw"
    printf 'COMPOSE_PROFILES=full\nCSF_UI=0\nCSF_UI_PASSWORD=secret\n' >"$W/engine/.env"
}
csf() { # a CSF install with the given csf.allow / csf.deny / csf.ignore bodies
    mkdir -p "$W/etc/csf"
    echo 'TESTING = "0"' >"$W/etc/csf/csf.conf"
    printf '%b' "$1" >"$W/etc/csf/csf.allow"
    printf '%b' "$2" >"$W/etc/csf/csf.deny"
    printf '%b' "${3:-}" >"$W/etc/csf/csf.ignore"
    printf '#!/bin/sh\necho "csf-uninstall" >>"%s/calls"\n' "$W" >"$W/etc/csf/uninstall.sh"
}
env_run() {
    PATH="$W/bin:$PATH" PA_ENGINE_DIR="$W/engine" PA_UFW_DIR="$W/etc/ufw" \
        PA_UFW_DEFAULTS="$W/etc/default-ufw" PA_FAIL2BAN_DIR="$W/etc/fail2ban" \
        PA_CSF_DIR="$W/etc/csf" PA_CSF_SRC="$W/src-csf" PA_BACKUP_DIR="$W/backups" SSH_CONNECTION="" "$@"
}
run() { env_run bash "$DIR/ufw.sh" "$@" >"$W/out" 2>&1; echo $?; }
calls() { grep -c -- "$1" "$W/calls" 2>/dev/null; }
line_of() { grep -n -m1 -- "$1" "$W/calls" | cut -d: -f1; }
restored() { grep -cxF -- "$1" "$W/restore-${2:-iptables}" 2>/dev/null; }
restored_line() { grep -nxF -m1 -- "$1" "$W/restore-iptables" | cut -d: -f1; }
hex() { printf '%s' "$1" | od -An -tx1 | tr -d ' \n'; }
# user.rules / user6.rules with the given tuple lines (after "### tuple ###").
rules() { printf '### tuple ### %s\n' "$@" >"$W/etc/ufw/user.rules"; }
rules6() { printf '### tuple ### %s\n' "$@" >"$W/etc/ufw/user6.rules"; }
# The engine's route rules, as ufw writes them, so they count as there.
ENGINE_ROUTES=("route:allow tcp 2011 0.0.0.0/0 any 0.0.0.0/0 in comment=$(hex 'panelalpha: engine api')"
    "route:allow tcp 21 0.0.0.0/0 any 0.0.0.0/0 in comment=$(hex 'panelalpha: ftp')"
    "route:allow tcp 30000:30009 0.0.0.0/0 any 0.0.0.0/0 in comment=$(hex 'panelalpha: ftp passive')"
    "route:allow tcp 2222 0.0.0.0/0 any 0.0.0.0/0 in comment=$(hex 'panelalpha: sftp')")

# One csf line, through the same function the migration uses.
convert() { # convert <allow|deny> <line> -> the ufw words, or SKIP
    env_run bash -c 'source "$1"; if csf_line_to_ufw "$2" "$3" 2>/dev/null; then echo "${UFW_ARGS[*]}${UFW_ARGS_OUT[*]:+ + ${UFW_ARGS_OUT[*]}}"; else echo SKIP; fi' _ "$DIR/ufw.sh" "$1" "$2"
}

expect "a bare address, both ways as in CSF" "allow in from 1.2.3.4 to any comment office + allow out from any to 1.2.3.4 comment office" "$(convert allow '1.2.3.4 # office')"
expect "a range" "deny in from 10.0.0.0/8 to any + deny out from any to 10.0.0.0/8" "$(convert deny '10.0.0.0/8')"
expect "IPv6" "deny in from 2001:db8::/32 to any + deny out from any to 2001:db8::/32" "$(convert deny '2001:db8::/32')"
expect "inbound port from an address" "allow in proto tcp from 1.2.3.4 to any port 22" "$(convert allow 'tcp|in|d=22|s=1.2.3.4')"
expect "CSF's port range spelling" "allow in proto tcp from 10.0.0.0/8 to any port 30000:30009" "$(convert allow 'tcp|in|d=30000_30009|s=10.0.0.0/8')"
expect "outbound to an address" "deny out proto tcp from any to 1.2.3.4 port 3306" "$(convert deny 'tcp|out|d=3306|d=1.2.3.4')"
expect "a source port" "allow in proto udp from 8.8.8.8 port 53 to any" "$(convert allow 'udp|in|s=53|s=8.8.8.8')"
expect "the engine's old docker line is dropped" "SKIP" "$(convert allow '172.25.0.0/24 # docker internal network')"
expect "lfd's automatic blocks are dropped" "SKIP" "$(convert deny '5.6.7.8 # lfd: (sshd) Failed SSH login from 5.6.7.8')"
expect "a manual deny is kept" "deny in from 5.6.7.8 to any comment Manually denied: spam + deny out from any to 5.6.7.8 comment Manually denied: spam" "$(convert deny '5.6.7.8 # Manually denied: spam')"
expect "a uid rule cannot move" "SKIP" "$(convert allow 'tcp|out|d=25|u=1000')"
expect "an include cannot move" "SKIP" "$(convert allow 'Include /etc/csf/cpanel.allow')"
expect "a hostname cannot move" "SKIP" "$(convert allow 'example.com')"
expect "a comment line" "SKIP" "$(convert allow '# tcp|in|d=22|s=1.2.3.4')"
expect "a CRLF line" "allow in from 1.2.3.4 to any + allow out from any to 1.2.3.4" "$(convert allow $'1.2.3.4\r')"
expect "an unknown protocol" "SKIP" "$(convert allow 'icmp|in|d=22|s=1.2.3.4')"
expect "an unknown direction" "SKIP" "$(convert allow 'tcp|both|d=22|s=1.2.3.4')"
expect "a fifth field" "SKIP" "$(convert allow 'tcp|in|d=22|s=1.2.3.4|x')"
expect "an unknown address prefix" "SKIP" "$(convert allow 'tcp|in|d=22|x=1.2.3.4')"
expect "a hostname in an advanced rule" "SKIP" "$(convert allow 'tcp|in|d=22|s=example.com')"
expect "an unknown port prefix" "SKIP" "$(convert allow 'tcp|in|p=22|s=1.2.3.4')"

# A host on CSF moves to ufw.
reset
csf '1.2.3.4 # office\n172.25.0.0/24 # docker internal network\n' '5.6.7.8 # Manually denied\n9.9.9.9 # lfd: (sshd) Failed SSH login\n' '203.0.113.5\n'
expect "a CSF host: installed" "0" "$(run install)"
expect "deny moved on top" "1" "$(calls 'ufw prepend deny in from 5.6.7.8 to any comment Manually denied')"
expect "both ways" "1" "$(calls 'ufw prepend deny out from any to 5.6.7.8 comment Manually denied')"
expect "allow moved on top, after the denies" "1" "$([ "$(line_of 'ufw prepend allow in from 1.2.3.4')" -gt "$(line_of 'ufw prepend deny')" ] && echo 1)"
expect "lfd's block left behind" "0" "$(calls 9.9.9.9)"
expect "the docker line left behind" "0" "$(calls 172.25.0.0)"
expect "CSF's own uninstaller ran" "1" "$(calls csf-uninstall)"
expect "CSF's config is backed up" "1" "$(ls "$W/backups" | grep -c '^panelalpha-csf-.*\.tgz$')"
expect "and removed" "no" "$([ -d "$W/etc/csf" ] && echo yes || echo no)"
expect "CSF UI settings leave .env" "0" "$(grep -c CSF_UI "$W/engine/.env")"
expect "trusted addresses are never banned" "203.0.113.5 1.2.3.4" "$(sort -r "$W/etc/fail2ban/panelalpha-ignoreip" | tr '\n' ' ' | sed 's/ $//')"
expect "rules move before CSF goes" "1" "$([ "$(line_of 'ufw prepend')" -lt "$(line_of csf-uninstall)" ] && echo 1)"
expect "ufw is enabled after CSF is gone" "1" "$([ "$(line_of 'ufw --force enable')" -gt "$(line_of csf-uninstall)" ] && echo 1)"

# The engine's ports and ufw's defaults.
expect "every sshd port stays open" "2" "$(grep -cE 'ufw allow proto tcp from any to any port (22|2200) comment panelalpha: ssh' "$W/calls")"
for p in "80 comment panelalpha: http" "443 comment panelalpha: https" "2011 comment panelalpha: engine api" "21 comment panelalpha: ftp" "30000:30009 comment panelalpha: ftp passive" "2222 comment panelalpha: sftp"; do
    expect "port ${p%% *} is opened and marked" "1" "$(calls "ufw allow proto tcp from any to any port $p")"
done
expect "incoming denied by default" "1" "$(calls 'ufw default deny incoming')"
expect "logging left as the operator set it" "0" "$(calls 'ufw logging')"
expect "ufw leaves Docker's chains alone" "MANAGE_BUILTINS=no" "$(grep MANAGE_BUILTINS "$W/etc/default-ufw")"
expect "and covers IPv6" "IPV6=yes" "$(grep IPV6 "$W/etc/default-ufw")"
expect "the hooks are executable" "yes" "$([ -x "$W/etc/ufw/before.init" ] && [ -x "$W/etc/ufw/after.init" ] && echo yes)"
expect "before.init takes the hook down" "1" "$(grep -c "$W/engine/scripts/firewall/ufw.sh published-off" "$W/etc/ufw/before.init")"
expect "after.init puts it back" "1" "$(grep -c "$W/engine/scripts/firewall/ufw.sh published-on" "$W/etc/ufw/after.init")"
expect "after.init restores Docker's FORWARD policy" "1" "$(grep -c 'iptables -P FORWARD DROP' "$W/etc/ufw/after.init")"

# fail2ban.
jail="$W/etc/fail2ban/jail.d/panelalpha.local"
expect "bans are the engine's ufw action" "2" "$(grep -cE '^banaction(_allports)? = panelalpha-ufw$' "$jail")"
section() { awk -v s="[$1]" '$0 == s { on = 1; next } /^\[/ { on = 0 } on' "$jail"; }
expect "sshd jail on every ssh port" "port = 22,2200" "$(section sshd | grep '^port')"
expect "the host's sshd only" "filter = panelalpha-sshd" "$(section sshd | grep '^filter')"
expect "sftp jail" "filter = panelalpha-sftp|backend = systemd|port = 2222" "$(section sftp | grep -E '^(filter|port|backend)' | tr '\n' '|' | sed 's/|$//')"
expect "ftp jail" "filter = panelalpha-ftp|backend = systemd|port = 21" "$(section ftp | grep -E '^(filter|port|backend)' | tr '\n' '|' | sed 's/|$//')"
expect "engine API jail on its own log" "logpath = $W/engine/logs/core/nginx/api-access.log" "$(section engine-api | grep '^logpath')"
expect "which exists before fail2ban looks" "yes" "$([ -f "$W/engine/logs/core/nginx/api-access.log" ] && echo yes)"
expect "the API takes more retries" "maxretry = 10" "$(section engine-api | grep '^maxretry')"
expect "the host's sshd is told apart from the SFTP container's" "1" "$(grep -c '^journalmatch = _SYSTEMD_UNIT=ssh.service + _SYSTEMD_UNIT=sshd.service$' "$W/etc/fail2ban/filter.d/panelalpha-sshd.conf")"
expect "and the jail says it too, over a distribution's own [sshd]" "journalmatch = _SYSTEMD_UNIT=ssh.service + _SYSTEMD_UNIT=sshd.service" "$(section sshd | grep '^journalmatch')"
expect "SFTP is facility local5" "1" "$(grep -c '^journalmatch = SYSLOG_IDENTIFIER=sshd SYSLOG_FACILITY=21$' "$W/etc/fail2ban/filter.d/panelalpha-sftp.conf")"
expect "FTP by its syslog name" "1" "$(grep -c '^journalmatch = SYSLOG_IDENTIFIER=pure-ftpd$' "$W/etc/fail2ban/filter.d/panelalpha-ftp.conf")"
expect "the API filter counts 401s on /api and /mcp" "1" "$(grep -c '"\[A-Z\]+ /(?:api|mcp)' "$W/etc/fail2ban/filter.d/panelalpha-api.conf")"
expect "with CSF's trusted addresses ignored" "1" "$(grep -c '^ignoreip = 127.0.0.1/8 ::1 1.2.3.4 203.0.113.5$' "$jail")"
expect "fail2ban restarted, its ban action being new" "1:0" "$(calls 'systemctl restart fail2ban'):$(calls 'fail2ban-client reload')"

# ufw logging switched off: the firewall log needs it.
reset
echo 'LOGLEVEL=off' >"$W/etc/ufw/ufw.conf"
run install >/dev/null
expect "logging off is turned back on" "1" "$(calls 'ufw logging low')"

# Trusted addresses written by the API carry a comment; fail2ban gets the address.
reset
mkdir -p "$W/etc/fail2ban"
printf '# trusted\n198.51.100.7 # office\n\n2001:db8::/32 # vpn\n198.51.100.7\n' >"$W/etc/fail2ban/panelalpha-ignoreip"
expect "fail2ban alone: done" "0" "$(run fail2ban)"
expect "comments stay out of ignoreip" "ignoreip = 127.0.0.1/8 ::1 198.51.100.7 2001:db8::/32" "$(grep '^ignoreip' "$W/etc/fail2ban/jail.d/panelalpha.local")"
expect "and nothing else is touched" "0" "$(calls '^ufw')"
rm -f "$W/calls"
run fail2ban >/dev/null
expect "the same ban action again: the jails restarted, which also repairs one a reload left bare" "1:0" "$(calls 'fail2ban-client reload --restart'):$(calls 'systemctl restart fail2ban')"
sed -i 's/^banaction = .*/banaction = ufw[blocktype=deny]/' "$W/etc/fail2ban/jail.d/panelalpha.local"
rm -f "$W/calls"
run fail2ban >/dev/null
expect "a jail on the old action: a restart, which a reload would leave without actions" "0:1" "$(calls 'fail2ban-client reload'):$(calls 'systemctl restart fail2ban')"
touch "$W/f2b-down"
rm -f "$W/calls"
run fail2ban >/dev/null
expect "a stopped fail2ban is started instead" "1|0" "$(calls 'systemctl restart fail2ban')|$(calls 'fail2ban-client reload')"

# CSF turned off by the operator: ufw stays off.
reset
csf '' ''
touch "$W/etc/csf/csf.disable"
expect "a disabled CSF host: installed" "0" "$(run install)"
expect "ufw is not enabled" "0" "$(calls 'ufw --force enable')"
expect "and the operator is told" "1" "$(grep -c "ufw is left off" "$W/out")"

# The empty /etc/csf an old core container's bind mount leaves behind.
reset
mkdir -p "$W/etc/csf"
expect "an empty CSF dir: installed" "0" "$(run install)"
expect "and the dir is cleared" "no" "$([ -d "$W/etc/csf" ] && echo yes || echo no)"
# It reappears after install's Docker restart; core's apply on the new container clears it.
reset
mkdir -p "$W/etc/csf"
expect "an empty CSF dir: applied" "0" "$(run apply)"
expect "apply clears it" "no" "$([ -d "$W/etc/csf" ] && echo yes || echo no)"
expect "and says so" "1" "$(grep -c "removed the empty $W/etc/csf" "$W/out")"
# Anything left in it goes once the move has finished (its backup is there)
# and nothing of CSF is installed or running.
leftover() { reset; mkdir -p "$W/etc/csf"; echo leftover >"$W/etc/csf/notes.txt"; }
gone() { [ -d "$W/etc/csf" ] && echo kept || echo removed; }
leftover
touch "$W/backups/panelalpha-csf-20261005101409.tgz"
expect "a non-empty CSF dir after the move: applied" "0" "$(run apply)"
expect "is removed" "removed" "$(gone)"
expect "and says so" "1" "$(grep -c "removed $W/etc/csf, left behind after the move from CSF" "$W/out")"
leftover
expect "no CSF backup: applied" "0" "$(run apply)"
expect "is kept" "kept" "$(gone)"
expect "and says why" "1" "$(grep -c "kept $W/etc/csf: no $W/backups/panelalpha-csf-\*.tgz" "$W/out")"
expect "install keeps it too, without a migration" "kept|0" "$(run install >/dev/null; gone)|$(calls csf-uninstall)"
leftover
touch "$W/backups/panelalpha-csf-20261005101409.tgz"
printf '#!/bin/sh\nexit 0\n' >"$W/bin/csf" && chmod +x "$W/bin/csf"
run apply >/dev/null
expect "a csf binary still there: kept" "kept" "$(gone)"
expect "and says why" "1" "$(grep -c "kept $W/etc/csf: csf is still installed" "$W/out")"
leftover
touch "$W/backups/panelalpha-csf-20261005101409.tgz" "$W/running-lfd"
run apply >/dev/null
expect "lfd still running: kept" "kept|1" "$(gone)|$(grep -c 'lfd is still running' "$W/out")"
leftover
touch "$W/backups/panelalpha-csf-20261005101409.tgz" "$W/loaded-csf"
run apply >/dev/null
expect "the csf service still installed: kept" "kept|1" "$(gone)|$(grep -c 'the csf service is still installed' "$W/out")"
# A csf.conf left after the move goes too, or every later install would migrate again.
leftover
echo 'TESTING = "0"' >"$W/etc/csf/csf.conf"
touch "$W/backups/panelalpha-csf-20261005101409.tgz"
expect "csf.conf after the move: applied" "0" "$(run apply)"
expect "is removed" "removed" "$(gone)"
leftover
echo 'TESTING = "0"' >"$W/etc/csf/csf.conf"
touch "$W/backups/panelalpha-csf-20261005101409.tgz"
run install >/dev/null
expect "install removes it instead of migrating again" "removed|0" "$(gone)|$(grep -c 'replacing CSF with ufw' "$W/out")"
leftover
echo 'TESTING = "0"' >"$W/etc/csf/csf.conf"
run apply >/dev/null
expect "csf.conf with no backup: kept" "kept|1" "$(gone)|$(grep -c "kept $W/etc/csf: no $W/backups/panelalpha-csf-\*.tgz" "$W/out")"
leftover
echo 'TESTING = "0"' >"$W/etc/csf/csf.conf"
touch "$W/backups/panelalpha-csf-20261005101409.tgz"
printf '#!/bin/sh\nexit 0\n' >"$W/bin/csf" && chmod +x "$W/bin/csf"
run apply >/dev/null
expect "csf.conf with a csf binary: kept" "kept|1" "$(gone)|$(grep -c "kept $W/etc/csf: csf is still installed" "$W/out")"
# A host still on CSF is not touched by apply.
reset
csf '' ''
run apply >/dev/null
expect "apply leaves a live CSF alone" "yes" "$([ -f "$W/etc/csf/csf.conf" ] && echo yes || echo no)"

# A rule ufw refuses is reported and the move goes on.
reset
csf '1.2.3.4\n5.6.7.8\n' ''
echo 'from 5\.6\.7\.8' >"$W/ufw-refuses"
expect "a refused rule: installed anyway" "0" "$(run install)"
expect "the refusal is reported" "1" "$(grep -c 'not moved: 5.6.7.8' "$W/out")"
expect "and the rest moved" "1" "$(grep -c 'moved 1 rule(s) from csf.allow' "$W/out")"

reset
csf '9.8.7.6 # both\n' ''
echo 'out from any to 9\.8\.7\.6' >"$W/ufw-refuses"
run install >/dev/null
expect "a refused outbound half is reported" "1" "$(grep -c 'not moved: 9.8.7.6' "$W/out")"

# CSF installed with only its unpacked source holding the uninstaller.
reset
csf '' ''
rm -f "$W/etc/csf/uninstall.sh"
mkdir -p "$W/src-csf"
printf '#!/bin/sh\necho "csf-src-uninstall" >>"%s/calls"\n' "$W" >"$W/src-csf/uninstall.sh"
run install >/dev/null
expect "the uninstaller in CSF's source is used" "1" "$(calls csf-src-uninstall)"
expect "and the source is removed" "no" "$([ -d "$W/src-csf" ] && echo yes || echo no)"

# A missing package is installed; an install that fails stops before ufw is touched.
reset
touch "$W/dpkg-missing"
run install >/dev/null
expect "a missing package is installed" "1" "$(calls 'apt-get -o DPkg::Lock::Timeout=300 install -y python3-systemd')"
reset
touch "$W/dpkg-missing" "$W/apt-fails"
expect "a failed package install fails the setup" "1" "$(run install)"
expect "and says so" "1" "$(grep -c 'could not install ufw and fail2ban' "$W/out")"
expect "before ufw is enabled" "0" "$(calls 'ufw --force enable')"

# An engine port ufw refuses is reported, not fatal.
reset
echo 'port 2011' >"$W/ufw-refuses"
expect "a refused engine port: installed anyway" "0" "$(run install)"
expect "the port is named" "1" "$(grep -c 'could not open 2011/tcp (engine api)' "$W/out")"

# A host without CSF.
reset
expect "a fresh host: installed" "0" "$(run install)"
expect "nothing to uninstall" "0" "$(calls csf-uninstall)"
expect "no backup" "0" "$(ls "$W/backups" | wc -l)"
expect "ufw enabled" "1" "$(calls 'ufw --force enable')"

# The Docker hook: what Docker DNATed from outside meets ufw's route rules, then a drop.
reset
printf 'ufw-user-forward\nDOCKER-USER\n' >"$W/chains"
expect "hook applied" "0" "$(run published-on)"
expect "the chain in one restore, added to what is there, once the xtables lock is free" "1" "$(calls 'iptables-restore -w 30 --noflush')"
expect "containers on Docker's bridges are left to their own chains" "2" "$(grep -cxE -- '-A PA-PUBLISHED -i (docker0|br-\+) -j RETURN' "$W/restore-iptables")"
expect "then ufw's route rules" "1" "$(restored '-A PA-PUBLISHED -j ufw-user-forward')"
expect "never the host's own rules" "0" "$(grep -c 'ufw-user-input' "$W/restore-iptables")"
expect "then a drop" "1" "$(restored '-A PA-PUBLISHED -j DROP')"
expect "the drop comes last" "1" "$([ "$(restored_line '-A PA-PUBLISHED -j DROP')" -gt "$(restored_line '-A PA-PUBLISHED -j ufw-user-forward')" ] && echo 1)"
expect "no log jump while ufw logs nothing" "0" "$(restored '-A PA-PUBLISHED -j ufw-logging-deny')"
expect "only new connections Docker DNATed, first when nothing else is there" "1" "$(calls 'iptables -I DOCKER-USER 1 -m conntrack --ctstate NEW -m conntrack --ctstate DNAT -j PA-PUBLISHED')"

reset
printf 'ufw-user-forward\nDOCKER-USER\nufw-logging-deny\n' >"$W/chains"
run published-on >/dev/null
expect "a refused published port is logged as ufw logs its own" "1" "$(restored '-A PA-PUBLISHED -j ufw-logging-deny')"
expect "before the drop" "1" "$([ "$(restored_line '-A PA-PUBLISHED -j DROP')" -gt "$(restored_line '-A PA-PUBLISHED -j ufw-logging-deny')" ] && echo 1)"
expect "no IPv6 hook without ufw6's chain" "0" "$(calls 'ip6tables-restore')"
expect "every iptables call waits for the xtables lock" "0" "$(cat "$W/no-wait" 2>/dev/null | wc -l)"
expect "the log chain is asked before the restore, which holds the lock" "1" "$([ "$(line_of 'iptables -n -L ufw-logging-deny')" -lt "$(line_of 'iptables-restore')" ] && echo 1)"

reset
printf 'ufw-user-forward\nDOCKER-USER\nufw6-user-forward\n' >"$W/chains"
run published-on >/dev/null
expect "IPv6 gets the same, from ufw6's route rules" "1" "$(restored '-A PA-PUBLISHED -j ufw6-user-forward' ip6tables)"
expect "and its own hook" "1" "$(calls 'ip6tables -I DOCKER-USER 1 -m conntrack --ctstate NEW -m conntrack --ctstate DNAT -j PA-PUBLISHED')"

# The tenant network's rules stay first.
reset
printf 'ufw-user-forward\nDOCKER-USER\n' >"$W/chains"
printf '%s\n' '-N DOCKER-USER' '-A DOCKER-USER -i br-pa-build -j PA-BUILD-EGRESS' '-A DOCKER-USER -o br-pa-tenants -j PA-TENANT-NET' \
    '-A DOCKER-USER -i br-pa-tenants -j PA-TENANT-NET' >"$W/iptables-DOCKER-USER"
run published-on >/dev/null
expect "the hook goes in after the tenant network's rules" "1" "$(calls 'iptables -I DOCKER-USER 4 -m conntrack --ctstate NEW -m conntrack --ctstate DNAT -j PA-PUBLISHED')"

reset
printf 'ufw-user-forward\nDOCKER-USER\n' >"$W/chains"
printf '%s\n' '-N DOCKER-USER' '-A DOCKER-USER -m conntrack --ctstate NEW -m conntrack --ctstate DNAT -j PA-PUBLISHED' \
    '-A DOCKER-USER -o br-pa-tenants -j PA-TENANT-NET' '-A DOCKER-USER -i br-pa-tenants -j PA-TENANT-NET' >"$W/iptables-DOCKER-USER"
run published-on >/dev/null
expect "a hook above them moves below" "1" "$(calls 'iptables -I DOCKER-USER 4 -m conntrack --ctstate NEW -m conntrack --ctstate DNAT -j PA-PUBLISHED')"
expect "the new one in before the old one goes" "1" "$([ "$(line_of 'iptables -D DOCKER-USER -m conntrack')" -gt "$(line_of 'iptables -I DOCKER-USER 4')" ] && echo 1)"

reset
printf 'ufw-user-forward\nDOCKER-USER\n' >"$W/chains"
printf '%s\n' '-N DOCKER-USER' '-A DOCKER-USER -o br-pa-tenants -j PA-TENANT-NET' '-A DOCKER-USER -i br-pa-tenants -j PA-TENANT-NET' \
    '-A DOCKER-USER -m conntrack --ctstate NEW -m conntrack --ctstate DNAT -j PA-PUBLISHED' >"$W/iptables-DOCKER-USER"
run published-on >/dev/null
expect "a hook in place is left as it is" "0" "$(calls 'iptables -[ID] DOCKER-USER')"

reset
printf 'ufw-user-forward\nDOCKER-USER\n' >"$W/chains"
touch "$W/restore-fails"
run published-on >/dev/null
expect "a restore iptables refuses is reported" "1" "$(grep -c 'could not rebuild PA-PUBLISHED' "$W/out")"
expect "and nothing jumps into a chain that is not there" "0" "$(calls 'iptables -I DOCKER-USER')"

reset
sed -i 's/^DEFAULT_INPUT_POLICY=.*/DEFAULT_INPUT_POLICY="ACCEPT"/' "$W/etc/default-ufw"
echo 'DEFAULT_FORWARD_POLICY="ACCEPT"' >>"$W/etc/default-ufw"
printf 'ufw-user-forward\nDOCKER-USER\n' >"$W/chains"
run published-on >/dev/null
expect "an accepting default never opens a published port" "1" "$(restored '-A PA-PUBLISHED -j DROP')"
expect "nor returns it to Docker" "0" "$(restored '-A PA-PUBLISHED -j RETURN')"

reset
echo 'DEFAULT_FORWARD_POLICY="REJECT"' >>"$W/etc/default-ufw"
printf 'ufw-user-forward\nDOCKER-USER\n' >"$W/chains"
run published-on >/dev/null
expect "a rejecting routed default rejects published ports" "1" "$(restored '-A PA-PUBLISHED -j REJECT')"

# The engine's own published ports as route rules, put in by install and apply.
reset
expect "install: done" "0" "$(run install)"
for p in "2011 comment panelalpha: engine api" "21 comment panelalpha: ftp" "30000:30009 comment panelalpha: ftp passive" "2222 comment panelalpha: sftp"; do
    expect "published port ${p%% *} gets a managed route rule" "1" "$(calls "ufw route allow proto tcp from any to any port $p")"
done
expect "host ports get none" "0" "$(grep -cE 'ufw route allow .*port (22|80|443) ' "$W/calls")"

reset
rules "${ENGINE_ROUTES[@]}" "allow tcp 2011 0.0.0.0/0 any 0.0.0.0/0 in comment=$(hex 'panelalpha: engine api')"
run install >/dev/null
expect "route rules already there are not added again" "0" "$(calls 'ufw route')"
expect "nor is anything carried over" "0" "$(calls 'docker ps')"

reset
rules "${ENGINE_ROUTES[@]:1}" "allow tcp 2011 0.0.0.0/0 any 0.0.0.0/0 in comment=$(hex 'panelalpha: engine api')"
printf 'ufw-user-forward\nDOCKER-USER\n' >"$W/chains"
expect "apply: done" "0" "$(run apply)"
expect "apply puts a missing one back" "1" "$(calls 'ufw route allow proto tcp from any to any port 2011 comment panelalpha: engine api')"
expect "and only that one" "1" "$(calls 'ufw route')"
expect "before the hook moves to route rules" "1" "$([ "$(line_of 'iptables-restore')" -gt "$(line_of 'ufw route allow')" ] && echo 1)"

reset
rules "allow tcp 22 0.0.0.0/0 any 0.0.0.0/0 in"
printf 'ufw-user-forward\nDOCKER-USER\n' >"$W/chains"
run apply >/dev/null
expect "apply leaves a ufw the engine did not set up alone" "0" "$(calls '^ufw')"
expect "and still hooks published ports" "1" "$(calls 'iptables-restore')"

# Upgrading: the operator's rules that reached a published container port, at
# the container's port, are carried over once.
upgrade() { # <docker ports> <tuple lines...>
    reset
    printf '%s\n' "$1" >"$W/docker-ports"
    shift
    rules "allow tcp 2011 0.0.0.0/0 any 0.0.0.0/0 in comment=$(hex 'panelalpha: engine api')" "$@"
    printf 'ufw-user-forward\nDOCKER-USER\n' >"$W/chains"
    run apply >/dev/null
}
upgrade '0.0.0.0:28081->8080/tcp, [::]:28081->8080/tcp' "allow tcp 8080 0.0.0.0/0 any 0.0.0.0/0 in comment=$(hex 'my app')" \
    "allow tcp 9090 0.0.0.0/0 any 0.0.0.0/0 in"
expect "an allow for a published container port becomes a route rule" "1" "$(calls 'ufw route allow proto tcp from any to any port 8080 comment my app')"
expect "and is reported" "1" "$(grep -c 'carried over to published ports: ufw route allow proto tcp from any to any port 8080 comment my app' "$W/out")"
expect "an allow for a port nothing publishes is not carried over" "0" "$(calls 'port 9090')"
expect "the engine's own rules are not carried over" "0" "$(calls 'ufw route allow proto tcp from any to any port 2011$')"
expect "carried over before the engine's route rules go in" "1" "$([ "$(line_of 'port 8080')" -lt "$(line_of 'port 2011 comment')" ] && echo 1)"

upgrade '0.0.0.0:30000-30009->30000-30009/tcp' "allow tcp 30005 0.0.0.0/0 any 203.0.113.7 in" "allow tcp 80,30002:30003 0.0.0.0/0 any 0.0.0.0/0 in" \
    "allow udp 30001 0.0.0.0/0 any 0.0.0.0/0 in"
expect "a port inside a published range" "1" "$(calls 'ufw route allow proto tcp from 203.0.113.7 to any port 30005$')"
expect "a list reaching into one" "1" "$(calls 'ufw route allow proto tcp from any to any port 80,30002:30003$')"
expect "another protocol does not" "0" "$(calls 'proto udp')"

upgrade '0.0.0.0:28022->22/tcp' "allow any any 0.0.0.0/0 any 10.10.0.1 in comment=$(hex 'office')" "allow any any 10.10.0.1 any 0.0.0.0/0 out" \
    "deny any any 0.0.0.0/0 any 198.51.100.9 in comment=$(hex 'spam')" "deny tcp 22 0.0.0.0/0 any 192.0.2.0/24 in" \
    "deny any any 0.0.0.0/0 any 192.0.2.99 in comment=$(hex 'by Fail2Ban after 5 attempts against sshd')" "allow tcp 22 0.0.0.0/0 any 0.0.0.0/0 in_eth0" \
    "limit tcp 22 0.0.0.0/0 any 0.0.0.0/0 in"
expect "an address allowed on every port keeps them" "1" "$(calls 'ufw route allow from 10.10.0.1 to any comment office')"
expect "a deny stays a deny, on top" "1" "$(calls 'ufw route prepend deny from 198.51.100.9 to any comment spam')"
expect "a deny on the container's port too" "1" "$(calls 'ufw route prepend deny proto tcp from 192.0.2.0/24 to any port 22$')"

upgrade '' "deny tcp 3306 0.0.0.0/0 any 198.51.100.0/24 in" "allow any any 0.0.0.0/0 any 10.10.0.1 in" "deny any any 0.0.0.0/0 any 203.0.113.9 in"
expect "a deny is carried over whatever is published, as an API deny covers both" "1" "$(calls 'ufw route prepend deny proto tcp from 198.51.100.0/24 to any port 3306$')"
expect "with nothing published at all" "1" "$(calls 'ufw route prepend deny from 203.0.113.9 to any$')"
expect "while an allow needs a published port" "0" "$(calls 'ufw route allow from 10.10.0.1')"
expect "an outbound rule is not carried over" "0" "$(calls 'to 10.10.0.1')"
expect "a ban is left to mirror_bans" "0" "$(calls 192.0.2.99)"
expect "nor a rule with options the route rule would lose" "0" "$(grep -cE 'ufw route (allow|limit) proto tcp from any to any port 22( |$)' "$W/calls")"

reset
printf '%s\n' '0.0.0.0:28081->8080/tcp' >"$W/docker-ports"
rules "allow tcp 2011 0.0.0.0/0 any 0.0.0.0/0 in comment=$(hex 'panelalpha: engine api')" "allow tcp 8080 0.0.0.0/0 any 0.0.0.0/0 in"
rules6 "allow tcp 8080 ::/0 any ::/0 in" "allow tcp 8080 ::/0 any 2001:db8::/32 in"
printf 'ufw-user-forward\nDOCKER-USER\n' >"$W/chains"
run apply >/dev/null
expect "a rule in both files is carried over once" "1" "$(calls 'ufw route allow proto tcp from any to any port 8080$')"
expect "an IPv6 one as well" "1" "$(calls 'ufw route allow proto tcp from 2001:db8::/32 to any port 8080$')"

upgrade '0.0.0.0:28081->8080/tcp' "allow tcp 8080 0.0.0.0/0 any 0.0.0.0/0 in" "${ENGINE_ROUTES[@]}"
expect "once the engine's route rules are there, nothing is carried over" "0" "$(calls 'ufw route')"

# Bans: on the host and on published ports, both lifted together.
reset
run install >/dev/null
action="$W/etc/fail2ban/action.d/panelalpha-ufw.conf"
expect "a ban denies the host's ports" "1" "$(grep -c '^actionban = ufw prepend deny from <ip> to any comment "<comment>"$' "$action")"
expect "and published ones" "1" "$(grep -c '^ *ufw route prepend deny from <ip> to any comment "<comment>"$' "$action")"
expect "unban lifts both" "2" "$(grep -cE '^(actionunban =)? *ufw (route )?delete deny from <ip> to any$' "$action")"
expect "with fail2ban's comment, so the API knows a ban" "1" "$(grep -c '^comment = by Fail2Ban after <failures> attempts against <name>$' "$action")"

reset
rules "${ENGINE_ROUTES[@]}" "deny any any 0.0.0.0/0 any 198.51.100.9 in comment=$(hex 'by Fail2Ban after 5 attempts against sshd')" \
    "deny any any 0.0.0.0/0 any 198.51.100.10 in comment=$(hex 'by Fail2Ban after 5 attempts against ftp')" \
    "route:deny any any 0.0.0.0/0 any 198.51.100.10 in comment=$(hex 'by Fail2Ban after 5 attempts against ftp')" \
    "deny any any 0.0.0.0/0 any 203.0.113.7 in comment=$(hex 'spam')"
rules6 "deny any any ::/0 any 2001:db8::7 in comment=$(hex 'by Fail2Ban after 10 attempts against engine-api')"
run install >/dev/null
expect "an earlier ban is extended to published ports" "1" "$(calls 'ufw route prepend deny from 198.51.100.9 to any comment by Fail2Ban after 5 attempts against sshd')"
expect "an IPv6 one too" "1" "$(calls 'ufw route prepend deny from 2001:db8::7 to any comment by Fail2Ban after 10 attempts against engine-api')"
expect "one that has it is left alone" "0" "$(calls 'ufw route prepend deny from 198.51.100.10')"
expect "and so is a deny that is not a ban" "0" "$(calls 'ufw route prepend deny from 203.0.113.7')"
expect "after fail2ban takes the new action" "1" "$([ "$(line_of 'ufw route prepend deny from 198.51.100.9')" -gt "$(line_of 'systemctl restart fail2ban')" ] && echo 1)"

reset
printf 'ufw-user-forward\n' >"$W/chains"
run published-on >/dev/null
expect "at boot, before Docker, DOCKER-USER is made for it" "1" "$(calls 'iptables -N DOCKER-USER')"

reset
expect "ufw off: no hook" "0" "$(run published-on)"
expect "nothing jumps into a chain that is not there" "0" "$(calls 'PA-PUBLISHED')"

reset
run unhook >/dev/null
expect "unhook takes the hook down" "1" "$(calls 'iptables -X PA-PUBLISHED')"
expect "and leaves ufw on" "0" "$(calls 'ufw --force disable')"

reset
run published-off >/dev/null
expect "published-off takes the hook down" "1" "$(calls 'iptables -X PA-PUBLISHED')"

reset
touch "$W/etc/ufw/before.init" "$W/etc/ufw/after.init" "$W/etc/fail2ban/jail.d/panelalpha.local" 2>/dev/null || {
    mkdir -p "$W/etc/fail2ban/jail.d"
    touch "$W/etc/ufw/before.init" "$W/etc/ufw/after.init" "$W/etc/fail2ban/jail.d/panelalpha.local"
}
expect "uninstall: done" "0" "$(run uninstall)"
expect "turns ufw off" "1" "$(calls 'ufw --force disable')"
expect "removes the hooks" "no" "$([ -e "$W/etc/ufw/before.init" ] || [ -e "$W/etc/ufw/after.init" ] && echo yes || echo no)"
expect "and the jail" "no" "$([ -e "$W/etc/fail2ban/jail.d/panelalpha.local" ] && echo yes || echo no)"
expect "and its action" "no" "$([ -e "$W/etc/fail2ban/action.d/panelalpha-ufw.conf" ] && echo yes || echo no)"

# scripts/firewall.sh: the provider comes from FIREWALL_PROVIDER in the engine's .env.
dispatch() { env_run bash "$(cd "$DIR/.." && pwd)/firewall.sh" "$@" >"$W/out" 2>&1; echo $?; }
reset
printf 'ufw-user-forward\nDOCKER-USER\n' >"$W/chains"
expect "firewall.sh --apply runs the ufw provider" "0" "$(dispatch --apply)"
expect "which puts the hook in" "1" "$(restored '-A PA-PUBLISHED -j ufw-user-forward')"
reset
expect "firewall.sh --install installs" "0" "$(dispatch --install)"
expect "and enables ufw" "1" "$(calls 'ufw --force enable')"
reset
dispatch --unhook >/dev/null
expect "firewall.sh --unhook leaves ufw on" "0" "$(calls 'ufw --force disable')"
dispatch --uninstall >/dev/null
expect "firewall.sh --uninstall turns it off" "1" "$(calls 'ufw --force disable')"
reset
echo "FIREWALL_PROVIDER=firewalld" >>"$W/engine/.env"
expect "an unknown provider is refused" "1" "$(dispatch --apply)"
expect "and named" "1" "$(grep -c "no provider script for 'firewalld'" "$W/out")"
reset
expect "firewall.sh without a command shows its usage" "1" "$(dispatch)"
expect "usage text" "1" "$(grep -c '^Usage:' "$W/out")"

expect "an unknown command is refused" "1" "$(run frobnicate)"
expect "with the usage" "1" "$(grep -c '^Usage:' "$W/out")"

echo
[ "$failures" = 0 ] && echo "All passed." || echo "$failures failed."
exit "$failures"
