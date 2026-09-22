#!/bin/bash
# Runs on the account, in ~/project, after the clone and before the build.
set -e

STATE="$HOME/.panelalpha/b1gmail"
ENV_FILE="$STATE/b1gmail.env"

# ~/project is emptied and re-cloned on every deploy (ProjectTree::clearContents),
# so nothing that must survive one can live under it: the signing key, the admin
# password and the mail store all go here instead.
mkdir -p "$STATE/data"
chmod 700 "$HOME/.panelalpha" "$STATE"

if [ ! -f "$ENV_FILE" ]; then
    umask 077
    {
        echo "B1GMAIL_SIGNKEY=$(openssl rand -hex 16)"
        echo "B1GMAIL_ADMIN_PASSWORD=$(openssl rand -base64 18 | tr -d '/+=' | cut -c1-16)"
    } > "$ENV_FILE"
    echo "[panelalpha] b1gMail: generated $ENV_FILE (admin password lives here)"
fi
chmod 600 "$ENV_FILE"

# The data directory is bind-mounted at /var/lib/b1gmail/data and holds every
# message body. It is outside the document root, but b1gMail's own admin panel
# checks for these two files, and a future operator may move the mount back
# under the checkout.
[ -f "$STATE/data/.htaccess" ] || printf '<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n' > "$STATE/data/.htaccess"
[ -f "$STATE/data/index.html" ] || : > "$STATE/data/index.html"

# Upstream's own instruction (README.md, "Getting started" step 2): the version
# file is a template in the repository. Copying it here rather than shipping a
# copy in files/ keeps the recipe from pinning a version it does not control.
if [ ! -f src/serverlib/version.inc.php ]; then
    cp src/serverlib/version.default.inc.php src/serverlib/version.inc.php
fi

# b1gMail writes sessions, its parse cache and the cron lock under src/temp/,
# and the repository ships those directories with dummy files only.
mkdir -p src/temp/cache src/temp/session src/logs
