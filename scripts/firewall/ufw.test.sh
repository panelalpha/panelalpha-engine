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
# iptables knows the chains listed in $W/chains; -N adds one.
cat >"$W/bin/iptables" <<FAKE
#!/bin/bash
echo "\$(basename "\$0") \$*" >>"$W/calls"
case "\$1 \$2" in
"-n -L") grep -qx "\$3" "$W/chains" 2>/dev/null ;;
"-N "*) echo "\$2" >>"$W/chains" ;;
"-C "*) exit 1 ;;
"-D "*) exit 1 ;;
esac
FAKE
cp "$W/bin/iptables" "$W/bin/ip6tables"
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
        "$W/ufw-refuses" "$W/dpkg-missing" "$W/apt-fails"
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
expect "bans are ufw deny rules" "2" "$(grep -c 'ufw\[blocktype=deny\]' "$jail")"
section() { awk -v s="[$1]" '$0 == s { on = 1; next } /^\[/ { on = 0 } on' "$jail"; }
expect "sshd jail on every ssh port" "port = 22,2200" "$(section sshd | grep '^port')"
expect "the host's sshd only" "filter = panelalpha-sshd" "$(section sshd | grep '^filter')"
expect "sftp jail" "filter = panelalpha-sftp|backend = systemd|port = 2222" "$(section sftp | grep -E '^(filter|port|backend)' | tr '\n' '|' | sed 's/|$//')"
expect "ftp jail" "filter = panelalpha-ftp|backend = systemd|port = 21" "$(section ftp | grep -E '^(filter|port|backend)' | tr '\n' '|' | sed 's/|$//')"
expect "engine API jail on its own log" "logpath = $W/engine/logs/core/nginx/api-access.log" "$(section engine-api | grep '^logpath')"
expect "which exists before fail2ban looks" "yes" "$([ -f "$W/engine/logs/core/nginx/api-access.log" ] && echo yes)"
expect "the API takes more retries" "maxretry = 10" "$(section engine-api | grep '^maxretry')"
expect "the host's sshd is told apart from the SFTP container's" "1" "$(grep -c '^journalmatch = _SYSTEMD_UNIT=ssh.service + _SYSTEMD_UNIT=sshd.service$' "$W/etc/fail2ban/filter.d/panelalpha-sshd.conf")"
expect "SFTP is facility local5" "1" "$(grep -c '^journalmatch = SYSLOG_IDENTIFIER=sshd SYSLOG_FACILITY=21$' "$W/etc/fail2ban/filter.d/panelalpha-sftp.conf")"
expect "FTP by its syslog name" "1" "$(grep -c '^journalmatch = SYSLOG_IDENTIFIER=pure-ftpd$' "$W/etc/fail2ban/filter.d/panelalpha-ftp.conf")"
expect "the API filter counts 401s on /api and /mcp" "1" "$(grep -c '"\[A-Z\]+ /(?:api|mcp)' "$W/etc/fail2ban/filter.d/panelalpha-api.conf")"
expect "with CSF's trusted addresses ignored" "1" "$(grep -c '^ignoreip = 127.0.0.1/8 ::1 1.2.3.4 203.0.113.5$' "$jail")"
expect "fail2ban reloaded" "1" "$(calls 'systemctl reload-or-restart fail2ban')"

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

# The Docker hook: what Docker DNATed from outside meets ufw's rules, then its default.
reset
printf 'ufw-user-input\nDOCKER-USER\n' >"$W/chains"
expect "hook applied" "0" "$(run published-on)"
expect "containers on Docker's bridges are left to their own chains" "2" "$(grep -cE '^iptables -A PA-PUBLISHED -i (docker0|br-\+) -j RETURN$' "$W/calls")"
expect "then ufw's user rules" "1" "$(calls 'iptables -A PA-PUBLISHED -j ufw-user-input')"
expect "then ufw's default incoming policy" "1" "$(calls 'iptables -A PA-PUBLISHED -j DROP')"
expect "the default comes last" "1" "$([ "$(line_of 'PA-PUBLISHED -j DROP')" -gt "$(line_of 'PA-PUBLISHED -j ufw-user-input')" ] && echo 1)"
expect "no log jump while ufw logs nothing" "0" "$(calls 'PA-PUBLISHED -j ufw-logging-deny')"

reset
printf 'ufw-user-input\nDOCKER-USER\nufw-logging-deny\n' >"$W/chains"
run published-on >/dev/null
expect "a refused published port is logged as ufw logs its own" "1" "$(calls 'iptables -A PA-PUBLISHED -j ufw-logging-deny')"
expect "before the drop" "1" "$([ "$(line_of 'PA-PUBLISHED -j DROP')" -gt "$(line_of 'PA-PUBLISHED -j ufw-logging-deny')" ] && echo 1)"
expect "only new connections Docker DNATed" "1" "$(calls 'iptables -I DOCKER-USER -m conntrack --ctstate NEW -m conntrack --ctstate DNAT -j PA-PUBLISHED')"
expect "no IPv6 hook without ufw6's chain" "0" "$(calls 'ip6tables -A PA-PUBLISHED')"

reset
sed -i 's/^DEFAULT_INPUT_POLICY=.*/DEFAULT_INPUT_POLICY="ACCEPT"/' "$W/etc/default-ufw"
printf 'ufw-user-input\nDOCKER-USER\n' >"$W/chains"
run published-on >/dev/null
expect "an accepting default leaves the port to Docker" "1" "$(calls 'iptables -A PA-PUBLISHED -j RETURN')"

reset
sed -i 's/^DEFAULT_INPUT_POLICY=.*/DEFAULT_INPUT_POLICY="REJECT"/' "$W/etc/default-ufw"
printf 'ufw-user-input\nDOCKER-USER\n' >"$W/chains"
run apply >/dev/null
expect "a rejecting default rejects published ports too" "1" "$(calls 'iptables -A PA-PUBLISHED -j REJECT')"

reset
printf 'ufw-user-input\n' >"$W/chains"
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

# scripts/firewall.sh: the provider comes from FIREWALL_PROVIDER in the engine's .env.
dispatch() { env_run bash "$(cd "$DIR/.." && pwd)/firewall.sh" "$@" >"$W/out" 2>&1; echo $?; }
reset
printf 'ufw-user-input\nDOCKER-USER\n' >"$W/chains"
expect "firewall.sh --apply runs the ufw provider" "0" "$(dispatch --apply)"
expect "which puts the hook in" "1" "$(calls 'iptables -A PA-PUBLISHED -j ufw-user-input')"
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
