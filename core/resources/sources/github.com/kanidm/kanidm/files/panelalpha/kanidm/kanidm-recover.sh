#!/bin/bash
# Run by the `recover` service once kanidmd is healthy. A new kanidm database
# has two built-in admins with no password: `idm_admin` (people, groups) and
# `admin` (the server's own settings, OAuth2). Upstream gives them one with
# `kanidmd recover-account <name>`, a random password; kanidm offers no way to
# set a chosen one on them (they are protected service accounts), and a person
# account cannot sign in with a password alone (MFA by default). So this runs
# recover-account for both, once, and writes the passwords to
# ~/.panelalpha/kanidm/admins.env (mounted at /panelalpha-out; 0600, owned
# by the account like the directory).
#
# Once per database: /data/.panelalpha-recovered marks a database whose
# passwords are in admins.env. While the file's idm_admin password signs in,
# or the marker says they were recovered here before (someone recovered or
# changed them since), nothing is touched. Delete admins.env to recover both
# again on the next deploy. Always exits 0.
set -u

API=https://app:8443
OUT=/panelalpha-out/admins.env
MARK=/data/.panelalpha-recovered

log() { echo "[panelalpha] kanidm: $*"; }

# JSON string escape (\ and ").
esc() { printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g'; }
api() { curl -ksS --max-time 30 -H 'Content-Type: application/json' "$@"; }

# signs_in NAME PASSWORD: kanidm's own auth flow (init, password, credential).
signs_in() {
    local hdr sid
    hdr=$(mktemp)
    api -o /dev/null -D "$hdr" -d "{\"step\":{\"init2\":{\"username\":\"$(esc "$1")\",\"issue\":\"token\",\"privileged\":false}}}" "$API/v1/auth" || { rm -f "$hdr"; return 1; }
    sid=$(tr -d '\r' < "$hdr" | sed -n 's/^[Xx]-[Kk][Aa][Nn][Ii][Dd][Mm]-[Aa][Uu][Tt][Hh]-[Ss][Ee][Ss][Ss][Ii][Oo][Nn]-[Ii][Dd]: *//p')
    rm -f "$hdr"
    [ -n "$sid" ] || return 1
    api -o /dev/null -H "X-KANIDM-AUTH-SESSION-ID: $sid" -d '{"step":{"begin":"password"}}' "$API/v1/auth" || return 1
    api -H "X-KANIDM-AUTH-SESSION-ID: $sid" -d "{\"step\":{\"cred\":{\"password\":\"$(esc "$2")\"}}}" "$API/v1/auth" \
        | grep -q '"success"'
}

# recover NAME: prints a new random password for NAME, or nothing.
recover() {
    kanidmd scripting recover-account "$1" 2>/dev/null | sed -n 's/.*"status":"ok".*"output":"\([^"]*\)".*/\1/p; s/.*"output":"\([^"]*\)".*"status":"ok".*/\1/p' | tail -n 1
}

if [ -s "$OUT" ]; then
    have=$(sed -n "s/^IDM_ADMIN_PASSWORD='\(.*\)'\$/\1/p" "$OUT")
    if [ -n "$have" ] && signs_in idm_admin "$have"; then
        log "idm_admin and admin: passwords in ~/.panelalpha/kanidm/admins.env"
        exit 0
    fi
    if [ -f "$MARK" ]; then
        log "idm_admin no longer signs in with ~/.panelalpha/kanidm/admins.env (recovered or changed since); leaving it. Delete the file to recover both admins again on the next deploy."
        exit 0
    fi
fi

IDM_PW=$(recover idm_admin)
ADMIN_PW=$(recover admin)
if [ -z "$IDM_PW" ] || [ -z "$ADMIN_PW" ]; then
    echo "[panelalpha] kanidm: kanidmd recover-account failed; run it by hand (see the recipe README)" >&2
    exit 0
fi

tmp="$OUT.tmp"
(umask 077
 {
     echo "# kanidm's built-in admins, from kanidmd recover-account (the recipe's recover service)."
     echo "# Sign in at ${KANIDM_ORIGIN:-the site}/ui/login. idm_admin: people and groups; admin: server settings, OAuth2."
     echo "IDM_ADMIN_USERNAME='idm_admin'"
     echo "IDM_ADMIN_PASSWORD='$IDM_PW'"
     echo "ADMIN_USERNAME='admin'"
     echo "ADMIN_PASSWORD='$ADMIN_PW'"
 } > "$tmp") \
    && chown "$(stat -c %u:%g /panelalpha-out)" "$tmp" && chmod 600 "$tmp" && mv -f "$tmp" "$OUT" \
    || { echo "[panelalpha] kanidm: could not write ~/.panelalpha/kanidm/admins.env" >&2; exit 0; }
touch "$MARK"

if signs_in idm_admin "$IDM_PW"; then
    log "recovered idm_admin and admin; their passwords are in ~/.panelalpha/kanidm/admins.env"
else
    echo "[panelalpha] kanidm: idm_admin does not sign in with the recovered password" >&2
fi
exit 0
