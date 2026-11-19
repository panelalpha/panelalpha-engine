<?php
// Sets the engine's login (PASSWORDCOCKPIT_ADMIN_USERNAME / _PASSWORD) on
// Passwordcockpit's built-in admin, user 1, while it still has the password
// the install SQL gives it (Admin123!, published in the README). A password
// changed in Passwordcockpit is never touched.
//
// A new database already gets the engine's password from entrypoint.sh
// (PASSWORDCOCKPIT_ADMIN_PASSWORD is the image's own variable), but keeps the
// username `admin`: the name is set here when the admin still has exactly the
// engine's password, so it is the engine's whole login.
$name = getenv('PASSWORDCOCKPIT_ADMIN_USERNAME') ?: 'admin';
$password = (string) getenv('PASSWORDCOCKPIT_ADMIN_PASSWORD');
if ($password === '') {
    exit(0);
}

$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=utf8', getenv('PASSWORDCOCKPIT_DATABASE_HOSTNAME'), getenv('PASSWORDCOCKPIT_DATABASE_DATABASE')),
    (string) getenv('PASSWORDCOCKPIT_DATABASE_USERNAME'),
    (string) getenv('PASSWORDCOCKPIT_DATABASE_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$admin = $pdo->query('SELECT username, password FROM user WHERE user_id = 1')->fetch(PDO::FETCH_ASSOC);
if (! $admin) {
    exit(0);
}

$hash = (string) $admin['password'];
if (password_verify('Admin123!', $hash)) {
    $hash = password_hash($password, PASSWORD_BCRYPT);
} elseif (! password_verify($password, $hash) || $admin['username'] === $name) {
    exit(0);
}

$pdo->prepare('UPDATE user SET username = ?, password = ? WHERE user_id = 1')->execute([$name, $hash]);
echo "[panelalpha] passwordcockpit: the admin's login is the one the engine generated\n";
