#!/bin/bash
# The image's own command. A dockerfile-strategy project runs its entrypoint and
# the engine generates none, so everything a first boot needs happens here.
set -euo pipefail

log() { echo "[panelalpha/sogo] $*"; }

DB_HOST="${SOGO_DB_HOST:-db}"
DB_PORT="${SOGO_DB_PORT:-3306}"
DB_NAME="${MARIADB_DATABASE:-sogo}"
DB_USER="${MARIADB_USER:-sogo}"
DB_PASS="${MARIADB_PASSWORD:-}"
ADMIN_USER="${SOGO_ADMIN_USER:-sogoadmin}"
ADMIN_PASS="${SOGO_ADMIN_PASSWORD:-}"
ADMIN_MAIL="${SOGO_ADMIN_EMAIL:-${SOGO_ADMIN_USER:-sogoadmin}@localhost}"

if [ -z "$DB_PASS" ]; then
    log "FATAL: MARIADB_PASSWORD is empty. The prepare hook writes it into"
    log "       ~/.panelalpha/sogo/sogo.env; the compose override reads that"
    log "       file as a second env_file. Neither happened."
    exit 1
fi

mysql_do() {
    mariadb --protocol=TCP -h "$DB_HOST" -P "$DB_PORT" \
            -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" "$@"
}

log "waiting for $DB_HOST:$DB_PORT"
for _ in $(seq 1 120); do
    if mysql_do -e "SELECT 1" >/dev/null 2>&1; then break; fi
    sleep 2
done
mysql_do -e "SELECT 1" >/dev/null

