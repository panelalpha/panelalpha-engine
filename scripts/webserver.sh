#!/bin/bash
# Change the engine webserver stack.
#
# Used interactively and by the engine API (System::runChangeWebserverScript)
# with --background so the API process does not wait on compose down/up.
#
# When sourced (see tests/scripts/webserver-parse-args.test.sh), only helpers/parse_args/main
# are loaded — flock and the entrypoint are skipped.

ENGINE_DIR=/opt/panelalpha/shared-hosting
SERIAL_NO=""

CURRENT_WEBSERVER=$(docker compose -f $ENGINE_DIR/docker-compose.yml ps -a sites-http --format json 2>/dev/null | jq '.Labels' 2>/dev/null | tr ',' '\n' | awk -F= '$1=="com.panelalpha.webserver"{print $2}')
if [ -z "$CURRENT_WEBSERVER" ]; then
    CURRENT_WEBSERVER=unknown
fi

print_info() {
    echo ""
    echo "Current webserver: $CURRENT_WEBSERVER"
    echo ""
    echo "Temporarily only nginx-proxy is supported (required for DinD projects)."
    echo "Switching to apache, nginx, litespeed, or openlitespeed is disabled."
    echo ""
    echo "Allowed:"
    echo "  webserver.sh --set nginx-proxy"
    echo ""
}

prepare_litespeed_serial() {
    local config_dir="$ENGINE_DIR/webserver-config/litespeed"

    mkdir -p "$config_dir"
    echo "$SERIAL_NO" >"$config_dir/serial.no"
    chown 994:994 "$config_dir/serial.no"
    rm -f "$config_dir/trial.key"
    echo "LiteSpeed serial number configured before webserver start"
}

set_webserver() {
    # Temporary: only nginx-proxy works correctly with DinD projects.
    if [ "$1" != "nginx-proxy" ]; then
        echo ""
        echo "Switching the webserver to '$1' is temporarily disabled."
        echo "Only nginx-proxy is supported right now (required for DinD projects)."
        echo "Allowed: webserver.sh --set nginx-proxy"
        echo ""
        exit 1
    fi

    case $1 in
    apache)
        COMPOSE_FILENAME=docker-compose.yml-apache
        ;;
    nginx)
        COMPOSE_FILENAME=docker-compose.yml-nginx
        ;;
    nginx-proxy)
        COMPOSE_FILENAME=docker-compose.yml-nginx-proxy
        ;;
    litespeed)
        COMPOSE_FILENAME=docker-compose.yml-litespeed
        ;;
    openlitespeed)
        COMPOSE_FILENAME=docker-compose.yml-openlitespeed
        ;;
    *)
        echo ""
        echo "Invalid webserver '$1'"
        print_info
        exit 1
        ;;
    esac

    if [ "$1" == "litespeed" ] && [ -n "$SERIAL_NO" ]; then
        prepare_litespeed_serial
    fi

    docker compose -f $ENGINE_DIR/docker-compose.yml down sites-http
    ln -sfn $ENGINE_DIR/$COMPOSE_FILENAME $ENGINE_DIR/docker-compose.yml-webserver
    docker compose -f $ENGINE_DIR/docker-compose.yml up -d
    docker compose -f $ENGINE_DIR/docker-compose.yml exec core php artisan users:rebuild --all --wipe-vhosts-dir -v
    echo ""
    echo "Webserver switched to '$1'"
    echo ""
    exit 0
}

parse_args() {
    local args=()

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
        --background)
            shift
            ;;
        *)
            args+=("$1")
            shift
            ;;
        esac
    done

    main "${args[@]}"
}

main() {
    case $1 in
    "" | help | --help)
        print_info
        exit 0
        ;;
    --set)
        set_webserver $2
        exit 0
        ;;
    *)
        echo ""
        echo "Invalid option '$1'"
        print_info
        exit 1
        ;;
    esac
}

strip_colors() {
    sed -r 's/\x1B\[[0-9;]*[mK]//g'
}

run_with_logging() {
    local background=$1
    shift

    local LOGS_DIR="/opt/panelalpha/log/change-webserver"
    mkdir -p "$LOGS_DIR"
    local LOCK_FILE="$LOGS_DIR/lock"

    exec 200>"$LOCK_FILE" || {
        echo "Cannot open lock file $LOCK_FILE for writing." >&2
        exit 1
    }
    flock -n 200 || {
        echo "Another instance is already running."
        exit 1
    }

    local RUN_ID RUN_DIR STDOUT_FILE STDERR_FILE EXIT_CODE_FILE PID_FILE
    RUN_ID=$(date +%s)
    RUN_DIR="$LOGS_DIR/$RUN_ID"
    mkdir -p "$RUN_DIR"
    ln -sfn "$RUN_DIR" "$LOGS_DIR/latest"

    STDOUT_FILE="$RUN_DIR/stdout"
    STDERR_FILE="$RUN_DIR/stderr"
    EXIT_CODE_FILE="$RUN_DIR/exit_code"
    PID_FILE="$RUN_DIR/pid"

    cleanup() {
        echo "$1" >"$EXIT_CODE_FILE"
        echo "[INFO] Change webserver script exited with code $1" >>"$STDOUT_FILE"
    }

    if [[ "$background" -eq 1 ]]; then
        (
            local exit_code=0
            trap 'cleanup $exit_code' EXIT INT TERM
            parse_args "$@" >"$STDOUT_FILE" 2>"$STDERR_FILE" || exit_code=$?
            strip_colors <"$STDOUT_FILE" >"$RUN_DIR/stdout.clean" || true
            strip_colors <"$STDERR_FILE" >"$RUN_DIR/stderr.clean" || true
        ) &
        echo $! >"$PID_FILE"
        disown
        echo "[INFO] Change webserver script started in background with PID $!" >>"$STDOUT_FILE"
        return 0
    fi

    local exit_code=0
    trap 'cleanup $exit_code' EXIT INT TERM
    echo $$ >"$PID_FILE"
    parse_args "$@" \
        > >(tee "$STDOUT_FILE") \
        2> >(tee "$STDERR_FILE" >&2) || exit_code=$?
    strip_colors <"$STDOUT_FILE" >"$RUN_DIR/stdout.clean" || true
    strip_colors <"$STDERR_FILE" >"$RUN_DIR/stderr.clean" || true
    return "$exit_code"
}

# Only when run, not when sourced, so the argument parser can be tested
# without switching anybody's webserver. See tests/scripts/webserver-parse-args.test.sh.
if [ "${BASH_SOURCE[0]}" = "${0}" ]; then
    BACKGROUND=0
    for arg in "$@"; do
        if [[ "$arg" == "--background" ]]; then
            BACKGROUND=1
            break
        fi
    done
    run_with_logging "$BACKGROUND" "$@"
fi
