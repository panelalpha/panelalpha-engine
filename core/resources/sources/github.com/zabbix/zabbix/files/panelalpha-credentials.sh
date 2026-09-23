#!/bin/bash
# Runs once per deploy as the `credentials` service, after zabbix-server has
# imported the schema and before zabbix-web -- the only service with a
# published port -- is allowed to start.
#
# Zabbix has no installer and no admin environment variables. create.sql.gz
# inserts two users with hashes that are identical in every installation on
# earth, and a stock deployment on a public name is therefore owned by whoever
# opens it first. This replaces both and refuses to let the frontend start if
# it could not.
set -euo pipefail

# The hashes upstream ships, from create.sql.gz: userid 1 "Admin" (password
# "zabbix") and userid 2 "guest" (empty password). Matched on rather than
# matched by username, so a password the customer sets themselves is never
# reverted by a redeploy -- the UPDATE simply finds nothing to do.
readonly SHIPPED_ADMIN='$2y$10$92nDno4n0Zm7Ej7Jfsz8WukBfgSS/U0QkIuu8WkJPihXBb2A1UrEK'
readonly SHIPPED_GUEST='$2y$10$89otZrRNmde97rIyzclecuk6LwKAsHN0BcvoOKGjbT.BwMBfm7G06'

# ADMIN_HASH and GUEST_HASH, single-quoted, written by hooks/prepare.sh. A
# bcrypt hash cannot contain a quote, so sourcing is safe; it is also the only
# way to move a value full of '$' through a compose file without fighting
# interpolation.
[ -r /panelalpha-admin-hashes ] || { echo "[panelalpha] missing /panelalpha-admin-hashes" >&2; exit 1; }
# shellcheck disable=SC1091
source /panelalpha-admin-hashes
[ -n "${ADMIN_HASH:-}" ] && [ -n "${GUEST_HASH:-}" ] \
    || { echo "[panelalpha] admin hashes are empty" >&2; exit 1; }

q() {
    MYSQL_PWD="${ZBX_DB_ROOT_PASSWORD}" mariadb \
        --skip-ssl-verify-server-cert \
        -h "${ZBX_DB_HOST}" -u root -N -B "${ZBX_DB_NAME}" -e "$1"
}

# zabbix-server's healthcheck (:10051 open) means the schema import finished,
# but the two are not the same instant on a slow first boot, so this waits for
# the table rather than trusting the ordering.
for _ in $(seq 1 90); do
    if [ "$(q "SELECT COUNT(*) FROM information_schema.tables \
               WHERE table_schema='${ZBX_DB_NAME}' AND table_name='users'" 2>/dev/null || echo 0)" = "1" ]; then
        break
    fi
    sleep 2
done

q "UPDATE users SET passwd='${ADMIN_HASH}' WHERE passwd='${SHIPPED_ADMIN}'"
q "UPDATE users SET passwd='${GUEST_HASH}' WHERE passwd='${SHIPPED_GUEST}'"

# The gate. Anything still on a published hash and the frontend does not start:
# zabbix-web's depends_on is service_completed_successfully, so a non-zero exit
# here leaves the published port closed rather than open on a known password.
left="$(q "SELECT COUNT(*) FROM users WHERE passwd IN ('${SHIPPED_ADMIN}','${SHIPPED_GUEST}')")"
if [ "${left}" != "0" ]; then
    echo "[panelalpha] refusing to publish the frontend: ${left} account(s) still on Zabbix's shipped password" >&2
    exit 1
fi

echo "[panelalpha] per-account credentials in place; no user is on a shipped password"

# Not a credential, and deliberately not part of the gate: the default
# "Zabbix server" host's agent interface is 127.0.0.1:10050 in the shipped
# schema, which inside the server's own container is nothing. Pointed at the
# agent sidecar instead, so the stock template collects rather than raising
# "Zabbix agent is not available" on an account nobody has configured yet.
#
# Keyed on the shipped 127.0.0.1 so a customer who repoints it keeps their
# choice, and `|| true` because a frontend that starts with one noisy host is
# far better than one that does not start at all.
q "UPDATE interface SET useip=0, dns='zabbix-agent', port='10050'
   WHERE type=1 AND useip=1 AND ip='127.0.0.1'
     AND hostid IN (SELECT hostid FROM hosts WHERE host='Zabbix server')" || true
