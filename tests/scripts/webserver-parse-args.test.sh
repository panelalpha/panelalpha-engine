#!/bin/bash
# Exercises webserver.sh's argument parser.
#
# `ChangeWebserverSystemTest::test_webserver_script_parse_args_handles_php_argument_order`
# has always referenced this file and it was never committed, so that test has
# failed since it was written.
#
# What it is guarding: the engine calls the script as
# `webserver.sh --set litespeed --serial-no=X --background`, but the flags it
# appends are not guaranteed to come last -- `System::runChangeWebserverScript()`
# builds the argv, and PHP's array order there has changed before. So
# `parse_args` strips the flags wherever they appear and forwards only the
# positional arguments to `main`. Get that wrong and `--set` receives
# `--serial-no=X` as the webserver name.
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../scripts" && pwd)"

# Sourcing runs the top-level `CURRENT_WEBSERVER=$(docker compose …)` probe,
# which is read-only and degrades to "unknown" wherever docker or jq is
# missing. The trailing `parse_args "$@"` is guarded against sourcing.
# shellcheck source=./webserver.sh
source "${SCRIPT_DIR}/webserver.sh" >/dev/null 2>&1

# Replace the real main, which would stop containers.
main() {
    echo "main:$*"
}

failures=0

expect() {
    local label="$1" expected="$2"
    shift 2
    SERIAL_NO=""
    local actual
    actual="$(parse_args "$@")"
    if [ "$actual" = "$expected" ]; then
        echo "PASS: ${label}"
    else
        echo "FAIL: ${label} -- expected '${expected}', got '${actual}'"
        failures=$((failures + 1))
    fi
}

expect_serial() {
    local label="$1" expected="$2"
    shift 2
    SERIAL_NO=""
    parse_args "$@" >/dev/null
    if [ "$SERIAL_NO" = "$expected" ]; then
        echo "PASS: ${label}"
    else
        echo "FAIL: ${label} -- expected SERIAL_NO '${expected}', got '${SERIAL_NO}'"
        failures=$((failures + 1))
    fi
}

# The order the engine happens to build today.
expect "flags last"            "main:--set litespeed" --set litespeed --serial-no=ABC --background
# The order it has produced before, and the reason this test exists.
expect "flags first"           "main:--set litespeed" --serial-no=ABC --background --set litespeed
expect "flags interleaved"     "main:--set litespeed" --set --serial-no=ABC litespeed --background
expect "no flags at all"       "main:--set nginx"     --set nginx
expect "no arguments"          "main:"

# Both spellings of the serial, wherever they sit.
expect_serial "--serial-no=VALUE"  "ABC" --set litespeed --serial-no=ABC
expect_serial "--serial-no VALUE"  "ABC" --set litespeed --serial-no ABC
expect_serial "serial before args" "ABC" --serial-no ABC --set litespeed
expect_serial "no serial given"    ""    --set nginx

if [ "$failures" -ne 0 ]; then
    echo "${failures} failure(s)"
    exit 1
fi
exit 0