# SOGo creates its own quick/folder tables on demand through GDLContentStore.
# The one table it never creates is the user directory: an SQL source reads a
# view or table the operator supplies. The columns are the five SQLSource.m:59-66
# documents as mandatory.
log "ensuring sogo_users"
mysql_do <<'SQL'
CREATE TABLE IF NOT EXISTS sogo_users (
  c_uid      VARCHAR(190) NOT NULL PRIMARY KEY,
  c_name     VARCHAR(190) NOT NULL,
  c_password VARCHAR(255) NOT NULL,
  c_cn       VARCHAR(190) DEFAULT NULL,
  mail       VARCHAR(190) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL

# The first account. Created once; a redeploy finds it and leaves the password
# alone, because the operator may well have changed it by then.
if [ -n "$ADMIN_PASS" ]; then
    existing=$(mysql_do -N -B -e \
        "SELECT COUNT(*) FROM sogo_users WHERE c_uid = '$(printf %s "$ADMIN_USER" | sed "s/'/''/g")'")
    if [ "$existing" = "0" ]; then
        hash=$(openssl passwd -6 "$ADMIN_PASS")
        mysql_do -e "INSERT INTO sogo_users (c_uid, c_name, c_password, c_cn, mail)
                     VALUES ('$ADMIN_USER', '$ADMIN_USER', '$hash', 'Administrator', '$ADMIN_MAIL')"
        log "created the first user: $ADMIN_USER"
    fi
fi

# /etc/sogo/sogo.conf is the file SOGoSystemDefaults.m:146-147 reads, and it
# carries the database password in five URLs. 0640 root:sogo -- readable by
# sogod, by nobody the web server can be talked into serving, and it is not
# under any document root: nginx in this image serves exactly
# /usr/local/lib/GNUstep/SOGo/WebServerResources and proxies /SOGo.
umask 027
conf=/etc/sogo/sogo.conf
{
    echo '{'
    echo "  SOGoProfileURL = \"mysql://${DB_USER}:${DB_PASS}@${DB_HOST}:${DB_PORT}/${DB_NAME}/sogo_user_profile\";"
    echo "  OCSFolderInfoURL = \"mysql://${DB_USER}:${DB_PASS}@${DB_HOST}:${DB_PORT}/${DB_NAME}/sogo_folder_info\";"
    echo "  OCSSessionsFolderURL = \"mysql://${DB_USER}:${DB_PASS}@${DB_HOST}:${DB_PORT}/${DB_NAME}/sogo_sessions_folder\";"
    echo "  OCSEMailAlarmsFolderURL = \"mysql://${DB_USER}:${DB_PASS}@${DB_HOST}:${DB_PORT}/${DB_NAME}/sogo_alarms_folder\";"
    # GCSAdminFolder logs "'OCSAdminURL' is not set" on every worker start
    # without it; it is where SOGo keeps administrative ACL state.
    echo "  OCSAdminURL = \"mysql://${DB_USER}:${DB_PASS}@${DB_HOST}:${DB_PORT}/${DB_NAME}/sogo_admin\";"
    echo "  SOGoUserSources = ("
    echo '    {'
    echo '      type = sql;'
    echo '      id = directory;'
      # SOGoUserManager refuses an address-book source with no displayname.
      echo '      displayName = "Directory";'
    echo "      viewURL = \"mysql://${DB_USER}:${DB_PASS}@${DB_HOST}:${DB_PORT}/${DB_NAME}/sogo_users\";"
    echo '      canAuthenticate = YES;'
    echo '      isAddressBook = YES;'
    echo '      userPasswordAlgorithm = "sha512-crypt";'
    echo '    }'
    echo '  );'
    echo "  SOGoMemcachedHost = \"${SOGO_MEMCACHED_HOST:-memcached:11211}\";"
    echo "  SOGoTimeZone = \"${SOGO_TIMEZONE:-UTC}\";"
    echo "  SOGoLanguage = ${SOGO_LANGUAGE:-English};"
    echo "  SOGoPageTitle = \"${SOGO_PAGE_TITLE:-SOGo}\";"
    # Calendar rather than Mail: a deploy with no IMAP server named must not
    # land its first visitor on the one module that cannot work yet.
    echo "  SOGoLoginModule = ${SOGO_LOGIN_MODULE:-Calendar};"
    echo "  SOGoSuperUsernames = (\"${ADMIN_USER}\");"
    echo "  WOWorkersCount = ${SOGO_WORKERS:-3};"
    echo '  SOGoMaximumPingInterval = 3540;'
    echo '  SOGoMaximumSyncInterval = 3540;'
    # SOGo is a mail *client*. Both of these name somebody else's server, and
    # both are empty unless the account owner sets them: this platform never
    # hosts the mailbox, and an unset IMAP server simply leaves the Mail module
    # without an account rather than breaking Calendar and Contacts.
    if [ -n "${SOGO_IMAP_SERVER:-}" ]; then
        echo "  SOGoIMAPServer = \"${SOGO_IMAP_SERVER}\";"
    fi
    if [ -n "${SOGO_SMTP_SERVER:-}" ]; then
        echo '  SOGoMailingMechanism = smtp;'
        echo "  SOGoSMTPServer = \"${SOGO_SMTP_SERVER}\";"
    fi
    echo '}'
} > "$conf"
chown root:sogo "$conf"
chmod 640 "$conf"
log "wrote $conf"

cleanup() { kill "${sogod_pid:-0}" "${nginx_pid:-0}" 2>/dev/null || true; }
trap cleanup TERM INT

# -WONoDetach YES matters more than it looks. sogod is a WOWatchDogApplication
# and daemonizes by default -- packaging/debian's unit is Type=forking. Started
# without it the parent exits 0 the moment the real daemon is forked away, and
# `wait -n` below reads that as "sogod is gone" and takes the container down
# with it, once a minute, forever, while sogod itself is up and answering. The
# option is spelled WONoDetach in libNGObjWeb 4.9.
log "starting sogod"
su -s /bin/bash -c \
   "exec /usr/local/sbin/sogod -WONoDetach YES -WOWorkersCount ${SOGO_WORKERS:-3} -WOPort 127.0.0.1:20000 -WOLogFile -" \
   sogo &
sogod_pid=$!

log "starting nginx"
nginx -g 'daemon off;' &
nginx_pid=$!

# Whichever dies first takes the container with it, so a dead sogod behind a
# live nginx is a restart and not a site that 502s forever.
wait -n "$sogod_pid" "$nginx_pid"
status=$?
log "a child exited with $status; stopping"
cleanup
exit "$status"
