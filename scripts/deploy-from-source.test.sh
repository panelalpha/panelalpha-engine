#!/bin/bash
# Exercises deploy-from-source.sh against a local "host": ssh and rsync are
# shims that drop the host name, so the upload and the bootstrap call run here.
# Checks that bootstrap arguments survive the remote shell word for word, and
# that the upload keeps the API suite's host config.
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT

mkdir -p "$WORK_DIR/bin" "$WORK_DIR/src/scripts" "$WORK_DIR/src/tests/api/env" "$WORK_DIR/remote/tests/api/env"
cat >"$WORK_DIR/bin/ssh" <<'EOF'
#!/bin/bash
while [[ $1 == -* ]]; do shift; done
shift
bash -c "$*"
EOF
REAL_RSYNC=$(command -v rsync)
cat >"$WORK_DIR/bin/rsync" <<EOF
#!/bin/bash
args=()
for a in "\$@"; do args+=("\${a/fakehost:/}"); done
exec "$REAL_RSYNC" "\${args[@]}"
EOF
chmod +x "$WORK_DIR/bin/ssh" "$WORK_DIR/bin/rsync"

cp "$SCRIPT_DIR/deploy-from-source.sh" "$WORK_DIR/src/scripts/"
# The "bootstrap" records its argv, one argument per line.
printf '#!/bin/bash\nprintf "%%s\\n" "$@" >"%s/argv"\n' "$WORK_DIR" >"$WORK_DIR/src/scripts/bootstrap-from-source.sh"
echo 'EXAMPLE=new' >"$WORK_DIR/src/tests/api/env/.env.example"
echo 'API_TOKEN=host-only' >"$WORK_DIR/remote/tests/api/env/.env"

failures=0
expect() { # expect <label> <expected> <actual>
    if [ "$2" = "$3" ]; then
        echo "PASS: $1"
    else
        echo "FAIL: $1 -- expected '$2', got '$3'"
        failures=$((failures + 1))
    fi
}

PATH="$WORK_DIR/bin:$PATH" bash "$WORK_DIR/src/scripts/deploy-from-source.sh" fakehost \
    --remote-dir "$WORK_DIR/remote" -- --services "core mail sites-dns" --domain "a'b \$HOME" >/dev/null 2>&1

expect "a quoted --services value stays one argument" \
    "$(printf '%s\n' --services 'core mail sites-dns' --domain "a'b \$HOME")" "$(cat "$WORK_DIR/argv" 2>/dev/null)"
expect "the host's tests/api/env/.env survives the upload" \
    'API_TOKEN=host-only' "$(cat "$WORK_DIR/remote/tests/api/env/.env" 2>/dev/null)"
expect "the .env.example template is still uploaded" \
    'EXAMPLE=new' "$(cat "$WORK_DIR/remote/tests/api/env/.env.example" 2>/dev/null)"

rm -f "$WORK_DIR/argv"
PATH="$WORK_DIR/bin:$PATH" bash "$WORK_DIR/src/scripts/deploy-from-source.sh" fakehost \
    --remote-dir "$WORK_DIR/remote" >/dev/null 2>&1
expect "no bootstrap arguments pass none" "" "$(cat "$WORK_DIR/argv" 2>/dev/null)"

[ "$failures" -eq 0 ] && echo "All passed." || echo "${failures} failed."
exit "$failures"
