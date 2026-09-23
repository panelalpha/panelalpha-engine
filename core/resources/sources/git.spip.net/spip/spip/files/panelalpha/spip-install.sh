#!/bin/sh
# PanelAlpha headless install for SPIP 5. Runs in the app container during the
# start hook, BEFORE Apache serves, so the site is never publicly reachable with
# an empty, first-visitor-wins installer (engine#200).
#
# SPIP ships no CLI installer and its boot is not CLI-safe on an empty database
# (the SQL-error path renders an HTML template and reads HTTP inputs, which
# aborts under php-cli). So this drives SPIP's OWN web wizard over a throwaway
# `php -S` bound to loopback only -- SPIP's real install code, run exactly as it
# runs for a browser, but reachable by nothing but this script. The database
# step is answered from mes_options.php's _INSTALL_* constants, so the walk is
# non-interactive; the admin account is created at the etape_3b POST.
#
# Idempotent: if a webmestre already exists (a redeploy: the DB and connect.php
# persist on /pa-data) it exits at once. The admin password is generated ONCE
# into ~/.panelalpha/spip/admin-credentials.txt (0600) and reused.
set -u

ETC="${SPIP_ETC_DIR:-/pa-data/spip/config}"
PA="/pa-data/spip"
CREDS="${PA}/admin-credentials.txt"
APP_URL="$(printf '%s' "${APP_URL:-}" | sed 's#/*$##')"
PORT=8399
CJ=/tmp/pa-spip-cj

DB_HOST="${DB_HOST:-}"; DB_DATABASE="${DB_DATABASE:-}"; DB_USERNAME="${DB_USERNAME:-}"
if [ -z "${DB_HOST}" ] || [ -z "${DB_DATABASE}" ]; then
	echo "panelalpha/spip: no DB env; leaving install to the owner" >&2
	exit 0
fi

# --- Idempotency: a webmestre already means the site is installed.
has_webmestre() {
	php -r '$c=@new mysqli(getenv("DB_HOST"),getenv("DB_USERNAME"),getenv("DB_PASSWORD"),getenv("DB_DATABASE"),(int)(getenv("DB_PORT")?:3306));
	if(!$c||$c->connect_errno) exit(2);
	$r=@$c->query("SELECT id_auteur FROM spip_auteurs WHERE webmestre=\x27oui\x27 LIMIT 1");
	exit(($r && $r->num_rows>0)?0:1);' 2>/dev/null
}
if has_webmestre; then
	echo "panelalpha/spip: already installed (webmestre present); skipping" >&2
	exit 0
fi

# First install: the wizard writes config/connect.php itself, so remove any
# stale/partial one that would make it report "already installed".
rm -f "${ETC}/connect.php"

# --- Admin password: generate once, reuse thereafter.
PW=""
if [ -f "${CREDS}" ]; then
	PW="$(sed -n 's/^password:[[:space:]]*//p' "${CREDS}" | head -1)"
fi
[ -n "${PW}" ] || PW="$(php -r 'echo bin2hex(random_bytes(12));')"
MAILDOM="$(printf '%s' "${APP_URL}" | sed 's#^[a-z]*://##;s#/.*##;s#:.*##')"
[ -n "${MAILDOM}" ] || MAILDOM="localhost"

# --- Throwaway loopback server running SPIP. PHP_CLI_SERVER_WORKERS gives it
# concurrency so SPIP's own self-requests during install do not deadlock a
# single-threaded server.
rm -f "${CJ}"
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:${PORT} -t /app >/tmp/pa-spip-phps.log 2>&1 &
SRV=$!
# wait for it to answer
i=0; while [ $i -lt 30 ]; do
	curl -s -o /dev/null --max-time 5 "http://127.0.0.1:${PORT}/" && break
	i=$((i+1)); sleep 1
done

