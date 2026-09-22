#!/bin/bash
# Three things that can only be done once Piler's schema exists and its web UI
# answers, and one of them is a security fix.
#
# 1. The shipped accounts. util/db-mysql.sql, which start.sh loads into every
#    empty database, inserts two rows:
#
#      (0, 'admin',   ..., '$1$PItc7d$zsUgON3JRrbdGS11t9JQW1', 1, 'local')
#      (1, 'auditor', ..., '$1$SLIIIS$JMBwGqQg4lIir2P2YU1y.0', 2, 'local')
#
#    with emails admin@local and auditor@local. Those are md5-crypt hashes of
#    'pilerrocks' and 'auditor' -- constants in a public GPL repository, and
#    webui/model/user/auth.php's checkFallbackLogin() authenticates against them
#    with a plain crypt() comparison before it tries LDAP or IMAP. A fresh
#    instance on a public HTTPS name therefore accepts a full administrator
#    login from anyone who has read the repository. There is no first-run wizard
#    and no sign-up page to close: replacing the rows is the fix.
#
#    The UPDATE is conditioned on the hash still being exactly the shipped one,
#    so it is idempotent across redeploys and never overwrites a password the
#    customer has since chosen in Settings. When it matches nothing the script
#    says so and skips ahead rather than reporting a refusal.
#
# 2. SITE_URL. etc/config-site.dist.php hardcodes
#    $config['SITE_URL'] = 'http://' . SITE_NAME . PATH_PREFIX, and start.sh
#    only substitutes the hostname into it. Every redirect Piler issues -- after
#    login, after saving a user, after logout -- is an absolute Location built
#    from that, so on an account whose TLS is terminated at the engine's proxy
#    the browser is bounced to http:// on each one. config-site.php is created
#    on the first boot inside the piler_etc volume, which is why this is
#    appended here rather than written by prepare.sh into a checkout the next
#    deploy deletes.
#
# 3. The proof. Signing in with the generated password is the only thing that
#    shows the replacement actually took: that the hash format crypt() was fed
#    round-trips, that the row it landed on is the one the login query finds,
#    and that the UI is past "the container started".
#
# Always exits 0. The `ready` gate depends on this service completing
# successfully, so a non-zero exit would fail `docker compose up -d` and take an
# otherwise working deploy down with it.

CONFIG_SITE=/etc/piler/config-site.php
BASE="${PILER_SETUP_URL:-http://piler}"

# The two hashes db-mysql.sql ships. Matched literally.
DEFAULT_ADMIN_HASH='$1$PItc7d$zsUgON3JRrbdGS11t9JQW1'
DEFAULT_AUDITOR_HASH='$1$SLIIIS$JMBwGqQg4lIir2P2YU1y.0'

say() { echo "[panelalpha-setup] $*"; }
finish() { exit 0; }

sql() {
    mysql --protocol=TCP -h "${MYSQL_HOSTNAME}" -u "${MYSQL_USER}" \
        -p"${MYSQL_PASSWORD}" -N -B "${MYSQL_DATABASE}" 2>/dev/null
}

if [ -z "${PILER_ADMIN_PASSWORD}" ] || [ -z "${MYSQL_PASSWORD}" ]; then
    say "no generated credentials in the environment; leaving the shipped accounts alone"
    finish
fi

# ---------------------------------------------------------------------------
# 1. Replace the shipped credentials.
#
# crypt() is what authenticates (auth.php:97), and webui/system/misc.php's own
# encrypt_password() writes '$6$rounds=5000$<salt>$' -- SHA-512, which is what
# Piler itself would store if the password were changed through the UI. The
# hashes are computed here with the image's PHP rather than in prepare.sh so
# that the plaintext never has to be turned into a hash by a tool whose crypt
# support differs from the one doing the checking.
#
# Three details that are each a way to get this silently wrong:
#
#   The password is passed through the environment, not argv: `php -r` puts
#   argv in /proc/<pid>/cmdline, which anything in this container can read while
#   the process lives.
#
#   The salt is written as "\$6\$rounds=5000\$" and not "$6$rounds=5000$". The
#   shell leaves the backslashes alone inside single quotes and PHP turns \$
#   into a literal $; written the obvious way, PHP interpolates $rounds as an
#   undefined variable, the salt degrades to "$6=5000$...", crypt() answers the
#   failure token, and the account ends up with a password nothing can match.
#
#   display_errors and error_reporting are forced off. PHP CLI with no php.ini
#   writes warnings to *stdout*, so one notice does not just warn -- it prepends
#   itself to the hash this function returns and goes into the UPDATE.
#
# The round trip is checked before the hash is returned: crypt($pw, $hash) has
# to reproduce $hash, which is exactly the comparison auth.php:97 makes.
hash_for() {
    PA_PW="$1" php -d display_errors=0 -d error_reporting=0 -r '
        $pw = getenv("PA_PW");
        $hash = crypt($pw, "\$6\$rounds=5000\$" . bin2hex(random_bytes(8)) . "\$");
        if (!is_string($hash) || strncmp($hash, "\$6\$", 3) !== 0 || crypt($pw, $hash) !== $hash) {
            exit(1);
        }
        echo $hash;
    ' 2>/dev/null
}

