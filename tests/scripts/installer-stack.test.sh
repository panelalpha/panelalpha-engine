#!/bin/bash
#
# installer.sh's stack start and checks against a fake docker: sites-http
# comes up on its own once the rest of the stack runs, an update fails when a
# service is not running or runs without a network, and a container left
# Created by a failed start is removed before `up`.
#
#   bash tests/scripts/installer-stack.test.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/../../scripts" && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

# installer.sh installs an engine when sourced, so cut out the functions.
for fn in remove_unstarted_containers check_engine_stack start_engine_stack; do
    sed -n "/^${fn}() {/,/^}\$/p" "$SCRIPT_DIR/installer.sh" >>"$TMP/funcs.sh"
done
# shellcheck source=/dev/null
source "$TMP/funcs.sh"

PASS=0
FAIL=0
check() {
    local what="$1" expected="$2" actual="$3"
    if [[ "$actual" == "$expected" ]]; then
        printf '  ok    %s\n' "$what"
        PASS=$((PASS + 1))
    else
        printf '  FAIL  %s\n        expected: %s\n        actual:   %s\n' "$what" "$expected" "$actual"
        FAIL=$((FAIL + 1))
    fi
}

echo_info() { echo "info:$1"; }
echo_error() {
    echo "error:$1"
    exit 101
}
# A wait turns every restarting container into a running one.
sleep() { sed -i 's/ restarting 1$/ running 1/' "$TMP/state"; }

# $TMP/state: one "service id status networks" line per container.
# $TMP/ups: every `compose up`, as `up <arguments>`.
docker() {
    local a svc='' id args="$*"
    case "$1" in
    compose)
        case " $args " in
        *" up "*) compose_up "${args#* up }" ;;
        *) cat "$TMP/services" ;;
        esac
        ;;
    ps)
        for a in "$@"; do
            case "$a" in label=com.docker.compose.service=*) svc="${a#label=com.docker.compose.service=}" ;; esac
        done
        awk -v s="$svc" 's == "" || $1 == s { print $2 }' "$TMP/state"
        ;;
    inspect)
        id="${!#}"
        case "$3" in
        *.Name*) awk -v i="$id" '$2 == i { print "/" $1 }' "$TMP/state" ;;
        *) awk -v i="$id" '$2 == i { print $3, $4 }' "$TMP/state" ;;
        esac
        ;;
    rm)
        id="${!#}"
        echo "$id" >>"$TMP/removed"
        sed -i "/ ${id} /d" "$TMP/state"
        ;;
    esac
}

# An `up` runs a new container for every service it names, or for all of them.
# $TMP/before-<n>: the containers when the n-th one started.
compose_up() { # <up arguments>
    local named svc
    echo "up $1" >>"$TMP/ups"
    containers >"$TMP/before-$(wc -l <"$TMP/ups")"
    named=$(tr ' ' '\n' <<<"$1" | grep -v '^-')
    for svc in ${named:-$(cat "$TMP/services")}; do
        sed -i "/^${svc} /d" "$TMP/state"
        echo "${svc} new-${svc} running 1" >>"$TMP/state"
    done
}

stack() { # stack <state lines...>
    printf '%s\n' "$@" >"$TMP/state"
    awk '{ print $1 }' "$TMP/state" >"$TMP/services"
    : >"$TMP/removed"
    : >"$TMP/ups"
    rm -f "$TMP"/before-*
}

fresh() { # fresh <enabled services...>
    printf '%s\n' "$@" >"$TMP/services"
    : >"$TMP/state"
    : >"$TMP/ups"
    rm -f "$TMP"/before-*
}

ups() { paste -sd'|' "$TMP/ups"; }
containers() { awk '{ print $2 }' "$TMP/state" | LC_ALL=C sort | tr '\n' ' ' | sed 's/ $//'; }

stack "core c1 running 1" "cache-registry c2 running 1" "mail c3 running 1"
out=$(check_engine_stack)
check "a running stack passes" "0" "$?"
check "a running stack says nothing" "" "$out"

stack "core c1 running 1" "cache-registry c2 running 0" "mail c3 running 1"
out=$(check_engine_stack)
check "a service without a network fails the update" "101" "$?"
check "it is named" "error:These engine services are not running: cache-registry(no network)" "$out"

stack "core c1 running 1" "cache-registry c2 created 0" "mail c3 exited 1"
out=$(check_engine_stack)
check "a service that never started fails the update" "101" "$?"
check "each one is named with its state" "error:These engine services are not running: cache-registry(created) mail(exited)" "$out"

stack "core c1 running 1" "cache-registry c2 running 1"
echo "lighthouse" >>"$TMP/services"
out=$(check_engine_stack)
check "an enabled service with no container fails" "error:These engine services are not running: lighthouse(missing)" "$out"

stack "core c1 running 1" "sites-http c2 restarting 1"
out=$(check_engine_stack)
check "a restarting service gets time to come up" "0" "$?"

stack "core c1 running 1" "cache-registry c2 created 0" "metrics c3 running 0" "mail c4 exited 1" "sites-db c5 running 1"
out=$(remove_unstarted_containers)
check "Created and networkless containers are removed" "c2 c3" "$(tr '\n' ' ' <"$TMP/removed" | sed 's/ $//')"
check "stopped and healthy ones stay" "core mail sites-db" "$(awk '{ print $1 }' "$TMP/state" | tr '\n' ' ' | sed 's/ $//')"
check "the removal is reported" "info:Removing /cache-registry: its last start failed, so it would run without a network" "$(head -n1 <<<"$out")"

stack "core c1 running 1" "root-broker c2 running 1" "sites-http c3 running 1" "metrics c4 running 1"
start_engine_stack
check "an update brings up everything but sites-http, then sites-http on its own" \
    "up -d --remove-orphans core root-broker metrics|up -d sites-http" "$(ups)"
check "every service ends up on a new container" "new-core new-metrics new-root-broker new-sites-http" "$(containers)"

stack "core c1 running 1" "sites-http c3 running 1"
start_engine_stack
check "the rest of the stack is up before sites-http is touched" "c3 new-core" "$(cat "$TMP/before-2" 2>/dev/null)"

fresh core root-broker metrics sites-http
start_engine_stack
check "a fresh install starts sites-http after the rest too" "up -d --remove-orphans core root-broker metrics|up -d sites-http" "$(ups)"
out=$(check_engine_stack)
check "and every service runs" "0" "$?"

fresh core root-broker metrics
start_engine_stack
check "a stack without sites-http's profile never names it, which would enable it" \
    "up -d --remove-orphans core root-broker metrics" "$(ups)"
check "so it gets no container" "new-core new-metrics new-root-broker" "$(containers)"

echo
echo "$PASS passed, $FAIL failed"
[[ $FAIL -eq 0 ]]
