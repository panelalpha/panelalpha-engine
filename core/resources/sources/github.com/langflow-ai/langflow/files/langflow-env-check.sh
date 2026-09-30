#!/bin/sh
# The image disables auto-login, so Langflow needs a superuser password to start.
if [ -z "${LANGFLOW_SUPERUSER_PASSWORD:-}" ]; then
    echo "langflow: missing project environment variable LANGFLOW_SUPERUSER_PASSWORD (the admin's password; login name is LANGFLOW_SUPERUSER, default 'langflow'; write any \$ as \$\$). Set it and redeploy." >&2
    exit 1
fi
echo "langflow: superuser password set"
