#!/bin/bash
# Closes the one door Bluecherry leaves open, and proves it closed.
#
# misc/sql/initial_data_mysql.sql -- loaded into every new database by
# bc_db_tool.sh new_db -- inserts a fixed administrator:
#
#   INSERT INTO Users (username, password, salt, ...) VALUES
#     ('Admin', 'b22dec1d6cfa580962f3a3796a5dc6b3', '1234', ...)
#
# and that hash is md5('bluecherry' . '1234'). www/lib/lib.php agrees: user::
# getInfo() sets default_password when password == md5('bluecherry'.salt), and
# checkPassword() is md5($password.$salt) === $password. There is no wizard and
# no forced change -- the UI shows a dismissible banner -- so on a public HTTPS
# name the account is Admin / bluecherry until someone changes it.
#
# The UPDATE is conditional on the row still carrying that exact default, which
# is the same test lib.php makes. That makes it idempotent across redeploys and
# keeps it from overwriting a password the customer has since set in the UI:
# once they change it, this matches nothing and does nothing.
#
# Always exits 0. `ready` waits for this service to complete successfully, so a
# non-zero exit would fail `docker compose up -d` and take a working deploy
# down with it.
set -u

say() { echo "[panelalpha-setup] $*"; }
finish() { exit 0; }

DB=(mysql -h "${BLUECHERRY_DB_HOST}" -u"${BLUECHERRY_DB_USER}" -p"${BLUECHERRY_DB_PASSWORD}" -D "${BLUECHERRY_DB_NAME}" -N -B)
BASE="${BLUECHERRY_APP_URL:-http://bluecherry}"

# The application container is healthy before this one starts, which means
# nginx and php-fpm answer -- but the entrypoint creates the schema after it
# starts them, so Users may not exist yet.
i=0
while [ "${i}" -lt 90 ]; do
    if "${DB[@]}" -e "SELECT 1 FROM Users LIMIT 1" >/dev/null 2>&1; then
        break
    fi
    i=$((i + 1))
    sleep 2
done
if ! "${DB[@]}" -e "SELECT 1 FROM Users LIMIT 1" >/dev/null 2>&1; then
    say "the Users table never appeared; leaving the database alone"
    finish
fi

# SET is evaluated against the pre-update row, so the WHERE clause still sees
# the old salt while the new one is being written.
if ! "${DB[@]}" -e "UPDATE Users
        SET salt = '${BLUECHERRY_ADMIN_SALT}',
            password = MD5(CONCAT('${BLUECHERRY_ADMIN_PASSWORD}', '${BLUECHERRY_ADMIN_SALT}'))
      WHERE username = '${BLUECHERRY_ADMIN_USERNAME}'
        AND password = MD5(CONCAT('bluecherry', salt));" 2>&1; then
    say "could not update the administrator's password"
    finish
fi

still_default=$("${DB[@]}" -e "SELECT COUNT(*) FROM Users
      WHERE password = MD5(CONCAT('bluecherry', salt));" 2>/dev/null)
if [ "${still_default:-0}" != "0" ]; then
    say "WARNING: ${still_default} account(s) still have the shipped default password"
fi

# On a redeploy of an account whose owner has since changed their password in
# the UI, the row matches neither the shipped default nor the generated
# credential -- the update above correctly did nothing, and signing in with the
# generated password would fail. That is the desired outcome, not a fault, so
# say so and stop rather than logging a refusal that looks like one.
ours=$("${DB[@]}" -e "SELECT COUNT(*) FROM Users
      WHERE username = '${BLUECHERRY_ADMIN_USERNAME}'
        AND password = MD5(CONCAT('${BLUECHERRY_ADMIN_PASSWORD}', salt));" 2>/dev/null)
if [ "${ours:-0}" = "0" ]; then
    say "${BLUECHERRY_ADMIN_USERNAME}'s password is neither the shipped default nor the generated one; it has been changed in the UI and is left alone"
    finish
fi

# The sign-in is the point: it proves the generated credential works against
# the row the database actually holds, rather than that a container started.
# ajax/login.php answers a POST to /login with Reply::ajaxDie, so a success is
# {"status":"1","msg":"\/",...} and a failure is {"status":"2",...}.
JAR=$(mktemp)
curl -fsS -m 20 -c "${JAR}" -o /dev/null "${BASE}/login" 2>/dev/null || {
    say "GET /login did not answer; skipping the sign-in check"
    finish
}
BODY=$(curl -fsS -m 20 -b "${JAR}" -c "${JAR}" \
    --data-urlencode "login=${BLUECHERRY_ADMIN_USERNAME}" \
    --data-urlencode "password=${BLUECHERRY_ADMIN_PASSWORD}" \
    "${BASE}/login" 2>/dev/null)
case "${BODY}" in
    *'"status":"1"'*) say "signed in as ${BLUECHERRY_ADMIN_USERNAME}" ;;
    *) say "sign-in as ${BLUECHERRY_ADMIN_USERNAME} was refused: ${BODY}" ; finish ;;
esac

# And that the session it handed back reaches an admin page rather than being
# bounced to /login by user::checkAccessPermissions(). /devices is an
# access_setup page, so only a real administrator session reaches it.
CODE=$(curl -s -m 20 -b "${JAR}" -o /dev/null -w '%{http_code}' "${BASE}/devices" 2>/dev/null)
say "authenticated GET /devices returned ${CODE}"

rm -f "${JAR}"
finish
