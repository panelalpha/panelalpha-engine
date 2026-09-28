#!/bin/bash
# Exercises retire-dockerhub-mirror.sh against a fake docker CLI that reports an
# old dockerhub-mirror container and volume and records what gets removed.
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT

mkdir -p "$WORK_DIR/bin"
cat >"$WORK_DIR/bin/docker" <<FAKE
#!/bin/bash
case "\$*" in
    "ps -aq --filter name=^panelalpha-dockerhub-mirror\$") echo c0ffee ;;
    "ps -aq --filter label=com.docker.compose.service=dockerhub-mirror") echo c0ffee ;;
    "volume ls -q --filter label=com.docker.compose.volume=dockerhub-mirror-data") echo shared-hosting_dockerhub-mirror-data ;;
    rm*|"volume rm"*) echo "\$*" >>"$WORK_DIR/removed" ;;
esac
FAKE
chmod +x "$WORK_DIR/bin/docker"

failures=0
expect() { # expect <label> <expected> <actual>
    if [ "$2" = "$3" ]; then
        echo "PASS: $1"
    else
        echo "FAIL: $1 -- expected '$2', got '$3'"
        failures=$((failures + 1))
    fi
}
retire() { PATH="$WORK_DIR/bin:$PATH" bash "$SCRIPT_DIR/retire-dockerhub-mirror.sh" "$WORK_DIR/.env" >/dev/null; }

printf 'A=1\nCOMPOSE_PROFILES=core,dockerhub-mirror,mail\n' >"$WORK_DIR/.env"
retire
expect "the old container is removed once, by id" "rm -f c0ffee" "$(grep '^rm' "$WORK_DIR/removed")"
expect "its cache volume is removed" "volume rm -f shared-hosting_dockerhub-mirror-data" "$(grep '^volume' "$WORK_DIR/removed")"
expect "the old profile becomes registry-proxy" "COMPOSE_PROFILES=core,registry-proxy,mail" "$(grep '^COMPOSE_PROFILES=' "$WORK_DIR/.env")"

printf 'COMPOSE_PROFILES=dockerhub-mirror\n' >"$WORK_DIR/.env"
retire
expect "a lone old profile is renamed too" "COMPOSE_PROFILES=registry-proxy" "$(cat "$WORK_DIR/.env")"

printf 'COMPOSE_PROFILES=full\n' >"$WORK_DIR/.env"
retire
expect "full is left alone" "COMPOSE_PROFILES=full" "$(cat "$WORK_DIR/.env")"

[ "$failures" -eq 0 ] && echo "All passed." || echo "${failures} failed."
exit "$failures"
