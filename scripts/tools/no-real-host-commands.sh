#!/usr/bin/env bash
#
# Fail if the unit suite spawns a real host command.
#
# A test double that has stopped intercepting does not fail -- the tests assert
# on the commands they recorded, not on whether anything ran -- so the only way
# to notice is to watch the host. This puts logging stubs first on PATH and
# reports anything that reaches them.
#
# Allowed, because each is read-only:
#   - `sudo test -f|-d|-e` against the temporary tree a test built
#   - `sudo docker info`, a capability probe
#   - the `docker compose ... ps` that webserver.sh runs on being sourced by
#     scripts/webserver-parse-args.test.sh; it degrades to "unknown" without docker
#
# Defaults to the two directories that are clean: tests/Unit/System and
# tests/Unit/Deploy. Widen it as other trees are brought up to the same standard.
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SHIM="$(mktemp -d)"
LOG="$(mktemp)"
trap 'rm -rf "$SHIM" "$LOG"' EXIT

for cmd in sudo docker nsenter systemctl useradd userdel; do
    printf '#!/bin/sh\necho "%s $*" >> %s\nexit 0\n' "$cmd" "$LOG" > "$SHIM/$cmd"
    chmod +x "$SHIM/$cmd"
done

cd "$ROOT/core"
if [ "$#" -eq 0 ]; then set -- tests/Unit/System tests/Unit/Deploy; fi

OUT="$(mktemp)"
trap 'rm -rf "$SHIM" "$LOG" "$OUT"' EXIT
PATH="$SHIM:$PATH" php vendor/bin/phpunit "$@" > "$OUT" 2>&1

# An empty log proves nothing if the run never happened. Test failures are fine
# here -- this checks what escaped, not whether the suite is green -- but a run
# that produced no result at all is not evidence.
if ! sed 's/\x1b\[[0-9;]*m//g' "$OUT" | grep -qE '^(OK|Tests:|OK, but)'; then
    echo "phpunit did not produce a result; the check proves nothing:" >&2
    tail -20 "$OUT" >&2
    exit 2
fi

UNEXPECTED="$(grep -vE '^sudo test -[fde] |^sudo docker info |^docker compose -f [^ ]+ ps -a sites-http' "$LOG" || true)"

if [ -n "$UNEXPECTED" ]; then
    echo "Real host commands escaped the test suite:" >&2
    echo "$UNEXPECTED" | sort | uniq -c | sort -rn >&2
    echo >&2
    echo "A test double has stopped intercepting. Fake at runProcess() -- it is the" >&2
    echo "bottom of the chain, so it catches exec() and execOnHost() too." >&2
    exit 1
fi

echo "OK: no real host command escaped."
