#!/bin/sh
# Sets the engine's password on wallabag's first user `wallabag` while it still
# has the default password `wallabag`, then runs the image's own entrypoint.
set -e
cd /var/www/wallabag

# Exits 0 while the database is new (wallabag:install will give it
# wallabag / wallabag) or its `wallabag` user still has that password; 1 once
# the password is anything else. Read-only: it never writes the database.
still_default() {
    php <<'PHP'
<?php
$db = '/var/www/wallabag/data/db/wallabag.sqlite';
if (! is_file($db) || filesize($db) === 0) {
    exit(0);
}
require '/var/www/wallabag/vendor/autoload.php';
try {
    $pdo = new PDO('sqlite:' . $db, null, null, [PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
    $user = $pdo->query("SELECT password, salt FROM wallabag_user WHERE username = 'wallabag'")->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    exit(1);
}
if (! $user) {
    exit(1);
}
// security.yml: FOS\UserBundle\Model\UserInterface: sha512 (base64, 5000 iterations).
$encoder = new Symfony\Component\Security\Core\Encoder\MessageDigestPasswordEncoder('sha512', true, 5000);
exit($encoder->isPasswordValid($user['password'], 'wallabag', $user['salt']) ? 0 : 1);
PHP
}

if [ -n "${WALLABAG_PASSWORD:-}" ] && still_default; then
    # The image's provisioner (parameters.yml, wallabag:install on a new
    # database, composer), then its migrations, none pending; it exits.
    /entrypoint.sh migrate
    if still_default; then
        su -c 'php bin/console fos:user:change-password wallabag "$WALLABAG_PASSWORD" --env=prod' -s /bin/sh nobody \
            && echo "[panelalpha] wallabag: the login of the user wallabag is the one the engine generated" \
            || echo "[panelalpha] wallabag: could not set the login; it stays wallabag/wallabag" >&2
    fi
fi
exec /entrypoint.sh wallabag
