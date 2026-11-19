#!/bin/bash
# Runs as root before FreeITSM's own entrypoint (entrypoint.sh, which starts
# Apache).
#
# database/freeitsm.sql, which the db service imports on its first start,
# seeds the analyst `admin` with the password `freeitsm` (published in the
# README) and must_change_password = 1. Here, while that admin still has the
# published password, it gets the login the engine generated instead: the
# username, a password_hash() of the password (as api/myaccount/change_password.php
# stores one) and must_change_password = 0, so the generated login is the one
# that works. A password changed in FreeITSM no longer verifies as `freeitsm`,
# so it is never touched.
#
# The Dockerfile COPYs the whole checkout into the web root, this file too:
# it is a shell script (Apache serves it as text, it never runs there) and it
# holds no secret; the login is read from the mounted /pa/app-credentials.env.

say() { echo "[panelalpha] freeitsm: $*" >&2; }

if [ ! -r /pa/app-credentials.env ]; then
    say "no /pa/app-credentials.env; the seeded admin is left as it is"
else
    # In a subshell: the login reaches PHP's CLI, not Apache.
    (
        set -a
        . /pa/app-credentials.env
        set +a
        if [ -z "${FREEITSM_ADMIN_USER:-}" ] || [ -z "${FREEITSM_ADMIN_PASSWORD:-}" ]; then
            say "FREEITSM_ADMIN_USER or FREEITSM_ADMIN_PASSWORD is empty; the seeded admin is left as it is"
            exit 0
        fi
        php -d display_errors=stderr <<'PHP'
<?php
const PUBLISHED_USER = 'admin';
const PUBLISHED_PASSWORD = 'freeitsm';

function say(string $m): void { fwrite(STDERR, "[panelalpha] freeitsm: {$m}\n"); }

$user = (string) getenv('FREEITSM_ADMIN_USER');
$password = (string) getenv('FREEITSM_ADMIN_PASSWORD');
// docker/config.php's defaults, which the compose file's environment sets.
$dsn = 'mysql:host='.(getenv('DB_SERVER') ?: 'db').';dbname='.(getenv('DB_NAME') ?: 'freeitsm').';charset=utf8mb4';

// The db service is healthy as soon as mysqld answers, which on the first
// start is while it is still importing the schema: wait for the analysts table.
$conn = null;
for ($i = 0; $i < 90; $i++) {
    try {
        $conn = new PDO($dsn, getenv('DB_USERNAME') ?: 'freeitsm', getenv('DB_PASSWORD') ?: 'freeitsm', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $conn->exec("SET time_zone = '+00:00'");
        $conn->query('SELECT 1 FROM analysts LIMIT 1');
        break;
    } catch (Throwable $e) {
        $conn = null;
        sleep(2);
    }
}
if ($conn === null) {
    say('the database or its analysts table did not come up in 180 s; the seeded admin is left as it is');
    exit(0);
}

$stmt = $conn->prepare('SELECT id, password_hash FROM analysts WHERE username = ?');
$stmt->execute([PUBLISHED_USER]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);
if (! $admin || ! password_verify(PUBLISHED_PASSWORD, (string) $admin['password_hash'])) {
    say('no analyst has the published login; left alone');
    exit(0);
}

$conn->prepare(
    'UPDATE analysts SET username = ?, password_hash = ?, must_change_password = 0,
            password_changed_datetime = UTC_TIMESTAMP(), last_modified_datetime = UTC_TIMESTAMP()
      WHERE id = ?'
)->execute([$user, password_hash($password, PASSWORD_DEFAULT), $admin['id']]);
say("the seeded admin now signs in as {$user} with the generated password");
PHP
    ) || say "setting the generated login failed; the seeded admin is left as it is"
fi

exec /usr/local/bin/entrypoint.sh "$@"
