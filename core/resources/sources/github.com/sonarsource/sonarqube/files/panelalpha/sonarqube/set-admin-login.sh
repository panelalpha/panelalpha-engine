#!/bin/bash
# Sets the engine's login on SonarQube's built-in admin while it still has the
# default password `admin`, through SonarQube's own web API (api/users/
# update_login and api/users/change_password, as the admin itself). Run by the
# `admin-login` one-shot once /api/system/status is UP. A password changed in
# SonarQube is never touched, and a failure never holds the stack back.
set -u
base=http://app:9000
name="${SONARQUBE_ADMIN_USERNAME:-admin}"
password="${SONARQUBE_ADMIN_PASSWORD:-}"
say() { echo "[panelalpha] sonarqube: $*" >&2; }

# Basic auth as <login>:<password> is accepted: api/authentication/validate says so.
valid() {
    curl -fsS -u "$1:$2" "${base}/api/authentication/validate" 2>/dev/null | grep -q '"valid":true'
}

if [ -z "${password}" ]; then
    say "no SONARQUBE_ADMIN_PASSWORD; the admin login is left as it is"
    exit 0
fi

# A new database gets admin/admin. Rename it first when the engine's login is
# another name, so a rename that went through is not repeated.
if [ "${name}" != admin ] && valid admin admin; then
    curl -fsS -o /dev/null -u admin:admin -X POST "${base}/api/users/update_login" \
        --data-urlencode login=admin --data-urlencode "newLogin=${name}" \
        || { say "could not rename the admin; it stays admin/admin"; exit 0; }
fi

if ! valid "${name}" admin; then
    say "the admin no longer has the default password; left alone"
    exit 0
fi

# The admin changing its own password, as the first sign-in asks: this also
# clears that request.
if curl -fsS -o /dev/null -u "${name}:admin" -X POST "${base}/api/users/change_password" \
    --data-urlencode "login=${name}" --data-urlencode previousPassword=admin \
    --data-urlencode "password=${password}"; then
    say "the admin's login is the one the engine generated"
else
    say "could not set the admin password; it stays ${name}/admin"
fi
exit 0
