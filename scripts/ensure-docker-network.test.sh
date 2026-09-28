#!/bin/bash
# Exercises ensure-docker-network.sh against fake docker/service/systemctl
# commands. The fake daemon's iptables chains are "flushed" until a restart.
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT
mkdir -p "$WORK_DIR/bin"

# State files: `exists` = the network is there, `flushed` = chains are gone,
# `broken` = create fails for a reason a restart does not fix.
cat >"$WORK_DIR/bin/docker" <<FAKE
#!/bin/bash
W="$WORK_DIR"
echo "docker \$*" >>"\$W/calls"
case "\$1 \$2" in
    "network inspect") [ -f "\$W/exists" ] ;;
    "network create")
        if [ -f "\$W/broken" ]; then echo "Error response from daemon: invalid MTU" >&2; exit 1; fi
        if [ -f "\$W/flushed" ]; then
            echo "Error response from daemon: Failed to Setup IP tables: iptables: No chain/target/match by that name." >&2
            exit 1
        fi
        touch "\$W/exists" ;;
    "info "*|"info") exit 0 ;;
esac
FAKE
cat >"$WORK_DIR/bin/service" <<FAKE
#!/bin/bash
echo "service \$*" >>"$WORK_DIR/calls"
rm -f "$WORK_DIR/flushed"
FAKE
printf '#!/bin/bash\nexit 0\n' >"$WORK_DIR/bin/systemctl"
chmod +x "$WORK_DIR/bin/"*

failures=0
expect() { # expect <label> <expected> <actual>
    if [ "$2" = "$3" ]; then
        echo "PASS: $1"
    else
        echo "FAIL: $1 -- expected '$2', got '$3'"
        failures=$((failures + 1))
    fi
}
reset() { rm -f "$WORK_DIR/exists" "$WORK_DIR/flushed" "$WORK_DIR/broken" "$WORK_DIR/calls"; }
ensure() { PATH="$WORK_DIR/bin:$PATH" bash "$SCRIPT_DIR/ensure-docker-network.sh" 1400 >/dev/null 2>&1; echo $?; }
restarts() { grep -c '^service docker restart' "$WORK_DIR/calls"; }

reset
expect "creates the network" "0" "$(ensure)"
expect "with the MTU asked for" "1" "$(grep -c 'mtu=1400' "$WORK_DIR/calls")"
expect "without restarting Docker" "0" "$(restarts)"

reset; touch "$WORK_DIR/exists"
expect "an existing network is left alone" "0" "$(ensure)"
expect "and nothing is created" "0" "$(grep -c 'network create' "$WORK_DIR/calls")"

reset; touch "$WORK_DIR/flushed"
expect "flushed iptables chains: restart and retry succeeds" "0" "$(ensure)"
expect "Docker is restarted exactly once" "1" "$(restarts)"

reset; touch "$WORK_DIR/broken"
expect "any other failure is reported, not retried" "1" "$(ensure)"
expect "and Docker is not restarted for it" "0" "$(restarts)"

[ "$failures" -eq 0 ] && echo "All passed." || echo "${failures} failed."
exit "$failures"
