#!/bin/sh
# Runs as root before the image's own entrypoint (/init /app/wrapper.sh).
# The web UI's only login is an access token: on the first start the app makes a
# random one in $CONFIG_DIR/auth.json and logs it masked. Here, while that file
# has no token, it gets the one the engine generated instead, in the shape the
# app writes ({"token": ...}, 0600, the app user's). A token the app already
# has, the generated one or one changed in the app, is never touched.
# The Dockerfile COPYs only its own directories, so this file is never in the
# image.
set -e

say() { echo "[panelalpha] media-preview-generator: $*" >&2; }

config_dir="${CONFIG_DIR:-/config}"
auth_file="${config_dir}/auth.json"

# In a subshell: the token reaches the file, not the server's environment.
(
    if grep -qs '"token"' "${auth_file}"; then
        say "${auth_file} has a token already; left alone"
        exit 0
    fi
    if [ ! -r /pa/app-credentials.env ]; then
        say "no /pa/app-credentials.env; the app will make its own token"
        exit 0
    fi
    . /pa/app-credentials.env
    if [ -z "${MEDIA_PREVIEW_TOKEN:-}" ]; then
        say "MEDIA_PREVIEW_TOKEN is empty; the app will make its own token"
        exit 0
    fi
    mkdir -p "${config_dir}"
    umask 077
    # The generated token is letters and digits only, so it needs no escaping.
    printf '{"token": "%s"}\n' "${MEDIA_PREVIEW_TOKEN}" > "${auth_file}.panelalpha"
    mv "${auth_file}.panelalpha" "${auth_file}"
    # The app runs as abc (PUID/PGID); the image's init only hands over /config
    # itself.
    chown "${PUID:-1000}:${PGID:-1000}" "${auth_file}"
    say "wrote the generated token to ${auth_file}"
)

exec /init /app/wrapper.sh "$@"
