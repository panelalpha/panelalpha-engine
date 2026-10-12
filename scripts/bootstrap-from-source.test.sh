#!/bin/bash
# Checks when bootstrap-from-source.sh reinstalls Composer dependencies: the
# upload never carries core/vendor, so a changed composer.lock must trigger an
# install on a host that already has one.
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT

eval "$(sed -n '/^COMPOSER_LOCK_STAMP=/p; /^composer_needed() {/,/^}/p; /^core_artisan() {/p; /^existing_tokens() {/,/^}/p; /^served_cert_note() {/,/^}/p; /^dns_port_holder() {/,/^}/p; /^report_dns_port_holder() {/,/^}/p' "$SCRIPT_DIR/bootstrap-from-source.sh")"
cd "$WORK_DIR" && mkdir -p core

failures=0
expect() { # expect <label> <yes|no>
    local got=no
    composer_needed && got=yes
    if [ "$got" = "$2" ]; then
        echo "PASS: $1"
    else
        echo "FAIL: $1 -- expected $2, got $got"
        failures=$((failures + 1))
    fi
}

FORCE_COMPOSER=0
echo '{"content-hash": "a"}' >core/composer.lock
expect "no vendor/ installs" yes

mkdir core/vendor
sha256sum core/composer.lock | cut -d' ' -f1 >core/vendor/.pa-composer-lock
expect "vendor/ built from this lock is kept" no

echo '{"content-hash": "b"}' >core/composer.lock
expect "a changed composer.lock installs" yes

rm core/vendor/.pa-composer-lock
expect "vendor/ from before the stamp existed installs" yes

sha256sum core/composer.lock | cut -d' ' -f1 >core/vendor/.pa-composer-lock
FORCE_COMPOSER=1
expect "--composer always installs" yes

# The closing message counts tokens through `api:token:list`; a redeploy keeps them.
FAKE_LIST=''
FAKE_LIST_EXIT=0
docker() { printf '%s' "$FAKE_LIST"; return "$FAKE_LIST_EXIT"; }
expect_tokens() { # expect_tokens <label> <count|fail>
    local got
    got=$(existing_tokens) || got=fail
    if [ "$got" = "$2" ]; then
        echo "PASS: $1"
    else
        echo "FAIL: $1 -- expected $2, got $got"
        failures=$((failures + 1))
    fi
}

FAKE_LIST=$'+----+------+-----------+---------+\r\n| ID | Name | Last Used | Created |\r\n+----+------+-----------+---------+\r\n+----+------+-----------+---------+\r\n'
expect_tokens "an empty token list is 0" 0

FAKE_LIST=$'+----+-------------+-----------+---------------------+\r\n| ID | Name        | Last Used | Created             |\r\n+----+-------------+-----------+---------------------+\r\n| 1  | retest-1003 |           | 2026-10-03 10:00:00 |\r\n| 12 | default     |           | 2026-10-05 09:00:00 |\r\n+----+-------------+-----------+---------------------+\r\n'
expect_tokens "two tokens are counted" 2

FAKE_LIST_EXIT=1
expect_tokens "an unreadable list is not 'no tokens'" fail

# The closing note follows the certificate served, not the one requested.
expect_note() { # expect_note <label> <cert> <first line>
    local got
    got=$(served_cert_note "$2" 203.0.113.9 | head -n 1)
    if [ "$got" = "$3" ]; then
        echo "PASS: $1"
    else
        echo "FAIL: $1 -- expected '$3', got '$got'"
        failures=$((failures + 1))
    fi
}
quiet() { "$@" >/dev/null 2>&1; }
quiet openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=ca -keyout ca.key -out ca.pem
quiet openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=203.0.113.9 \
    -addext subjectAltName=IP:203.0.113.9 -keyout self.key -out self.pem
for san in IP:203.0.113.9 DNS:panel.example.com; do
    quiet openssl req -new -newkey rsa:2048 -nodes -subj /CN=leaf -keyout leaf.key -out leaf.csr
    quiet openssl x509 -req -in leaf.csr -CA ca.pem -CAkey ca.key -CAcreateserial -days 1 \
        -extfile <(echo "subjectAltName=$san") -out "${san%%:*}.pem"
done
export SSL_CERT_FILE="$WORK_DIR/ca.pem"
expect_note "a self-signed certificate needs --cacert" self.pem "The certificate is self-signed; clients need it explicitly:"
expect_note "a trusted one for the address needs nothing" IP.pem "The certificate is publicly trusted for 203.0.113.9; clients need nothing extra."
expect_note "a trusted one for a name says so" DNS.pem "The certificate is publicly trusted, but not for 203.0.113.9; connect by the name it is issued for."
unset SSL_CERT_FILE

# The DNS port check runs under set -e at the top level: a holder that is gone
# by the time ss looks again must not end the bootstrap.
step() { echo "step: $1"; }
warn() { echo "warn: $1"; }
SS_CALLS="$WORK_DIR/ss-calls"
ss() { # each call prints the next |-separated entry of FAKE_SS, one line per listener
    local n
    n=$(($(cat "$SS_CALLS" 2>/dev/null || echo 0) + 1))
    echo "$n" >"$SS_CALLS"
    printf '%s\n' "${FAKE_SS[n - 1]:-}" | tr '|' '\n' | sed '/^$/d'
}
expect_holder() { # expect_holder <label> <expected output, lines joined by />
    local got
    rm -f "$SS_CALLS"
    got=$(set -e; report_dns_port_holder; echo survived)
    got=$(printf '%s\n' "$got" | tr '\n' '/')
    if [ "$got" = "$2" ]; then
        echo "PASS: $1"
    else
        echo "FAIL: $1 -- expected '$2', got '$got'"
        failures=$((failures + 1))
    fi
}
DOCKER_LINE='UNCONN 0 0 127.0.0.54:53 0.0.0.0:* users:(("docker-proxy",pid=10,fd=4))'
OTHER_LINE='UNCONN 0 0 127.0.0.54:53 0.0.0.0:* users:(("dnsmasq",pid=11,fd=4))'
WARNING="warn: Something still holds 127.0.0.54:53 — dns-proxy may fail to start: $OTHER_LINE"
FAKE_SS=('')
expect_holder "a free port says nothing" "survived/"
FAKE_SS=("UNCONN 0 0 127.0.0.1:53 0.0.0.0:*|$DOCKER_LINE" "$DOCKER_LINE")
expect_holder "a dns-proxy holder is fine" "step: 127.0.0.54:53 is held by a running dns-proxy container — fine/survived/"
FAKE_SS=("$OTHER_LINE" "$OTHER_LINE")
expect_holder "another holder is warned about" "$WARNING/survived/"
FAKE_SS=("$OTHER_LINE" '')
expect_holder "a holder gone between two looks does not end the bootstrap" "$WARNING/survived/"
unset -f ss step warn

[ "$failures" -eq 0 ] && echo "All passed." || echo "${failures} failed."
exit "$failures"
