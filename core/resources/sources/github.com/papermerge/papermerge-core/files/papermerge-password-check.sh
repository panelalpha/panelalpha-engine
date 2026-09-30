#!/bin/sh
# Papermerge creates its first (and only) login from PAPERMERGE__AUTH__PASSWORD.
if [ -z "${PAPERMERGE__AUTH__PASSWORD:-}" ]; then
    echo "papermerge: PAPERMERGE__AUTH__PASSWORD is not set. Set it as a project environment variable (the password of the first login, user PAPERMERGE__AUTH__USERNAME, default admin) and redeploy." >&2
    exit 1
fi
echo "papermerge: admin password set"
