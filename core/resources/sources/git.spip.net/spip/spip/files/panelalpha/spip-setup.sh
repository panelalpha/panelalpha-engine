#!/bin/sh
# PanelAlpha setup for SPIP (git.spip.net/spip/spip). Runs in the app container
# before Apache, on every boot (start stage, before: true).
#
# Three jobs:
#
# 1. Lay out the application tree. SPIP 5 is a Composer distribution: the git
#    checkout is only spip.php + config + composer.json, and the app (ecrire/,
#    prive/, plugins-dist/, squelettes-dist/) is placed by the composer-plugin
#    spip-league/composer-installer. The engine's host PHP build runs composer
#    with --no-plugins (that plugin is not on its installer whitelist, and a
#    source recipe cannot override the build command -- see README "Engine
#    gap"), so the tree is left under vendor/ and the kernel will not boot.
#    This re-runs composer *inside the container*, where SPIP's own
#    config.allow-plugins limits execution to composer-installer + symfony/
#    runtime, which places the tree. vendor/ is already resolved by the host
#    build, so this is mostly the plugin's file placement. Gated on ecrire/
#    being absent, so a plain restart skips it.
#
# 2. Persist config + media across the redeploy that empties ~/project
#    (engine#173). SPIP_ETC_DIR (manifest env) points SPIP's config dir at
#    /pa-data/spip/config, so connect.php + cles.php (DB DSN + crypto keys)
#    live on the bind-mounted account home. That dir also has to carry SPIP's
#    framework config (config/spip/*.php) and mes_options.php, both copied in
#    below. IMG/ (uploaded media) has no env override and is symlinked onto
#    /pa-data.
#
# 3. Pre-fill the installer's database step (mes_options.php -> _INSTALL_*
#    constants from the account MySQL env) and write the hardened .htaccess.
#
# /pa-data is ~/.panelalpha bind-mounted by the recipe's compose override; it
# survives a redeploy, ~/project does not. HOME is unset here: absolute paths.
set -eu

PA_DATA="/pa-data/spip"
ETC="${PA_DATA}/config"
IMG="${PA_DATA}/IMG"

if [ ! -d /pa-data ]; then
	echo "panelalpha/spip: /pa-data is not mounted; the compose override did not apply" >&2
	exit 1
fi

cd /app

# --- 1. Lay out the application tree if the build's --no-plugins install did not.
if [ ! -d /app/ecrire ]; then
	echo "panelalpha/spip: laying out the SPIP tree (composer install with the layout plugin)" >&2
	COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_HOME=/tmp/composer \
		composer install --no-dev --no-interaction --no-scripts --optimize-autoloader \
		|| { echo "panelalpha/spip: composer layout failed" >&2; exit 1; }
fi

# --- 2. Persistent config dir + media.
umask 077
mkdir -p "${ETC}" "${IMG}"
chmod 700 "${PA_DATA}"
# The bind mount is owned by the account, which is who Apache runs as; SPIP must
# be able to write connect.php/cles.php here and read the config below.
chmod 777 "${ETC}" "${IMG}" 2>/dev/null || true

# SPIP loads its framework config from <config-dir>/spip/*.php; since
# SPIP_ETC_DIR moves the config dir onto /pa-data, that tree must be here too.
# Copied every boot so it tracks the deployed SPIP version.
if [ -d /app/config/spip ]; then
	cp -a /app/config/spip "${ETC}/" 2>/dev/null || cp -rf /app/config/spip "${ETC}/"
fi

# Installer pre-fill (defines _INSTALL_* from getenv at runtime). Copied every
# boot so a recipe update propagates.
cp -f panelalpha/mes_options.php "${ETC}/mes_options.php"
chmod 666 "${ETC}/mes_options.php" 2>/dev/null || true

# Uploaded media: move any freshly-created IMG/ onto /pa-data once, then symlink.
if [ -d /app/IMG ] && [ ! -L /app/IMG ]; then
	cp -an /app/IMG/. "${IMG}/" 2>/dev/null || true
	rm -rf /app/IMG
fi
ln -sfn "${IMG}" /app/IMG

# Never serve the clone's VCS metadata (the .htaccess blocks it too).
rm -rf /app/.git

# --- 4. Headless install seed. Create the schema + super-admin BEFORE Apache
# serves, so the site is never publicly reachable with an empty, first-visitor-
# wins installer (engine#200). Drives SPIP's own web wizard over a throwaway
# loopback php -S; idempotent (a no-op once a webmestre exists), so a redeploy
# just skips it. Non-fatal: if it cannot complete, the boot still serves and the
# owner can install by hand.
sh panelalpha/spip-install.sh \
	|| echo "panelalpha/spip: headless install incomplete; owner may install via ecrire/?exec=install" >&2

# --- 3. Document-root .htaccess: SPIP's own recommended rules plus an explicit
# deny of config/ and tmp/, written every boot from the shipped htaccess.txt.
if [ -f /app/htaccess.txt ]; then
	{
		echo '# --- PanelAlpha hardening (prepended) ---'
		echo 'RedirectMatch 403 (?i)/(config|tmp)(/|$)'
		echo 'RedirectMatch 403 (?i)/panelalpha(/|$)'
		echo 'RedirectMatch 404 (?i)/(htaccess\.txt|composer\.lock|CHANGELOG\.md|UPGRADE[^/]*\.md|SECURITY\.md|README\.md|\.env\.dist)$'
		echo '# --- SPIP htaccess.txt ---'
		cat /app/htaccess.txt
	} > /app/.htaccess
fi

echo "panelalpha/spip: ready. config -> ${ETC}, IMG -> ${IMG}; installer DB step pre-filled. Owner completes ecrire/?exec=install on first visit." >&2
