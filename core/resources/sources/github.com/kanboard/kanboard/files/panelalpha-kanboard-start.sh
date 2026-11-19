#!/bin/sh
# Sets the engine's login on Kanboard's admin while it still has the default
# password `admin`, then runs Kanboard's own entrypoint (which chowns data/ to nginx).
set -e
if [ -n "${KANBOARD_ADMIN_PASSWORD:-}" ]; then
    php <<'PHP' || echo "[panelalpha] kanboard: could not set the admin login; it stays admin/admin" >&2
<?php
require '/var/www/app/app/common.php';

// The first use of the database runs Kanboard's migrations: a new one gets admin/admin.
$users = $container['db']->table('users');
$name = getenv('KANBOARD_ADMIN_USERNAME') ?: 'admin';
$user = $users->eq('username', $name)->findOne() ?: $container['db']->table('users')->eq('username', 'admin')->findOne();
if (! $user || ! password_verify('admin', (string) $user['password'])) {
    exit(0);
}

$container['db']->table('users')->eq('id', $user['id'])->update([
    'username' => $name,
    'password' => password_hash(getenv('KANBOARD_ADMIN_PASSWORD'), PASSWORD_BCRYPT),
]);
echo "[panelalpha] kanboard: the admin's login is the one the engine generated\n";
PHP
fi
exec /usr/local/bin/entrypoint.sh "$@"
