#!/usr/bin/env bash

set -euo pipefail

cd "$(dirname "$0")"

TMPDIR="$(mktemp -d)"
SERIAL_NO=""
SERIAL_FILE_SOURCE="../webserver-config/litespeed/serial.no"

cleanup() {
    rm -rf "$TMPDIR"
}
trap cleanup EXIT

usage() {
    cat <<EOF
Usage:
  $0 [--serial-no SERIAL_NUMBER]

Description:
  Checks LiteSpeed license inside Docker.

Flow:
  1. If --serial-no is provided → use it
  2. Else try: $SERIAL_FILE_SOURCE
  3. Else fallback to trial key

Options:
  --serial-no SERIAL_NUMBER   Provide a LiteSpeed serial number manually
EOF
}

# ----------------------------
# Parse args
# ----------------------------
while [[ $# -gt 0 ]]; do
    case "$1" in
        --serial-no=*)
            SERIAL_NO="${1#*=}"
            shift
            ;;
        --serial-no)
            SERIAL_NO="${2:-}"
            shift 2
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            echo "Unknown argument: $1"
            usage
            exit 1
            ;;
    esac
done

# ----------------------------
# Resolve serial number source
# ----------------------------
if [[ -n "$SERIAL_NO" ]]; then
    echo "Using serial number provided via --serial-no"
    echo "$SERIAL_NO" > "$TMPDIR/serial.no"

else
    echo "No serial number provided via --serial-no"
    echo "Checking for saved serial number at: $SERIAL_FILE_SOURCE"

    if [[ -f "$SERIAL_FILE_SOURCE" ]]; then
        echo "Found saved serial number, using it"
        cp "$SERIAL_FILE_SOURCE" "$TMPDIR/serial.no"
    else
        echo "No saved serial number found"
        echo "Will fallback to trial license"
    fi
fi

# ----------------------------
# Create entrypoint script
# ----------------------------
cat > "$TMPDIR/entrypoint.sh" <<'EOF'
#!/usr/bin/env bash
set -e

TMPDIR="/tmp/ls-check"

# Ensure config dir exists
mkdir -p /usr/local/lsws/conf

cp -R /usr/local/lsws/.conf/* /usr/local/lsws/conf/ 2>/dev/null || true

if [[ -f "$TMPDIR/serial.no" ]]; then
    SERIAL_NUMBER=$(cat "$TMPDIR/serial.no")
    echo "Serial number detected: $SERIAL_NUMBER"

    cp "$TMPDIR/serial.no" /usr/local/lsws/conf/serial.no

    echo "Running license check (lshttpd -r)..."
    echo "lshttpd output:"
    /usr/local/lsws/bin/lshttpd -r
else
    echo "Downloading trial key..."

    wget --https-only --max-redirect=0 -q -P /usr/local/lsws/conf/ https://license.litespeedtech.com/reseller/trial.key

    echo "Running license check with trial key (lshttpd -V)..."
    echo "lshttpd output:"
    /usr/local/lsws/bin/lshttpd -V
fi
EOF

chmod +x "$TMPDIR/entrypoint.sh"

docker run --rm \
    -v "$TMPDIR":/tmp/ls-check \
    --entrypoint /tmp/ls-check/entrypoint.sh \
    litespeedtech/litespeed