ADMIN_HASH=$(hash_for "${PILER_ADMIN_PASSWORD}")
case "${ADMIN_HASH}" in
    '$6$'*) : ;;
    *)
        say "could not compute a usable password hash; leaving the shipped accounts alone"
        finish
        ;;
esac

# Quoting: the hash is [./0-9A-Za-z$] only, and the shell has already finished
# with it, so a single-quoted SQL literal is safe. The generated password never
# reaches SQL at all.
changed=$(sql <<SQL
UPDATE user SET password='${ADMIN_HASH}' WHERE uid=0 AND password='${DEFAULT_ADMIN_HASH}';
SELECT ROW_COUNT();
SQL
)

if [ "${changed}" = "1" ]; then
    say "replaced the shipped admin@local password"
elif [ "${changed}" = "0" ]; then
    say "admin@local no longer carries the shipped password; left as it is"
else
    say "could not reach the database to check admin@local"
    finish
fi

# The auditor account is the same problem one row down: isadmin=2 is Piler's
# auditor role, which can search and read every archived message of every
# domain it is granted. Given a password nobody was told rather than the
# published one; an operator who wants an auditor sets one in Settings.
AUDITOR_HASH=$(hash_for "$(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n')")
case "${AUDITOR_HASH}" in
    '$6$'*)
        aud=$(sql <<SQL
UPDATE user SET password='${AUDITOR_HASH}' WHERE uid=1 AND password='${DEFAULT_AUDITOR_HASH}';
SELECT ROW_COUNT();
SQL
)
        [ "${aud}" = "1" ] && say "locked the shipped auditor@local account (no password is kept for it)"
        ;;
esac

# ---------------------------------------------------------------------------
# 2. SITE_URL over https.
if [ -n "${PA_PUBLIC_URL}" ] && [ -f "${CONFIG_SITE}" ]; then
    case "${PA_PUBLIC_URL}" in
        http://localhost*|"") : ;;
        *)
            url="${PA_PUBLIC_URL%/}/"
            if grep -q 'PanelAlpha SITE_URL' "${CONFIG_SITE}"; then
                # Rewritten rather than appended again, so a redeploy after a
                # domain change does not leave two assignments with the stale
                # one further down the file.
                sed -i "s#^\$config\['SITE_URL'\] = '.*'; // PanelAlpha SITE_URL#\$config['SITE_URL'] = '${url}'; // PanelAlpha SITE_URL#" "${CONFIG_SITE}"
            else
                printf "\n\$config['SITE_URL'] = '%s'; // PanelAlpha SITE_URL\n" "${url}" >> "${CONFIG_SITE}"
            fi
            say "SITE_URL set to ${url}"
            ;;
    esac
fi

# ---------------------------------------------------------------------------
# 3. Sign in with the password that was just written.
#
# POST to login.php, which is the rewrite the shipped vhost maps to
# index.php?route=login/login. A successful administrator login answers 302 to
# index.php?route=health/health (controller/login/login.php); a failed one
# re-renders the login form with 200, so the status alone distinguishes them.
# A session cookie jar is used because the controller reads the failed-login
# counter out of the session before it validates.
COOKIES=$(mktemp)
curl -fsS -m 15 -c "${COOKIES}" -o /dev/null "${BASE}/" 2>/dev/null

location=$(curl -sS -m 20 -b "${COOKIES}" -c "${COOKIES}" -o /dev/null \
    -D - -X POST \
    --data-urlencode "username=${PILER_ADMIN_USERNAME:-admin@local}" \
    --data-urlencode "password=${PILER_ADMIN_PASSWORD}" \
    --data-urlencode "relocation=" \
    "${BASE}/login.php" 2>/dev/null | tr -d '\r' | sed -n 's/^[Ll]ocation: //p')
rm -f "${COOKIES}"

case "${location}" in
    *health/health*|*search.php*)
        say "signed in as ${PILER_ADMIN_USERNAME:-admin@local} with the generated password"
        ;;
    *)
        say "WARNING: sign-in check did not redirect as expected (Location: '${location:-none}')"
        ;;
esac

finish
