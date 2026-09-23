#!/bin/sh
# PanelAlpha setup for Galette (github.com/galette/galette). Runs in the app
# container BEFORE Apache, on every boot (start stage, before: true). /app is the
# galette/ tree (app_root: galette).
#
# Three jobs, all idempotent:
#   1. Persist config/ and data/ onto /pa-data (~/.panelalpha), which survives the
#      redeploy that empties ~/project (engine#173); ~/project does not.
#   2. Headless install BEFORE the site is publicly reachable: create the schema
#      and a generated-password super-admin, so the first visitor never meets an
#      open installer. Skipped once config.inc.php exists in persistent storage.
#   3. Lock the first-visitor-wins web installer.
#
# HOME is unset here: absolute paths only.
set -eu

PA_DATA="/pa-data/galette"
CFG="${PA_DATA}/config"
DATA="${PA_DATA}/data"
SECRETS="${PA_DATA}/secrets"

if [ ! -d /pa-data ]; then
	echo "panelalpha/galette: /pa-data is not mounted; the compose override did not apply" >&2
	exit 1
fi

cd /app

# --- 1. Persistent config/ + data/.
umask 077
mkdir -p "${PA_DATA}" "${SECRETS}"
chmod 700 "${PA_DATA}" "${SECRETS}"

# Seed persistent copies from the fresh checkout once (keeps Galette's shipped
# data/ subdir skeleton and the deny-.htaccess/index.php guards), then replace
# the checkout dirs with symlinks onto persistent storage.
if [ ! -e "${CFG}" ] && [ -d /app/config ]; then
	cp -a /app/config "${CFG}"
fi
if [ ! -e "${DATA}" ] && [ -d /app/data ]; then
	cp -a /app/data "${DATA}"
fi
# The bind mount is owned by the account, which is who Apache runs as; Galette
# must be able to write config.inc.php here and everything under data/.
chmod -R u+rwX "${CFG}" "${DATA}" 2>/dev/null || true

rm -rf /app/config /app/data
ln -sfn "${CFG}" /app/config
ln -sfn "${DATA}" /app/data

# --- 2. Headless install. config.inc.php in persistent storage == already
# installed, so a redeploy just re-links (above) and re-locks (below).
if [ ! -f "${CFG}/config.inc.php" ]; then
	echo "panelalpha/galette: no config found, running headless install" >&2

	if [ -z "${DB_HOST:-}" ] || [ -z "${DB_DATABASE:-}" ] || [ -z "${DB_USERNAME:-}" ]; then
		echo "panelalpha/galette: DB_* env is not set; cannot install" >&2
		exit 1
	fi

	# Super-admin credentials: generated once, 0600, reused, never logged.
	if [ ! -f "${SECRETS}/admin.txt" ]; then
		_user="superadmin"
		_pass="$(tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 24)"
		printf 'username=%s\npassword=%s\n' "${_user}" "${_pass}" > "${SECRETS}/admin.txt"
		chmod 600 "${SECRETS}/admin.txt"
	fi
	ADMIN_USER="$(sed -n 's/^username=//p' "${SECRETS}/admin.txt")"
	ADMIN_PASS="$(sed -n 's/^password=//p' "${SECRETS}/admin.txt")"

	php /app/panelalpha/galette-console.php galette:install \
		--dbtype=mysql \
		--dbhost="${DB_HOST}" \
		--dbport="${DB_PORT:-3306}" \
		--dbname="${DB_DATABASE}" \
		--dbuser="${DB_USERNAME}" \
		--dbpass="${DB_PASSWORD:-}" \
		--dbprefix=galette_ \
		--admin="${ADMIN_USER}" \
		--password="${ADMIN_PASS}" \
		--write-config \
		--no-interaction \
		|| { echo "panelalpha/galette: headless install failed" >&2; exit 1; }

	echo "panelalpha/galette: install complete; super-admin credentials in ~/.panelalpha/galette/secrets/admin.txt" >&2
fi

# --- 3. Lock the installer. Galette's webroot/installer.php never gates on an
# existing install, so remove it (and the unauthenticated test scripts) from the
# document root. Re-done every boot because ~/project is re-cloned each deploy.
rm -f /app/webroot/installer.php /app/webroot/compat_test.php /app/webroot/post_contribution_test.php

# Never serve the clone's VCS metadata.
rm -rf /app/.git

echo "panelalpha/galette: ready (config -> ${CFG}, data -> ${DATA}); installer locked." >&2