B="http://127.0.0.1:${PORT}/ecrire/?exec=install"
step() { curl -s --max-time 90 -c "${CJ}" -b "${CJ}" "$1" -o /tmp/pa-spip-step.html -w '%{http_code}'; }

step "${B}&var_lang_ecrire=en" >/dev/null
step "${B}&etape=chmod"        >/dev/null
step "${B}&etape=1"            >/dev/null
step "${B}&etape=2"            >/dev/null
step "${B}&etape=3&choix_db=${DB_DATABASE}&sel_db=${DB_DATABASE}" >/dev/null
# etape_3b: create the super-admin (server-side hashing, SPIP's own crypto).
curl -s --max-time 90 -c "${CJ}" -b "${CJ}" "${B}&etape=3b" \
	--data-urlencode exec=install --data-urlencode etape=3b \
	--data-urlencode nom=Administrator \
	--data-urlencode "email=admin@${MAILDOM}" \
	--data-urlencode login=admin \
	--data-urlencode "pass=${PW}" --data-urlencode "pass_verif=${PW}" \
	-o /tmp/pa-spip-3b.html -w '%{http_code}' >/dev/null
# etape_4: activate the bundled plugins.
step "${B}&etape=4" >/dev/null
# etape_fin: finalises config (renames connect.tmp.php -> connect.php) and writes
# the .htaccess access file. It ends in a redirect / self-request, so it is given
# its own bounded timeout; the rename it does happens first regardless.
curl -s --max-time 45 -c "${CJ}" -b "${CJ}" "${B}&etape=fin" -o /dev/null 2>/dev/null || true

kill "${SRV}" 2>/dev/null

# Safety net: guarantee the principal connection + chmod files are finalised
# even if etape_fin was cut short (the rename is deterministic).
[ -f "${ETC}/connect.php" ] || { [ -f "${ETC}/connect.tmp.php" ] && cp -f "${ETC}/connect.tmp.php" "${ETC}/connect.php"; }
[ -f "${ETC}/chmod.php" ]   || { [ -f "${ETC}/chmod.tmp.php" ]   && cp -f "${ETC}/chmod.tmp.php"   "${ETC}/chmod.php"; }
rm -f "${ETC}/connect.tmp.php" "${ETC}/chmod.tmp.php" 2>/dev/null || true

# --- Verify, then fix the stored site address (the walk ran over 127.0.0.1).
if ! has_webmestre; then
	echo "panelalpha/spip: headless install did not create a webmestre; owner may finish via ecrire/?exec=install" >&2
	# tail the wizard's last screen for the deploy log
	sed -e 's/<[^>]*>/ /g' /tmp/pa-spip-3b.html 2>/dev/null | tr -s ' \n' ' \n' | grep -iE 'error|erreur' | head -3 >&2
	exit 1
fi

php -r '$u=rtrim(getenv("APP_URL"),"/"); if($u==="") exit;
$c=@new mysqli(getenv("DB_HOST"),getenv("DB_USERNAME"),getenv("DB_PASSWORD"),getenv("DB_DATABASE"),(int)(getenv("DB_PORT")?:3306));
if(!$c||$c->connect_errno) exit;
$s=$c->prepare("UPDATE spip_meta SET valeur=? WHERE nom=\x27adresse_site\x27"); $s->bind_param("s",$u); $s->execute();' 2>/dev/null
# drop the compiled cache so the corrected adresse_site takes effect on first hit
rm -rf /app/tmp/cache/* 2>/dev/null || true

# --- Store the credentials for the owner (0600), plaintext nowhere else.
umask 077
printf 'url:      %s/ecrire/\nlogin:    admin\npassword: %s\n' "${APP_URL}" "${PW}" > "${CREDS}"
chmod 600 "${CREDS}" 2>/dev/null || true
rm -f "${CJ}" /tmp/pa-spip-step.html /tmp/pa-spip-3b.html
echo "panelalpha/spip: headless install complete; admin credentials at ~/.panelalpha/spip/admin-credentials.txt" >&2
exit 0
